<?php
require_once 'db.php';
header('Content-Type: application/json');

$type  = $_GET['type']  ?? '';
$date  = $_GET['date']  ?? date('Y-m-d');
$debut = $_GET['debut'] ?? date('Y-m-01');
$fin   = $_GET['fin']   ?? date('Y-m-d');

// ── Sous-requête prix d'achat moyen (COALESCE sur stocks) ─────
// On utilise le dernier prix d'achat connu pour chaque produit
// via la table stocks (prix_achat), ou detail_achats en fallback
$pa_subquery = "
  COALESCE(
    (SELECT AVG(s.prix_achat) FROM stocks s WHERE s.id_produit = dv.id_produit AND s.quantite_disponible > 0),
    (SELECT AVG(da.prix_achat_unitaire) FROM detail_achats da WHERE da.id_produit = dv.id_produit),
    0
  )
";

switch ($type) {

  // ══════════════════════════════════════════════════════════
  //  JOUR — avec détail produits
  // ══════════════════════════════════════════════════════════
  case 'jours':
    $stmt = $pdo->prepare("
      SELECT
        DATE(v.date_vente)                                         AS date,
        SUM(v.total)                                               AS ca,
        SUM(dv.quantite * (dv.prix_unitaire - ({$pa_subquery})))   AS marge,
        SUM(dv.quantite * ({$pa_subquery}))                        AS cout_achat,
        SUM(COALESCE(v.remise_montant, 0))                         AS remises,
        COUNT(DISTINCT v.id_vente)                                 AS nb_ventes
      FROM ventes v
      JOIN detail_ventes dv ON v.id_vente = dv.id_vente
      WHERE DATE(v.date_vente) = :date
        AND v.statut_paiement != 'annule'
      GROUP BY DATE(v.date_vente)
    ");
    $stmt->execute([':date' => $date]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    // Top produits du jour
    $stmtP = $pdo->prepare("
      SELECT
        p.nom_commercial                                                 AS nom,
        p.molecule,
        SUM(dv.quantite)                                                 AS qte,
        SUM(dv.prix_unitaire * dv.quantite)                              AS ca,
        SUM(dv.quantite * (dv.prix_unitaire - ({$pa_subquery})))         AS marge,
        SUM(dv.quantite * ({$pa_subquery}))                              AS cout_achat
      FROM detail_ventes dv
      JOIN ventes v  ON dv.id_vente  = v.id_vente
      JOIN produits p ON dv.id_produit = p.id_produit
      WHERE DATE(v.date_vente) = :date
        AND v.statut_paiement != 'annule'
      GROUP BY dv.id_produit, p.nom_commercial, p.molecule
      ORDER BY marge DESC
      LIMIT 15
    ");
    $stmtP->execute([':date' => $date]);
    $produits = $stmtP->fetchAll(PDO::FETCH_ASSOC);

    $data = $row ? [array_merge($row, ['produits' => $produits])] : [];
    break;

  // ══════════════════════════════════════════════════════════
  //  SEMAINES
  // ══════════════════════════════════════════════════════════
  case 'semaines':
    $stmt = $pdo->prepare("
      SELECT
        YEARWEEK(v.date_vente, 1)                                        AS semaine_key,
        WEEK(v.date_vente, 1)                                            AS semaine,
        YEAR(v.date_vente)                                               AS annee,
        MIN(DATE(v.date_vente))                                          AS debut,
        MAX(DATE(v.date_vente))                                          AS fin,
        SUM(v.total)                                                     AS ca,
        SUM(dv.quantite * (dv.prix_unitaire - ({$pa_subquery})))         AS marge,
        SUM(dv.quantite * ({$pa_subquery}))                              AS cout_achat,
        SUM(COALESCE(v.remise_montant, 0))                               AS remises,
        COUNT(DISTINCT v.id_vente)                                       AS nb_ventes
      FROM ventes v
      JOIN detail_ventes dv ON v.id_vente = dv.id_vente
      WHERE v.date_vente BETWEEN :debut AND :fin
        AND v.statut_paiement != 'annule'
      GROUP BY YEARWEEK(v.date_vente, 1)
      ORDER BY semaine_key ASC
    ");
    $stmt->execute([':debut' => $debut, ':fin' => $fin]);
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    break;

  // ══════════════════════════════════════════════════════════
  //  MOIS
  // ══════════════════════════════════════════════════════════
  case 'mois':
    $stmt = $pdo->query("
      SELECT
        YEAR(v.date_vente)                                               AS annee,
        MONTH(v.date_vente)                                              AS mois,
        DATE_FORMAT(v.date_vente, '%M %Y')                               AS nom_mois,
        SUM(v.total)                                                     AS ca,
        SUM(dv.quantite * (dv.prix_unitaire - ({$pa_subquery})))         AS marge,
        SUM(dv.quantite * ({$pa_subquery}))                              AS cout_achat,
        SUM(COALESCE(v.remise_montant, 0))                               AS remises,
        COUNT(DISTINCT v.id_vente)                                       AS nb_ventes
      FROM ventes v
      JOIN detail_ventes dv ON v.id_vente = dv.id_vente
      WHERE v.statut_paiement != 'annule'
      GROUP BY YEAR(v.date_vente), MONTH(v.date_vente)
      ORDER BY annee ASC, mois ASC
    ");
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    break;

  // ══════════════════════════════════════════════════════════
  //  PRODUITS
  // ══════════════════════════════════════════════════════════
  case 'produits':
    $periodes = [
      'jour'      => "DATE(v.date_vente) = CURDATE()",
      'semaine'   => "YEARWEEK(v.date_vente,1) = YEARWEEK(CURDATE(),1)",
      'mois'      => "YEAR(v.date_vente) = YEAR(CURDATE()) AND MONTH(v.date_vente) = MONTH(CURDATE())",
      'trimestre' => "QUARTER(v.date_vente) = QUARTER(CURDATE()) AND YEAR(v.date_vente) = YEAR(CURDATE())",
      'annee'     => "YEAR(v.date_vente) = YEAR(CURDATE())",
    ];
    $periodeSel = $_GET['periode'] ?? 'semaine';
    $whereP = $periodes[$periodeSel] ?? $periodes['semaine'];

    $stmt = $pdo->query("
      SELECT
        p.id_produit,
        p.nom_commercial                                                  AS nom,
        p.molecule,
        f.nom_famille                                                     AS famille,
        SUM(dv.quantite)                                                  AS quantite,
        COUNT(DISTINCT v.id_vente)                                        AS nb_tickets,
        SUM(dv.prix_unitaire * dv.quantite)                               AS ca,
        SUM(dv.quantite * ({$pa_subquery}))                               AS cout_achat,
        SUM(dv.quantite * (dv.prix_unitaire - ({$pa_subquery})))          AS marge,
        AVG({$pa_subquery})                                               AS pa_moyen
      FROM detail_ventes dv
      JOIN ventes v   ON dv.id_vente   = v.id_vente
      JOIN produits p ON dv.id_produit = p.id_produit
      LEFT JOIN sous_familles sf ON p.id_sous_famille = sf.id_sous_famille
      LEFT JOIN familles f       ON sf.id_famille     = f.id_famille
      WHERE {$whereP}
        AND v.statut_paiement != 'annule'
      GROUP BY p.id_produit, p.nom_commercial, p.molecule, f.nom_famille
      ORDER BY marge DESC
    ");
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    break;

  // ══════════════════════════════════════════════════════════
  //  FAMILLES + SOUS-FAMILLES (imbriquees)
  // ══════════════════════════════════════════════════════════
  case 'familles':
    $periodes = [
      'jour'      => "DATE(v.date_vente) = CURDATE()",
      'semaine'   => "YEARWEEK(v.date_vente,1) = YEARWEEK(CURDATE(),1)",
      'mois'      => "YEAR(v.date_vente) = YEAR(CURDATE()) AND MONTH(v.date_vente) = MONTH(CURDATE())",
      'trimestre' => "QUARTER(v.date_vente) = QUARTER(CURDATE()) AND YEAR(v.date_vente) = YEAR(CURDATE())",
      'annee'     => "YEAR(v.date_vente) = YEAR(CURDATE())",
    ];
    $periodeSel = $_GET['periode'] ?? 'semaine';
    $whereF = $periodes[$periodeSel] ?? $periodes['semaine'];

    // Familles
    $stmtF = $pdo->query("
      SELECT
        f.id_famille,
        f.nom_famille,
        COUNT(DISTINCT p.id_produit)                                      AS nb_produits,
        SUM(dv.quantite)                                                  AS quantite,
        SUM(dv.prix_unitaire * dv.quantite)                               AS ca,
        SUM(dv.quantite * ({$pa_subquery}))                               AS cout_achat,
        SUM(dv.quantite * (dv.prix_unitaire - ({$pa_subquery})))          AS marge
      FROM familles f
      JOIN sous_familles sf ON sf.id_famille     = f.id_famille
      JOIN produits p       ON p.id_sous_famille = sf.id_sous_famille
      JOIN detail_ventes dv ON dv.id_produit     = p.id_produit
      JOIN ventes v         ON dv.id_vente       = v.id_vente
      WHERE {$whereF}
        AND v.statut_paiement != 'annule'
      GROUP BY f.id_famille, f.nom_famille
      ORDER BY marge DESC
    ");
    $familles = $stmtF->fetchAll(PDO::FETCH_ASSOC);

    // Sous-familles par famille
    $stmtSF = $pdo->query("
      SELECT
        sf.id_famille,
        sf.id_sous_famille,
        sf.nom_sous_famille                                               AS nom,
        COUNT(DISTINCT p.id_produit)                                      AS nb_produits,
        SUM(dv.quantite)                                                  AS quantite,
        SUM(dv.prix_unitaire * dv.quantite)                               AS ca,
        SUM(dv.quantite * ({$pa_subquery}))                               AS cout_achat,
        SUM(dv.quantite * (dv.prix_unitaire - ({$pa_subquery})))          AS marge
      FROM sous_familles sf
      JOIN produits p       ON p.id_sous_famille = sf.id_sous_famille
      JOIN detail_ventes dv ON dv.id_produit     = p.id_produit
      JOIN ventes v         ON dv.id_vente       = v.id_vente
      WHERE {$whereF}
        AND v.statut_paiement != 'annule'
      GROUP BY sf.id_sous_famille, sf.id_famille, sf.nom_sous_famille
      ORDER BY marge DESC
    ");
    $sousFamilles = $stmtSF->fetchAll(PDO::FETCH_ASSOC);

    // Grouper sous-familles dans familles
    $sfMap = [];
    foreach ($sousFamilles as $sf) {
      $sfMap[$sf['id_famille']][] = $sf;
    }
    foreach ($familles as &$fam) {
      $fam['sous_familles'] = $sfMap[$fam['id_famille']] ?? [];
    }
    unset($fam);
    $data = $familles;
    break;

  // ══════════════════════════════════════════════════════════
  //  TOTAL (tableau de bord global)
  // ══════════════════════════════════════════════════════════
  case 'total':
    $stmtT = $pdo->query("
      SELECT
        SUM(v.total)                                                      AS ca,
        SUM(dv.quantite * (dv.prix_unitaire - ({$pa_subquery})))          AS marge,
        SUM(dv.quantite * ({$pa_subquery}))                               AS cout_achat,
        SUM(COALESCE(v.remise_montant, 0))                                AS remises,
        COUNT(DISTINCT v.id_vente)                                        AS nb_ventes
      FROM ventes v
      JOIN detail_ventes dv ON v.id_vente = dv.id_vente
      WHERE v.statut_paiement != 'annule'
    ");
    $total = $stmtT->fetch(PDO::FETCH_ASSOC);

    // Charges totales
    $stmtC = $pdo->query("SELECT SUM(montant) AS charges FROM charges");
    $ch    = $stmtC->fetch(PDO::FETCH_ASSOC);
    $total['charges'] = $ch['charges'] ?? 0;

    // Periodes (par mois pour le breakdown)
    $stmtP = $pdo->query("
      SELECT
        DATE_FORMAT(v.date_vente,'%b %Y')                                  AS label,
        SUM(v.total)                                                       AS ca,
        SUM(dv.quantite * (dv.prix_unitaire - ({$pa_subquery})))           AS marge
      FROM ventes v
      JOIN detail_ventes dv ON v.id_vente = dv.id_vente
      WHERE v.statut_paiement != 'annule'
      GROUP BY YEAR(v.date_vente), MONTH(v.date_vente)
      ORDER BY YEAR(v.date_vente) ASC, MONTH(v.date_vente) ASC
      LIMIT 12
    ");
    $total['periodes'] = $stmtP->fetchAll(PDO::FETCH_ASSOC);
    $data = [$total];
    break;

  // ══════════════════════════════════════════════════════════
  //  EVOLUTION — par jour sur une plage
  // ══════════════════════════════════════════════════════════
  case 'evolution':
    $stmt = $pdo->prepare("
      SELECT
        DATE(v.date_vente)                                                AS date,
        SUM(v.total)                                                      AS ca,
        SUM(dv.quantite * (dv.prix_unitaire - ({$pa_subquery})))          AS marge,
        SUM(dv.quantite * ({$pa_subquery}))                               AS cout_achat,
        SUM(COALESCE(v.remise_montant, 0))                                AS remises,
        COUNT(DISTINCT v.id_vente)                                        AS nb_ventes
      FROM ventes v
      JOIN detail_ventes dv ON v.id_vente = dv.id_vente
      WHERE v.date_vente BETWEEN :debut AND :fin
        AND v.statut_paiement != 'annule'
      GROUP BY DATE(v.date_vente)
      ORDER BY date ASC
    ");
    $debutEvo = $_GET['debut'] ?? date('Y-m-d', strtotime('-30 days'));
    $finEvo   = $_GET['fin']   ?? date('Y-m-d');
    $stmt->execute([':debut' => $debutEvo, ':fin' => $finEvo]);
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    break;

  default:
    http_response_code(400);
    echo json_encode(['error' => 'Type non reconnu : ' . htmlspecialchars($type)]);
    exit;
}

echo json_encode($data);
