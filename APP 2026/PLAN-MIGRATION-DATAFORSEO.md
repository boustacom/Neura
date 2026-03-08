# Plan de Migration: Value SERP → DataForSEO + Mapbox GL JS

## Objectif
Migrer le moteur de scan de grille de Value SERP vers DataForSEO (task-based Google Maps API) avec injection de coordonnees GPS precises par point, et migrer la carte de Leaflet.js vers Mapbox GL JS.

---

## PHASE 1 : Configuration & Fonctions de base DataForSEO

### 1.1 — config.php
- **SUPPRIMER** : `VALUESERP_API_KEY` et `VALUESERP_API_URL` (lignes 51-54)
- **AJOUTER** :
```php
// DATAFORSEO (Google Maps SERP — task-based)
define('DATAFORSEO_LOGIN', '');    // A remplir
define('DATAFORSEO_PASSWORD', ''); // A remplir
define('DATAFORSEO_API_URL', 'https://api.dataforseo.com/v3');

// MAPBOX
define('MAPBOX_TOKEN', 'YOUR_MAPBOX_TOKEN_HERE');
```
- **MODIFIER** : `DEFAULT_GRID_RADIUS_KM` → `DEFAULT_GRID_RADIUS_STEP_KM` (= distance entre anneaux, pas rayon total)

### 1.2 — functions.php : Nouvelles fonctions DataForSEO
**SUPPRIMER** :
- `valueSerpRequest()` (lignes 170-239)
- `dataforseoRequest()` stub deprecated (lignes 241-247)
- `extractResultsFromResponse()` (lignes 1260-1273) — specifique au format ValueSERP

**AJOUTER** 3 nouvelles fonctions :
```
dataforseoPostTasks(array $tasks): array
```
- POST vers `/v3/serp/google/maps/task_post`
- Auth: HTTP Basic (login:password en Base64)
- Body: JSON array de tasks, chaque task = {keyword, location_coordinate, language_code, depth, tag}
- Retourne: array de task IDs

```
dataforseoGetResult(string $taskId): ?array
```
- GET vers `/v3/serp/google/maps/task_get/advanced/{taskId}`
- Auth: idem
- Retourne: array d'items normalises ou null si pas encore pret

```
dataforseoWaitForResults(array $taskIds, int $maxWaitSec = 120): array
```
- Boucle de polling: verifie `tasks_ready` puis recupere les resultats
- Retourne: array taskId => items

### 1.3 — functions.php : Adaptateur de reponse
**AJOUTER** une fonction de normalisation pour convertir les items DataForSEO vers notre format interne :
```
normalizeDataforseoItem(array $item, int $index): array
```
Mapping :
- `rank_group` → `position` (ou $index + 1 si absent)
- `title` → `title`
- `address` → `address`
- `phone` → `phone`
- `url` ou `domain` → `link`/`website`
- `cid` → `data_cid`
- `place_id` → `place_id`
- `rating.value` → `rating`
- `rating.votes_count` → `reviews`
- `category` → `category`

Cela permet de garder `computeRankForPoint()`, `extractPlaceIdFromItem()`, `extractCidFromItem()` INCHANGES — ils travailleront sur le format normalise.

### 1.4 — functions.php : Algorithme radial
**MODIFIER** `generateCircularGridCoordinates()` pour accepter `radiusStep` (distance entre anneaux) au lieu de `radiusKm` (rayon total) :
- Parametres : `(float $lat, float $lng, int $numRings = 3, float $radiusStepKm = 5.0)`
- Formule : `$ringRadiusKm = $ring * $radiusStepKm` (au lieu de `($radiusKm / $numRings) * $ring`)
- Meme resultat mathematique si `radiusStepKm = radiusKm / numRings`

**AJOUTER** la version JS identique dans app.js :
```javascript
APP.generateRadialGrid = function(centerLat, centerLng, numRings, radiusStep) { ... }
```

---

## PHASE 2 : Migration des scripts de scan

### 2.1 — scan-async.php (Scan interactif depuis l'app)
C'est le fichier le plus impacte. **Strategie BATCH** :

**Avant** (ValueSERP) : 1 appel HTTP par point de grille (37 appels sequentiels)
**Apres** (DataForSEO) : 1 POST avec 37 tasks → polling → recuperation resultats

Modifications dans `runBackgroundScan()` :
1. **Phase 1 (Position tracking)** : 1 task DataForSEO au centre (au lieu de `valueSerpRequest`)
2. **Phase 2 (Grid scan)** :
   a. Construire un array de 37 tasks avec `location_coordinate: "lat,lng,13"` par point
   b. `dataforseoPostTasks()` — 1 seul appel POST
   c. `dataforseoWaitForResults()` — polling avec mise a jour progression
   d. Pour chaque resultat : normaliser items → `computeRankForPoint()` → stocker

