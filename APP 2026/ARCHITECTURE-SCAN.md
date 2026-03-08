# BOUS'TACOM — Architecture du Scan de Grille SEO Local

> Document de reference — Solution mise en place le 27 fevrier 2026
> A conserver precieusement : cette logique est le coeur du produit.

---

## Le Probleme

Les grid scans (37 points GPS disposes en cercles autour de la fiche Google)
donnaient systematiquement 0% de visibilite et une position moyenne de 98+.

**Cause racine** : l'endpoint DataForSEO `/serp/google/maps/` est **viewport-base**.
Il simule ce que Google Maps affiche visuellement a l'ecran pour un point GPS donne.
A 10km du centre, le viewport montre une zone completement differente et retourne
seulement 2-7 resultats hors sujet. C'est un comportement normal de Google Maps,
mais catastrophique pour un scan de grille.

**Preuve** :
- MAPS au centre : 31 resultats, notre fiche trouvee #3 ✓
- MAPS a 10km : 2 resultats, notre fiche absente ✗
- LOCAL_FINDER au centre : 87 resultats, notre fiche trouvee #6 ✓
- LOCAL_FINDER a 10km : 88 resultats, notre fiche trouvee #8 ✓ ← LA SOLUTION

---

## La Solution : LOCAL_FINDER LIVE + curl_multi

### Principe

On utilise **deux endpoints DataForSEO differents** selon le besoin :

| Etape | Endpoint | Mode | But |
|-------|----------|------|-----|
| Position tracking (centre) | `/serp/google/maps/task_post` | Async (POST + polling) | Rang exact au centre |
| Grid scan (37 points) | `/serp/google/local_finder/live/advanced` | Synchrone (LIVE) | Rang a chaque point GPS |

### Pourquoi LOCAL_FINDER ?

