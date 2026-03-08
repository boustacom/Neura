<?php
/**
 * BOUS'TACOM — Générateur de PDF d'audit prospect
 *
 * Design sobre, élégant, branding Neura.
 * Sections : Couverture, Résumé exécutif, Visibilité locale,
 *            E-réputation, Présence digitale, Recommandations
 *
 * IMPORTANT: FPDF utilise ISO-8859-1. Tous les textes passent par u()
 */

$fpdfPath = __DIR__ . '/fpdf/fpdf.php';
if (file_exists($fpdfPath)) {
    require_once $fpdfPath;
}

class AuditReportGenerator {

    private $pdf;
    private $audit;
    private $gridPoints;
    private $competitors;
    private $breakdown;

    private $primary = [37, 99, 235];
    private $green = [34, 197, 94];
    private $orange = [245, 158, 11];
    private $red = [239, 68, 68];
    private $pink = [236, 72, 153];
    private $blue = [59, 130, 246];
    private $dark = [26, 26, 46];
    private $gray = [120, 130, 150];
    private $lightGray = [200, 210, 220];
    private $bgLight = [245, 247, 250];

    public function __construct(array $audit, array $gridPoints = [], array $competitors = []) {
        $this->audit = $audit;
        $this->gridPoints = $gridPoints;
        $this->competitors = $competitors;
        $auditData = is_string($audit['audit_data'] ?? '') ? json_decode($audit['audit_data'], true) : ($audit['audit_data'] ?? []);
        $this->breakdown = $auditData['score_breakdown'] ?? [];
    }

    /**
     * Génère le PDF et le sauvegarde au chemin indiqué
     */
    public function generate(string $filepath): void {
        if (!class_exists('FPDF')) return;

        $this->pdf = new \FPDF('P', 'mm', 'A4');
        $this->pdf->SetAutoPageBreak(true, 20);

        $this->renderCover();
        $this->renderExecutiveSummary();
        $this->renderVisibility();
        $this->renderReputation();
        $this->renderPresence();
        $this->renderActivity();
        $this->renderRecommendations();
        $this->renderFooter();

        $this->pdf->Output('F', $filepath);
    }

    // ================================================================
    // PAGE 1 : COUVERTURE
    // ================================================================
    private function renderCover(): void {
        $pdf = $this->pdf;
        $pdf->AddPage();

        // Bande colorée en haut
        $pdf->SetFillColor(...$this->primary);
        $pdf->Rect(0, 0, 210, 4, 'F');

        // Logo
        $pdf->SetY(30);
        $pdf->SetFont('Helvetica', 'B', 32);
        $this->setColor($this->dark);
        $pdf->Cell(0, 14, $this->u('NEURA'), 0, 1, 'C');

        $pdf->SetFont('Helvetica', '', 12);
        $this->setColor($this->gray);
        $pdf->Cell(0, 6, $this->u('Audit de visibilité locale'), 0, 1, 'C');
        $pdf->Ln(20);

        // Nom du business
        $pdf->SetFont('Helvetica', 'B', 22);
        $this->setColor($this->dark);
        $name = $this->audit['business_name'] ?? 'Entreprise';
        $pdf->Cell(0, 12, $this->u($name), 0, 1, 'C');

        // Ville
        $city = $this->audit['city'] ?? '';
        if ($city) {
            $pdf->SetFont('Helvetica', '', 14);
            $this->setColor($this->gray);
            $pdf->Cell(0, 8, $this->u($city), 0, 1, 'C');
        }

        $pdf->Ln(16);

        // Score global — grand cercle
        $score = (int)($this->audit['score'] ?? 0);
        $scoreColor = $score >= 70 ? $this->green : ($score >= 40 ? $this->orange : $this->red);

        // Box score centrée
        $boxW = 80;
        $boxX = (210 - $boxW) / 2;
        $boxY = $pdf->GetY();

        $pdf->SetFillColor(...$this->bgLight);
        $pdf->Rect($boxX, $boxY, $boxW, 45, 'F');

        // Accent bar
        $pdf->SetFillColor(...$scoreColor);
        $pdf->Rect($boxX, $boxY, $boxW, 2, 'F');

        // Score number
        $pdf->SetXY($boxX, $boxY + 6);
        $pdf->SetFont('Helvetica', 'B', 42);
        $pdf->SetTextColor(...$scoreColor);
        $pdf->Cell($boxW, 16, $this->u($score . '/100'), 0, 1, 'C');

        // Label
        $pdf->SetXY($boxX, $boxY + 26);
        $pdf->SetFont('Helvetica', '', 10);
        $this->setColor($this->gray);
        $pdf->Cell($boxW, 5, 'SCORE GLOBAL', 0, 1, 'C');

        // Niveau texte
        $level = $score >= 70 ? 'Bon' : ($score >= 40 ? 'À améliorer' : 'Faible');
        $pdf->SetXY($boxX, $boxY + 33);
        $pdf->SetFont('Helvetica', 'B', 11);
        $pdf->SetTextColor(...$scoreColor);
        $pdf->Cell($boxW, 5, $this->u($level), 0, 1, 'C');

        $pdf->SetY($boxY + 55);

        // Explication simple pour le prospect
        $pdf->SetFont('Helvetica', '', 9);
        $this->setColor($this->gray);
        $pdf->MultiCell(0, 4.5, $this->u("Ce rapport analyse la visibilité de votre entreprise sur Google. Quand un client potentiel recherche vos services sur Google ou Google Maps, apparaissez-vous en bonne position ? C'est ce que j'ai vérifié pour vous."), 0, 'C');

        $pdf->Ln(4);

        // Zone analysée
        $city = $this->audit['city'] ?? '';
        $keyword = $this->audit['keyword'] ?? '';
        if ($city || $keyword) {
            $pdf->SetFont('Helvetica', 'B', 9);
            $this->setColor($this->primary);
            $zoneText = 'Zone analysée : ';
            if ($keyword) $zoneText .= '"' . $keyword . '"';
            $address = $this->audit['address'] ?? '';
            if ($address) {
                $zoneText .= ' autour de ' . $address . ' (rayon ~15 km)';
            } elseif ($city) {
                $zoneText .= ' à ' . $city . ' et dans un rayon d\'environ 15 km';
            }
            $pdf->Cell(0, 5, $this->u($zoneText), 0, 1, 'C');
        }

        $pdf->Ln(4);

        // Date
        $pdf->SetFont('Helvetica', '', 9);
        $this->setColor($this->gray);
        $pdf->Cell(0, 5, $this->u('Rapport généré le ' . date('d/m/Y')), 0, 1, 'C');

        // Bande colorée en bas
        $pdf->SetFillColor(...$this->primary);
        $pdf->Rect(0, 293, 210, 4, 'F');
    }

