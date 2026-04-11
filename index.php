<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>PharmAssist - Connexion</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="icon" type="image/png" href="logo_pharmassist.png">
    <style>
        :root { --primary: #2c3e50; --secondary: #27ae60; --light: #ecf0f1; }
        body {
            margin: 0; padding: 0; font-family: 'Segoe UI', sans-serif;
            background: linear-gradient(-45deg, #2c3e50, #27ae60, #2980b9);
            background-size: 400% 400%;
            animation: gradient 15s ease infinite;
            height: 100vh; display: flex; align-items: center; justify-content: center;
        }
        @keyframes gradient { 0% {background-position: 0% 50%;} 50% {background-position: 100% 50%;} 100% {background-position: 0% 50%;} }
        
        .login-card {
            background: rgba(255, 255, 255, 0.95); padding: 40px;
            border-radius: 20px; box-shadow: 0 15px 35px rgba(0,0,0,0.2);
            width: 100%; max-width: 400px; text-align: center;
            transform: translateY(0); transition: 0.3s;
        }
        .login-card:hover { transform: translateY(-5px); }
        .logo { width: 100px; margin-bottom: 10px; border-radius: 50%; }
        h2 { color: var(--primary); margin-bottom: 30px; }
        .input-group { position: relative; margin-bottom: 20px; }
        .input-group i { position: absolute; left: 15px; top: 12px; color: #7f8c8d; }
        input {
            width: 100%; padding: 12px 15px 12px 45px; border: 1px solid #ddd;
            border-radius: 25px; outline: none; box-sizing: border-box; transition: 0.3s;
        }
        input:focus { border-color: var(--secondary); box-shadow: 0 0 8px rgba(39, 174, 96, 0.2); }
        button {
            width: 100%; padding: 12px; background: var(--secondary); border: none;
            color: white; border-radius: 25px; font-weight: bold; cursor: pointer;
            transition: 0.3s; margin-top: 10px;
        }
        button:hover { background: #219150; letter-spacing: 1px; }
        .error { color: #e74c3c; font-size: 0.9em; margin-bottom: 15px; }
    </style>
</head>
<body>
    <div class="login-card">
        <img src="logo_pharmassist.png" alt="Logo PharmAssist" class="logo">
        <h2>PharmAssist</h2>
        
        <?php if(isset($_GET['error'])): ?>
            <div class="error">Identifiants incorrects</div>
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
            <button type="submit" name="connexion">Se connecter</button>
        </form>
    </div>
</body>
</html>