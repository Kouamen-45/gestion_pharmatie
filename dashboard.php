<?php
session_start();
require_once 'db.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

// 1. Chiffre d'affaires du jour
$sql_ca = "SELECT SUM(total) as total_ca FROM ventes WHERE DATE(date_vente) = CURDATE()";
$res_ca = $pdo->query($sql_ca)->fetch();
$ca_du_jour = $res_ca['total_ca'] ?? 0;

// 2. Produits en rupture
$sql_rupture = "SELECT COUNT(*) as nb FROM produits p 
                WHERE (SELECT SUM(quantite_disponible) FROM stocks s WHERE s.id_produit = p.id_produit) <= p.seuil_alerte";
$res_rupture = $pdo->query($sql_rupture)->fetch();

// 3. Péremptions proches
$sql_peremption = "SELECT COUNT(*) as nb FROM stocks WHERE date_peremption <= DATE_ADD(CURDATE(), INTERVAL 90 DAY) AND quantite_disponible > 0";
$res_peremption = $pdo->query($sql_peremption)->fetch();

// 4. État de la caisse actuelle
$sql_caisse = "SELECT statut FROM sessions_caisse WHERE statut = 'ouvert' LIMIT 1";
$caisse_active = $pdo->query($sql_caisse)->fetch();

// 5. Dernières ventes
$sql_recentes_ventes = "SELECT v.*, u.username FROM ventes v 
                        JOIN utilisateurs u ON v.id_utilisateur = u.id_user 
                        ORDER BY v.date_vente DESC LIMIT 5";
$recentes_ventes = $pdo->query($sql_recentes_ventes)->fetchAll();

// 6. Données pour le graphique (7 derniers jours)
$labels = [];
$donnees = [];
for ($i = 6; $i >= 0; $i--) {
    $date = date('Y-m-d', strtotime("-$i days"));
    $labels[] = date('d/m', strtotime($date));
    $stmt = $pdo->prepare("SELECT SUM(total) as mt FROM ventes WHERE DATE(date_vente) = ?");
    $stmt->execute([$date]);
    $res = $stmt->fetch();
    $donnees[] = $res['mt'] ?? 0;
}

// 6. DONNÉES TOP 5 PRODUITS (Nouveauté)
$sql_top = "SELECT p.nom_commercial, SUM(dv.quantite) as total_vendu 
            FROM details_ventes dv 
            JOIN produits p ON dv.id_produit = p.id_produit 
            GROUP BY p.id_produit 
            ORDER BY total_vendu DESC LIMIT 5";
$top_produits = $pdo->query($sql_top)->fetchAll();