    // ================================================================
    // PAGE 2 : RÉSUMÉ EXÉCUTIF
    // ================================================================
    private function renderExecutiveSummary(): void {
        $pdf = $this->pdf;
        $pdf->AddPage();

        // Header bande
        $pdf->SetFillColor(...$this->primary);
        $pdf->Rect(0, 0, 210, 3, 'F');
        $pdf->Ln(8);

        $this->sectionTitle('Résumé exécutif');

        $this->explanatoryText("Votre score global reflète la santé de votre présence en ligne sur 4 piliers. Plus le score est élevé, plus vous avez de chances d'apparaître devant vos concurrents quand un client potentiel recherche vos services sur Google. Objectif : viser un score supérieur à 70/100.");

        $score = (int)($this->audit['score'] ?? 0);

        // 4 KPI boxes
        $vis = $this->breakdown['visibility']['score'] ?? 0;
        $rep = $this->breakdown['reputation']['score'] ?? 0;
        $pre = $this->breakdown['presence']['score'] ?? 0;
        $act = $this->breakdown['activity']['score'] ?? 0;

        $y = $pdf->GetY();
        $this->kpiBox(12, $y, 44, 'Visibilité', $vis . '/35', $this->scoreColor($vis, 35));
        $this->kpiBox(60, $y, 44, 'E-réputation', $rep . '/25', $this->scoreColor($rep, 25));
        $this->kpiBox(108, $y, 44, 'Présence', $pre . '/25', $this->scoreColor($pre, 25));
        $this->kpiBox(156, $y, 44, 'Activité', $act . '/15', $this->scoreColor($act, 15));
        $pdf->SetY($y + 30);

        // Barre de score horizontale
        $pdf->Ln(4);
        $barY = $pdf->GetY();
        $barW = 180;
        $barX = 15;
        $barH = 8;

        // Fond
        $pdf->SetFillColor(235, 238, 242);
        $pdf->Rect($barX, $barY, $barW, $barH, 'F');

        // Remplissage
        $fillW = max(2, ($score / 100) * $barW);
        $scoreColor = $score >= 70 ? $this->green : ($score >= 40 ? $this->orange : $this->red);
        $pdf->SetFillColor(...$scoreColor);
        $pdf->Rect($barX, $barY, $fillW, $barH, 'F');

        // Score texte sur la barre
        $pdf->SetXY($barX, $barY);
        $pdf->SetFont('Helvetica', 'B', 8);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->Cell($fillW, $barH, $this->u($score . '/100'), 0, 0, 'C');

        $pdf->SetY($barY + $barH + 8);

        // Texte résumé auto-généré
        $this->setColor($this->dark);
        $pdf->SetFont('Helvetica', '', 10);

        $name = $this->audit['business_name'] ?? 'L\'établissement';
        $city = $this->audit['city'] ?? '';

        $position = (int)($this->audit['target_rank'] ?? $this->audit['position'] ?? 0);

        if ($score >= 70) {
            $summary = "$name" . ($city ? " à $city" : '') . " obtient un score de $score/100. La fiche présente de bonnes bases, mais des optimisations ciblées permettraient de consolider l'avantage concurrentiel et maximiser l'acquisition de clients.";
        } elseif ($score >= 40) {
            $nbAbove = $position > 1 ? $position - 1 : 0;
            $summary = "$name" . ($city ? " à $city" : '') . " obtient un score de $score/100. " . ($position > 3 ? "{$nbAbove} concurrent(s) apparaissent avant vous dans les résultats locaux. " : '') . "Plusieurs faiblesses identifiées impactent directement votre visibilité et votre capacité à attirer de nouveaux clients.";
        } else {
            $summary = "$name" . ($city ? " à $city" : '') . " obtient un score de $score/100, révélant des lacunes significatives. Chaque jour sans optimisation représente des clients perdus au profit de vos concurrents mieux référencés.";
        }

        $pdf->MultiCell(180, 5.5, $this->u($summary), 0, 'L');
        $pdf->Ln(6);

        // Infos fiche
        $this->subTitle('Informations de la fiche');

        $infos = [
            ['Nom', $this->audit['business_name'] ?? '-'],
            ['Ville', $this->audit['city'] ?? '-'],
            ['Adresse', $this->audit['address'] ?? '-'],
            ['Catégorie', $this->audit['category'] ?? '-'],
            ['Site web', $this->audit['domain'] ?? 'Non renseigné'],
            ['Téléphone', $this->audit['prospect_phone'] ?? 'Non renseigné'],
            ['Note Google', ($this->audit['rating'] ?? 0) ? $this->audit['rating'] . '/5 (objectif : 4.5+)' : 'N/A'],
            ['Nombre d\'avis', ((int)($this->audit['reviews_count'] ?? 0)) . ' (objectif : 50+)'],
            ['Photos', ((int)($this->audit['total_photos'] ?? 0) ?: '0') . ' (objectif : 30+)'],
            ['Description', !empty($this->audit['description']) ? 'Oui' : 'Non rédigée (à rédiger)'],
            ['Rang local', ($this->audit['target_rank'] ?? $this->audit['position'] ?? 0) ? '#' . ($this->audit['target_rank'] ?? $this->audit['position']) . ' (objectif : Top 3)' : 'N/A'],
        ];

        // Ajouter le mot-clé et la zone analysés si disponibles
        $keyword = $this->audit['keyword'] ?? '';
        if ($keyword) {
            $zoneInfo = $keyword;
            $cityInfo = $this->audit['city'] ?? '';
            $addressInfo = $this->audit['address'] ?? '';
            if ($addressInfo) {
                $zoneInfo .= ' - autour de ' . $addressInfo . ' (rayon ~15 km)';
            } elseif ($cityInfo) {
                $zoneInfo .= ' - ' . $cityInfo . ' (rayon ~15 km)';
            }
            array_splice($infos, 4, 0, [['Mot-clé analysé', $zoneInfo]]);
        }

        $pdf->SetFont('Helvetica', '', 9);
        $even = false;
        foreach ($infos as $info) {
            if ($even) {
                $pdf->SetFillColor(250, 251, 253);
            } else {
                $pdf->SetFillColor(...$this->bgLight);
            }
            $this->setColor($this->gray);
            $pdf->Cell(50, 5.5, $this->u($info[0]), 0, 0, 'L', true);
            $this->setColor($this->dark);
            $pdf->SetFont('Helvetica', 'B', 9);
            $pdf->Cell(140, 5.5, $this->u((string)$info[1]), 0, 1, 'L', true);
            $pdf->SetFont('Helvetica', '', 9);
            $even = !$even;
        }

        $pdf->Ln(4);
    }

