<?php
require_once 'db.php';

try {
    // ---- Ventes + clients ----
    $sql = "SELECT 
                v.id_vente,
                v.date_vente,
                DATE_FORMAT(v.date_vente, '%H:%i') AS heure,
                v.total,
                v.mode_paiement,
                v.remise,
                v.id_client,
                v.id_assurance,
                v.part_assurance,
                v.part_patient,
                v.statut_paiement,
                c.nom  AS client_nom,
                c.prenom AS client_prenom,
                c.telephone
            FROM ventes v
            LEFT JOIN clients c ON v.id_client = c.id_client
            WHERE DATE(v.date_vente) = CURDATE()
            ORDER BY v.date_vente DESC
            LIMIT 100";

    $stmt = $pdo->query($sql);
    $ventes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // ---- Articles par vente (1 seule requete groupee) ----
    if (!empty($ventes)) {
        $ids = array_column($ventes, 'id_vente');
        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        $sqlArt = "SELECT 
                       dv.id_vente,
                       p.nom_commercial AS nom,
                       dv.quantite,
                       dv.prix_unitaire,
                       dv.type_unite
                   FROM detail_ventes dv
                   INNER JOIN produits p ON dv.id_produit = p.id_produit
                   WHERE dv.id_vente IN ($placeholders)";

        $stmtArt = $pdo->prepare($sqlArt);
        $stmtArt->execute($ids);
        $allArticles = $stmtArt->fetchAll(PDO::FETCH_ASSOC);

        // Indexer par id_vente
        $articlesMap = [];
        foreach ($allArticles as $a) {
            $articlesMap[$a['id_vente']][] = $a;
        }

        // Injecter les articles dans chaque vente
        foreach ($ventes as &$v) {
            $v['articles'] = $articlesMap[$v['id_vente']] ?? [];
        }
        unset($v);
    }

    // ---- Totaux par mode de paiement ----
    $sqlTotaux = "SELECT
                    SUM(CASE WHEN LOWER(mode_paiement) = 'especes'      THEN total ELSE 0 END) AS especes,
                    SUM(CASE WHEN LOWER(mode_paiement) = 'mobile money' THEN total ELSE 0 END) AS mobile,
                    SUM(CASE WHEN LOWER(mode_paiement) = 'assurance'    THEN total ELSE 0 END) AS assurance
                  FROM ventes
                  WHERE DATE(date_vente) = CURDATE()";
    $totaux = $pdo->query($sqlTotaux)->fetch(PDO::FETCH_ASSOC);

    echo json_encode([
        'status' => 'success',
        'ventes' => $ventes,
        'totaux' => [
            'especes'   => floatval($totaux['especes']   ?? 0),
            'mobile'    => floatval($totaux['mobile']    ?? 0),
            'assurance' => floatval($totaux['assurance'] ?? 0),
        ]
    ]);

} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
