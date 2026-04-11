<?php
session_start();
require_once 'db.php';

$stmt = $pdo->prepare("SELECT id_session FROM sessions_caisse WHERE id_utilisateur = ? AND statut = 'ouvert' LIMIT 1");
$stmt->execute([$_SESSION['user_id']]);
$session = $stmt->fetch();

echo json_encode(['ouvert' => (bool)$session]);