    // ================================================================
    // SECTION : VISIBILITÉ LOCALE
    // ================================================================
    private function renderVisibility(): void {
        $pdf = $this->pdf;

        $this->checkPageBreak(80);
        $this->sectionTitle('Visibilité locale');

        $visDetails = $this->breakdown['visibility']['details'] ?? [];
        $visScore = $this->breakdown['visibility']['score'] ?? 0;

        $this->explanatoryText("Quand un client recherche un service sur Google (ex. \"plombier Brive\"), Google affiche une carte avec 3 entreprises. C'est le \"Local Pack\" : seuls ces 3 résultats sont visibles immédiatement. Si vous n'y êtes pas, le client ne vous verra pas, car 75% des gens ne cliquent pas sur \"Plus de résultats\". L'objectif est d'être dans ce Top 3.");

        // KPIs principaux — target_rank (rang compétitif grille) prioritaire sur position (single-point)
        $targetRank = (int)($this->audit['target_rank'] ?? 0);
        if (!$targetRank) {
            foreach ($this->competitors as $comp) {
                if ((int)($comp['is_target'] ?? 0) === 1) { $targetRank = (int)($comp['rank'] ?? 0); break; }
            }
        }
        $position = $targetRank ?: (int)($this->audit['position'] ?? 0);
        $gridVis = (int)($this->audit['grid_visibility'] ?? 0);
        $top3 = (int)($this->audit['grid_top3'] ?? 0);

        $y = $pdf->GetY();
        $posColor = $position && $position <= 3 ? $this->green : ($position && $position <= 7 ? $this->orange : $this->red);
        $this->kpiBox(12, $y, 58, 'Rang local', $position ? '#' . $position : 'N/A', $posColor);

        $visColor = $gridVis >= 50 ? $this->green : ($gridVis >= 20 ? $this->orange : $this->red);
        $this->kpiBox(74, $y, 58, $this->u('Visibilité locale'), $gridVis . '%', $visColor);

        $nbAbove = $position > 1 ? $position - 1 : 0;
        $this->kpiBox(136, $y, 58, 'Concurrents devant', $nbAbove > 0 ? (string)$nbAbove : '0', $nbAbove > 0 ? $this->red : $this->green);
        $pdf->SetY($y + 30);

        // Objectifs à viser
        $pdf->SetFont('Helvetica', 'I', 8);
        $this->setColor($this->primary);
        $pdf->Cell(62, 4, $this->u('Objectif : Top 3'), 0, 0, 'C');
        $pdf->Cell(62, 4, $this->u('Objectif : > 70%'), 0, 0, 'C');
        $pdf->Cell(62, 4, $this->u('Objectif : 0'), 0, 1, 'C');
        $this->setColor($this->dark);
        $pdf->Ln(4);

        // Explication rang
        $pdf->SetFont('Helvetica', '', 10);
        $this->setColor($this->dark);
        if ($position > 3) {
            $comment = "Position #{$position} : {$nbAbove} concurrent(s) apparaissent avant vous. Concrètement, quand un client potentiel recherche vos services, il voit d'abord vos concurrents. Votre fiche n'est visible que si le client fait la démarche de cliquer sur \"Plus de résultats\".";
        } elseif ($position >= 1) {
            $comment = "Position #{$position} : bonne nouvelle, votre fiche apparaît directement quand un client recherche vos services. C'est un avantage important qu'il faut maintenir et consolider.";
        } else {
            $comment = "Position non détectée. Votre fiche n'apparaît pas dans les résultats pour ce mot-clé. Les clients qui recherchent ce service ne vous trouvent pas sur Google.";
        }
        $pdf->MultiCell(180, 5, $this->u($comment), 0, 'L');
        $pdf->Ln(4);

        // Stats grille detaillees
        if ($this->audit['grid_scan_id']) {
            $top10 = (int)($this->audit['grid_top10'] ?? 0);
            $top20 = (int)($this->audit['grid_top20'] ?? 0);
            $totalPts = 49;

            $city = $this->audit['city'] ?? '';
            $address = $this->audit['address'] ?? '';
            $locationLabel = $address ?: ($city ?: 'votre adresse');
            $this->subTitle('Couverture géographique — rayon d\'environ 15 km autour de ' . $locationLabel);

            $pdf->SetFont('Helvetica', 'I', 8);
            $this->setColor($this->gray);
            $pdf->MultiCell(180, 4, $this->u("J'ai simulé 49 recherches Google depuis des points différents répartis dans un rayon d'environ 15 km autour de votre localisation" . ($address ? " ($address)" : '') . ". Cela permet de vérifier si votre entreprise est visible partout dans votre zone de chalandise, et pas uniquement à proximité immédiate. Par exemple, un client qui vous cherche depuis un quartier voisin ou une commune limitrophe vous trouvera-t-il ?"), 0, 'L');
            $this->setColor($this->dark);
            $pdf->Ln(4);

            $y = $pdf->GetY();
            $this->kpiBox(12, $y, 44, 'Top 3', $top3 . '/' . $totalPts, $top3 >= 25 ? $this->green : ($top3 >= 10 ? $this->orange : $this->red));
            $this->kpiBox(60, $y, 44, 'Top 10', $top10 . '/' . $totalPts, $top10 >= 35 ? $this->green : ($top10 >= 20 ? $this->orange : $this->red));
            $this->kpiBox(108, $y, 44, 'Top 20', $top20 . '/' . $totalPts, $top20 >= 40 ? $this->green : ($top20 >= 25 ? $this->orange : $this->red));
            $this->kpiBox(156, $y, 44, 'Hors top 20', ($totalPts - $top20) . '/' . $totalPts, $this->red);
            $pdf->SetY($y + 30);

            // Objectifs à viser
            $pdf->SetFont('Helvetica', 'I', 8);
            $this->setColor($this->primary);
            $pdf->Cell(48, 4, $this->u('Objectif : > 25/49'), 0, 0, 'C');
            $pdf->Cell(48, 4, $this->u('Objectif : > 35/49'), 0, 0, 'C');
            $pdf->Cell(48, 4, $this->u('Objectif : > 40/49'), 0, 0, 'C');
            $pdf->Cell(48, 4, $this->u('Objectif : 0'), 0, 1, 'C');
            $this->setColor($this->dark);
            $pdf->Ln(3);

            // Commentaire grille contextuel
            $pdf->SetFont('Helvetica', '', 9);
            if ($top10 >= $totalPts * 0.8 && $top3 < $totalPts * 0.3) {
                // Cas fréquent : bien classé partout (Top 10) mais rarement Top 3
                $pdf->SetTextColor(...$this->orange);
                $pdf->MultiCell(180, 4.5, $this->u("Bonne nouvelle : votre fiche apparaît dans le Top 10 sur {$top10}/{$totalPts} points de votre zone. Vous êtes présent mais pas encore en position dominante. L'objectif est de passer du Top 10 au Top 3 pour maximiser votre visibilité dans le Local Pack Google."), 0, 'L');
            } elseif ($top3 >= $totalPts * 0.5) {
                $pdf->SetTextColor(...$this->green);
                $pdf->MultiCell(180, 4.5, $this->u("Excellent : votre fiche apparaît dans le Top 3 sur {$top3}/{$totalPts} points. Vous dominez votre zone géographique. Continuez à maintenir cette position."), 0, 'L');
            } elseif ($top3 < $totalPts * 0.5) {
                $pctMissing = round(100 - (($top3 / $totalPts) * 100));
                $pdf->SetTextColor(...$this->red);
                $pdf->MultiCell(180, 4.5, $this->u("Concrètement : sur {$pctMissing}% de votre zone géographique, ce sont vos concurrents qui apparaissent en premier. Les clients potentiels situés en dehors du centre-ville ne vous trouvent pas dans le Top 3 lorsqu'ils recherchent vos services sur Google."), 0, 'L');
            }
            $this->setColor($this->dark);
            $pdf->Ln(3);

            // Tableau concurrents si disponible
            if (!empty($this->competitors)) {
                $this->checkPageBreak(60);
                $this->subTitle('Classement des concurrents sur votre zone');

                $pdf->SetFont('Helvetica', 'I', 8);
                $this->setColor($this->gray);
                $pdf->MultiCell(180, 4, $this->u("Classement basé sur la position moyenne dans les résultats locaux sur les 49 points de la grille. Plus la position est basse, plus l'entreprise est visible."), 0, 'L');
                $this->setColor($this->dark);
                $pdf->Ln(2);

                // Header tableau
                $pdf->SetFont('Helvetica', 'B', 8);
                $pdf->SetFillColor(...$this->bgLight);
                $this->setColor($this->gray);
                $pdf->Cell(12, 6, '#', 0, 0, 'C', true);
                $pdf->Cell(80, 6, 'Entreprise', 0, 0, 'L', true);
                $pdf->Cell(22, 6, 'Note', 0, 0, 'C', true);
                $pdf->Cell(22, 6, 'Avis', 0, 0, 'C', true);
                $pdf->Cell(25, 6, 'Pos. moy.', 0, 0, 'C', true);
                $pdf->Cell(25, 6, $this->u('Vu sur'), 0, 1, 'C', true);

                // Lignes
                foreach ($this->competitors as $comp) {
                    $isTarget = (int)($comp['is_target'] ?? 0) === 1;
                    $rank = $comp['rank'] ?? '—';
                    $avg = (float)($comp['avg_position'] ?? 101);
                    $appearances = (int)($comp['appearances'] ?? 0);

                    // Couleur ligne
                    if ($isTarget) {
                        $pdf->SetFillColor(37, 99, 235, 15);
                        $pdf->SetFillColor(220, 230, 250);
                    }

                    $pdf->SetFont('Helvetica', $isTarget ? 'B' : '', 8);
                    $rankColor = $rank <= 3 ? $this->green : ($rank <= 10 ? $this->orange : $this->red);
                    $pdf->SetTextColor(...$rankColor);
                    $pdf->Cell(12, 5.5, '#' . $rank, 0, 0, 'C', $isTarget);

                    $this->setColor($this->dark);
                    $title = mb_substr($comp['title'] ?? '', 0, 40, 'UTF-8');
                    if (mb_strlen($comp['title'] ?? '', 'UTF-8') > 40) $title .= '...';
                    $pdf->Cell(80, 5.5, $this->u(($isTarget ? '> ' : '') . $title), 0, 0, 'L', $isTarget);

                    $rating = $comp['rating'] ? number_format((float)$comp['rating'], 1) . '/5' : '-';
                    $pdf->Cell(22, 5.5, $this->u($rating), 0, 0, 'C', $isTarget);

                    $pdf->Cell(22, 5.5, $this->u((string)($comp['reviews_count'] ?? '-')), 0, 0, 'C', $isTarget);

                    $avgColor = $avg <= 3 ? $this->green : ($avg <= 10 ? $this->orange : ($avg <= 20 ? $this->pink : $this->red));
                    $pdf->SetTextColor(...$avgColor);
                    $avgLabel = $avg <= 20 ? number_format($avg, 1) : '20+';
                    $pdf->Cell(25, 5.5, $this->u($avgLabel), 0, 0, 'C', $isTarget);

                    $this->setColor($this->dark);
                    $pdf->Cell(25, 5.5, $this->u($appearances . '/49'), 0, 1, 'C', $isTarget);
                }

                $pdf->Ln(3);
            }
        }

        // Détail du scoring — 3 critères transparents
        $this->checkPageBreak(40);
        $this->subTitle('Détail du score Visibilité');

        $rangPts = (int)($visDetails['rang_google'] ?? 0);
        $gridTop3Pts = (int)($visDetails['grid_top3_score'] ?? 0);
        $gridTop10Pts = (int)($visDetails['grid_top10_score'] ?? 0);

        // Header tableau
        $pdf->SetFont('Helvetica', 'B', 8);
        $pdf->SetFillColor(...$this->bgLight);
        $this->setColor($this->gray);
        $pdf->Cell(55, 6, $this->u('Critère'), 0, 0, 'L', true);
        $pdf->Cell(35, 6, $this->u('Votre valeur'), 0, 0, 'C', true);
        $pdf->Cell(20, 6, $this->u('Points'), 0, 0, 'C', true);
        $pdf->Cell(80, 6, $this->u('Barème'), 0, 1, 'L', true);

        // Ligne 1 : Rang local
        $this->setColor($this->dark);
        $pdf->SetFont('Helvetica', 'B', 9);
        $pdf->Cell(55, 5.5, $this->u('Rang local'), 0, 0, 'L');
        $pdf->SetFont('Helvetica', '', 9);
        $pdf->SetTextColor(...$posColor);
        $pdf->Cell(35, 5.5, $this->u($position ? '#' . $position : 'N/A'), 0, 0, 'C');
        $rangColor = $this->scoreColor($rangPts, 15);
        $pdf->SetTextColor(...$rangColor);
        $pdf->SetFont('Helvetica', 'B', 9);
        $pdf->Cell(20, 5.5, $this->u($rangPts . '/15'), 0, 0, 'C');
        $pdf->SetFont('Helvetica', '', 7);
        $this->setColor($this->gray);
        $pdf->Cell(80, 5.5, $this->u('#1=15 | #2=12 | #3=10 | #4=6 | #5=4 | #6-7=2 | #8-10=1'), 0, 1, 'L');

        // Ligne 2 : Couverture top 3 grille
        $this->setColor($this->dark);
        $pdf->SetFont('Helvetica', 'B', 9);
        $pdf->Cell(55, 5.5, $this->u('Grille : couverture Top 3'), 0, 0, 'L');
        $pdf->SetFont('Helvetica', '', 9);
        $top3Ratio = $totalPts > 0 ? round(($top3 / $totalPts) * 100) : 0;
        $t3Color = $top3Ratio >= 50 ? $this->green : ($top3Ratio >= 20 ? $this->orange : $this->red);
        $pdf->SetTextColor(...$t3Color);
        $pdf->Cell(35, 5.5, $this->u($top3 . '/' . $totalPts . ' (' . $top3Ratio . '%)'), 0, 0, 'C');
        $gt3Color = $this->scoreColor($gridTop3Pts, 12);
        $pdf->SetTextColor(...$gt3Color);
        $pdf->SetFont('Helvetica', 'B', 9);
        $pdf->Cell(20, 5.5, $this->u($gridTop3Pts . '/12'), 0, 0, 'C');
        $pdf->SetFont('Helvetica', '', 7);
        $this->setColor($this->gray);
        $pdf->Cell(80, 5.5, $this->u('70%+=12 | 50%+=9 | 30%+=6 | 10%+=3'), 0, 1, 'L');

        // Ligne 3 : Couverture top 10 grille
        $this->setColor($this->dark);
        $pdf->SetFont('Helvetica', 'B', 9);
        $pdf->Cell(55, 5.5, $this->u('Grille : couverture Top 10'), 0, 0, 'L');
        $pdf->SetFont('Helvetica', '', 9);
        $top10Ratio = isset($top10) && $totalPts > 0 ? round(($top10 / $totalPts) * 100) : 0;
        $t10Color = $top10Ratio >= 60 ? $this->green : ($top10Ratio >= 30 ? $this->orange : $this->red);
        $pdf->SetTextColor(...$t10Color);
        $pdf->Cell(35, 5.5, $this->u(isset($top10) ? $top10 . '/' . $totalPts . ' (' . $top10Ratio . '%)' : 'N/A'), 0, 0, 'C');
        $gt10Color = $this->scoreColor($gridTop10Pts, 8);
        $pdf->SetTextColor(...$gt10Color);
        $pdf->SetFont('Helvetica', 'B', 9);
        $pdf->Cell(20, 5.5, $this->u($gridTop10Pts . '/8'), 0, 0, 'C');
        $pdf->SetFont('Helvetica', '', 7);
        $this->setColor($this->gray);
        $pdf->Cell(80, 5.5, $this->u('80%+=8 | 60%+=6 | 40%+=4 | 20%+=2'), 0, 1, 'L');

        $pdf->Ln(4);

        // Score section
        $this->sectionScoreBar('Visibilité locale', $visScore, 35);
        $pdf->Ln(4);
    }

