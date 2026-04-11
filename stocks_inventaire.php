<?php
session_start();
require_once 'db.php';
if (!isset($_SESSION['user_id'])) { header('Location: login.php'); exit(); }

// Requête pour récupérer les stocks avec les infos produits et catégories
$stocks = $pdo->query("SELECT s.*, p.nom_commercial, c.nom_categorie 
                       FROM stocks s
                       JOIN produits p ON s.id_produit = p.id_produit
                       LEFT JOIN categories c ON p.id_categorie = c.id_categorie
                       WHERE s.quantite_disponible > 0
                       ORDER BY s.date_peremption ASC")->fetchAll();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>PharmAssist - Inventaire des Stocks</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        :root {
            --sidebar-width: 250px;
            --primary: #2c3e50;
            --secondary: #27ae60;
            --danger: #e74c3c;
            --warning: #f39c12;
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
            z-index: 1000;
        }
        .sidebar-header { padding: 20px; text-align: center; background: rgba(0,0,0,0.1); border-bottom: 1px solid rgba(255,255,255,0.1); }
        .sidebar-menu { list-style: none; padding: 0; margin: 0; }
        .sidebar-menu a { color: white; text-decoration: none; padding: 15px 20px; display: flex; align-items: center; gap: 12px; transition: 0.3s; border-bottom: 1px solid rgba(255,255,255,0.05); }
        .sidebar-menu a:hover { background: rgba(255,255,255,0.1); }
        .sidebar-menu a.active { background: var(--secondary); }

        /* Contenu Principal */
        .main-content { margin-left: var(--sidebar-width); flex: 1; padding: 30px; }
        
        .header-flex { display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px; }
        
        /* Recherche */
        .search-container { position: relative; width: 350px; }
        .search-container i { position: absolute; left: 15px; top: 12px; color: #95a5a6; }
        .search-box { 
            width: 100%; padding: 10px 10px 10px 40px; 
            border: 1px solid #ddd; border-radius: 25px; 
            outline: none; transition: 0.3s; box-sizing: border-box;
        }
        .search-box:focus { border-color: var(--info); box-shadow: 0 0 8px rgba(52, 152, 219, 0.2); }

        /* Table Card */
        .section-card { background: white; padding: 25px; border-radius: 12px; box-shadow: 0 4px 15px rgba(0,0,0,0.05); }
        table { width: 100%; border-collapse: collapse; }
        th { text-align: left; padding: 15px; color: #7f8c8d; border-bottom: 2px solid var(--light); font-size: 13px; text-transform: uppercase; }
        td { padding: 15px; border-bottom: 1px solid var(--light); color: var(--primary); font-size: 14px; }
        
        /* Badges & Pills */
        .status-pill { padding: 5px 12px; border-radius: 20px; font-size: 0.75rem; font-weight: bold; color: white; display: inline-block; }
        .bg-danger { background-color: var(--danger); }
        .bg-warning { background-color: var(--warning); }
        .bg-success { background-color: var(--secondary); }
        
        .lot-code { background: #edf2f7; padding: 3px 8px; border-radius: 4px; font-family: 'Courier New', monospace; font-weight: bold; font-size: 0.9em; }
        .qty-badge { background: var(--primary); color: white; padding: 4px 10px; border-radius: 6px; font-weight: bold; }

        /* Actions buttons */
        .action-link { 
            padding: 6px 12px; border-radius: 6px; text-decoration: none; font-size: 13px; font-weight: 500;
            display: inline-flex; align-items: center; gap: 5px; transition: 0.2s;
        }
        .btn-mouvement { color: var(--info); border: 1px solid var(--info); }
        .btn-mouvement:hover { background: var(--info); color: white; }
        .btn-ajuster { color: #e67e22; border: 1px solid #e67e22; margin-left: 5px; }
        .btn-ajuster:hover { background: #e67e22; color: white; }
    </style>
</head>
<body>

    <nav class="sidebar">
        <div class="sidebar-header">
           <img src="logo_pharmassist.png" style="width: 80px;height: 80px; object-fit: contain;" />
        </div>
        <ul class="sidebar-menu">
            <li><a href="dashboard.php"><i class="fas fa-th-large"></i> Dashboard</a></li>
            <li><a href="ventes.php"><i class="fas fa-shopping-cart"></i> Ventes</a></li>
            <li><a href="caisse.php"><i class="fas fa-cash-register"></i> Ma Caisse</a></li>
            <li><a href="stocks_inventaire.php" class="active"><i class="fas fa-boxes"></i> Inventaire Stock</a></li>
            <li><a href="rapport_mensuel.php"><i class="fas fa-chart-bar"></i> Rapports</a></li>
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
                <h2 style="margin:0;"><i class="fas fa-boxes" style="color: var(--secondary);"></i> État du Stock par Lot</h2>
                <small style="color: #7f8c8d;">Liste détaillée des produits disponibles en rayon et réserve</small>
            </div>
            <div class="search-container">
                <i class="fas fa-search"></i>
                <input type="text" id="searchInput" class="search-box" placeholder="Rechercher un produit..." onkeyup="filterTable()">
            </div>
        </div>

        <div class="section-card">
            <table id="stockTable">
                <thead>
                    <tr>
                        <th>Désignation Produit</th>
                        <th>Catégorie</th>
                        <th>N° Lot</th>
                        <th>Date Péremption</th>
                        <th>Quantité</th>
                        <th>Statut</th>
                        <th style="text-align: center;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($stocks as $s): 
                        $date_p = new DateTime($s['date_peremption']);
                        $aujourdhui = new DateTime();
                        $diff = $aujourdhui->diff($date_p);
                        $mois_restants = ($diff->y * 12) + $diff->m;

                        $statut_classe = "bg-success";
                        $statut_texte = "CONFORME";

                        if ($date_p < $aujourdhui) {
                            $statut_classe = "bg-danger";
                            $statut_texte = "PÉRIMÉ";
                        } elseif ($mois_restants <= 6) {
                            $statut_classe = "bg-warning";
                            $statut_texte = "ALERTE PROCHE";
                        }
                    ?>
                    <tr>
                        <td><b><?= htmlspecialchars($s['nom_commercial']) ?></b></td>
                        <td><span style="color: #7f8c8d;"><?= htmlspecialchars($s['nom_categorie'] ?? 'Générique') ?></span></td>
                        <td><span class="lot-code"><?= htmlspecialchars($s['numero_lot']) ?></span></td>
                        <td>
                            <i class="far fa-calendar-alt" style="color:#bdc3c7"></i> 
                            <?= date('d/m/Y', strtotime($s['date_peremption'])) ?>
                        </td>
                        <td><span class="qty-badge"><?= $s['quantite_disponible'] ?></span></td>
                        <td><span class="status-pill <?= $statut_classe ?>"><?= $statut_texte ?></span></td>
                        <td style="text-align: center;">
                            <a href="produit_mouvements.php?id=<?= $s['id_produit'] ?>" class="action-link btn-mouvement" title="Historique des flux">
                                <i class="fas fa-exchange-alt"></i> Flux
                            </a>
                            <a href="stock_ajustement.php?id_stock=<?= $s['id_stock'] ?>" class="action-link btn-ajuster" title="Corriger le stock">
                                <i class="fas fa-tools"></i>
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php if(empty($stocks)): ?>
                <div style="text-align:center; padding:50px; color:#95a5a6;">
                    <i class="fas fa-box-open fa-3x"></i><br><br>
                    Aucun stock disponible actuellement.
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
    function filterTable() {
        let input = document.getElementById("searchInput");
        let filter = input.value.toUpperCase();
        let table = document.getElementById("stockTable");
        let tr = table.getElementsByTagName("tr");

        for (let i = 1; i < tr.length; i++) {
            let td = tr[i].getElementsByTagName("td")[0]; 
            if (td) {
                let txtValue = td.textContent || td.innerText;
                tr[i].style.display = txtValue.toUpperCase().indexOf(filter) > -1 ? "" : "none";
            }
        }
    }
    </script>
</body>
</html>