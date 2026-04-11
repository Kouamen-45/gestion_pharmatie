<?php
require_once 'db.php';

// Le mot de passe que tu veux utiliser
$password_en_clair = 'admin123';

// On génère le hash proprement avec PHP
$nouveau_hash = password_hash($password_en_clair, PASSWORD_DEFAULT);

try {
    // On met à jour l'utilisateur 'admin' (ou on le crée s'il n'existe pas)
    $sql = "UPDATE Utilisateurs SET password = ? WHERE username = 'admin'";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$nouveau_hash]);

    if ($stmt->rowCount() > 0) {
        echo "✅ Succès ! Le mot de passe a été mis à jour avec password_hash().<br>";
        echo "Identifiant : <b>admin</b><br>";
        echo "Mot de passe : <b>admin123</b><br>";
        echo "<a href='login.php'>Retourner à la page de connexion</a>";
    } else {
        echo "❌ L'utilisateur 'admin' n'existe pas dans la table ou le mot de passe est déjà le même.";
    }
} catch (PDOException $e) {
    echo "Erreur : " . $e->getMessage();
}
?>