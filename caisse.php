<?php
session_start();
require_once 'db.php';
if (!isset($_SESSION['user_id'])) { header('Location: login.php'); exit(); }

// ═══════════════════════════════════════════════════════
//  DONNÉES DE SESSION
// ═══════════════════════════════════════════════════════
$session = $pdo->query("SELECT sc.*, u.nom_complet FROM sessions_caisse sc LEFT JOIN utilisateurs u ON sc.id_utilisateur = u.id_user WHERE sc.statut = 'ouvert' LIMIT 1")->fetch();

$mouvements         = [];
$solde_actuel       = 0;
$stats_ventes       = ['total_ca' => 0, 'nb_tickets' => 0, 'panier_moyen' => 0];
$repartition_paiement = ['cash' => 0, 'momo' => 0, 'carte' => 0, 'assurance' => 0];
$marge_session      = 0;
$top_produits       = [];
$top_familles       = [];
$evolution          = 0;
$total_entrees      = 0;
$total_sorties      = 0;
$charges_session    = [];
$total_charges      = 0;
$stats_assurance    = [];
$stats_clients      = ['nb_clients' => 0, 'ca_clients' => 0];
$ventes_horaires    = [];
$peremptions        = [];
$remises_total      = 0;
$alertes_stock      = [];
$top_produits_ca    = [];
$derniere_cloture   = null;
$recap_clotures     = [];

