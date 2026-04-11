<?php
session_start();
require_once 'db.php';
header('Content-Type: application/json');

$id_stock = $_POST['id_stock'];
$id_produit = $_POST['id_produit'];
$type = $_POST['type_ajustement'];
$qte = (int)$_POST['quantite'];
$motif = $_POST['motif'];
$id_user = $_SESSION['user_id'];

try {
    $pdo->beginTransaction();

    // 1. Enregistrer l'ajustement pour l'historique
    $stmt = $pdo->prepare("INSERT INTO ajustements (id_produit, id_stock, type_ajustement, quantite_ajustee, motif, id_utilisateur) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->execute([$id_produit, $id_stock, $type, $qte, $motif, $id_user]);

    // 2. Mettre à jour la table stocks
    if ($type === 'ajout') {
        $stmtUpd = $pdo->prepare("UPDATE stocks SET quantite_disponible = quantite_disponible + ? WHERE id_stock = ?");
    } else {
        $stmtUpd = $pdo->prepare("UPDATE stocks SET quantite_disponible = quantite_disponible - ? WHERE id_stock = ?");
    }
    $stmtUpd->execute([$qte, $id_stock]);

    $pdo->commit();
    echo json_encode(['status' => 'success']);
} catch (Exception $e) {
    $pdo->rollBack();
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}