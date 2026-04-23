<?php
/**
 * get_commande_originale.php
 * Retourne les lignes du bon de commande initial pour comparaison côté JS
 */
include 'db.php';
header('Content-Type: application/json');

$id_commande = intval($_POST['id_commande'] ?? 0);
if ($id_commande <= 0) {
    echo json_encode(['success' => false, 'message' => 'ID commande invalide.']);
    exit;
}

try {
    $stmt = $pdo->prepare("
        SELECT
            cl.id_ligne,
            cl.id_produit,
            cl.quantite_commandee,
            cl.quantite_recue,
            p.nom_commercial,
            p.molecule,
            p.prix_achat AS pa_reference,
            c.id_fournisseur,
            c.date_commande,
            c.total_prevu,
            f.nom_fournisseur
        FROM commande_lignes cl
        JOIN produits    p ON p.id_produit    = cl.id_produit
        JOIN commandes   c ON c.id_commande   = cl.id_commande
        LEFT JOIN fournisseurs f ON f.id_fournisseur = c.id_fournisseur
        WHERE cl.id_commande = ?
        ORDER BY p.nom_commercial ASC
    ");
    $stmt->execute([$id_commande]);
    $lignes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($lignes)) {
        echo json_encode(['success' => false, 'message' => 'Commande introuvable ou vide.']);
        exit;
    }

    echo json_encode([
        'success'          => true,
        'lignes'           => $lignes,
        'id_fournisseur'   => $lignes[0]['id_fournisseur'],
        'nom_fournisseur'  => $lignes[0]['nom_fournisseur'],
        'date_commande'    => $lignes[0]['date_commande'],
        'total_prevu'      => $lignes[0]['total_prevu'],
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>