L'endpoint **Local Finder** simule la page "Plus de resultats" du Google Local Pack
(ce qui s'affiche en-dessous de Google Maps dans une recherche Google classique).

Contrairement a `/maps/` :
- Il retourne les **MEMES commerces** quel que soit le point GPS
- Seul le **CLASSEMENT** change par proximite geographique
- Typiquement 80-100 resultats stables sur toute la grille
- C'est exactement comme ca que Localo, BrightLocal, etc. fonctionnent

### Pourquoi LIVE (pas async) ?

Le mode async de local_finder (`task_post` + `task_get`) **ne fonctionne pas** :
- Les tasks sont creees (status 20100 = "Task Created")
- Mais elles ne completent **jamais** — timeout systematique apres 120-300s
- C'est probablement un bug ou une limitation cote DataForSEO

Le mode LIVE (`/live/advanced`) fonctionne parfaitement :
- Resultat immediat dans la reponse HTTP
- Pas de polling necessaire

### Pourquoi curl_multi ?

L'endpoint LIVE n'accepte qu'**UNE SEULE task par requete API**.
Envoyer 37 tasks dans un POST → seule la premiere est traitee, les 36 autres
retournent l'erreur "You can set only one task at a time."

**Solution** : `curl_multi` en PHP
- 37 requetes HTTP separees, chacune avec 1 task
- Toutes envoyees **en parallele** via `curl_multi_exec()`
- Temps total = temps de la requete la plus lente (~30-60s)
- Pas 37x plus long, juste 1x

---

## Architecture Technique

### Flux d'un scan (live_scan.php / scan-async.php)

```
1. POSITION TRACKING (centre)
   ├─ dataforseoPostTasks([keyword], gridMode=false)
   │   → POST /serp/google/maps/task_post (1 task)
   ├─ dataforseoWaitForResults(taskIds, 90s)
   │   → GET /serp/google/maps/task_get/advanced/{id} (polling 2-5s)
   ├─ dbEnsureConnected()  ← CRITIQUE : MySQL timeout apres polling
   ├─ computeRankForPoint(location, items)
   │   → Matching : place_id (tier 1) > CID (tier 2)
   └─ INSERT keyword_positions

2. GRID SCAN (37 points)
   ├─ dataforseoLocalFinderLive(batchTasks, 100)
   │   ├─ Prepare 37 requetes HTTP (1 task chacune)
   │   ├─ curl_multi_init() + curl_multi_add_handle() x37
   │   ├─ curl_multi_exec() → toutes en parallele
   │   └─ Collecte + normalisation des resultats
   ├─ dbEnsureConnected()
   ├─ Pour chaque point :
   │   ├─ computeRankForPoint(location, items)
   │   ├─ INSERT grid_points
   │   └─ INSERT grid_competitors (tous les concurrents)
   └─ computeGridKPIs(positions, totalPoints)
       → UPDATE grid_scans (avg_position, visibility_score, top3/10/20/out)
```

### Fonctions cles (functions.php)

| Fonction | Role |
|----------|------|
| `dataforseoLocalFinderLive()` | **LA fonction miracle** — curl_multi, 37 requetes paralleles, 1 task chacune |
| `dataforseoPostTasks()` | POST async pour le position tracking (maps) |
| `dataforseoWaitForResults()` | Polling des tasks async |
| `dataforseoGetResult()` | GET d'une task, accepte maps_search ET local_pack |
| `normalizeDataforseoItem()` | Convertit le format DataForSEO → format interne |
| `computeRankForPoint()` | Matching 2-tier : place_id > CID (pas de fuzzy) |
| `computeGridKPIs()` | Calcul des KPI : visibilite = top3/total x 100 |
| `applyBackfill()` | Auto-decouverte CID/place_id et persistence en base |

### Fichiers utilisant cette logique

| Fichier | Contexte |
|---------|----------|
| `api/live_scan.php` | Scan synchrone AJAX (clic utilisateur sur bouton refresh) |
| `api/scan-async.php` | Scan arriere-plan (fastcgi_finish_request) |
| `cron/scan-queue.php` | Cron toutes les 5 min (file d'attente) |
| `cron/scan-grids.php` | Cron quotidien 06:00 (scan automatique toutes les fiches) |

---

## Parametres DataForSEO

```php
// Pour CHAQUE requete (maps et local_finder) :
$apiTask = [
    'keyword'             => 'seo Brive',      // keyword + ville (toujours fusionnes)
    'location_coordinate' => '45.1234567,1.5234567,13',  // lat,lng,zoom13
    'language_code'       => 'fr',
    'depth'               => 100,               // max resultats
    'device'              => 'mobile',
    'os'                  => 'android',
];
```

**Points critiques** :
- Zoom `13z` (pas 15z — trop etroit, moins de resultats)
- Keyword + ville toujours fusionnes (`dataforseoPostTasks` et `dataforseoLocalFinderLive` le font automatiquement)
- `depth=100` pour avoir assez de resultats de matching
- `device=mobile` + `os=android` pour simuler une recherche mobile

---

## Matching (computeRankForPoint)

L'algorithme de matching identifie notre fiche dans les resultats :

1. **TIER 1 — Place ID** : comparaison exacte de `place_id` (identifiant Google le plus stable)
2. **TIER 2 — CID** : comparaison exacte de `data_cid` (Customer ID Google, entier 64-bit)
3. **Pas de TIER 3 fuzzy** — trop de faux positifs avec accents/tirets

Si un CID ou place_id est decouvert pour la premiere fois → **auto-backfill** en base
via `applyBackfill()` (n'ecrase jamais une valeur existante).

---

## Couts

| Action | Cout par task | Cout par scan (37 pts) |
|--------|---------------|------------------------|
| Position tracking (maps async) | $0.0006 | $0.0006 |
| Grid point (local_finder live) | $0.002 | $0.074 |
| **Total par keyword** | | **~$0.075** |

---

## Ce qu'il ne faut PAS toucher

- **Le position tracking au centre** DOIT rester sur `/serp/google/maps/` (le viewport est OK au centre exact)
- **prospects.php** et **track-keywords.php** n'utilisent pas de grille → pas concernes
- **Le zoom 13z** — ne PAS repasser a 15z
- **La fusion keyword+ville** — supprimee une fois par erreur, tout a casse
- **dbEnsureConnected()** apres chaque appel DataForSEO long — MySQL ferme la connexion apres 30s d'inactivite

---

## Diagnostic (debug-scan.php)

Le fichier `api/debug-scan.php` est un outil de diagnostic qui compare
les 4 strategies (MAPS centre, MAPS 10km, LOCAL_FINDER centre, LOCAL_FINDER 10km).
Utile si les resultats deviennent suspects. Acces via :
```
https://app.boustacom.fr/app/api/debug-scan.php?location_id=XX&keyword_id=YY
```
