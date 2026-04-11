<?php
session_start();
require_once 'db.php';
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fournisseur = $_POST['fournisseur'];
    $produits = json_decode($_POST['produits'], true);
    $id_user = $_SESSION['user_id'];

    try {
        $pdo->beginTransaction();

        $total_achat = 0;
        foreach($produits as $p) { $total_achat += ($p['qte'] * $p['prix']); }

        // 1. Insertion dans 'achats'
        $stmtA = $pdo->prepare("INSERT INTO achats (fournisseur, montant_total, id_utilisateur) VALUES (?, ?, ?)");
        $stmtA->execute([$fournisseur, $total_achat, $id_user]);
        $id_achat = $pdo->lastInsertId();

        foreach($produits as $p) {
            // 2. Insertion dans 'detail_achats' (selon tes colonnes : id_achat, id_produit, quantite_recue, prix_achat_unitaire, date_peremption)
            $stmtD = $pdo->prepare("INSERT INTO detail_achats (id_achat, id_produit, quantite_recue, prix_achat_unitaire, date_peremption) VALUES (?, ?, ?, ?, ?)");
            $stmtD->execute([$id_achat, $p['id'], $p['qte'], $p['prix'], $p['peremp']]);

            // 3. Insertion dans 'stocks' (selon tes colonnes : id_produit, numero_lot, date_peremption, quantite_disponible, date_reception)
            $stmtS = $pdo->prepare("INSERT INTO stocks (id_produit, numero_lot, date_peremption, quantite_disponible, date_reception) VALUES (?, ?, ?, ?, CURDATE())");
            $stmtS->execute([$p['id'], $p['lot'], $p['peremp'], $p['qte']]);
        }

        $pdo->commit();
        echo json_encode(['status' => 'success']);
    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
}

// Action GET pour les détails dans l'historique
if (isset($_GET['action']) && $_GET['action'] === 'get_achat_details') {
    $id = $_GET['id_achat'];
    $stmt = $pdo->prepare("SELECT d.*, p.nom_commercial FROM detail_achats d JOIN produits p ON d.id_produit = p.id_produit WHERE d.id_achat = ?");
    $stmt->execute([$id]);
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    exit;
}