// 7. Données graphique (7 jours)
$labels = [];
$donnees = [];
for ($i = 6; $i >= 0; $i--) {
    $date = date('Y-m-d', strtotime("-$i days"));
    $labels[] = date('d/m', strtotime($date));
    $stmt = $pdo->prepare("SELECT SUM(total) as mt FROM ventes WHERE DATE(date_vente) = ?");
    $stmt->execute([$date]);
    $res = $stmt->fetch();
    $donnees[] = $res['mt'] ?? 0;
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>PharmAssist - Tableau de Bord</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="icon" type="image/png" href="logo_pharmassist.png">
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
        body { margin: 0; font-family: 'Segoe UI', sans-serif; background: var(--light); display: flex; transition: background 0.5s ease; }
        
        /* Animation Flash pour Alerte */
        @keyframes flash-red {
            0% { background-color: var(--light); }
            50% { background-color: #ffcccc; }
            100% { background-color: var(--light); }
        }
        .alert-flash { animation: flash-red 0.8s 3; }

        /* Sidebar */
        .sidebar { width: var(--sidebar-width); background: var(--primary); height: 100vh; color: white; position: fixed; }
        .sidebar-header { padding: 20px; text-align: center; background: rgba(0,0,0,0.1); border-bottom: 1px solid rgba(255,255,255,0.1); }
        .sidebar-menu { list-style: none; padding: 0; margin: 0; }
        .sidebar-menu li { border-bottom: 1px solid rgba(255,255,255,0.05); }
        .sidebar-menu li:hover { background: rgba(255,255,255,0.1); }
        .sidebar-menu a { color: white; text-decoration: none; padding: 15px 20px; display: flex; align-items: center; gap: 12px; transition: 0.3s; }
        .sidebar-menu a.active { background: var(--secondary); }

        /* Main Content */
        .main-content { margin-left: var(--sidebar-width); flex: 1; padding: 30px; }
        .header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px; }
        
        /* Stats Grid */
        .stats-container { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 20px; margin-bottom: 30px; }
        .card { background: white; padding: 20px; border-radius: 12px; box-shadow: 0 4px 15px rgba(0,0,0,0.05); display: flex; align-items: center; gap: 20px; }
        .card i { font-size: 35px; width: 60px; height: 60px; display: flex; align-items: center; justify-content: center; border-radius: 50%; }
        
        .card.ca i { background: #eafaf1; color: var(--secondary); }
        .card.danger i { background: #fdedec; color: var(--danger); }
        .card.warning i { background: #fef5e7; color: var(--warning); }
        .card.caisse i { background: #ebf5fb; color: var(--info); }
        
        .card-info h3 { margin: 0; font-size: 22px; color: #2c3e50; }
        .card-info p { margin: 5px 0 0; color: #7f8c8d; font-size: 14px; text-transform: uppercase; }

        /* Tables & Search */
        .section-card { background: white; padding: 25px; border-radius: 12px; box-shadow: 0 4px 15px rgba(0,0,0,0.05); margin-bottom: 30px; }
        .section-card h2 { margin-top: 0; font-size: 18px; color: var(--primary); display: flex; align-items: center; gap: 10px; }
        
        table { width: 100%; border-collapse: collapse; margin-top: 15px; }
        th { text-align: left; padding: 12px; color: #7f8c8d; border-bottom: 2px solid #f4f7f6; }
        td { padding: 12px; border-bottom: 1px solid #f4f7f6; color: #2c3e50; }
        
        .status-open { color: var(--secondary); font-weight: bold; }
        .status-closed { color: var(--danger); font-weight: bold; }
        .high-amount { color: var(--danger); font-weight: bold; font-size: 1.1em; }

        #globalSearch { width: 100%; padding: 12px 12px 12px 45px; border: 2px solid #f4f7f6; border-radius: 8px; font-size: 16px; box-sizing: border-box; outline: none; transition: 0.3s; }
        #globalSearch:focus { border-color: var(--info); }

        .sidebar {
    width: var(--sidebar-width);
    background: var(--primary);
    height: 100vh;
    color: white;
    position: fixed;
    /* --- AJOUTEZ CES DEUX LIGNES --- */
    overflow-y: auto;  /* Autorise le scroll vertical si le menu est trop long */
    display: flex;
    flex-direction: column;
}

/* Optionnel : Rendre la barre de défilement plus discrète */
.sidebar::-webkit-scrollbar {
    width: 5px;
}
.sidebar::-webkit-scrollbar-thumb {
    background: rgba(255, 255, 255, 0.2);
    border-radius: 10px;
}
    </style>
</head>
<body id="dashboardBody">

    <nav class="sidebar">
        <div class="sidebar-header">
           <img src="logo_pharmassist.png" style="width: 90px;height: 90px;" />
        </div>
        <ul class="sidebar-menu">
            <li><a href="dashboard.php" class="active"><i class="fas fa-th-large"></i> Dashboard</a></li>
            <li><a href="ventes.php"><i class="fas fa-shopping-cart"></i> Ventes</a></li>
            <li><a href="caisse.php"><i class="fas fa-cash-register"></i> Ma Caisse</a></li>
            <li><a href="archives_caisse.php"><i class="fas fa-file-invoice-dollar"></i>Archive Caisse</a></li>
            <li><a href="archives_caisse.php"><i class="fas fa-file-invoice-dollar"></i>Produits & Stocks</a></li>
            <li><a href="stocks_inventaire.php"><i class="fas fa-boxes"></i> Inventaire Stock</a></li>
            <li><a href="rapport_mensuel.php"><i class="fas fa-file-invoice-dollar"></i> Rapports</a></li>
            <li><a href="achat_nouveau.php"><i class="fas fa-file-invoice-dollar"></i> Achats</a></li>
            <li><a href="achats_historique.php"><i class="fas fa-file-invoice-dollar"></i>Historique Achats</a></li>
            <li><a href="facture.php"><i class="fas fa-file-invoice-dollar"></i>Factures</a></li>
            <li style="margin-top: 30px;"><a href="logout.php" style="color: #ff7675;"><i class="fas fa-sign-out-alt"></i> Déconnexion</a></li>
        </ul>
    </nav>

    <div class="main-content">
        <div class="header">
            <div>
                <h1>Tableau de Bord</h1>
                <p style="color: #7f8c8d; margin: 0;">Session de : <b><?php echo htmlspecialchars($_SESSION['username']); ?></b></p>
            </div>
            <div style="text-align: right;">
                <div style="font-size: 18px; font-weight: bold;"><?php echo date('d F Y'); ?></div>
                <div id="clock" style="color: var(--info); font-weight: bold;"></div>
            </div>
        </div>

        <div class="section-card">
            <div style="position: relative;">
                <i class="fas fa-search" style="position: absolute; left: 15px; top: 15px; color: #7f8c8d;"></i>
                <input type="text" id="globalSearch" placeholder="Recherche rapide de prix ou stock...">
                <div id="searchResults" style="display: none; position: absolute; width: 100%; background: white; z-index: 1000; box-shadow: 0 10px 25px rgba(0,0,0,0.1); border-radius: 0 0 8px 8px; max-height: 400px; overflow-y: auto;"></div>
            </div>
        </div>

        <div class="stats-container">
            <div class="card ca">
                <i class="fas fa-money-bill-wave"></i>
                <div class="card-info">
                    <h3><?php echo number_format($ca_du_jour, 0, '.', ' '); ?> F</h3>
                    <p>Ventes du Jour</p>
                </div>
            </div>
            <div class="card caisse">
                <i class="fas fa-vault"></i>
                <div class="card-info">
                    <h3 class="<?php echo $caisse_active ? 'status-open' : 'status-closed'; ?>">
                        <?php echo $caisse_active ? 'OUVERTE' : 'FERMÉE'; ?>
                    </h3>
                    <p>État Caisse</p>
                </div>
            </div>
            <div class="card danger">
                <i class="fas fa-exclamation-circle"></i>
                <div class="card-info">
                    <h3><?php echo $res_rupture['nb']; ?></h3>
                    <p>Ruptures</p>
                </div>
            </div>
            <div class="card warning">
                <i class="fas fa-hourglass-end"></i>
                <div class="card-info">
                    <h3><?php echo $res_peremption['nb']; ?></h3>
                    <p>Périmés (90j)</p>
                </div>
            </div>
        </div>

           <div class="right-col">
                <div class="section-card">
                    <h2><i class="fas fa-crown" style="color: gold;"></i> Top 5 Ventes</h2>
                    <p style="font-size: 12px; color: #7f8c8d; margin-bottom: 20px;">Volumes les plus vendus</p>
                    
                    <?php 
                    $max_vendu = !empty($top_produits) ? $top_produits[0]['total_vendu'] : 1; 
                    foreach($top_produits as $tp): 
                        $pct = ($tp['total_vendu'] / $max_vendu) * 100;
                    ?>
                    <div class="top-item">
                        <div class="top-info">
                            <span><?= htmlspecialchars($tp['nom_commercial']) ?></span>
                            <b><?= $tp['total_vendu'] ?> u.</b>
                        </div>
                        <div class="progress-bar">
                            <div class="progress-fill" style="width: <?= $pct ?>%;"></div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                
                
            </div>

        <div class="section-card">
            <h2><i class="fas fa-chart-line"></i> Performance de la semaine</h2>
            <div style="height: 300px; width: 100%;">
                <canvas id="ventesChart"></canvas>
            </div>
        </div>

        <div class="section-card">
            <h2><i class="fas fa-shopping-bag"></i> Dernières Ventes</h2>
            <table id="ventesTable">
                <thead>
                    <tr>
                        <th>Heure</th>
                        <th>Référence</th>
                        <th>Vendeur</th>
                        <th>Montant</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($recentes_ventes as $v): ?>
                    <tr class="vente-row" data-montant="<?php echo $v['total']; ?>">
                        <td><?php echo date('H:i', strtotime($v['date_vente'])); ?></td>
                        <td>#<?php echo $v['id_vente']; ?></td>
                        <td><?php echo htmlspecialchars($v['username']); ?></td>
                        <td class="montant-valeur"><b><?php echo number_format($v['total'], 0, '.', ' '); ?> F</b></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>


    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    
    <script>
        // 1. Horloge
        function updateClock() {
            document.getElementById('clock').innerText = new Date().toLocaleTimeString('fr-FR');
        }
        setInterval(updateClock, 1000);
        updateClock();

        // 2. Système d'Alerte (Seuil : 50 000 F)
        const SEUIL_ALERTE = 50000;

        function declencherAlerte(montant, vendeur) {
            // Alerte Vocale
            const annonce = new SpeechSynthesisUtterance("Attention, vente importante de " + montant + " francs par " + vendeur);
            annonce.lang = 'fr-FR';
            window.speechSynthesis.speak(annonce);

            // Alerte Visuelle (Flash)
            document.body.classList.add('alert-flash');
            setTimeout(() => { document.body.classList.remove('alert-flash'); }, 3000);
        }

        // Vérification des ventes au chargement
        $(document).ready(function() {
            $('.vente-row').each(function() {
                let montant = parseFloat($(this).data('montant'));
                let vendeur = $(this).find('td:nth-child(3)').text();
                
                if (montant >= SEUIL_ALERTE) {
                    $(this).find('.montant-valeur').addClass('high-amount');
                    // On ne déclenche l'alerte sonore qu'une fois pour la dernière vente si elle est récente
                    if($(this).is(':first-child')) {
                        declencherAlerte(montant, vendeur);
                    }
                }
            });
        });

        // 3. Recherche Live
        $(document).ready(function() {
            $('#globalSearch').on('keyup', function() {
                let query = $(this).val();
                if (query.length > 1) {
                    $.get('ajax_recherche.php', { q: query }, function(data) {
                        $('#searchResults').html(data).show();
                    });
                } else { $('#searchResults').hide(); }
            });
            $(document).on('click', function(e) {
                if (!$(e.target).closest('#globalSearch').length) $('#searchResults').hide();
            });
        });

        // 4. Graphique
        const ctx = document.getElementById('ventesChart').getContext('2d');
        new Chart(ctx, {
            type: 'line',
            data: {
                labels: <?php echo json_encode($labels); ?>,
                datasets: [{
                    label: 'Ventes',
                    data: <?php echo json_encode($donnees); ?>,
                    borderColor: '#2ecc71',
                    backgroundColor: 'rgba(46, 204, 113, 0.1)',
                    fill: true,
                    tension: 0.4,
                    pointRadius: 6,
                    pointHoverRadius: 8,
                    pointBackgroundColor: '#fff',
                    pointBorderColor: '#2ecc71',
                    pointBorderWidth: 2
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                return ' CA : ' + context.parsed.y.toLocaleString() + ' F';
                            }
                        }
                    }
                },
                scales: {
                    y: { beginAtZero: true, grid: { color: '#f0f0f0' } },
                    x: { grid: { display: false } }
                }
            }
        });
    </script>
</body>
</html>