    // ================================================================
    // SECTION : E-REPUTATION
    // ================================================================
    private function renderReputation(): void {
        $pdf = $this->pdf;

        $this->checkPageBreak(80);
        $this->sectionTitle('E-réputation');

        $repScore = $this->breakdown['reputation']['score'] ?? 0;
        $repDetails = $this->breakdown['reputation']['details'] ?? [];

        $this->explanatoryText("Avant de choisir un prestataire, 90% des consommateurs consultent les avis Google. Les avis influencent directement deux choses : la confiance du client (il choisira une entreprise bien notée) et votre position sur Google (Google met en avant les entreprises avec beaucoup d'avis positifs et qui répondent à chaque avis).");

        $rating = (float)($this->audit['rating'] ?? 0);
        $reviews = (int)($this->audit['reviews_count'] ?? 0);

        $y = $pdf->GetY();
        $ratingColor = $rating >= 4.5 ? $this->green : ($rating >= 3.5 ? $this->orange : $this->red);
        $this->kpiBox(12, $y, 86, 'Note moyenne', $rating ? $rating . '/5' : 'N/A', $ratingColor);

        $revColor = $reviews >= 50 ? $this->green : ($reviews >= 10 ? $this->orange : $this->red);
        $this->kpiBox(102, $y, 86, 'Nombre d\'avis', $reviews, $revColor);

        $pdf->SetY($y + 30);

        // Objectifs à viser
        $pdf->SetFont('Helvetica', 'I', 8);
        $this->setColor($this->primary);
        $pdf->Cell(95, 4, $this->u('Objectif : 4.5/5 ou plus'), 0, 0, 'C');
        $pdf->Cell(95, 4, $this->u('Objectif : 50 avis minimum'), 0, 1, 'C');
        $this->setColor($this->dark);
        $pdf->Ln(2);

        // Détail du scoring — 3 critères transparents
        $this->subTitle('Détail du score E-réputation');

        $ratingPts = (int)($repDetails['rating'] ?? 0);
        $reviewsPts = (int)($repDetails['reviews_count'] ?? 0);
        $qualityPts = (int)($repDetails['quality'] ?? 0);

        // Tableau des critères
        $pdf->SetFont('Helvetica', 'B', 8);
        $pdf->SetFillColor(...$this->bgLight);
        $this->setColor($this->gray);
        $pdf->Cell(55, 6, $this->u('Critère'), 0, 0, 'L', true);
        $pdf->Cell(35, 6, $this->u('Votre valeur'), 0, 0, 'C', true);
        $pdf->Cell(20, 6, $this->u('Points'), 0, 0, 'C', true);
        $pdf->Cell(80, 6, $this->u('Barème'), 0, 1, 'L', true);

        $pdf->SetFont('Helvetica', '', 8);

        // Ligne 1 : Note moyenne
        $this->setColor($this->dark);
        $pdf->SetFont('Helvetica', 'B', 9);
        $pdf->Cell(55, 5.5, $this->u('Note moyenne Google'), 0, 0, 'L');
        $pdf->SetFont('Helvetica', '', 9);
        $pdf->SetTextColor(...$ratingColor);
        $pdf->Cell(35, 5.5, $this->u($rating ? $rating . '/5' : 'N/A'), 0, 0, 'C');
        $ratingPtsColor = $this->scoreColor($ratingPts, 12);
        $pdf->SetTextColor(...$ratingPtsColor);
        $pdf->SetFont('Helvetica', 'B', 9);
        $pdf->Cell(20, 5.5, $this->u($ratingPts . '/12'), 0, 0, 'C');
        $pdf->SetFont('Helvetica', '', 7);
        $this->setColor($this->gray);
        $pdf->Cell(80, 5.5, $this->u('4.8+ = 12 | 4.5+ = 10 | 4.2+ = 7 | 4.0+ = 5 | 3.5+ = 3'), 0, 1, 'L');

        // Ligne 2 : Nombre d'avis
        $this->setColor($this->dark);
        $pdf->SetFont('Helvetica', 'B', 9);
        $pdf->Cell(55, 5.5, $this->u('Volume d\'avis'), 0, 0, 'L');
        $pdf->SetFont('Helvetica', '', 9);
        $pdf->SetTextColor(...$revColor);
        $pdf->Cell(35, 5.5, $this->u((string)$reviews . ' avis'), 0, 0, 'C');
        $revPtsColor = $this->scoreColor($reviewsPts, 10);
        $pdf->SetTextColor(...$revPtsColor);
        $pdf->SetFont('Helvetica', 'B', 9);
        $pdf->Cell(20, 5.5, $this->u($reviewsPts . '/10'), 0, 0, 'C');
        $pdf->SetFont('Helvetica', '', 7);
        $this->setColor($this->gray);
        $pdf->Cell(80, 5.5, $this->u('200+ = 10 | 100+ = 7 | 50+ = 5 | 20+ = 3 | 5+ = 1'), 0, 1, 'L');

        // Ligne 3 : Qualité combinée (bonus)
        $this->setColor($this->dark);
        $pdf->SetFont('Helvetica', 'B', 9);
        $pdf->Cell(55, 5.5, $this->u('Bonus qualité (note + volume)'), 0, 0, 'L');
        $pdf->SetFont('Helvetica', '', 9);
        $this->setColor($this->gray);
        $comboLabel = ($rating >= 4.5 && $reviews >= 100) ? 'Excellent combo' : (($rating >= 4.3 && $reviews >= 50) ? 'Bon combo' : (($rating >= 4.0 && $reviews >= 20) ? 'Correct' : 'Insuffisant'));
        $pdf->Cell(35, 5.5, $this->u($comboLabel), 0, 0, 'C');
        $qualPtsColor = $qualityPts >= 2 ? $this->green : ($qualityPts >= 1 ? $this->orange : $this->red);
        $pdf->SetTextColor(...$qualPtsColor);
        $pdf->SetFont('Helvetica', 'B', 9);
        $pdf->Cell(20, 5.5, $this->u($qualityPts . '/3'), 0, 0, 'C');
        $pdf->SetFont('Helvetica', '', 7);
        $this->setColor($this->gray);
        $pdf->Cell(80, 5.5, $this->u('4.5+ & 100+ = 3 | 4.3+ & 50+ = 2 | 4.0+ & 20+ = 1'), 0, 1, 'L');

        $pdf->Ln(4);

        // Commentaire
        $pdf->SetFont('Helvetica', '', 10);
        $this->setColor($this->dark);

        if ($rating >= 4.5 && $reviews >= 50) {
            $comment = "Excellente e-réputation ! Vos clients vous font confiance et cela se voit. Ce volume d'avis positifs est un atout majeur face à vos concurrents. Continuez à répondre à chaque avis pour maintenir cet avantage.";
        } elseif ($rating >= 4.0 && $reviews >= 20) {
            $comment = "Bonne base. Pour passer au niveau supérieur, demandez systématiquement un avis à vos clients satisfaits (par QR code affiché en caisse, SMS après une prestation, ou email de suivi). L'objectif est d'atteindre 50 avis avec une note de 4.5+.";
        } elseif ($rating < 3.5) {
            $comment = "Attention, votre e-réputation nécessite une action urgente. Une note de " . $rating . "/5 fait fuir les prospects. Un client qui compare votre fiche à un concurrent noté 4.7 ne vous contactera pas. Répondez professionnellement à chaque avis négatif et déployez une stratégie pour collecter des avis positifs.";
        } elseif ($reviews < 10) {
            $comment = "Trop peu d'avis pour rassurer un prospect (" . $reviews . " avis seulement). Imaginez : vous cherchez un prestataire et vous voyez " . $reviews . " avis face à un concurrent qui en a 80. Qui choisissez-vous ? De plus, votre note de " . $rating . "/5 ne suffit pas à vous démarquer. Objectif : atteindre 50 avis avec une note supérieure à 4.5.";
        } elseif ($rating < 4.0) {
            $comment = "Note insuffisante pour vous démarquer (" . $rating . "/5). Un client hésitera entre vous et un concurrent noté 4.7 — le choix est vite fait. Identifiez les insatisfactions récurrentes dans vos avis négatifs et mettez en place une collecte d'avis systématique auprès de vos clients satisfaits. Objectif : dépasser 4.5/5.";
        } else {
            $comment = "Note honorable (" . $rating . "/5) mais pas encore au niveau des meilleurs. Pour dominer, visez 4.5+ avec 50+ avis. Demandez systématiquement un avis à vos clients satisfaits.";
        }

        $pdf->MultiCell(180, 5, $this->u($comment), 0, 'L');

        $pdf->Ln(4);

        $this->sectionScoreBar('E-réputation', $repScore, 25);
        $pdf->Ln(4);
    }

