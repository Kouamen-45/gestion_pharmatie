<?php
session_start();
require_once 'db.php';
if (!isset($_SESSION['user_id'])) { header('Location: login.php'); exit(); }

$mois_selectionne = $_GET['mois'] ?? date('Y-m');

// 1. Calcul du Chiffre d'Affaires (CA)
$stmtCA = $pdo->prepare("SELECT SUM(total) as total_ca, COUNT(id_vente) as nb_ventes 
                         FROM ventes 
                         WHERE DATE_FORMAT(date_vente, '%Y-%m') = ?");
$stmtCA->execute([$mois_selectionne]);
$stats_ventes = $stmtCA->fetch();

// 2. Calcul de la Marge Brut
$stmtMarge = $pdo->prepare("
    SELECT SUM(dv.quantite * (dv.prix_unitaire - (SELECT AVG(prix_achat_unitaire) FROM detail_achats da WHERE da.id_produit = dv.id_produit))) as benefice_estime
    FROM detail_ventes dv
    JOIN ventes v ON dv.id_vente = v.id_vente
    WHERE DATE_FORMAT(v.date_vente, '%Y-%m') = ?");
$stmtMarge->execute([$mois_selectionne]);
$marge = $stmtMarge->fetch();

// 3. Top 5 des produits
$top_produits = $pdo->prepare("
    SELECT p.nom_commercial, SUM(dv.quantite) as total_qte, SUM(dv.quantite * dv.prix_unitaire) as total_montant
    FROM detail_ventes dv
    JOIN produits p ON dv.id_produit = p.id_produit
    JOIN ventes v ON dv.id_vente = v.id_vente
    WHERE DATE_FORMAT(v.date_vente, '%Y-%m') = ?
    GROUP BY p.id_produit
    ORDER BY total_qte DESC LIMIT 5");
$top_produits->execute([$mois_selectionne]);
$top = $top_produits->fetchAll();
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>PharmAssist - Rapport Mensuel</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        :root {
            --sidebar-width: 250px;
            --primary: #2c3e50;
            --secondary: #27ae60;
            --info: #3498db;
            --light: #f4f7f6;
        }
        body { margin: 0; font-family: 'Segoe UI', sans-serif; background: var(--light); display: flex; }

        /* Sidebar Unifiée */
        .sidebar { 
            width: var(--sidebar-width); 
            background: var(--primary); 
            height: 100vh; 
            color: white; 
            position: fixed; 
            overflow-y: auto; 
            display: flex; 
            flex-direction: column; 
        }
        .sidebar-header { padding: 20px; text-align: center; background: rgba(0,0,0,0.1); border-bottom: 1px solid rgba(255,255,255,0.1); }
        .sidebar-menu { list-style: none; padding: 0; margin: 0; }
        .sidebar-menu a { color: white; text-decoration: none; padding: 15px 20px; display: flex; align-items: center; gap: 12px; transition: 0.3s; border-bottom: 1px solid rgba(255,255,255,0.05); }
        .sidebar-menu a:hover { background: rgba(255,255,255,0.1); }
        .sidebar-menu a.active { background: var(--secondary); }

        /* Contenu */
        .main-content { margin-left: var(--sidebar-width); flex: 1; padding: 30px; }
        .header-flex { display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px; }

        /* Stats Cards */
        .grid-stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px; margin-bottom: 30px; }
        .stat-card { background: white; padding: 25px; border-radius: 12px; box-shadow: 0 4px 15px rgba(0,0,0,0.05); border-top: 4px solid var(--info); }
        .stat-card.profit { border-top-color: var(--secondary); }
        .stat-card h3 { margin: 0; color: #7f8c8d; font-size: 12px; text-transform: uppercase; letter-spacing: 1px; }
        .stat-card p { margin: 10px 0 0; font-size: 28px; font-weight: bold; color: var(--primary); }

        /* Tableau Top Produits */
        .section-card { background: white; padding: 25px; border-radius: 12px; box-shadow: 0 4px 15px rgba(0,0,0,0.05); }
        table { width: 100%; border-collapse: collapse; margin-top: 15px; }
        th { text-align: left; padding: 15px; color: #7f8c8d; border-bottom: 2px solid var(--light); font-size: 13px; }
        td { padding: 15px; border-bottom: 1px solid var(--light); }

        /* Progress Bar */
        .progress-container { width: 100%; display: flex; align-items: center; gap: 10px; }
        .progress-bar { flex: 1; background: #eee; height: 8px; border-radius: 4px; overflow: hidden; }
        .progress-fill { height: 100%; background: var(--secondary); border-radius: 4px; }

        /* Boutons & Inputs */
        .btn-print { background: var(--primary); color: white; border: none; padding: 12px 20px; border-radius: 8px; cursor: pointer; font-weight: bold; display: flex; align-items: center; gap: 8px; transition: 0.3s; }
        .btn-print:hover { background: #34495e; transform: translateY(-2px); }
        input[type="month"] { padding: 10px; border: 1px solid #ddd; border-radius: 8px; font-family: inherit; font-size: 14px; outline: none; }

        @media print {
            .sidebar, .btn-print, form { display: none !important; }
            .main-content { margin-left: 0 !important; padding: 0 !important; }
            .section-card, .stat-card { box-shadow: none !important; border: 1px solid #eee !important; }
        }
    </style>
</head>
<body>

    <nav class="sidebar">
        <div class="sidebar-header">
           <img src="logo_pharmassist.png" style="width: 80px; height: 80px; object-fit: contain;" />
        </div>
        <ul class="sidebar-menu">
            <li><a href="dashboard.php"><i class="fas fa-th-large"></i> Dashboard</a></li>
            <li><a href="ventes.php"><i class="fas fa-shopping-cart"></i> Ventes</a></li>
            <li><a href="caisse.php"><i class="fas fa-cash-register"></i> Ma Caisse</a></li>
            <li><a href="stocks_inventaire.php"><i class="fas fa-boxes"></i> Inventaire Stock</a></li>
            <li><a href="rapport_mensuel.php" class="active"><i class="fas fa-chart-bar"></i> Rapports</a></li>
            <li><a href="achat_nouveau.php"><i class="fas fa-cart-plus"></i> Achats</a></li>
            <li><a href="achats_historique.php"><i class="fas fa-history"></i> Historique Achats</a></li>
            <li><a href="archives_caisse.php"><i class="fas fa-archive"></i> Archive Caisse</a></li>
            <li><a href="facture.php"><i class="fas fa-file-invoice"></i> Historique Ventes</a></li>
            <li style="margin-top: 30px;"><a href="logout.php" style="color: #ff7675;"><i class="fas fa-sign-out-alt"></i> Déconnexion</a></li>
        </ul>
    </nav>

    <div class="main-content">
        <div class="header-flex">
            <div>
                <h2 style="margin:0;"><i class="fas fa-file-contract" style="color: var(--info);"></i> Rapport d'Activité</h2>
                <small style="color: #7f8c8d;">Analyse des performances pour la période sélectionnée</small>
            </div>
            <div style="display: flex; gap: 15px; align-items: center;">
                <form method="GET" id="monthForm">
                    <input type="month" name="mois" value="<?= $mois_selectionne ?>" onchange="document.getElementById('monthForm').submit()">
                </form>
                <button class="btn-print" onclick="window.print()">
                    <i class="fas fa-print"></i> EXPORTER PDF / IMPRIMER
                </button>
            </div>
        </div>

        <div class="grid-stats">
            <div class="stat-card">
                <h3><i class="fas fa-coins"></i> Chiffre d'Affaires</h3>
                <p><?= number_format($stats_ventes['total_ca'] ?? 0, 0, '.', ' ') ?> F</p>
            </div>
            <div class="stat-card profit">
                <h3><i class="fas fa-chart-line"></i> Bénéfice Estimé (Marge)</h3>
                <p style="color: var(--secondary);"><?= number_format($marge['benefice_estime'] ?? 0, 0, '.', ' ') ?> F</p>
            </div>
            <div class="stat-card">
                <h3><i class="fas fa-shopping-basket"></i> Volume de Ventes</h3>
                <p><?= $stats_ventes['nb_ventes'] ?> <small style="font-size: 14px; color:#bdc3c7;">Transactions</small></p>
            </div>
        </div>

        <div class="section-card">
            <h3 style="margin-top:0; display:flex; align-items:center; gap:10px;">
                <i class="fas fa-medal" style="color: #f1c40f;"></i> 
                Top 5 des Produits les plus performants
            </h3>
            <table>
                <thead>
                    <tr>
                        <th>Nom du Produit</th>
                        <th style="text-align: center;">Qté Vendue</th>
                        <th>Revenu Généré</th>
                        <th>Contribution au CA (%)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($top as $p): 
                        $part = ($stats_ventes['total_ca'] > 0) ? ($p['total_montant'] / $stats_ventes['total_ca']) * 100 : 0;
                    ?>
                    <tr>
                        <td><b><?= htmlspecialchars($p['nom_commercial']) ?></b></td>
                        <td style="text-align: center;"><span style="background: var(--light); padding: 5px 12px; border-radius: 15px; font-weight: bold;"><?= $p['total_qte'] ?></span></td>
                        <td style="font-weight: bold;"><?= number_format($p['total_montant'], 0, '.', ' ') ?> F</td>
                        <td>
                            <div class="progress-container">
                                <div class="progress-bar">
                                    <div class="progress-fill" style="width: <?= $part ?>%;"></div>
                                </div>
                                <span style="font-size: 12px; font-weight: bold; min-width: 40px;"><?= round($part, 1) ?>%</span>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if(empty($top)): ?>
                        <tr><td colspan="4" style="text-align:center; padding:40px; color:#95a5a6;">Aucune donnée disponible pour ce mois.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

</body>
</html>