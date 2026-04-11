<?php
require_once 'db.php';

$action = $_POST['action'] ?? '';

// Récupérer toutes les familles
if ($action == 'get_familles') {
    $stmt = $pdo->query("SELECT * FROM familles ORDER BY nom_famille ASC");
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    exit;
}

// Récupérer les sous-familles d'une famille précise
elseif ($action == 'get_sous_familles') {
    $id_famille = $_POST['id_famille'];
    $stmt = $pdo->prepare("SELECT * FROM sous_familles WHERE id_famille = ? ORDER BY nom_sous_famille ASC");
    $stmt->execute([$id_famille]);
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    exit;
}

// Ajouter une Famille
elseif ($action == 'add_famille') {
    $nom = $_POST['nom'];
    $stmt = $pdo->prepare("INSERT INTO familles (nom_famille) VALUES (?)");
    $stmt->execute([$nom]);
    echo json_encode(['status' => 'success']);
    exit;
}

// Ajouter une Sous-Famille
elseif ($action == 'add_sous_famille') {
    $id_f = $_POST['id_famille'];
    $nom = $_POST['nom'];
    $stmt = $pdo->prepare("INSERT INTO sous_familles (id_famille, nom_sous_famille) VALUES (?, ?)");
    $stmt->execute([$id_f, $nom]);
    echo json_encode(['status' => 'success']);
    exit;
}

elseif ($action == 'delete_famille') {
    $id = $_POST['id'];
    // Attention: SQL empêchera la suppression si des sous-familles y sont liées (sécurité)
    $stmt = $pdo->prepare("DELETE FROM familles WHERE id_famille = ?");
    $stmt->execute([$id]);
    echo json_encode(['status' => 'success']);
    exit;
}