    // ================================================================
    // SECTION : PRESENCE DIGITALE
    // ================================================================
    private function renderPresence(): void {
        $pdf = $this->pdf;

        $this->checkPageBreak(70);
        $this->sectionTitle('Présence digitale');

        $preScore = $this->breakdown['presence']['score'] ?? 0;
        $details = $this->breakdown['presence']['details'] ?? [];
        $titleScore = $details['title'] ?? 0;
        $titleIssues = $details['title_issues'] ?? [];
        $photosCount = (int)($details['photos_count'] ?? $this->audit['total_photos'] ?? 0);
        $hasDescription = $details['has_description'] ?? !empty($this->audit['description']);
        $this->explanatoryText("Votre fiche Google Business Profile est votre vitrine en ligne. C'est souvent la première chose qu'un client voit avant même de visiter votre site web. Chaque information manquante (pas de photos, pas de description, pas de numéro de téléphone...) réduit vos chances d'être contacté et votre position sur Google.");

        // Checklist principale — pts alignés avec calculateProspectAuditScore()
        $items = [
            ['Site web', !empty($this->audit['domain']), $this->audit['domain'] ?? '', 5],
            ['Téléphone', !empty($this->audit['prospect_phone']), $this->audit['prospect_phone'] ?? '', 3],
            ['Catégorie', !empty($this->audit['category']), $this->audit['category'] ?? '', 2],
        ];

        foreach ($items as $item) {
            $this->checkPageBreak(8);
            $this->renderChecklistItem($item[0], $item[1], $item[2], $item[3]);
        }

        // Photos (0-5 pts) — avec objectif et barème
        $this->checkPageBreak(14);
        $photosOk = $photosCount >= 30;
        $photoPts = 0;
        if ($photosCount >= 30) $photoPts = 5;
        elseif ($photosCount >= 15) $photoPts = 3;
        elseif ($photosCount >= 5) $photoPts = 2;
        elseif ($photosCount >= 1) $photoPts = 1;
        $photosLabel = $photosCount > 0 ? $photosCount . '/30 photos (obj. 30+)' : 'Aucune photo (obj. 30+)';
        $this->renderChecklistItemPts('Photos', $photosCount >= 15, $photosLabel, $photoPts, 5);
        // Barème sous la ligne
        $pdf->SetFont('Helvetica', 'I', 7);
        $this->setColor($this->gray);
        $pdf->SetX(20);
        $pdf->Cell(170, 4, $this->u('30+ photos = 5pts | 15+ = 3pts | 5+ = 2pts | 1+ = 1pt (extérieur, intérieur, équipe, réalisations)'), 0, 1, 'L');
        $this->setColor($this->dark);

        // Description (0 ou 4 pts)
        $this->checkPageBreak(8);
        $descLabel = $hasDescription ? 'Description rédigée' : 'Aucune description';
        $this->renderChecklistItem('Description GBP', $hasDescription, $descLabel, 4);

        // Titre GBP — analyse spéciale, score réel du breakdown
        $this->checkPageBreak(8);
        $titleOk = $titleScore >= 4;
        $this->renderChecklistItemPts('Titre de la fiche', $titleOk, $this->audit['business_name'] ?? '', (int)$titleScore, 6);

        // Afficher les problèmes de titre
        if (!empty($titleIssues)) {
            $pdf->SetFont('Helvetica', 'I', 8);
            $pdf->SetTextColor(...$this->red);
            foreach ($titleIssues as $issue) {
                $this->checkPageBreak(6);
                $pdf->SetX(20);
                $pdf->Cell(5, 5, '!', 0, 0, 'C');
                $pdf->MultiCell(165, 4.5, $this->u($issue), 0, 'L');
            }
            $this->setColor($this->dark);
            $pdf->Ln(2);
        }

        // Règles Google pour le titre
        $this->checkPageBreak(30);
        $pdf->Ln(2);
        $this->subTitle('Règles Google pour le titre de la fiche');
        $pdf->SetFont('Helvetica', 'I', 8);
        $this->setColor($this->gray);
        $pdf->MultiCell(180, 4, $this->u("Google est très strict sur le titre de votre fiche. Un titre non conforme peut entraîner une suspension de votre fiche (elle disparaît de Google). Voici les règles à respecter :"), 0, 'L');
        $pdf->Ln(2);
        $pdf->SetFont('Helvetica', '', 8);
        $rules = [
            'Le titre doit être le vrai nom de votre entreprise (celui sur votre enseigne)',
            'Pas d\'ajout de mots-clés (ex. "Dupont Plomberie Chauffage Brive" = interdit)',
            'Pas de slogans ou phrases commerciales (ex. "N°1 de la region" = interdit)',
            'Pas de MAJUSCULES excessives (ex. "DUPONT PLOMBERIE" = interdit)',
            'Pas de numéro de téléphone ou d\'adresse dans le titre',
        ];
        foreach ($rules as $rule) {
            $pdf->SetX(14);
            $this->setColor($this->gray);
            $pdf->Cell(4, 4, '-', 0, 0, 'C');
            $pdf->Cell(170, 4, $this->u($rule), 0, 1, 'L');
        }

        $pdf->Ln(4);
        $this->sectionScoreBar('Présence digitale', $preScore, 25);
        $pdf->Ln(4);
    }

