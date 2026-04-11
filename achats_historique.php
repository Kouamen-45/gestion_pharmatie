<?php
session_start();
require_once 'db.php';
if (!isset($_SESSION['user_id'])) { header('Location: login.php'); exit(); }

$achats = $pdo->query("SELECT a.*, u.username 
                       FROM achats a 
                       LEFT JOIN utilisateurs u ON a.id_utilisateur = u.id_user 
                       ORDER BY a.date_achat DESC")->fetchAll();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>PharmAssist - Historique Achats</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <style>
        :root {
            --sidebar-width: 250px;
            --primary: #2c3e50;
            --secondary: #27ae60;
            --warning: #f39c12;
            --danger: #e74c3c;
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
        .sidebar-menu li { border-bottom: 1px solid rgba(255,255,255,0.05); }
        .sidebar-menu a { color: white; text-decoration: none; padding: 15px 20px; display: flex; align-items: center; gap: 12px; transition: 0.3s; }
        .sidebar-menu a:hover { background: rgba(255,255,255,0.1); }
        .sidebar-menu a.active { background: var(--secondary); }
        .sidebar::-webkit-scrollbar { width: 5px; }
        .sidebar::-webkit-scrollbar-thumb { background: rgba(255, 255, 255, 0.2); border-radius: 10px; }

        /* Contenu Principal */
        .main-content { margin-left: var(--sidebar-width); flex: 1; padding: 30px; }
        
        .section-card { background: white; padding: 25px; border-radius: 12px; box-shadow: 0 4px 15px rgba(0,0,0,0.05); }
        h2 { color: var(--primary); margin-bottom: 25px; display: flex; align-items: center; gap: 10px; }

        /* Table Styles */
        table { width: 100%; border-collapse: collapse; }
        th { text-align: left; padding: 15px; color: #7f8c8d; border-bottom: 2px solid var(--light); font-size: 13px; text-transform: uppercase; }
        td { padding: 15px; border-bottom: 1px solid var(--light); color: var(--primary); font-size: 14px; }
        
        .id-badge { background: #edf2f7; padding: 4px 8px; border-radius: 6px; font-family: monospace; font-weight: bold; color: var(--primary); }
        
        .btn-detail { 
            background: var(--info); 
            color: white; 
            border: none; 
            width: 35px; 
            height: 35px; 
            border-radius: 8px; 
            cursor: pointer; 
            transition: 0.2s;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .btn-detail:hover { transform: scale(1.1); background: #2980b9; }

        /* Styles spécifique pour la popup de détails */
        .swal-table { width: 100%; border-collapse: collapse; font-size: 0.9rem; }
        .swal-table th { background: #f8f9fa; padding: 10px; border-bottom: 2px solid #dee2e6; }
        .swal-table td { padding: 10px; border-bottom: 1px solid #eee; }
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
            <li><a href="stocks_inventaire.php"><i class="fas fa-boxes"></i> Inventaire Stock</a></li>
            <li><a href="rapport_mensuel.php"><i class="fas fa-chart-bar"></i> Rapports</a></li>
            <li><a href="achat_nouveau.php"><i class="fas fa-cart-plus"></i> Achats</a></li>
            <li><a href="achats_historique.php" class="active"><i class="fas fa-history"></i> Historique Achats</a></li>
            <li><a href="archives_caisse.php"><i class="fas fa-archive"></i> Archive Caisse</a></li>
            <li><a href="facture.php"><i class="fas fa-file-invoice"></i> Historique Ventes</a></li>
            <li style="margin-top: 30px;"><a href="logout.php" style="color: #ff7675;"><i class="fas fa-sign-out-alt"></i> Déconnexion</a></li>
        </ul>
    </nav>

    <div class="main-content">
        <h2><i class="fas fa-history"></i> Historique des Réceptions</h2>

        <div class="section-card">
            <table>
                <thead>
                    <tr>
                        <th>N° Achat</th>
                        <th>Date & Heure</th>
                        <th>Fournisseur</th>
                        <th>Montant Total</th>
                        <th>Réceptionné par</th>
                        <th style="text-align: center;">Détails</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($achats as $a): ?>
                    <tr>
                        <td><span class="id-badge">#<?= $a['id_achat'] ?></span></td>
                        <td>
                            <i class="far fa-calendar-alt" style="color:#bdc3c7"></i> <?= date('d/m/Y', strtotime($a['date_achat'])) ?><br>
                            <small style="color:#95a5a6"><i class="far fa-clock"></i> <?= date('H:i', strtotime($a['date_achat'])) ?></small>
                        </td>
                        <td><i class="fas fa-truck" style="color:#bdc3c7"></i> <b><?= htmlspecialchars($a['fournisseur']) ?></b></td>
                        <td style="color: var(--secondary); font-weight: bold;"><?= number_format($a['montant_total'], 0, '.', ' ') ?> F</td>
                        <td><i class="far fa-user" style="color:#bdc3c7"></i> <?= htmlspecialchars($a['username']) ?></td>
                        <td style="text-align: center;">
                            <button class="btn-detail" onclick="voirDetails(<?= $a['id_achat'] ?>)" title="Voir le détail des produits">
                                <i class="fas fa-eye"></i>
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if(empty($achats)): ?>
                        <tr><td colspan="6" style="text-align:center; padding:30px; color:#95a5a6;">Aucun achat enregistré.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
    function voirDetails(id) {
        $.get('ajax_achats.php', { action: 'get_achat_details', id_achat: id }, function(res) {
            let html = `
                <div style="max-height: 400px; overflow-y: auto;">
                    <table class="swal-table">
                        <thead>
                            <tr>
                                <th>Produit</th>
                                <th style="text-align:center;">Qté</th>
                                <th style="text-align:right;">P. Achat</th>
                                <th style="text-align:center;">Péremption</th>
                            </tr>
                        </thead>
                        <tbody>`;
            
            res.forEach(d => {
                html += `
                    <tr>
                        <td style="text-align:left;"><b>${d.nom_commercial}</b></td>
                        <td style="text-align:center;">${d.quantite_recue}</td>
                        <td style="text-align:right;">${parseInt(d.prix_achat_unitaire).toLocaleString()} F</td>
                        <td style="text-align:center;"><small>${d.date_peremption}</small></td>
                    </tr>`;
            });
            
            html += `</tbody></table></div>`;

            Swal.fire({ 
                title: 'Détails de l\'achat #' + id, 
                html: html, 
                width: '700px',
                confirmButtonColor: '#2c3e50',
                confirmButtonText: 'Fermer'
            });
        }, 'json').fail(function() {
            Swal.fire('Erreur', 'Impossible de charger les détails.', 'error');
        });
    }
    </script>
</body>
</html>