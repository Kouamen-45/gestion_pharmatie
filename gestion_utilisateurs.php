<?php
session_start();
require_once 'db.php';

// Sécurité : Seul l'Administrateur ou le Pharmacien peut accéder à cette page
if (!isset($_SESSION['user_id']) || $_SESSION['role'] == 'Vendeur') {
    header('Location: dashboard.php');
    exit();
}

// 1. Récupérer tous les utilisateurs
$utilisateurs = $pdo->query("SELECT * FROM utilisateurs ORDER BY role ASC")->fetchAll();

// 2. Traitement de l'ajout
if (isset($_POST['ajouter'])) {
    $nom = $_POST['nom_complet'];
    $user = $_POST['username'];
    $pass = password_hash($_POST['password'], PASSWORD_DEFAULT);
    $role = $_POST['role'];
    
    $stmt = $pdo->prepare("INSERT INTO utilisateurs (nom_complet, username, password, role, statut) VALUES (?, ?, ?, ?, 1)");
    $stmt->execute([$nom, $user, $pass, $role]);
    header("Location: gestion_utilisateurs.php");
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Utilisateurs - PharmAssist</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        :root { --primary: #2c3e50; --accent: #3498db; --bg: #f4f7f6; }
        body { margin: 0; font-family: 'Segoe UI', sans-serif; background: var(--bg); display: flex; }
        .main-content { margin-left: 250px; flex: 1; padding: 30px; }
        .card { background: white; padding: 20px; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.05); margin-bottom: 20px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 12px; border-bottom: 1px solid #eee; text-align: left; }
        .badge { padding: 5px 10px; border-radius: 15px; font-size: 12px; color: white; }
        .Admin { background: #e74c3c; } .Pharmacien { background: #f39c12; } .Vendeur { background: #27ae60; }
        input, select { padding: 8px; margin: 5px; border: 1px solid #ddd; border-radius: 4px; }
        .btn { background: var(--accent); color: white; border: none; padding: 10px 20px; border-radius: 5px; cursor: pointer; }
    </style>
</head>
<body>

<?php include 'sidebar.php'; // On suppose que vous avez centralisé le menu ?>

<div class="main-content">
    <h2><i class="fas fa-users-cog"></i> Gestion des Utilisateurs</h2>

    <div class="card">
        <h3>Ajouter un nouvel employé</h3>
        <form method="POST">
            <input type="text" name="nom_complet" placeholder="Nom Complet" required>
            <input type="text" name="username" placeholder="Identifiant" required>
            <input type="password" name="password" placeholder="Mot de passe" required>
            <select name="role">
                <option value="Vendeur">Vendeur</option>
                <option value="Pharmacien">Pharmacien</option>
                <option value="Administrateur">Administrateur</option>
            </select>
            <button type="submit" name="ajouter" class="btn">Créer le compte</button>
        </form>
    </div>

    <div class="card">
        <table>
            <thead>
                <tr>
                    <th>Nom</th>
                    <th>Identifiant</th>
                    <th>Rôle</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($utilisateurs as $u): ?>
                <tr>
                    <td><b><?= htmlspecialchars($u['nom_complet']) ?></b></td>
                    <td><?= htmlspecialchars($u['username']) ?></td>
                    <td><span class="badge <?= $u['role'] ?>"><?= $u['role'] ?></span></td>
                    <td>
                        <a href="edit_user.php?id=<?= $u['id_user'] ?>" style="color: var(--accent);"><i class="fas fa-edit"></i></a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

</body>
</html>