    /**
     * Affiche une ligne checklist (OK/X + label + valeur + points)
     */
    private function renderChecklistItem(string $label, bool $ok, string $value, int $maxPts): void {
        $this->renderChecklistItemPts($label, $ok, $value, $ok ? $maxPts : 0, $maxPts);
    }

    /**
     * Affiche une ligne checklist avec score partiel possible
     */
    private function renderChecklistItemPts(string $label, bool $ok, string $value, int $pts, int $maxPts): void {
        $pdf = $this->pdf;

        if ($pts >= $maxPts) {
            $pdf->SetTextColor(...$this->green);
            $pdf->SetFont('Helvetica', 'B', 12);
            $pdf->Cell(8, 6, 'OK', 0, 0, 'C');
        } elseif ($pts > 0) {
            $pdf->SetTextColor(...$this->orange);
            $pdf->SetFont('Helvetica', 'B', 12);
            $pdf->Cell(8, 6, '~', 0, 0, 'C');
        } else {
            $pdf->SetTextColor(...$this->red);
            $pdf->SetFont('Helvetica', 'B', 12);
            $pdf->Cell(8, 6, 'X', 0, 0, 'C');
        }

        $pdf->SetFont('Helvetica', 'B', 10);
        $this->setColor($this->dark);
        $pdf->Cell(45, 6, $this->u($label), 0, 0, 'L');

        $pdf->SetFont('Helvetica', '', 9);
        if ($ok) {
            $this->setColor($this->gray);
            $pdf->Cell(75, 6, $this->u(substr($value, 0, 50)), 0, 0, 'L');
        } else {
            $pdf->SetTextColor(...$this->red);
            $pdf->Cell(75, 6, $this->u($value ?: 'Non renseigné'), 0, 0, 'L');
        }

        $pdf->SetFont('Helvetica', 'B', 9);
        $color = $pts >= $maxPts ? $this->green : ($pts > 0 ? $this->orange : $this->red);
        $pdf->SetTextColor(...$color);
        $pdf->Cell(20, 6, $this->u($pts . '/' . $maxPts . ' pts'), 0, 1, 'R');
    }