if ($session) {
    $date_debut = $session['date_ouverture'];

    // ── STATS VENTES GLOBALES ──
    $stmtStats = $pdo->prepare("
        SELECT COUNT(id_vente) as nb_tickets,
               IFNULL(SUM(total), 0) as total_ca,
               IFNULL(AVG(total), 0) as panier_moyen,
               IFNULL(SUM(remise_montant), 0) as total_remises,
               IFNULL(SUM(part_assurance), 0) as total_assurance,
               IFNULL(SUM(part_patient), 0) as total_patient
        FROM ventes WHERE date_vente >= ? AND statut_paiement != 'annule'
    ");
    $stmtStats->execute([$date_debut]);
    $stats_ventes = $stmtStats->fetch();
    $remises_total = $stats_ventes['total_remises'] ?? 0;

    // ── SOLDE CAISSE ──
    $stmtSolde = $pdo->prepare("
        SELECT SUM(CASE WHEN type_mouvement IN ('entree','ouverture') THEN montant ELSE 0 END)
             - SUM(CASE WHEN type_mouvement = 'sortie' THEN montant ELSE 0 END) as solde
        FROM caisse WHERE date_mouvement >= ?
    ");
    $stmtSolde->execute([$date_debut]);
    $solde_actuel = $stmtSolde->fetch()['solde'] ?? 0;

    // ── RÉPARTITION PAIEMENTS ──
    $stmtPay = $pdo->prepare("SELECT mode_paiement, SUM(total) as total FROM ventes WHERE date_vente >= ? AND statut_paiement != 'annule' GROUP BY mode_paiement");
    $stmtPay->execute([$date_debut]);
    while ($row = $stmtPay->fetch()) {
        $mode = mb_strtolower($row['mode_paiement'], 'UTF-8');
        if (in_array($mode, ['espèces','especes']))       $repartition_paiement['cash']      = $row['total'];
        if (in_array($mode, ['mobile money','momo']))     $repartition_paiement['momo']      = $row['total'];
        if ($mode === 'carte')                             $repartition_paiement['carte']     = $row['total'];
        if (in_array($mode, ['assurance','tiers payant'])) $repartition_paiement['assurance'] = $row['total'];
    }

    // ── MOUVEMENTS CAISSE ──
    $stmtMouv = $pdo->prepare("SELECT c.*, u.nom_complet FROM caisse c LEFT JOIN utilisateurs u ON c.id_utilisateur = u.id_user WHERE c.date_mouvement >= ? ORDER BY c.date_mouvement DESC");
    $stmtMouv->execute([$date_debut]);
    $mouvements = $stmtMouv->fetchAll();
    foreach ($mouvements as $m) {
        if ($m['type_mouvement'] === 'entree')  $total_entrees += $m['montant'];
        if ($m['type_mouvement'] === 'sortie')  $total_sorties += $m['montant'];
    }

    // ── MARGE ──
    $stmtMarge = $pdo->prepare("
        SELECT IFNULL(SUM((dv.prix_unitaire - IFNULL(p.prix_achat, 0)) * dv.quantite), 0) as marge_totale
        FROM detail_ventes dv
        JOIN produits p ON dv.id_produit = p.id_produit
        JOIN ventes v ON dv.id_vente = v.id_vente
        WHERE v.date_vente >= ? AND v.statut_paiement != 'annule'
    ");
    $stmtMarge->execute([$date_debut]);
    $marge_session = $stmtMarge->fetch()['marge_totale'] ?? 0;

    // ── TOP 5 PRODUITS (QTÉ) ──
    $stmtTop = $pdo->prepare("
        SELECT p.nom_commercial, SUM(dv.quantite) as qte,
               SUM(dv.quantite * dv.prix_unitaire) as ca_produit
        FROM detail_ventes dv
        JOIN produits p ON dv.id_produit = p.id_produit
        JOIN ventes v ON dv.id_vente = v.id_vente
        WHERE v.date_vente >= ? AND v.statut_paiement != 'annule'
        GROUP BY p.id_produit ORDER BY qte DESC LIMIT 5
    ");
    $stmtTop->execute([$date_debut]);
    $top_produits = $stmtTop->fetchAll();

    // ── TOP 5 PRODUITS (CA) ──
    $stmtTopCA = $pdo->prepare("
        SELECT p.nom_commercial, SUM(dv.quantite * dv.prix_unitaire) as ca_produit, SUM(dv.quantite) as qte
        FROM detail_ventes dv
        JOIN produits p ON dv.id_produit = p.id_produit
        JOIN ventes v ON dv.id_vente = v.id_vente
        WHERE v.date_vente >= ? AND v.statut_paiement != 'annule'
        GROUP BY p.id_produit ORDER BY ca_produit DESC LIMIT 5
    ");
    $stmtTopCA->execute([$date_debut]);
    $top_produits_ca = $stmtTopCA->fetchAll();

    // ── TOP FAMILLES ──
    $stmtFam = $pdo->prepare("
        SELECT f.nom_famille, SUM(dv.quantite * dv.prix_unitaire) as ca_fam, SUM(dv.quantite) as qte_fam
        FROM detail_ventes dv
        JOIN produits p ON dv.id_produit = p.id_produit
        JOIN sous_familles sf ON p.id_sous_famille = sf.id_sous_famille
        JOIN familles f ON sf.id_famille = f.id_famille
        JOIN ventes v ON dv.id_vente = v.id_vente
        WHERE v.date_vente >= ? AND v.statut_paiement != 'annule'
        GROUP BY f.id_famille ORDER BY ca_fam DESC LIMIT 5
    ");
    $stmtFam->execute([$date_debut]);
    $top_familles = $stmtFam->fetchAll();

    // ── STATS ASSURANCES ──
    $stmtAss = $pdo->prepare("
        SELECT a.nom_assurance, COUNT(v.id_vente) as nb, SUM(v.part_assurance) as montant_ass, SUM(v.total) as ca_tot
        FROM ventes v JOIN assurances a ON v.id_assurance = a.id_assurance
        WHERE v.date_vente >= ? AND v.statut_paiement != 'annule'
        GROUP BY a.id_assurance ORDER BY montant_ass DESC LIMIT 5
    ");
    $stmtAss->execute([$date_debut]);
    $stats_assurance = $stmtAss->fetchAll();

    // ── CLIENTS FIDÈLES DE LA SESSION ──
    $stmtCli = $pdo->prepare("
        SELECT COUNT(DISTINCT v.id_client) as nb_clients, IFNULL(SUM(v.total), 0) as ca_clients
        FROM ventes v WHERE v.date_vente >= ? AND v.id_client IS NOT NULL AND v.statut_paiement != 'annule'
    ");
    $stmtCli->execute([$date_debut]);
    $stats_clients = $stmtCli->fetch();

    // ── VENTES PAR HEURE ──
    $stmtHeure = $pdo->prepare("
        SELECT HOUR(date_vente) as heure, COUNT(*) as nb, SUM(total) as ca
        FROM ventes WHERE date_vente >= ? AND statut_paiement != 'annule'
        GROUP BY HOUR(date_vente) ORDER BY heure
    ");
    $stmtHeure->execute([$date_debut]);
    $ventes_horaires = $stmtHeure->fetchAll();

    // ── CHARGES DU JOUR ──
    $stmtCh = $pdo->prepare("
        SELECT ch.*, cc.libelle as categorie
        FROM charges ch LEFT JOIN compte_charges cc ON ch.code_compte = cc.code_compte
        WHERE DATE(ch.date_operation) >= DATE(?) ORDER BY ch.date_operation DESC
    ");
    $stmtCh->execute([$date_debut]);
    $charges_session = $stmtCh->fetchAll();
    foreach ($charges_session as $ch) { $total_charges += $ch['montant']; }

    // ── PÉREMPTIONS PROCHES (30 jours) ──
    $peremptions = $pdo->query("
        SELECT p.nom_commercial, s.date_peremption, s.quantite_disponible, s.numero_lot,
               DATEDIFF(s.date_peremption, CURDATE()) as jours_restants
        FROM stocks s JOIN produits p ON s.id_produit = p.id_produit
        WHERE s.date_peremption BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)
          AND s.quantite_disponible > 0
        ORDER BY s.date_peremption LIMIT 8
    ")->fetchAll();

    // ── ALERTES STOCK ──
    $alertes_stock = $pdo->query("
        SELECT p.nom_commercial, SUM(s.quantite_disponible) as qte_dispo, p.seuil_alerte
        FROM produits p JOIN stocks s ON p.id_produit = s.id_produit
        WHERE p.actif = 1
        GROUP BY p.id_produit
        HAVING qte_dispo <= p.seuil_alerte
        ORDER BY qte_dispo ASC LIMIT 8
    ")->fetchAll();

    // ── ÉVOLUTION VS HIER ──
    $hier_debut = date('Y-m-d H:i:s', strtotime($date_debut . ' -1 day'));
    $hier_fin   = date('Y-m-d H:i:s', strtotime('now -1 day'));
    $stmtHier = $pdo->prepare("SELECT SUM(total) as ca FROM ventes WHERE date_vente BETWEEN ? AND ?");
    $stmtHier->execute([$hier_debut, $hier_fin]);
    $ca_hier  = $stmtHier->fetch()['ca'] ?? 0;
    $evolution = ($ca_hier > 0) ? (($stats_ventes['total_ca'] - $ca_hier) / $ca_hier) * 100 : 0;

    // ── DERNIÈRES CLÔTURES ──
    $recap_clotures = $pdo->query("
        SELECT cl.*, u.nom_complet FROM clotures cl LEFT JOIN utilisateurs u ON cl.id_utilisateur = u.id_user
        ORDER BY cl.date_cloture DESC LIMIT 5
    ")->fetchAll();
}

// ── HELPERS ──
function fmt($n) { return number_format($n, 0, '.', ' '); }
function taux($part, $total) { return ($total > 0) ? round(($part / $total) * 100, 1) : 0; }
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<title>PharmAssist - Pilotage Caisse</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:ital,opsz,wght@0,9..40,300;0,9..40,400;0,9..40,500;0,9..40,600;1,9..40,400&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
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
    --teal: #0d9488;
    --teal-light: #ccfbf1;
    --orange: #f97316;
    --orange-light: #ffedd5;
    --shadow-sm: 0 1px 3px rgba(0,0,0,0.06), 0 1px 2px rgba(0,0,0,0.04);
    --shadow: 0 4px 12px rgba(0,0,0,0.07);
    --radius: 8px;
    --sidebar-w: 220px;
}
* { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: 'DM Sans', sans-serif; font-size: 13px; background: var(--bg); color: var(--text-primary); display: flex; min-height: 100vh; }

/* ── SIDEBAR ── */
.sidebar { width: var(--sidebar-w); background: var(--sidebar-bg); height: 100vh; position: fixed; top: 0; left: 0; display: flex; flex-direction: column; overflow: hidden; }
.sidebar-logo { padding: 18px 20px 14px; border-bottom: 1px solid rgba(255,255,255,0.06); }
.brand { display: flex; align-items: center; gap: 9px; }
.brand-icon { width: 28px; height: 28px; background: var(--green); border-radius: 6px; display: flex; align-items: center; justify-content: center; color: white; font-size: 12px; }
.brand-name { font-size: 13px; font-weight: 600; color: #f9fafb; letter-spacing: .02em; }
.brand-sub { font-size: 10px; color: #6b7280; margin-top: 1px; }
.sidebar-section-label { font-size: 9px; font-weight: 600; letter-spacing: .1em; text-transform: uppercase; color: #4b5563; padding: 14px 20px 6px; }
.sidebar-menu { list-style: none; padding: 0 10px; }
.sidebar-menu li { margin-bottom: 2px; }
.sidebar-menu a { color: #9ca3af; text-decoration: none; padding: 8px 12px; border-radius: 6px; display: flex; align-items: center; gap: 9px; font-size: 12.5px; font-weight: 400; transition: all .15s ease; }
.sidebar-menu a:hover { background: var(--sidebar-hover); color: #e5e7eb; }
.sidebar-menu a.active { background: var(--sidebar-active); color: #ecfdf5; font-weight: 500; border-left: 2px solid var(--sidebar-active-border); padding-left: 10px; }
.sidebar-menu a i { width: 14px; font-size: 12px; text-align: center; opacity: .8; }
.sidebar-footer { margin-top: auto; padding: 14px 20px; border-top: 1px solid rgba(255,255,255,0.06); }
.user-info { display: flex; align-items: center; gap: 9px; }
.avatar { width: 26px; height: 26px; background: #374151; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 10px; color: #9ca3af; }
.user-name { font-size: 11px; font-weight: 500; color: #e5e7eb; }
.user-role { font-size: 10px; color: #6b7280; }

/* ── MAIN ── */
.main-content { margin-left: var(--sidebar-w); flex: 1; display: flex; flex-direction: column; min-height: 100vh; }

/* ── TOPBAR ── */
.topbar { background: var(--card-bg); border-bottom: 1px solid var(--card-border); padding: 0 24px; height: 52px; display: flex; align-items: center; justify-content: space-between; position: sticky; top: 0; z-index: 10; }
.topbar-left { display: flex; align-items: center; gap: 8px; }
.topbar-title { font-size: 13px; font-weight: 600; color: var(--text-primary); }
.topbar-breadcrumb { font-size: 11px; color: var(--text-muted); }
.topbar-divider { width: 1px; height: 16px; background: var(--card-border); }
.topbar-right { display: flex; align-items: center; gap: 10px; }

/* ── SESSION BADGE ── */
.session-badge { display: inline-flex; align-items: center; gap: 5px; padding: 3px 9px; border-radius: 20px; font-size: 10.5px; font-weight: 500; }
.session-badge.open { background: var(--green-light); color: #065f46; }
.session-badge.closed { background: #f3f4f6; color: var(--text-secondary); }
.session-badge .dot { width: 5px; height: 5px; border-radius: 50%; background: currentColor; animation: pulse 2s infinite; }
@keyframes pulse { 0%,100% { opacity:1; } 50% { opacity:.4; } }

/* ── PAGE BODY ── */
.page-body { padding: 20px 24px; flex: 1; }

/* ── BUTTONS ── */
.btn { padding: 7px 14px; border: none; border-radius: 6px; cursor: pointer; font-family: 'DM Sans', sans-serif; font-size: 12px; font-weight: 500; color: white; display: inline-flex; align-items: center; gap: 6px; transition: all .15s ease; letter-spacing: .01em; }
.btn:hover { filter: brightness(.92); transform: translateY(-1px); }
.btn:active { transform: translateY(0); }
.btn-sm { padding: 5px 10px; font-size: 11px; }
.btn-green { background: var(--green); }
.btn-blue { background: var(--blue); }
.btn-red { background: var(--red); }
.btn-dark { background: #374151; }
.btn-navy { background: var(--sidebar-bg); }
.btn-amber { background: var(--amber); }
.btn-teal { background: var(--teal); }
.btn-outline { background: transparent; border: 1px solid var(--card-border); color: var(--text-secondary); }
.btn-outline:hover { background: #f9fafb; color: var(--text-primary); filter: none; }

/* ── TABS ── */
.tabs-nav { display: flex; gap: 2px; margin-bottom: 16px; background: #e5e7eb; padding: 3px; border-radius: 8px; width: fit-content; flex-wrap: wrap; }
.tab-btn { padding: 6px 16px; cursor: pointer; border: none; background: transparent; border-radius: 6px; font-family: 'DM Sans', sans-serif; font-size: 12px; font-weight: 500; color: var(--text-secondary); transition: all .15s ease; }
.tab-btn.active { background: white; color: var(--text-primary); box-shadow: var(--shadow-sm); }
.tab-content { display: none; }
.tab-content.active { display: block; animation: fadeIn .2s ease; }
@keyframes fadeIn { from { opacity:0; transform:translateY(4px); } to { opacity:1; transform:translateY(0); } }

/* ── GRIDS ── */
.grid-4 { display: grid; grid-template-columns: repeat(4, 1fr); gap: 14px; margin-bottom: 16px; }
.grid-3 { display: grid; grid-template-columns: repeat(3, 1fr); gap: 14px; margin-bottom: 16px; }
.grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; margin-bottom: 16px; }
.grid-2-1 { display: grid; grid-template-columns: 2fr 1fr; gap: 14px; margin-bottom: 16px; }
@media (max-width: 1200px) {
    .grid-4 { grid-template-columns: repeat(2, 1fr); }
    .grid-3 { grid-template-columns: repeat(2, 1fr); }
    .grid-2-1 { grid-template-columns: 1fr; }
}
@media (max-width: 768px) {
    .grid-4, .grid-3, .grid-2 { grid-template-columns: 1fr; }
}

/* ── STAT CARDS ── */
.stat-card { background: var(--card-bg); border-radius: var(--radius); border: 1px solid var(--card-border); padding: 14px 16px; box-shadow: var(--shadow-sm); position: relative; overflow: hidden; transition: box-shadow .15s ease; }
.stat-card:hover { box-shadow: var(--shadow); }
.stat-card::before { content: ''; position: absolute; top: 0; left: 0; width: 3px; height: 100%; background: var(--accent-color, var(--blue)); }
.card-label { font-size: 10.5px; font-weight: 500; color: var(--text-muted); text-transform: uppercase; letter-spacing: .05em; margin-bottom: 6px; }
.card-value { font-family: 'DM Mono', monospace; font-size: 20px; font-weight: 500; color: var(--text-primary); line-height: 1; margin-bottom: 6px; }
.card-value.sm { font-size: 16px; }
.card-sub { font-size: 10.5px; color: var(--text-secondary); }
.card-icon { position: absolute; top: 14px; right: 14px; width: 30px; height: 30px; border-radius: 6px; display: flex; align-items: center; justify-content: center; font-size: 12px; }
.card-green  { --accent-color: var(--green); }
.card-blue   { --accent-color: var(--blue); }
.card-amber  { --accent-color: var(--amber); }
.card-red    { --accent-color: var(--red); }
.card-purple { --accent-color: var(--purple); }
.card-teal   { --accent-color: var(--teal); }
.card-orange { --accent-color: var(--orange); }
.icon-green  { background: var(--green-light);  color: var(--green); }
.icon-blue   { background: var(--blue-light);   color: var(--blue); }
.icon-amber  { background: var(--amber-light);  color: var(--amber); }
.icon-red    { background: var(--red-light);    color: var(--red); }
.icon-purple { background: var(--purple-light); color: var(--purple); }
.icon-teal   { background: var(--teal-light);   color: var(--teal); }
.icon-orange { background: var(--orange-light); color: var(--orange); }

/* ── PANEL ── */
.panel { background: var(--card-bg); border-radius: var(--radius); border: 1px solid var(--card-border); box-shadow: var(--shadow-sm); overflow: hidden; margin-bottom: 16px; }
.panel-header { padding: 12px 16px; border-bottom: 1px solid var(--card-border); display: flex; align-items: center; justify-content: space-between; }
.panel-title { font-size: 12px; font-weight: 600; color: var(--text-primary); display: flex; align-items: center; gap: 7px; }
.panel-title i { font-size: 11px; color: var(--text-muted); }
.panel-body { padding: 14px 16px; }
.panel-body.p0 { padding: 0; }
.panel-footer { padding: 10px 16px; background: #f9fafb; border-top: 1px solid var(--card-border); display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }

/* ── TABLE ── */
.data-table { width: 100%; border-collapse: collapse; }
.data-table thead tr { border-bottom: 1px solid var(--card-border); }
.data-table thead th { padding: 7px 12px; font-size: 10.5px; font-weight: 600; color: var(--text-muted); text-transform: uppercase; letter-spacing: .05em; text-align: left; background: #f9fafb; white-space: nowrap; }
.data-table thead th.r { text-align: right; }
.data-table tbody tr { border-bottom: 1px solid #f3f4f6; transition: background .1s; }
.data-table tbody tr:last-child { border-bottom: none; }
.data-table tbody tr:hover { background: #f9fafb; }
.data-table tbody td { padding: 8px 12px; font-size: 12px; color: var(--text-primary); }
.data-table tbody td.r { text-align: right; font-family: 'DM Mono', monospace; font-size: 11.5px; }
.data-table tbody td.mono { font-family: 'DM Mono', monospace; font-size: 11.5px; }
.empty-row td { text-align: center; color: var(--text-muted); padding: 20px 12px !important; font-size: 11.5px; }

/* ── BADGES ── */
.type-badge { display: inline-flex; align-items: center; gap: 4px; padding: 2px 7px; border-radius: 4px; font-size: 10px; font-weight: 600; text-transform: uppercase; letter-spacing: .04em; }
.badge-entree    { background: var(--green-light); color: #065f46; }
.badge-sortie    { background: var(--red-light); color: #991b1b; }
.badge-ouverture { background: var(--blue-light); color: #1e40af; }
.badge-success   { background: var(--green-light); color: #065f46; }
.badge-warn      { background: var(--amber-light); color: #92400e; }
.badge-danger    { background: var(--red-light); color: #991b1b; }

/* ── ACTION BAR ── */
.action-bar { display: flex; align-items: center; gap: 8px; margin-bottom: 16px; flex-wrap: wrap; }
.action-bar .sep { width: 1px; height: 22px; background: var(--card-border); margin: 0 2px; }

/* ── PAYMENT PILLS ── */
.pay-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 8px; }
.pay-pill { background: #f9fafb; border: 1px solid var(--card-border); border-radius: 6px; padding: 8px 10px; text-align: center; }
.pay-label { font-size: 9.5px; font-weight: 600; color: var(--text-muted); text-transform: uppercase; letter-spacing: .06em; margin-bottom: 3px; }
.pay-value { font-family: 'DM Mono', monospace; font-size: 12px; font-weight: 500; color: var(--text-primary); }

/* ── LIST ITEMS ── */
.list-item { display: flex; align-items: center; justify-content: space-between; padding: 7px 0; border-bottom: 1px solid #f3f4f6; }
.list-item:last-child { border-bottom: none; }
.list-item .rank { width: 20px; font-size: 10px; font-weight: 700; color: var(--text-muted); font-family: 'DM Mono', monospace; }
.list-item .name { flex: 1; font-size: 12px; color: var(--text-primary); padding: 0 8px; }
.list-item .chip { font-family: 'DM Mono', monospace; font-size: 11px; font-weight: 500; padding: 2px 7px; border-radius: 4px; }
.chip-gray   { background: #f3f4f6; color: var(--text-secondary); }
.chip-green  { background: var(--green-light); color: #065f46; }
.chip-red    { background: var(--red-light); color: #991b1b; }
.chip-amber  { background: var(--amber-light); color: #92400e; }
.chip-blue   { background: var(--blue-light); color: #1e40af; }

/* ── PROGRESS BAR ── */
.progress-wrap { margin-top: 6px; }
.progress-bar-bg { height: 4px; background: #f3f4f6; border-radius: 2px; overflow: hidden; }
.progress-bar-fill { height: 100%; border-radius: 2px; transition: width .4s ease; }

/* ── TIMELINE HORAIRE ── */
.heure-grid { display: flex; align-items: flex-end; gap: 6px; height: 60px; }
.heure-bar-wrap { display: flex; flex-direction: column; align-items: center; gap: 4px; flex: 1; }
.heure-bar { width: 100%; background: var(--blue-light); border-radius: 3px 3px 0 0; min-height: 3px; transition: background .15s; cursor: default; position: relative; }
.heure-bar:hover { background: var(--blue); }
.heure-label { font-size: 9px; color: var(--text-muted); font-family: 'DM Mono', monospace; }

/* ── RECAP LIGNE ── */
.recap-line { display: flex; justify-content: space-between; align-items: center; padding: 6px 0; border-bottom: 1px solid #f3f4f6; font-size: 12px; }
.recap-line:last-child { border-bottom: none; }
.recap-line.total { font-weight: 600; padding-top: 10px; margin-top: 4px; border-top: 2px solid var(--card-border); border-bottom: none; font-size: 13px; }
.recap-line .rl-label { color: var(--text-secondary); }
.recap-line .rl-val { font-family: 'DM Mono', monospace; font-weight: 500; }

/* ── EVO BADGE ── */
.evo-badge { display: inline-flex; align-items: center; gap: 3px; padding: 2px 7px; border-radius: 4px; font-size: 10.5px; font-weight: 600; }
.evo-up   { background: var(--green-light); color: #065f46; }
.evo-down { background: var(--red-light); color: #991b1b; }
.evo-flat { background: #f3f4f6; color: var(--text-secondary); }

/* ── CAISSE FERMEE ── */
.closed-state { display: flex; flex-direction: column; align-items: center; justify-content: center; padding: 60px 20px; background: var(--card-bg); border-radius: var(--radius); border: 1px solid var(--card-border); gap: 12px; }
.closed-icon { width: 52px; height: 52px; background: #f3f4f6; border-radius: 12px; display: flex; align-items: center; justify-content: center; color: var(--text-muted); font-size: 20px; }

/* ── TOTALS ROW ── */
.totals-row { display: flex; gap: 10px; padding: 10px 12px; background: #f9fafb; border-top: 1px solid var(--card-border); flex-wrap: wrap; }
.total-item { display: flex; align-items: center; gap: 6px; font-size: 11px; }
.total-item .total-label { color: var(--text-muted); }
.total-item .total-val { font-family: 'DM Mono', monospace; font-weight: 500; }
.total-item.t-green .total-val { color: var(--green); }
.total-item.t-red .total-val { color: var(--red); }
.total-item.t-blue .total-val { color: var(--blue); }

/* ── CLOTURE HISTORY ── */
.cloture-row { display: flex; align-items: center; gap: 10px; padding: 8px 0; border-bottom: 1px solid #f3f4f6; }
.cloture-row:last-child { border-bottom: none; }

/* ── SECTION TITLE ── */
.section-label { font-size: 10px; font-weight: 600; text-transform: uppercase; letter-spacing: .08em; color: var(--text-muted); margin: 12px 0 8px; }

/* scrollbar */
::-webkit-scrollbar { width: 5px; height: 5px; }
::-webkit-scrollbar-track { background: transparent; }
::-webkit-scrollbar-thumb { background: #d1d5db; border-radius: 10px; }

/* tooltip on bar */
.heure-bar::after { content: attr(data-tip); position: absolute; bottom: 110%; left: 50%; transform: translateX(-50%); background: #111827; color: #f9fafb; font-size: 9px; padding: 2px 5px; border-radius: 4px; white-space: nowrap; opacity: 0; pointer-events: none; transition: opacity .15s; font-family: 'DM Mono', monospace; }
.heure-bar:hover::after { opacity: 1; }
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
        <li><a href="charges.php"><i class="fas fa-file-invoice-dollar"></i> Charges</a></li>
    </ul>
    <div class="sidebar-footer">
        <div class="user-info">
            <div class="avatar"><i class="fas fa-user"></i></div>
            <div>
                <div class="user-name"><?= htmlspecialchars($session['nom_complet'] ?? 'Pharmacien') ?></div>
                <div class="user-role">Administrateur</div>
            </div>
        </div>
    </div>
</nav>

<!-- ════ MAIN ════ -->
<div class="main-content">

    <!-- TOPBAR -->
    <div class="topbar">
        <div class="topbar-left">
            <span class="topbar-breadcrumb">PharmAssist</span>
            <span style="margin:0 6px;color:#d1d5db;">/</span>
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
                <button class="btn btn-outline btn-sm" onclick="imprimerTicketSession(<?= $session['id_session'] ?>)">
                    <i class="fas fa-print"></i> Rapport provisoire
                </button>
                <button class="btn btn-navy btn-sm" onclick="cloturerCaisse(<?= $solde_actuel ?>)">
                    <i class="fas fa-power-off"></i> Cloturer
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
            <h3 style="font-size:14px;font-weight:600;">Caisse fermee</h3>
            <p style="font-size:12px;color:var(--text-secondary);">Aucune session active. Ouvrez la caisse pour commencer.</p>
            <button class="btn btn-green" onclick="ouvrirCaisse()">
                <i class="fas fa-unlock-alt"></i> Ouvrir la session
            </button>
        </div>

        <!-- Historique des clotures meme si caisse fermee -->
        <?php if (!empty($recap_clotures)): ?>
        <div class="panel" style="margin-top:16px;">
            <div class="panel-header">
                <div class="panel-title"><i class="fas fa-history"></i> Historique des 5 dernieres clotures</div>
            </div>
            <table class="data-table">
                <thead><tr>
                    <th>Date</th><th>Ventes</th><th>Esp.</th><th>MoMo</th><th>Assur.</th><th class="r">Marge brute</th><th class="r">Total encaisse</th>
                </tr></thead>
                <tbody>
                <?php foreach($recap_clotures as $cl): ?>
                <tr>
                    <td class="mono"><?= date('d/m/Y', strtotime($cl['date_cloture'])) ?></td>
                    <td><?= $cl['nb_ventes'] ?> tickets</td>
                    <td class="mono"><?= fmt($cl['total_especes']) ?> F</td>
                    <td class="mono"><?= fmt($cl['total_mobile_money']) ?> F</td>
                    <td class="mono"><?= fmt($cl['total_assurance']) ?> F</td>
                    <td class="r" style="color:var(--green);"><?= fmt($cl['marge_brute']) ?> F</td>
                    <td class="r" style="font-weight:600;"><?= fmt($cl['montant_final']) ?> F</td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

    <?php else: ?>

    <!-- ═══ ONGLETS ═══ -->
    <div class="tabs-nav">
        <button class="tab-btn active" onclick="openTab(event,'tab-gestion')">Caisse &amp; Cash</button>
        <button class="tab-btn" onclick="openTab(event,'tab-ventes')">Ventes &amp; Marges</button>
        <button class="tab-btn" onclick="openTab(event,'tab-produits')">Produits &amp; Stock</button>
        <button class="tab-btn" onclick="openTab(event,'tab-charges')">Charges</button>
        <button class="tab-btn" onclick="openTab(event,'tab-recap')">Recapitulatif</button>
    </div>

    <!-- ════════════════════════════════════════════════════
         TAB 1 — CAISSE & CASH
    ════════════════════════════════════════════════════ -->
    <div id="tab-gestion" class="tab-content active">

        <!-- KPIs ligne 1 -->
        <div class="grid-4">
            <div class="stat-card card-amber">
                <div class="card-icon icon-amber"><i class="fas fa-coins"></i></div>
                <div class="card-label">Solde caisse (theorique)</div>
                <div class="card-value"><?= fmt($solde_actuel) ?> F</div>
                <div class="card-sub">Fond + entrees - sorties</div>
            </div>
            <div class="stat-card card-green">
                <div class="card-icon icon-green"><i class="fas fa-cash-register"></i></div>
                <div class="card-label">Fond d'ouverture</div>
                <div class="card-value"><?= fmt($session['fond_caisse_depart']) ?> F</div>
                <div class="card-sub">Saisie a l'ouverture</div>
            </div>
            <div class="stat-card card-blue">
                <div class="card-icon icon-blue"><i class="fas fa-arrow-circle-up"></i></div>
                <div class="card-label">Total entrees</div>
                <div class="card-value"><?= fmt($total_entrees) ?> F</div>
                <div class="card-sub">Mouvements manuels</div>
            </div>
            <div class="stat-card card-red">
                <div class="card-icon icon-red"><i class="fas fa-arrow-circle-down"></i></div>
                <div class="card-label">Total sorties</div>
                <div class="card-value"><?= fmt($total_sorties) ?> F</div>
                <div class="card-sub">Depenses enregistrees</div>
            </div>
        </div>

        <!-- Répartition paiements -->
        <div class="panel">
            <div class="panel-header">
                <div class="panel-title"><i class="fas fa-credit-card"></i> Repartition par mode de paiement</div>
                <span style="font-size:11px;color:var(--text-muted);">CA total : <?= fmt($stats_ventes['total_ca']) ?> F</span>
            </div>
            <div class="panel-body">
                <div class="pay-grid">
                    <?php
                    $pays = [
                        ['label'=>'Especes', 'val'=>$repartition_paiement['cash'], 'color'=>'var(--amber)'],
                        ['label'=>'Mobile Money', 'val'=>$repartition_paiement['momo'], 'color'=>'var(--blue)'],
                        ['label'=>'Carte', 'val'=>$repartition_paiement['carte'], 'color'=>'var(--purple)'],
                        ['label'=>'Assurance', 'val'=>$repartition_paiement['assurance'], 'color'=>'var(--teal)'],
                    ];
                    foreach($pays as $p):
                        $pct = taux($p['val'], $stats_ventes['total_ca']);
                    ?>
                    <div class="pay-pill">
                        <div class="pay-label"><?= $p['label'] ?></div>
                        <div class="pay-value" style="font-size:15px;"><?= fmt($p['val']) ?> F</div>
                        <div style="font-size:10px;color:var(--text-muted);margin-top:3px;"><?= $pct ?>% du CA</div>
                        <div class="progress-wrap">
                            <div class="progress-bar-bg"><div class="progress-bar-fill" style="width:<?= $pct ?>%;background:<?= $p['color'] ?>;"></div></div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <!-- Actions -->
        <div class="action-bar">
            <button class="btn btn-red" onclick="ajouterMouvement('sortie')"><i class="fas fa-minus-circle"></i> Depense</button>
            <button class="btn btn-green" onclick="ajouterMouvement('entree')"><i class="fas fa-plus-circle"></i> Entree manuelle</button>
            <div class="sep"></div>
            <button class="btn btn-teal" onclick="ajouterCharge()"><i class="fas fa-file-invoice-dollar"></i> Enregistrer une charge</button>
        </div>

        <!-- Journal des mouvements -->
        <div class="panel">
            <div class="panel-header">
                <div class="panel-title"><i class="fas fa-list-ul"></i> Journal des mouvements caisse</div>
                <span style="font-size:11px;color:var(--text-muted);"><?= count($mouvements) ?> operations</span>
            </div>
            <table class="data-table">
                <thead><tr>
                    <th>Heure</th><th>Type</th><th>Motif / Vente</th><th>Operateur</th><th class="r">Montant</th>
                </tr></thead>
                <tbody>
                <?php if(empty($mouvements)): ?>
                    <tr class="empty-row"><td colspan="5">Aucun mouvement pour cette session</td></tr>
                <?php endif; ?>
                <?php foreach($mouvements as $m):
                    $t = $m['type_mouvement'];
                    $cls = ($t==='entree') ? 'badge-entree' : (($t==='sortie') ? 'badge-sortie' : 'badge-ouverture');
                    $couleur = ($t==='sortie') ? 'var(--red)' : 'var(--green)';
                    $sign = ($t==='sortie') ? '-' : '+';
                ?>
                <tr>
                    <td class="mono" style="color:var(--text-secondary);"><?= date('H:i', strtotime($m['date_mouvement'])) ?></td>
                    <td><span class="type-badge <?= $cls ?>"><?= strtoupper($t) ?></span></td>
                    <td style="color:var(--text-secondary);">
                        <?= htmlspecialchars($m['motif'] ?: '—') ?>
                        <?php if($m['id_vente']): ?>
                            <span style="font-size:10px;background:#f3f4f6;padding:1px 5px;border-radius:3px;margin-left:4px;font-family:'DM Mono',monospace;">#V<?= $m['id_vente'] ?></span>
                        <?php endif; ?>
                    </td>
                    <td style="font-size:11px;color:var(--text-muted);"><?= htmlspecialchars($m['nom_complet'] ?? '—') ?></td>
                    <td class="r" style="color:<?= $couleur ?>;font-weight:500;"><?= $sign.fmt($m['montant']) ?> F</td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <div class="totals-row">
                <div class="total-item t-green"><span class="total-label">Entrees manuelles :</span><span class="total-val">+<?= fmt($total_entrees) ?> F</span></div>
                <div style="width:1px;background:var(--card-border);margin:0 4px;"></div>
                <div class="total-item t-red"><span class="total-label">Sorties manuelles :</span><span class="total-val">-<?= fmt($total_sorties) ?> F</span></div>
                <div style="width:1px;background:var(--card-border);margin:0 4px;"></div>
                <div class="total-item t-blue"><span class="total-label">Solde net :</span><span class="total-val"><?= fmt($solde_actuel) ?> F</span></div>
            </div>
        </div>
    </div><!-- /tab-gestion -->

    <!-- ════════════════════════════════════════════════════
         TAB 2 — VENTES & MARGES
    ════════════════════════════════════════════════════ -->
    <div id="tab-ventes" class="tab-content">

        <!-- KPIs ventes -->
        <div class="grid-4">
            <div class="stat-card card-green">
                <div class="card-icon icon-green"><i class="fas fa-chart-line"></i></div>
                <div class="card-label">CA Session</div>
                <div class="card-value"><?= fmt($stats_ventes['total_ca']) ?> F</div>
                <div class="card-sub">
                    <?php if($evolution != 0): ?>
                    <span class="evo-badge <?= $evolution >= 0 ? 'evo-up' : 'evo-down' ?>">
                        <i class="fas fa-arrow-<?= $evolution >= 0 ? 'up' : 'down' ?>"></i>
                        <?= round(abs($evolution),1) ?>% vs hier
                    </span>
                    <?php else: ?>
                    <span class="evo-badge evo-flat">--% vs hier</span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="stat-card card-blue">
                <div class="card-icon icon-blue"><i class="fas fa-shopping-basket"></i></div>
                <div class="card-label">Panier moyen</div>
                <div class="card-value"><?= fmt($stats_ventes['panier_moyen']) ?> F</div>
                <div class="card-sub"><?= $stats_ventes['nb_tickets'] ?> tickets edites</div>
            </div>
            <div class="stat-card card-purple">
                <div class="card-icon icon-purple"><i class="fas fa-percent"></i></div>
                <div class="card-label">Marge brute estimee</div>
                <div class="card-value"><?= fmt($marge_session) ?> F</div>
                <?php $taux_m = taux($marge_session, $stats_ventes['total_ca']); ?>
                <div class="card-sub">Taux : <?= $taux_m ?>%
                    <div class="progress-wrap"><div class="progress-bar-bg"><div class="progress-bar-fill" style="width:<?= $taux_m ?>%;background:var(--purple);"></div></div></div>
                </div>
            </div>
            <div class="stat-card card-orange">
                <div class="card-icon icon-orange"><i class="fas fa-tag"></i></div>
                <div class="card-label">Remises accordees</div>
                <div class="card-value sm"><?= fmt($remises_total) ?> F</div>
                <div class="card-sub"><?= taux($remises_total, $stats_ventes['total_ca'] + $remises_total) ?>% du brut</div>
            </div>
        </div>

        <div class="grid-2">
            <!-- CA par heure -->
            <div class="panel">
                <div class="panel-header">
                    <div class="panel-title"><i class="fas fa-clock"></i> Activite par heure</div>
                    <span style="font-size:10.5px;color:var(--text-muted);">tickets / CA</span>
                </div>
                <div class="panel-body">
                <?php if(empty($ventes_horaires)): ?>
                    <p style="font-size:11.5px;color:var(--text-muted);text-align:center;padding:16px 0;">Aucune donnee horaire</p>
                <?php else:
                    $max_ca = max(array_column($ventes_horaires, 'ca')) ?: 1;
                ?>
                    <div class="heure-grid">
                    <?php foreach($ventes_horaires as $h):
                        $pct = round(($h['ca'] / $max_ca) * 100);
                    ?>
                        <div class="heure-bar-wrap">
                            <div class="heure-bar" style="height:<?= max(4, $pct * 0.6) ?>px;" data-tip="<?= $h['heure'] ?>h: <?= fmt($h['ca']) ?>F (<?= $h['nb'] ?> tickets)"></div>
                            <div class="heure-label"><?= $h['heure'] ?>h</div>
                        </div>
                    <?php endforeach; ?>
                    </div>
                    <div class="totals-row" style="margin-top:8px;border-top:1px solid var(--card-border);border-radius:0;padding:8px 0 0;background:none;">
                    <?php foreach($ventes_horaires as $h): ?>
                        <div style="font-size:10px;color:var(--text-secondary);">
                            <b style="color:var(--text-primary);font-family:'DM Mono',monospace;"><?= $h['nb'] ?></b> @ <?= $h['heure'] ?>h
                        </div>
                    <?php endforeach; ?>
                    </div>
                <?php endif; ?>
                </div>
            </div>

            <!-- Stats clients -->
            <div class="panel">
                <div class="panel-header">
                    <div class="panel-title"><i class="fas fa-users"></i> Clients &amp; Assurances</div>
                </div>
                <div class="panel-body">
                    <div class="section-label">Clients identifies</div>
                    <div style="display:flex;gap:10px;margin-bottom:14px;">
                        <div class="pay-pill" style="flex:1;">
                            <div class="pay-label">Nb clients</div>
                            <div class="pay-value" style="font-size:18px;"><?= $stats_clients['nb_clients'] ?></div>
                        </div>
                        <div class="pay-pill" style="flex:2;">
                            <div class="pay-label">CA clients fideles</div>
                            <div class="pay-value"><?= fmt($stats_clients['ca_clients']) ?> F</div>
                        </div>
                    </div>

                    <div class="section-label">Assurances &amp; Tiers payant</div>
                    <?php if(empty($stats_assurance)): ?>
                        <p style="font-size:11.5px;color:var(--text-muted);text-align:center;padding:10px 0;">Aucune vente en assurance</p>
                    <?php else: ?>
                        <?php foreach($stats_assurance as $a): ?>
                        <div class="list-item">
                            <span class="name" style="padding-left:0;"><?= htmlspecialchars($a['nom_assurance']) ?></span>
                            <span class="chip chip-teal" style="margin-right:6px;"><?= $a['nb'] ?> ventes</span>
                            <span class="chip chip-blue"><?= fmt($a['montant_ass']) ?> F</span>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>

                    <?php if($stats_ventes['total_assurance'] > 0): ?>
                    <div style="margin-top:10px;padding:8px 10px;background:var(--teal-light);border-radius:6px;font-size:11.5px;">
                        <b style="color:var(--teal);">Part assurance totale :</b>
                        <span style="font-family:'DM Mono',monospace;float:right;color:var(--teal);font-weight:600;"><?= fmt($stats_ventes['total_assurance']) ?> F</span>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Decomposition du CA -->
        <div class="panel">
            <div class="panel-header">
                <div class="panel-title"><i class="fas fa-chart-pie"></i> Decomposition du chiffre d'affaires</div>
            </div>
            <div class="panel-body">
                <?php
                $items = [
                    ['label'=>'CA brut (avant remises)', 'val'=>$stats_ventes['total_ca'] + $remises_total, 'color'=>'var(--text-primary)', 'bold'=>false],
                    ['label'=>'(-) Remises accordees', 'val'=>-$remises_total, 'color'=>'var(--red)', 'bold'=>false],
                    ['label'=>'(=) CA net encaisse', 'val'=>$stats_ventes['total_ca'], 'color'=>'var(--green)', 'bold'=>true],
                    ['label'=>'  dont part patients', 'val'=>$stats_ventes['total_patient'], 'color'=>'var(--text-secondary)', 'bold'=>false],
                    ['label'=>'  dont part assurances', 'val'=>$stats_ventes['total_assurance'], 'color'=>'var(--teal)', 'bold'=>false],
                    ['label'=>'(-) Cout marchandises vendues (estime)', 'val'=>-($stats_ventes['total_ca'] - $marge_session), 'color'=>'var(--red)', 'bold'=>false],
                    ['label'=>'(=) Marge brute', 'val'=>$marge_session, 'color'=>'var(--purple)', 'bold'=>true],
                ];
                foreach($items as $it):
                ?>
                <div class="recap-line <?= $it['bold'] ? 'total' : '' ?>">
                    <span class="rl-label" style="color:<?= $it['bold'] ? 'var(--text-primary)' : 'var(--text-secondary)' ?>;"><?= $it['label'] ?></span>
                    <span class="rl-val" style="color:<?= $it['color'] ?>;"><?= ($it['val'] > 0 ? '+' : '') . fmt($it['val']) ?> F</span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

    </div><!-- /tab-ventes -->

    <!-- ════════════════════════════════════════════════════
         TAB 3 — PRODUITS & STOCK
    ════════════════════════════════════════════════════ -->
    <div id="tab-produits" class="tab-content">
        <div class="grid-2">

            <!-- Top 5 par quantite -->
            <div class="panel">
                <div class="panel-header">
                    <div class="panel-title"><i class="fas fa-sort-amount-down"></i> Top 5 par quantite vendue</div>
                    <span style="font-size:10.5px;color:var(--text-muted);">cette session</span>
                </div>
                <div class="panel-body" style="padding:8px 16px;">
                <?php if(empty($top_produits)): ?>
                    <p style="font-size:11.5px;color:var(--text-muted);text-align:center;padding:16px 0;">Aucune vente</p>
                <?php else: $maxQ = $top_produits[0]['qte'] ?: 1; ?>
                    <?php foreach($top_produits as $i=>$tp): ?>
                    <div class="list-item">
                        <span class="rank"><?= str_pad($i+1,2,'0',STR_PAD_LEFT) ?></span>
                        <div style="flex:1;padding:0 8px;">
                            <div style="font-size:12px;"><?= htmlspecialchars($tp['nom_commercial']) ?></div>
                            <div class="progress-bar-bg" style="margin-top:4px;"><div class="progress-bar-fill" style="width:<?= round(($tp['qte']/$maxQ)*100) ?>%;background:var(--green);"></div></div>
                        </div>
                        <span class="chip chip-green"><?= $tp['qte'] ?> vdus</span>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
                </div>
            </div>

            <!-- Top 5 par CA -->
            <div class="panel">
                <div class="panel-header">
                    <div class="panel-title"><i class="fas fa-trophy"></i> Top 5 par chiffre d'affaires</div>
                    <span style="font-size:10.5px;color:var(--text-muted);">cette session</span>
                </div>
                <div class="panel-body" style="padding:8px 16px;">
                <?php if(empty($top_produits_ca)): ?>
                    <p style="font-size:11.5px;color:var(--text-muted);text-align:center;padding:16px 0;">Aucune vente</p>
                <?php else: $maxCA = $top_produits_ca[0]['ca_produit'] ?: 1; ?>
                    <?php foreach($top_produits_ca as $i=>$tp): ?>
                    <div class="list-item">
                        <span class="rank"><?= str_pad($i+1,2,'0',STR_PAD_LEFT) ?></span>
                        <div style="flex:1;padding:0 8px;">
                            <div style="font-size:12px;"><?= htmlspecialchars($tp['nom_commercial']) ?></div>
                            <div class="progress-bar-bg" style="margin-top:4px;"><div class="progress-bar-fill" style="width:<?= round(($tp['ca_produit']/$maxCA)*100) ?>%;background:var(--blue);"></div></div>
                        </div>
                        <span class="chip chip-blue"><?= fmt($tp['ca_produit']) ?> F</span>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
                </div>
            </div>

        </div>

        <div class="grid-2">
            <!-- Top familles -->
            <div class="panel">
                <div class="panel-header">
                    <div class="panel-title"><i class="fas fa-layer-group"></i> Ventes par famille therapeutique</div>
                </div>
                <div class="panel-body" style="padding:8px 16px;">
                <?php if(empty($top_familles)): ?>
                    <p style="font-size:11.5px;color:var(--text-muted);text-align:center;padding:16px 0;">Aucune donnee</p>
                <?php else: $maxF = $top_familles[0]['ca_fam'] ?: 1; ?>
                    <?php foreach($top_familles as $i=>$f): ?>
                    <div class="list-item">
                        <span class="rank"><?= str_pad($i+1,2,'0',STR_PAD_LEFT) ?></span>
                        <div style="flex:1;padding:0 8px;">
                            <div style="font-size:12px;"><?= htmlspecialchars($f['nom_famille']) ?></div>
                            <div class="progress-bar-bg" style="margin-top:4px;"><div class="progress-bar-fill" style="width:<?= round(($f['ca_fam']/$maxF)*100) ?>%;background:var(--purple);"></div></div>
                        </div>
                        <span class="chip chip-gray"><?= fmt($f['ca_fam']) ?> F</span>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
                </div>
            </div>

            <!-- Alertes stock -->
            <div class="panel">
                <div class="panel-header">
                    <div class="panel-title" style="color:var(--red);"><i class="fas fa-exclamation-triangle" style="color:var(--red);"></i> Alertes stock bas</div>
                    <span style="font-size:10.5px;color:var(--text-muted);"><?= count($alertes_stock) ?> produits</span>
                </div>
                <div class="panel-body" style="padding:8px 16px;">
                <?php if(empty($alertes_stock)): ?>
                    <p style="font-size:11.5px;color:var(--text-muted);text-align:center;padding:16px 0;">Aucune alerte stock</p>
                <?php endif; ?>
                <?php foreach($alertes_stock as $a): ?>
                    <div class="list-item">
                        <span class="name" style="padding-left:0;"><?= htmlspecialchars($a['nom_commercial']) ?></span>
                        <span style="font-size:10px;color:var(--text-muted);margin-right:6px;">seuil: <?= $a['seuil_alerte'] ?></span>
                        <span class="chip chip-red"><?= $a['qte_dispo'] ?> restants</span>
                    </div>
                <?php endforeach; ?>
                </div>
            </div>
        </div>

        <!-- Peremptions proches -->
        <?php if(!empty($peremptions)): ?>
        <div class="panel">
            <div class="panel-header">
                <div class="panel-title" style="color:var(--amber);"><i class="fas fa-calendar-times" style="color:var(--amber);"></i> Peremptions dans les 30 jours</div>
                <span style="font-size:10.5px;color:var(--text-muted);"><?= count($peremptions) ?> lots concernes</span>
            </div>
            <table class="data-table">
                <thead><tr>
                    <th>Produit</th><th>Lot</th><th>Expire le</th><th>Stock</th><th class="r">Jours restants</th>
                </tr></thead>
                <tbody>
                <?php foreach($peremptions as $p):
                    $jours = $p['jours_restants'];
                    $badgeCls = $jours <= 7 ? 'chip-red' : ($jours <= 15 ? 'chip-amber' : 'chip-green');
                ?>
                <tr>
                    <td><?= htmlspecialchars($p['nom_commercial']) ?></td>
                    <td class="mono" style="color:var(--text-secondary);"><?= htmlspecialchars($p['numero_lot']) ?></td>
                    <td class="mono"><?= date('d/m/Y', strtotime($p['date_peremption'])) ?></td>
                    <td><?= $p['quantite_disponible'] ?> unites</td>
                    <td class="r"><span class="chip <?= $badgeCls ?>"><?= $jours ?> j.</span></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

    </div><!-- /tab-produits -->

    <!-- ════════════════════════════════════════════════════
         TAB 4 — CHARGES
    ════════════════════════════════════════════════════ -->
    <div id="tab-charges" class="tab-content">

        <div class="grid-3">
            <div class="stat-card card-red">
                <div class="card-icon icon-red"><i class="fas fa-file-invoice-dollar"></i></div>
                <div class="card-label">Total charges du jour</div>
                <div class="card-value"><?= fmt($total_charges) ?> F</div>
                <div class="card-sub"><?= count($charges_session) ?> operations enregistrees</div>
            </div>
            <div class="stat-card card-green">
                <div class="card-icon icon-green"><i class="fas fa-hand-holding-usd"></i></div>
                <div class="card-label">Resultat net estime</div>
                <div class="card-value"><?= fmt($marge_session - $total_charges) ?> F</div>
                <div class="card-sub">Marge brute - charges</div>
            </div>
            <div class="stat-card card-amber">
                <div class="card-icon icon-amber"><i class="fas fa-balance-scale"></i></div>
                <div class="card-label">Ratio charges / CA</div>
                <div class="card-value sm"><?= taux($total_charges, $stats_ventes['total_ca']) ?>%</div>
                <div class="card-sub">du chiffre d'affaires</div>
            </div>
        </div>

        <!-- Actions charges -->
        <div class="action-bar">
            <button class="btn btn-teal" onclick="ajouterCharge()"><i class="fas fa-plus"></i> Nouvelle charge</button>
        </div>

        <div class="panel">
            <div class="panel-header">
                <div class="panel-title"><i class="fas fa-receipt"></i> Charges enregistrees aujourd'hui</div>
            </div>
            <table class="data-table">
                <thead><tr>
                    <th>Heure</th><th>Libelle</th><th>Categorie</th><th>Mode reglement</th><th class="r">Montant</th>
                </tr></thead>
                <tbody>
                <?php if(empty($charges_session)): ?>
                    <tr class="empty-row"><td colspan="5">Aucune charge enregistree aujourd'hui</td></tr>
                <?php endif; ?>
                <?php foreach($charges_session as $ch): ?>
                <tr>
                    <td class="mono" style="color:var(--text-secondary);"><?= date('H:i', strtotime($ch['date_operation'])) ?></td>
                    <td><?= htmlspecialchars($ch['libelle_operation']) ?></td>
                    <td><span style="font-size:10.5px;color:var(--text-secondary);"><?= htmlspecialchars($ch['categorie'] ?? $ch['code_compte'] ?? '—') ?></span></td>
                    <td><span style="font-size:10.5px;"><?= htmlspecialchars($ch['mode_paiement'] ?? '—') ?></span></td>
                    <td class="r" style="color:var(--red);font-weight:500;">-<?= fmt($ch['montant']) ?> F</td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php if(!empty($charges_session)): ?>
            <div class="totals-row">
                <div class="total-item t-red">
                    <span class="total-label">Total charges :</span>
                    <span class="total-val">-<?= fmt($total_charges) ?> F</span>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div><!-- /tab-charges -->

    <!-- ════════════════════════════════════════════════════
         TAB 5 — RÉCAPITULATIF SESSION
    ════════════════════════════════════════════════════ -->
    <div id="tab-recap" class="tab-content">
        <div class="grid-2-1">

            <!-- Recap complet gauche -->
            <div>
                <!-- Bilan financier -->
                <div class="panel">
                    <div class="panel-header">
                        <div class="panel-title"><i class="fas fa-file-alt"></i> Bilan financier de session</div>
                        <span style="font-size:10.5px;color:var(--text-muted);">Depuis <?= date('d/m H:i', strtotime($session['date_ouverture'])) ?></span>
                    </div>
                    <div class="panel-body">
                        <?php
                        $net_session = $stats_ventes['total_ca'] - $total_charges;
                        $items_recap = [
                            ['label'=>'Fond de caisse initial', 'val'=>$session['fond_caisse_depart'], 'color'=>'var(--text-primary)'],
                            ['label'=>'(+) Ventes especes', 'val'=>$repartition_paiement['cash'], 'color'=>'var(--green)'],
                            ['label'=>'(+) Ventes Mobile Money', 'val'=>$repartition_paiement['momo'], 'color'=>'var(--blue)'],
                            ['label'=>'(+) Ventes Carte', 'val'=>$repartition_paiement['carte'], 'color'=>'var(--purple)'],
                            ['label'=>'(+) Part Assurance', 'val'=>$repartition_paiement['assurance'], 'color'=>'var(--teal)'],
                            ['label'=>'(+) Entrees manuelles', 'val'=>$total_entrees, 'color'=>'var(--blue)'],
                            ['label'=>'(-) Depenses / sorties caisse', 'val'=>-$total_sorties, 'color'=>'var(--red)'],
                            ['label'=>'(-) Remises accordees', 'val'=>-$remises_total, 'color'=>'var(--red)'],
                            ['label'=>'(-) Charges du jour', 'val'=>-$total_charges, 'color'=>'var(--red)'],
                        ];
                        ?>
                        <?php foreach($items_recap as $it): ?>
                        <div class="recap-line">
                            <span class="rl-label"><?= $it['label'] ?></span>
                            <span class="rl-val" style="color:<?= $it['color'] ?>;"><?= ($it['val'] >= 0 ? '+' : '') . fmt($it['val']) ?> F</span>
                        </div>
                        <?php endforeach; ?>
                        <div class="recap-line total">
                            <span>Solde theorique caisse</span>
                            <span class="rl-val" style="color:var(--amber);font-size:15px;"><?= fmt($solde_actuel) ?> F</span>
                        </div>
                        <div class="recap-line total" style="border-top:none;padding-top:4px;">
                            <span>Marge brute estimee</span>
                            <span class="rl-val" style="color:var(--purple);font-size:15px;"><?= fmt($marge_session) ?> F</span>
                        </div>
                        <div class="recap-line total" style="border-top:none;padding-top:4px;">
                            <span>Resultat net (Marge - Charges)</span>
                            <span class="rl-val" style="color:<?= ($marge_session - $total_charges) >= 0 ? 'var(--green)' : 'var(--red)' ?>;font-size:15px;">
                                <?= fmt($marge_session - $total_charges) ?> F
                            </span>
                        </div>
                    </div>
                </div>

                <!-- Tableau ventes session -->
                <div class="panel">
                    <div class="panel-header">
                        <div class="panel-title"><i class="fas fa-clipboard-list"></i> Synthese de l'activite ventes</div>
                    </div>
                    <div class="panel-body">
                        <?php
                        $synth = [
                            ['label'=>'Nombre de tickets', 'val'=>$stats_ventes['nb_tickets'].' tickets', 'icon'=>'fas fa-receipt'],
                            ['label'=>'CA total encaisse', 'val'=>fmt($stats_ventes['total_ca']).' F', 'icon'=>'fas fa-dollar-sign'],
                            ['label'=>'Panier moyen', 'val'=>fmt($stats_ventes['panier_moyen']).' F', 'icon'=>'fas fa-shopping-basket'],
                            ['label'=>'Remises totales', 'val'=>fmt($remises_total).' F', 'icon'=>'fas fa-tag'],
                            ['label'=>'Part assurance', 'val'=>fmt($stats_ventes['total_assurance']).' F', 'icon'=>'fas fa-shield-alt'],
                            ['label'=>'Clients identifies', 'val'=>$stats_clients['nb_clients'].' clients', 'icon'=>'fas fa-user-check'],
                            ['label'=>'Taux de marge', 'val'=>$taux_m.'%', 'icon'=>'fas fa-percent'],
                        ];
                        ?>
                        <?php foreach($synth as $s): ?>
                        <div style="display:flex;align-items:center;gap:10px;padding:6px 0;border-bottom:1px solid #f3f4f6;">
                            <div style="width:26px;height:26px;background:#f3f4f6;border-radius:6px;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                                <i class="<?= $s['icon'] ?>" style="font-size:10px;color:var(--text-muted);"></i>
                            </div>
                            <span style="flex:1;font-size:12px;color:var(--text-secondary);"><?= $s['label'] ?></span>
                            <span style="font-family:'DM Mono',monospace;font-size:12px;font-weight:500;color:var(--text-primary);"><?= $s['val'] ?></span>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <!-- Droite : actions + historique -->
            <div>
                <!-- Actions cloture -->
                <div class="panel">
                    <div class="panel-header">
                        <div class="panel-title"><i class="fas fa-lock"></i> Cloture de session</div>
                    </div>
                    <div class="panel-body">
                        <div style="text-align:center;padding:10px 0 14px;">
                            <div style="font-size:11px;color:var(--text-secondary);margin-bottom:8px;">Solde theorique actuel</div>
                            <div style="font-family:'DM Mono',monospace;font-size:28px;font-weight:500;color:var(--amber);"><?= fmt($solde_actuel) ?> F</div>
                            <div style="font-size:10.5px;color:var(--text-muted);margin-top:4px;">
                                Duree session: <?= round((time() - strtotime($session['date_ouverture'])) / 3600, 1) ?> h
                            </div>
                        </div>
                        <div style="display:flex;flex-direction:column;gap:8px;margin-top:8px;">
                            <button class="btn btn-dark" onclick="cloturerCaisse(<?= $solde_actuel ?>)" style="justify-content:center;padding:10px;">
                                <i class="fas fa-power-off"></i> Cloturer la journee
                            </button>
                            <button class="btn btn-outline" onclick="imprimerTicketSession(<?= $session['id_session'] ?>)" style="justify-content:center;">
                                <i class="fas fa-print"></i> Imprimer le rapport provisoire
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Historique clôtures -->
                <div class="panel">
                    <div class="panel-header">
                        <div class="panel-title"><i class="fas fa-history"></i> Sessions precedentes</div>
                    </div>
                    <div class="panel-body" style="padding:8px 16px;">
                    <?php if(empty($recap_clotures)): ?>
                        <p style="font-size:11.5px;color:var(--text-muted);text-align:center;padding:12px 0;">Aucune cloture anterieure</p>
                    <?php endif; ?>
                    <?php foreach($recap_clotures as $cl): ?>
                        <div class="list-item" style="flex-direction:column;align-items:flex-start;gap:4px;">
                            <div style="display:flex;justify-content:space-between;width:100%;align-items:center;">
                                <span style="font-family:'DM Mono',monospace;font-size:11px;font-weight:500;"><?= date('d/m/Y', strtotime($cl['date_cloture'])) ?></span>
                                <span style="font-family:'DM Mono',monospace;font-size:12px;font-weight:600;color:var(--green);"><?= fmt($cl['montant_final']) ?> F</span>
                            </div>
                            <div style="display:flex;gap:8px;font-size:10.5px;color:var(--text-muted);">
                                <span><?= $cl['nb_ventes'] ?> ventes</span>
                                <span>|</span>
                                <span>Marge : <?= fmt($cl['marge_brute']) ?> F</span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                    </div>
                </div>
            </div><!-- /droite -->

        </div><!-- /grid-2-1 -->
    </div><!-- /tab-recap -->

    <?php endif; ?>
    </div><!-- /page-body -->
</div><!-- /main-content -->

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<!-- ⚠️ À mettre AVANT ton script -->
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<script>

// ── TABS ──
function openTab(evt, id) {
    document.querySelectorAll('.tab-content').forEach(el => el.classList.remove('active'));
    document.querySelectorAll('.tab-btn').forEach(el => el.classList.remove('active'));
    document.getElementById(id).classList.add('active');
    evt.currentTarget.classList.add('active');
}

// ── OUVRIR CAISSE ──
function ouvrirCaisse() {
    Swal.fire({
        html: `
            <p style="font-size:13px;color:#6b7280;margin-bottom:12px;">
                Saisissez le fond de caisse initial en espèces
            </p>
            <input id="swal-fond" class="swal2-input" type="number"
                placeholder="Montant (F CFA)" min="0">
        `,
        showCancelButton: true,
        confirmButtonText: 'Ouvrir la session',
        cancelButtonText: 'Annuler',
        confirmButtonColor: '#10b981',

        preConfirm: () => {
            const v = document.getElementById('swal-fond').value;
            if (!v || v < 0) {
                Swal.showValidationMessage('Montant invalide');
                return false;
            }
            return parseFloat(v);
        }

    }).then(res => {
        if (res.isConfirmed) {
            $.post('ajax_caisse.php', {
                action: 'ouvrir',
                montant: res.value
            }, () => location.reload());
        }
    });
}

// ── MOUVEMENT (ENTRÉE / SORTIE) ──
function ajouterMouvement(type) {
    const isOut = type === 'sortie';

    Swal.fire({
        html: `
            <input id="swal-mt" class="swal2-input" type="number" placeholder="Montant (F CFA)" min="0">
            <input id="swal-mo" class="swal2-input" placeholder="Motif / description">
        `,
        showCancelButton: true,
        confirmButtonText: isOut ? 'Valider la dépense' : "Valider l'entrée",
        cancelButtonText: 'Annuler',
        confirmButtonColor: isOut ? '#ef4444' : '#10b981',

        preConfirm: () => {
            const mt = document.getElementById('swal-mt').value;
            const mo = document.getElementById('swal-mo').value;

            if (!mt || mt <= 0) {
                Swal.showValidationMessage('Montant invalide');
                return false;
            }

            return { montant: mt, motif: mo };
        }

    }).then(res => {
        if (res.isConfirmed) {
            $.post('ajax_caisse.php', {
                action: 'mouvement',
                type: type,
                montant: res.value.montant,
                motif: res.value.motif
            }, () => location.reload());
        }
    });
}

// ── AJOUTER UNE CHARGE ──
function ajouterCharge() {
    Swal.fire({
        title: 'Nouvelle charge',
        html: `
            <input id="ch-lib" class="swal2-input" placeholder="Libellé de la charge">
            <input id="ch-mt" class="swal2-input" type="number" placeholder="Montant (F CFA)" min="0">
            <select id="ch-mode" class="swal2-select">
                <option value="">-- Mode de règlement --</option>
                <option value="Especes">Espèces</option>
                <option value="Mobile Money">Mobile Money</option>
                <option value="Carte">Carte</option>
                <option value="Virement">Virement</option>
            </select>
            <input id="ch-code" class="swal2-input" placeholder="Code compte (optionnel)">
        `,
        showCancelButton: true,
        confirmButtonText: 'Enregistrer',
        cancelButtonText: 'Annuler',
        confirmButtonColor: '#0d9488',

        preConfirm: () => {
            const lib = document.getElementById('ch-lib').value.trim();
            const mt  = document.getElementById('ch-mt').value;

            if (!lib) {
                Swal.showValidationMessage('Libellé requis');
                return false;
            }

            if (!mt || mt <= 0) {
                Swal.showValidationMessage('Montant invalide');
                return false;
            }

            return {
                libelle: lib,
                montant: mt,
                mode: document.getElementById('ch-mode').value,
                code: document.getElementById('ch-code').value
            };
        }

    }).then(res => {
        if (res.isConfirmed) {
            $.post('ajax_caisse.php', {
                action: 'charge',
                libelle: res.value.libelle,
                montant: res.value.montant,
                mode_paiement: res.value.mode,
                code_compte: res.value.code
            }, () => location.reload());
        }
    });
}

// ── CLÔTURER CAISSE ──
function cloturerCaisse(theo) {
    Swal.fire({
        html: `
        <p style="font-size:12px;color:#6b7280;margin-bottom:10px;">
            Entrez le montant réel compté :
        </p>
        <input id="swal-reel" class="swal2-input" type="number"
            placeholder="Montant réel (F CFA)" min="0">
        `,
        showCancelButton: true,
        confirmButtonText: 'Générer bilan',
        cancelButtonText: 'Annuler',

        preConfirm: () => {
            const v = document.getElementById('swal-reel').value;
            if (!v || v < 0) {
                Swal.showValidationMessage('Montant invalide');
                return false;
            }
            return parseFloat(v);
        }

    }).then(res => {

        if (!res.isConfirmed) return;

        const reel  = res.value;
        const ecart = reel - theo;

        const couleur = ecart >= 0 ? '#10b981' : '#ef4444';
        const label = ecart > 0 ? 'Excédent' : (ecart < 0 ? 'Déficit' : 'Équilibre');

        Swal.fire({
            html: `
                <p><b>Théorique :</b> ${theo.toLocaleString()} F</p>
                <p><b>Réel :</b> ${reel.toLocaleString()} F</p>
                <p style="color:${couleur};font-weight:bold;">
                    ${label} : ${ecart >= 0 ? '+' : ''}${ecart.toLocaleString()} F
                </p>
            `,
            confirmButtonText: 'Confirmer la clôture',
            showCancelButton: true

        }).then(confirm => {
            if (confirm.isConfirmed) {
                $.post('ajax_caisse.php', {
                    action: 'cloturer',
                    montant_reel: reel
                }, () => location.reload());
            }
        });

    });
}

</script>
</body>
</html>