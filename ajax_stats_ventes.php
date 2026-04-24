<?php
// ════════════════════════════════════════════════
//  ajax_stats_ventes.php
//  Fournit toutes les données pour le panel Stats CA
// ════════════════════════════════════════════════

session_start();
require_once 'db.php';

// Header JSON en PREMIER avant tout echo
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Non autorise']);
    exit;
}

$action = trim($_GET['action'] ?? $_POST['action'] ?? '');

// ─── Helper : plage de dates selon période ───────────────────────
function plage(string $periode): array
{
    switch ($periode) {
        case 'jour':
            return [date('Y-m-d'), date('Y-m-d')];
        case 'semaine':
            return [date('Y-m-d', strtotime('monday this week')), date('Y-m-d')];
        case 'mois':
            return [date('Y-m-01'), date('Y-m-d')];
        case 'trimestre':
            $mois = ceil(date('n') / 3) * 3 - 2;
            return [date('Y-') . str_pad($mois, 2, '0', STR_PAD_LEFT) . '-01', date('Y-m-d')];
        case 'annee':
            return [date('Y-01-01'), date('Y-m-d')];
        default:
            return [date('Y-m-d'), date('Y-m-d')];
    }
}

try {

// ════════════════════════════════════════════════
//  1. STATS JOUR
// ════════════════════════════════════════════════
if ($action === 'stats_jour') {

    $today = date('Y-m-d');

    // KPI globaux
    $stmt = $pdo->prepare("
        SELECT
            COUNT(id_vente)           AS nb_tickets,
            IFNULL(SUM(total), 0)     AS ca_total,
            IFNULL(AVG(total), 0)     AS panier_moyen,
            IFNULL(SUM(remise), 0)    AS total_remises
        FROM ventes
        WHERE DATE(date_vente) = :today
    ");
    $stmt->execute([':today' => $today]);
    $kpi = $stmt->fetch(PDO::FETCH_ASSOC);

    // Répartition modes de paiement
    $stmt2 = $pdo->prepare("
        SELECT mode_paiement, IFNULL(SUM(total), 0) AS ca
        FROM ventes
        WHERE DATE(date_vente) = :today
        GROUP BY mode_paiement
    ");
    $stmt2->execute([':today' => $today]);
    $modes_raw = $stmt2->fetchAll(PDO::FETCH_ASSOC);

    $modes = ['especes' => 0, 'mobile_money' => 0, 'assurance' => 0];
    foreach ($modes_raw as $m) {
        $key = str_replace([' ', "'"], ['_', ''], strtolower((string)$m['mode_paiement']));
        if (str_contains($key, 'espece'))    $modes['especes']      += floatval($m['ca']);
        if (str_contains($key, 'mobile'))    $modes['mobile_money'] += floatval($m['ca']);
        if (str_contains($key, 'assurance')) $modes['assurance']    += floatval($m['ca']);
    }

    // Tranches horaires
    $stmt3 = $pdo->prepare("
        SELECT
            HOUR(date_vente)          AS heure,
            IFNULL(SUM(total), 0)     AS ca
        FROM ventes
        WHERE DATE(date_vente) = :today
        GROUP BY HOUR(date_vente)
        ORDER BY heure ASC
    ");
    $stmt3->execute([':today' => $today]);
    $heures = $stmt3->fetchAll(PDO::FETCH_ASSOC);

    // Top 10 produits du jour
    // CORRECTION : produits n'a pas id_famille -> on passe par sous_familles
    $stmt4 = $pdo->prepare("
        SELECT
            p.nom_commercial                            AS nom,
            IFNULL(f.nom_famille, 'Non classee')        AS famille,
            IFNULL(SUM(dv.quantite), 0)                 AS qte,
            IFNULL(SUM(dv.quantite * dv.prix_unitaire), 0) AS ca
        FROM detail_ventes dv
        JOIN ventes v           ON v.id_vente       = dv.id_vente
        JOIN produits p         ON p.id_produit      = dv.id_produit
        LEFT JOIN sous_familles sf ON sf.id_sous_famille = p.id_sous_famille
        LEFT JOIN familles f    ON f.id_famille      = sf.id_famille
        WHERE DATE(v.date_vente) = :today
        GROUP BY dv.id_produit, p.nom_commercial, f.nom_famille
        ORDER BY ca DESC
        LIMIT 10
    ");
    $stmt4->execute([':today' => $today]);
    $top = $stmt4->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'data'    => [
            'ca_total'      => round(floatval($kpi['ca_total']), 0),
            'nb_tickets'    => intval($kpi['nb_tickets']),
            'panier_moyen'  => round(floatval($kpi['panier_moyen']), 0),
            'total_remises' => round(floatval($kpi['total_remises']), 0),
            'modes'         => $modes,
            'heures'        => $heures,
            'top_produits'  => $top,
        ]
    ]);

// ════════════════════════════════════════════════
//  2. STATS SEMAINE
// ════════════════════════════════════════════════
} elseif ($action === 'stats_semaine') {

    $jours_semaine = [];
    $ca_total      = 0;
    $nb_tickets    = 0;
    $total_remises = 0;
    $best_day      = null;
    $worst_day     = null;
    $best_ca       = -1;
    $worst_ca      = PHP_INT_MAX;

    $jours_noms = ['Dim', 'Lun', 'Mar', 'Mer', 'Jeu', 'Ven', 'Sam'];

    $stmt = $pdo->prepare("
        SELECT
            COUNT(id_vente)          AS nb_tickets,
            IFNULL(SUM(total), 0)    AS ca,
            IFNULL(SUM(remise), 0)   AS remises
        FROM ventes
        WHERE DATE(date_vente) = :date
    ");

    for ($i = 6; $i >= 0; $i--) {
        $date = date('Y-m-d', strtotime("-{$i} days"));
        $stmt->execute([':date' => $date]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        $label = $jours_noms[date('w', strtotime($date))] . ' ' . date('d/m', strtotime($date));
        $ca    = round(floatval($row['ca']), 0);
        $rem   = round(floatval($row['remises']), 0);

        $jours_semaine[] = [
            'date'       => $date,
            'label'      => $label,
            'nb_tickets' => intval($row['nb_tickets']),
            'ca'         => $ca,
            'remises'    => $rem,
        ];

        $ca_total      += $ca;
        $nb_tickets    += intval($row['nb_tickets']);
        $total_remises += $rem;

        if ($ca > $best_ca)  { $best_ca  = $ca;  $best_day  = $label; }
        if ($ca < $worst_ca) { $worst_ca = $ca;  $worst_day = $label; }
    }

    // Semaine précédente (J-14 à J-8)
    $prev_start = date('Y-m-d', strtotime('-14 days'));
    $prev_end   = date('Y-m-d', strtotime('-8 days'));

    $stmt2 = $pdo->prepare("
        SELECT IFNULL(SUM(total), 0) AS ca
        FROM ventes
        WHERE DATE(date_vente) BETWEEN :debut AND :fin
    ");
    $stmt2->execute([':debut' => $prev_start, ':fin' => $prev_end]);
    $prev = $stmt2->fetch(PDO::FETCH_ASSOC);

    $prev_ca = round(floatval($prev['ca']), 0);
    $delta   = $prev_ca > 0 ? (($ca_total - $prev_ca) / $prev_ca * 100) : 0;

    echo json_encode([
        'success' => true,
        'data'    => [
            'jours'           => $jours_semaine,
            'ca_total'        => $ca_total,
            'nb_tickets'      => $nb_tickets,
            'total_remises'   => $total_remises,
            'best_day_label'  => $best_day,
            'worst_day_label' => $worst_day,
            'prev_ca'         => $prev_ca,
            'delta_vs_prev'   => round($delta, 1),
        ]
    ]);

// ════════════════════════════════════════════════
//  3. STATS MOIS
// ════════════════════════════════════════════════
} elseif ($action === 'stats_mois') {

    $mois  = intval($_GET['mois']  ?? date('n'));
    $annee = intval($_GET['annee'] ?? date('Y'));

    // Sécuriser les bornes
    $mois  = max(1, min(12, $mois));
    $annee = max(2000, min(2100, $annee));

    $debut = sprintf('%04d-%02d-01', $annee, $mois);
    $fin   = date('Y-m-t', strtotime($debut));

    $stmt = $pdo->prepare("
        SELECT
            COUNT(id_vente)          AS nb_tickets,
            IFNULL(SUM(total), 0)    AS ca_total,
            IFNULL(AVG(total), 0)    AS panier_moyen,
            IFNULL(SUM(remise), 0)   AS total_remises
        FROM ventes
        WHERE DATE(date_vente) BETWEEN :debut AND :fin
    ");
    $stmt->execute([':debut' => $debut, ':fin' => $fin]);
    $kpi = $stmt->fetch(PDO::FETCH_ASSOC);

    // Semaines du mois
    $semaines = [];
    $cur      = new DateTime($debut);
    $end      = new DateTime($fin);
    $week_num = 1;

    $stmtW = $pdo->prepare("
        SELECT
            COUNT(id_vente)                          AS nb_tickets,
            IFNULL(SUM(total), 0)                    AS ca,
            COUNT(DISTINCT DATE(date_vente))         AS jours_actifs
        FROM ventes
        WHERE DATE(date_vente) BETWEEN :ws AND :we
    ");

    while ($cur <= $end) {
        $week_start = (clone $cur)->modify('monday this week');
        if ($week_start < new DateTime($debut)) $week_start = new DateTime($debut);

        $week_end = (clone $cur)->modify('sunday this week');
        if ($week_end > new DateTime($fin)) $week_end = new DateTime($fin);

        $ws = $week_start->format('Y-m-d');
        $we = $week_end->format('Y-m-d');

        $stmtW->execute([':ws' => $ws, ':we' => $we]);
        $row = $stmtW->fetch(PDO::FETCH_ASSOC);

        $semaines[] = [
            'semaine'      => $week_num,
            'debut'        => $week_start->format('d/m'),
            'fin'          => $week_end->format('d/m'),
            'nb_tickets'   => intval($row['nb_tickets']),
            'ca'           => round(floatval($row['ca']), 0),
            'jours_actifs' => intval($row['jours_actifs']),
        ];

        $week_num++;
        $cur = (clone $week_end)->modify('+1 day');
    }

    // Mois précédent
    $prev_date  = date('Y-m-d', strtotime($debut . ' -1 month'));
    $prev_debut = date('Y-m-01', strtotime($prev_date));
    $prev_fin   = date('Y-m-t', strtotime($prev_date));

    $stmtP = $pdo->prepare("
        SELECT IFNULL(SUM(total), 0) AS ca
        FROM ventes
        WHERE DATE(date_vente) BETWEEN :debut AND :fin
    ");
    $stmtP->execute([':debut' => $prev_debut, ':fin' => $prev_fin]);
    $prev = $stmtP->fetch(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'data'    => [
            'nb_tickets'    => intval($kpi['nb_tickets']),
            'ca_total'      => round(floatval($kpi['ca_total']), 0),
            'panier_moyen'  => round(floatval($kpi['panier_moyen']), 0),
            'total_remises' => round(floatval($kpi['total_remises']), 0),
            'semaines'      => $semaines,
            'prev_ca'       => round(floatval($prev['ca']), 0),
        ]
    ]);

// ════════════════════════════════════════════════
//  4. STATS PRODUITS titres
// ════════════════════════════════════════════════
} elseif ($action === 'stats_produits') {

    [$debut, $fin] = plage(trim($_GET['periode'] ?? 'semaine'));

    $tri      = in_array($_GET['tri'] ?? 'ca', ['ca', 'qte', 'tickets']) ? ($_GET['tri'] ?? 'ca') : 'ca';
    $afficher = in_array($_GET['afficher'] ?? 'top', ['top', 'flop', 'tous']) ? ($_GET['afficher'] ?? 'top') : 'top';
    $search   = trim($_GET['search'] ?? '');

    $tri_col = match ($tri) {
        'qte'     => 'qte_vendue',
        'tickets' => 'nb_tickets',
        default   => 'ca_total',
    };

    $order = ($afficher === 'flop') ? 'ASC' : 'DESC';
    $limit = ($afficher === 'tous') ? 200 : 20;

    // CORRECTION : produits.id_famille n'existe pas
    // -> jointure via sous_familles pour remonter à familles
    // CORRECTION : f.nom -> f.nom_famille
    $sql = "
        SELECT
            p.id_produit,
            p.nom_commercial                                    AS nom,
            p.molecule,
            p.prix_achat                                         AS pa_ref,
            IFNULL(f.nom_famille, 'N/A')                        AS famille,
            COUNT(DISTINCT dv.id_vente)                         AS nb_tickets,
            IFNULL(SUM(dv.quantite), 0)                         AS qte_vendue,
            IFNULL(SUM(dv.quantite * dv.prix_unitaire), 0)      AS ca_total,
            IFNULL(AVG(dv.prix_unitaire), 0)                    AS pv_moyen
        FROM produits p
        LEFT JOIN sous_familles sf  ON sf.id_sous_famille = p.id_sous_famille
        LEFT JOIN familles f        ON f.id_famille       = sf.id_famille
        LEFT JOIN detail_ventes dv  ON dv.id_produit      = p.id_produit
            AND EXISTS (
                SELECT 1 FROM ventes v2
                WHERE v2.id_vente = dv.id_vente
                  AND DATE(v2.date_vente) BETWEEN :debut AND :fin
            )
        WHERE p.actif = 1
    ";

    $params = [':debut' => $debut, ':fin' => $fin];

    if ($search !== '') {
        $sql .= " AND p.nom_commercial LIKE :search ";
        $params[':search'] = '%' . $search . '%';
    }

    $sql .= " GROUP BY p.id_produit, p.nom_commercial, p.molecule, p.prix_achat, f.nom_famille ";

    if ($afficher !== 'tous') {
        $sql .= " HAVING ca_total > 0 ";
    }

    $sql .= " ORDER BY {$tri_col} {$order} LIMIT {$limit}";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $produits = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // PA moyen depuis les stocks (requête préparée en boucle)
    $stmtPA = $pdo->prepare("
        SELECT IFNULL(AVG(prix_achat), 0) AS pa
        FROM stocks
        WHERE id_produit = :id
    ");
    foreach ($produits as &$prod) {
        $stmtPA->execute([':id' => $prod['id_produit']]);
        $pa = $stmtPA->fetchColumn();
        $prod['pa_moyen'] = round(floatval($pa), 0);
        $prod['ca']       = round(floatval($prod['ca_total']), 0);
        $prod['qte']      = intval($prod['qte_vendue']);
    }
    unset($prod);

    // Stats globales
    $total_ca = array_sum(array_column($produits, 'ca'));
    $top_nom  = $produits[0]['nom'] ?? 'N/A';

    // Produits non vendus sur la période
    $stmtZero = $pdo->prepare("
        SELECT COUNT(*) FROM produits p
        WHERE p.actif = 1
          AND NOT EXISTS (
              SELECT 1 FROM detail_ventes dv
              JOIN ventes v ON v.id_vente = dv.id_vente
              WHERE dv.id_produit = p.id_produit
                AND DATE(v.date_vente) BETWEEN :debut AND :fin
          )
    ");
    $stmtZero->execute([':debut' => $debut, ':fin' => $fin]);
    $nb_zero = intval($stmtZero->fetchColumn());

    echo json_encode([
        'success' => true,
        'data'    => [
            'produits'           => $produits,
            'ca_total'           => $total_ca,
            'nb_produits_vendus' => count(array_filter($produits, fn($p) => $p['ca'] > 0)),
            'top_produit'        => $top_nom,
            'nb_non_vendus'      => $nb_zero,
        ]
    ]);

// ════════════════════════════════════════════════
//  5. STATS FAMILLES
// ════════════════════════════════════════════════
} 
elseif ($action === 'stats_familles') {

    [$debut, $fin] = plage(trim($_GET['periode'] ?? 'semaine'));

    // CORRECTION : f.nom -> f.nom_famille
    // CORRECTION : GROUP BY doit inclure toutes les colonnes non agrégées
    $stmt = $pdo->prepare("
        SELECT
            f.id_famille                                                    AS id,
            f.nom_famille                                                   AS nom,
            COUNT(DISTINCT p.id_produit)                                    AS nb_produits,
            COUNT(DISTINCT CASE WHEN dv.id_produit IS NOT NULL
                  THEN p.id_produit END)                                    AS nb_produits_vendus,
            IFNULL(SUM(dv.quantite), 0)                                     AS qte_vendue,
            COUNT(DISTINCT dv.id_vente)                                     AS nb_tickets,
            IFNULL(SUM(dv.quantite * dv.prix_unitaire), 0)                  AS ca
        FROM familles f
        LEFT JOIN sous_familles sf ON sf.id_famille       = f.id_famille
        LEFT JOIN produits p       ON p.id_sous_famille   = sf.id_sous_famille
        LEFT JOIN detail_ventes dv ON dv.id_produit       = p.id_produit
            AND EXISTS (
                SELECT 1 FROM ventes v2
                WHERE v2.id_vente = dv.id_vente
                  AND DATE(v2.date_vente) BETWEEN :debut AND :fin
            )
        GROUP BY f.id_famille, f.nom_famille
        ORDER BY ca DESC
    ");
    $stmt->execute([':debut' => $debut, ':fin' => $fin]);
    $familles = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $total_ca = array_sum(array_column($familles, 'ca'));

    echo json_encode([
        'success' => true,
        'data'    => [
            'familles' => $familles,
            'ca_total' => round(floatval($total_ca), 0),
        ]
    ]);

// ════════════════════════════════════════════════
//  6. STATS SOUS-FAMILLES
// ════════════════════════════════════════════════
} elseif ($action === 'stats_sous_familles') {

    $id_famille = intval($_GET['id_famille'] ?? 0);
    [$debut, $fin] = plage(trim($_GET['periode'] ?? 'semaine'));

    if (!$id_famille) {
        echo json_encode(['success' => false, 'message' => 'ID famille requis']);
        exit;
    }

    // CORRECTION : sf.nom -> sf.nom_sous_famille | sf.description supprimé (inexistant)
    $stmt = $pdo->prepare("
        SELECT
            sf.id_sous_famille                                              AS id,
            sf.nom_sous_famille                                             AS nom,
            COUNT(DISTINCT p.id_produit)                                    AS nb_produits,
            COUNT(DISTINCT CASE WHEN dv.id_produit IS NOT NULL
                  THEN p.id_produit END)                                    AS nb_produits_vendus,
            IFNULL(SUM(dv.quantite), 0)                                     AS qte_vendue,
            COUNT(DISTINCT dv.id_vente)                                     AS nb_tickets,
            IFNULL(SUM(dv.quantite * dv.prix_unitaire), 0)                  AS ca
        FROM sous_familles sf
        LEFT JOIN produits p       ON p.id_sous_famille = sf.id_sous_famille
        LEFT JOIN detail_ventes dv ON dv.id_produit     = p.id_produit
            AND EXISTS (
                SELECT 1 FROM ventes v2
                WHERE v2.id_vente = dv.id_vente
                  AND DATE(v2.date_vente) BETWEEN :debut AND :fin
            )
        WHERE sf.id_famille = :id_famille
        GROUP BY sf.id_sous_famille, sf.nom_sous_famille
        ORDER BY ca DESC
    ");
    $stmt->execute([
        ':debut'      => $debut,
        ':fin'        => $fin,
        ':id_famille' => $id_famille,
    ]);
    $sf = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Top 5 produits de la famille
    $stmtTop = $pdo->prepare("
        SELECT
            p.nom_commercial                                AS nom,
            IFNULL(SUM(dv.quantite * dv.prix_unitaire), 0) AS ca
        FROM detail_ventes dv
        JOIN ventes v     ON v.id_vente      = dv.id_vente
        JOIN produits p   ON p.id_produit    = dv.id_produit
        JOIN sous_familles sf2 ON sf2.id_sous_famille = p.id_sous_famille
        WHERE sf2.id_famille         = :id_famille
          AND DATE(v.date_vente) BETWEEN :debut AND :fin
        GROUP BY dv.id_produit, p.nom_commercial
        ORDER BY ca DESC
        LIMIT 5
    ");
    $stmtTop->execute([
        ':debut'      => $debut,
        ':fin'        => $fin,
        ':id_famille' => $id_famille,
    ]);
    $top5 = $stmtTop->fetchAll(PDO::FETCH_ASSOC);

    $total_ca = array_sum(array_column($sf, 'ca'));

    echo json_encode([
        'success' => true,
        'data'    => [
            'sous_familles' => $sf,
            'top_produits'  => $top5,
            'ca_total'      => round(floatval($total_ca), 0),
        ]
    ]);

} else {
    echo json_encode(['success' => false, 'message' => 'Action inconnue : ' . htmlspecialchars($action)]);
}

} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Erreur SQL : ' . $e->getMessage()]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Erreur : ' . $e->getMessage()]);
}

exit;

?>