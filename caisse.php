<?php
session_start();
require_once 'db.php';
if (!isset($_SESSION['user_id'])) { header('Location: login.php'); exit(); }

// --- INITIALISATION DES VARIABLES ---
$session = $pdo->query("SELECT * FROM sessions_caisse WHERE statut = 'ouvert' LIMIT 1")->fetch();
$mouvements = [];
$solde_actuel = 0;
$stats_ventes = ['total_ca' => 0, 'nb_tickets' => 0, 'panier_moyen' => 0];
$repartition_paiement = ['cash' => 0, 'momo' => 0, 'carte' => 0];
$marge_session = 0;
$top_produits = [];
$evolution = 0;

if ($session) {
    $date_debut = $session['date_ouverture'];

    // 1. STATS GLOBALES (CA, Tickets, Panier Moyen)
    $stmtStats = $pdo->prepare("SELECT COUNT(id_vente) as nb_tickets, IFNULL(SUM(total), 0) as total_ca, IFNULL(AVG(total), 0) as panier_moyen FROM ventes WHERE date_vente >= ?");
    $stmtStats->execute([$date_debut]);
    $stats_ventes = $stmtStats->fetch();

    // 2. SOLDE THÉORIQUE (Espèces uniquement)
    $stmtSolde = $pdo->prepare("SELECT SUM(CASE WHEN type_mouvement IN ('entree', 'ouverture') THEN montant ELSE 0 END) - SUM(CASE WHEN type_mouvement = 'sortie' THEN montant ELSE 0 END) as solde FROM caisse WHERE date_mouvement >= ?");
    $stmtSolde->execute([$date_debut]);
    $solde_actuel = $stmtSolde->fetch()['solde'] ?? 0;

    // 3. RÉPARTITION PAIEMENTS (Momo, Cash, Carte)
    $stmtPay = $pdo->prepare("SELECT mode_paiement, SUM(total) as total FROM ventes WHERE date_vente >= ? GROUP BY mode_paiement");
    $stmtPay->execute([$date_debut]);
    while($row = $stmtPay->fetch()) {
        $mode = mb_strtolower($row['mode_paiement'], 'UTF-8');
        if($mode == 'espèces' || $mode == 'especes') $repartition_paiement['cash'] = $row['total'];
        if($mode == 'mobile money' || $mode == 'momo') $repartition_paiement['momo'] = $row['total'];
        if($mode == 'carte') $repartition_paiement['carte'] = $row['total'];
    }

    // 4. MOUVEMENTS DE CAISSE (Journal)
    $stmtMouv = $pdo->prepare("SELECT * FROM caisse WHERE date_mouvement >= ? ORDER BY date_mouvement DESC");
    $stmtMouv->execute([$date_debut]);
    $mouvements = $stmtMouv->fetchAll();

    $total_entrees = 0;
    $total_sorties = 0;
foreach($mouvements as $m) {
    if($m['type_mouvement'] == 'entree') $total_entrees += $m['montant'];
    if($m['type_mouvement'] == 'sortie') $total_sorties += $m['montant'];
}

    // 5. MARGE & TOP PRODUITS
    $stmtMarge = $pdo->prepare("SELECT SUM((dv.prix_unitaire - p.prix_achat) * dv.quantite) as marge_totale FROM detail_ventes dv JOIN produits p ON dv.id_produit = p.id_produit JOIN ventes v ON dv.id_vente = v.id_vente WHERE v.date_vente >= ?");
    $stmtMarge->execute([$date_debut]);
    $marge_session = $stmtMarge->fetch()['marge_totale'] ?? 0;

    $stmtTop = $pdo->prepare("SELECT p.nom_commercial, SUM(dv.quantite) as qte FROM detail_ventes dv JOIN produits p ON dv.id_produit = p.id_produit JOIN ventes v ON dv.id_vente = v.id_vente WHERE v.date_vente >= ? GROUP BY p.id_produit ORDER BY qte DESC LIMIT 5");
    $stmtTop->execute([$date_debut]);
    $top_produits = $stmtTop->fetchAll();

    // 6. ÉVOLUTION (Hier vs Aujourd'hui)
    $hier_debut = date('Y-m-d H:i:s', strtotime($date_debut . ' -1 day'));
    $hier_fin = date('Y-m-d H:i:s', strtotime('now -1 day'));
    $stmtHier = $pdo->prepare("SELECT SUM(total) as ca FROM ventes WHERE date_vente BETWEEN ? AND ?");
    $stmtHier->execute([$hier_debut, $hier_fin]);
    $ca_hier = $stmtHier->fetch()['ca'] ?? 0;
    $evolution = ($ca_hier > 0) ? (($stats_ventes['total_ca'] - $ca_hier) / $ca_hier) * 100 : 0;
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>PharmAssist - Pilotage de Caisse</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <style>
        :root { --primary: #2c3e50; --secondary: #27ae60; --warning: #f39c12; --danger: #e74c3c; --info: #3498db; --light: #f4f7f6; }
        body { margin: 0; font-family: 'Segoe UI', sans-serif; background: var(--light); display: flex; }
        .sidebar { width: 250px; background: var(--primary); height: 100vh; color: white; position: fixed; }
        .sidebar-menu { list-style: none; padding: 0; }
        .sidebar-menu a { color: white; text-decoration: none; padding: 15px 20px; display: flex; align-items: center; gap: 12px; }
        .sidebar-menu a.active { background: var(--secondary); }
        .main-content { margin-left: 250px; flex: 1; padding: 30px; }
        .stat-card { background: white; padding: 20px; border-radius: 12px; box-shadow: 0 4px 6px rgba(0,0,0,0.02); border-top: 4px solid var(--info); margin-bottom: 20px; }
        .value { font-size: 24px; font-weight: bold; display: block; margin-top: 10px; }
        .tabs-nav { display: flex; gap: 5px; margin-bottom: 20px; border-bottom: 2px solid #ddd; }
        .tab-btn { padding: 12px 25px; cursor: pointer; border: none; background: #eee; border-radius: 8px 8px 0 0; font-weight: bold; }
        .tab-btn.active { background: var(--primary); color: white; }
        .tab-content { display: none; }
        .tab-content.active { display: block; animation: fadeIn 0.3s; }
        .grid-panel { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 20px; }
        .btn { padding: 12px 20px; border: none; border-radius: 8px; cursor: pointer; font-weight: bold; color: white; display: inline-flex; align-items: center; gap: 8px; }
        @keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }
    </style>
</head>
<body>

    <nav class="sidebar">
        <div style="padding:20px; text-align:center;"><h3>PharmAssist</h3></div>
        <ul class="sidebar-menu">
            <li><a href="dashboard.php"><i class="fas fa-chart-line"></i> Dashboard</a></li>
            <li><a href="ventes.php"><i class="fas fa-shopping-cart"></i> Ventes</a></li>
            <li><a href="caisse.php" class="active"><i class="fas fa-cash-register"></i> Caisse</a></li>
            <li><a href="produits_gestion.php"><i class="fas fa-pills"></i> Stocks</a></li>
        </ul>
    </nav>

    <div class="main-content">
        <div style="display: flex; justify-content: space-between; margin-bottom: 20px;">
            <h2><i class="fas fa-cash-register"></i> Pilotage de Caisse</h2>
            <?php if($session): ?>
                <button class="btn" style="background: var(--primary)" onclick="imprimerTicketSession(<?= $session['id_session'] ?>)">
                    <i class="fas fa-print"></i> Ticket Provisoire
                </button>
            <?php endif; ?>
        </div>

        <?php if (!$session): ?>
            <div class="stat-card" style="text-align:center; padding: 50px;">
                <i class="fas fa-lock" style="font-size: 50px; color: #ddd;"></i>
                <h3>Caisse Fermée</h3>
                <button class="btn" style="background: var(--secondary)" onclick="ouvrirCaisse()">Ouvrir la session</button>
            </div>
        <?php else: ?>

            <div class="tabs-nav">
                <button class="tab-btn active" onclick="openTab(event, 'panel-gestion')">Gestion & Cash</button>
                <button class="tab-btn" onclick="openTab(event, 'panel-ventes')">Stats Ventes</button>
                <button class="tab-btn" onclick="openTab(event, 'panel-produits')">Analyses Produits</button>
            </div>

            <div id="panel-gestion" class="tab-content active">
                <div class="grid-panel">
                    <div class="stat-card" style="border-top-color: var(--warning);">
                        <h4>Solde Théorique (Espèces)</h4>
                        <span class="value" style="color: var(--secondary);"><?= number_format($solde_actuel, 0, '.', ' ') ?> F</span>
                    </div>
                    <div class="stat-card">
                        <h4>Moyens de Paiement</h4>
                        <div style="display:flex; gap:10px; margin-top:10px;">
                            <small><b>Cash:</b> <?= number_format($repartition_paiement['cash'], 0, '.', ' ') ?> F</small>
                            <small><b>Momo:</b> <?= number_format($repartition_paiement['momo'], 0, '.', ' ') ?> F</small>
                        </div>
                    </div>
                </div>

                <div style="margin: 20px 0; display:flex; gap:10px;">
                    <button class="btn" style="background: var(--danger)" onclick="ajouterMouvement('sortie')">Dépense</button>
                    <button class="btn" style="background: var(--secondary)" onclick="ajouterMouvement('entree')">Entrée</button>
                    <button class="btn" style="background: #34495e" onclick="cloturerCaisse(<?= $solde_actuel ?>)">Clôturer la journée</button>
                </div>

                <div class="stat-card">
                    <h4>Journal des mouvements</h4>
                    <table width="100%" style="border-collapse:collapse; margin-top:15px;">
                        <tr style="background:#eee"><th>Heure</th><th>Type</th><th>Motif</th><th style="text-align:right">Montant</th></tr>
                        <?php foreach($mouvements as $m): ?>
                        <tr>
                            <td><?= date('H:i', strtotime($m['date_mouvement'])) ?></td>
                            <td><?= strtoupper($m['type_mouvement']) ?></td>
                            <td><?= htmlspecialchars($m['motif']) ?></td>
                            <td style="text-align:right"><b><?= number_format($m['montant'], 0, '.', ' ') ?> F</b></td>
                        </tr>
                        <?php endforeach; ?>
                    </table>
                </div>
            </div>

            <div id="panel-ventes" class="tab-content">
                <div class="grid-panel">
                    <div class="stat-card" style="border-top-color: var(--secondary);">
                        <h4>CA Session</h4>
                        <span class="value"><?= number_format($stats_ventes['total_ca'], 0, '.', ' ') ?> F</span>
                        <small style="color: <?= $evolution >= 0 ? 'green' : 'red' ?>;">
                            <?= round($evolution, 1) ?>% vs Hier
                        </small>
                    </div>
                    <div class="stat-card">
                        <h4>Panier Moyen</h4>
                        <span class="value"><?= number_format($stats_ventes['panier_moyen'], 0, '.', ' ') ?> F</span>
                        <small><?= $stats_ventes['nb_tickets'] ?> Tickets édités</small>
                    </div>
                    <div class="stat-card" style="border-top-color: #9b59b6;">
                        <h4>Marge estimée</h4>
                        <span class="value"><?= number_format($marge_session, 0, '.', ' ') ?> F</span>
                    </div>
                </div>
            </div>

            <div id="panel-produits" class="tab-content">marge_session
                <div class="grid-panel">
                    <div class="stat-card" style="border-top-color: var(--danger);">
                        <h4><i class="fas fa-exclamation-triangle"></i> Alertes Stock Bas</h4>
                        <?php
                        $alertes = $pdo->query("SELECT p.nom_commercial, s.quantite_disponible FROM produits p JOIN stocks s ON p.id_produit = s.id_produit WHERE s.quantite_disponible <= p.seuil_alerte LIMIT 5")->fetchAll();
                        foreach($alertes as $a): ?>
                            <div style="padding:5px 0; border-bottom:1px solid #f9f9f9; color:var(--danger)">
                                <?= $a['nom_commercial'] ?> (<b><?= $a['quantite_disponible'] ?></b> restants)
                            </div>
                        <?php endforeach; ?>
                    </div>
                    
                    <div class="stat-card">
                        <h4>Top 5 Ventes</h4>
                        <?php foreach($top_produits as $tp): ?>
                            <div style="display:flex; justify-content:space-between; font-size:14px; padding:5px 0;">
                                <span><?= $tp['nom_commercial'] ?></span>
                                <b><?= $tp['qte'] ?> vdus</b>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

        <?php endif; ?>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
        function openTab(evt, tabName) {
            $('.tab-content').removeClass('active');
            $('.tab-btn').removeClass('active');
            $('#' + tabName).addClass('active');
            $(evt.currentTarget).addClass('active');
        }

        function ouvrirCaisse() {
            Swal.fire({
                title: 'Fond de caisse',
                input: 'number',
                showCancelButton: true,
                confirmButtonText: 'Ouvrir'
            }).then((res) => {
                if(res.isConfirmed) {
                    $.post('ajax_caisse.php', { action: 'ouvrir', montant: res.value }, () => location.reload());
                }
            });
        }

        function ajouterMouvement(type) {
            Swal.fire({
                title: type === 'sortie' ? 'Dépense' : 'Entrée',
                html: '<input id="swal-mt" class="swal2-input" placeholder="Montant"><input id="swal-mo" class="swal2-input" placeholder="Motif">',
                preConfirm: () => { return [$('#swal-mt').val(), $('#swal-mo').val()] }
            }).then((res) => {
                if(res.isConfirmed) {
                    $.post('ajax_caisse.php', { action: 'mouvement', type: type, montant: res.value[0], motif: res.value[1] }, () => location.reload());
                }
            });
        }


        function cloturerCaisse(theo) {
    Swal.fire({
        title: 'Clôture de Caisse',
        text: 'Saisissez le montant réel compté dans le tiroir (Espèces) :',
        input: 'number',
        inputAttributes: { min: 0, step: 1 },
        showCancelButton: true,
        confirmButtonText: 'Générer le bilan',
        cancelButtonText: 'Annuler'
    }).then((res) => {
        if (res.isConfirmed) {
            const reel = parseFloat(res.value);
            const ecart = reel - theo;
            const couleurEcart = ecart >= 0 ? '#27ae60' : '#e74c3c';

            // On affiche le récapitulatif avant la validation finale
            Swal.fire({
                title: 'Bilan de Clôture (X-Caisse)',
                html: `
                <div style="text-align: left; font-family: monospace; font-size: 14px; background: #f9f9f9; padding: 15px; border-radius: 8px;">
                    <div style="display: flex; justify-content: space-between;"><span>Fond de caisse :</span> <b>${$('#fond_init').val() || 0} F</b></div>
                    <div style="display: flex; justify-content: space-between; color: #27ae60;"><span>(+) Ventes Espèces :</span> <b><?= number_format($repartition_paiement['cash'], 0, '.', ' ') ?> F</b></div>
                    <div style="display: flex; justify-content: space-between; color: #3498db;"><span>(+) Entrées Diverses :</span> <b><?= number_format(array_sum(array_column(array_filter($mouvements, fn($m) => $m['type_mouvement'] == 'entree'), 'montant')), 0, '.', ' ') ?> F</b></div>
                    <div style="display: flex; justify-content: space-between; color: #e74c3c;"><span>(-) Dépenses/Sorties :</span> <b><?= number_format(array_sum(array_column(array_filter($mouvements, fn($m) => $m['type_mouvement'] == 'sortie'), 'montant')), 0, '.', ' ') ?> F</b></div>
                    <hr>
                    <div style="display: flex; justify-content: space-between; font-weight: bold;"><span>TOTAL THÉORIQUE :</span> <span>${theo} F</span></div>
                    <div style="display: flex; justify-content: space-between; font-weight: bold;"><span>TOTAL RÉEL :</span> <span>${reel} F</span></div>
                    <div style="display: flex; justify-content: space-between; font-weight: bold; color: ${couleurEcart};">
                        <span>ÉCART :</span> <span>${ecart > 0 ? '+' : ''}${ecart} F</span>
                    </div>
                </div>
                <p style="margin-top:15px">Souhaitez-vous valider la fermeture définitive de la caisse ?</p>
                `,
                icon: 'info',
                showCancelButton: true,
                confirmButtonText: 'Oui, Clôturer',
                cancelButtonText: 'Rectifier',
                confirmButtonColor: '#34495e'
            }).then((confirm) => {
                if (confirm.isConfirmed) {
                    $.post('ajax_caisse.php', { 
                        action: 'cloturer', 
                        reel: reel, 
                        theorique: theo,
                        ecart: ecart 
                    }, () => {
                        Swal.fire('Succès', 'Caisse clôturée avec succès', 'success').then(() => location.reload());
                    });
                }
            });
        }
    });
}
    </script>
</body>
</html>