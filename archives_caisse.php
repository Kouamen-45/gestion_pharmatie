<?php
session_start();
require_once 'db.php';
if (!isset($_SESSION['user_id'])) { header('Location: login.php'); exit(); }

// Récupération des sessions fermées (de la plus récente à la plus ancienne)
$archives = $pdo->query("SELECT s.*, u.username 
                         FROM sessions_caisse s
                         LEFT JOIN utilisateurs u ON s.id_utilisateur = u.id_user
                         WHERE s.statut = 'ferme'
                         ORDER BY s.date_cloture DESC")->fetchAll();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>PharmAssist - Archives Caisse</title>
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
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th { text-align: left; padding: 15px; color: #7f8c8d; border-bottom: 2px solid var(--light); font-size: 13px; text-transform: uppercase; }
        td { padding: 15px; border-bottom: 1px solid var(--light); color: var(--primary); font-size: 14px; }
        
        .ecart-negatif { color: var(--danger); font-weight: bold; background: #fdedec; padding: 4px 8px; border-radius: 4px; }
        .ecart-positif { color: var(--secondary); font-weight: bold; background: #eafaf1; padding: 4px 8px; border-radius: 4px; }
        .ecart-null { color: #7f8c8d; font-style: italic; }

        .btn-view { 
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
        .btn-view:hover { transform: scale(1.1); opacity: 0.9; }
        
        small { color: #95a5a6; }
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
            <li><a href="achats_historique.php"><i class="fas fa-history"></i> Historique Achats</a></li>
            <li><a href="archives_caisse.php" class="active"><i class="fas fa-archive"></i> Archive Caisse</a></li>
            <li><a href="facture.php"><i class="fas fa-file-invoice"></i> Historique Ventes</a></li>
            <li style="margin-top: 30px;"><a href="logout.php" style="color: #ff7675;"><i class="fas fa-sign-out-alt"></i> Déconnexion</a></li>
        </ul>
    </nav>

    <div class="main-content">
        <h2><i class="fas fa-archive"></i> Historique des Clôtures de Caisse</h2>

        <div class="section-card">
            <table>
                <thead>
                    <tr>
                        <th>Ouverture</th>
                        <th>Clôture</th>
                        <th>Caissier</th>
                        <th>Fond Départ</th>
                        <th>Montant Réel</th>
                        <th>Écart</th>
                        <th style="text-align: center;">Détails</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($archives as $s): ?>
                    <tr>
                        <td>
                            <i class="far fa-calendar-alt" style="color: #bdc3c7;"></i> <?= date('d/m/y', strtotime($s['date_ouverture'])) ?><br>
                            <small><i class="far fa-clock"></i> <?= date('H:i', strtotime($s['date_ouverture'])) ?></small>
                        </td>
                        <td>
                            <i class="far fa-calendar-check" style="color: #bdc3c7;"></i> <?= date('d/m/y', strtotime($s['date_cloture'])) ?><br>
                            <small><i class="far fa-clock"></i> <?= date('H:i', strtotime($s['date_cloture'])) ?></small>
                        </td>
                        <td><i class="fas fa-user-circle"></i> <?= htmlspecialchars($s['username']) ?></td>
                        <td><?= number_format($s['fond_caisse_depart'], 0, '.', ' ') ?> F</td>
                        <td><b><?= number_format($s['montant_final_reel'], 0, '.', ' ') ?> F</b></td>
                        <td>
                            <?php 
                            $ecart = $s['montant_final_reel'] - $s['montant_theorique'];
                            if($ecart == 0) echo "<span class='ecart-null'>Aucun</span>";
                            elseif($ecart > 0) echo "<span class='ecart-positif'>+" . number_format($ecart, 0, '.', ' ') . " F</span>";
                            else echo "<span class='ecart-negatif'>" . number_format($ecart, 0, '.', ' ') . " F</span>";
                            ?>
                        </td>
                        <td style="text-align: center;">
                            <button class="btn-view" onclick="voirDetailsSession(<?= $s['id_session'] ?>)" title="Voir mouvements">
                                <i class="fas fa-eye"></i>
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    
    <script>
    function voirDetailsSession(id) {
        $.get('ajax_caisse.php', { action: 'get_session_details', id_session: id }, function(res) {
            if (res.length === 0) {
                Swal.fire('Info', 'Aucun mouvement enregistré pour cette session.', 'info');
                return;
            }

            let html = `
                <div style="max-height: 450px; overflow-y: auto; padding: 5px;">
                    <table style="width:100%; border-collapse: collapse; font-size: 0.85rem;">
                        <thead>
                            <tr style="background: #f8f9fa; border-bottom: 2px solid #eee;">
                                <th style="padding: 10px; text-align: left;">Heure</th>
                                <th style="padding: 10px; text-align: left;">Type</th>
                                <th style="padding: 10px; text-align: left;">Motif</th>
                                <th style="padding: 10px; text-align: right;">Montant</th>
                            </tr>
                        </thead>
                        <tbody>`;
            
            res.forEach(m => {
                let color = (m.type_mouvement === 'entree' || m.type_mouvement === 'ouverture') ? '#27ae60' : '#e74c3c';
                let icon = (m.type_mouvement === 'entree' || m.type_mouvement === 'ouverture') ? 'fa-arrow-up' : 'fa-arrow-down';
                let heure = m.date_mouvement.split(' ')[1].substring(0, 5);
                
                html += `
                    <tr style="border-bottom: 1px solid #f4f4f4;">
                        <td style="padding: 10px;">${heure}</td>
                        <td style="padding: 10px; font-weight:bold; color:${color}">
                            <i class="fas ${icon} fa-xs"></i> ${m.type_mouvement.toUpperCase()}
                        </td>
                        <td style="padding: 10px;">${m.motif}</td>
                        <td style="padding: 10px; text-align: right; font-weight:bold;">${parseInt(m.montant).toLocaleString()} F</td>
                    </tr>`;
            });

            html += `</tbody></table></div>`;

            Swal.fire({
                title: 'Journal Session #' + id,
                html: html,
                width: '650px',
                confirmButtonText: 'Fermer',
                confirmButtonColor: '#2c3e50'
            });
        }, 'json').fail(function() {
            Swal.fire('Erreur', 'Impossible de charger les détails.', 'error');
        });
    }
    </script>
</body>
</html>