    // ================================================================
    // SECTION : ACTIVITÉ
    // ================================================================
    private function renderActivity(): void {
        $pdf = $this->pdf;

        $this->checkPageBreak(50);
        $this->sectionTitle('Activité');

        $actScore = $this->breakdown['activity']['score'] ?? 0;
        $actDetails = $this->breakdown['activity']['details'] ?? [];

        $this->explanatoryText("Google favorise les fiches qui vivent et évoluent. Une fiche avec des photos récentes, des avis réguliers et du contenu à jour montre que l'entreprise est active et professionnelle. À l'inverse, une fiche figée depuis des mois envoie un signal négatif aussi bien à Google qu'aux clients potentiels.");

        $comboPts = (int)($actDetails['combo'] ?? 0);
        $photoActPts = (int)($actDetails['photos_activity'] ?? 0);

        $rating = (float)($this->audit['rating'] ?? 0);
        $reviews = (int)($this->audit['reviews_count'] ?? 0);
        $totalPhotos = (int)($this->audit['total_photos'] ?? 0);

        // Header tableau
        $pdf->SetFont('Helvetica', 'B', 8);
        $pdf->SetFillColor(...$this->bgLight);
        $this->setColor($this->gray);
        $pdf->Cell(55, 6, $this->u('Critère'), 0, 0, 'L', true);
        $pdf->Cell(35, 6, $this->u('Votre valeur'), 0, 0, 'C', true);
        $pdf->Cell(20, 6, $this->u('Points'), 0, 0, 'C', true);
        $pdf->Cell(80, 6, $this->u('Barème'), 0, 1, 'L', true);

        // Ligne 1 : Combo avis + note
        $this->setColor($this->dark);
        $pdf->SetFont('Helvetica', 'B', 9);
        $pdf->Cell(55, 5.5, $this->u('Dynamique avis + note'), 0, 0, 'L');
        $pdf->SetFont('Helvetica', '', 9);
        $comboLabel = $reviews . ' avis, ' . ($rating ?: '0') . '/5';
        $this->setColor($this->gray);
        $pdf->Cell(35, 5.5, $this->u($comboLabel), 0, 0, 'C');
        $comboColor = $this->scoreColor($comboPts, 10);
        $pdf->SetTextColor(...$comboColor);
        $pdf->SetFont('Helvetica', 'B', 9);
        $pdf->Cell(20, 5.5, $this->u($comboPts . '/10'), 0, 0, 'C');
        $pdf->SetFont('Helvetica', '', 7);
        $this->setColor($this->gray);
        $pdf->Cell(80, 5.5, $this->u('100+ & 4.0+=10 | 50+ & 3.5+=7 | 20+ & 3.0+=4 | 10+=2'), 0, 1, 'L');

        // Ligne 2 : Volume photos
        $this->setColor($this->dark);
        $pdf->SetFont('Helvetica', 'B', 9);
        $pdf->Cell(55, 5.5, $this->u('Richesse photo'), 0, 0, 'L');
        $pdf->SetFont('Helvetica', '', 9);
        $photoColor = $totalPhotos >= 25 ? $this->green : ($totalPhotos >= 10 ? $this->orange : $this->red);
        $pdf->SetTextColor(...$photoColor);
        $pdf->Cell(35, 5.5, $this->u($totalPhotos . ' photos'), 0, 0, 'C');
        $paColor = $this->scoreColor($photoActPts, 5);
        $pdf->SetTextColor(...$paColor);
        $pdf->SetFont('Helvetica', 'B', 9);
        $pdf->Cell(20, 5.5, $this->u($photoActPts . '/5'), 0, 0, 'C');
        $pdf->SetFont('Helvetica', '', 7);
        $this->setColor($this->gray);
        $pdf->Cell(80, 5.5, $this->u('50+=5 | 25+=3 | 10+=2 | 3+=1'), 0, 1, 'L');

        $pdf->Ln(4);

        // Commentaire
        $pdf->SetFont('Helvetica', '', 10);
        $this->setColor($this->dark);
        if ($actScore >= 12) {
            $comment = "Très bonne dynamique ! Votre fiche est vivante avec des avis récents et du contenu visuel varié. C'est un signal de confiance fort pour les clients qui vous découvrent.";
        } elseif ($actScore >= 7) {
            $comment = "Activité correcte mais perfectible. Publiez régulièrement des photos de vos réalisations, de votre équipe et de vos locaux. Demandez un avis à chaque client satisfait. Objectif : au moins 50 photos et 100+ avis.";
        } else {
            $comment = "Votre fiche manque de vie. Un prospect qui voit une fiche sans photos récentes ou avec peu d'avis passera à un concurrent. Objectif : ajoutez 5 photos/mois et mettez en place une demande d'avis systématique.";
        }
        $pdf->MultiCell(180, 5, $this->u($comment), 0, 'L');

        $pdf->Ln(4);
        $this->sectionScoreBar('Activité', $actScore, 15);
        $pdf->Ln(4);
    }

