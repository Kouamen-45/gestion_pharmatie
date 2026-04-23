<?php
require_once 'db.php';

$id_vente = intval($_GET['id'] ?? 0);
if (!$id_vente) {
    echo json_encode(['status' => 'error', 'message' => 'ID invalide']);
    exit;
}

try {
    // En-tete de la vente
    $sqlV = "SELECT 
                 v.*,
                 DATE_FORMAT(v.date_vente, '%d/%m/%Y a %H:%i') AS date_affich,
                 c.nom AS client_nom,
                 c.prenom AS client_prenom,
                 c.telephone,
                 a.nom_assurance
             FROM ventes v
             LEFT JOIN clients c ON v.id_client = c.id_client
             LEFT JOIN assurances a ON v.id_assurance = a.id_assurance
             WHERE v.id_vente = ?";
    $vente = $pdo->prepare($sqlV);
    $vente->execute([$id_vente]);
    $v = $vente->fetch(PDO::FETCH_ASSOC);

    if (!$v) {
        echo json_encode(['status' => 'error', 'message' => 'Vente introuvable']);
        exit;
    }

    // Lignes de la vente
    $sqlArt = "SELECT 
                   dv.quantite,
                   dv.prix_unitaire,
                   dv.type_unite,
                   p.nom_commercial,
                   p.molecule
               FROM detail_ventes dv
               INNER JOIN produits p ON dv.id_produit = p.id_produit
               WHERE dv.id_vente = ?";
    $stmtArt = $pdo->prepare($sqlArt);
    $stmtArt->execute([$id_vente]);
    $articles = $stmtArt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'status'   => 'success',
        'vente'    => $v,
        'articles' => $articles
    ]);

} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
