<?php
session_start();
// Si l'utilisateur est déjà connecté, on le redirige vers le dashboard
if (isset($_SESSION['user_id'])) {
    header('Location: produits_gestion.php');
    exit();
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PharmAssist - Connexion</title>
    <link rel="icon" type="image/png" href="logo_pharmassist.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        :root {
            --primary-color: #2c3e50;
            --secondary-color: #27ae60;
            --accent-color: #3498db;
            --error-color: #e74c3c;
        }

        body, html {
            height: 100%;
            margin: 0;
            font-family: 'Poppins', sans-serif;
            overflow: hidden;
        }

        /* Animation du fond dégradé */
        .bg-animated {
            background: linear-gradient(-45deg, #2c3e50, #27ae60, #2980b9, #16a085);
            background-size: 400% 400%;
            animation: gradientBG 15s ease infinite;
            height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        @keyframes gradientBG {
            0% { background-position: 0% 50%; }
            50% { background-position: 100% 50%; }
            100% { background-position: 0% 50%; }
        }

        .login-container {
            background: rgba(255, 255, 255, 0.9);
            padding: 40px;
            border-radius: 20px;
            box-shadow: 0 15px 35px rgba(0,0,0,0.3);
            width: 100%;
            max-width: 400px;
            text-align: center;
            backdrop-filter: blur(10px);
            transform: translateY(0);
            transition: all 0.3s ease;
        }

        .login-container:hover {
            transform: translateY(-10px);
        }

        .logo-area img {
            width: 80px;
            height: 80px;
            margin-bottom: 15px;
            filter: drop-shadow(0 5px 15px rgba(0,0,0,0.1));
        }

        h2 {
            color: var(--primary-color);
            margin-bottom: 5px;
            font-weight: 700;
        }

        p.subtitle {
            color: #7f8c8d;
            margin-bottom: 30px;
            font-size: 0.9em;
        }

        .input-group {
            position: relative;
            margin-bottom: 20px;
        }

        .input-group i {
            position: absolute;
            left: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: #95a5a6;
            transition: 0.3s;
        }

        .input-group input {
            width: 100%;
            padding: 12px 15px 12px 45px;
            border: 2px solid #eee;
            border-radius: 30px;
            outline: none;
            font-size: 16px;
            box-sizing: border-box;
            transition: 0.3s;
        }

        .input-group input:focus {
            border-color: var(--secondary-color);
        }

        .input-group input:focus + i {
            color: var(--secondary-color);
        }

        .btn-login {
            width: 100%;
            padding: 12px;
            background: var(--secondary-color);
            border: none;
            color: white;
            border-radius: 30px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: 0.3s;
            box-shadow: 0 5px 15px rgba(39, 174, 96, 0.3);
        }

        .btn-login:hover {
            background: #219150;
            box-shadow: 0 8px 20px rgba(39, 174, 96, 0.4);
            letter-spacing: 1px;
        }

        .error-msg {
            background: #fdeaea;
            color: var(--error-color);
            padding: 10px;
            border-radius: 10px;
            margin-bottom: 20px;
            font-size: 0.85em;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }
    </style>
</head>
<body>

<div class="bg-animated">
    <div class="login-container">
        <div class="logo-area">
            <img src="logo_pharmassist.png" alt="PharmAssist Logo">
            <h2>PharmAssist</h2>
            <p class="subtitle">Gestion de Pharmacie Intelligente</p>
        </div>

        <?php if(isset($_GET['error'])): ?>
            <div class="error-msg">
                <i class="fas fa-exclamation-circle"></i>
                Identifiants incorrects. Veuillez réessayer.
            </div>
        <?php endif; ?>

        <form action="traitement_login.php" method="POST">
            <div class="input-group">
                <i class="fas fa-user"></i>
                <input type="text" name="username" placeholder="Nom d'utilisateur" required>
            </div>
            
            <div class="input-group">
                <i class="fas fa-lock"></i>
                <input type="password" name="password" placeholder="Mot de passe" required>
            </div>

            <button type="submit" name="connexion" class="btn-login">
                Se connecter <i class="fas fa-arrow-right" style="margin-left: 10px;"></i>
            </button>
        </form>
    </div>
</div>

</body>
</html>