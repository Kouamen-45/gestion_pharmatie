<?php
session_start();
require_once 'db.php'; // On appelle notre fichier de connexion ici

if (isset($_POST['connexion'])) {
    $username = $_POST['username'];
    $password = $_POST['password'];

    // On utilise directement la variable $pdo définie dans db.php
    $stmt = $pdo->prepare("SELECT * FROM Utilisateurs WHERE username = ?");
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    if ($user && password_verify($password, $user['password'])) {
        $_SESSION['user_id'] = $user['id_user'];
        $_SESSION['username'] = $user['nom_complet'];
        $_SESSION['role'] = $user['role'];
      
        header('Location: produits_gestion.php');
        exit();
    } else {
        header('Location: index.php?error=1');
        exit();
    }
}