<?php
session_start();
require_once 'db.php';
if (!isset($_SESSION['user_id'])) { header('Location: login.php'); exit(); }

$session = $pdo->query("SELECT * FROM sessions_caisse WHERE statut = 'ouvert' LIMIT 1")->fetch();
$mouvements = [];
$solde_actuel = 0;
$stats_ventes = ['total_ca' => 0, 'nb_tickets' => 0, 'panier_moyen' => 0];
$repartition_paiement = ['cash' => 0, 'momo' => 0, 'carte' => 0];
$marge_session = 0;
$top_produits = [];
$evolution = 0;
$total_entrees = 0;
$total_sorties = 0;

if ($session) {
    $date_debut = $session['date_ouverture'];
    $stmtStats = $pdo->prepare("SELECT COUNT(id_vente) as nb_tickets, IFNULL(SUM(total), 0) as total_ca, IFNULL(AVG(total), 0) as panier_moyen FROM ventes WHERE date_vente >= ?");
    $stmtStats->execute([$date_debut]);
    $stats_ventes = $stmtStats->fetch();
    $stmtSolde = $pdo->prepare("SELECT SUM(CASE WHEN type_mouvement IN ('entree', 'ouverture') THEN montant ELSE 0 END) - SUM(CASE WHEN type_mouvement = 'sortie' THEN montant ELSE 0 END) as solde FROM caisse WHERE date_mouvement >= ?");
    $stmtSolde->execute([$date_debut]);
    $solde_actuel = $stmtSolde->fetch()['solde'] ?? 0;
    $stmtPay = $pdo->prepare("SELECT mode_paiement, SUM(total) as total FROM ventes WHERE date_vente >= ? GROUP BY mode_paiement");
    $stmtPay->execute([$date_debut]);
    while($row = $stmtPay->fetch()) {
        $mode = mb_strtolower($row['mode_paiement'], 'UTF-8');
        if($mode == 'espèces' || $mode == 'especes') $repartition_paiement['cash'] = $row['total'];
        if($mode == 'mobile money' || $mode == 'momo') $repartition_paiement['momo'] = $row['total'];
        if($mode == 'carte') $repartition_paiement['carte'] = $row['total'];
    }
    $stmtMouv = $pdo->prepare("SELECT * FROM caisse WHERE date_mouvement >= ? ORDER BY date_mouvement DESC");
    $stmtMouv->execute([$date_debut]);
    $mouvements = $stmtMouv->fetchAll();
    foreach($mouvements as $m) {
        if($m['type_mouvement'] == 'entree') $total_entrees += $m['montant'];
        if($m['type_mouvement'] == 'sortie') $total_sorties += $m['montant'];
    }
    $stmtMarge = $pdo->prepare("SELECT SUM((dv.prix_unitaire - p.prix_achat) * dv.quantite) as marge_totale FROM detail_ventes dv JOIN produits p ON dv.id_produit = p.id_produit JOIN ventes v ON dv.id_vente = v.id_vente WHERE v.date_vente >= ?");
    $stmtMarge->execute([$date_debut]);
    $marge_session = $stmtMarge->fetch()['marge_totale'] ?? 0;
    $stmtTop = $pdo->prepare("SELECT p.nom_commercial, SUM(dv.quantite) as qte FROM detail_ventes dv JOIN produits p ON dv.id_produit = p.id_produit JOIN ventes v ON dv.id_vente = v.id_vente WHERE v.date_vente >= ? GROUP BY p.id_produit ORDER BY qte DESC LIMIT 5");
    $stmtTop->execute([$date_debut]);
    $top_produits = $stmtTop->fetchAll();
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
    <title>PharmAssist - Caisse</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <style>
        :root {
            --bg: #f0f2f5;
            --sidebar-bg: #111827;
            --sidebar-hover: #1f2937;
            --sidebar-active: #065f46;
            --sidebar-active-border: #10b981;
            --card-bg: #ffffff;
            --card-border: #e5e7eb;
            --text-primary: #111827;
            --text-secondary: #6b7280;
            --text-muted: #9ca3af;
            --green: #10b981;
            --green-light: #d1fae5;
            --blue: #3b82f6;
            --blue-light: #dbeafe;
            --amber: #f59e0b;
            --amber-light: #fef3c7;
            --red: #ef4444;
            --red-light: #fee2e2;
            --purple: #8b5cf6;
            --purple-light: #ede9fe;
            --shadow-sm: 0 1px 3px rgba(0,0,0,0.06), 0 1px 2px rgba(0,0,0,0.04);
            --shadow: 0 4px 12px rgba(0,0,0,0.06);
            --radius: 8px;
            --sidebar-w: 220px;
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: 'DM Sans', sans-serif;
            font-size: 13px;
            background: var(--bg);
            color: var(--text-primary);
            display: flex;
            min-height: 100vh;
        }

        /* ── SIDEBAR ── */
        .sidebar {
            width: var(--sidebar-w);
            background: var(--sidebar-bg);
            height: 100vh;
            position: fixed;
            top: 0; left: 0;
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }

        .sidebar-logo {
            padding: 18px 20px 14px;
            border-bottom: 1px solid rgba(255,255,255,0.06);
        }

        .sidebar-logo .brand {
            display: flex;
            align-items: center;
            gap: 9px;
        }

        .sidebar-logo .brand-icon {
            width: 28px; height: 28px;
            background: var(--green);
            border-radius: 6px;
            display: flex; align-items: center; justify-content: center;
            color: white;
            font-size: 12px;
        }

        .sidebar-logo .brand-name {
            font-size: 13px;
            font-weight: 600;
            color: #f9fafb;
            letter-spacing: 0.02em;
        }

        .sidebar-logo .brand-sub {
            font-size: 10px;
            color: #6b7280;
            margin-top: 1px;
        }

        .sidebar-section-label {
            font-size: 9px;
            font-weight: 600;
            letter-spacing: 0.1em;
            text-transform: uppercase;
            color: #4b5563;
            padding: 14px 20px 6px;
        }

        .sidebar-menu {
            list-style: none;
            padding: 0 10px;
        }

        .sidebar-menu li { margin-bottom: 2px; }

        .sidebar-menu a {
            color: #9ca3af;
            text-decoration: none;
            padding: 8px 12px;
            border-radius: 6px;
            display: flex;
            align-items: center;
            gap: 9px;
            font-size: 12.5px;
            font-weight: 400;
            transition: all 0.15s ease;
        }

        .sidebar-menu a:hover {
            background: var(--sidebar-hover);
            color: #e5e7eb;
        }

        .sidebar-menu a.active {
            background: var(--sidebar-active);
            color: #ecfdf5;
            font-weight: 500;
            border-left: 2px solid var(--sidebar-active-border);
            padding-left: 10px;
        }

        .sidebar-menu a i {
            width: 14px;
            font-size: 12px;
            text-align: center;
            opacity: 0.8;
        }

        .sidebar-footer {
            margin-top: auto;
            padding: 14px 20px;
            border-top: 1px solid rgba(255,255,255,0.06);
        }

        .sidebar-footer .user-info {
            display: flex;
            align-items: center;
            gap: 9px;
        }

        .sidebar-footer .avatar {
            width: 26px; height: 26px;
            background: #374151;
            border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            font-size: 10px;
            color: #9ca3af;
        }

        .sidebar-footer .user-name {
            font-size: 11px;
            font-weight: 500;
            color: #e5e7eb;
        }

        .sidebar-footer .user-role {
            font-size: 10px;
            color: #6b7280;
        }

        /* ── MAIN ── */
        .main-content {
            margin-left: var(--sidebar-w);
            flex: 1;
            display: flex;
            flex-direction: column;
            min-height: 100vh;
        }

        /* ── TOP BAR ── */
        .topbar {
            background: var(--card-bg);
            border-bottom: 1px solid var(--card-border);
            padding: 0 24px;
            height: 52px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            position: sticky;
            top: 0;
            z-index: 10;
        }

        .topbar-left {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .topbar-title {
            font-size: 13px;
            font-weight: 600;
            color: var(--text-primary);
        }

        .topbar-breadcrumb {
            font-size: 11px;
            color: var(--text-muted);
        }

        .topbar-divider {
            width: 1px; height: 16px;
            background: var(--card-border);
        }

        .topbar-right {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        /* ── SESSION BADGE ── */
        .session-badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 3px 9px;
            border-radius: 20px;
            font-size: 10.5px;
            font-weight: 500;
        }

        .session-badge.open {
            background: var(--green-light);
            color: #065f46;
        }

        .session-badge.closed {
            background: #f3f4f6;
            color: var(--text-secondary);
        }

        .session-badge .dot {
            width: 5px; height: 5px;
            border-radius: 50%;
            background: currentColor;
            animation: pulse 2s infinite;
        }

        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.4; }
        }

        /* ── PAGE CONTENT ── */
        .page-body {
            padding: 20px 24px;
            flex: 1;
        }

        /* ── BUTTONS ── */
        .btn {
            padding: 7px 14px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-family: 'DM Sans', sans-serif;
            font-size: 12px;
            font-weight: 500;
            color: white;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: all 0.15s ease;
            letter-spacing: 0.01em;
        }

        .btn:hover { filter: brightness(0.92); transform: translateY(-1px); }
        .btn:active { transform: translateY(0); }
        .btn-sm { padding: 5px 10px; font-size: 11px; }
        .btn-green { background: var(--green); }
        .btn-blue { background: var(--blue); }
        .btn-red { background: var(--red); }
        .btn-dark { background: #374151; }
        .btn-navy { background: var(--sidebar-bg); }

        /* ── TABS ── */
        .tabs-nav {
            display: flex;
            gap: 2px;
            margin-bottom: 16px;
            background: #e5e7eb;
            padding: 3px;
            border-radius: 8px;
            width: fit-content;
        }

        .tab-btn {
            padding: 6px 16px;
            cursor: pointer;
            border: none;
            background: transparent;
            border-radius: 6px;
            font-family: 'DM Sans', sans-serif;
            font-size: 12px;
            font-weight: 500;
            color: var(--text-secondary);
            transition: all 0.15s ease;
        }

        .tab-btn.active {
            background: white;
            color: var(--text-primary);
            box-shadow: var(--shadow-sm);
        }

        .tab-content { display: none; }
        .tab-content.active { display: block; animation: fadeIn 0.2s ease; }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(4px); }
            to { opacity: 1; transform: translateY(0); }
        }

        /* ── GRID ── */
        .grid-panel {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 14px;
            margin-bottom: 16px;
        }

        /* ── STAT CARDS ── */
        .stat-card {
            background: var(--card-bg);
            border-radius: var(--radius);
            border: 1px solid var(--card-border);
            padding: 14px 16px;
            box-shadow: var(--shadow-sm);
            position: relative;
            overflow: hidden;
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: 0; left: 0;
            width: 3px;
            height: 100%;
            background: var(--accent-color, var(--blue));
            border-radius: 4px 0 0 4px;
        }

        .stat-card .card-label {
            font-size: 10.5px;
            font-weight: 500;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.05em;
            margin-bottom: 6px;
        }

        .stat-card .card-value {
            font-family: 'DM Mono', monospace;
            font-size: 20px;
            font-weight: 500;
            color: var(--text-primary);
            line-height: 1;
            margin-bottom: 6px;
        }

        .stat-card .card-sub {
            font-size: 10.5px;
            color: var(--text-secondary);
        }

        .stat-card .card-icon {
            position: absolute;
            top: 14px; right: 14px;
            width: 30px; height: 30px;
            border-radius: 6px;
            display: flex; align-items: center; justify-content: center;
            font-size: 12px;
        }

        /* Color variants */
        .card-green  { --accent-color: var(--green); }
        .card-blue   { --accent-color: var(--blue); }
        .card-amber  { --accent-color: var(--amber); }
        .card-red    { --accent-color: var(--red); }
        .card-purple { --accent-color: var(--purple); }

        .icon-green  { background: var(--green-light); color: var(--green); }
        .icon-blue   { background: var(--blue-light); color: var(--blue); }
        .icon-amber  { background: var(--amber-light); color: var(--amber); }
        .icon-red    { background: var(--red-light); color: var(--red); }
        .icon-purple { background: var(--purple-light); color: var(--purple); }

        /* ── PANEL (content card) ── */
        .panel {
            background: var(--card-bg);
            border-radius: var(--radius);
            border: 1px solid var(--card-border);
            box-shadow: var(--shadow-sm);
            overflow: hidden;
            margin-bottom: 16px;
        }

        .panel-header {
            padding: 12px 16px;
            border-bottom: 1px solid var(--card-border);
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .panel-title {
            font-size: 12px;
            font-weight: 600;
            color: var(--text-primary);
            display: flex;
            align-items: center;
            gap: 7px;
        }

        .panel-title i {
            font-size: 11px;
            color: var(--text-muted);
        }

        .panel-body { padding: 16px; }

        /* ── TABLE ── */
        .data-table {
            width: 100%;
            border-collapse: collapse;
        }

        .data-table thead tr {
            border-bottom: 1px solid var(--card-border);
        }

        .data-table thead th {
            padding: 7px 12px;
            font-size: 10.5px;
            font-weight: 600;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.05em;
            text-align: left;
            background: #f9fafb;
        }

        .data-table thead th:last-child { text-align: right; }

        .data-table tbody tr {
            border-bottom: 1px solid #f3f4f6;
            transition: background 0.1s;
        }

        .data-table tbody tr:last-child { border-bottom: none; }
        .data-table tbody tr:hover { background: #f9fafb; }

        .data-table tbody td {
            padding: 8px 12px;
            font-size: 12px;
            color: var(--text-primary);
        }

        .data-table tbody td:last-child {
            text-align: right;
            font-family: 'DM Mono', monospace;
            font-size: 11.5px;
        }

        /* Type badge */
        .type-badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 2px 7px;
            border-radius: 4px;
            font-size: 10px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }

        .badge-entree  { background: var(--green-light); color: #065f46; }
        .badge-sortie  { background: var(--red-light); color: #991b1b; }
        .badge-ouverture { background: var(--blue-light); color: #1e40af; }

        /* ── ACTION BAR ── */
        .action-bar {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 16px;
            flex-wrap: wrap;
        }

        .action-bar .sep {
            width: 1px; height: 22px;
            background: var(--card-border);
            margin: 0 2px;
        }

        /* ── PAYMENT PILLS ── */
        .pay-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 8px;
            margin-top: 8px;
        }

        .pay-pill {
            background: #f9fafb;
            border: 1px solid var(--card-border);
            border-radius: 6px;
            padding: 8px 10px;
            text-align: center;
        }

        .pay-pill .pay-label {
            font-size: 9.5px;
            font-weight: 600;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.06em;
            margin-bottom: 3px;
        }

        .pay-pill .pay-value {
            font-family: 'DM Mono', monospace;
            font-size: 12.5px;
            font-weight: 500;
            color: var(--text-primary);
        }

        /* ── TOP LIST ── */
        .top-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 7px 0;
            border-bottom: 1px solid #f3f4f6;
        }

        .top-item:last-child { border-bottom: none; }

        .top-item .rank {
            width: 18px;
            font-size: 10px;
            font-weight: 700;
            color: var(--text-muted);
            font-family: 'DM Mono', monospace;
        }

        .top-item .name {
            flex: 1;
            font-size: 12px;
            color: var(--text-primary);
            padding: 0 8px;
        }

        .top-item .qty {
            font-family: 'DM Mono', monospace;
            font-size: 11px;
            font-weight: 500;
            background: #f3f4f6;
            padding: 2px 7px;
            border-radius: 4px;
            color: var(--text-secondary);
        }

        /* ── ALERT LIST ── */
        .alert-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 7px 0;
            border-bottom: 1px solid #f3f4f6;
        }

        .alert-item:last-child { border-bottom: none; }

        .alert-item .alert-name {
            font-size: 12px;
            color: var(--text-primary);
        }

        .alert-item .alert-qty {
            font-family: 'DM Mono', monospace;
            font-size: 11px;
            font-weight: 500;
            background: var(--red-light);
            color: var(--red);
            padding: 2px 7px;
            border-radius: 4px;
        }

        /* ── BADGE EVOLUTION ── */
        .evo-badge {
            display: inline-flex;
            align-items: center;
            gap: 3px;
            padding: 2px 7px;
            border-radius: 4px;
            font-size: 10.5px;
            font-weight: 600;
        }

        .evo-up   { background: var(--green-light); color: #065f46; }
        .evo-down { background: var(--red-light); color: #991b1b; }

        /* ── CAISSE FERMEE ── */
        .closed-state {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 60px 20px;
            background: var(--card-bg);
            border-radius: var(--radius);
            border: 1px solid var(--card-border);
            gap: 12px;
        }

        .closed-state .closed-icon {
            width: 52px; height: 52px;
            background: #f3f4f6;
            border-radius: 12px;
            display: flex; align-items: center; justify-content: center;
            color: var(--text-muted);
            font-size: 20px;
        }

        .closed-state h3 {
            font-size: 14px;
            font-weight: 600;
            color: var(--text-primary);
        }

        .closed-state p {
            font-size: 12px;
            color: var(--text-secondary);
        }

        /* ── TOTALS ROW ── */
        .totals-row {
            display: flex;
            gap: 10px;
            padding: 10px 12px;
            background: #f9fafb;
            border-top: 1px solid var(--card-border);
            border-radius: 0 0 var(--radius) var(--radius);
        }

        .total-item {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 11px;
        }

        .total-item .total-label { color: var(--text-muted); }
        .total-item .total-val {
            font-family: 'DM Mono', monospace;
            font-weight: 500;
        }

        .total-item.t-green .total-val { color: var(--green); }
        .total-item.t-red .total-val { color: var(--red); }

        /* scrollbar */
        ::-webkit-scrollbar { width: 5px; height: 5px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: #d1d5db; border-radius: 10px; }
    </style>
</head>
<body>

<!-- ════ SIDEBAR ════ -->
<nav class="sidebar">
    <div class="sidebar-logo">
        <div class="brand">
            <div class="brand-icon"><i class="fas fa-plus"></i></div>
            <div>
                <div class="brand-name">PharmAssist</div>
                <div class="brand-sub">Gestion Officine</div>
            </div>
        </div>
    </div>

    <div class="sidebar-section-label">Navigation</div>
    <ul class="sidebar-menu">
        <li><a href="dashboard.php"><i class="fas fa-chart-line"></i> Dashboard</a></li>
        <li><a href="ventes.php"><i class="fas fa-shopping-cart"></i> Ventes</a></li>
        <li><a href="caisse.php" class="active"><i class="fas fa-cash-register"></i> Caisse</a></li>
        <li><a href="produits_gestion.php"><i class="fas fa-pills"></i> Stocks</a></li>
    </ul>

    <div class="sidebar-footer">
        <div class="user-info">
            <div class="avatar"><i class="fas fa-user"></i></div>
            <div>
                <div class="user-name">Pharmacien</div>
                <div class="user-role">Administrateur</div>
            </div>
        </div>
    </div>
</nav>

<!-- ════ MAIN ════ -->
<div class="main-content">

    <!-- TOP BAR -->
    <div class="topbar">
        <div class="topbar-left">
            <span class="topbar-breadcrumb">PharmAssist</span>
            <span style="margin: 0 6px; color: #d1d5db;">/</span>
            <span class="topbar-title">Pilotage de Caisse</span>
            <div class="topbar-divider" style="margin-left:10px;"></div>
            <?php if($session): ?>
                <span class="session-badge open">
                    <span class="dot"></span>
                    Session ouverte &mdash; <?= date('d/m H:i', strtotime($session['date_ouverture'])) ?>
                </span>
            <?php else: ?>
                <span class="session-badge closed">Caisse fermee</span>
            <?php endif; ?>
        </div>
        <div class="topbar-right">
            <?php if($session): ?>
                <button class="btn btn-dark" onclick="imprimerTicketSession(<?= $session['id_session'] ?>)">
                    <i class="fas fa-print"></i> Ticket provisoire
                </button>
            <?php endif; ?>
        </div>
    </div>

    <!-- PAGE BODY -->
    <div class="page-body">

        <?php if (!$session): ?>
        <!-- CAISSE FERMEE -->
        <div class="closed-state">
            <div class="closed-icon"><i class="fas fa-lock"></i></div>
            <h3>Caisse fermee</h3>
            <p>Aucune session active. Ouvrez la caisse pour commencer.</p>
            <button class="btn btn-green" onclick="ouvrirCaisse()">
                <i class="fas fa-unlock-alt"></i> Ouvrir la session
            </button>
        </div>

        <?php else: ?>

        <!-- TABS -->
        <div class="tabs-nav">
            <button class="tab-btn active" onclick="openTab(event, 'panel-gestion')">Gestion &amp; Cash</button>
            <button class="tab-btn" onclick="openTab(event, 'panel-ventes')">Stats Ventes</button>
            <button class="tab-btn" onclick="openTab(event, 'panel-produits')">Analyses</button>
        </div>

        <!-- ══ TAB 1 : GESTION & CASH ══ -->
        <div id="panel-gestion" class="tab-content active">

            <!-- STAT CARDS ROW -->
            <div class="grid-panel">
                <div class="stat-card card-amber">
                    <div class="card-icon icon-amber"><i class="fas fa-coins"></i></div>
                    <div class="card-label">Solde theorique (especes)</div>
                    <div class="card-value"><?= number_format($solde_actuel, 0, '.', ' ') ?> F</div>
                    <div class="card-sub">Fond + ventes cash - depenses</div>
                </div>
                <div class="stat-card card-blue">
                    <div class="card-icon icon-blue"><i class="fas fa-credit-card"></i></div>
                    <div class="card-label">Moyens de paiement</div>
                    <div class="pay-grid">
                        <div class="pay-pill">
                            <div class="pay-label">Cash</div>
                            <div class="pay-value"><?= number_format($repartition_paiement['cash'], 0, '.', ' ') ?> F</div>
                        </div>
                        <div class="pay-pill">
                            <div class="pay-label">Momo</div>
                            <div class="pay-value"><?= number_format($repartition_paiement['momo'], 0, '.', ' ') ?> F</div>
                        </div>
                        <div class="pay-pill">
                            <div class="pay-label">Carte</div>
                            <div class="pay-value"><?= number_format($repartition_paiement['carte'], 0, '.', ' ') ?> F</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ACTION BAR -->
            <div class="action-bar">
                <button class="btn btn-red" onclick="ajouterMouvement('sortie')">
                    <i class="fas fa-arrow-up"></i> Depense
                </button>
                <button class="btn btn-green" onclick="ajouterMouvement('entree')">
                    <i class="fas fa-arrow-down"></i> Entree
                </button>
                <div class="sep"></div>
                <button class="btn btn-navy" onclick="cloturerCaisse(<?= $solde_actuel ?>)">
                    <i class="fas fa-power-off"></i> Cloturer la journee
                </button>
            </div>

            <!-- JOURNAL TABLE -->
            <div class="panel">
                <div class="panel-header">
                    <div class="panel-title">
                        <i class="fas fa-list-ul"></i>
                        Journal des mouvements
                    </div>
                    <span style="font-size:11px; color:var(--text-muted)"><?= count($mouvements) ?> operations</span>
                </div>
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Heure</th>
                            <th>Type</th>
                            <th>Motif</th>
                            <th>Montant</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if(empty($mouvements)): ?>
                        <tr>
                            <td colspan="4" style="text-align:center; color:var(--text-muted); padding:20px 12px; font-size:11.5px;">
                                Aucun mouvement pour cette session
                            </td>
                        </tr>
                        <?php endif; ?>
                        <?php foreach($mouvements as $m): ?>
                        <tr>
                            <td>
                                <span style="font-family:'DM Mono',monospace; font-size:11.5px; color:var(--text-secondary);">
                                    <?= date('H:i', strtotime($m['date_mouvement'])) ?>
                                </span>
                            </td>
                            <td>
                                <?php
                                $t = $m['type_mouvement'];
                                $cls = ($t == 'entree') ? 'badge-entree' : (($t == 'sortie') ? 'badge-sortie' : 'badge-ouverture');
                                ?>
                                <span class="type-badge <?= $cls ?>"><?= strtoupper($t) ?></span>
                            </td>
                            <td style="color:var(--text-secondary);"><?= htmlspecialchars($m['motif'] ?: '—') ?></td>
                            <td>
                                <span style="color: <?= ($m['type_mouvement'] == 'sortie') ? 'var(--red)' : 'var(--green)' ?>; font-weight:500;">
                                    <?= ($m['type_mouvement'] == 'sortie' ? '-' : '+') . number_format($m['montant'], 0, '.', ' ') ?> F
                                </span>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <div class="totals-row">
                    <div class="total-item t-green">
                        <span class="total-label">Total entrees :</span>
                        <span class="total-val">+<?= number_format($total_entrees, 0, '.', ' ') ?> F</span>
                    </div>
                    <div style="width:1px;background:var(--card-border);margin:0 4px;"></div>
                    <div class="total-item t-red">
                        <span class="total-label">Total sorties :</span>
                        <span class="total-val">-<?= number_format($total_sorties, 0, '.', ' ') ?> F</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- ══ TAB 2 : STATS VENTES ══ -->
        <div id="panel-ventes" class="tab-content">
            <div class="grid-panel">
                <div class="stat-card card-green">
                    <div class="card-icon icon-green"><i class="fas fa-chart-bar"></i></div>
                    <div class="card-label">CA Session</div>
                    <div class="card-value"><?= number_format($stats_ventes['total_ca'], 0, '.', ' ') ?> F</div>
                    <div class="card-sub">
                        <span class="evo-badge <?= $evolution >= 0 ? 'evo-up' : 'evo-down' ?>">
                            <i class="fas fa-arrow-<?= $evolution >= 0 ? 'up' : 'down' ?>"></i>
                            <?= round(abs($evolution), 1) ?>% vs hier
                        </span>
                    </div>
                </div>
                <div class="stat-card card-blue">
                    <div class="card-icon icon-blue"><i class="fas fa-shopping-basket"></i></div>
                    <div class="card-label">Panier moyen</div>
                    <div class="card-value"><?= number_format($stats_ventes['panier_moyen'], 0, '.', ' ') ?> F</div>
                    <div class="card-sub"><?= $stats_ventes['nb_tickets'] ?> tickets edites</div>
                </div>
                <div class="stat-card card-purple">
                    <div class="card-icon icon-purple"><i class="fas fa-percentage"></i></div>
                    <div class="card-label">Marge estimee</div>
                    <div class="card-value"><?= number_format($marge_session, 0, '.', ' ') ?> F</div>
                    <div class="card-sub">
                        <?php $taux = ($stats_ventes['total_ca'] > 0) ? round(($marge_session / $stats_ventes['total_ca']) * 100, 1) : 0; ?>
                        Taux : <?= $taux ?>%
                    </div>
                </div>
            </div>
        </div>

        <!-- ══ TAB 3 : ANALYSES PRODUITS ══ -->
        <div id="panel-produits" class="tab-content">
            <div class="grid-panel" style="grid-template-columns: 1fr 1fr;">

                <!-- Alertes stock -->
                <div class="panel">
                    <div class="panel-header">
                        <div class="panel-title" style="color: var(--red);">
                            <i class="fas fa-exclamation-triangle" style="color:var(--red);"></i>
                            Alertes Stock Bas
                        </div>
                    </div>
                    <div class="panel-body" style="padding: 8px 16px;">
                        <?php
                        $alertes = $pdo->query("SELECT p.nom_commercial, s.quantite_disponible FROM produits p JOIN stocks s ON p.id_produit = s.id_produit WHERE s.quantite_disponible <= p.seuil_alerte LIMIT 5")->fetchAll();
                        if(empty($alertes)): ?>
                            <p style="font-size:11.5px; color:var(--text-muted); text-align:center; padding:16px 0;">
                                Aucune alerte stock
                            </p>
                        <?php endif; ?>
                        <?php foreach($alertes as $a): ?>
                        <div class="alert-item">
                            <span class="alert-name"><?= htmlspecialchars($a['nom_commercial']) ?></span>
                            <span class="alert-qty"><?= $a['quantite_disponible'] ?> restants</span>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Top 5 ventes -->
                <div class="panel">
                    <div class="panel-header">
                        <div class="panel-title">
                            <i class="fas fa-trophy"></i>
                            Top 5 Ventes
                        </div>
                        <span style="font-size:10.5px; color:var(--text-muted)">cette session</span>
                    </div>
                    <div class="panel-body" style="padding: 8px 16px;">
                        <?php if(empty($top_produits)): ?>
                            <p style="font-size:11.5px; color:var(--text-muted); text-align:center; padding:16px 0;">
                                Aucune vente pour cette session
                            </p>
                        <?php endif; ?>
                        <?php foreach($top_produits as $i => $tp): ?>
                        <div class="top-item">
                            <span class="rank"><?= str_pad($i+1, 2, '0', STR_PAD_LEFT) ?></span>
                            <span class="name"><?= htmlspecialchars($tp['nom_commercial']) ?></span>
                            <span class="qty"><?= $tp['qte'] ?> vdus</span>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>

            </div>
        </div>

        <?php endif; ?>
    </div><!-- /page-body -->
</div><!-- /main-content -->

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
            title: 'Ouverture de caisse',
            text: 'Saisissez le fond de caisse initial (especes) :',
            input: 'number',
            inputAttributes: { min: 0, placeholder: '0' },
            showCancelButton: true,
            confirmButtonText: 'Ouvrir la session',
            cancelButtonText: 'Annuler',
            confirmButtonColor: '#10b981'
        }).then((res) => {
            if(res.isConfirmed) {
                $.post('ajax_caisse.php', { action: 'ouvrir', montant: res.value }, () => location.reload());
            }
        });
    }

    function ajouterMouvement(type) {
        const isOut = type === 'sortie';
        Swal.fire({
            title: isOut ? 'Enregistrer une depense' : 'Enregistrer une entree',
            html: `
                <input id="swal-mt" class="swal2-input" type="number" placeholder="Montant (F)" min="0">
                <input id="swal-mo" class="swal2-input" placeholder="Motif / description">
            `,
            showCancelButton: true,
            confirmButtonText: isOut ? 'Enregistrer la depense' : 'Enregistrer l\'entree',
            cancelButtonText: 'Annuler',
            confirmButtonColor: isOut ? '#ef4444' : '#10b981',
            preConfirm: () => {
                const mt = $('#swal-mt').val();
                const mo = $('#swal-mo').val();
                if(!mt || mt <= 0) { Swal.showValidationMessage('Montant invalide'); return false; }
                return [mt, mo];
            }
        }).then((res) => {
            if(res.isConfirmed) {
                $.post('ajax_caisse.php', { action: 'mouvement', type: type, montant: res.value[0], motif: res.value[1] }, () => location.reload());
            }
        });
    }

    function cloturerCaisse(theo) {
        Swal.fire({
            title: 'Cloture de Caisse',
            text: 'Saisissez le montant reel compte dans le tiroir (especes) :',
            input: 'number',
            inputAttributes: { min: 0, step: 1 },
            showCancelButton: true,
            confirmButtonText: 'Generer le bilan',
            cancelButtonText: 'Annuler',
            confirmButtonColor: '#374151'
        }).then((res) => {
            if (res.isConfirmed) {
                const reel = parseFloat(res.value);
                const ecart = reel - theo;
                const couleurEcart = ecart >= 0 ? '#10b981' : '#ef4444';
                const ecartLabel = ecart >= 0 ? 'Excedent' : 'Deficit';

                Swal.fire({
                    title: 'Bilan de Cloture',
                    html: `
                    <div style="text-align:left; font-family:'DM Sans',sans-serif; font-size:12px; background:#f9fafb; padding:14px 16px; border-radius:8px; border:1px solid #e5e7eb;">
                        <div style="display:flex; justify-content:space-between; padding:5px 0; border-bottom:1px solid #f3f4f6;">
                            <span style="color:#6b7280;">Ventes especes</span>
                            <b style="font-family:'DM Mono',monospace; color:#10b981;"><?= number_format($repartition_paiement['cash'], 0, '.', ' ') ?> F</b>
                        </div>
                        <div style="display:flex; justify-content:space-between; padding:5px 0; border-bottom:1px solid #f3f4f6;">
                            <span style="color:#6b7280;">(+) Entrees diverses</span>
                            <b style="font-family:'DM Mono',monospace; color:#3b82f6;"><?= number_format($total_entrees, 0, '.', ' ') ?> F</b>
                        </div>
                        <div style="display:flex; justify-content:space-between; padding:5px 0; border-bottom:1px solid #e5e7eb;">
                            <span style="color:#6b7280;">(-) Depenses/Sorties</span>
                            <b style="font-family:'DM Mono',monospace; color:#ef4444;"><?= number_format($total_sorties, 0, '.', ' ') ?> F</b>
                        </div>
                        <div style="display:flex; justify-content:space-between; padding:8px 0 5px; font-weight:600;">
                            <span>Total theorique</span>
                            <span style="font-family:'DM Mono',monospace;">${theo} F</span>
                        </div>
                        <div style="display:flex; justify-content:space-between; padding:5px 0; font-weight:600;">
                            <span>Total reel compte</span>
                            <span style="font-family:'DM Mono',monospace;">${reel} F</span>
                        </div>
                        <div style="display:flex; justify-content:space-between; padding:8px 10px; margin-top:8px; background:${ecart >= 0 ? '#d1fae5' : '#fee2e2'}; border-radius:6px; font-weight:700;">
                            <span style="color:${couleurEcart};">${ecartLabel}</span>
                            <span style="font-family:'DM Mono',monospace; color:${couleurEcart};">${ecart > 0 ? '+' : ''}${ecart} F</span>
                        </div>
                    </div>
                    <p style="margin-top:12px; font-size:12px; color:#6b7280;">Confirmer la fermeture definitive de la caisse ?</p>
                    `,
                    icon: 'info',
                    showCancelButton: true,
                    confirmButtonText: 'Oui, Cloturer',
                    cancelButtonText: 'Rectifier',
                    confirmButtonColor: '#111827'
                }).then((confirm) => {
                    if (confirm.isConfirmed) {
                        $.post('ajax_caisse.php', {
                            action: 'cloturer',
                            reel: reel,
                            theorique: theo,
                            ecart: ecart
                        }, () => {
                            Swal.fire({
                                title: 'Caisse cloturee',
                                text: 'La session a ete fermee avec succes.',
                                icon: 'success',
                                confirmButtonColor: '#10b981'
                            }).then(() => location.reload());
                        });
                    }
                });
            }
        });
    }

    // ════════════════════════════════════════════════════════════════════
//  imprimerTicketSession — Rapport complet d'une session de caisse
// ════════════════════════════════════════════════════════════════════
function imprimerTicketSession(idSession) {
    const printWin = window.open('', '_blank', 'width=860,height=900');

    // Affichage chargement pendant la requete AJAX
    printWin.document.write(`
        <!DOCTYPE html><html><head><title>Chargement...</title>
        <style>
            body { display:flex; align-items:center; justify-content:center;
                   height:100vh; margin:0; font-family:monospace;
                   background:#f0f0f0; color:#333; font-size:18px; }
        </style></head>
        <body>Preparation du rapport en cours...</body></html>
    `);

    $.ajax({
        url: 'get_session_ticket.php',
        type: 'POST',
        data: { id_session: idSession },
        dataType: 'json',
        success: function(res) {
            if (!res.success) {
                printWin.document.write('<p style="color:red;padding:20px;">' + res.message + '</p>');
                return;
            }
            printWin.document.open();
            printWin.document.write(res.html);
            printWin.document.close();

            // Lancer l'impression automatiquement apres chargement
            printWin.onload = function() {
                setTimeout(function() {
                    printWin.focus();
                    printWin.print();
                }, 400);
            };
        },
        error: function() {
            printWin.document.write('<p style="color:red;padding:20px;">Erreur de connexion au serveur.</p>');
        }
    });
}
</script>
</body>
</html>