Avantage : au lieu de 37 appels HTTP sequentiels (37 × 0.5s = ~18s d'attente pure), on fait 1 POST + polling (typiquement 5-15s total).

### 2.2 — scan-grids.php (Cron quotidien)
Meme strategie BATCH que scan-async :
- Pour chaque keyword : poster 37 tasks en 1 seul POST
- Attendre les resultats en batch
- Stocker dans grid_points + grid_competitors

### 2.3 — track-keywords.php (Suivi positions rapide)
- Remplacer `valueSerpRequest()` par un appel DataForSEO au centre uniquement
- La cascade de fallback (GPS → UULE → France) sera simplifiee :
  - DataForSEO gere directement les coordonnees GPS via `location_coordinate`
  - Plus besoin de UULE ou de location textuelle comme fallback
  - Si erreur → fallback sur `location_name: "France"` + `keyword`

### 2.4 — grid.php
- L'action `scan` dans grid.php n'existe pas actuellement (le scan est dans scan-async.php)
- **Seulement** mettre a jour les commentaires (retirer mentions "ValueSERP")
- Les actions `list` et `get` ne changent PAS (elles lisent la BDD)

---

## PHASE 3 : Migration carte Leaflet → Mapbox GL JS

### 3.1 — app.js : Module positionMap
**REMPLACER** le bloc `_initLeaflet()` + `_renderMap()` :

**Avant** :
- Chargement dynamique de Leaflet.js 1.9.4
- `L.map()` + `L.tileLayer()` (OpenStreetMap)
- `L.circle()` pour la zone de couverture
- `L.marker()` + `L.divIcon()` pour les points
- `L.tooltip()` pour le hover

**Apres** :
- Chargement dynamique de Mapbox GL JS v3 (CSS + JS)
- `new mapboxgl.Map({ container, style, center, zoom, accessToken })`
  - Style: `mapbox://styles/mapbox/dark-v11` (theme sombre pour matcher l'app)
  - Token: injecte via `<meta name="mapbox-token">` dans le HTML
- Zone de couverture : GeoJSON source + circle-layer (fill + stroke dashes)
- Points : `mapboxgl.Marker({ element: customDiv })` avec le MEME design :
  - Pos 1-3 : vert `rgba(34,197,94,.8)`, 36px
  - Pos 4-10 : vert clair `rgba(34,197,94,.55)`, 32px
  - Pos 11-20 : orange `rgba(245,158,11,.55)`, 30px
  - Pos 20+ : rouge `rgba(239,68,68,.45)`, 28px
  - Pas de donnees : gris `rgba(107,114,128,.4)`, 26px
- Centre business : marker rouge 14px (identique)
- Popup au hover : `new mapboxgl.Popup({ offset: 25 })`

### 3.2 — index.php ou layout
- Ajouter `<meta name="mapbox-token" content="<?= MAPBOX_TOKEN ?>">` dans le <head>
- (Le JS charge Mapbox GL dynamiquement — pas besoin de l'inclure statiquement)

---

## PHASE 4 : Nettoyage final

### 4.1 — Fichiers a supprimer
- `app/api/test-valueserp.php` (endpoint de test ValueSERP)

### 4.2 — Commentaires et references
- Mettre a jour TOUS les commentaires docblock mentionnant "ValueSERP"
- Mettre a jour les messages d'erreur (ex: "Rechargez vos credits ValueSERP" → "Verifiez vos credits DataForSEO")

### 4.3 — sync.sh
- Retirer `test-valueserp.php` de la liste de deploiement
- S'assurer que tous les fichiers modifies sont dans la liste

### 4.4 — Base de donnees
- **AUCUNE migration SQL** — les tables `grid_scans`, `grid_points`, `grid_competitors` restent identiques
- Le champ `grid_radius_km` dans `gbp_locations` peut garder son nom mais representera `radius_step_km`
- Les anciens scans restent accessibles (lecture seule)

---

## RECAPITULATIF DES FICHIERS

| Fichier | Action | Ampleur |
|---------|--------|---------|
| `config.php` | MODIFIER | Petite (constantes) |
| `functions.php` | MODIFIER | **Grande** (3 fonctions DataForSEO + normalizer + grid algo) |
| `scan-async.php` | MODIFIER | **Grande** (batch DataForSEO dans runBackgroundScan) |
| `scan-grids.php` | MODIFIER | **Grande** (batch DataForSEO dans le cron) |
| `track-keywords.php` | MODIFIER | Moyenne (remplacer les appels ValueSERP) |
| `grid.php` | MODIFIER | Petite (commentaires seulement) |
| `app.js` | MODIFIER | **Grande** (Mapbox GL JS + grid algo JS) |
| `index.php` | MODIFIER | Petite (meta tag Mapbox token) |
| `test-valueserp.php` | SUPPRIMER | — |
| `sync.sh` | MODIFIER | Petite (liste fichiers) |

## ORDRE D'EXECUTION RECOMMANDE
1. Phase 1 (config + functions) — fondations
2. Phase 2.3 (track-keywords) — test rapide DataForSEO
3. Phase 2.1 (scan-async) — scan interactif complet
4. Phase 2.2 (scan-grids) — cron
5. Phase 3 (Mapbox) — visualisation
6. Phase 4 (nettoyage) — finalisation
