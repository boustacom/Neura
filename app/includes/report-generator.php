<?php
/**
 * BOUS'TACOM — Generateur de rapports PDF PRO
 *
 * Design sobre, élégant, compréhensible pour le client.
 * Sections : Stats Google, Classement mots-clés, Avis, Posts
 * Avec textes explicatifs pour chaque section.
 *
 * IMPORTANT: FPDF utilise ISO-8859-1. Tous les textes passent par u()
 * qui convertit UTF-8 → ISO-8859-1 (accents FR supportés) et nettoie les emojis/Unicode.
 */

$fpdfPath = __DIR__ . '/fpdf/fpdf.php';
if (file_exists($fpdfPath)) {
    require_once $fpdfPath;
}

class ReportGenerator {

    private $pdf;
    private $primary = [37, 99, 235];
    private $green = [34, 197, 94];
    private $orange = [245, 158, 11];
    private $red = [239, 68, 68];
    private $blue = [59, 130, 246];
    private $pink = [236, 72, 153];
    private $dark = [26, 26, 46];
    private $gray = [120, 130, 150];
    private $lightGray = [200, 210, 220];
    private $bgLight = [245, 247, 250];

    /**
     * Genere un rapport PDF complet
     */
    public function generate(int $locationId, array $sections, string $startDate, string $endDate, ?string $periodLabel = null): ?string {
        if (!class_exists('FPDF')) return null;

        $location = $this->getLocationData($locationId);
        if (!$location) return null;

        $this->pdf = new \FPDF('P', 'mm', 'A4');
        $this->pdf->SetAutoPageBreak(true, 20);
        $this->pdf->AddPage();

        // ====== COVER HEADER ======
        $this->renderHeader($location, $startDate, $endDate, $periodLabel);

        // ====== SECTIONS ======
        $hasSections = false;

        if (!empty($sections['google_stats'])) {
            $this->sectionStats($locationId, $startDate, $endDate, $location);
            $hasSections = true;
        }

        if (!empty($sections['keyword_positions'])) {
            $this->sectionKeywords($locationId, $location);
            $hasSections = true;
        }

        if (!empty($sections['reviews_summary'])) {
            $this->sectionReviews($locationId);
            $hasSections = true;
        }

        if (!empty($sections['posts_summary'])) {
            $this->sectionPosts($locationId);
            $hasSections = true;
        }

        if (!empty($sections['google_places'])) {
            $this->sectionGooglePlaces($locationId, $startDate, $endDate);
            $hasSections = true;
        }

        if (!$hasSections) {
            $this->pdf->SetFont('Helvetica', '', 11);
            $this->setColor($this->gray);
            $this->pdf->Cell(0, 8, $this->u('Aucune section sélectionnée pour ce rapport.'), 0, 1, 'C');
        }

        // ====== FOOTER ======
        $this->renderFooter();

        $filename = "boustacom_rapport_{$locationId}_" . date('Ymd_His') . ".pdf";
        $filepath = sys_get_temp_dir() . '/' . $filename;
        $this->pdf->Output('F', $filepath);

        return $filepath;
    }

    // ================================================================
    // HEADER
    // ================================================================
    private function renderHeader(array $location, string $startDate, string $endDate, ?string $periodLabel = null): void {
        $pdf = $this->pdf;

        // Bande coloree en haut
        $pdf->SetFillColor(...$this->primary);
        $pdf->Rect(0, 0, 210, 3, 'F');

        $pdf->Ln(8);

        // Logo / Marque
        $pdf->SetFont('Helvetica', 'B', 22);
        $this->setColor($this->dark);
        $pdf->Cell(0, 10, $this->u("NEURA"), 0, 1, 'L');

        $pdf->SetFont('Helvetica', '', 10);
        $this->setColor($this->gray);
        $pdf->Cell(0, 5, $this->u('Rapport de performance SEO local'), 0, 1, 'L');
        $pdf->Ln(6);

        // Fiche client — card style
        $y = $pdf->GetY();
        $managedSince = !empty($location['created_at']) ? date('d/m/Y', strtotime($location['created_at'])) : null;
        $cardH = $managedSince ? 28 : 22;
        $pdf->SetFillColor(...$this->bgLight);
        $pdf->Rect(10, $y, 190, $cardH, 'F');

        $pdf->SetXY(14, $y + 3);
        $pdf->SetFont('Helvetica', 'B', 14);
        $this->setColor($this->dark);
        $pdf->Cell(120, 7, $this->u($location['name'] ?? 'Fiche'), 0, 0, 'L');

        // Periode a droite — utiliser le label explicite si fourni (ex: "Janvier 2026")
        $displayPeriod = $periodLabel ?: $this->formatPeriod($startDate, $endDate);
        $pdf->SetFont('Helvetica', '', 9);
        $this->setColor($this->gray);
        $pdf->Cell(66, 7, $this->u($displayPeriod), 0, 1, 'R');

        $pdf->SetX(14);
        $pdf->SetFont('Helvetica', '', 10);
        $this->setColor($this->gray);
        $parts = [];
        if (!empty($location['city'])) $parts[] = $location['city'];
        if (!empty($location['category'])) $parts[] = $location['category'];
        if ($managedSince) {
            $pdf->Cell(120, 6, $this->u(implode(' - ', $parts)), 0, 0, 'L');
            $pdf->SetFont('Helvetica', 'I', 8);
            $pdf->SetTextColor(...$this->primary);
            $pdf->Cell(66, 6, $this->u('Suivi depuis le ' . $managedSince), 0, 1, 'R');
        } else {
            $pdf->Cell(0, 6, $this->u(implode(' - ', $parts)), 0, 1, 'L');
        }

        $pdf->SetY($y + $cardH + 4);

        // Ligne de separation
        $pdf->SetDrawColor(...$this->primary);
        $pdf->SetLineWidth(0.4);
        $pdf->Line(10, $pdf->GetY(), 200, $pdf->GetY());
        $pdf->Ln(8);
    }

