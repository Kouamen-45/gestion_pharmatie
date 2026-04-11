<?php
require_once 'db.php';
$action = $_POST['action'] ?? '';

if ($action == 'get_fournisseurs') {
    $stmt = $pdo->query("SELECT * FROM fournisseurs ORDER BY nom_fournisseur ASC");
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    exit;
}

elseif ($action == 'add_fournisseur') {
    $d = $_POST['data'];
    $sql = "INSERT INTO fournisseurs (nom_fournisseur, telephone, email) VALUES (?, ?, ?)";
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$d['nom'], $d['tel'], $d['email']]);
        echo json_encode(['status' => 'success']);
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
}

// Récupérer un seul fournisseur pour l'édition
if ($action == 'get_un_fournisseur') {
    $id = $_POST['id'];
    $stmt = $pdo->prepare("SELECT * FROM fournisseurs WHERE id_fournisseur = ?");
    $stmt->execute([$id]);
    echo json_encode($stmt->fetch(PDO::FETCH_ASSOC));
    exit;
}

// Mettre à jour les informations
elseif ($action == 'update_fournisseur') {
    $d = $_POST['data'];
    $sql = "UPDATE fournisseurs SET nom_fournisseur = ?, telephone = ?, email = ? WHERE id_fournisseur = ?";
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$d['nom'], $d['tel'], $d['email'], $d['id']]);
        echo json_encode(['status' => 'success']);
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
}