    // ================================================================
    // SECTION : RECOMMANDATIONS
    // ================================================================
    private function renderRecommendations(): void {
        $pdf = $this->pdf;

        $this->checkPageBreak(60);
        $this->sectionTitle('Recommandations');

        $this->explanatoryText("Voici les actions prioritaires à mettre en place pour améliorer votre visibilité et attirer plus de clients. Chaque point résolu améliorera votre position sur Google et augmentera vos chances d'être contacté par de nouveaux prospects.");

        $recommendations = $this->breakdown['recommendations'] ?? [];
        if (empty($recommendations)) {
            $recommendations = ['Maintenir la qualité actuelle et surveiller régulièrement la visibilité.'];
        }

        $pdf->SetFont('Helvetica', '', 10);
        foreach ($recommendations as $idx => $reco) {
            $this->checkPageBreak(12);
            $y = $pdf->GetY();

            // Numero
            $pdf->SetFillColor(...$this->primary);
            $num = $idx + 1;
            $pdf->Rect(14, $y + 1, 6, 6, 'F');
            $pdf->SetXY(14, $y + 1);
            $pdf->SetFont('Helvetica', 'B', 8);
            $pdf->SetTextColor(255, 255, 255);
            $pdf->Cell(6, 6, (string)$num, 0, 0, 'C');

            // Texte
            $pdf->SetXY(24, $y);
            $pdf->SetFont('Helvetica', '', 10);
            $this->setColor($this->dark);
            $pdf->MultiCell(170, 5.5, $this->u($reco), 0, 'L');
            $pdf->Ln(2);
        }

        $pdf->Ln(6);
    }

    // ================================================================
    // FOOTER
    // ================================================================
    private function renderFooter(): void {
        $pdf = $this->pdf;
        $pdf->Ln(8);

        $pdf->SetDrawColor(...$this->lightGray);
        $pdf->SetLineWidth(0.2);
        $pdf->Line(10, $pdf->GetY(), 200, $pdf->GetY());
        $pdf->Ln(6);

        // Call-to-action
        $pdf->SetFont('Helvetica', 'B', 11);
        $this->setColor($this->primary);
        $pdf->Cell(0, 6, $this->u('Vous souhaitez améliorer votre visibilité ?'), 0, 1, 'C');
        $pdf->SetFont('Helvetica', '', 9);
        $this->setColor($this->dark);
        $pdf->MultiCell(0, 4.5, $this->u("Contactez-moi pour un accompagnement personnalisé. Je peux vous aider à optimiser votre fiche Google, améliorer votre positionnement et attirer plus de clients grâce au référencement local."), 0, 'C');

        $pdf->Ln(6);

        $pdf->SetFont('Helvetica', '', 8);
        $this->setColor($this->gray);
        $pdf->Cell(0, 4, $this->u("Rapport généré par Neura — une solution développée par BOUS'TACOM — " . date('d/m/Y à H:i')), 0, 1, 'C');
        $pdf->Cell(0, 4, $this->u("Ce rapport est confidentiel et destiné uniquement au destinataire."), 0, 1, 'C');
    }

    // ================================================================
    // HELPERS
    // ================================================================

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

    private function subTitle(string $title): void {
        $pdf = $this->pdf;
        $pdf->SetFont('Helvetica', 'B', 10);
        $this->setColor($this->dark);
        $pdf->Cell(0, 6, $this->u($title), 0, 1, 'L');
        $pdf->Ln(2);
    }

    private function explanatoryText(string $text): void {
        $pdf = $this->pdf;
        $pdf->SetFont('Helvetica', 'I', 9);
        $this->setColor($this->gray);
        $pdf->MultiCell(190, 4.5, $this->u($text), 0, 'L');
        $pdf->Ln(4);
    }

    private function kpiBox(float $x, float $y, float $w, string $label, $value, array $color): void {
        $pdf = $this->pdf;

        $pdf->SetFillColor(...$this->bgLight);
        $pdf->Rect($x, $y, $w, 24, 'F');

        // Accent line
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

        $this->setColor($this->dark);
    }

    private function sectionScoreBar(string $label, int $score, int $max): void {
        $pdf = $this->pdf;
        $pct = $max > 0 ? ($score / $max) * 100 : 0;
        $color = $this->scoreColor($score, $max);

        $pdf->SetFont('Helvetica', 'B', 9);
        $this->setColor($this->dark);
        $pdf->Cell(60, 6, $this->u("Score : $score/$max"), 0, 0, 'L');

        // Barre
        $barX = $pdf->GetX();
        $barY = $pdf->GetY() + 1;
        $barW = 100;
        $barH = 4;

        $pdf->SetFillColor(235, 238, 242);
        $pdf->Rect($barX, $barY, $barW, $barH, 'F');

        $fillW = max(1, ($pct / 100) * $barW);
        $pdf->SetFillColor(...$color);
        $pdf->Rect($barX, $barY, $fillW, $barH, 'F');

        $pdf->SetX($barX + $barW + 4);
        $pdf->SetFont('Helvetica', 'B', 9);
        $pdf->SetTextColor(...$color);
        $pdf->Cell(20, 6, round($pct) . '%', 0, 1, 'L');
    }

    private function scoreColor(int $score, int $max): array {
        $pct = $max > 0 ? ($score / $max) * 100 : 0;
        if ($pct >= 70) return $this->green;
        if ($pct >= 40) return $this->orange;
        return $this->red;
    }

    private function setColor(array $c): void {
        $this->pdf->SetTextColor($c[0], $c[1], $c[2]);
    }

    private function u(string $s): string {
        // Remplacer les caractères Unicode hors ISO-8859-1 avant conversion
        $s = str_replace(
            ["\xe2\x80\x94", "\xe2\x80\x93", "\xe2\x80\x99", "\xe2\x80\x98", "\xe2\x80\x9c", "\xe2\x80\x9d",
             "\xe2\x96\xb2", "\xe2\x96\xbc", "\xe2\x96\xba", "\xc5\x93", "\xe2\x80\xa6", "\xe2\x80\xa2",
             "\xc2\xa0", "\xe2\x80\x8b", "\xe2\x80\x8c", "\xe2\x80\x8d"],
            ['-', '-', "'", "'", '"', '"',
             '+', '-', '>', 'oe', '...', '-',
             ' ', '', '', ''],
            $s
        );
        // Supprimer les emojis et caractères multi-octets hors BMP
        $s = preg_replace('/[\x{1F000}-\x{1FFFF}]|[\x{2600}-\x{27BF}]|[\x{FE00}-\x{FE0F}]|[\x{1F900}-\x{1F9FF}]/u', '', $s) ?? $s;
        // iconv TRANSLIT pour convertir proprement UTF-8 → ISO-8859-1
        $result = @iconv('UTF-8', 'ISO-8859-1//TRANSLIT', $s);
        if ($result !== false) return $result;
        $result = @iconv('UTF-8', 'ISO-8859-1//IGNORE', $s);
        return $result !== false ? $result : $s;
    }

    private function checkPageBreak(float $height): void {
        if ($this->pdf->GetY() + $height > 275) {
            $this->pdf->AddPage();
        }
    }
}
