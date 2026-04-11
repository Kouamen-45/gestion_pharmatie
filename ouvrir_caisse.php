<?php
session_start();
require_once 'db.php';

$response = ['success' => false, 'message' => ''];

if (isset($_POST['fond_depart']) && isset($_SESSION['user_id'])) {
    $user_id = $_SESSION['user_id'];
    $fond = floatval($_POST['fond_depart']);

    try {
        // On insère la nouvelle session
        $sql = "INSERT INTO sessions_caisse (id_utilisateur, date_ouverture, fond_caisse_depart, statut) 
                VALUES (?, NOW(), ?, 'ouvert')";
        $stmt = $pdo->prepare($sql);
        
        if ($stmt->execute([$user_id, $fond])) {
            $_SESSION['id_session_caisse'] = $pdo->lastInsertId();
            $response['success'] = true;
        } else {
            $response['message'] = "Erreur lors de l'enregistrement en base de données.";
        }
    } catch (Exception $e) {
        $response['message'] = $e->getMessage();
    }
} else {
    $response['message'] = "Données manquantes.";
}

echo json_encode($response);