    // ================================================================
    // SECTION : STATISTIQUES GOOGLE
    // ================================================================
    private function sectionStats(int $locationId, string $startDate, string $endDate, array $location = []): void {
        $pdf = $this->pdf;

        $this->sectionTitle('Statistiques Google Business');
        $this->explanatoryText("Ces chiffres proviennent directement de Google et montrent comment les internautes trouvent et interagissent avec votre fiche. Plus ces chiffres augmentent, plus votre visibilité locale progresse.");

        // Recuperer les stats mensuelles
        $stmt = db()->prepare('
            SELECT DATE_FORMAT(stat_date, "%Y-%m") as month,
                   SUM(impressions_search) as impressions_search,
                   SUM(impressions_maps) as impressions_maps,
                   SUM(call_clicks) as call_clicks,
                   SUM(website_clicks) as website_clicks,
                   SUM(direction_requests) as direction_requests,
                   COUNT(*) as days
            FROM location_daily_stats
            WHERE location_id = ? AND stat_date >= ? AND stat_date <= ?
            GROUP BY DATE_FORMAT(stat_date, "%Y-%m")
            ORDER BY month
        ');
        $stmt->execute([$locationId, $startDate, $endDate]);
        $monthly = $stmt->fetchAll();

        if (empty($monthly)) {
            $this->emptyText('Aucune statistique disponible. Synchronisez les données depuis Google.');
            return;
        }

        // Stats du MOIS DU RAPPORT (dernier mois complet) vs mois precedent
        $current = $monthly[count($monthly) - 1];
        $previous = count($monthly) >= 2 ? $monthly[count($monthly) - 2] : null;

        // Si le dernier mois est incomplet (< 28 jours), prendre les 2 precedents
        if ((int)$current['days'] < 28 && count($monthly) >= 3) {
            $current = $monthly[count($monthly) - 2];
            $previous = $monthly[count($monthly) - 3];
        }

        $curMonth = [
            'search' => (int)$current['impressions_search'],
            'maps'   => (int)$current['impressions_maps'],
            'calls'  => (int)$current['call_clicks'],
            'web'    => (int)$current['website_clicks'],
            'dirs'   => (int)$current['direction_requests'],
        ];

        // Tendances : comparaison mois du rapport vs mois precedent
        $trends = [];
        if ($previous) {
            $keys = ['search' => 'impressions_search', 'maps' => 'impressions_maps', 'calls' => 'call_clicks', 'web' => 'website_clicks', 'dirs' => 'direction_requests'];
            foreach ($keys as $k => $col) {
                $cur = (int)$current[$col];
                $prev = (int)$previous[$col];
                if ($prev > 0) {
                    $pct = round(($cur - $prev) / $prev * 100);
                    $dir = $pct > 0 ? 'up' : ($pct < 0 ? 'down' : 'stable');
                    $trends[$k] = ['pct' => $pct, 'dir' => $dir];
                }
            }
        }

        // Nom du mois du rapport pour le sous-titre
        $reportMonthLabel = $this->monthLabel($current['month']);

        // --- VISIBILITE : 2 KPI boxes cote a cote ---
        $this->subTitle('Visibilité - ' . $reportMonthLabel);
        $this->explanatoryText("Nombre de fois où votre fiche est apparue dans les résultats de recherche Google et sur Google Maps ce mois-ci, comparé au mois précédent.");

        $y = $pdf->GetY();
        $this->kpiBox(12, $y, 92, 'Recherche Google', $curMonth['search'], $trends['search'] ?? null, $this->primary);
        $this->kpiBox(108, $y, 92, 'Google Maps', $curMonth['maps'], $trends['maps'] ?? null, $this->orange);
        $pdf->SetY($y + 28);

        // --- INTERACTIONS : 3 KPI boxes ---
        $this->subTitle('Interactions clients - ' . $reportMonthLabel);
        $this->explanatoryText("Actions concrètes des internautes ce mois-ci : appels téléphoniques, visites sur votre site web et demandes d'itinéraire.");

        $y = $pdf->GetY();
        $this->kpiBox(12, $y, 60, 'Appels', $curMonth['calls'], $trends['calls'] ?? null, $this->green);
        $this->kpiBox(76, $y, 60, 'Site web', $curMonth['web'], $trends['web'] ?? null, $this->blue);
        $this->kpiBox(140, $y, 60, 'Itinéraires', $curMonth['dirs'], $trends['dirs'] ?? null, $this->pink);
        $pdf->SetY($y + 28);

        // --- GRAPHIQUES D'EVOLUTION ---
        if (count($monthly) >= 3) {
            // Graphique Visibilite
            $this->checkPageBreak(65);
            $this->subTitle('Évolution de la visibilité');
            $y = $pdf->GetY();
            $this->drawBarChart(14, $y, 182, 55, $monthly, [
                ['col' => 'impressions_search', 'label' => 'Recherche Google', 'color' => $this->primary],
                ['col' => 'impressions_maps', 'label' => 'Google Maps', 'color' => $this->orange],
            ]);
            $pdf->SetY($y + 58);

            // Graphique Interactions
            $this->checkPageBreak(65);
            $this->subTitle('Évolution des interactions');
            $y = $pdf->GetY();
            $this->drawBarChart(14, $y, 182, 55, $monthly, [
                ['col' => 'call_clicks', 'label' => 'Appels', 'color' => $this->green],
                ['col' => 'website_clicks', 'label' => 'Site web', 'color' => $this->blue],
                ['col' => 'direction_requests', 'label' => 'Itinéraires', 'color' => $this->pink],
            ]);
            $pdf->SetY($y + 58);
        }

        // --- TABLEAU MENSUEL ---
        if (count($monthly) >= 2) {
            $this->checkPageBreak(50);
            $this->subTitle('Détail mensuel');

            // En-têtes
            $pdf->SetFont('Helvetica', 'B', 8);
            $pdf->SetFillColor(...$this->bgLight);
            $this->setColor($this->gray);
            $pdf->Cell(28, 6, 'Mois', 0, 0, 'L', true);
            $pdf->Cell(14, 6, 'Jours', 0, 0, 'C', true);
            $pdf->Cell(28, 6, 'Recherche', 0, 0, 'R', true);
            $pdf->Cell(24, 6, 'Maps', 0, 0, 'R', true);
            $pdf->Cell(22, 6, 'Appels', 0, 0, 'R', true);
            $pdf->Cell(22, 6, 'Web', 0, 0, 'R', true);
            $pdf->Cell(22, 6, $this->u('Itinéraires'), 0, 0, 'R', true);
            $pdf->Cell(28, 6, 'Total', 0, 1, 'R', true);

            $pdf->SetFont('Helvetica', '', 8);
            $even = false;
            foreach (array_reverse($monthly) as $m) {
                if ($even) {
                    $pdf->SetFillColor(250, 251, 253);
                    $fill = true;
                } else {
                    $fill = false;
                }
                $total = (int)$m['impressions_search'] + (int)$m['impressions_maps'] + (int)$m['call_clicks'] + (int)$m['website_clicks'] + (int)$m['direction_requests'];

                $this->setColor($this->dark);
                $pdf->Cell(28, 5.5, $this->monthLabel($m['month']), 0, 0, 'L', $fill);
                $this->setColor($this->gray);
                $pdf->Cell(14, 5.5, $m['days'], 0, 0, 'C', $fill);
                $this->setColor($this->dark);
                $pdf->Cell(28, 5.5, number_format((int)$m['impressions_search'], 0, ',', ' '), 0, 0, 'R', $fill);
                $pdf->Cell(24, 5.5, number_format((int)$m['impressions_maps'], 0, ',', ' '), 0, 0, 'R', $fill);
                $pdf->Cell(22, 5.5, (int)$m['call_clicks'], 0, 0, 'R', $fill);
                $pdf->Cell(22, 5.5, (int)$m['website_clicks'], 0, 0, 'R', $fill);
                $pdf->Cell(22, 5.5, (int)$m['direction_requests'], 0, 0, 'R', $fill);
                $pdf->SetFont('Helvetica', 'B', 8);
                $pdf->Cell(28, 5.5, number_format($total, 0, ',', ' '), 0, 1, 'R', $fill);
                $pdf->SetFont('Helvetica', '', 8);

                $even = !$even;
            }
            $pdf->Ln(6);
        }

        // --- EVOLUTION DEPUIS LA PRISE EN CHARGE ---
        $managedSince = !empty($location['created_at']) ? $location['created_at'] : null;
        if ($managedSince && count($monthly) >= 2) {
            // Chercher le 1er mois complet apres la prise en charge
            $managedMonth = date('Y-m', strtotime($managedSince));
            $firstMonth = null;
            foreach ($monthly as $m) {
                // Prendre le 1er mois avec au moins 15 jours de donnees
                if ($m['month'] >= $managedMonth && (int)$m['days'] >= 15) {
                    $firstMonth = $m;
                    break;
                }
            }

            if ($firstMonth && $firstMonth['month'] !== $current['month']) {
                $this->checkPageBreak(40);
                $sinceDate = date('d/m/Y', strtotime($managedSince));
                $this->subTitle('Évolution depuis la prise en charge (' . $sinceDate . ')');
                $this->explanatoryText("Comparaison entre le premier mois de suivi (" . $this->monthLabel($firstMonth['month']) . ") et le mois actuel (" . $this->monthLabel($current['month']) . "). Ces chiffres montrent l'impact concret de l'optimisation SEO local sur votre visibilité.");

                $keys = [
                    'impressions_search' => ['label' => 'Recherche Google', 'color' => $this->primary],
                    'impressions_maps'   => ['label' => 'Google Maps', 'color' => $this->orange],
                    'call_clicks'        => ['label' => 'Appels', 'color' => $this->green],
                    'website_clicks'     => ['label' => 'Site web', 'color' => $this->blue],
                    'direction_requests' => ['label' => 'Itinéraires', 'color' => $this->pink],
                ];

                $y = $pdf->GetY();
                $x = 12;
                $colW = 37;

                // Header ligne : metrique, debut, maintenant, evolution
                $pdf->SetFont('Helvetica', 'B', 7);
                $pdf->SetFillColor(...$this->bgLight);
                $this->setColor($this->gray);
                $pdf->SetXY($x, $y);
                $pdf->Cell($colW, 5.5, '', 0, 0, 'L', true);
                $pdf->Cell(30, 5.5, $this->u($this->monthLabel($firstMonth['month'])), 0, 0, 'C', true);
                $pdf->Cell(30, 5.5, $this->u($this->monthLabel($current['month'])), 0, 0, 'C', true);
                $pdf->Cell(28, 5.5, $this->u('Évolution'), 0, 1, 'C', true);

                $pdf->SetFont('Helvetica', '', 8);
                $even = false;
                foreach ($keys as $col => $info) {
                    $first = (int)$firstMonth[$col];
                    $cur   = (int)$current[$col];
                    $diff  = $cur - $first;
                    $pct   = $first > 0 ? round(($cur - $first) / $first * 100) : ($cur > 0 ? 100 : 0);

                    if ($even) {
                        $pdf->SetFillColor(250, 251, 253);
                        $fill = true;
                    } else {
                        $fill = false;
                    }

                    $pdf->SetXY($x, $pdf->GetY());
                    $pdf->SetFont('Helvetica', 'B', 8);
                    $pdf->SetTextColor(...$info['color']);
                    $pdf->Cell($colW, 5.5, $this->u($info['label']), 0, 0, 'L', $fill);

                    $pdf->SetFont('Helvetica', '', 8);
                    $this->setColor($this->gray);
                    $pdf->Cell(30, 5.5, number_format($first, 0, ',', ' '), 0, 0, 'C', $fill);
                    $this->setColor($this->dark);
                    $pdf->Cell(30, 5.5, number_format($cur, 0, ',', ' '), 0, 0, 'C', $fill);

                    // Evolution avec couleur
                    $pdf->SetFont('Helvetica', 'B', 8);
                    if ($pct > 0) {
                        $pdf->SetTextColor(...$this->green);
                        $evoText = '+' . $pct . '%';
                    } elseif ($pct < 0) {
                        $pdf->SetTextColor(...$this->red);
                        $evoText = $pct . '%';
                    } else {
                        $this->setColor($this->gray);
                        $evoText = '=';
                    }
                    $pdf->Cell(28, 5.5, $evoText, 0, 1, 'C', $fill);

                    $even = !$even;
                }
                $pdf->Ln(4);
            }
        }

        $pdf->Ln(4);
    }

    // ================================================================
    // SECTION : CLASSEMENT DES MOTS-CLES
    // ================================================================
    private function sectionKeywords(int $locationId, array $location): void {
        $pdf = $this->pdf;

        $this->checkPageBreak(60);
        $this->sectionTitle('Classement des mots-clés');

        $this->explanatoryText("Ce tableau montre la position de votre fiche dans les résultats Google pour chaque mot-clé stratégique. Plus la position est proche de 1, plus vous êtes visible. Le Local Pack désigne le top 3 mis en avant par Google sur la carte.");

        // Mots-cles + positions + ville cible
        $stmt = db()->prepare('
            SELECT k.id, k.keyword, k.target_city, kp.position, kp.in_local_pack
            FROM keywords k
            LEFT JOIN keyword_positions kp ON kp.keyword_id = k.id
                AND kp.tracked_at = (SELECT MAX(tracked_at) FROM keyword_positions WHERE keyword_id = k.id)
            WHERE k.location_id = ? AND k.is_active = 1
            ORDER BY COALESCE(kp.position, 999) ASC
        ');
        $stmt->execute([$locationId]);
        $keywords = $stmt->fetchAll();

        if (empty($keywords)) {
            $this->emptyText('Aucun mot-clé suivi pour cette fiche.');
            return;
        }

        // KPI resume
        $total = count($keywords);
        $top3 = 0; $top10 = 0; $top20 = 0; $notRanked = 0;
        foreach ($keywords as $kw) {
            $pos = $kw['position'];
            if (!$pos || $pos > 20) { $notRanked++; continue; }
            if ($pos <= 3) $top3++;
            if ($pos <= 10) $top10++;
            if ($pos <= 20) $top20++;
        }

        $y = $pdf->GetY();
        $this->kpiBox(12, $y, 44, 'Top 3', $top3 . '/' . $total, null, $this->green);
        $this->kpiBox(60, $y, 44, 'Top 10', $top10 . '/' . $total, null, $this->orange);
        $this->kpiBox(108, $y, 44, 'Top 20', $top20 . '/' . $total, null, $this->blue);
        $this->kpiBox(156, $y, 44, 'Non classé', $notRanked . '/' . $total, null, $this->red);
        $pdf->SetY($y + 28);

        // Tableau des mots-cles
        $pdf->SetFont('Helvetica', 'B', 8);
        $pdf->SetFillColor(...$this->bgLight);
        $this->setColor($this->gray);
        $pdf->Cell(60, 6, $this->u('Mot-clé'), 0, 0, 'L', true);
        $pdf->Cell(34, 6, 'Ville suivie', 0, 0, 'L', true);
        $pdf->Cell(24, 6, 'Position', 0, 0, 'C', true);
        $pdf->Cell(24, 6, 'Local Pack', 0, 0, 'C', true);
        $pdf->Cell(46, 6, 'Niveau', 0, 1, 'C', true);

        $pdf->SetFont('Helvetica', '', 9);
        $even = false;
        foreach ($keywords as $kw) {
            $this->checkPageBreak(8);
            $pos = $kw['position'] ?? null;
            $posLabel = $pos ? "#$pos" : '-';
            $lp = !empty($kw['in_local_pack']) ? 'Oui' : '-';

            // Couleur et niveau selon position
            if ($pos && $pos <= 3) {
                $color = $this->green;
                $level = 'Excellent';
            } elseif ($pos && $pos <= 10) {
                $color = $this->orange;
                $level = 'Bon';
            } elseif ($pos && $pos <= 20) {
                $color = $this->blue;
                $level = $this->u('À améliorer');
            } else {
                $color = $this->red;
                $level = 'Non visible';
            }

            if ($even) {
                $pdf->SetFillColor(250, 251, 253);
                $fill = true;
            } else {
                $fill = false;
            }

            $this->setColor($this->dark);
            $pdf->Cell(60, 6, $this->u($kw['keyword']), 0, 0, 'L', $fill);

            // Ville suivie
            $city = $kw['target_city'] ?? '';
            // Extraire juste le nom de ville (avant la virgule)
            if ($city && strpos($city, ',') !== false) $city = trim(explode(',', $city)[0]);
            $this->setColor($this->gray);
            $pdf->SetFont('Helvetica', '', 8);
            $pdf->Cell(34, 6, $this->u($city ?: '-'), 0, 0, 'L', $fill);

            // Position avec couleur
            $pdf->SetTextColor(...$color);
            $pdf->SetFont('Helvetica', 'B', 9);
            $pdf->Cell(24, 6, $posLabel, 0, 0, 'C', $fill);
            $pdf->SetFont('Helvetica', '', 9);

            // Local Pack
            if ($lp === 'Oui') {
                $pdf->SetTextColor(...$this->green);
                $pdf->SetFont('Helvetica', 'B', 9);
            } else {
                $this->setColor($this->gray);
            }
            $pdf->Cell(24, 6, $lp, 0, 0, 'C', $fill);
            $pdf->SetFont('Helvetica', '', 8);

            // Niveau
            $pdf->SetTextColor(...$color);
            $pdf->Cell(46, 6, $level, 0, 1, 'C', $fill);

            $even = !$even;
        }

        // Grille de visibilite (si disponible)
        $this->addGridVisibility($locationId, $keywords);

        $this->setColor($this->dark);
        $pdf->Ln(6);
    }

    /**
     * Ajouter les scores de visibilite grille si disponibles
     */
    private function addGridVisibility(int $locationId, array $keywords): void {
        $pdf = $this->pdf;
        $keywordIds = array_column($keywords, 'id');
        if (empty($keywordIds)) return;

        $placeholders = implode(',', array_fill(0, count($keywordIds), '?'));
        $stmt = db()->prepare("
            SELECT gs.keyword_id, k.keyword, gs.avg_position, gs.visibility_score,
                   gs.top3_count, gs.top10_count, gs.top20_count, gs.out_count, gs.total_points
            FROM grid_scans gs
            JOIN keywords k ON gs.keyword_id = k.id
            WHERE gs.keyword_id IN ($placeholders)
              AND gs.scanned_at = (SELECT MAX(scanned_at) FROM grid_scans WHERE keyword_id = gs.keyword_id)
            ORDER BY gs.visibility_score DESC
        ");
        $stmt->execute($keywordIds);
        $grids = $stmt->fetchAll();

        if (empty($grids)) return;

        $this->checkPageBreak(40);
        $pdf->Ln(4);
        $this->subTitle('Visibilité locale (grille géographique)');
        $this->explanatoryText("La grille de visibilité simule des recherches depuis 49 points GPS autour de votre adresse. Le score indique dans quel pourcentage de ces points vous apparaissez dans le top 20. Plus ce pourcentage est élevé, plus vous dominez votre zone.");

        // En-tetes
        $pdf->SetFont('Helvetica', 'B', 8);
        $pdf->SetFillColor(...$this->bgLight);
        $this->setColor($this->gray);
        $pdf->Cell(65, 6, $this->u('Mot-clé'), 0, 0, 'L', true);
        $pdf->Cell(25, 6, $this->u('Visibilité'), 0, 0, 'C', true);
        $pdf->Cell(22, 6, 'Pos. moy.', 0, 0, 'C', true);
        $pdf->Cell(22, 6, 'Top 3', 0, 0, 'C', true);
        $pdf->Cell(22, 6, 'Top 10', 0, 0, 'C', true);
        $pdf->Cell(22, 6, 'Top 20', 0, 0, 'C', true);
        $pdf->Cell(12, 6, 'Hors', 0, 1, 'C', true);

        $pdf->SetFont('Helvetica', '', 8);
        $even = false;
        foreach ($grids as $g) {
            $this->checkPageBreak(7);
            $vis = (int)$g['visibility_score'];
            $avg = $g['avg_position'] ? round((float)$g['avg_position'], 1) : '-';

            if ($vis >= 70) $visColor = $this->green;
            elseif ($vis >= 40) $visColor = $this->orange;
            else $visColor = $this->red;

            if ($even) {
                $pdf->SetFillColor(250, 251, 253);
                $fill = true;
            } else { $fill = false; }

            $this->setColor($this->dark);
            $pdf->Cell(65, 5.5, $this->u($g['keyword']), 0, 0, 'L', $fill);

            $pdf->SetTextColor(...$visColor);
            $pdf->SetFont('Helvetica', 'B', 9);
            $pdf->Cell(25, 5.5, $vis . '%', 0, 0, 'C', $fill);
            $pdf->SetFont('Helvetica', '', 8);

            $this->setColor($this->dark);
            $pdf->Cell(22, 5.5, "#$avg", 0, 0, 'C', $fill);
            $pdf->SetTextColor(...$this->green);
            $pdf->Cell(22, 5.5, (int)$g['top3_count'], 0, 0, 'C', $fill);
            $pdf->SetTextColor(...$this->orange);
            $pdf->Cell(22, 5.5, (int)$g['top10_count'], 0, 0, 'C', $fill);
            $pdf->SetTextColor(...$this->blue);
            $pdf->Cell(22, 5.5, (int)$g['top20_count'], 0, 0, 'C', $fill);
            $pdf->SetTextColor(...$this->red);
            $pdf->Cell(12, 5.5, (int)$g['out_count'], 0, 1, 'C', $fill);

            $even = !$even;
        }
        $this->setColor($this->dark);
    }

    // ================================================================
    // SECTION : AVIS GOOGLE
    // ================================================================
    private function sectionReviews(int $locationId): void {
        $pdf = $this->pdf;

        $this->checkPageBreak(50);
        $this->sectionTitle('Avis Google');
        $this->explanatoryText("Les avis clients sont un facteur majeur de votre référencement local. Répondre à chaque avis (positif comme négatif) améliore votre visibilité et renforce la confiance des prospects.");

        $stmt = db()->prepare('
            SELECT COUNT(*) as total, ROUND(AVG(rating), 1) as avg_rating,
                   SUM(CASE WHEN is_replied = 0 THEN 1 ELSE 0 END) as unanswered,
                   SUM(CASE WHEN rating = 5 THEN 1 ELSE 0 END) as s5,
                   SUM(CASE WHEN rating = 4 THEN 1 ELSE 0 END) as s4,
                   SUM(CASE WHEN rating = 3 THEN 1 ELSE 0 END) as s3,
                   SUM(CASE WHEN rating = 2 THEN 1 ELSE 0 END) as s2,
                   SUM(CASE WHEN rating = 1 THEN 1 ELSE 0 END) as s1
            FROM reviews WHERE location_id = ?
        ');
        $stmt->execute([$locationId]);
        $stats = $stmt->fetch();

        if (!$stats || !(int)$stats['total']) {
            $this->emptyText('Aucun avis enregistré.');
            return;
        }

        // KPI boxes
        $y = $pdf->GetY();
        $avgColor = ($stats['avg_rating'] >= 4.5) ? $this->green : (($stats['avg_rating'] >= 3.5) ? $this->orange : $this->red);
        $this->kpiBox(12, $y, 60, 'Note moyenne', ($stats['avg_rating'] ?? '-') . '/5', null, $avgColor);
        $this->kpiBox(76, $y, 60, 'Total avis', (int)$stats['total'], null, $this->primary);
        $unColor = ((int)$stats['unanswered'] > 0) ? $this->red : $this->green;
        $this->kpiBox(140, $y, 60, 'Sans réponse', (int)$stats['unanswered'], null, $unColor);
        $pdf->SetY($y + 28);

        // Distribution des notes
        $pdf->SetFont('Helvetica', '', 9);
        $total = (int)$stats['total'];
        for ($star = 5; $star >= 1; $star--) {
            $count = (int)$stats["s{$star}"];
            $pct = $total > 0 ? round($count / $total * 100) : 0;
            $barWidth = $total > 0 ? round($count / $total * 100) : 0;

            $this->setColor($this->dark);
            $pdf->Cell(20, 5, $this->u($star . ' étoile' . ($star > 1 ? 's' : '')), 0, 0, 'L');

            // Barre de progression
            $barX = $pdf->GetX();
            $barY = $pdf->GetY() + 0.5;
            $pdf->SetFillColor(235, 238, 242);
            $pdf->Rect($barX, $barY, 100, 4, 'F');
            if ($barWidth > 0) {
                $barColor = $star >= 4 ? $this->green : ($star >= 3 ? $this->orange : $this->red);
                $pdf->SetFillColor(...$barColor);
                $pdf->Rect($barX, $barY, min($barWidth, 100), 4, 'F');
            }
            $pdf->SetX($barX + 104);
            $this->setColor($this->gray);
            $pdf->Cell(20, 5, "$count ($pct%)", 0, 1, 'L');
        }

        $pdf->Ln(8);
    }

    // ================================================================
    // SECTION : GOOGLE POSTS (sans brouillons ni echoues)
    // ================================================================
    private function sectionPosts(int $locationId): void {
        $pdf = $this->pdf;

        $this->checkPageBreak(40);
        $this->sectionTitle('Publications Google');
        $this->explanatoryText("Les Google Posts permettent de communiquer directement avec vos clients via votre fiche. Publier régulièrement des posts améliore votre référencement et montre à Google que votre fiche est active.");

        $stmt = db()->prepare('
            SELECT
                   SUM(CASE WHEN status = "published" THEN 1 ELSE 0 END) as published,
                   SUM(CASE WHEN status = "scheduled" OR status = "list_pending" THEN 1 ELSE 0 END) as scheduled
            FROM google_posts WHERE location_id = ?
        ');
        $stmt->execute([$locationId]);
        $stats = $stmt->fetch();

        $published = (int)($stats['published'] ?? 0);
        $scheduled = (int)($stats['scheduled'] ?? 0);
        $totalPub = $published + $scheduled;

        $y = $pdf->GetY();
        $this->kpiBox(12, $y, 60, 'Total publications', $totalPub, null, $this->primary);
        $this->kpiBox(76, $y, 60, 'Publiés', $published, null, $this->green);
        $this->kpiBox(140, $y, 60, 'Planifiés', $scheduled, null, $this->blue);
        $pdf->SetY($y + 28);

        $pdf->Ln(4);
    }

    // ================================================================
    // SECTION : ETAT FICHE GOOGLE (Places API)
    // ================================================================
    private function sectionGooglePlaces(int $locationId, string $startDate, string $endDate): void {
        $pdf = $this->pdf;

        // Recuperer le place_id de la location
        $stmt = db()->prepare('SELECT place_id FROM gbp_locations WHERE id = ? AND places_api_linked = 1');
        $stmt->execute([$locationId]);
        $loc = $stmt->fetch();

        if (!$loc || empty($loc['place_id'])) {
            return; // Pas de Places API liee, on skip silencieusement
        }

        $placeId = $loc['place_id'];

        // Recuperer les donnees du cache
        $stmtC = db()->prepare('SELECT raw_data, is_sab, updated_at FROM google_places_cache WHERE place_id = ? AND mode = ? ORDER BY updated_at DESC LIMIT 1');
        $stmtC->execute([$placeId, 'extended']);
        $cache = $stmtC->fetch(PDO::FETCH_ASSOC);

        if (!$cache) {
            return; // Pas de donnees en cache
        }

        $placeData = json_decode($cache['raw_data'], true);
        if (!$placeData) return;

        $isSab = (int)$cache['is_sab'];

        // Recuperer l'historique stats pour evolution M vs M-1
        $stmtH = db()->prepare('
            SELECT stat_date, rating, total_reviews, total_photos, completeness_score
            FROM google_places_stats_history
            WHERE place_id = ? AND stat_date >= DATE_SUB(?, INTERVAL 60 DAY)
            ORDER BY stat_date ASC
        ');
        $stmtH->execute([$placeId, $endDate]);
        $history = $stmtH->fetchAll(PDO::FETCH_ASSOC);

        // Recuperer les derniers avis Places
        $stmtR = db()->prepare('
            SELECT author_name, rating, text_content, review_time, has_owner_reply
            FROM google_places_reviews
            WHERE place_id = ? ORDER BY review_time DESC LIMIT 5
        ');
        $stmtR->execute([$placeId]);
        $reviews = $stmtR->fetchAll(PDO::FETCH_ASSOC);

        // Score de completude
        $completeness = calculatePlacesCompletenessScore($placeData);

        // ====== RENDU ======
        $this->checkPageBreak(50);
        $this->sectionTitle('État de la fiche Google');
        $this->explanatoryText("Données issues de Google Places API en temps réel. Dernier rafraîchissement : " . date('d/m/Y', strtotime($cache['updated_at'])) . ".");

        // --- KPI Row 1 : Identite ---
        $name = $placeData['displayName']['text'] ?? '';
        $address = $isSab ? 'Adresse masquée (fiche SAB)' : ($placeData['formattedAddress'] ?? '');
        $category = $placeData['primaryTypeDisplayName']['text'] ?? ($placeData['primaryType'] ?? '');
        $status = $placeData['businessStatus'] ?? 'OPERATIONAL';

        $pdf->SetFont('Helvetica', 'B', 11);
        $this->setColor($this->dark);
        $pdf->Cell(0, 6, $this->u($name), 0, 1, 'L');
        $pdf->SetFont('Helvetica', '', 9);
        $this->setColor($this->gray);
        $pdf->Cell(0, 5, $this->u($address), 0, 1, 'L');
        if ($category) {
            $pdf->Cell(0, 5, $this->u('Catégorie : ' . $category), 0, 1, 'L');
        }
        // Statut
        $statusLabel = 'Ouvert';
        $statusColor = $this->green;
        if ($status === 'CLOSED_TEMPORARILY') { $statusLabel = 'Fermé temporairement'; $statusColor = $this->orange; }
        elseif ($status === 'CLOSED_PERMANENTLY') { $statusLabel = 'Fermé définitivement'; $statusColor = $this->red; }
        $pdf->SetFont('Helvetica', 'B', 9);
        $pdf->SetTextColor(...$statusColor);
        $pdf->Cell(0, 5, $this->u('Statut : ' . $statusLabel), 0, 1, 'L');
        $pdf->Ln(4);

        // --- KPI Row 2 : Note, Avis, Photos, Completude ---
        $rating = $placeData['rating'] ?? 0;
        $totalReviews = $placeData['userRatingCount'] ?? 0;
        $totalPhotos = count($placeData['photos'] ?? []);
        $compScore = $completeness['score'] ?? 0;

        // Evolution M vs M-1
        $ratingTrend = null;
        $reviewsTrend = null;
        if (count($history) >= 2) {
            $latest = end($history);
            // Chercher le snapshot ~30j avant
            $prevDate = date('Y-m-d', strtotime($latest['stat_date'] . ' -30 days'));
            $prev = null;
            foreach ($history as $h) {
                if ($h['stat_date'] <= $prevDate) $prev = $h;
            }
            if ($prev) {
                if ($prev['rating'] > 0) {
                    $ratingDiff = round(($latest['rating'] ?? 0) - ($prev['rating'] ?? 0), 1);
                    $ratingTrend = ['value' => ($ratingDiff >= 0 ? '+' : '') . $ratingDiff, 'positive' => $ratingDiff >= 0];
                }
                if ($prev['total_reviews'] > 0) {
                    $reviewsDiff = (int)($latest['total_reviews'] ?? 0) - (int)($prev['total_reviews'] ?? 0);
                    $reviewsTrend = ['value' => ($reviewsDiff >= 0 ? '+' : '') . $reviewsDiff . ' avis', 'positive' => $reviewsDiff >= 0];
                }
            }
        }

        $this->checkPageBreak(32);
        $y = $pdf->GetY();
        $ratingColor = $rating >= 4.5 ? $this->green : ($rating >= 3.5 ? $this->orange : $this->red);
        $this->kpiBox(12, $y, 43, 'Note Google', number_format($rating, 1) . '/5', $ratingTrend, $ratingColor);
        $this->kpiBox(58, $y, 43, 'Nombre d\'avis', (string)$totalReviews, $reviewsTrend, $this->blue);
        $this->kpiBox(104, $y, 43, 'Photos', (string)$totalPhotos, null, $this->pink);
        $compColor = $compScore >= 80 ? $this->green : ($compScore >= 50 ? $this->orange : $this->red);
        $this->kpiBox(150, $y, 43, 'Complétude', $compScore . '%', null, $compColor);
        $pdf->SetY($y + 28);
        $pdf->Ln(2);

        // --- Horaires ---
        $hasHours = !empty($placeData['regularOpeningHours']['weekdayDescriptions']);
        if ($hasHours) {
            $this->checkPageBreak(30);
            $this->subTitle('Horaires');
            $pdf->SetFont('Helvetica', '', 8);
            $this->setColor($this->dark);
            foreach ($placeData['regularOpeningHours']['weekdayDescriptions'] as $line) {
                $pdf->Cell(0, 4, $this->u($line), 0, 1, 'L');
            }
            $pdf->Ln(3);
        } else {
            $this->subTitle('Horaires');
            $pdf->SetFont('Helvetica', '', 9);
            $this->setColor($this->orange);
            $pdf->Cell(0, 5, $this->u('Aucun horaire renseigné sur la fiche.'), 0, 1, 'L');
            $pdf->Ln(3);
        }

        // --- Checklist completude ---
        $this->checkPageBreak(40);
        $this->subTitle('Checklist de complétude (' . $compScore . '%)');
        $pdf->SetFont('Helvetica', '', 8);
        foreach ($completeness['checks'] as $check) {
            $icon = $check['ok'] ? 'V' : 'X';
            $color = $check['ok'] ? $this->green : $this->red;
            $pdf->SetTextColor(...$color);
            $pdf->Cell(6, 4, $icon, 0, 0, 'L');
            $this->setColor($this->dark);
            $pdf->Cell(80, 4, $this->u($check['label']), 0, 0, 'L');
            $this->setColor($this->gray);
            $pdf->Cell(0, 4, $this->u(($check['ok'] ? 'OK' : 'À compléter') . ' (' . $check['weight'] . ' pts)'), 0, 1, 'L');
        }
        $pdf->Ln(3);

        // --- 5 derniers avis ---
        if (!empty($reviews)) {
            $this->checkPageBreak(35);
            $this->subTitle('Derniers avis Google');
            $pdf->SetFont('Helvetica', '', 8);

            foreach ($reviews as $rev) {
                $this->checkPageBreak(14);
                $stars = str_repeat('*', (int)$rev['rating']) . str_repeat('-', 5 - (int)$rev['rating']);
                $author = $rev['author_name'] ?: 'Anonyme';
                $date = $rev['review_time'] ? date('d/m/Y', strtotime($rev['review_time'])) : '';
                $replied = $rev['has_owner_reply'] ? ' [Répondu]' : ' [Sans réponse]';
                $replyColor = $rev['has_owner_reply'] ? $this->green : $this->orange;

                // Ligne auteur + note
                $pdf->SetFont('Helvetica', 'B', 8);
                $this->setColor($this->dark);
                $pdf->Cell(50, 4, $this->u($author), 0, 0, 'L');
                $pdf->SetFont('Helvetica', '', 8);
                $this->setColor($this->gray);
                $pdf->Cell(20, 4, $stars, 0, 0, 'L');
                $pdf->Cell(20, 4, $date, 0, 0, 'L');
                $pdf->SetFont('Helvetica', 'B', 7);
                $pdf->SetTextColor(...$replyColor);
                $pdf->Cell(0, 4, $this->u($replied), 0, 1, 'L');

                // Texte de l'avis (tronque)
                $text = $rev['text_content'] ?? '';
                if (mb_strlen($text) > 150) $text = mb_substr($text, 0, 150) . '...';
                if ($text) {
                    $pdf->SetFont('Helvetica', '', 7);
                    $this->setColor($this->gray);
                    $pdf->MultiCell(180, 3.5, $this->u('"' . $text . '"'), 0, 'L');
                }
                $pdf->Ln(1.5);
            }
            $pdf->Ln(2);
        }

        $pdf->Ln(4);
    }

    // ================================================================
    // FOOTER
    // ================================================================
    private function renderFooter(): void {
        $pdf = $this->pdf;
        $pdf->Ln(8);

        // Ligne fine
        $pdf->SetDrawColor(...$this->lightGray);
        $pdf->SetLineWidth(0.2);
        $pdf->Line(10, $pdf->GetY(), 200, $pdf->GetY());
        $pdf->Ln(4);

        $pdf->SetFont('Helvetica', '', 8);
        $this->setColor($this->gray);
        $pdf->Cell(0, 4, $this->u("Rapport généré par Neura — une solution développée par BOUS'TACOM — " . date('d/m/Y à H:i')), 0, 1, 'C');
        $pdf->Cell(0, 4, $this->u("Ce rapport est confidentiel et destiné uniquement au destinataire."), 0, 1, 'C');
    }

    // ================================================================
    // HELPERS
    // ================================================================

    /** Titre de section principal */
    private function sectionTitle(string $title): void {
        $pdf = $this->pdf;
        $pdf->Ln(2);
        $pdf->SetFont('Helvetica', 'B', 14);
        $this->setColor($this->dark);
        $pdf->Cell(0, 8, $this->u($title), 0, 1, 'L');
        $pdf->SetDrawColor(...$this->primary);
        $pdf->SetLineWidth(0.5);
        $pdf->Line(10, $pdf->GetY(), 50, $pdf->GetY());
        $pdf->Ln(3);
    }

    /** Sous-titre */
    private function subTitle(string $title): void {
        $pdf = $this->pdf;
        $pdf->SetFont('Helvetica', 'B', 10);
        $this->setColor($this->dark);
        $pdf->Cell(0, 6, $this->u($title), 0, 1, 'L');
        $pdf->Ln(2);
    }

    /** Texte explicatif italique */
    private function explanatoryText(string $text): void {
        $pdf = $this->pdf;
        $pdf->SetFont('Helvetica', 'I', 9);
        $this->setColor($this->gray);
        $pdf->MultiCell(190, 4.5, $this->u($text), 0, 'L');
        $pdf->Ln(4);
    }

    /** Texte vide */
    private function emptyText(string $text): void {
        $pdf = $this->pdf;
        $pdf->SetFont('Helvetica', '', 10);
        $this->setColor($this->gray);
        $pdf->Cell(0, 6, $this->u($text), 0, 1, 'L');
        $pdf->Ln(6);
    }

    /** Box KPI avec optionnel % tendance */
    private function kpiBox(float $x, float $y, float $w, string $label, $value, ?array $trend, array $color): void {
        $pdf = $this->pdf;

        // Background
        $pdf->SetFillColor(...$this->bgLight);
        $pdf->Rect($x, $y, $w, 24, 'F');

        // Accent line en haut
        $pdf->SetFillColor(...$color);
        $pdf->Rect($x, $y, $w, 1.5, 'F');

        // Label
        $pdf->SetXY($x + 4, $y + 3);
        $pdf->SetFont('Helvetica', '', 7);
        $this->setColor($this->gray);
        $pdf->Cell($w - 8, 4, strtoupper($this->u($label)), 0, 1, 'L');

        // Valeur
        $pdf->SetXY($x + 4, $y + 9);
        $pdf->SetFont('Helvetica', 'B', 16);
        $pdf->SetTextColor(...$color);

        $valStr = is_numeric($value) ? number_format((int)$value, 0, ',', ' ') : (string)$value;
        $pdf->Cell($w - 8, 8, $this->u($valStr), 0, 1, 'L');

        // Tendance (ASCII only — no Unicode arrows)
        if ($trend) {
            $pdf->SetXY($x + 4, $y + 17);
            $pdf->SetFont('Helvetica', '', 7);
            $arrow = $trend['dir'] === 'up' ? '+' : ($trend['dir'] === 'down' ? '-' : '=');
            $pct = ($trend['pct'] > 0 ? '+' : '') . $trend['pct'] . '%';
            $tColor = $trend['dir'] === 'up' ? $this->green : ($trend['dir'] === 'down' ? $this->red : $this->gray);
            $pdf->SetTextColor(...$tColor);
            $pdf->Cell($w - 8, 3, $this->u("$pct vs mois préc."), 0, 1, 'L');
        }

        $this->setColor($this->dark);
    }

    /**
     * Dessine un graphique a barres groupees avec ligne de tendance (total)
     * @param float $x      Position X
     * @param float $y      Position Y
     * @param float $w      Largeur totale
     * @param float $h      Hauteur totale
     * @param array $monthly  Donnees mensuelles
     * @param array $series   [['col' => 'impressions_search', 'label' => 'Recherche', 'color' => [r,g,b]], ...]
     */
    private function drawBarChart(float $x, float $y, float $w, float $h, array $monthly, array $series): void {
        $pdf = $this->pdf;
        $n = count($monthly);
        if ($n < 2) return;

        $labelH = 10;     // hauteur pour les labels X
        $legendH = 8;     // hauteur pour la legende
        $chartH = $h - $labelH - $legendH;
        $chartY = $y + $legendH;

        // Fond du graphique
        $pdf->SetFillColor(250, 251, 253);
        $pdf->Rect($x, $chartY, $w, $chartH, 'F');

        // Lignes horizontales subtiles (grille)
        $pdf->SetDrawColor(230, 232, 236);
        $pdf->SetLineWidth(0.15);
        for ($i = 1; $i <= 4; $i++) {
            $gy = $chartY + ($chartH * $i / 5);
            $pdf->Line($x, $gy, $x + $w, $gy);
        }

        // Calculer le max pour l'echelle
        $maxVal = 1;
        $totals = [];
        foreach ($monthly as $idx => $m) {
            $total = 0;
            foreach ($series as $s) {
                $v = (int)($m[$s['col']] ?? 0);
                if ($v > $maxVal) $maxVal = $v;
                $total += $v;
            }
            $totals[$idx] = $total;
        }
        $maxVal = max($maxVal, 1);
        $maxTotal = max(max($totals), 1);

        // Dimensions des barres
        $seriesCount = count($series);
        $groupW = ($w - 10) / $n;       // largeur par mois
        $barW = max(3, min(10, ($groupW - 4) / $seriesCount)); // largeur par barre
        $groupBarW = $barW * $seriesCount + ($seriesCount - 1); // largeur totale du groupe

        // Dessiner les barres
        foreach ($monthly as $idx => $m) {
            $gx = $x + 5 + ($idx * $groupW) + ($groupW - $groupBarW) / 2;

            foreach ($series as $si => $s) {
                $v = (int)($m[$s['col']] ?? 0);
                $barH = ($v / $maxVal) * ($chartH - 4);
                if ($barH < 1 && $v > 0) $barH = 1;

                $bx = $gx + $si * ($barW + 1);
                $by = $chartY + $chartH - $barH;

                $pdf->SetFillColor(...$s['color']);
                $pdf->Rect($bx, $by, $barW, $barH, 'F');
            }
        }

        // Ligne de tendance (total des series)
        $pdf->SetDrawColor(...$this->primary);
        $pdf->SetLineWidth(0.6);
        $points = [];
        foreach ($monthly as $idx => $m) {
            $px = $x + 5 + ($idx * $groupW) + $groupW / 2;
            $total = $totals[$idx];
            $py = $chartY + $chartH - (($total / $maxTotal) * ($chartH - 4)) - 2;
            $points[] = [$px, $py];
        }
        for ($i = 1; $i < count($points); $i++) {
            $pdf->Line($points[$i-1][0], $points[$i-1][1], $points[$i][0], $points[$i][1]);
        }
        // Points sur la ligne
        foreach ($points as $pt) {
            $pdf->SetFillColor(...$this->primary);
            $pdf->Rect($pt[0] - 1, $pt[1] - 1, 2, 2, 'F');
        }

        // Labels X (mois)
        $pdf->SetFont('Helvetica', '', 6);
        $this->setColor($this->gray);
        foreach ($monthly as $idx => $m) {
            $lx = $x + 5 + ($idx * $groupW);
            $pdf->SetXY($lx, $chartY + $chartH + 1);
            $pdf->Cell($groupW, 4, $this->monthLabel($m['month']), 0, 0, 'C');
        }

        // Legende
        $lx = $x + 2;
        $ly = $y;
        $pdf->SetFont('Helvetica', '', 6.5);
        foreach ($series as $s) {
            $pdf->SetFillColor(...$s['color']);
            $pdf->Rect($lx, $ly + 1.5, 5, 3, 'F');
            $pdf->SetXY($lx + 6, $ly);
            $this->setColor($this->gray);
            $pdf->Cell(30, 5, $this->u($s['label']), 0, 0, 'L');
            $lx += $pdf->GetStringWidth($this->u($s['label'])) + 12;
        }
        // Legende ligne tendance
        $pdf->SetDrawColor(...$this->primary);
        $pdf->SetLineWidth(0.5);
        $pdf->Line($lx, $ly + 3, $lx + 6, $ly + 3);
        $pdf->SetXY($lx + 7, $ly);
        $this->setColor($this->gray);
        $pdf->Cell(20, 5, 'Tendance', 0, 0, 'L');

        // Echelle Y a gauche (max)
        $pdf->SetFont('Helvetica', '', 5.5);
        $this->setColor($this->lightGray);
        $pdf->SetXY($x - 1, $chartY);
        $pdf->Cell(10, 3, number_format($maxVal, 0, ',', ' '), 0, 0, 'R');
        $pdf->SetXY($x - 1, $chartY + $chartH - 3);
        $pdf->Cell(10, 3, '0', 0, 0, 'R');

        $this->setColor($this->dark);
    }

    /** Compute trends from last 2 complete months */
    private function computeTrends(array $monthly): array {
        if (count($monthly) < 2) return [];
        $current = $monthly[count($monthly) - 1];
        $previous = $monthly[count($monthly) - 2];

        // Si mois courant < 28 jours, utiliser les 2 precedents
        if ((int)$current['days'] < 28 && count($monthly) >= 3) {
            $current = $monthly[count($monthly) - 2];
            $previous = $monthly[count($monthly) - 3];
        }

        $trends = [];
        $keys = ['search' => 'impressions_search', 'maps' => 'impressions_maps', 'calls' => 'call_clicks', 'web' => 'website_clicks', 'dirs' => 'direction_requests'];
        foreach ($keys as $k => $col) {
            $cur = (int)$current[$col];
            $prev = (int)$previous[$col];
            if ($prev > 0) {
                $pct = round(($cur - $prev) / $prev * 100);
                $dir = $pct > 0 ? 'up' : ($pct < 0 ? 'down' : 'stable');
                $trends[$k] = ['pct' => $pct, 'dir' => $dir];
            }
        }
        return $trends;
    }

    private function getLocationData(int $locationId): ?array {
        $stmt = db()->prepare('SELECT * FROM gbp_locations WHERE id = ?');
        $stmt->execute([$locationId]);
        return $stmt->fetch() ?: null;
    }

    private function setColor(array $c): void {
        $this->pdf->SetTextColor($c[0], $c[1], $c[2]);
    }

    /**
     * Convertit UTF-8 en ISO-8859-1 pour FPDF.
     * Les accents français (é, è, ê, à, ç, etc.) sont dans ISO-8859-1 → passent sans problème.
     * Les caractères Unicode hors ISO-8859-1 (emojis, guillemets typographiques, etc.)
     * sont remplacés par des équivalents ASCII avant la conversion.
     */
    private function u(string $s): string {
        // Remplacer les caractères Unicode NON ISO-8859-1 par des équivalents ASCII
        $s = str_replace(
            ['—', '–', "\u{2019}", "\u{2018}", "\u{201C}", "\u{201D}", "\u{2026}",
             "\u{2022}", "\u{2122}", "\u{00D7}", "\u{2192}", "\u{2190}", "\u{2191}", "\u{2193}",
             "\u{2713}", "\u{2717}", "\u{2605}", "\u{2606}", "\u{0153}", "\u{0152}",
             "\u{25B2}", "\u{25BC}", "\u{25BA}", "\u{2014}", "\u{2013}"],
            ['-', '-', "'", "'", '"', '"', '...',
             '-', 'TM', 'x', '->', '<-', '+', '-',
             'V', 'X', '*', '-', 'oe', 'OE',
             '+', '-', '>', '-', '-'],
            $s
        );
        // Supprimer les emojis (plages Unicode U+1F000+ et symboles divers)
        $s = preg_replace('/[\x{1F000}-\x{1FFFF}]|[\x{2600}-\x{27BF}]|[\x{FE00}-\x{FE0F}]|[\x{200D}]|[\x{20E3}]/u', '', $s);
        // Convertir UTF-8 → ISO-8859-1 avec translittération (plus robuste que utf8_decode)
        $converted = @iconv('UTF-8', 'ISO-8859-1//TRANSLIT//IGNORE', $s);
        return $converted !== false ? $converted : utf8_decode($s);
    }

    private function formatPeriod(string $start, string $end): string {
        $mois = ['', 'Janvier', 'Février', 'Mars', 'Avril', 'Mai', 'Juin', 'Juillet', 'Août', 'Septembre', 'Octobre', 'Novembre', 'Décembre'];
        $sM = $mois[(int)date('n', strtotime($start))] . ' ' . date('Y', strtotime($start));
        $eM = $mois[(int)date('n', strtotime($end))] . ' ' . date('Y', strtotime($end));
        return ($sM === $eM) ? $sM : "$sM - $eM";
    }

    private function monthLabel(string $ym): string {
        $mois = ['Jan', 'Fév', 'Mar', 'Avr', 'Mai', 'Jun', 'Jul', 'Aoû', 'Sep', 'Oct', 'Nov', 'Déc'];
        $parts = explode('-', $ym);
        return $mois[(int)$parts[1] - 1] . ' ' . $parts[0];
    }

    private function checkPageBreak(float $height): void {
        if ($this->pdf->GetY() + $height > 275) {
            $this->pdf->AddPage();
        }
    }
}
