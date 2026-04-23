<?php
session_start();
require_once 'db.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

// --- RÉCUPÉRATION DES DONNÉES ---
$total_p = $pdo->query("SELECT COUNT(*) FROM produits")->fetchColumn();

// Calcul Ruptures (Winpharma Style : basé sur seuil)
$ruptures = $pdo->query("SELECT COUNT(*) FROM produits p WHERE (SELECT IFNULL(SUM(quantite_disponible), 0) FROM stocks WHERE id_produit = p.id_produit) <= p.seuil_alerte")->fetchColumn();

$categories = $pdo->query("SELECT * FROM categories ORDER BY nom_categorie ASC")->fetchAll();
$fournisseurs = $pdo->query("SELECT * FROM fournisseurs ORDER BY nom_fournisseur ASC")->fetchAll();

$produits = $pdo->query("SELECT p.*, f.nom_famille, sf.nom_sous_famille, fr.nom_fournisseur,
                         (SELECT IFNULL(SUM(quantite_disponible), 0) 
                          FROM stocks 
                          WHERE id_produit = p.id_produit) as stock_total
                         FROM produits p 
                         LEFT JOIN sous_familles sf ON p.id_sous_famille = sf.id_sous_famille
                         LEFT JOIN familles f ON sf.id_famille = f.id_famille
                         LEFT JOIN fournisseurs fr ON p.id_fournisseur_pref = fr.id_fournisseur
                         -- On ne met pas de WHERE p.actif = 1 ici pour que le JS puisse 
                         -- basculer entre actifs et archivés sans recharger la page.
                         ORDER BY p.id_produit DESC")->fetchAll();
// Lots actifs
$lots_detail = $pdo->query("SELECT s.*, p.nom_commercial, c.nom_categorie 
                            FROM stocks s
                            JOIN produits p ON s.id_produit = p.id_produit
                            LEFT JOIN categories c ON p.id_categorie = c.id_categorie
                            WHERE s.quantite_disponible > 0
                            ORDER BY s.date_peremption ASC")->fetchAll();

// --- NOUVEAU : Historique des Mouvements (Les 50 derniers) ---
$mouvements_groupes = $pdo->query("SELECT 
    p.id_produit, 
    p.nom_commercial, 
    MAX(m.date_mouvement) as derniere_date,
    SUM(CASE WHEN m.quantite > 0 THEN m.quantite ELSE 0 END) as total_entrees,
    SUM(CASE WHEN m.quantite < 0 THEN ABS(m.quantite) ELSE 0 END) as total_sorties,
    COUNT(m.id_mouvement) as nb_operations
    FROM produits p
    JOIN mouvements_stock m ON p.id_produit = m.id_produit
    GROUP BY p.id_produit, p.nom_commercial
    ORDER BY derniere_date DESC")->fetchAll();

// --- NOTIFICATIONS AMÉLIORÉES ---
$notifs = [];
$nb_perimes = 0;
$nb_proches = 0;

foreach($lots_detail as $ld) {
    $date_p = strtotime($ld['date_peremption']);
    $today = time();
    $trois_mois = strtotime('+3 months');

    if ($date_p < $today) {
        $nb_perimes++;
        $notifs[] = ["type" => "error", "title" => "PÉRIMÉ", "msg" => $ld['nom_commercial'] . " (Lot: " . $ld['numero_lot'] . ")"];
    } elseif ($date_p <= $trois_mois) {
        $nb_proches++;
        // Alerte préventive Winpharma
        $notifs[] = ["type" => "warning", "title" => "PÉREMPTION PROCHE", "msg" => $ld['nom_commercial'] . " expire bientôt."];
    }
}

// On cherche les lots qui périment dans moins de 6 mois (180 jours)
$alertes_peremption = $pdo->query("
    SELECT s.*, p.nom_commercial, DATEDIFF(s.date_peremption, CURDATE()) as jours_restants 
    FROM stocks s 
    JOIN produits p ON s.id_produit = p.id_produit 
    WHERE s.quantite_disponible > 0 
    AND s.date_peremption <= DATE_ADD(CURDATE(), INTERVAL 6 MONTH)
    ORDER BY s.date_peremption ASC
")->fetchAll();

// Requête pour détecter les produits à réapprovisionner
$sql_besoins = "
    SELECT 
        p.id_produit, 
        p.nom_commercial, 
        p.seuil_alerte,
        p.stock_max, -- Le niveau idéal à atteindre
        (SELECT IFNULL(SUM(quantite_disponible), 0) FROM stocks WHERE id_produit = p.id_produit) as stock_actuel,
        (SELECT IFNULL(SUM(ABS(quantite)), 0) / 30 FROM mouvements_stock 
         WHERE id_produit = p.id_produit AND type_mouvement = 'vente' 
         AND date_mouvement > DATE_SUB(NOW(), INTERVAL 30 DAY)) as cmj -- Consommation Moyenne Journalière sur 30 jours
    FROM produits p
    HAVING stock_actuel <= seuil_alerte
";

// Dans ajax_produits.php - Action : generer_propositions_commande
$sql = "SELECT 
            p.id_produit, p.nom_commercial, p.stock_max, p.seuil_alerte, p.id_fournisseur_pref,
            (SELECT IFNULL(SUM(quantite_disponible), 0) FROM stocks WHERE id_produit = p.id_produit) as stock_physique,
            (SELECT IFNULL(SUM(qte_commandee - qte_reçue), 0) FROM lignes_commande WHERE id_produit = p.id_produit AND statut = 'en_attente') as stock_en_route
        FROM produits p
        HAVING (stock_physique + stock_en_route) <= seuil_alerte";

 $id_commande = isset($_GET['id']) ? intval($_GET['id']) : 4;

// On récupère les infos du BC
$stmt = $pdo->prepare("SELECT c.*, f.nom_fournisseur FROM commandes c JOIN fournisseurs f ON c.id_fournisseur = f.id_fournisseur WHERE c.id_commande = ?");
$stmt->execute([$id_commande]);
$cmd = $stmt->fetch();

// On récupère les lignes prévues
$stmtL = $pdo->prepare("SELECT cl.*, p.nom_commercial, p.prix_unitaire FROM commande_lignes cl JOIN produits p ON cl.id_produit = p.id_produit WHERE cl.id_commande = ?");
$stmtL->execute([$id_commande]);
$lignes = $stmtL->fetchAll();


$where_date = "";
if(isset($_GET['debut']) && isset($_GET['fin'])) {
    $where_date = " AND m.date_mouvement BETWEEN '{$_GET['debut']} 00:00:00' AND '{$_GET['fin']} 23:59:59'";
}

$mouvements_groupes = $pdo->query("SELECT 
    p.id_produit, 
    p.nom_commercial, 
    MAX(m.date_mouvement) as derniere_date,
    SUM(CASE WHEN m.quantite > 0 THEN m.quantite ELSE 0 END) as total_entrees,
    SUM(CASE WHEN m.quantite < 0 THEN ABS(m.quantite) ELSE 0 END) as total_sorties,
    COUNT(m.id_mouvement) as nb_operations
    FROM produits p
    JOIN mouvements_stock m ON p.id_produit = m.id_produit
    WHERE 1=1 $where_date
    GROUP BY p.id_produit, p.nom_commercial
    ORDER BY derniere_date DESC")->fetchAll();
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>PharmAssist - Gestion Stock Avancée</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-EVSTQN3/azprG1Anm3QDgpJLIm9Nao0Yz1ztcQTwFspd3yD65VohhpuuCOmLASjC" crossorigin="anonymous">
<style>
/* =============================================
   PHARMASSIST — DESIGN SYSTEM UNIFIÉ v2
   Police: DM Sans (compact, pro, lisible)
   Thème: Navy sombre + Bleu acier + Blanc
============================================= */

@import url('https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600;700&display=swap');

:root {
  --primary:     #1a2535;
  --primary-mid: #22334a;
  --secondary:   #2878d8;
  --accent:      #3b9eff;
  --success:     #1a9e5f;
  --danger:      #d63540;
  --warning:     #e08a00;
  --light:       #f0f2f5;
  --white:       #ffffff;
  --border:      #d8dde6;
  --text:        #2a3347;
  --text-muted:  #7b8a9e;
  --radius:      4px;
  --shadow-sm:   0 1px 3px rgba(0,0,0,0.08);
  --shadow:      0 2px 8px rgba(0,0,0,0.1);
}

/* ── RESET & BASE ── */
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; line-height: 1.4; }

body {
  font-family: 'DM Sans', 'Segoe UI', sans-serif;
  font-size: 12px;
  background: var(--light);
  color: var(--text);
  display: flex;
}

h1 { font-size: 15px; font-weight: 700; color: var(--primary); }
h2 { font-size: 13px; font-weight: 600; color: var(--primary); }
h3 { font-size: 12px; font-weight: 600; color: var(--primary); }
h4 { font-size: 11.5px; font-weight: 600; color: var(--primary); }
h5 { font-size: 11px; font-weight: 600; }
p, label, small { font-size: 11px; }
b, strong { font-weight: 600; }

/* ── SIDEBAR ── */
.sidebar {
  width: 148px;
  min-height: 100vh;
  background: var(--primary);
  position: fixed;
  top: 0; left: 0;
  display: flex;
  flex-direction: column;
  z-index: 100;
  border-right: 1px solid rgba(255,255,255,0.06);
  transition: width 0.2s ease;
}

.sidebar-header {
  padding: 12px 10px 8px;
  border-bottom: 1px solid rgba(255,255,255,0.07);
}

.sidebar-header button {
  width: 100%;
  background: rgba(255,255,255,0.07);
  border: none;
  color: #fff;
  font-family: 'DM Sans', sans-serif;
  font-size: 11px;
  font-weight: 600;
  padding: 7px 10px;
  border-radius: var(--radius);
  cursor: pointer;
  text-align: left;
  display: flex;
  align-items: center;
  gap: 7px;
  letter-spacing: 0.3px;
  transition: background 0.15s;
}

.sidebar-header button:hover { background: rgba(255,255,255,0.12); }

.sidebar-menu {
  list-style: none;
  padding: 6px 0;
  flex: 1;
}

.sidebar-menu li a {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 8px 12px;
  font-size: 11px;
  color: rgba(255,255,255,0.75);
  text-decoration: none;
  border-left: 3px solid transparent;
  transition: all 0.15s;
  white-space: nowrap;
  overflow: hidden;
}

.sidebar-menu li a i {
  width: 14px;
  text-align: center;
  font-size: 11px;
  flex-shrink: 0;
}

.sidebar-menu li a:hover {
  background: rgba(255,255,255,0.06);
  color: #fff;
}

.sidebar-menu li a.active {
  background: rgba(40,120,216,0.18);
  border-left-color: var(--secondary);
  color: #fff;
  font-weight: 600;
}

.sidebar-menu li a[style*="ff7675"] {
  color: #ff8585 !important;
}

/* Sidebar collapsed */
.sidebar.collapsed { width: 44px; }
.sidebar.collapsed .sidebar-menu li a span { display: none; }
.sidebar.collapsed .sidebar-header h2,
.sidebar.collapsed .sidebar-header .sidebar-title { display: none; }

/* ── CONTENT ── */
.content {
  margin-left: 148px;
  padding: 14px 16px;
  width: calc(100% - 148px);
  min-height: 100vh;
  transition: margin-left 0.2s, width 0.2s;
}

.content.collapsed {
  margin-left: 44px;
  width: calc(100% - 44px);
}

/* ── PAGE HEADER (Breadcrumb zone) ── */
.page-header {
  background: var(--white);
  border: 1px solid var(--border);
  border-radius: var(--radius);
  padding: 8px 12px;
  margin-bottom: 10px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  box-shadow: var(--shadow-sm);
}

#breadcrumb {
  font-size: 10px;
  color: var(--text-muted);
  margin-bottom: 2px;
}

#main-title {
  font-size: 13px !important;
  font-weight: 700;
  color: var(--primary);
  margin: 0 !important;
}

#status-indicator {
  font-size: 10px !important;
  font-weight: 600;
  display: flex;
  align-items: center;
  gap: 5px;
}

.dot {
  height: 7px !important;
  width: 7px !important;
}

/* ── STAT CARDS ── */
.stats-row {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: 8px;
  margin-bottom: 10px;
}

.stat-card {
  background: var(--white);
  border: 1px solid var(--border);
  border-radius: var(--radius);
  padding: 10px 12px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  box-shadow: var(--shadow-sm);
  transition: box-shadow 0.15s;
}

.stat-card:hover { box-shadow: var(--shadow); }

.stat-card > div:first-child { flex: 1; }

.stat-label {
  font-size: 10px;
  font-weight: 500;
  color: var(--text-muted);
  text-transform: uppercase;
  letter-spacing: 0.4px;
  margin-bottom: 2px;
}

.stat-val {
  font-size: 18px;
  font-weight: 700;
  color: var(--primary);
  line-height: 1.1;
}

.stat-card i { font-size: 18px; opacity: 0.6; }

/* ── TAB NAVBAR ── */
.tab-navbar {
  background: var(--white);
  border: 1px solid var(--border);
  border-bottom: none;
  padding: 4px 6px;
  display: flex;
  gap: 2px;
  flex-wrap: wrap;
  box-shadow: var(--shadow-sm);
}

.dropbtn {
  background: transparent;
  border: none;
  border-radius: var(--radius);
  font-family: 'DM Sans', sans-serif;
  font-size: 11px;
  font-weight: 500;
  color: var(--text);
  padding: 6px 10px;
  cursor: pointer;
  display: flex;
  align-items: center;
  gap: 5px;
  transition: background 0.12s;
  white-space: nowrap;
  position: relative;
}

.dropbtn:hover { background: var(--light); color: var(--secondary); }
.dropbtn i { font-size: 10px; }

.nav-item-dropdown { position: relative; }

.dropdown-content {
  display: none;
  position: absolute;
  top: 100%;
  left: 0;
  background: var(--white);
  border: 1px solid var(--border);
  border-radius: 0 0 var(--radius) var(--radius);
  min-width: 190px;
  z-index: 9999;
  box-shadow: 0 4px 12px rgba(0,0,0,0.1);
  padding: 3px 0;
}

.dropdown-content a,
.dropdown-content .nav-btn {
  display: flex;
  align-items: center;
  gap: 7px;
  padding: 7px 12px;
  font-size: 11px;
  font-family: 'DM Sans', sans-serif;
  color: var(--text);
  text-decoration: none;
  border: none;
  background: none;
  cursor: pointer;
  width: 100%;
  text-align: left;
  transition: background 0.1s;
}

.dropdown-content a i,
.dropdown-content .nav-btn i {
  width: 13px;
  font-size: 10px;
  color: var(--text-muted);
}

.dropdown-content a:hover,
.dropdown-content .nav-btn:hover {
  background: var(--light);
  color: var(--secondary);
}

.dropdown-content a:hover i,
.dropdown-content .nav-btn:hover i { color: var(--secondary); }

.nav-item-dropdown:hover .dropdown-content { display: block; }

/* Séparateurs dans la navbar */
.dropbtn[style*="border-left"] { border-left: 1px solid var(--border) !important; }

/* ── BADGE NOTIFY ── */
.badge-notify {
  position: absolute;
  top: 2px;
  right: 2px;
  font-size: 8px;
  padding: 1px 4px;
  background: var(--danger);
  color: white;
  border-radius: 10px;
  font-weight: 700;
  line-height: 1.4;
}

/* ── PANELS ── */
.panel {
  display: none;
  background: var(--white);
  border: 1px solid var(--border);
  border-top: none;
  border-radius: 0 0 var(--radius) var(--radius);
  padding: 14px;
  box-shadow: var(--shadow-sm);
  animation: fadePanel 0.15s ease;
}

@keyframes fadePanel {
  from { opacity: 0; transform: translateY(3px); }
  to   { opacity: 1; transform: translateY(0); }
}

/* Panel title */
.panel-title {
  font-size: 12px;
  font-weight: 700;
  color: var(--primary);
  margin-bottom: 12px;
  padding-bottom: 8px;
  border-bottom: 2px solid var(--light);
  display: flex;
  align-items: center;
  gap: 7px;
}

.panel-title i { color: var(--secondary); }

/* ── TABLES ── */
.win-table,
.table {
  width: 100%;
  border-collapse: collapse;
  font-size: 11px;
}

.win-table thead tr,
.table thead tr {
  background: #f4f6fa !important;
}

.win-table th {
  font-size: 10px;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.5px;
  color: var(--text-muted);
  padding: 7px 9px;
  border-bottom: 2px solid var(--border);
  white-space: nowrap;
  background: #f4f6fa;
}

.win-table td {
  padding: 7px 9px;
  border-bottom: 1px solid #edf0f5;
  vertical-align: middle;
  color: var(--text);
  font-size: 11px;
}

.win-table tr:last-child td { border-bottom: none; }

.win-table tbody tr:hover { background: #f8fafd; }

/* Bootstrap table overrides */
.table th {
  font-size: 10px !important;
  font-weight: 700 !important;
  text-transform: uppercase;
  letter-spacing: 0.4px;
  color: var(--text-muted) !important;
  padding: 7px 9px !important;
  background: #f4f6fa !important;
  border-color: var(--border) !important;
}

.table td {
  font-size: 11px !important;
  padding: 7px 9px !important;
  vertical-align: middle !important;
  border-color: #edf0f5 !important;
}

.table-hover tbody tr:hover { background: #f8fafd !important; }

/* Dark thead override */
.table-dark th,
.table thead tr[style*="background: #2d3436"] th,
.table thead tr[style*="background:#2d3436"] th {
  background: var(--primary) !important;
  color: #fff !important;
  font-size: 10px !important;
}

/* ── TABLE CONTAINER ── */
.table-container {
  max-height: 420px;
  overflow-y: auto;
  border: 1px solid var(--border);
  border-radius: var(--radius);
}

/* ── FILTER BAR ── */
.filter-bar,
div[style*="background: #f8f9fa"][style*="display: flex"],
div[style*="background:#f8f9fa"][style*="display:flex"],
form.row {
  background: #f4f6fa !important;
  border: 1px solid var(--border) !important;
  border-radius: var(--radius) !important;
  padding: 10px 12px !important;
  gap: 8px !important;
  margin-bottom: 12px;
}

.filter-bar label,
div[style*="background: #f8f9fa"] label {
  font-size: 10px !important;
  font-weight: 700;
  color: var(--text-muted);
  text-transform: uppercase;
  letter-spacing: 0.3px;
  margin-bottom: 3px;
  display: block;
}

/* ── INPUTS & SELECTS ── */
input[type="text"],
input[type="number"],
input[type="date"],
input[type="email"],
select,
textarea,
.form-control,
.form-select,
.win-input {
  font-family: 'DM Sans', sans-serif !important;
  font-size: 11px !important;
  padding: 5px 8px !important;
  border: 1px solid var(--border) !important;
  border-radius: var(--radius) !important;
  color: var(--text) !important;
  background: var(--white) !important;
  width: 100%;
  transition: border-color 0.12s, box-shadow 0.12s;
  line-height: 1.4 !important;
}

input:focus,
select:focus,
textarea:focus,
.form-control:focus,
.form-select:focus {
  border-color: var(--secondary) !important;
  box-shadow: 0 0 0 2px rgba(40,120,216,0.12) !important;
  outline: none !important;
}

.form-control-sm,
.input-group-sm input,
.input-group-sm select {
  font-size: 11px !important;
  padding: 4px 7px !important;
}

/* Input group */
.input-group-text {
  font-size: 11px !important;
  padding: 4px 8px !important;
  background: #f4f6fa !important;
  border-color: var(--border) !important;
  color: var(--text-muted) !important;
}

/* ── BUTTONS ── */
.tab-btn,
.btn-action,
.btn-save {
  font-family: 'DM Sans', sans-serif;
  font-size: 11px;
  font-weight: 500;
  padding: 5px 10px;
  border-radius: var(--radius);
  cursor: pointer;
  border: 1px solid var(--border);
  background: var(--light);
  color: var(--text);
  display: inline-flex;
  align-items: center;
  gap: 5px;
  transition: all 0.12s;
  line-height: 1.4;
  white-space: nowrap;
}

.tab-btn:hover { background: #e2e7f0; border-color: #c4cbd8; }
.btn-save { background: var(--secondary); color: #fff; border-color: var(--secondary); }
.btn-save:hover { background: #1f65c0; border-color: #1f65c0; }

/* Bootstrap btn overrides */
.btn { font-family: 'DM Sans', sans-serif !important; font-size: 11px !important; }
.btn-sm { font-size: 10px !important; padding: 3px 8px !important; }
.btn-primary { background: var(--secondary) !important; border-color: var(--secondary) !important; }
.btn-success { background: var(--success) !important; border-color: var(--success) !important; }
.btn-danger  { background: var(--danger) !important;  border-color: var(--danger) !important; }
.btn-lg { font-size: 12px !important; padding: 7px 16px !important; }

/* ── BADGES ── */
.badge-type {
  font-size: 9px;
  font-weight: 700;
  padding: 2px 6px;
  border-radius: 3px;
  text-transform: uppercase;
  letter-spacing: 0.3px;
  display: inline-block;
}

.badge-cat {
  font-size: 9px;
  font-weight: 700;
  padding: 2px 6px;
  border-radius: 3px;
  background: #ebf4ff;
  color: var(--secondary);
  display: inline-block;
}

.badge-lot {
  font-size: 9px;
  font-weight: 600;
  padding: 2px 6px;
  border-radius: 3px;
  background: #f0f2f5;
  color: var(--text-muted);
  font-family: 'Courier New', monospace;
  display: inline-block;
}

/* Bootstrap badges */
.badge { font-size: 9px !important; font-weight: 700 !important; padding: 3px 7px !important; }
.bg-success { background-color: var(--success) !important; }
.bg-danger  { background-color: var(--danger)  !important; }
.bg-warning { background-color: var(--warning) !important; }

/* Statut badges custom */
.statut-badge {
  font-size: 9px !important;
  font-weight: 700;
  padding: 3px 8px !important;
  border-radius: 10px !important;
  text-transform: uppercase;
  letter-spacing: 0.3px;
}

/* ── CARDS (Bootstrap) ── */
.card { border: 1px solid var(--border) !important; border-radius: var(--radius) !important; box-shadow: var(--shadow-sm) !important; }
.card-header {
  background: var(--white) !important;
  border-bottom: 2px solid var(--light) !important;
  padding: 9px 14px !important;
  font-size: 12px !important;
  font-weight: 600 !important;
}

.card-body { padding: 12px 14px !important; }
.card-header h5 { font-size: 12px !important; margin: 0 !important; }

/* ── ALERT BOXES ── */
.alert {
  font-size: 11px !important;
  padding: 8px 12px !important;
  border-radius: var(--radius) !important;
}

.alert-info {
  background: #e8f3fd !important;
  border-color: #b8d9f8 !important;
  color: #1a4d7e !important;
}

/* ── MINI STAT BOXES dans panels ── */
div[style*="background: #fff5f5"],
div[style*="background: #fffaf0"],
div[style*="background: #f0fff4"] {
  border-radius: var(--radius) !important;
  padding: 10px 12px !important;
}

div[style*="background: #fff5f5"] span,
div[style*="background: #fffaf0"] span,
div[style*="background: #f0fff4"] span {
  font-size: 11px !important;
}

/* Grand chiffre dans mini stats */
div[style*="background: #fff5f5"] span[style*="font-size: 1.5rem"],
div[style*="background: #fffaf0"] span[style*="font-size: 1.5rem"],
div[style*="background: #f0fff4"] span[style*="font-size: 1.5rem"] {
  font-size: 22px !important;
  font-weight: 800 !important;
  line-height: 1.1 !important;
}

/* ── PROGRESS BAR ── */
.progress { height: 14px !important; border-radius: 7px !important; }
.progress-bar { font-size: 9px !important; font-weight: 700 !important; }

/* ── SEARCH RESULTS FLOATING ── */
.search-results-floating {
  position: absolute;
  background: var(--white);
  border: 1px solid var(--border);
  border-top: none;
  width: 100%;
  max-height: 200px;
  overflow-y: auto;
  z-index: 9000;
  box-shadow: var(--shadow);
  border-radius: 0 0 var(--radius) var(--radius);
}

.search-results-floating .result-item,
.result-item {
  padding: 7px 10px;
  font-size: 11px;
  cursor: pointer;
  border-bottom: 1px solid var(--light);
  transition: background 0.1s;
}

.search-results-floating .result-item:hover,
.result-item:hover { background: #eef4fd; color: var(--secondary); }

/* ── GROUP BOX (form sections) ── */
.group-box {
  background: #f8fafc;
  border: 1px solid var(--border);
  border-radius: var(--radius);
  padding: 12px;
  margin-bottom: 10px;
}

/* ── MODALS ── */
.modal-header { padding: 10px 14px !important; }
.modal-title { font-size: 13px !important; font-weight: 700 !important; }
.modal-body  { padding: 12px 14px !important; font-size: 11px !important; }
.modal-footer { padding: 8px 14px !important; }

.modal-header.bg-info { background: var(--secondary) !important; }

/* ── FICHE MODAL (custom) ── */
#modalFiche > div {
  border-radius: 6px !important;
  padding: 16px !important;
}

/* ── TABLES inside panels with inline styles ── */
/* Override paddings trop grands dans les panels */
td[style*="padding:12px"], th[style*="padding:15px"],
td[style*="padding:15px"] {
  padding: 7px 9px !important;
}

td[style*="padding:10px"], th[style*="padding:10px"] {
  padding: 6px 9px !important;
}

/* Override des font-size inline */
[style*="font-size:13px"]:not(.sidebar-menu *):not(body) { font-size: 11px !important; }
[style*="font-size: 13px"]:not(.sidebar-menu *):not(body) { font-size: 11px !important; }
[style*="font-size:14px"] { font-size: 11px !important; }
[style*="font-size: 14px"] { font-size: 11px !important; }
[style*="font-size:16px"] { font-size: 13px !important; }
[style*="font-size: 16px"] { font-size: 13px !important; }
[style*="font-size: 1.5rem"] { font-size: 1.2rem !important; }
[style*="font-size:1.5rem"]  { font-size: 1.2rem !important; }
[style*="font-size: 0.9rem"] { font-size: 10px !important; }
[style*="font-size: 0.85rem"]{ font-size: 10px !important; }
[style*="font-size: 0.8rem"] { font-size: 10px !important; }

/* h2 inline dans panels */
h2[style], h2.text-primary { font-size: 13px !important; }

/* ── AUTOPILOTE TABLE ── */
#table_autopilote,
#table_autopilote th,
#table_autopilote td {
  font-size: 11px;
}

#table_autopilote th {
  font-size: 10px;
  text-transform: uppercase;
  font-weight: 700;
  color: var(--text-muted);
  padding: 7px 9px;
  background: #f4f6fa;
  border-bottom: 2px solid var(--border);
}

/* ── PANEL ALERTES ── */
#panel-alertes-perimes .win-table th,
#panel-alertes .win-table th {
  background: #f4f6fa;
}

/* ── JOURNAL DES ACTIVITES ── */
#panel-logs table th { font-size: 10px !important; padding: 6px 9px !important; }
#panel-logs table td { font-size: 11px !important; padding: 6px 9px !important; }

/* ── INVENTAIRE ── */
#panel-inv_saisie .win-table th,
#panel-inv_validation .win-table th,
#panel-inv_historique .win-table th {
  font-size: 10px;
}

/* ── PAGE HEADER inline style override ── */
.page-header[style] {
  padding: 8px 12px !important;
  margin-bottom: 10px !important;
  font-size: 11px !important;
}

.page-header[style] h2 { font-size: 13px !important; }

/* ── ANIMATIONS ── */
@keyframes fadeIn {
  from { opacity: 0; }
  to   { opacity: 1; }
}

/* ── PRINT ── */
@media print {
  body * { visibility: hidden; }
  #print-zone, #print-zone * { visibility: visible; }
  #print-zone { position: absolute; left: 0; top: 0; width: 100%; display: block !important; }
}

/* ── RESPONSIVE ── */
@media (max-width: 1100px) {
  .stats-row { grid-template-columns: repeat(2, 1fr); }
}

/* =======================================
   DEMO PREVIEW BELOW (remove in prod)
======================================= */
.demo-wrap { padding: 20px; }
.demo-section { margin-bottom: 24px; }
.demo-label {
  font-size: 9px;
  text-transform: uppercase;
  font-weight: 700;
  letter-spacing: 1px;
  color: var(--text-muted);
  margin-bottom: 8px;
  border-bottom: 1px solid var(--border);
  padding-bottom: 4px;
}
</style>
</head>
<body>

    <div style="width:148px; background:var(--primary); min-height:100vh; position:fixed; top:0; left:0;">
  <div class="sidebar-header">
    <button><i class="fas fa-bars"></i> PharmAssist</button>
  </div>
  <ul class="sidebar-menu">
    <li><a href="#"><i class="fas fa-th-large"></i> Dashboard</a></li>
    <li><a href="#"><i class="fas fa-shopping-cart"></i> Ventes</a></li>
    <li><a href="#" class="active"><i class="fas fa-boxes"></i> Stocks</a></li>
    <li><a href="#" style="color:#ff8585;"><i class="fas fa-sign-out-alt"></i> Déconnexion</a></li>
  </ul>
</div>

    <div class="content demo-wrap">

          <div class="demo-section">
            <div class="demo-label">Gestion de Stock & Traçabilité</div>
            <div class="page-header">
              <div>
                <div id="breadcrumb"><i class="fas fa-home"></i> PharmAssist / <span>Stocks</span></div>
                <h2 id="main-title">Gestion de Stock &amp; Traçabilité</h2>
              </div>
              <div id="status-indicator">
                <span class="dot" style="background:#1a9e5f; border-radius:50%; display:inline-block;"></span>
                Système Prêt
              </div>
            </div>
          </div>

    <!-- Stat Cards -->
  <div class="demo-section">
    <div class="demo-label">Stat Cards</div>
    <div class="stats-row">
      <div class="stat-card">
        <div>
          <div class="stat-label">Produits</div>
          <div class="stat-val"><?= $total_p ?></div>
        </div>
        <i class="fas fa-pills" style="color:var(--secondary);"></i>
      </div>
      <div class="stat-card" style="border-left:3px solid var(--danger)">
        <div>
          <div class="stat-label">Ruptures / Alertes</div>
          <div class="stat-val" style="color:var(--danger)"><?= $ruptures ?></div>
        </div>
        <i class="fas fa-exclamation-circle" style="color:var(--danger);"></i>
      </div>
      <div class="stat-card">
        <div>
          <div class="stat-label">Périmés</div>
          <div class="stat-val"><?= $nb_perimes ?></div>
        </div>
        <i class="fas fa-calendar-times" style="color:var(--danger);"></i>
      </div>
      <div class="stat-card">
        <div>
          <div class="stat-label">Proches Péremption</div>
          <div class="stat-val"><?= $nb_proches ?></div>
        </div>
        <i class="fas fa-hourglass-half" style="color:var(--warning);"></i>
      </div>
    </div>
  </div>

 <div class="demo-section">
    <div class="demo-label">Navbar Dropdowns</div>
<nav class="tab-navbar">
    <div class="nav-item-dropdown">
        <button class="dropbtn"><i class="fas fa-boxes"></i> Produits <i class="fas fa-caret-down"></i></button>
        <div class="dropdown-content">
            <a href="#" onclick="showPanel('list')"><i class="fas fa-table"></i> Catalogue</a>
            <a href="#" onclick="showPanel('nouveau-produit')"><i class="fas fa-plus"></i> Nouveau Produit</a>
            <a href="#" onclick="showPanel('maj-emplacements')"><i class="fas fa-map-marker-alt"></i> Gestion Emplacements</a>
            <a href="#" onclick="showPanel('hierarchie')"><i class="fas fa-map-marker-alt"></i>Configuration des Rayons & Classes</a>
            <a href="#" onclick="showPanel('logs')"><i class="fas fa-map-marker-alt"></i>Journal des Activités</a>
        </div>
    </div>

    <div class="nav-item-dropdown">
        <button class="dropbtn" style="border-left: 1px solid #ccc;"><i class="fas fa-layer-group"></i> Stocks <span id="badge-stock-alerte" class="badge-notify" style="display:none;">0</span><i class="fas fa-caret-down"></i></button>
        <div class="dropdown-content">
            <a href="#" onclick="showPanel('view_lots')"><i class="fas fa-barcode"></i> État des Lots</a>
            <a href="#" onclick="showPanel('mouvements')"><i class="fas fa-exchange-alt"></i> Mouvements </a>
            <a href="#" onclick="visualiserRayonActuel()" style="background:#f1f2f6;"><i class="fas fa-eye"></i> Voir le Rayon</a>
            <a href="#" onclick="showPanel('alertes-perimes')" style="color:var(--danger);"><i class="fas fa-trash-alt"></i>Alertes & Périmes</a>
           <?php
// On compte les produits périmant dans moins de 3 mois pour l'alerte menu
$countAlertes = $pdo->query("SELECT COUNT(*) FROM stocks WHERE quantite_disponible > 0 AND date_peremption <= DATE_ADD(NOW(), INTERVAL 3 MONTH)")->fetchColumn();
?>

<a href="#" onclick="showPanel('alertes')" class="nav-btn">
    <i class="fas fa-exclamation-triangle"></i> Alertes 
    <?php if($countAlertes > 0): ?>
        <span style="background:#d63031; color:white; border-radius:50%; padding:2px 6px; font-size:10px; vertical-align:top;">
            <?= $countAlertes ?>
        </span>
    <?php endif; ?>
</a>
        </div>
    </div>


       <div class="nav-item-dropdown">
        <button class="dropbtn" style="border-left: 1px solid #ccc;"><i class="fas fa-truck"></i> Fournisseurs <i class="fas fa-caret-down"></i></button>
        <div class="dropdown-content">
            <a href="#" onclick="showPanel('fournisseurs')"><i class="fas fa-plus"></i> Fournisseurs</a>
        </div>
    </div>


    <div class="nav-item-dropdown">
        <button class="dropbtn" style="border-left: 1px solid #ccc;"><i class="fas fa-truck-loading"></i> Achats & Approv <i class="fas fa-caret-down"></i></button>
        <div class="dropdown-content">
            <a href="#" onclick="showPanel('autopilote'); chargerSuggestions();"><i class="fas fa-robot"></i> Autopilote</a>
 

            <a href="#"  onclick="showPanel('facture')"><i class="fas fa-file-download"></i>/Facture</a> 
            <a href="#"  onclick="showPanel('historique')"><i class="fas fa-history"></i> Historique Achats</a>
            <a href="#"  onclick="showPanel('dettes')"><i class="fas fa-money-bill-wave"></i> Dettes Fournisseurs</a>
            <a href="#"  onclick="showPanel('retours')"><i class="fas fa-undo"></i> Retour de Produits</a>
            <a href="#"  onclick="showPanel('bon-livraison')"><i class="fas fa-address-book"></i>BL</a>
            <a href="#"  onclick="showPanel('Carnet')"><i class="fas fa-list-ul"></i>BC</a>
            <!-- <a href="#"  onclick="showPanel('reception')"><i class="fas fa-address-book"></i> Réception/Contrôle</a> -->
        </div>
    </div>

    <div class="nav-item-dropdown">
        <button class="dropbtn" style="border-left: 1px solid #ccc;"><i class="fas fa-chart-line"></i> Rapports <i class="fas fa-caret-down"></i></button>
        <div class="dropdown-content">
            <a href="#" onclick="showPanel('rapport_pertes')"><i class="fas fa-file-invoice"></i> Rapport des Pertes</a>
            <a href="#" onclick="genererBilanSante()"><i class="fas fa-heartbeat"></i> Bilan de Santé</a>
        </div>
    </div>

    <div class="nav-item-dropdown">
        <button class="dropbtn" style="border-left: 1px solid #ccc;"><i class="fas fa-barcode"></i> Inventaires <i class="fas fa-caret-down"></i></button>
        <div class="dropdown-content">
            <a href="#" onclick="showPanel('inv_saisie')"><i class="fas fa-check-double"></i> Saisie Comptage</a>
            <a href="#" onclick="showPanel('inv_validation')"><i class="fas fa-archive"></i> Ajustement & Validation</a>
            <a href="#" onclick="showPanel('inv_historique')"><i class="fas fa-archive"></i> Archives Inventaire</a>
        </div>
    </div>
</nav>
</div>
        <div class="page-header" style="background: #f8f9fa; padding: 15px 20px; border-bottom: 2px solid #dee2e6; margin-bottom: 20px;">
                <div id="breadcrumb" style="font-size: 0.85rem; color: #6c757d; margin-bottom: 5px;">
                    <i class="fas fa-home"></i> PharmAssist / <span id="current-category">Gestion</span>
                </div>
                <div style="display: flex; align-items: center; justify-content: space-between;">
                    <h2 id="main-title" style="margin: 0; color: #2c3e50; font-size: 1.5rem;">Tableau de bord</h2>
                    <div id="status-indicator" style="font-size: 0.9rem; font-weight: bold;">
                        <span class="dot" style="height: 10px; width: 10px; background-color: #27ae60; border-radius: 50%; display: inline-block; margin-right: 5px;"></span>
                        Système Prêt
                    </div>
                </div>
            </div>

    <div class="panel" id="panel-fournisseurs" style="display:none;">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
            <h3><i class="fas fa-address-book"></i> Gestion des Fournisseurs</h3>
            <button onclick="ouvrirModalFournisseur()" class="tab-btn" style="background:var(--win-blue); color:white;">
                <i class="fas fa-plus"></i> Nouveau Fournisseur
            </button>
        </div>

        <table class="win-table">
            <thead>
                <tr>
                    <th>Nom du Fournisseur</th>
                    <th>Téléphone</th>
                    <th>Email</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody id="liste-fournisseurs-body">
                </tbody>
        </table>
    </div>


    <div class="panel" id="panel-logs" style="display:none;">
    <h3><i class="fas fa-history"></i> Journal des Activités</h3>
    <div style="max-height: 500px; overflow-y: auto;">
        <table style="width:100%; border-collapse: collapse;">
            <thead>
                <tr style="background:#f1f5f9; position: sticky; top: 0;">
                    <th style="padding:10px; text-align:left;">Date</th>
                    <th style="padding:10px; text-align:left;">Utilisateur</th>
                    <th style="padding:10px; text-align:left;">Action</th>
                    <th style="padding:10px; text-align:left;">Détails</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $logs = $pdo->query("SELECT * FROM logs_activites ORDER BY date_action DESC LIMIT 100")->fetchAll();
                foreach($logs as $l):
                    $color = ($l['action_type'] == 'SUPPRESSION') ? '#e74c3c' : '#2d3436';
                ?>
                <tr style="border-bottom:1px solid #eee; font-size:13px;">
                    <td style="padding:10px;"><?= date('d/m/Y H:i', strtotime($l['date_action'])) ?></td>
                    <td style="padding:10px;"><b><?= $l['utilisateur'] ?></b></td>
                    <td style="padding:10px; color:<?= $color ?>; font-weight:bold;"><?= $l['action_type'] ?></td>
                    <td style="padding:10px;"><?= htmlspecialchars($l['description']) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>



<div id="panel-mouvements" class="panel">
    <div class="card shadow-sm border-0">
        <div class="card-header bg-white py-3 d-flex align-items-center justify-content-between">
            <h5 class="mb-0 text-primary">
                <i class="fas fa-history me-2"></i> Flux de Stocks
            </h5>
            <span class="badge bg-primary-subtle text-primary" id="badge-count"></span>
        </div>
        <div class="card-body">

            <!-- Formulaire de filtrage -->
            <form id="filter-form" class="row g-2 mb-4">
                <div class="col-md-3">
                    <label class="small text-muted">Produit</label>
                    <input type="text" name="f_nom" class="form-control form-control-sm" placeholder="Nom du produit...">
                </div>
                <div class="col-md-2">
                    <label class="small text-muted">Type</label>
                    <select name="f_type" class="form-select form-select-sm">
                        <option value="">Tous les types</option>
                        <option value="entree_achat">ENTREE ACHAT</option>
                        <option value="sortie_vente">SORTIE VENTE</option>
                        <option value="casse">CASSE</option>
                        <option value="perime">PERIME</option>
                        <option value="ajustement_inventaire">INVENTAIRE</option>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="small text-muted">Periode</label>
                    <div class="input-group input-group-sm">
                        <input type="date" name="f_debut" class="form-control">
                        <span class="input-group-text">au</span>
                        <input type="date" name="f_fin" class="form-control">
                    </div>
                </div>
                <div class="col-md-3 d-flex align-items-end gap-1">
                    <button type="submit" class="btn btn-primary btn-sm flex-grow-1">
                        <i class="fas fa-filter me-1"></i> Filtrer
                    </button>
                    <div class="dropdown">
                        <button class="btn btn-outline-secondary btn-sm dropdown-toggle" type="button" data-bs-toggle="dropdown">
                            <i class="fas fa-download"></i>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <li><a class="dropdown-item" href="#" id="export-excel"><i class="fas fa-file-excel text-success me-2"></i> Excel</a></li>
                            <li><a class="dropdown-item" href="#" id="export-pdf"><i class="fas fa-file-pdf text-danger me-2"></i> PDF</a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item" href="#" id="print-table"><i class="fas fa-print me-2"></i> Imprimer</a></li>
                        </ul>
                    </div>
                </div>
            </form>

            <!-- Tableau groupé par produit -->
            <div class="table-responsive">
                <table class="table table-hover align-middle" style="font-size: 14px;">
                    <thead class="table-light">
                        <tr>
                            <th>Produit</th>
                            <th class="text-center">Nb Mouvements</th>
                            <th class="text-center text-success">Total Entrees</th>
                            <th class="text-center text-danger">Total Sorties</th>
                            <th class="text-center">Stock Actuel</th>
                            <th class="text-center">Dernier Mvt</th>
                            <th class="text-center">Detail</th>
                        </tr>
                    </thead>
                    <tbody id="body-mouvements">
                        <tr>
                            <td colspan="7" class="text-center text-muted py-4">
                                <i class="fas fa-filter me-2"></i>Appliquez un filtre pour charger les données.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

        </div>
    </div>
</div>


<!-- ====================================================
     MODAL : Détail des mouvements d'un produit
     ==================================================== -->
<div class="modal fade" id="modalDetailMouvements" tabindex="-1" aria-labelledby="modalDetailLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">

            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title" id="modalDetailLabel">
                    <i class="fas fa-list-alt me-2"></i>
                    <span id="modal-product-name">Détail des mouvements</span>
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>

            <div class="modal-body p-0">

                <!-- Résumé rapide du produit -->
                <div class="row g-0 border-bottom" id="modal-summary" style="background:#f8f9fa;">
                    <div class="col-3 text-center py-3 border-end">
                        <div class="small text-muted">Total Entrées</div>
                        <div class="fw-bold text-success fs-5" id="modal-total-entrees">—</div>
                    </div>
                    <div class="col-3 text-center py-3 border-end">
                        <div class="small text-muted">Total Sorties</div>
                        <div class="fw-bold text-danger fs-5" id="modal-total-sorties">—</div>
                    </div>
                    <div class="col-3 text-center py-3 border-end">
                        <div class="small text-muted">Nb Mouvements</div>
                        <div class="fw-bold text-primary fs-5" id="modal-nb-mvt">—</div>
                    </div>
                    <div class="col-3 text-center py-3">
                        <div class="small text-muted">Stock Actuel</div>
                        <div class="fw-bold fs-5" id="modal-stock-actuel">—</div>
                    </div>
                </div>

                <!-- Tableau des mouvements détaillés -->
                <div class="table-responsive">
                    <table class="table table-hover table-sm mb-0" style="font-size: 13px;">
                        <thead class="table-dark sticky-top">
                            <tr>
                                <th>Date</th>
                                <th>Lot</th>
                                <th>Type</th>
                                <th class="text-center">Qte Init</th>
                                <th class="text-center">Mouvement</th>
                                <th class="text-center">Qte Finale</th>
                                <th>Note</th>
                            </tr>
                        </thead>
                        <tbody id="modal-body-detail">
                            <tr>
                                <td colspan="7" class="text-center py-4">
                                    <i class="fas fa-spinner fa-spin me-2"></i> Chargement...
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

            </div>

            <div class="modal-footer">
                <small class="text-muted me-auto" id="modal-filter-info"></small>
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Fermer</button>
                <button type="button" class="btn btn-outline-primary btn-sm" id="modal-print-btn">
                    <i class="fas fa-print me-1"></i> Imprimer
                </button>
            </div>

        </div>
    </div>
</div>



<div class="panel" id="panel-alertes-perimes" style="display:none;">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
        <h3 class="panel-title" style="color: #c53030; margin: 0;">
            <i class="fas fa-exclamation-triangle"></i> Tableau de Bord des Alertes
        </h3>
        <button onclick="chargerAlertesStock()" class="btn-action" style="background:#4a5568; color:white; border:none; padding:8px 15px; border-radius:4px; cursor:pointer;">
            <i class="fas fa-sync-alt"></i> Actualiser
        </button>
    </div>

    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 25px;">
        <div style="background: #fff5f5; border: 1px solid #feb2b2; padding: 15px; border-radius: 8px;">
            <h4 style="margin:0 0 10px 0; color:#c53030;"><i class="fas fa-hourglass-end"></i> Périssables (Moins de 6 mois)</h4>
            <div id="container-perimes-liste">Chargement...</div>
        </div>
        <div style="background: #fffaf0; border: 1px solid #fbd38d; padding: 15px; border-radius: 8px;">
            <h4 style="margin:0 0 10px 0; color:#c05621;"><i class="fas fa-pills"></i> Ruptures & Stocks Faibles</h4>
            <div id="container-ruptures-liste">Chargement...</div>
        </div>
    </div>

    <table class="win-table">
        <thead>
            <tr>
                <th>Produit</th>
                <th>Lot / Emplacement</th>
                <th>Date Péremption</th>
                <th>Quantité</th>
                <th style="text-align:center;">Niveau Critique</th>
            </tr>
        </thead>
        <tbody id="tbody-alertes">
            </tbody>
    </table>
</div>



<div class="panel" id="panel-" style="display:none;">
    <h3 class="panel-title"><i class="fas fa-exchange-alt"></i> Synthèse des Flux par Produit</h3>
    <p style="font-size: 0.8rem; color: #666; margin-bottom: 10px;"><i>Astuce : Double-cliquez sur une ligne pour voir l'historique complet.</i></p>
    

        <div style="background: #f8f9fa; padding: 15px; border-radius: 8px; margin-bottom: 20px; display: flex; gap: 15px; align-items: flex-end; border: 1px solid #e2e8f0;">
            <div style="flex: 1;">
                <label style="font-size: 12px; font-weight: bold; color: #4a5568;">Date Début</label>
                <input type="date" id="flux-date-debut" class="win-input" style="width: 100%; padding: 8px;">
            </div>
            <div style="flex: 1;">
                <label style="font-size: 12px; font-weight: bold; color: #4a5568;">Date Fin</label>
                <input type="date" id="flux-date-fin" class="win-input" style="width: 100%; padding: 8px;">
            </div>
            <button onclick="filtrerFluxParPeriode()" class="btn-action" style="background: #3498db; color: white; height: 38px; padding: 0 20px; border-radius: 4px; border: none; cursor: pointer;">
                <i class="fas fa-search"></i> Filtrer
            </button>
            <button onclick="exporterFlux('pdf')" class="btn-action" style="background: #e74c3c; color: white; border: none; padding: 0 15px; border-radius: 4px; cursor: pointer; height: 38px;" title="Exporter en PDF">
                <i class="fas fa-file-pdf"></i>
            </button>
            <button onclick="exporterFlux('excel')" class="btn-action" style="background: #27ae60; color: white; border: none; padding: 0 15px; border-radius: 4px; cursor: pointer; height: 38px;" title="Exporter en Excel">
                <i class="fas fa-file-excel"></i>
            </button>
            <button onclick="location.reload()" class="btn-action" style="background: #95a5a6; color: white; height: 38px; padding: 0 15px; border-radius: 4px; border: none; cursor: pointer;">
                <i class="fas fa-sync-alt"></i>
            </button>
        </div>  

    <table class="win-table">
        <thead>
            <tr>
                <th>Produit</th>
                <th>Dernière Activité</th>
                <th style="text-align:center;">Opérations</th>
                <th style="color:green;">Total Entrées</th>
                <th style="color:red;">Total Sorties</th>
            </tr>
        </thead>
        <tbody id="tbody-mouvements">
            <?php foreach($mouvements_groupes as $m): ?>
            <tr class="row-flux" ondblclick="ouvrirDetailFlux(<?= $m['id_produit'] ?>, '<?= addslashes($m['nom_commercial']) ?>')" style="cursor:pointer;" title="Double-cliquez pour voir le détail">
                <td><strong><?= htmlspecialchars($m['nom_commercial']) ?></strong></td>
                <td style="font-size:0.85rem"><?= date('d/m/y H:i', strtotime($m['derniere_date'])) ?></td>
                <td style="text-align:center;"><span class="badge-cat"><?= $m['nb_operations'] ?> flux</span></td>
                <td style="color:green; font-weight:bold;">+ <?= $m['total_entrees'] ?></td>
                <td style="color:red; font-weight:bold;">- <?= $m['total_sorties'] ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>


    <div class="panel" id="panel-alertes" style="display:none;">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
        <h2><i class="fas fa-exclamation-triangle" style="color:#e74c3c;"></i> Alertes de Péremption</h2>
        <div style="background:#f8f9fa; padding:10px; border-radius:5px; border:1px solid #ddd;">
            <strong>Légende :</strong> 
            <span style="color:#d63031;">● Périmé</span> | 
            <span style="color:#e67e22;">● < 3 mois</span> | 
            <span style="color:#f1c40f;">● < 6 mois</span>
        </div>
    </div>

    <table style="width:100%; border-collapse: collapse; background: white; box-shadow: 0 2px 10px rgba(0,0,0,0.1);">
        <thead>
            <tr style="background: #2d3436; color:white;">
                <th style="padding:15px; text-align:left;">Produit</th>
                <th style="padding:15px; text-align:left;">N° Lot</th>
                <th style="padding:15px; text-align:center;">Date Péremption</th>
                <th style="padding:15px; text-align:center;">Jours Restants</th>
                <th style="padding:15px; text-align:right;">Stock Actuel</th>
            </tr>
        </thead>
        <tbody>
            <?php
            // On cherche les produits qui périment dans moins de 180 jours
            $sql = "SELECT s.*, p.nom_commercial, 
                           DATEDIFF(s.date_peremption, NOW()) as jours_restants 
                    FROM stocks s 
                    JOIN produits p ON s.id_produit = p.id_produit 
                    WHERE s.quantite_disponible > 0 
                    AND s.date_peremption <= DATE_ADD(NOW(), INTERVAL 6 MONTH)
                    ORDER BY s.date_peremption ASC";
            $stmt = $pdo->query($sql);
            
            while($row = $stmt->fetch()):
                $jours = $row['jours_restants'];
                $rowStyle = "";
                $dotColor = "#27ae60";

                if($jours <= 0) {
                    $rowStyle = "background:#fff5f5; color:#d63031; font-weight:bold;";
                    $dotColor = "#d63031";
                } elseif ($jours <= 90) {
                    $dotColor = "#e67e22";
                } elseif ($jours <= 180) {
                    $dotColor = "#f1c40f";
                }
            ?>
            <tr style="border-bottom: 1px solid #eee; <?= $rowStyle ?>">
                <td style="padding:12px;">
                    <span style="color:<?= $dotColor ?>;">●</span> <?= $row['nom_commercial'] ?>
                </td>
                <td style="padding:12px;"><?= $row['numero_lot'] ?></td>
                <td style="padding:12px; text-align:center;">
                    <?= date('d/m/Y', strtotime($row['date_peremption'])) ?>
                </td>
                <td style="padding:12px; text-align:center;">
                    <?php 
                        if($jours <= 0) echo "PÉRIMÉ";
                        else echo $jours . " jours";
                    ?>
                </td>
                <td style="padding:12px; text-align:right;">
                    <b><?= $row['quantite_disponible'] ?></b>
                </td>
            </tr>
            <?php endwhile; ?>
        </tbody>
    </table>
</div>


    <div class="panel" id="panel-Carnet" style="display:none;">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
        <h2><i class="fas fa-list-ul"></i> Carnet des Commandes (Bons de Commande)</h2>
        <a href="#" onclick="showPanel('autopilote'); chargerSuggestions();" class="btn" style="background:#0984e3; color:white; padding:10px 20px; text-decoration:none; border-radius:5px;">
            <i class="fas fa-plus"></i> Nouvelle Commande
        </a>
    </div>


<div style="margin-bottom: 20px; background: #f8f9fa; padding: 15px; border-radius: 8px; border: 1px solid #dee2e6; display: flex; gap: 15px; align-items: flex-end; flex-wrap: wrap;">
    
    <div style="flex: 1; min-width: 200px;">
        <label style="font-weight: bold; display: block; margin-bottom: 5px;"><i class="fas fa-search"></i> Rechercher :</label>
        <input type="text" id="recherche_commande" onkeyup="filtrerCarnet()" placeholder="Fournisseur ou N°..." style="width: 100%; padding: 8px; border-radius: 4px; border: 1px solid #ccc;">
    </div>

    <div>
        <label style="font-weight: bold; display: block; margin-bottom: 5px;"><i class="fas fa-calendar-alt"></i> Du :</label>
        <input type="date" id="date_debut" onchange="filtrerCarnet()" style="padding: 7px; border-radius: 4px; border: 1px solid #ccc;">
    </div>
    <div>
        <label style="font-weight: bold; display: block; margin-bottom: 5px;">Au :</label>
        <input type="date" id="date_fin" onchange="filtrerCarnet()" style="padding: 7px; border-radius: 4px; border: 1px solid #ccc;">
    </div>

    <div style="width: 150px;">
        <label style="font-weight: bold; display: block; margin-bottom: 5px;"><i class="fas fa-filter"></i> Statut :</label>
        <select id="filtre_statut" onchange="filtrerCarnet()" style="width: 100%; padding: 8px; border-radius: 4px; border: 1px solid #ccc;">
            <option value="tous">Tous</option>
            <option value="en_attente">EN ATTENTE</option>
            <option value="terminee">TERMINÉE</option>
            <option value="annulee">ANNULÉE</option>
        </select>
    </div>

 

    <div style="display:flex; gap:10px;">
        <button onclick="exporterExcel()" class="btn" style="background: #27ae60; color: white; padding: 9px 15px; border: none; border-radius: 4px; cursor: pointer;">
            <i class="fas fa-file-excel"></i>
        </button>
        <span id="compteur_commandes" style="background: #0984e3; color: white; padding: 9px 12px; border-radius: 4px; font-size: 0.9em; font-weight: bold; min-width: 100px; text-align: center;"></span>
    </div>
</div>

    <table style="width:100%; border-collapse: collapse; background: white; box-shadow: 0 2px 10px rgba(0,0,0,0.1);">
        <thead>
            <tr  style="background: #f1f2f6; border-bottom: 2px solid #0984e3;">
                <th style="padding:15px; text-align:left;">N° BC</th>
                <th style="padding:15px; text-align:left;">Date</th>
                <th style="padding:15px; text-align:left;">Fournisseur</th>
                <th style="padding:15px; text-align:right;">Total Estimé</th>
                <th style="padding:15px; text-align:center;">Statut</th>
                <th style="padding:15px; text-align:center;">Actions</th>
            </tr>
        </thead>
        <tbody>
    <?php
    $sql = "SELECT c.*, f.nom_fournisseur 
            FROM commandes c 
            JOIN fournisseurs f ON c.id_fournisseur = f.id_fournisseur 
            ORDER BY c.date_commande DESC";
    $stmt = $pdo->query($sql);
    while($row = $stmt->fetch()):
        $isAnnulee = ($row['statut'] == 'annulee');
        
        // Couleur selon le statut
        $color = ($row['statut'] == 'en_attente') ? '#e67e22' : '#27ae60';
        if($isAnnulee) $color = '#d63031';

        // Style de ligne si annulée
        $rowStyle = $isAnnulee ? 'background-color: #f8f9fa; color: #a0a0a0;' : 'background-color: white; color: #2d3436;';


  // On extrait juste la partie YYYY-MM-DD de la date_commande
  $dateSeule = date('Y-m-d', strtotime($row['date_commande']));

    ?>
    <tr class="ligne-commande" data-date="<?= $dateSeule ?>" style="border-bottom: 1px solid #eee; <?= $rowStyle ?>">
        <td style="padding:12px;"><b>#<?= $row['id_commande'] ?></b></td>
        <td style="padding:12px;"><?= date('d/m/Y H:i', strtotime($row['date_commande'])) ?></td>
        <td style="padding:12px;"><?= strtoupper($row['nom_fournisseur']) ?></td>
        <td style="padding:12px; text-align:right; font-weight:bold;">
            <?= number_format($row['total_prevu'], 0, '.', ' ') ?> FCFA
        </td>
        <td style="padding:12px; text-align:center;">
            <span class="statut-badge" style="background:<?= $color ?>; color:white; padding:4px 10px; border-radius:15px; font-size:0.85em;">
                <?= strtoupper($row['statut']) ?>
            </span>
        </td>
        <td style="padding:12px; text-align:center;">
    <button onclick="voirDetailsCommande(<?= $row['id_commande'] ?>)" title="Voir détails" style="border:none; background:none; color:#2d3436; cursor:pointer; margin-right:10px;">
        <i class="fas fa-eye fa-lg"></i>
    </button>

    <?php if ($row['statut'] == 'en_attente' || $row['statut'] == 'en_attente'): ?>
        <a href="generer_pdf_commande.php?id_commande=<?= $row['id_commande'] ?>" target="_blank" title="Imprimer le PDF" style="color:#0984e3; margin-right:10px;">
            <i class="fas fa-file-pdf fa-lg"></i>
        </a>

        <button onclick="ouvrirReception(<?= $row['id_commande'] ?>)" title="Réceptionner la commande" style="border:none; background:none; color:#27ae60; cursor:pointer; margin-right:10px;">
            <i class="fas fa-truck fa-lg"></i>
        </button>

        <button onclick="annulerCommande(<?= $row['id_commande'] ?>)" title="Annuler" style="border:none; background:none; color:#d63031; cursor:pointer;">
            <i class="fas fa-times-circle fa-lg"></i>
        </button>
    <?php endif; ?>

    <?php if ($row['statut'] == 'annulee'): ?>
        <i class="fas fa-ban fa-lg" style="color:#ccc;" title="Commande annulée"></i>
    <?php endif; ?>
    
    <?php if ($row['statut'] == 'terminee'): ?>
        <i class="fas fa-check-double fa-lg" style="color:#27ae60;" title="Déjà réceptionnée"></i>
    <?php endif; ?>
</td>
    </tr>
    <?php endwhile; ?>
</tbody>
    </table>
</div>


    <div class="panel" id="panel-bon-livraison" style="display:none;">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px; border-bottom:2px solid var(--win-blue); padding-bottom:10px;">
        <h3 style="color:var(--win-blue); margin:0;"><i class="fas fa-file-invoice"></i> Nouveau Bon de Livraison (BL)</h3>
        <div>
            <button onclick="imprimerBL()" class="tab-btn" style="background:#636e72; color:white;"><i class="fas fa-print"></i></button>
            <button onclick="saveBL()" class="tab-btn" style="background:var(--secondary); color:white;"><i class="fas fa-check"></i> Enregistrer le BL</button>
            <button onclick="transformerEnReception()" class="tab-btn" style="background:var(--info); color:white;">
                    <i class="fas fa-arrow-right"></i> Passer au Contrôle Qualité
            </button>
        </div>
    </div>

    <div class="row g-3" style="background:#f1f2f6; padding:20px; border-radius:8px; margin-bottom:20px;">
        <div class="col-md-3">
            <label class="small fw-bold">Réf. Fournisseur (BL N°)</label>
            <input type="text" id="bl-ref" class="form-control" placeholder="Ex: BL2024-001">
        </div>
        <div class="col-md-3">
            <label class="small fw-bold">Fournisseur</label>
            <select id="bl-fournisseur" class="form-select">
                <?php foreach($fournisseurs as $f): ?>
                    <option value="<?= $f['id_fournisseur'] ?>"><?= $f['nom_fournisseur'] ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-3">
            <label class="small fw-bold">Date du document</label>
            <input type="date" id="bl-date-doc" class="form-control" value="<?= date('Y-m-d') ?>">
        </div>
        <div class="col-md-3">
            <label class="small fw-bold">Type de transport</label>
            <select class="form-select">
                <option>Normal</option>
                <option>Chaîne de froid (2°C - 8°C)</option>
                <option>Urgent</option>
            </select>
        </div>
    </div>

    <div class="input-group mb-3">
        <span class="input-group-text bg-white"><i class="fas fa-barcode"></i></span>
        <input type="text" class="form-control" id="search-prod-bl" placeholder="Scanner un produit ou taper le nom pour l'ajouter au BL..." onkeyup="rechercheProduitBL(this.value)">
    </div>

    <table class="win-table">
        <thead style="background:var(--primary); color:white;">
            <tr>
                <th>Code/Désignation</th>
                <th width="120">Unité</th>
                <th width="120">Qté Attendue</th>
                <th width="150">P.U Achat (HT)</th>
                <th width="150">Total HT</th>
                <th width="50"></th>
            </tr>
        </thead>
        <tbody id="table-bl-items">
            </tbody>
        <tfoot>
            <tr style="background:#dfe6e9; font-weight:bold;">
                <td colspan="4" class="text-end">TOTAL GÉNÉRAL DU BL :</td>
                <td id="total-bl-ht">0.00 FCFA</td>
                <td></td>
            </tr>
        </tfoot>
    </table>
</div>

<div class="panel" id="panel-reception" style="display:none;">
    <h3><i class="fas fa-truck-loading"></i> Réception de la Commande <span id="num_commande_reception">...</span></h3>
    
    <table style="width:100%; border-collapse: collapse; margin-top:20px;" class="table table-bordered">
        <thead>
            <tr style="background:#27ae60; color:white;">
                <th style="padding:10px;color:white">Produit</th>
                <th style="padding:10px;color:white">P.A. Unit</th> 
                <th style="padding:10px;color:white">Qté Reçue</th>
                <th style="padding:10px;color:white">Sous-Total</th> 
                <th style="padding:10px;color:white">N° Lot / Péremption</th>
            </tr>
        </thead>
        <tbody id="corps_reception"></tbody>
        <tfoot style="background: #f1f2f6; border-top: 2px solid #27ae60;">
        <tr>
            <td colspan="3" style="padding:12px; text-align:right; font-weight:bold;">TOTAL FACTURE :</td>
            <td style="padding:12px; text-align:right; font-weight:bold; color:#27ae60; font-size:16px;">
                <span id="total_global_reception">0</span> F
            </td>
            <td></td>
        </tr>
    </tfoot>
    </table>

    <div style="margin-top:20px; text-align:right;">
        <button onclick="validerStock()" class="btn" style="background:#27ae60; color:white; padding:12px 25px; border:none; border-radius:5px; cursor:pointer;">
            <i class="fas fa-save"></i> Confirmer l'entrée en stock
        </button>
    </div>
</div>



<div id="panel-inv_saisie" class="panel">
        <div class="d-flex justify-content-between mb-3">
            <h3><i class="fas fa-bolt"></i> Inventaire Rapide</h3>
            <button class="btn btn-success" onclick="validerToutLInventaire()">
                <i class="fas fa-check"></i> Valider et Mettre à jour le Stock
            </button>

            <button class="btn btn-outline-danger" onclick="reinitialiserSessionInventaire()">
                <i class="fas fa-undo"></i> Tout effacer et recommencer
            </button>
        </div>

        <div class="alert alert-info d-flex justify-content-between align-items-center">
            <div>
                <i class="fas fa-tasks"></i> Progression de l'inventaire : 
                <b id="count-valides">0</b> / <span id="count-total">0</span> produits traités
            </div>
            <div class="progress w-50" style="height: 20px;">
                <div id="progress-bar-inv" class="progress-bar bg-success progress-bar-striped progress-bar-animated" 
                     role="progressbar" style="width: 0%">0%</div>
            </div>
        </div>

        <div class="input-group mb-3">
                <span class="input-group-text"><i class="fas fa-search"></i></span>
                <input type="text" id="recherche-inventaire" class="form-control" 
                       placeholder="Chercher un produit ou un numéro de lot..." onkeyup="filtrerTableauInventaire()">
            </div>

        <table class="win-table">
            <thead>
                <tr class="table-dark">
                    <th>Produit</th>
                    <th>Lot</th>
                    <th>Théorique</th>
                    <th width="120">Réel</th>
                    <th width="100">Écart</th>
                    <th width="120">Actions</th>
                </tr>
            </thead>
            <tbody id="body-inventaire-direct">
                </tbody>
        </table>
</div>

<div id="panel-inv_validation" class="panel" style="display:none;">
        <h3><i class="fas fa-sync"></i> Récapitulatif et Mise à jour du Stock</h3>
        <table class="win-table">
            <thead>
                <tr class="table-primary">
                    <th>Produit / Lot</th>
                    <th>Théorique</th>
                    <th>Réel (Modifiable)</th>
                    <th>Écart</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody id="body-validation-inventaire"></tbody>
        </table>
        <div class="text-end mt-3">
            <button class="btn btn-success btn-lg" onclick="finaliserInventaire()"><i class="fas fa-save"></i> Valider définitivement l'inventaire</button>
        </div>
</div>

<div id="panel-inv_historique" class="panel" style="display:none;">
        <h3><i class="fas fa-history"></i> Historique des Inventaires</h3>
        <table class="win-table">
            <thead>
                <tr>
                    <th>Date d'Inventaire</th>
                    <th>Type</th>
                    <th>Statut</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody id="body-historique-inventaire"></tbody>
        </table>
</div>

<div class="modal fade" id="modalSaisieInventaire" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header"><h5>Comptage : <span id="modal-titre-produit"></span></h5></div>
            <div class="modal-body">
                <table class="table table-bordered">
                    <thead>
                        <tr>
                            <th>Lot / Péremption</th>
                            <th>Théorique</th>
                            <th>Quantité Réelle</th>
                            <th>Écart</th>
                        </tr>
                    </thead>
                    <tbody id="body-lots-inventaire"></tbody>
                </table>
            </div>
            <div class="modal-footer">
                <button class="btn btn-primary" onclick="enregistrerSaisieTemporaire()">Ajouter au récapitulatif</button>
            </div>
        </div>
    </div>
</div>

        <div class="panel mt-4" id="panel-dettes">
        <table class="win-table">
            <thead>
                <tr class="table-dark">
                    <th>Fournisseur</th>
                    <th>N° Facture</th>
                    <th>Date Achat</th>
                    <th>Échéance</th>
                    <th>Total</th>
                    <th>Déjà Payé</th>
                    <th>Reste à Payer</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody id="liste-dettes">
                </tbody>
        </table>
</div>


<div id="panel-historique" class="panel mt-4">
    <div class="card shadow-sm border-0">
        <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
            <h5 class="mb-0 text-primary">
                <i class="fas fa-boxes me-2"></i> Historique des Achats par Produit
            </h5>
            <span class="badge bg-secondary" id="achats-count-badge">-- produits</span>
        </div>

        <div class="card-body">

            <!-- ── Barre de filtres principale ── -->
            <form id="filter-achats-form" class="row g-2 mb-3">
                <!-- Recherche live -->
                <div class="col-md-4">
                    <div class="input-group input-group-sm">
                        <span class="input-group-text"><i class="fas fa-search"></i></span>
                        <input type="text" id="search-produit-achats"
                               name="f_nom" class="form-control"
                               placeholder="Rechercher un produit...">
                        <button type="button" class="btn btn-outline-secondary" id="clear-search-achats">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                </div>

                <!-- Fournisseur -->
                <div class="col-md-3">
                    <select name="f_fournisseur" id="f_fournisseur_achats" class="form-select form-select-sm">
                        <option value="">Tous les fournisseurs</option>
                        <!-- Chargé dynamiquement -->
                    </select>
                </div>

                <!-- Période globale -->
                <div class="col-md-4">
                    <div class="input-group input-group-sm">
                        <input type="date" name="f_debut" id="f_debut_achats" class="form-control">
                        <span class="input-group-text">au</span>
                        <input type="date" name="f_fin" id="f_fin_achats" class="form-control">
                    </div>
                </div>

                <!-- Boutons -->
                <div class="col-md-1 d-flex gap-1">
                    <button type="submit" class="btn btn-primary btn-sm flex-grow-1">
                        <i class="fas fa-filter"></i>
                    </button>
                    <button type="button" class="btn btn-outline-secondary btn-sm" id="reset-filter-achats" title="Réinitialiser">
                        <i class="fas fa-redo"></i>
                    </button>
                </div>
            </form>

            <!-- ── Table groupée par produit ── -->
            <div class="table-responsive">
                <table class="table table-hover align-middle" style="font-size:13.5px;">
                    <thead class="table-light">
                        <tr>
                            <th>Produit</th>
                            <th>Fournisseur pref.</th>
                            <th class="text-center">Nb Achats</th>
                            <th class="text-center">Qte Totale</th>
                            <th class="text-center">Montant Total</th>
                            <th class="text-center">Prix Moy. Unitaire</th>
                            <th class="text-center">Dernier Achat</th>
                            <th class="text-center">Action</th>
                        </tr>
                    </thead>
                    <tbody id="body-achats-grouped">
                        <tr>
                            <td colspan="8" class="text-center text-muted py-4">
                                <i class="fas fa-spinner fa-spin me-2"></i> Chargement...
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

        </div><!-- /card-body -->
    </div><!-- /card -->
</div>


<!-- Modal Edition Ligne Achat -->
<div class="modal fade" id="modalEditAchat" tabindex="-1" aria-labelledby="modalEditAchatLabel" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-scrollable">
    <div class="modal-content">

      <div class="modal-header bg-primary text-white">
        <h5 class="modal-title" id="modalEditAchatLabel">
          <i class="fas fa-edit me-2"></i>
          Modifier les lignes d'achat — <span id="modal-produit-nom"></span>
        </h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>

      <div class="modal-body p-0">

        <!-- Alerte résultat -->
        <div id="edit-achat-alert" class="alert m-3 d-none" role="alert"></div>

        <!-- Tableau des lignes -->
        <div class="table-responsive">
          <table class="table table-bordered align-middle mb-0" style="font-size:13px;">
            <thead class="table-light sticky-top">
              <tr>
                <th>N° Facture</th>
                <th>Date Achat</th>
                <th>Fournisseur</th>
                <th class="text-center">Lot (stock)</th>
                <th class="text-center" style="width:130px;">Qte Recue</th>
                <th class="text-center" style="width:150px;">Prix Achat Unit.</th>
                <th class="text-center">Peremption</th>
                <th class="text-center" style="width:90px;">Action</th>
              </tr>
            </thead>
            <tbody id="body-edit-achat-lignes">
              <tr>
                <td colspan="8" class="text-center py-4 text-muted">
                  <i class="fas fa-spinner fa-spin me-1"></i> Chargement...
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <!-- Légende -->
        <div class="p-3 border-top bg-light">
          <small class="text-muted">
            <i class="fas fa-info-circle text-primary me-1"></i>
            Modifier une <strong>quantite</strong> met a jour le stock disponible (difference).
            Modifier un <strong>prix</strong> met a jour le lot stock et recalcule le montant total de la commande.
          </small>
        </div>

      </div><!-- /modal-body -->

      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
          <i class="fas fa-times me-1"></i> Fermer
        </button>
      </div>

    </div>
  </div>
</div>

<!-- ============================================================
     MODAL DÉTAIL ACHATS PAR PRODUIT
============================================================ -->
<div class="modal fade" id="modalDetailAchats" tabindex="-1" aria-labelledby="modalDetailAchatsLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">

            <!-- En-tête -->
            <div class="modal-header bg-primary text-white">
                <div>
                    <h5 class="modal-title mb-0" id="modalDetailAchatsLabel">
                        <i class="fas fa-history me-2"></i>
                        <span id="modal-produit-nom">--</span>
                    </h5>
                    <small class="opacity-75" id="modal-produit-molecule"></small>
                </div>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>

            <!-- Stats rapides -->
            <div class="modal-body pb-0">
                <div class="row g-2 mb-3" id="modal-stats-row">
                    <div class="col-6 col-md-3">
                        <div class="border rounded p-2 text-center bg-light">
                            <div class="fw-bold text-primary fs-5" id="stat-nb-factures">--</div>
                            <div class="small text-muted">Factures</div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="border rounded p-2 text-center bg-light">
                            <div class="fw-bold text-success fs-5" id="stat-qte-totale">--</div>
                            <div class="small text-muted">Qté Totale</div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="border rounded p-2 text-center bg-light">
                            <div class="fw-bold text-warning fs-5" id="stat-montant-total">--</div>
                            <div class="small text-muted">Montant Total</div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="border rounded p-2 text-center bg-light">
                            <div class="fw-bold text-info fs-5" id="stat-prix-moyen">--</div>
                            <div class="small text-muted">Prix Moy. Unit.</div>
                        </div>
                    </div>
                </div>

                <!-- Filtres dans le modal -->
                <form id="modal-filter-form" class="row g-2 mb-3">
                    <input type="hidden" id="modal-id-produit" name="id_produit" value="">

                    <div class="col-md-4">
                        <div class="input-group input-group-sm">
                            <input type="date" name="f_debut" id="modal-f-debut" class="form-control">
                            <span class="input-group-text">au</span>
                            <input type="date" name="f_fin" id="modal-f-fin" class="form-control">
                        </div>
                    </div>
                    <div class="col-md-3">
                        <select name="f_statut" class="form-select form-select-sm">
                            <option value="">Tous statuts paiement</option>
                            <option value="paye">Payé</option>
                            <option value="partiel">Partiel</option>
                            <option value="impaye">Impayé</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <select name="f_mode" class="form-select form-select-sm">
                            <option value="">Tous modes règlement</option>
                            <option value="especes">Espèces</option>
                            <option value="cheque">Chèque</option>
                            <option value="virement">Virement</option>
                            <option value="mobile_money">Mobile Money</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <button type="submit" class="btn btn-primary btn-sm w-100">
                            <i class="fas fa-filter me-1"></i> Filtrer
                        </button>
                    </div>
                </form>
            </div>

            <!-- Table détail -->
            <div class="modal-body pt-0">
                <div class="table-responsive">
                    <table class="table table-hover table-sm align-middle" style="font-size:13px;">
                        <thead class="table-dark">
                            <tr>
                                <th>Date</th>
                                <th>N° Facture</th>
                                <th>Fournisseur</th>
                                <th class="text-center">Qté Reçue</th>
                                <th class="text-center">Prix Unit. Achat</th>
                                <th class="text-center">Sous-Total</th>
                                <th>N° Lot</th>
                                <th>Date Péremption</th>
                                <th class="text-center">Statut</th>
                                <th class="text-center">Mode Règl.</th>
                            </tr>
                        </thead>
                        <tbody id="body-detail-achats">
                            <tr>
                                <td colspan="10" class="text-center py-3">
                                    <i class="fas fa-spinner fa-spin"></i> Chargement...
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Fermer</button>
            </div>

        </div><!-- /modal-content -->
    </div>
</div>

        <div class="panel" id="panel-retours">
        <div class="row mb-3">
            <div class="col-md-6">
                <label>Rechercher le lot à retourner :</label>
                <input type="text" id="search-lot" class="form-control" placeholder="Scanner le lot ou taper le nom du produit..." onkeyup="chercherLotPourRetour(this.value)">
                <div id="res-search-lot" class="search-results-floating"></div>
            </div>
        </div>

        <table class="win-table" id="table-retour">
            <thead>
                <tr class="table-dark">
                    <th>Produit / Lot</th>
                    <th>Stock Actuel</th>
                    <th>Quantité à Retourner</th>
                    <th>Motif</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody id="body-retour"></tbody>
        </table>
        
        <div class="text-end mt-3">
            <button class="btn btn-danger btn-lg" onclick="validerTousLesRetours()">
                <i class="fas fa-shipping-fast"></i> Confirmer l'expédition du retour
            </button>
        </div>
    </div>

        <div id="panel-facture" class="panel">
        <form id="form-reception">
            <div class="row mb-4">
                <div class="col-md-4">
                    <label>Fournisseur</label>
                    <select class="form-select" name="id_fournisseur" id="id_fournisseur" required>
                        <option value="">-- Sélectionner Fournisseur --</option>
                        <?php 
                        $res = $pdo->query("SELECT id_fournisseur, nom_fournisseur FROM fournisseurs");
                        while($f = $res->fetch()) echo "<option value='{$f['id_fournisseur']}'>{$f['nom_fournisseur']}</option>";
                        ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label>N° Facture / BL</label>
                    <input type="text" class="form-control" name="num_facture" id="num_facture" required>
                </div>
                <div class="col-md-4">
                    <label>Date Réception</label>
                    <input type="date" class="form-control" name="date_achat" id="date_achat" value="<?= date('Y-m-d') ?>">
                </div>

                <div class="row mt-4 p-3" style="background: #f1f2f6; border-radius: 8px;">
                    <div class="col-md-3">
                        <label><b>Mode de Règlement</b></label>
                        <select class="form-select" name="mode_reglement" id="mode_reglement" onchange="gererTypePaiement()">
                            <option value="comptant">Comptant (Espèces/Chèque)</option>
                            <option value="credit">Crédit Fournisseur</option>
                            <option value="partiel">Paiement Partiel</option>
                        </select>
                    </div>
                    
                    <div class="col-md-3" id="div-montant-paye">
                        <label><b>Montant Versé</b></label>
                        <input type="number" class="form-control" name="montant_verse" id="montant_verse" value="0">
                    </div>

                    <div class="col-md-3" id="div-echeance" style="display:none;">
                        <label><b>Date d'échéance</b></label>
                        <input type="date" class="form-control" name="date_echeance">
                    </div>
</div>
            </div>

            <div style="position: relative;" class="mb-4">
                <label><b>Rechercher un produit à ajouter :</b></label>
                <input type="text" id="search-prod" class="form-control" placeholder="Scanner ou taper le nom..." onkeyup="rechercherProduitAchat(this.value)">
                <div id="res-search" class="search-results-floating"></div>
            </div>

            <table class="win-table" id="table-items">
                <thead>
                    <tr class="table-dark">
                        <th>Désignation</th>
                        <th>N° Lot</th>
                        <th>Péremption</th>
                        <th>Dernier P.A (Brt)</th> 
                        <th>Qté (Brt)</th>
                        <th>Nouveau P.A (Brt)</th>
                        <th>Total</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody></tbody>
                <tfoot>
                    <tr class="table-light">
                        <td colspan="5" class="text-end"><strong>MONTANT TOTAL FACTURE :</strong></td>
                        <td id="grand-total" class="fw-bold text-primary">0</td>
                        <td class="fw-bold text-primary">FCFA</td>
                    </tr>
                </tfoot>
            </table>

            <div class="text-end mt-4">
                <button type="button" class="btn btn-success btn-lg" onclick="finaliserAchat()">
                    <i class="fas fa-check-circle"></i> Valider l'Entrée en Stock
                </button>
            </div>
        </form>
    </div>


   <div class="panel" id="panel-hierarchie" style="display:none;">
    <h3 style="color:var(--primary)"><i class="fas fa-sitemap"></i> Configuration des Rayons & Classes</h3>
    
    <div style="display:grid; grid-template-columns: 1fr 1fr; gap:30px;">
        <div style="background:#f8f9fa; padding:20px; border-radius:8px; border:1px solid #ddd;">
            <h4>1. Familles (Niveau 1)</h4>
            <div style="display:flex; gap:10px; margin-bottom:15px;">
                <input type="text" id="new-famille-nom" placeholder="Nom Famille (ex: Parapharmacie)" class="swal2-input" style="margin:0; flex-grow:1;">
                <button onclick="ajouterFamille()" class="tab-btn" style="background:var(--secondary); color:white;"><i class="fas fa-plus"></i></button>
            </div>
            <ul id="liste-familles-config" style="list-style:none; padding:0; max-height:300px; overflow-y:auto;">
                </ul>
        </div>

        <div style="background:#f8f9fa; padding:20px; border-radius:8px; border:1px solid #ddd;">
            <h4>2. Sous-Familles (Niveau 2)</h4>
            <div style="margin-bottom:15px;">
                <select id="select-famille-pour-sf" class="swal2-input" style="margin:0; width:100%; margin-bottom:10px;">
                    <option value="">-- Choisir Famille Cible --</option>
                </select>
                <div style="display:flex; gap:10px;">
                    <input type="text" id="new-sf-nom" placeholder="Nom Sous-Famille (ex: Solaire)" class="swal2-input" style="margin:0; flex-grow:1;">
                    <button onclick="ajouterSousFamille()" class="tab-btn" style="background:var(--win-blue); color:white;"><i class="fas fa-plus"></i></button>
                </div>
            </div>
            <ul id="liste-sf-config" style="list-style:none; padding:0; max-height:300px; overflow-y:auto;">
                <li style="color:#7f8c8d; font-style:italic;">Sélectionnez une famille pour voir ses classes.</li>
            </ul>
        </div>
    </div>
</div>

<div class="panel" id="panel-ajustement-inventaire" style="display:none;">
    <h3><i class="fas fa-adjust"></i> Inventaire Tournant / Ajustement</h3>
    <div class="group-box">
        <label>Scanner ou Rechercher le produit :</label>
        <input type="text" id="inv-search" onkeyup="rechercherProduitInv(this.value)" placeholder="Nom ou Code-barres...">
        <div id="inv-results" class="search-results-floating"></div>
    </div>
    
    <div id="inv-details" style="display:none; margin-top:20px;">
        <table class="win-table">
            <thead>
                <tr>
                    <th>Lot</th>
                    <th>Stock Théorique</th>
                    <th>Stock Réel (Physique)</th>
                    <th>Écart</th>
                </tr>
            </thead>
            <tbody id="inv-body-lots"></tbody>
        </table>
        <button onclick="validerAjustement()" class="btn-save" style="margin-top:10px;">
            <i class="fas fa-check"></i> Enregistrer l'ajustement
        </button>
    </div>
</div>

<div class="panel" id="panel-gestion-commandes">
    <div style="background:#0984e3; color:white; padding:15px; border-radius:8px 8px 0 0;">
        <h3 style="margin:0;"><i class="fas fa-list-ul"></i> Commandes en cours</h3>
    </div>
    <table class="win-table">
        <thead>
            <tr>
                <th>N° Commande</th>
                <th>Fournisseur</th>
                <th>Date Envoi</th>
                <th>Statut</th>
                <th>Action</th>
            </tr>
        </thead>
        <tbody id="liste-commandes-en-cours">
            </tbody>
    </table>
</div>
<input type="hidden" id="id_commande_active" value="">
<div id="zone-details-commande" style="display:none; margin-top:20px; border:2px solid #0984e3; padding:15px; border-radius:8px;">
    <h4 id="titre-detail-commande">Détails de la Commande</h4>
    <table class="win-table">
        <thead>
            <tr>
                <th>Produit</th>
                <th>Qté Commandée</th>
                <th>Numéro de Lot</th>
                <th>Péremption</th>
            </tr>
        </thead>
        <tbody id="corps-detail-commande"></tbody>
    </table>
    <div style="margin-top:15px; display:flex; gap:10px; justify-content:flex-end;">
        <button onclick="validerTouteLaReception()" class="tab-btn" style="background:#27ae60; color:white;"><i class="fas fa-check"></i> Valider la réception</button>
        <button onclick="annulerCommande()" class="tab-btn" style="background:#e74c3c; color:white;"><i class="fas fa-times"></i> Annuler la commande</button>
    </div>
</div>

<div class="panel" id="panel-list">
    <h3 class="panel-title"><i class="fas fa-boxes"></i> Catalogue Général</h3>

    <div class="filter-bar" style="background: #f8f9fa; padding: 15px; border-radius: 8px; margin-bottom: 20px; display: flex; gap: 15px; align-items: flex-end; border: 1px solid #e2e8f0;">
        
        <div style="flex: 2;">
            <label style="font-size: 12px; font-weight: bold; color: #4a5568;"><i class="fas fa-search"></i> Recherche Rapide</label>
            <input type="text" id="filter-name" class="win-input" placeholder="Nom commercial, molécule..." onkeyup="appliquerFiltres()" style="width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px;">
        </div>

        <div style="flex: 1;">
            <label style="font-size: 12px; font-weight: bold; color: #4a5568;"><i class="fas fa-filter"></i> Famille</label>
            <select id="filter-family" class="win-input" onchange="appliquerFiltres()" style="width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px;">
                <option value="">Toutes les familles</option>
                <?php 
                $all_fams = $pdo->query("SELECT nom_famille FROM familles ORDER BY nom_famille")->fetchAll();
                foreach($all_fams as $f): ?>
                    <option value="<?= htmlspecialchars($f['nom_famille']) ?>"><?= htmlspecialchars($f['nom_famille']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div style="flex: 1;">
            <label style="font-size: 12px; font-weight: bold; color: #4a5568;"><i class="fas fa-map-marker-alt"></i> Emplacement</label>
            <select id="filter-location" class="win-input" onchange="appliquerFiltres()" style="width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px;">
                <option value="">Tous les rayons</option>
                <?php 
                // Extraction des emplacements uniques de la liste des produits
                $emplacements_uniques = array_unique(array_column($produits, 'emplacement'));
                sort($emplacements_uniques);
                foreach($emplacements_uniques as $emp): if(!$emp) continue; ?>
                    <option value="<?= htmlspecialchars($emp) ?>"><?= htmlspecialchars($emp) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <button onclick="resetFiltres()" class="btn-action" style="background: #95a5a6; color: white; height: 38px; padding: 0 15px; border-radius: 4px; border: none; cursor: pointer;" title="Réinitialiser">
            <i class="fas fa-sync-alt"></i>
        </button>

       <div style="flex: 1; min-width: 150px; display: flex; align-items: flex-end; gap: 10px;">
    <div style="flex: 1;">
        <label style="font-size: 12px; font-weight: bold; color: #4a5568;"><i class="fas fa-eye"></i> Affichage</label>
        <select id="filter-archive" class="win-input" onchange="basculerVueArchive()" style="width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px; background: #fffbe6;">
            <option value="1">Produits Actifs</option>
            <option value="0" id="option-archive-texte">Produits Archivés (0)</option>
        </select>
    </div>
    
    <button id="btn-print-archive" onclick="imprimerArchives()" class="btn-action" style="display: none; background: #636e72; color: white; height: 38px; padding: 0 15px; border-radius: 4px; border: none; cursor: pointer;" title="Imprimer les archives">
        <i class="fas fa-print"></i>
    </button>
</div>
    </div>

    <table class="win-table" style="width: 100%; border-collapse: collapse;">
        <thead>
            <tr style="background: #f1f5f9; text-align: left;">
                <th style="padding: 12px; border-bottom: 2px solid #cbd5e0;">Désignation</th>
                <th style="padding: 12px; border-bottom: 2px solid #cbd5e0;">Catégorie & Classe</th>
                <th style="padding: 12px; border-bottom: 2px solid #cbd5e0;">Prix Vente</th>
                <th style="padding: 12px; border-bottom: 2px solid #cbd5e0;">Stock Total</th>
                <th style="padding: 12px; border-bottom: 2px solid #cbd5e0;">Statut</th>
                <th style="padding: 12px; border-bottom: 2px solid #cbd5e0;">Fournisseur Préféré</th>
                <th style="padding: 12px; border-bottom: 2px solid #cbd5e0; text-align: center;">Actions</th>
            </tr>
        </thead>
       <tbody id="table-produits-body">
    <?php 
    $all_fournisseurs = $pdo->query("SELECT id_fournisseur, nom_fournisseur FROM fournisseurs ORDER BY nom_fournisseur ASC")->fetchAll();
    
    foreach($produits as $p): 
        // --- LOGIQUE DE DÉCONDITIONNEMENT ---
        $stock_total = intval($p['stock_total']);
        $coef = intval($p['coefficient_division'] ?? 1);
        $est_detail = $p['est_detail'] ?? 0;

        // Calcul des boîtes entières et des unités restantes
        if ($est_detail == 1 && $coef > 1) {
            $nb_boites = floor($stock_total / $coef);
            $unites_restantes = $stock_total % $coef;
            
            // Formatage de l'affichage
            $display_stock = "<strong>$nb_boites</strong> <small>Btes</small>";
            if ($unites_restantes > 0) {
                $display_stock .= " + <strong>$unites_restantes</strong> <small>Unité(s)</small>";
            }
        } else {
            $display_stock = "<strong>$stock_total</strong>";
        }

        // --- LOGIQUE DE STATUT ---
        $status = "Normal"; 
        $color = "#2c3e50"; 
        $seuil = intval($p['seuil_alerte'] ?? 0);

        if($stock_total <= 0) { 
            $status = "Rupture"; $color = "#e74c3c"; 
        } elseif($stock_total <= $seuil) { 
            $status = "Alerte"; $color = "#f39c12"; 
        }
    ?>
    <tr class="searchable-row" 
        data-nom="<?= strtolower(htmlspecialchars($p['nom_commercial'])) ?>" 
        data-famille="<?= htmlspecialchars($p['nom_famille'] ?? 'N/A') ?>" 
        data-emplacement="<?= htmlspecialchars($p['emplacement'] ?? 'Non classé') ?>"
        data-actif="<?= $p['actif'] ?>"
        style="display: <?= ($p['actif'] == 1) ? 'table-row' : 'none' ?>; 
           border-bottom: 1px solid #edf2f7;
           <?= ($p['actif'] == 0) ? 'opacity: 0.7; background-color: #fcfcfc;' : '' ?>">
        
        <td style="padding: 12px;">
            <strong><?= htmlspecialchars($p['nom_commercial']) ?></strong><br>
            <small style="color: #7f8c8d;"><i class="fas fa-map-marker-alt"></i> <?= htmlspecialchars($p['emplacement'] ?? 'Non classé') ?></small>
        </td>

        <td style="padding: 12px;">
            <span class="badge-cat" style="background: #ebf8ff; color: #2b6cb0; padding: 2px 8px; border-radius: 4px; font-size: 11px; font-weight: bold;">
                <?= htmlspecialchars($p['nom_famille'] ?? 'N/A') ?>
            </span><br>
            <small style="color: #7f8c8d;"><?= htmlspecialchars($p['nom_sous_famille'] ?? '---') ?></small>
        </td>

        <td style="padding: 12px; font-weight: bold;">
            <?= number_format($p['prix_unitaire'], 0, '.', ' ') ?> <em> (<?= number_format($p['prix_unitaire_detail'], 0, '.', ' ') ?>) <em> <small>FCFA</small>
        </td>

        <td style="padding: 12px; color: <?= $color ?>; line-height: 1.2;">
            <?= $display_stock ?>
        </td>

        <td style="padding: 12px;">
            <span style="font-weight: bold; color: <?= $color ?>; border: 1px solid <?= $color ?>; padding: 2px 6px; border-radius: 4px; font-size: 10px; text-transform: uppercase;">
                <?= $status ?>
            </span>
        </td>

        <td style="padding: 12px;">
            <select onchange="changerFournisseur(<?= $p['id_produit'] ?>, this.value)" 
                    style="padding: 5px; border-radius: 4px; border: 1px solid #ddd; width: 100%; font-size: 12px;">
                <option value="">-- Aucun --</option>
                <?php foreach ($all_fournisseurs as $f): ?>
                    <option value="<?= $f['id_fournisseur'] ?>" <?= ($p['id_fournisseur_pref'] == $f['id_fournisseur']) ? 'selected' : '' ?>>
                        <?= htmlspecialchars($f['nom_fournisseur']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </td>

            <td style="padding: 12px; white-space: nowrap; text-align: center;">
                <button onclick="viewLots(<?= $p['id_produit'] ?>, '<?= addslashes($p['nom_commercial']) ?>')" 
                        class="btn-action" style="color:#3498db; background:none; border:none; cursor:pointer;" title="Voir Stocks/Lots">
                    <i class="fas fa-eye"></i>
                </button>

                <button onclick="ouvrirModifProduit(<?= htmlspecialchars(json_encode($p)) ?>)" 
                        class="btn-action" style="color:#2ecc71; background:none; border:none; cursor:pointer;" title="Modifier">
                    <i class="fas fa-edit"></i>
                </button>

               <button onclick="supprimerProduit(<?= $p['id_produit'] ?>, '<?= addslashes($p['nom_commercial']) ?>', <?= $stock_total ?>)" 
                        class="btn-action" style="color:#e74c3c; background:none; border:none; cursor:pointer;" title="Supprimer">
                    <i class="fas fa-trash-alt"></i>
                </button>

                <button onclick="archiverProduit(<?= $p['id_produit'] ?>, '<?= addslashes($p['nom_commercial']) ?>')" 
                        class="btn-action" style="color:#f39c12; background:none; border:none; cursor:pointer;" title="Archiver">
                    <i class="fas fa-archive"></i>
                </button>
            </td>
    </tr>
    <?php endforeach; ?>
</tbody>
    </table>
</div>



<script>
/**
 * FILTRAGE DYNAMIQUE JS
 * Parcourt les lignes du tableau sans recharger la page
 */
function appliquerFiltres() {
    const nameVal = document.getElementById('filter-name').value.toLowerCase();
    const famVal = document.getElementById('filter-family').value;
    const locVal = document.getElementById('filter-location').value;

    $('.searchable-row').each(function() {
        const rowNom = $(this).data('nom');
        const rowFam = $(this).data('famille');
        const rowLoc = $(this).data('emplacement');

        const matchNom = rowNom.includes(nameVal);
        const matchFam = (famVal === "" || rowFam === famVal);
        const matchLoc = (locVal === "" || rowLoc === locVal);

        if (matchNom && matchFam && matchLoc) {
            $(this).show();
        } else {
            $(this).hide();
        }
    });
}

function resetFiltres() {
    document.getElementById('filter-name').value = "";
    document.getElementById('filter-family').value = "";
    document.getElementById('filter-location').value = "";
    $('.searchable-row').show();
}
</script>

        <div class="panel" id="panel-maj-emplacements" style="display:none;">
    <div class="alert-info" style="background:#e8f4fd; padding:15px; border-left:5px solid #3498db; margin-bottom:20px;">
        <i class="fas fa-info-circle"></i> <strong>Mode Saisie Rapide :</strong> Scannez ou sélectionnez les produits, puis définissez leur nouvel emplacement cible.
    </div>

    <div style="display:grid; grid-template-columns: 1fr 350px; gap:20px;">
        <div style="background:white; padding:20px; border:1px solid #ddd; border-radius:8px;">
            <h4>1. Produits sélectionnés</h4>
            <div style="margin-bottom:15px;">
                <select id="recherche-maj-prod" class="swal2-input" style="width:100%;">
                    <option value="">Scanner ou rechercher un produit...</option>
                    <?php foreach($produits as $p): ?>
                        <option value="<?= $p['id_produit'] ?>" data-nom="<?= $p['nom_commercial'] ?>" data-loc="<?= $p['emplacement'] ?>">
                            <?= $p['nom_commercial'] ?> (Actuel: <?= $p['emplacement'] ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <table style="width:100%; border-collapse:collapse;">
                <thead>
                    <tr style="border-bottom:2px solid #eee;">
                        <th style="text-align:left; padding:10px;">Produit</th>
                        <th style="text-align:left; padding:10px;">Ancien Emplacement</th>
                        <th style="text-align:center; padding:10px;">Action</th>
                    </tr>
                </thead>
                <tbody id="liste-maj-rapide">
                    </tbody>
            </table>
        </div>

        <div style="background:#f8f9fa; padding:20px; border:1px solid #ddd; border-radius:8px; height: fit-content;">
            <h4>2. Nouvel Emplacement</h4>
            <div class="input-group">
                <label>Nom du Rayon / Étagère :</label>
                <input type="text" id="nouveau-rayon-cible" class="swal2-input" placeholder="ex: Rayon B - Niveau 2" style="width:100%;">
            </div>
            <button onclick="appliquerChangementGroupe()" class="btn-save" style="background:#3498db; color:white; width:100%; margin-top:20px; padding:15px; border:none; cursor:pointer;">
                <i class="fas fa-sync"></i> Mettre à jour la sélection
            </button>
        </div>
    </div>
</div>

        <div class="panel" id="panel-rapport_pertes" style="display:none;">
    <h3 class="panel-title" style="color:var(--danger)">Analyse des Pertes (Casse / Périmés / Ajustements négatifs)</h3>
    
    <div style="background: #f8f9fa; padding: 15px; border-radius: 8px; margin-bottom: 20px; display: flex; gap: 15px; align-items: flex-end;">
        <div class="form-group" style="margin-bottom:0">
            <label>Du :</label>
            <input type="date" id="date_debut" value="<?= date('Y-m-01') ?>">
        </div>
        <div class="form-group" style="margin-bottom:0">
            <label>Au :</label>
            <input type="date" id="date_fin" value="<?= date('Y-m-d') ?>">
        </div>
        <button onclick="chargerRapportPertes()" class="tab-btn" style="background:var(--primary); color:white; height: 40px;">
            <i class="fas fa-search"></i> Générer le rapport
        </button>
        <button onclick="imprimerRapport()" class="tab-btn" style="background:var(--secondary); color:white; height: 40px;">
            <i class="fas fa-print"></i> Imprimer
        </button>
    </div>

    <div id="zone_rapport_pertes">
        <div class="no-print" style="display: flex; justify-content: center; margin-bottom: 30px; background: #fff; padding: 20px; border-radius: 8px; border: 1px solid #eee;">
    <div style="width: 300px; height: 300px;">
        <canvas id="chartPertes"></canvas>
    </div>
</div>
        <table id="table_pertes">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Produit</th>
                    <th>Type</th>
                    <th>Quantité</th>
                    <th>Valeur Estimée (PV)</th>
                    <th>Motif / Justification</th>
                </tr>
            </thead>
            <tbody id="body_rapport_pertes">
                <tr><td colspan="6" style="text-align:center; padding:30px;">Cliquez sur "Générer" pour charger les données.</td></tr>
            </tbody>
            <tfoot>
                <tr style="background:#eee; font-weight:bold;">
                    <td colspan="4" style="text-align:right">Total des pertes sur la période :</td>
                    <td id="total_valeur_pertes">0 FCFA</td>
                    <td></td>
                </tr>
            </tfoot>


        </table>
    </div>
</div>

<div class="panel" id="panel-view_lots" style="display:none;">
    <h3 class="panel-title"><i class="fas fa-boxes"></i> Détail par Lots & Expiration</h3>

    <?php
    $total_perimes = 0;
    $total_alertes = 0;
    $six_mois = strtotime('+6 months');
    $aujourdhui = time();

    foreach($lots_detail as $l) {
        $p_date = strtotime($l['date_peremption']);
        if ($p_date < $aujourdhui) $total_perimes++;
        elseif ($p_date <= $six_mois) $total_alertes++;
    }
    ?>

    <div style="display: flex; gap: 15px; margin-bottom: 20px;">
        <div style="flex: 1; background: #fff5f5; padding: 10px; border-radius: 8px; border: 1px solid #feb2b2; text-align: center;">
            <span style="display: block; font-size: 0.8rem; color: #c53030; font-weight: bold;">PÉRIMÉS</span>
            <span style="font-size: 1.5rem; font-weight: bold; color: #c53030;"><?= $total_perimes ?></span>
        </div>
        <div style="flex: 1; background: #fffaf0; padding: 10px; border-radius: 8px; border: 1px solid #fbd38d; text-align: center;">
            <span style="display: block; font-size: 0.8rem; color: #975a16; font-weight: bold;">ALERTE < 6 MOIS</span>
            <span style="font-size: 1.5rem; font-weight: bold; color: #975a16;"><?= $total_alertes ?></span>
        </div>
        <div style="flex: 1; background: #f0fff4; padding: 10px; border-radius: 8px; border: 1px solid #c6f6d5; text-align: center;">
            <span style="display: block; font-size: 0.8rem; color: #276749; font-weight: bold;">VALIDE</span>
            <span style="font-size: 1.5rem; font-weight: bold; color: #276749;"><?= count($lots_detail) - ($total_perimes + $total_alertes) ?></span>
        </div>
    </div>

    <table class="win-table" id="table-lots">
        <thead>
            <tr style="background: #edf2f7;">
                <th>Produit</th>
                <th>N° Lot</th>
                <th>Quantité</th>
                <th>Expiration</th>
                <th>Statut</th>
                <th>Action</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach($lots_detail as $ld): 
                $ts_peremp = strtotime($ld['date_peremption']);
                $is_perime = $ts_peremp < $aujourdhui;
                $is_alerte = (!$is_perime && $ts_peremp <= $six_mois);
                
                // Style de ligne selon l'urgence
                $bg_color = "";
                if($is_perime) $bg_color = "background-color: #fff5f5;";
                elseif($is_alerte) $bg_color = "background-color: #fffaf0;";
            ?>
            <tr class="searchable" data-id-prod="<?= $ld['id_produit'] ?>" style="<?= $bg_color ?>">
                <td style="font-weight: 500;"><?= htmlspecialchars($ld['nom_commercial']) ?></td>
                <td><span class="badge-lot"><?= htmlspecialchars($ld['numero_lot']) ?></span></td>
                <td style="font-weight:bold; color: #2d3748;"><?= $ld['quantite_disponible'] ?></td>
                <td style="font-weight: bold; color: <?= $is_perime ? '#e53e3e' : ($is_alerte ? '#dd6b20' : '#2d3748') ?>">
                    <?= date('d/m/Y', $ts_peremp) ?>
                </td>
                <td>
                    <?php if($is_perime): ?>
                        <span style="color: #e53e3e; font-size: 0.8rem;"><i class="fas fa-skull-crossbones"></i> À retirer</span>
                    <?php elseif($is_alerte): ?>
                        <span style="color: #dd6b20; font-size: 0.8rem;"><i class="fas fa-hourglass-half"></i> Proche</span>
                    <?php else: ?>
                        <span style="color: #38a169; font-size: 0.8rem;"><i class="fas fa-check"></i> Conforme</span>
                    <?php endif; ?>
                </td>
                <td>
                    <button onclick="ouvrirAjustement(<?= $ld['id_stock'] ?>, '<?= addslashes($ld['nom_commercial']) ?>', <?= $ld['quantite_disponible'] ?>)" 
                            class="tab-btn" style="padding:4px 10px; font-size:0.75rem; background:#f1c40f; border:none; border-radius:4px; cursor:pointer;">
                        <i class="fas fa-edit"></i> Ajuster
                    </button>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    
    <div style="margin-top: 15px; text-align: center;">
        <button onclick="$('#table-lots tbody tr').show();" style="background:none; border:none; color:#3182ce; cursor:pointer; text-decoration:underline;">
            Afficher tous les lots de la pharmacie
        </button>
    </div>
</div>

<div class="panel" id="panel-nouveau-produit" style="display:none;">
    <h3><i class="fas fa-pills"></i> Fiche de Référencement Produit</h3>
    
    <div style="background: #edf2f7; padding: 15px; border-radius: 8px; margin-bottom: 20px; border: 1px solid #cbd5e0;">
        <label style="font-weight: bold; color: #2d3748;">Type de référencement :</label>
        <div style="margin-top: 10px;">
            <label style="margin-right: 20px; cursor: pointer;">
                <input type="radio" name="type_prod_select" value="medical" checked onchange="basculerInterfaceProduit('medical')"> 
                <b>Médicament / Parapharmacie</b>
            </label>
            <label style="margin-right: 20px; cursor: pointer;">
                <input type="radio" name="type_prod_select" value="lot" onchange="basculerInterfaceProduit('lot')"> 
                <b>Médicament par LOTS (Traçabilité)</b>
            </label>
            <label style="cursor: pointer;">
                <input type="radio" name="type_prod_select" value="divers" onchange="basculerInterfaceProduit('divers')"> 
                <b>Divers (Bic, Bonbon, etc.)</b>
            </label>
        </div>
    </div>

    <form id="form-add-produit" class="form-grid">
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
            <div class="group-box">
                <div class="input-group">
                    <label>1. Famille / Rayon</label>
                    <select name="id_famille" id="reg-famille" onchange="chargerSousFamillesForm(this.value)" required style="width:100%;">
                        <option value="">-- Sélectionner Rayon --</option>
                        <?php 
                        $requete_fams = $pdo->query("SELECT * FROM familles ORDER BY nom_famille ASC");
                        foreach($requete_fams->fetchAll() as $f): ?>
                            <option value="<?= $f['id_famille'] ?>"><?= htmlspecialchars($f['nom_famille']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="input-group section-medicale">
                    <label>2. Sous-Famille / Classe</label>
                    <select name="id_sous_famille" id="reg-sous-famille" style="width:100%;">
                        <option value="">Choisir un rayon d'abord...</option>
                    </select>
                </div>
            </div>

            <div class="group-box">
                <div class="input-group">
                    <label>Nom Commercial</label>
                    <input type="text" name="nom_commercial" id="nom_commercial" required style="width:100%;">
                </div>
                <div class="input-group section-medicale">
                    <label>Molécule (DCI)</label>
                    <input type="text" name="molecule" style="width:100%;">
                </div>
                <div class="input-group section-medicale">
                    <label>Dosage</label>
                    <input type="text" name="dosage" style="width:100%;">
                </div>
            </div>
        </div>

        <div class="section-medicale" style="background: #f0f7ff; padding: 15px; border-radius: 8px; border: 1px solid #bee3f8; margin-top: 20px;">
            <h4 style="margin-top:0; color: #2b6cb0;"><i class="fas fa-cut"></i> Gestion du Déconditionnement</h4>
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                <div class="input-group">
                    <label>Vente au détail possible ?</label>
                    <select name="est_detail" id="est_detail" class="win-input" onchange="toggleCoef(this.value); calculerTotalUnites();" style="width:100%;">
                        <option value="0">Non (Boîte entière uniquement)</option>
                        <option value="1">Oui (Plaquette, Ampoule, Unité)</option>
                    </select>
                </div>
                <div class="input-group" id="group-coef" style="display:none;">
                    <label>Nombre d'unités par boîte</label>
                    <input type="number" name="coefficient_division" id="coefficient_division" value="1" min="1" oninput="calculerTotalUnites()" style="width:100%;">
                    <small>Ex: 3 pour plaquettes, 10 pour injections</small>
                </div>

                 <!-- 👉 NOUVEAU CHAMP : PRIX DÉTAIL -->
        <div class="input-group" id="group-prix-detail" style="display:none;">
            <label>Prix de vente de l'unité (Détail)</label>
            <input type="number" name="prix_unitaire_detail" id="prix_unitaire_detail" placeholder="Ex: 850" style="width:100%; border: 2px solid #3182ce;">
            <small style="color: #2b6cb0;">Prix pour 1 ampoule/plaquette</small>
        </div>
            </div>
        </div>

        <div id="section-entree-lot" style="display:none; background: #fffaf0; padding: 15px; border-radius: 8px; border: 1px solid #fbd38d; margin-top: 20px;">
            <h4 style="margin-top:0; color: #dd6b20;"><i class="fas fa-boxes"></i> Premier Arrivage (Stock Initial)</h4>
            <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 15px;">
                <div class="input-group">
                    <label>N° de Lot</label>
                    <input type="text" name="num_lot" placeholder="Ex: LOT24-001">
                </div>
                <div class="input-group">
                    <label>Quantité (Boîtes)</label>
                    <input type="number" name="qty_lot_boites" id="qty_lot_boites" value="0" min="0" oninput="calculerTotalUnites()">
                </div>
                <div class="input-group">
                    <label>Date de Péremption</label>
                    <input type="date" name="peremp_lot">
                </div>
            </div>
            <div id="calcul-unites-reel" style="margin-top: 10px; font-weight: bold; color: #c05621; display: none;">
                <i class="fas fa-calculator"></i> Conversion : <span id="label-calcul">0</span> unité(s) seront créées.
            </div>
        </div>

        <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 20px; margin-top: 20px;">
            <div class="input-group">
                <label>Prix Unitaire (Vente Boîte)</label>
                <input type="number" name="prix_unitaire" required style="width:100%;">
            </div>
            <div class="input-group section-medicale">
                <label>Fournisseur Habituel</label>
                <select name="id_fournisseur" style="width:100%;">
                    <option value="">-- Choisir --</option>
                   <?php foreach ($all_fournisseurs as $f): ?>
                    <option value="<?= $f['id_fournisseur'] ?>">
                        <?= htmlspecialchars($f['nom_fournisseur']) ?>
                    </option>
                <?php endforeach; ?>
                </select>
            </div>
        </div>



        <div class="input-group" style="margin-top: 20px;">
            <label>Description / Notes</label>
            <textarea name="description" rows="2" style="width:100%; border:1px solid #ddd; border-radius:4px;"></textarea>
        </div>

        <div id="bloc-alertes" style="background: #fff5f5; padding: 15px; border-radius: 8px; border: 1px solid #feb2b2; margin-top: 20px;">
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                <div class="input-group">
                    <label>Seuil d'Alerte (Boîtes)</label>
                    <input type="number" name="seuil_alerte" id="seuil_alerte" value="5" style="width:100%;">
                </div>
                <div class="input-group">
                    <label>Alerte Péremption (Mois)</label>
                    <input type="number" name="delai_peremption" id="delai_peremption" value="6" style="width:100%;">
                </div>
            </div>
        </div>

        <input type="hidden" name="action" value="add_prod">
        <input type="hidden" name="est_divers" id="est_divers" value="0">
        <input type="hidden" name="emplacement" value="NON DÉFINI">
        <input type="hidden" name="prix_achat" id="prix_achat" value="0">
        <input type="hidden" name="stock_max" value="0">

        <div style="margin-top: 30px; text-align: right;">
            <button type="submit" class="btn-save" style="background:#2c3e50; color:white; padding: 12px 25px; border:none; border-radius:4px; cursor:pointer;">
                <i class="fas fa-check-circle"></i> ENREGISTRER L'ARTICLE
            </button>
        </div>
    </form>
</div>

        <div class="panel" id="panel-autopilote" style="display:none;">

            <div style="background:#e3f2fd; padding:15px; border-radius:8px; margin-bottom:20px; display:flex; gap:20px; align-items:center;" class="no-print">
<div style="background:#e3f2fd; padding:15px; border-radius:8px; margin-bottom:20px; display:flex; gap:20px; align-items:center;" class="no-print">
    <?php
// On récupère la liste des fournisseurs
$query_f = $pdo->query("SELECT id_fournisseur, nom_fournisseur FROM fournisseurs ORDER BY nom_fournisseur ASC");
$liste_fournisseurs = $query_f->fetchAll();
?>

<div>
    <label><b>1. Fournisseur :</b></label><br>
    <select id="select_fournisseur" onchange="chargerSuggestions()" style="padding:10px; width:250px; border:2px solid #0984e3; border-radius:5px; font-weight:bold;">
        <option value="0" selected disabled>-- Choisir dans la base --</option>
        <?php foreach($liste_fournisseurs as $f): ?>
            <option value="<?= $f['id_fournisseur'] ?>">
                <?= strtoupper($f['nom_fournisseur']) ?>
            </option>
        <?php endforeach; ?>
    </select>
</div>
    <div>
        <label><b>2. Seuil de commande :</b></label><br>
        <input type="number" id="input_seuil_perso" value="10" oninput="chargerSuggestions()" style="padding:9px; width:150px; border:2px solid #0984e3; border-radius:5px; text-align:center; font-weight:bold;">
    </div>

    <button onclick="finaliserEtEnvoyer()" id="btn-final" style="background:#27ae60; color:white; border:none; padding:12px 25px; border-radius:5px; cursor:pointer; font-weight:bold; transition: 0.3s;top: 50px;">
        <i class="fas fa-paper-plane"></i> <span id="btn-envoi-texte">ENVOYER LA COMMANDE</span>
    </button>
</div>
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
        <button onclick="chargerSuggestions()" class="tab-btn" style="background:#f8f9fa; border:1px solid #ddd;">
            <i class="fas fa-sync-alt"></i> Re-calculer
        </button>
    </div>

 
</div>

   <table id="table_autopilote">
        <thead>
            <tr style="background:#f1f2f6;">
               <th style="width: 250px;">
                <input type="checkbox" id="check_all_autopilote" onclick="toggleAllChecks(this)" style="transform: scale(1.2); margin-right: 10px;"> 
                Produit
            </th>
                <th>Stock Actuel</th>
                <th>Ventes/J (CMJ)</th>
                <th>P.A. Unitaire</th>
                <th style="width:150px;">Qte suggérée</th>
                <th style="width:120px;">Sous-total</th> </tr>
            </tr>
        </thead>
        <tbody id="body_suggestions">
            <tr><td colspan="5" style="text-align:center; padding:20px;">Analyse des besoins en cours...</td></tr>
        </tbody>
    </table>
</div>

<div class="panel" id="panel-maj-catalogue" style="display:none;">
    <h3><i class="fas fa-sync"></i> Mises à jour du Catalogue Référentiel</h3>
    <p>Comparaison entre vos tarifs et les prix officiels du marché.</p>
    
    <table class="win-table">
        <thead>
            <tr>
                <th>Produit</th>
                <th>Votre Prix</th>
                <th>Prix Référentiel</th>
                <th>Action</th>
            </tr>
        </thead>
        <tbody id="body-maj-catalogue">
            </tbody>
    </table>
</div>

<div class="panel" id="panel-propositions" style="display:none;">
    <div style="display:flex; justify-content:space-between; align-items:center;">
        <h3><i class="fas fa-magic"></i> Propositions winAutopilote</h3>
        <button onclick="envoyerToutesCommandes()" class="btn-save" style="background:#2ecc71;">
            <i class="fas fa-paper-plane"></i> Tout envoyer aux fournisseurs
        </button>
    </div>

    <table class="win-table">
        <thead>
            <tr>
                <th>Produit</th>
                <th>Stock (Physique + Attente)</th>
                <th>Ventes (30j)</th>
                <th>Fournisseur</th>
                <th>Qté à Commander</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody id="body-propositions">
            </tbody>
    </table>
</div>

<div class="panel" id="panel-reception" style="display:none;">
    <h3 style="color:var(--secondary)"><i class="fas fa-truck-loading"></i> Réception des Commandes en cours</h3>
    <div style="background:#f8f9fa; padding:15px; border-radius:8px; margin-bottom:20px; border-left:5px solid var(--win-blue);">
        <p style="margin:0"><strong>Auto-Réception active :</strong> Les quantités ont été pré-remplies selon votre commande. Modifiez uniquement en cas d'écart (manquant ou surplus).</p>
    </div>

    <table id="table_reception">
        <thead>
            <tr style="background:#eee;">
                <th>Produit</th>
                <th>Qté Commandée</th>
                <th style="width:150px;">Qté Reçue (Réelle)</th>
                <th>Ecart</th>
                <th>Action</th>
            </tr>
        </thead>
        <tbody id="body_reception">
            </tbody>
    </table>

    <div style="margin-top:20px; text-align:right;">
        <button onclick="validerReceptionFinale()" class="tab-btn" style="background:var(--secondary); color:white; padding:12px 25px;">
            <i class="fas fa-check-double"></i> INTÉGRER AU STOCK PHYSIQUE
        </button>
    </div>
</div>


<div class="panel" id="panel-retours" style="display:none;">
    <h3 style="color:#e67e22"><i class="fas fa-box-open"></i> Préparation de Retour (Avoir)</h3>
    
    <div style="background:#fff3e0; padding:15px; border-radius:8px; margin-bottom:20px; border-left:5px solid #e67e22;">
        <form id="form-retour" class="ajax-form">
            <input type="hidden" name="action" value="creer_retour">
            <div style="display:grid; grid-template-columns: 1fr 1fr 1fr auto; gap:10px; align-items:end;">
                <div>
                    <label>Produit à retourner :</label>
                    <select name="id_produit" class="swal2-input" style="width:100%; margin:0;" required>
                        <?php foreach($produits as $p): ?>
                            <option value="<?= $p['id_produit'] ?>"><?= $p['nom_commercial'] ?> (Stock: <?= $p['stock_total'] ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label>Quantité :</label>
                    <input type="number" name="quantite" class="swal2-input" style="width:100%; margin:0;" required>
                </div>
                <div>
                    <label>Motif du retour :</label>
                    <select name="motif" class="swal2-input" style="width:100%; margin:0;">
                        <option value="Périmé">Périmé / Proche péremption</option>
                        <option value="Erreur Commande">Erreur de livraison</option>
                        <option value="Defectueux">Produit défectueux / Casse</option>
                        <option value="Rappel">Rappel de lot (Laboratoire)</option>
                    </select>
                </div>
                <button type="submit" class="tab-btn" style="background:#e67e22; color:white; height:45px;">
                    Valider le retrait
                </button>
            </div>
        </form>
    </div>

    <table id="table_retours_liste">
        <thead>
            <tr style="background:#eee;">
                <th>Date</th>
                <th>Produit</th>
                <th>Quantité</th>
                <th>Fournisseur</th>
                <th>Motif</th>
                <th>Action</th>
            </tr>
        </thead>
        <tbody id="body_retours">
            </tbody>
    </table>
</div>

<div class="panel" id="panel-maj-molecules" style="display:none;">
    <h4><i class="fas fa-microscope"></i> Indexation des Molécules</h4>
    <p>Complétez les molécules pour activer la substitution automatique.</p>
    
    <table class="win-table">
        <thead>
            <tr>
                <th>Produit</th>
                <th>Molécule (DCI)</th>
                <th>Dosage</th>
                <th>Action</th>
            </tr>
        </thead>
        <tbody id="body-saisie-molecules">
            </tbody>
    </table>
</div>





       

        <div class="panel" id="panel-add_stock" style="display:none;">
            <h3 class="panel-title">Entrée de Stock (Achat Fournisseur)</h3>
            <form class="ajax-form">
                <input type="hidden" name="action" value="add_stock_entry">
                <div style="display:grid; grid-template-columns: 1fr 1fr; gap:20px;">
                    <div>
                        <label>Produit</label><br>
                        <select name="id_produit" style="width:100%; padding:10px;" required>
                            <?php foreach($produits as $p): ?><option value="<?= $p['id_produit'] ?>"><?= $p['nom_commercial'] ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div><label>N° Lot</label><input type="text" name="numero_lot" style="width:100%; padding:10px;" required></div>
                    <div><label>Quantité reçue</label><input type="number" name="quantite" style="width:100%; padding:10px;" required></div>
                    <div><label>Date péremption</label><input type="date" name="date_peremption" style="width:100%; padding:10px;" required></div>
                </div>
                <button type="submit" class="tab-btn" style="margin-top:20px; background:var(--secondary); color:white; padding:12px 30px;">Valider la réception</button>
            </form>
        </div>

        <div id="modalFiche" class="no-print" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.7); z-index:9999; justify-content:center; align-items:center;">
    <div style="background:white; width:80%; max-height:90%; border-radius:10px; padding:20px; overflow-y:auto; position:relative;">
        <button onclick="$('#modalFiche').hide()" style="position:absolute; right:20px; top:20px; border:none; background:none; font-size:24px; cursor:pointer;">&times;</button>
        
        <h2 id="fiche_nom_produit" style="color:var(--primary); margin-top:0;">Fiche de Stock</h2>
        
        <div style="display:grid; grid-template-columns: repeat(3, 1fr); gap:10px; margin-bottom:20px;">
            <div style="padding:10px; background:#f8f9fa; border-left:4px solid var(--secondary)">
                <small>Entrées Totales</small><br><strong id="fiche_total_entree" style="color:var(--secondary)">0</strong>
            </div>
            <div style="padding:10px; background:#f8f9fa; border-left:4px solid var(--danger)">
                <small>Sorties/Pertes</small><br><strong id="fiche_total_sortie" style="color:var(--danger)">0</strong>
            </div>
            <div style="padding:10px; background:#f8f9fa; border-left:4px solid var(--info)">
                <small>Stock Actuel</small><br><strong id="fiche_stock_final">0</strong>
            </div>
        </div>

        <table style="width:100%; border-collapse:collapse;">
            <thead>
                <tr style="background:#eee;">
                    <th>Date</th>
                    <th>Opération</th>
                    <th>Quantité</th>
                    <th>Utilisateur</th>
                    <th>Motif</th>
                </tr>
            </thead>
            <tbody id="body_fiche_mouvements"></tbody>
        </table>
    </div>
</div>






<div id="print-zone" style="display:none;">
    <div style="text-align:center; border-bottom:2px solid #333; padding-bottom:10px; margin-bottom:20px;">
        <h2>PHARMASSIST - BON DE RÉCEPTION</h2>
        <p>Généré par winAutopilote le : <span id="print-date"></span></p>
    </div>
    <div style="margin-bottom:20px;">
        <strong>Fournisseur :</strong> <span id="print-fournisseur"></span><br>
        <strong>Commande N° :</strong> <span id="print-id-commande"></span>
    </div>
    <table style="width:100%; border-collapse:collapse;" border="1">
        <thead>
            <tr style="background:#eee;">
                <th style="padding:10px;">Produit</th>
                <th style="padding:10px;">Qté Commandée</th>
                <th style="padding:10px;">Qté Reçue</th>
                <th style="padding:10px;">État</th>
            </tr>
        </thead>
        <tbody id="print-body"></tbody>
    </table>
    <div style="margin-top:30px; display:flex; justify-content:space-between;">
        <p>Visa Pharmacien :</p>
        <p>Signature Livreur :</p>
    </div>

    <div id="print-zone" style="display:none;">
    <div style="text-align:center; border-bottom:2px solid #333; padding-bottom:10px; margin-bottom:20px;">
        <h2>PHARMASSIST - BON DE RÉCEPTION</h2>
        <p>Généré par winAutopilote le : <span id="print-date"></span></p>
    </div>
    <div style="margin-bottom:20px;">
        <strong>Fournisseur :</strong> <span id="print-fournisseur"></span><br>
        <strong>Commande N° :</strong> <span id="print-id-commande"></span>
    </div>
    <table style="width:100%; border-collapse:collapse;" border="1">
        <thead>
            <tr style="background:#eee;">
                <th style="padding:10px;">Produit</th>
                <th style="padding:10px;">Qté Commandée</th>
                <th style="padding:10px;">Qté Reçue</th>
                <th style="padding:10px;">État</th>
            </tr>
        </thead>
        <tbody id="print-body"></tbody>
    </table>
    <div style="margin-top:30px; display:flex; justify-content:space-between;">
        <p>Visa Pharmacien :</p>
        <p>Signature Livreur :</p>
    </div>
</div>

<style>
@media print {
    body * { visibility: hidden; }
    #print-zone, #print-zone * { visibility: visible; }
    #print-zone { position: absolute; left: 0; top: 0; width: 100%; display: block !important; }
}
</style> 
</div>




<style>
@media print {
    body * { visibility: hidden; }
    #print-zone, #print-zone * { visibility: visible; }
    #print-zone { position: absolute; left: 0; top: 0; width: 100%; display: block !important; }
}
</style>
    </div>





     <div class="modal fade" id="modalDetailAchat" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-info text-white">
                <h5 class="modal-title">Détails de la Facture : <span id="modal-num-facture"></span></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="row mb-3">
                    <div class="col-6"><strong>Fournisseur :</strong> <span id="modal-fournisseur"></span></div>
                    <div class="col-6 text-end"><strong>Date :</strong> <span id="modal-date"></span></div>
                </div>
                <table class="table table-sm table-bordered">
                    <thead class="table-light">
                        <tr>
                            <th>Produit</th>
                            <th>N° Lot</th>
                            <th>Péremption</th>
                            <th class="text-center">Qté (Détail)</th>
                            <th class="text-end">P.A Unitaire</th>
                        </tr>
                    </thead>
                    <tbody id="modal-corps-detail">
                        </tbody>
                </table>
            </div>
        </div>
    </div>
</div>


<div class="modal fade" id="modalPaiement" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header"><h5>Enregistrer un règlement</h5></div>
            <div class="modal-body">
                <input type="hidden" id="pay-id-achat">
                <p>Facture : <b id="pay-num"></b> (<span id="pay-fournisseur"></span>)</p>
                <div class="mb-3">
                    <label>Montant restant : <b id="pay-reste"></b> FCFA</label>
                    <input type="number" id="montant-reglement" class="form-control" placeholder="Saisir le montant versé">
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-success" onclick="validerReglement()">Confirmer le paiement</button>
            </div>
        </div>
    </div>
</div>



    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.5.25/jspdf.plugin.autotable.min.js"></script>
    <script>



        let monGraphique = null; // Variable globale pour détruire/recréer le graphique

function chargerRapportPertes() {
    const debut = $('#date_debut').val();
    const fin = $('#date_fin').val();

    $.post('ajax_produits.php', {
        action: 'get_rapport_pertes',
        debut: debut,
        fin: fin
    }, function(res) {
        if(res.status === 'success') {
            let html = '';
            let totalArgent = 0;
            
            // Initialisation des compteurs pour le graphique
            let stats = { "casse": 0, "perime": 0, "ajustement": 0 };

            res.data.forEach(item => {
                let valeur = Math.abs(item.quantite) * parseFloat(item.prix_unitaire);
                totalArgent += valeur;

                // On remplit les stats pour le graphique
                let type = item.type_mouvement.toLowerCase();
                if(stats.hasOwnProperty(type)) {
                    stats[type] += valeur;
                } else {
                    stats["ajustement"] += valeur; // Par défaut
                }

                html += `<tr>
                    <td>${item.date_mouvement}</td>
                    <td><b>${item.nom_commercial}</b></td>
                    <td><span class="badge-type">${item.type_mouvement}</span></td>
                    <td style="color:red"><b>${item.quantite}</b></td>
                    <td>${valeur.toLocaleString()} FCFA</td>
                    <td>${item.motif || '-'}</td>
                </tr>`;
            });

            $('#body_rapport_pertes').html(html || '<tr><td colspan="6" style="text-align:center">Aucune perte.</td></tr>');
            $('#total_valeur_pertes').text(totalArgent.toLocaleString() + ' FCFA');

            // --- GÉNÉRATION DU GRAPHIQUE ---
            genererGraphique(stats);
        }
    }, 'json');
}

function genererGraphique(donnees) {
    const ctx = document.getElementById('chartPertes').getContext('2d');
    
    // Si un graphique existe déjà, on le détruit pour le rafraîchir
    if(monGraphique) monGraphique.destroy();

    monGraphique = new Chart(ctx, {
        type: 'doughnut', // Style "Donut" très propre
        data: {
            labels: ['Casse', 'Périmés', 'Ajustements'],
            datasets: [{
                data: [donnees.casse, donnees.perime, donnees.ajustement],
                backgroundColor: ['#e74c3c', '#f39c12', '#3498db'],
                borderWidth: 2
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { position: 'bottom' },
                title: { display: true, text: 'Répartition financière des pertes' }
            }
        }
    });
}

function showPanel(panelId) {
    // 1. Définition des variables par défaut
    let title = "Tableau de bord";
    let cat = "Gestion";
    // 2. Mise à jour du titre et du fil d'ariane selon le panel
    switch(panelId) {
        case 'nouveau-produit':
            title = "Fiche Nouveau Produit";
            cat = "Stocks > Référentiel";
            break;
        case 'list': // Correspond à ton panel-list (Catalogue) 
            title = "Inventaire des Produits";
            cat = "Stocks > Consultation";
            break;
        case 'reception':
            title = "Réception de Commande";
            cat = "Achats > Logistique";
            break;
        case 'retours':
            title = "Gestion des Retours";
            cat = "Achats > Fournisseurs";
            break;
        case 'fournisseurs':
            title = "Gestion des Fournisseurs";
            cat = "Fournisseurs > Liste";
            break;
        case 'maj-emplacements':
            title = "Gestion des Emplacements";
            cat = "Stocks > Organisation";
            break;
        case 'rapport_pertes':
            title = "Analyse des Pertes";
            cat = "Rapports > Audit";
            break;
        case 'view_lots':
            title = "État des Lots & Expirations";
            cat = "Stocks > Traçabilité";
            break;
        case 'gestion-commandes':
            title = "Commandes en cours";
            cat = "Achats > Suivi";
            break;
        case 'maj-catalogue':
            title = " Catalogue Référentiel";
            cat = "Produits > catalogue";
            break;
        case 'hierarchie':
            title = " Catalogue Référentiel";
            cat = "Produits > Rayons & Classes";
            break;
    }

    // 3. Application visuelle des titres dans le HTML
    document.getElementById('main-title').innerText = title;
    document.getElementById('current-category').innerText = cat;

    // 4. LOGIQUE DE MASQUAGE : On cache TOUS les panels
    const allPanels = document.querySelectorAll('.panel');
    allPanels.forEach(p => {
        p.style.display = 'none';
    });

    // 5. AFFICHAGE : On affiche uniquement celui qui nous intéresse
    const targetPanel = document.getElementById('panel-' + panelId);
    if (targetPanel) {
        targetPanel.style.display = 'block';
    } else {
        console.warn("Attention: Le panel 'panel-" + panelId + "' n'existe pas dans le HTML.");
    }
}

// Afficher le catalogue par défaut au chargement
/*window.onload = function() {
    showPanel('list'); 
};*/

        $(document).ready(function() {
            // Recherche universelle
            $("#main-search").on("keyup", function() {
                var value = $(this).val().toLowerCase();
                $(".searchable").filter(function() { $(this).toggle($(this).text().toLowerCase().indexOf(value) > -1) });
            });

            // Gestion des formulaires
            $(".ajax-form").on("submit", function(e) {
                e.preventDefault();
                $.post('ajax_produits.php', $(this).serialize(), function(res) {
                    if(res.status === 'success') {
                        Swal.fire('Succès', res.message, 'success').then(() => location.reload());
                    } else {
                        Swal.fire('Erreur', res.message, 'error');
                    }
                }, 'json');
            });

            // Notifications Winpharma Style
            const notifications = <?= json_encode($notifs); ?>;
            notifications.forEach((n, i) => {
                setTimeout(() => {
                    const Toast = Swal.mixin({ toast: true, position: 'top-end', showConfirmButton: false, timer: 3000 });
                    Toast.fire({ icon: n.type, title: n.title, text: n.msg });
                }, i * 500);
            });
        });

        // Fonction d'ajustement (Style Winpharma)
        function ouvrirAjustement(idStock, nom, qteActuelle) {
            Swal.fire({
                title: 'Rectification Stock',
                html: `<p>Produit: <b>${nom}</b></p>
                       <input type="number" id="qte_reelle" class="swal2-input" placeholder="Quantité réelle" value="${qteActuelle}">
                       <select id="motif_ajust" class="swal2-input">
                            <option value="Erreur inventaire">Erreur inventaire</option>
                            <option value="Casse / Dégradation">Casse / Dégradation</option>
                            <option value="Vol constaté">Vol constaté</option>
                            <option value="Retour fournisseur">Retour fournisseur</option>
                       </select>`,
                showCancelButton: true,
                confirmButtonText: 'Enregistrer',
                preConfirm: () => {
                    return { qte: $('#qte_reelle').val(), motif: $('#motif_ajust').val() }
                }
            }).then((result) => {
                if (result.isConfirmed) {
                    $.post('ajax_produits.php', {
                        action: 'ajuster_stock',
                        id_stock: idStock,
                        quantite_reelle: result.value.qte,
                        motif: result.value.motif
                    }, (res) => { Swal.fire({ title: 'Stock Mis à jour', showCancelButton: true }) }, 'json');
                }
            });
        }

        function editPrice(id, name) {
            Swal.fire({ title: 'Prix : ' + name, input: 'number', showCancelButton: true })
                .then((r) => { if(r.value) $.post('ajax_produits.php', {action:'edit_price', id:id, prix:r.value}, () => location.reload()); });
        }



function chargerRapportPertes() {
    const debut = $('#date_debut').val();
    const fin = $('#date_fin').val();

    // On lance l'appel au serveur
    $.post('ajax_produits.php', {
        action: 'get_rapport_pertes',
        debut: debut,
        fin: fin
    }, function(res) {
        if(res.status === 'success') {
            let html = '';
            let totalArgent = 0; // Variable pour cumuler l'argent

            if(res.data.length > 0) {
                res.data.forEach(item => {
                    // Calcul de la valeur : Quantité (en positif) x Prix Unitaire
                    let valeurPertes = Math.abs(item.quantite) * parseFloat(item.prix_unitaire);
                    totalArgent += valeurPertes;

                    html += `<tr>
                        <td>${item.date_mouvement}</td>
                        <td><b>${item.nom_commercial}</b></td>
                        <td><span class="badge-type type-sortie">${item.type_mouvement}</span></td>
                        <td style="color:var(--danger); font-weight:bold">${item.quantite}</td>
                        <td>${valeurPertes.toLocaleString()} FCFA</td>
                        <td style="font-style:italic">${item.motif || 'Aucun motif'}</td>
                    </tr>`;
                });
            } else {
                html = '<tr><td colspan="6" style="text-align:center; padding:20px;">Aucune perte enregistrée sur cette période.</td></tr>';
            }

            // 1. On injecte les lignes dans le tableau
            $('#body_rapport_pertes').html(html);

            // 2. On met à jour le totalisateur financier en bas
            $('#total_valeur_pertes').html(totalArgent.toLocaleString() + ' FCFA');
            
            // On change la couleur en rouge si le total est supérieur à 0
            if(totalArgent > 0) {
                $('#total_valeur_pertes').css('color', 'var(--danger)');
            }
        }
    }, 'json');
}

function imprimerRapport() {
    const contenu = document.getElementById('zone_rapport_pertes').innerHTML;
    const debut = $('#date_debut').val();
    const fin = $('#date_fin').val();
    
    const fenetre = window.open('', '', 'height=600,width=800');
    fenetre.document.write('<html><head><title>Rapport de Pertes</title>');
    fenetre.document.write('<style>table{width:100%; border-collapse:collapse;} th,td{border:1px solid #ddd; padding:8px; text-align:left;} th{background:#f2f2f2;}</style>');
    fenetre.document.write('</head><body>');
    fenetre.document.write('<h2>Rapport des Pertes - PharmAssist</h2>');
    fenetre.document.write('<p>Période du ' + debut + ' au ' + fin + '</p>');
    fenetre.document.write(contenu);
    fenetre.document.write('</body></html>');
    fenetre.document.close();
    fenetre.print();
}

function ouvrirFiche(id, nom) {
    $('#fiche_nom_produit').text("Historique : " + nom);
    $('#body_fiche_mouvements').html('<tr><td colspan="5" style="text-align:center">Chargement...</td></tr>');
    $('#modalFiche').css('display', 'flex');

    $.post('ajax_produits.php', {
        action: 'get_rapport_mouvements',
        debut: '2000-01-01', // On prend tout l'historique
        fin: '<?= date("Y-m-d") ?>',
        type: 'tous'
    }, function(res) {
        if(res.status === 'success') {
            let html = '';
            let tEntree = 0;
            let tSortie = 0;
            
            // On filtre uniquement les mouvements du produit cliqué
            let mouvementsDuProduit = res.data.filter(m => m.nom_commercial === nom);

            mouvementsDuProduit.forEach(m => {
                let qte = parseInt(m.quantite);
                if(qte > 0) tEntree += qte; else tSortie += Math.abs(qte);

                html += `<tr>
                    <td>${m.date_mouvement}</td>
                    <td><span class="badge-type">${m.type_mouvement}</span></td>
                    <td style="font-weight:bold; color:${qte > 0 ? 'green' : 'red'}">${qte > 0 ? '+' : ''}${qte}</td>
                    <td><small>${m.utilisateur || 'Admin'}</small></td>
                    <td>${m.motif || '-'}</td>
                </tr>`;
            });

            $('#body_fiche_mouvements').html(html || '<tr><td colspan="5" style="text-align:center">Aucun mouvement pour ce produit.</td></tr>');
            $('#fiche_total_entree').text(tEntree);
            $('#fiche_total_sortie').text(tSortie);
            $('#fiche_stock_final').text(tEntree - tSortie);
        }
    }, 'json');
}


function changerFournisseur(idProduit, idFournisseur) {
    $.post('ajax_produits.php', {
        action: 'update_product_supplier',
        id_produit: idProduit,
        id_fournisseur: idFournisseur
    }, function(res) {
        if(res.status === 'success') {
            // Petit message discret en bas de l'écran (Toast)
            const Toast = Swal.mixin({
                toast: true,
                position: 'top-end',
                showConfirmButton: false,
                timer: 2000
            });
            Toast.fire({
                icon: 'success',
                title: 'Liaison fournisseur mise à jour'
            });
        }
    }, 'json');
}

function chargerSuggestions() {
    let seuil_saisie = parseInt($('#input_seuil_perso').val()) || 0;
    $('#check_all_autopilote').prop('checked', false);
    let id_f = $('#select_fournisseur').val();
    
    $('#body_suggestions').html('<tr><td colspan="5" style="text-align:center;">Chargement...</td></tr>');
    
    $.post('ajax_produits.php', { 
        action: 'get_besoins_autopilote',
        id_fournisseur: id_f
    }, function(res) {
        console.log(res)
        if(res.status === 'success') {
            let html = '';
            res.data.forEach(item => {
                let stock = parseInt(item.stock_actuel);
                let cmj = parseFloat(item.cmj) || 0;
                let prix = parseFloat(item.prix_achat) || 0; // RÉCUPÉRATION DU PRIX (selon ta BD)

                let styleRouge = (stock <= seuil_saisie) ? 'background-color: #ffebee; color: #d32f2f; font-weight: bold;' : '';
                let checkboxChecked = (stock <= seuil_saisie) ? 'checked' : '';
                

                let prixAchat = parseFloat(item.prix_achat) || 0;
                let suggestion = Math.max(0, parseInt(item.stock_max) - stock);
                let sousTotalInitial = suggestion * prixAchat;
              html += `
    <tr style="${styleRouge}">
        <td style="padding: 10px;">
            <input type="checkbox" class="commande-check" ${checkboxChecked}> 
            <span>${item.nom_commercial}</span>
        </td>
        <td style="text-align:center;">${stock}</td>
        <td style="text-align:center;">${cmj.toFixed(2)}</td>
        <td style="text-align:center;">${prixAchat.toLocaleString()} F</td>
        <td style="text-align:center;">
            <input type="number" class="qte-suggeree" 
                oninput="recalculerLigne(this)"
                data-id="${item.id_produit}" 
                data-prix="${prixAchat}" 
                value="${suggestion}" 
                style="width:70px; font-weight:bold; text-align:center;">
        </td>
        <td style="text-align:right; font-weight:bold; color:#2c3e50;">
            <span class="ligne-total">${sousTotalInitial.toLocaleString()}</span> F
        </td>
    </tr>`;
            });
            $('#body_suggestions').html(html || '<tr><td colspan="5">Aucun produit.</td></tr>');
        }
    }, 'json');
}

function finaliserEtEnvoyer() {
    let lignes = [];
    let id_f = $('#select_fournisseur').val();
    let total_commande = 0; // INITIALISATION DU TOTAL

    $('.commande-check:checked').each(function() {
        let tr = $(this).closest('tr');
        let inputQte = tr.find('.qte-suggeree');
        
        let id_p = inputQte.data('id'); 
        let prix = parseFloat(inputQte.data('prix')) || 0; // RÉCUPÈRE LE PRIX
        let qte = parseInt(inputQte.val()) || 0;

        if (qte > 0 && id_p) {
            lignes.push({ id_p: id_p, qte: qte });
            total_commande += (qte * prix); // CALCUL DU TOTAL
        }
    });

    if (lignes.length === 0) {
        Swal.fire("Attention", "Cochez des produits avec une quantité > 0.", "warning");
        return;
    }

    // On passe le total à la fonction de confirmation
    confirmTransmition(id_f, lignes, total_commande);
}

function confirmTransmition(id_f, lignes, total_commande) {
    Swal.fire({
        title: 'Transmission winAutopilote',
        html: `Confirmez-vous l'envoi de <b>${lignes.length}</b> produits ?<br>
               Montant estimé : <b>${total_commande.toFixed(2)} FCFA</b>`, // AFFICHAGE DU TOTAL
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Oui, transmettre !'
    }).then((result) => {
        if (result.isConfirmed) {
            $.post('ajax_produits.php', {
                action: 'recevoir_commande_complete',
                id_fournisseur: id_f,
                lignes: lignes,
                total_prevu: total_commande // ENVOI DU TOTAL À PHP
            }, function(res) {
                if(res.status === 'success') {
                    window.open('generer_pdf_commande.php?id_commande=' + res.id_commande, '_blank');
                    location.reload();
                } else {
                    Swal.fire("Erreur", res.message, "error");
                }
            }, 'json');
        }
    });
}

let commandeEnAttente = []; // Stockage temporaire pour la réception

// Cette fonction sera appelée après le succès de finaliserEtEnvoyer()
function preparerReception(lignes) {
    commandeEnAttente = lignes;
    showPanel('reception');
    let html = '';
    
    lignes.forEach((item, index) => {
        html += `
            <tr id="row_rec_${index}">
                <td><b>${item.nom}</b></td>
                <td style="text-align:center;">${item.qte}</td>
                <td>
                    <input type="number" class="qte-livree" value="${item.qte}" 
                           oninput="calculerEcartReception(${index}, ${item.qte})"
                           style="width:80px; padding:5px;">
                </td>
                <td id="ecart_rec_${index}" style="color:green; font-weight:bold;">0</td>
                <td><button onclick="marquerManquant(${index})" class="btn-action" title="Signaler Manquant"><i class="fas fa-times" style="color:red"></i></button></td>
            </tr>`;
    });
    $('#body_reception').html(html);
}

function calculerEcartReception(index, qteCmd) {
    let qteRecue = parseInt($(`#row_rec_${index} .qte-livree`).val()) || 0;
    let ecart = qteRecue - qteCmd;
    let el = $(`#ecart_rec_${index}`);
    el.text(ecart > 0 ? `+${ecart}` : ecart);
    el.css('color', ecart === 0 ? 'green' : (ecart < 0 ? 'red' : 'orange'));
}

function validerReceptionFinale() {
    let lignesFinales = [];
    $('#body_reception tr').each(function(i) {
        lignesFinales.push({
            id_p: commandeEnAttente[i].id_p,
            qte: $(this).find('.qte-livree').val()
        });
    });

    Swal.fire({
        title: 'Confirmer la réception ?',
        text: "Les stocks seront mis à jour immédiatement au comptoir.",
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Oui, stocker'
    }).then((result) => {
        if (result.isConfirmed) {
            $.post('ajax_produits.php', {
                action: 'auto_reception_stock',
                lignes: lignesFinales
            }, function(res) {
                if(res.status === 'success') {
                    Swal.fire('Stock à jour !', 'Les produits sont disponibles à la vente.', 'success')
                        .then(() => location.reload());
                }
            }, 'json');
        }
    });
}

/*function validerReceptionFinale() {
    let lignesFinales = [];
    let anomalies = [];

    $('#body_reception tr').each(function(i) {
        let qteCmd = parseInt(commandeEnAttente[i].qte);
        let qteRec = parseInt($(this).find('.qte-livree').val()) || 0;
        let nomProd = commandeEnAttente[i].nom;

        if (qteRec < qteCmd) {
            anomalies.push(`${nomProd} : manque ${qteCmd - qteRec} unité(s)`);
        }

        lignesFinales.push({
            id_p: commandeEnAttente[i].id_p,
            qte: qteRec
        });
    });

    // Si des manquants sont détectés, on demande une confirmation spéciale
    if (anomalies.length > 0) {
        Swal.fire({
            title: 'Attention : Manquants détectés',
            html: `<div style="text-align:left; color:#e74c3c;"><b>Les produits suivants sont incomplets :</b><br><small>${anomalies.join('<br>')}</small></div><br>Voulez-vous valider la réception partielle ?`,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#27ae60',
            cancelButtonColor: '#d33',
            confirmButtonText: 'Oui, valider avec manquants'
        }).then((result) => {
            if (result.isConfirmed) envoyerDonneesReception(lignesFinales);
        });
    } else {
        envoyerDonneesReception(lignesFinales);
    }
}*/

function envoyerDonneesReception(lignes) {
    $.post('ajax_produits.php', {
        action: 'recevoir_commande_complete',
        lignes: lignes,
        id_commande: window.current_id_commande // ID récupéré lors de l'envoi
    }, function(res) {
        if(res.status === 'success') {
            Swal.fire('Réception Terminée', res.message, 'success').then(() => location.reload());
        }
    }, 'json');
}

function imprimerBonReception(lignes) {
    // ... (En-tête identique à précédemment)
    
    let html = `
        <thead>
            <tr style="background:#eee;">
                <th style="padding:10px;">Produit</th>
                <th style="padding:10px;">Emplacement</th>
                <th style="padding:10px;">Qté Reçue</th>
                <th style="padding:10px;">Note</th>
            </tr>
        </thead>
        <tbody>`;

    lignes.forEach(l => {
        // On récupère les infos complètes via l'objet produit
        let info = commandeEnAttente.find(item => item.id_p == l.id_p);
        
        html += `
            <tr>
                <td style="padding:8px; border-bottom:1px solid #ddd;">${info.nom}</td>
                <td style="padding:8px; border-bottom:1px solid #ddd; color:#2980b9;">${info.emplacement || '-'}</td>
                <td style="padding:8px; border-bottom:1px solid #ddd; text-align:center;"><b>${l.qte}</b></td>
                <td style="padding:8px; border-bottom:1px solid #ddd;">[ ] Rangé</td>
            </tr>`;
    });
    
    $('#print-body').html(html + '</tbody>');
    window.print();
}

function envoyerDonneesReception(lignes) {
    $.post('ajax_produits.php', {
        action: 'recevoir_commande_complete',
        lignes: lignes,
        id_commande: window.current_id_commande
    }, function(res) {
        if(res.status === 'success') {
            Swal.fire({
                title: 'Réception Terminée',
                text: res.message,
                icon: 'success',
                showCancelButton: true,
                confirmButtonText: '<i class="fas fa-print"></i> Imprimer le Bon',
                cancelButtonText: 'Fermer'
            }).then((result) => {
                if (result.isConfirmed) {
                    imprimerBonReception(lignes);
                }
                location.reload();
            });
        }
    }, 'json');
}

function validerReceptionFinale() {
    let lignesFinales = [];
    let anomalies = [];

    $('#body_reception tr').each(function(i) {
        let qteCmd = parseInt(commandeEnAttente[i].qte);
        let qteRec = parseInt($(this).find('.qte-livree').val()) || 0;
        let nomProd = commandeEnAttente[i].nom;

        if (qteRec < qteCmd) {
            anomalies.push(`${nomProd} : manque ${qteCmd - qteRec} unité(s)`);
        }

        lignesFinales.push({
            id_p: commandeEnAttente[i].id_p,
            qte: qteRec
        });
    });

    // Si des manquants sont détectés, on demande une confirmation spéciale
    if (anomalies.length > 0) {
        Swal.fire({
            title: 'Attention : Manquants détectés',
            html: `<div style="text-align:left; color:#e74c3c;"><b>Les produits suivants sont incomplets :</b><br><small>${anomalies.join('<br>')}</small></div><br>Voulez-vous valider la réception partielle ?`,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#27ae60',
            cancelButtonColor: '#d33',
            confirmButtonText: 'Oui, valider avec manquants'
        }).then((result) => {
            if (result.isConfirmed) envoyerDonneesReception(lignesFinales);
        });
    } else {
        envoyerDonneesReception(lignesFinales);
    }
}

function envoyerDonneesReception(lignes) {
    $.post('ajax_produits.php', {
        action: 'recevoir_commande_complete',
        lignes: lignes,
        id_commande: window.current_id_commande // ID récupéré lors de l'envoi
    }, function(res) {
        if(res.status === 'success') {
            Swal.fire('Réception Terminée', res.message, 'success').then(() => location.reload());
        }
    }, 'json');
}

function imprimerBonReception(lignes) {
    // 1. Remplissage des infos d'en-tête
    $('#print-date').text(new Date().toLocaleString('fr-FR'));
    $('#print-fournisseur').text($('#select_fournisseur option:selected').text());
    $('#print-id-commande').text(window.current_id_commande || 'N/A');

    // 2. Construction du tableau
    let html = '';
    lignes.forEach(l => {
        // On retrouve le nom du produit via le tableau commandeEnAttente
        let infoOriginale = commandeEnAttente.find(item => item.id_p == l.id_p);
        let ecart = l.qte - infoOriginale.qte;
        let statut = (ecart === 0) ? "CONFORME" : (ecart < 0 ? "MANQUANT" : "SURPLUS");

        html += `
            <tr>
                <td style="padding:8px;">${infoOriginale.nom}</td>
                <td style="padding:8px; text-align:center;">${infoOriginale.qte}</td>
                <td style="padding:8px; text-align:center;"><b>${l.qte}</b></td>
                <td style="padding:8px; text-align:center; font-size:0.8rem;">${statut}</td>
            </tr>`;
    });
    
    $('#print-body').html(html);

    // 3. Lancer l'impression
    window.print();
}

function lancerNettoyagePerimes() {
    Swal.fire({
        title: 'Nettoyage Automatique',
        text: "Voulez-vous retirer tous les produits périmés du stock actuel ? Cette action sera enregistrée dans l'historique des flux.",
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#e74c3c',
        confirmButtonText: 'Oui, purger le stock',
        cancelButtonText: 'Annuler'
    }).then((result) => {
        if (result.isConfirmed) {
            $.post('ajax_produits.php', { action: 'nettoyage_automatique_perimes' }, function(res) {
                if(res.status === 'success') {
                    Swal.fire('Terminé !', res.message, 'success').then(() => location.reload());
                } else {
                    Swal.fire('Erreur', res.message, 'error');
                }
            }, 'json');
        }
    });
}

// Dans votre fonction qui affiche la liste des produits à réceptionner
function afficherListeReception(lignes) {
    let html = '';
    lignes.forEach((l, index) => {
        html += `
            <tr>
                <td>
                    <b>${l.nom}</b><br>
                    <small style="color: #2980b9;"><i class="fas fa-map-marker-alt"></i> ${l.emplacement || 'Non défini'}</small>
                </td>
                <td style="text-align:center;">${l.qte}</td>
                <td>
                    <input type="number" class="qte-livree swal2-input" 
                           style="width:80px; margin:0;" 
                           value="${l.qte}" 
                           data-index="${index}">
                </td>
            </tr>`;
    });
    $('#body_reception').html(html);
}

/*function showPanel(panelId) {
    // 1. Masquer tous les panneaux
    $('.panel').hide();
    
    // 2. Afficher le panneau demandé
    $('#panel-' + panelId).fadeIn();
    
    // 3. Charger les données nécessaires (catégories et fournisseurs)
    if(panelId === 'nouveau-produit') {
        chargerListesDeroulantes();
    }
}*/

/*$('#form-add-produit').on('submit', function(e) {
    e.preventDefault();
    console.log('nnnnnnnnnn')
    // On récupère le bouton cliqué (stay = 1 ou 0)
    const stayOpen = $(document.activeElement).val() === "1";
    const formData = $(this).serialize();

    $.post('ajax_produits.php', formData, function(response) {
        console.log(response.status)
        if(response.status === 'success') {
            const emplacement = $('[name="emplacement"]').val();
            
            Swal.fire({
                title: 'Produit enregistré !',
                text: 'Localisation : ' + emplacement,
                icon: 'success',
                timer: 1500,
                showConfirmButton: false
            });

            if (stayOpen) {
                // On vide le formulaire pour le produit suivant
                $('#form-add-produit')[0].reset();
                // Optionnel : on garde l'emplacement si on range par rayon
                $('[name="emplacement"]').val(emplacement); 
                $('[name="nom_commercial"]').focus();
            } else {
                // Retour à la vue d'ensemble
                showPanel('liste-produits');
                location.reload();
            }
        }
         else {
            Swal.fire('Erreur', response.message, 'error');
        }
    }, 'json');
});*/

/*function showPanel(panelId) {
    // 1. Masquer tous les panneaux
    $('.panel').hide();
    
    // 2. Afficher le panneau demandé
    $('#panel-' + panelId).fadeIn();

    // 3. Mise à jour dynamique du titre et du fil d'Ariane
    let title = "Tableau de bord";
    let cat = "Gestion";

    switch(panelId) {
        case 'nouveau-produit':
            title = "Fiche Nouveau Produit";
            cat = "Stocks > Référentiel";
            break;
        case 'liste-produits':
            title = "Inventaire des Produits";
            cat = "Stocks > Consultation";
            break;
        case 'reception':
            title = "Réception de Commande";
            cat = "Achats > Logistique";
            break;
        case 'retours':
            title = "Gestion des Retours";
            cat = "Achats > Fournisseurs";
            break;
        case 'fournisseurs':
            title = "Gestion des Fournisseurs";
            cat = "Fournisseurs > Ajouter Fournisseurs";
            break;
    }

    $('#main-title').text(title);
    $('#current-category').text(cat);
}
*/


let produitsAModifier = [];

$('#recherche-maj-prod').on('change', function() {
    let id = $(this).val();
    if(!id) return;

    let nom = $(this).find(':selected').data('nom');
    let loc = $(this).find(':selected').data('loc');

    if(!produitsAModifier.includes(id)) {
        produitsAModifier.push(id);
        $('#liste-maj-rapide').append(`
            <tr id="row-maj-${id}">
                <td style="padding:10px;">${nom}</td>
                <td style="padding:10px; color:#7f8c8d;">${loc || 'Non défini'}</td>
                <td style="text-align:center;">
                    <button onclick="retirerDeLaListe('${id}')" style="color:red; border:none; background:none; cursor:pointer;">
                        <i class="fas fa-times"></i>
                    </button>
                </td>
            </tr>
        `);
    }
    $(this).val('').trigger('change.select2'); // Reset pour le prochain scan
});

function appliquerChangementGroupe() {
    let nouveauRayon = $('#nouveau-rayon-cible').val();
    if(produitsAModifier.length === 0 || !nouveauRayon) {
        Swal.fire('Erreur', 'Sélectionnez des produits et un emplacement cible.', 'error');
        return;
    }

    $.post('ajax_produits.php', {
        action: 'maj_emplacements_groupe',
        ids: produitsAModifier,
        emplacement: nouveauRayon
    }, function(res) {
        if(res.status === 'success') {
            Swal.fire('Succès', res.message, 'success').then(() => location.reload());
        }
    }, 'json');
}

function visualiserRayonActuel() {
    // Étape 1 : On demande à l'utilisateur de saisir le nom du rayon
    Swal.fire({
        title: 'Inspection de Rayon',
        text: 'Entrez le nom du rayon à visualiser (ex: Rayon A, Frigo, etc.)',
        input: 'text',
        inputPlaceholder: 'Nom du rayon...',
        showCancelButton: true,
        confirmButtonText: 'Afficher le contenu',
        cancelButtonText: 'Annuler',
        confirmButtonColor: '#9b59b6'
    }).then((result) => {
        // Étape 2 : Si l'utilisateur a saisi quelque chose
        if (result.isConfirmed && result.value) {
            let rayon = result.value;

            // Appel AJAX pour récupérer les produits de ce rayon
            $.post('ajax_produits.php', {
                action: 'get_produits_par_rayon',
                emplacement: rayon
            }, function(res) {
                if(res.status === 'success') {
                    if (res.data.length === 0) {
                        Swal.fire('Rayon Vide', `Aucun produit n'est affecté au "${rayon}".`, 'info');
                        return;
                    }

                    // Construction de la liste des produits trouvés
                    let htmlTable = `
                        <table style="width:100%; border-collapse:collapse; font-size:14px;">
                            <thead>
                                <tr style="background:#f1f1f1;">
                                    <th style="padding:8px; border:1px solid #ddd;">Produit</th>
                                    <th style="padding:8px; border:1px solid #ddd;">Stock Total</th>
                                </tr>
                            </thead>
                            <tbody>
                                ${res.data.map(p => `
                                    <tr>
                                        <td style="padding:8px; border:1px solid #ddd; text-align:left;">${p.nom_commercial}</td>
                                        <td style="padding:8px; border:1px solid #ddd;"><b>${p.total_stock}</b></td>
                                    </tr>
                                `).join('')}
                            </tbody>
                        </table>`;

                    Swal.fire({
                        title: `Contenu du : ${rayon}`,
                        html: htmlTable,
                        width: '500px'
                    });
                }
            }, 'json');
        }
    });
}

function verifierMisesAJour() {
    $.post('ajax_produits.php', { action: 'comparer_catalogue' }, function(res) {
        let html = '';
        res.data.forEach(p => {
            let difference = p.prix_catalogue - p.prix_actuel;
            let color = difference > 0 ? 'red' : 'green';

            html += `
                <tr>
                    <td>${p.nom_commercial}</td>
                    <td>${p.prix_actuel} €</td>
                    <td style="color:${color}; font-weight:bold;">${p.prix_catalogue} €</td>
                    <td>
                        <button onclick="appliquerMaj('${p.id_produit}', '${p.prix_catalogue}')" class="btn-action">
                            Actualiser la fiche
                        </button>
                    </td>
                </tr>`;
        });
        $('#body-maj-catalogue').html(html);
    }, 'json');
}

function chercherSubstitution(idProd, nomProd) {
    Swal.fire({
        title: 'Analyse des équivalents...',
        html: 'Recherche de la même molécule et du même dosage en stock',
        didOpen: () => { Swal.showLoading(); }
    });

    $.post('ajax_produits.php', { action: 'get_equivalents', id_produit: idProd }, function(res) {
        if(res.status === 'success' && res.data.length > 0) {
            let listHtml = `
                <div style="text-align:left; font-size:14px;">
                <p>Alternatives pour <b>${nomProd}</b> :</p>
                <table style="width:100%; border-collapse:collapse;">
                    <thead>
                        <tr style="border-bottom:2px solid #eee;">
                            <th style="padding:8px;">Produit</th>
                            <th style="padding:8px;">Stock</th>
                            <th style="padding:8px;">Emplacement</th>
                        </tr>
                    </thead>
                    <tbody>`;

            res.data.forEach(p => {
                let sColor = p.stock_total > 0 ? '#27ae60' : '#e74c3c';
                listHtml += `
                    <tr style="border-bottom:1px solid #f1f1f1;">
                        <td style="padding:8px;"><b>${p.nom_commercial}</b></td>
                        <td style="padding:8px; color:${sColor}; font-weight:bold;">${p.stock_total}</td>
                        <td style="padding:8px; font-size:12px;">${p.emplacement || '-'}</td>
                    </tr>`;
            });

            listHtml += '</tbody></table></div>';

            Swal.fire({
                title: 'Substitution Possible',
                html: listHtml,
                width: '600px',
                confirmButtonText: 'OK'
            });
        } else {
            Swal.fire('Aucune alternative', 'Aucun produit équivalent trouvé dans votre base.', 'info');
        }
    }, 'json');
}

function chargerProduitsSansMolecule() {
    $.post('ajax_produits.php', { action: 'get_produits_incomplets' }, function(res) {
        let html = '';
        // Dans votre fonction chargerProduitsSansMolecule
html += `
    <tr id="row-mol-${p.id_produit}">
        <td><b>${p.nom_commercial}</b></td>
        <td>
            <input type="text" id="mol-${p.id_produit}" 
                   class="swal2-input input-molecule" 
                   placeholder="Tapez le début de la molécule..." 
                   style="margin:0; width:100%;">
        </td>
        </tr>`;
        res.data.forEach(p => {
            html += `
                <tr id="row-mol-${p.id_produit}">
                    <td><b>${p.nom_commercial}</b></td>
                    <td><input type="text" id="mol-${p.id_produit}" class="swal2-input" placeholder="ex: Paracétamol" style="margin:0; width:100%;"></td>
                    <td><input type="text" id="dos-${p.id_produit}" class="swal2-input" placeholder="ex: 500mg" style="margin:0; width:100%;"></td>
                    <td>
                        <button onclick="saveMolecule(${p.id_produit})" class="btn-save" style="background:#27ae60; padding:10px;">
                            <i class="fas fa-save"></i>
                        </button>
                    </td>
                </tr>`;
        });
        $('#body-saisie-molecules').html(html);
    }, 'json');
}

function saveMolecule(id) {
    const mol = $('#mol-' + id).val();
    const dos = $('#dos-' + id).val();

    $.post('ajax_produits.php', { 
        action: 'update_molecule', 
        id_produit: id, 
        molecule: mol, 
        dosage: dos 
    }, function(res) {
        if(res.status === 'success') {
            $('#row-mol-' + id).fadeOut(); // Le produit disparaît une fois rempli
        }
    }, 'json');
}

function activerSaisiePredictive(selector) {
    $.getJSON('ajax_produits.php?action=get_liste_molecules', function(data) {
        $(selector).autocomplete({
            source: data,
            minLength: 2, // Commence à suggérer après 2 lettres
            delay: 100
        });
    });
}

// À appeler quand vous affichez votre panneau de saisie
$(document).on('focus', '.input-molecule', function() {
    activerSaisiePredictive(this);
});

function calculerQteOptimale(p) {
    // Formule winAutopilote : (Stock Max - Stock Actuel) + Ajustement selon ventes
    let besoinBase = p.stock_max - (p.stock_physique + p.stock_en_route);
    
    // Si les ventes sont fortes, on peut suggérer un peu plus
    if (p.ventes_mensuelles > p.stock_max) {
        besoinBase += Math.ceil(p.ventes_mensuelles * 0.2); // +20% de sécurité
    }
    
    return Math.max(0, besoinBase);
}

function validerLigneReception(idLigne) {
    let qte = $('#qte-reçue-' + idLigne).val();
    let lot = $('#lot-' + idLigne).val();
    let peremption = $('#exp-' + idLigne).val();

    if(!lot || !peremption) {
        Swal.fire('Attention', 'Le numéro de lot et la péremption sont obligatoires.', 'warning');
        return;
    }

    $.post('ajax_produits.php', {
        action: 'finaliser_reception_ligne',
        id_ligne: idLigne,
        quantite: qte,
        lot: lot,
        date_exp: peremption
    }, function(res) {
        if(res.status === 'success') {
            $('#row-reception-' + idLigne).css('background', '#d4edda').fadeOut();
            actualiserBadgeRupture(); // Le badge de la navbar se met à jour !
        }
    }, 'json');
}

function imprimerBonReception(idCommande) {
    $.post('ajax_produits.php', { action: 'generer_bon_reception', id_commande: idCommande }, function(res) {
        if(res.status === 'success') {
            let date = new Date().toLocaleDateString();
            let html = `
                <div id="print-area" style="text-align:left; font-family: Arial, sans-serif; padding:20px;">
                    <h2 style="text-align:center; color:#2c3e50;">Bon de Réception Stock</h2>
                    <hr>
                    <p><b>Fournisseur :</b> ${res.data[0].nom_fournisseur}</p>
                    <p><b>Date de réception :</b> ${date}</p>
                    <table style="width:100%; border-collapse:collapse; margin-top:20px;">
                        <thead>
                            <tr style="background:#f1f1f1;">
                                <th style="border:1px solid #ddd; padding:8px;">Produit</th>
                                <th style="border:1px solid #ddd; padding:8px;">Qté</th>
                                <th style="border:1px solid #ddd; padding:8px;">Lot</th>
                                <th style="border:1px solid #ddd; padding:8px;">Péremption</th>
                            </tr>
                        </thead>
                        <tbody>
                            ${res.data.map(item => `
                                <tr>
                                    <td style="border:1px solid #ddd; padding:8px;">${item.nom_commercial}</td>
                                    <td style="border:1px solid #ddd; padding:8px; text-align:center;">${item.qte_reçue}</td>
                                    <td style="border:1px solid #ddd; padding:8px;">${item.numero_lot}</td>
                                    <td style="border:1px solid #ddd; padding:8px;">${item.date_peremption}</td>
                                </tr>
                            `).join('')}
                        </tbody>
                    </table>
                    <div style="margin-top:30px; display:flex; justify-content:space-between;">
                        <span>Signature Réceptionnaire :</span>
                        <span>Cachet Officine :</span>
                    </div>
                </div>`;

            Swal.fire({
                title: 'Bon de réception prêt',
                html: html,
                width: '800px',
                confirmButtonText: '<i class="fas fa-print"></i> Imprimer',
                showCancelButton: true,
                cancelButtonText: 'Fermer'
            }).then((result) => {
                if (result.isConfirmed) {
                    let printContents = document.getElementById('print-area').innerHTML;
                    let originalContents = document.body.innerHTML;
                    document.body.innerHTML = printContents;
                    window.print();
                    document.body.innerHTML = originalContents;
                    location.reload(); // Pour recharger les scripts JS
                }
            });
        }
    }, 'json');
}

function actualiserSousFamilles(idFamille) {
    const selectSF = document.getElementById('select-sous-famille');
    
    if (!idFamille) {
        selectSF.disabled = true;
        selectSF.innerHTML = '<option value="">Choisir d\'abord une famille...</option>';
        return;
    }

    $.post('ajax_hierarchie.php', { action: 'get_sous_familles', id_famille: idFamille }, function(data) {
        let html = '<option value="">-- Choisir Sous-Famille --</option>';
        data.forEach(sf => {
            html += `<option value="${sf.id_sous_famille}">${sf.nom_sous_famille}</option>`;
        });
        selectSF.innerHTML = html;
        selectSF.disabled = false;
    }, 'json');
}

function chargerConfigHierarchie() {
    // Charger les Familles
    $.post('ajax_produits.php', { action: 'get_familles' }, function(data) {
        //console.log(data)
        let htmlList = '';
        let htmlSelect = '<option value="">-- Choisir Famille Cible --</option>';
        
        data.forEach(f => {
            htmlList += `<li style="padding:10px; border-bottom:1px solid #eee; display:flex; justify-content:space-between;">
                            <span><b>${f.nom_famille}</b></span>
                            <button onclick="supprimerFamille(${f.id_famille})" style="color:red; border:none; background:none; cursor:pointer;"><i class="fas fa-trash"></i></button>
                         </li>`;
            htmlSelect += `<option value="${f.id_famille}">${f.nom_famille}</option>`;
        });
        
        $('#liste-familles-config').html(htmlList);
        $('#select-famille-pour-sf').html(htmlSelect);
    }, 'json');
}

// Déclencher le chargement quand on sélectionne une famille dans le bloc 2
$(document).on('change', '#select-famille-pour-sf', function() {
    const id = $(this).val();
    if(!id) return;
    
    $.post('ajax_produits.php', { action: 'get_sous_familles', id_famille: id }, function(data) {
        let html = '';
        data.forEach(sf => {
            html += `<li style="padding:8px; background:white; margin-bottom:5px; border-radius:4px; border:1px solid #eee; display:flex; justify-content:space-between;">
                        ${sf.nom_sous_famille}
                        <button onclick="supprimerSF(${sf.id_sous_famille})" style="color:#e67e22; border:none; background:none; cursor:pointer;"><i class="fas fa-times"></i></button>
                     </li>`;
        });
        $('#liste-sf-config').html(html || '<li>Aucune sous-famille.</li>');
    }, 'json');
});

// Fonction pour ajouter une Famille
function ajouterFamille() {
    const nom = $('#new-famille-nom').val();
    
    if (!nom) {
        Swal.fire('Erreur', 'Veuillez saisir un nom de famille', 'error');
        return;
    }

    $.post('ajax_hierarchie.php', { 
        action: 'add_famille', 
        nom: nom 
    }, function(res) {
        if (res.status === 'success') {
            $('#new-famille-nom').val(''); // Vide le champ
            chargerConfigHierarchie(); // Rafraîchit la liste
            Swal.fire({
                icon: 'success',
                title: 'Famille ajoutée',
                timer: 1500,
                showConfirmButton: false
            });
        }
    }, 'json');
}

// Fonction pour ajouter une Sous-Famille
function ajouterSousFamille() {
    const idFamille = $('#select-famille-pour-sf').val();
    const nomSF = $('#new-sf-nom').val();

    if (!idFamille || !nomSF) {
        Swal.fire('Attention', 'Sélectionnez une famille et saisissez un nom', 'warning');
        return;
    }

    $.post('ajax_hierarchie.php', { 
        action: 'add_sous_famille', 
        id_famille: idFamille,
        nom: nomSF 
    }, function(res) {
        if (res.status === 'success') {
            $('#new-sf-nom').val('');
            // On simule un changement de select pour rafraîchir la liste des SF
            $('#select-famille-pour-sf').trigger('change'); 
            Swal.fire({
                icon: 'success',
                title: 'Classe ajoutée',
                timer: 1500,
                showConfirmButton: false
            });
        }
    }, 'json');
}

// Remplit la liste des sous-familles dans le formulaire de création
function chargerSousFamillesForm(idFamille) {
    const selectSF = $('#reg-sous-famille');
    
    if (!idFamille) {
        selectSF.prop('disabled', true).html('<option value="">Choisir un rayon d\'abord...</option>');
        return;
    }

    $.post('ajax_hierarchie.php', { action: 'get_sous_familles', id_famille: idFamille }, function(data) {
        let options = '<option value="">-- Sélectionner la Classe --</option>';
        data.forEach(sf => {
            options += `<option value="${sf.id_sous_famille}">${sf.nom_sous_famille}</option>`;
        });
        selectSF.html(options).prop('disabled', false);
    }, 'json');
}

// Fonction d'envoi du nouveau produit
/*function sauvegarderProduit() {
    const formData = $('#form-add-produit').serialize();
    
    $.post('ajax_produits.php', formData + '&action=ajouter_produit', function(res) {
        if(res.status === 'success') {
            Swal.fire('Succès', 'Le produit a été ajouté au catalogue', 'success');
            $('#form-add-produit')[0].reset();
            showPanel('list'); // Retour à la liste
            location.reload(); // Pour rafraîchir le catalogue
        }
    }, 'json');
}*/

function sauvegarderProduit() {
    const formData = $('#form-add-produit').serialize();
    console.log('object')
/*    $.post('ajax_produits.php', formData + '&action=ajouter_produit', function(res) {
        if(res.status === 'success') {
            const newId = res.id_produit; // On récupère l'ID créé par le serveur
            
            Swal.fire({
                title: 'Produit enregistré !',
                text: "Voulez-vous définir l'emplacement en rayon maintenant ?",
                icon: 'success',
                showCancelButton: true,
                confirmButtonColor: '#3498db',
                cancelButtonColor: '#95a5a6',
                confirmButtonText: '<i class="fas fa-map-marker-alt"></i> Oui, définir le rayon',
                cancelButtonText: 'Plus tard'
            }).then((result) => {
                if (result.isConfirmed) {
                    // Option 1 : On demande l'emplacement directement
                    demanderEmplacement(newId);
                } else {
                    // Option 2 : On ferme et on recharge
                    location.reload();
                }
            });
            
            $('#form-add-produit')[0].reset();
        } else {
            Swal.fire('Erreur', res.message, 'error');
        }
    }, 'json');*/
}

function demanderEmplacement(idProduit) {
    Swal.fire({
        title: 'Emplacement en Rayon',
        input: 'text',
        inputLabel: 'Saisissez le code rayon ou étagère',
        inputPlaceholder: 'ex: Rayon A, Étage 2',
        showCancelButton: true,
        confirmButtonText: 'Enregistrer'
    }).then((result) => {
        if (result.value) {
            $.post('ajax_produits.php', {
                action: 'update_emplacement',
                id_produit: idProduit,
                emplacement: result.value
            }, function() {
                Swal.fire('Mis à jour !', '', 'success').then(() => location.reload());
            });
        } else {
            location.reload();
        }
    });
}

function chargerFournisseurs() {
    $.post('ajax_fournisseurs.php', { action: 'get_fournisseurs' }, function(data) {
        //console.log(data)
        let html = '';
        data.forEach(f => {
            html += `<tr>
                <td><b>${f.nom_fournisseur}</b></td>
                <td>${f.telephone || '-'}</td>
                <td>${f.email || '-'}</td>
                <td>
                    <button onclick="editerFournisseur(${f.id_fournisseur})" class="btn-action"><i class="fas fa-edit"></i></button>
                </td>
            </tr>`;
        });
        $('#liste-fournisseurs-body').html(html);
    }, 'json');
}

function ouvrirModalFournisseur() {
    Swal.fire({
        title: 'Ajouter un Fournisseur',
        html: `
            <input id="f-nom" class="swal2-input" placeholder="Nom du fournisseur">
            <input id="f-tel" class="swal2-input" placeholder="Téléphone">
            <input id="f-email" class="swal2-input" placeholder="Email">
        `,
        focusConfirm: false,
        showCancelButton: true,
        confirmButtonText: 'Enregistrer',
        preConfirm: () => {
            return {
                nom: document.getElementById('f-nom').value,
                tel: document.getElementById('f-tel').value,
                email: document.getElementById('f-email').value
            }
        }
    }).then((result) => {
        if (result.isConfirmed) {
            $.post('ajax_fournisseurs.php', { 
                action: 'add_fournisseur', 
                data: result.value 
            }, function(res) {
                if(res.status === 'success') {
                    Swal.fire('Succès', 'Fournisseur créé', 'success');
                    chargerFournisseurs();
                }
            }, 'json');
        }
    });
}

function editerFournisseur(id) {
    // 1. Récupérer les données actuelles du fournisseur
    $.post('ajax_fournisseurs.php', { action: 'get_un_fournisseur', id: id }, function(f) {
        
        Swal.fire({
            title: 'Modifier le Fournisseur',
            html: `
                <input id="edit-f-nom" class="swal2-input" placeholder="Nom" value="${f.nom_fournisseur}">
                <input id="edit-f-tel" class="swal2-input" placeholder="Téléphone" value="${f.telephone || ''}">
                <input id="edit-f-email" class="swal2-input" placeholder="Email" value="${f.email || ''}">
            `,
            showCancelButton: true,
            confirmButtonText: 'Mettre à jour',
            preConfirm: () => {
                return {
                    id: id,
                    nom: document.getElementById('edit-f-nom').value,
                    tel: document.getElementById('edit-f-tel').value,
                    email: document.getElementById('edit-f-email').value
                }
            }
        }).then((result) => {
            if (result.isConfirmed) {
                // 2. Envoyer les modifications au serveur
                $.post('ajax_fournisseurs.php', { 
                    action: 'update_fournisseur', 
                    data: result.value 
                }, function(res) {
                    if(res.status === 'success') {
                        Swal.fire('Mis à jour !', '', 'success');
                        chargerFournisseurs(); // Rafraîchir le tableau
                    }
                }, 'json');
            }
        });
    }, 'json');
}
chargerFournisseurs();
//chargerSousFamillesForm();

function viewLots(idProd, nomProd) {
    Swal.fire({
        title: 'Chargement des détails...',
        didOpen: () => { Swal.showLoading(); }
    });

    $.post('ajax_produits.php', { action: 'get_details_lots', id_produit: idProd }, function(res) {
        if(res.status === 'success') {
            let p = res.produit;
            let coef = parseFloat(p.coefficient_division) || 1; // On récupère le coefficient (ex: 10)
            let pa = parseFloat(p.prix_achat) || 1; // On récupère le coefficient (ex: 10)

            let html = `
                <div style="text-align:left; font-size:13px;">
                    <div style="background:#f0f7ff; padding:10px; border-radius:5px; margin-bottom:15px; border-left:5px solid #3498db;">
                        <b>Molécule :</b> ${p.molecule || 'N/A'}<br>
                        <b>Dosage :</b> ${p.dosage || 'N/A'}<br>
                        <b>Format :</b> 1 Boîte = ${coef} Unités<br>
                        <b>P.A :</b> 1 Boîte = ${pa} FCFA
                    </div>
                    
                    <h4 style="border-bottom:1px solid #ddd; padding-bottom:5px;"><i class="fas fa-boxes"></i> État des Stocks</h4>
                    <table style="width:100%; border-collapse:collapse; margin-top:10px;">
                        <thead>
                            <tr style="background:#eee; text-align:left;">
                                <th style="padding:5px;">N° Lot</th>
                                <th style="padding:5px;">Péremption</th>
                                <th style="padding:5px;">Stock (Boîtes + Détail)</th>
                                <th style="padding:5px;">Action</th>
                            </tr>
                        </thead>
                        <tbody>`;

            if(res.lots.length > 0) {
                res.lots.forEach(lot => {
                    let isPerime = new Date(lot.date_peremption) < new Date();
                    let totalUnites = parseFloat(lot.quantite_disponible);
                    
                    // --- CALCUL DU STOCK MIXTE ---
                    let nbBoites = Math.floor(totalUnites / coef); // Nombre de boites entières
                    let resteUnites = totalUnites % coef;         // Unités restantes
                    
                    let affichageStock = `<b>${nbBoites} Bté(s)</b>`;
                    if(resteUnites > 0) {
                        affichageStock += ` + <span style="color:#e67e22;">${resteUnites} Unités</span>`;
                    }

                    let styleRow = isPerime ? 'color:red; background:#fff5f5;' : '';
                    
                    html += `
                        <tr style="border-bottom:1px solid #eee; ${styleRow}">
                            <td style="padding:8px;">${lot.numero_lot}</td>
                            <td style="padding:8px;">${lot.date_peremption} ${isPerime ? '⚠️' : ''}</td>
                            <td style="padding:8px;">${affichageStock}</td>
                            <td style="padding:8px; text-align:center;">
                                <button onclick="sortirStock(${lot.id_stock}, ${idProd}, '${lot.numero_lot}')" 
                                        style="border:none; background:none; color:#e74c3c; cursor:pointer;" title="Sortir du stock">
                                    <i class="fas fa-minus-circle"></i>
                                </button>
                            </td>
                        </tr>`;
                });
            } else {
                html += '<tr><td colspan="4" style="text-align:center; padding:10px;">Aucun lot en stock.</td></tr>';
            }

            html += `</tbody></table>
                    <div style="margin-top:15px; font-style:italic; color:#666; border-top:1px solid #eee; padding-top:10px;">
                        <b>Note :</b> Le stock est calculé sur la base de ${coef} unités par boîte.
                    </div>
                </div>`;

            Swal.fire({
                title: nomProd,
                html: html,
                width: '600px',
                confirmButtonText: 'Fermer'
            });
        }
    }, 'json');
}

// Fonction pour déclarer une sortie (Casse/Périmé)
function sortirStock(idStock, idProd, lotNom) {
    Swal.fire({
        title: `Sortie du Lot: ${lotNom}`,
        html: `
            <div style="text-align:left;">
                <label>Quantité à retirer :</label>
                <input type="number" id="qte-sortie" class="swal2-input" min="1" value="1">
                <label>Motif de la sortie :</label>
                <select id="motif-sortie" class="swal2-input">
                    <option value="casse">Casse / Détérioration</option>
                    <option value="perime">Péremption</option>
                    <option value="ajustement_inventaire">Erreur d'inventaire (Retrait)</option>
                    <option value="retour_fournisseur">Retour Fournisseur</option>
                </select>
            </div>
        `,
        showCancelButton: true,
        confirmButtonText: 'Confirmer la sortie',
        confirmButtonColor: '#e74c3c',
        preConfirm: () => {
            return {
                id_stock: idStock,
                id_produit: idProd,
                quantite: document.getElementById('qte-sortie').value,
                motif: document.getElementById('motif-sortie').value
            }
        }
    }).then((result) => {
        if (result.isConfirmed) {
            $.post('ajax_produits.php', { action: 'declarer_sortie', data: result.value }, function(res) {
                if(res.status === 'success') {
                    Swal.fire('Mis à jour', 'Le stock a été ajusté et le mouvement enregistré.', 'success');
                    // On ferme et on rafraîchit pour voir le nouveau stock
                    location.reload();
                } else {
                    Swal.fire('Erreur', res.message, 'error');
                }
            }, 'json');
        }
    });
}


function appliquerMaj(idProd, nouveauPrix) {
    Swal.fire({
        title: 'Confirmer le changement ?',
        text: `Le prix passera à ${nouveauPrix} FCFA`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Oui, actualiser',
        cancelButtonText: 'Annuler'
    }).then((result) => {
        if (result.isConfirmed) {
            $.post('ajax_produits.php', { 
                action: 'appliquer_maj_prix', 
                id_produit: idProd, 
                nouveau_prix: nouveauPrix 
            }, function(res) {
                if(res.status === 'success') {
                    Swal.fire('Mis à jour !', 'Le prix a été actualisé dans votre base.', 'success');
                    verifierMisesAJour(); // Rafraîchit la liste des différences
                }
            }, 'json');
        }
    });
}

function lancerNettoyagePerimes() {
    Swal.fire({
        title: 'Purger les produits périmés ?',
        text: "Tous les lots périmés seront retirés du stock de vente et envoyés en rapport de perte.",
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        confirmButtonText: 'Oui, purger'
    }).then((result) => {
        if (result.isConfirmed) {
            $.post('ajax_produits.php', { action: 'purger_perimes' }, function(res) {
                Swal.fire('Succès', res.count + ' lots ont été déplacés en pertes.', 'success');
                location.reload();
            }, 'json');
        }
    });
}

function rafraichirBadgesAlertes() {
    $.post('ajax_produits.php', { action: 'get_alertes_critiques' }, function(res) {
        if(res.status === 'success') {
            const count = res.count;
            const badge = $('#badge-stock-alerte');
            
            if(count > 0) {
                badge.text(count).show();
                // Optionnel : faire clignoter si c'est critique
                if(count > 10) badge.addClass('blink-red');
            } else {
                badge.hide();
            }
        }
    }, 'json');
}

// Appeler au chargement et toutes les 5 minutes
$(document).ready(function() {
    rafraichirBadgesAlertes();
    setInterval(rafraichirBadgesAlertes, 300000); 
});

function basculerInterfaceProduit(type) {
    if (type === 'divers') {
        // Masquer les sections médicales
        $('.section-medicale').hide();
        $('#bloc-alertes').hide();
        
        // Retirer l'obligation du fournisseur et sous-famille
        $('[name="id_sous_famille"], [name="id_fournisseur"]').prop('required', false);
        
        // Mettre des valeurs par défaut invisibles
        $('#est_divers').val('1');
        $('#seuil_alerte').val('0'); // Pas d'alerte par défaut pour un bic
        $('#delai_peremption').val('120'); // 10 ans pour éviter les alertes périmées
        
        $('#nom_commercial').attr('placeholder', 'ex: BIC CRISTAL BLEU');
    } else {
        // Réafficher tout
        $('.section-medicale').show();
        $('#bloc-alertes').show();
        
        // Remettre les obligations
        $('[name="id_sous_famille"], [name="id_fournisseur"]').prop('required', true);
        
        $('#est_divers').val('0');
        $('#seuil_alerte').val('5');
        $('#delai_peremption').val('6');
        
        $('#nom_commercial').attr('placeholder', 'ex: DOLIPRANE 500mg');
    }
}
chargerConfigHierarchie()

function supprimerFamille(idFam) {
    Swal.fire({
        title: 'Êtes-vous sûr ?',
        text: "Cela supprimera la famille et ses liens. Vérifiez qu'elle est vide !",
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#e74c3c',
        cancelButtonColor: '#95a5a6',
        confirmButtonText: 'Oui, supprimer',
        cancelButtonText: 'Annuler'
    }).then((result) => {
        if (result.isConfirmed) {
            $.post('ajax_produits.php', { action: 'supprimer_famille', id_famille: idFam }, function(res) {
                if(res.status === 'success') {
                    Swal.fire('Supprimé !', 'La famille a été retirée.', 'success');
                    chargerConfigHierarchie(); // On rafraîchit la liste immédiatement
                } else {
                    Swal.fire('Erreur', res.message, 'error');
                }
            }, 'json');
        }
    });
}

function supprimerSF(idSousFam) {
    Swal.fire({
        title: 'Supprimer cette classe ?',
        text: "Attention : si des produits sont encore liés à cette sous-famille, l'opération échouera.",
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#e74c3c',
        confirmButtonText: 'Oui, supprimer',
        cancelButtonText: 'Annuler'
    }).then((result) => {
        if (result.isConfirmed) {
            $.post('ajax_produits.php', { action: 'supprimer_sous_famille', id_sous_famille: idSousFam }, function(res) {
                if(res.status === 'success') {
                    Swal.fire('Supprimé !', 'La classe a été retirée avec succès.', 'success');
                    // On rafraîchit l'affichage (réutilisez votre fonction de chargement de liste)
                    chargerListeSousFamilles(); 
                } else {
                    Swal.fire('Action impossible', res.message, 'error');
                }
            }, 'json');
        }
    });
}

// Fonction pour basculer entre Médical, Lot, et Divers
function basculerInterfaceProduit(type) {
    if (type === 'divers') {
        $('.section-medicale').hide();
        $('#bloc-alertes').hide();
        $('#section-entree-lot').hide();
        $('[name="id_sous_famille"], [name="id_fournisseur"]').prop('required', false);
        $('#est_divers').val('1');
        $('#seuil_alerte').val('0');
        $('#delai_peremption').val('120');
        $('#nom_commercial').attr('placeholder', 'ex: BIC CRISTAL BLEU');
    } else {
        $('.section-medicale').show();
        $('#bloc-alertes').show();
        
        if(type === 'lot') {
            $('#section-entree-lot').show();
            $('#calcul-unites-reel').show();
        } else {
            $('#section-entree-lot').hide();
            $('#calcul-unites-reel').hide();
        }
        
        $('[name="id_sous_famille"], [name="id_fournisseur"]').prop('required', true);
        $('#est_divers').val('0');
        $('#seuil_alerte').val('5');
        $('#delai_peremption').val('6');
        $('#nom_commercial').attr('placeholder', 'ex: DOLIPRANE 500mg');
    }
    calculerTotalUnites();
}

// Fonction de calcul en temps réel des unités (Boites * Coef)
function calculerTotalUnites() {
    const qtyBoites = parseInt($('#qty_lot_boites').val()) || 0;
    const estDetail = $('#est_detail').val();
    const coef = parseInt($('#coefficient_division').val()) || 1;
    
    let total;
    if (estDetail === "1") {
        total = qtyBoites * coef;
        $('#label-calcul').text(total + " unités (ex: plaquettes)");
    } else {
        total = qtyBoites;
        $('#label-calcul').text(total + " boîtes");
    }
}

// Affichage/Masquage du champ coefficient
function toggleCoef(val) {
    if (val == '1') {
        $('#group-coef').fadeIn();
        $('#group-prix-detail').fadeIn(); // Affiche le champ prix détail
    } else {
        $('#group-coef').hide();
        $('#group-prix-detail').hide(); // Cache le champ prix détail
        $('#coefficient_division').val(1);
        $('#prix_unitaire_detail').val(0);
    }
}

// Soumission du formulaire
$('#form-add-produit').on('submit', function(e) {
    e.preventDefault();

    let type = $('input[name="type_prod_select"]:checked').val();

    // 👉 Si produit divers → envoi direct
    if (type === 'divers') {
        envoyerFormulaire($(this));
        return;
    }

    // 👉 Sinon → ouvrir modal
    Swal.fire({
        title: 'Informations complémentaires',
        html: `
            <input type="number" id="swal_prix_achat" class="swal2-input" placeholder="Prix d'achat (PA)">
            <input type="number" id="swal_stock_max" class="swal2-input" placeholder="Stock maximum">
            <input type="text" id="swal_emplacement" class="swal2-input" placeholder="Emplacement (Rayon)">
        `,
        confirmButtonText: 'Valider',
        focusConfirm: false,
        preConfirm: () => {
            let pa = document.getElementById('swal_prix_achat').value;
            let stock = document.getElementById('swal_stock_max').value;
            let emplacement = document.getElementById('swal_emplacement').value;

            if (!pa || !stock || !emplacement) {
                Swal.showValidationMessage('Tous les champs sont obligatoires');
                return false;
            }

            return { pa, stock, emplacement };
        }
    }).then((result) => {
        if (result.isConfirmed) {

            // 👉 Injecter dans le formulaire
            $('#prix_achat').val(result.value.pa);
            $('input[name="stock_max"]').val(result.value.stock);
            $('input[name="emplacement"]').val(result.value.emplacement);

            envoyerFormulaire($('#form-add-produit'));
        }
    });
});

function envoyerFormulaire(form) {

    const formData = form.serialize();

    $.post('ajax_produits.php', formData, function(response) {
        console.log(response)
        if(response.status === 'success') {
            Swal.fire({
                title: 'Succès !',
                text: response.message,
                icon: 'success',
                timer: 2000,
                showConfirmButton: false
            }).then(() => {
                location.reload();
            });

        } else {
            Swal.fire('Erreur', response.message, 'error');
        }

    }, 'json');
}




// 1. AFFICHER LES DÉTAILS D'UNE COMMANDE
function voirDetails(id_cmd) {
    // On mémorise l'ID dans le champ caché pour que validerTouteLaReception puisse le lire
    //console.log(id_cmd)
    $('#id_commande_active').val(id_cmd);
    
    // On prépare l'interface
    $('#titre-detail-commande').text("Gestion de la Commande #" + id_cmd);
    $('#corps-detail-commande').html('<tr><td colspan="4" style="text-align:center;">Chargement des produits...</td></tr>');
    $('#zone-details-commande').fadeIn(); 

    // Appel AJAX pour récupérer les lignes de la commande
    $.post('ajax_produits.php', { 
        action: 'get_details_reception', 
        id_commande: id_cmd 
    }, function(res) {
        if(res.status === 'success') {
            let html = '';
            if(res.data.length === 0) {
                html = '<tr><td colspan="4" style="text-align:center;">Aucun produit dans cette commande.</td></tr>';
            } else {
                res.data.forEach(item => {
                    // Note : On utilise item.id_ligne et item.id_produit pour la précision
                    html += `
                    <tr class="ligne-reception" data-id-p="${item.id_produit}" data-id-ligne="${item.id_ligne}">
                        <td><b>${item.nom_commercial}</b></td>
                        <td>
                            <input type="number" class="qte-recue" value="${item.quantite_commandee}" 
                                   style="width:80px; border:2px solid #0984e3; border-radius:4px; text-align:center;">
                        </td>
                        <td>
                            <input type="text" class="lot-reception" placeholder="N° Lot" 
                                   style="width:120px; border:2px solid #0984e3; border-radius:4px;">
                        </td>
                        <td>
                            <input type="date" class="peremption-reception" value="${res.date_suggested}" 
                                   style="border:2px solid #0984e3; border-radius:4px;">
                        </td>
                    </tr>`;
                });
            }
            $('#corps-detail-commande').html(html);
        } else {
            Swal.fire("Erreur", res.message, "error");
        }
    }, 'json');
}

// 1. Rechercher le produit dans l'inventaire
function rechercherProduitInv(val) {
    if (val.length < 2) {
        $('#inv-results').hide();
        return;
    }
     //console.log("object")
    $.post('ajax_produits.php', { action: 'rechercher_inv', query: val }, function(data) {
        //console.log(data)
        let html = '';
        data.forEach(p => {
                html += `<div class="result-item" onclick="selectionnerProduitInv(${p.id_produit}, '${p.nom_commercial.replace(/'/g, "\\'")}')">
                            ${p.nom_commercial} (${p.stock_reel} en stock)
                        </div>`;
            });
        $('#inv-results').html(html).show();
    }, 'json');
}

// 2. Afficher les lots du produit sélectionné
function selectionnerProduitInv(id, nom) {
    $('#inv-search').val(nom);
    $('#inv-results').hide();

    $.post('ajax_produits.php', { action: 'get_lots_inv', id_produit: id }, function(lots) {
        let html = '';
        lots.forEach(l => {
            html += `
                <tr data-id-stock="${l.id_stock}" data-id-prod="${id}">
                    <td><b>${l.numero_lot}</b><br><small>Exp: ${l.date_peremption}</small></td>
                    <td><input type="number" class="theo-qte" value="${l.quantite_disponible}" readonly style="background:#eee; width:80px;"></td>
                    <td><input type="number" class="reel-qte" value="${l.quantite_disponible}" oninput="calculerEcartInv(this)" style="width:80px; border:1px solid #3182ce;"></td>
                    <td><b class="ecart-label">0</b></td>
                </tr>`;
        });
        $('#inv-body-lots').html(html);
        $('#inv-details').fadeIn();
    }, 'json');
}

// 3. Calculer l'écart en temps réel
function calculerEcartInv(input) {
    const row = $(input).closest('tr');
    const theo = parseInt(row.find('.theo-qte').val());
    const reel = parseInt($(input).val()) || 0;
    const ecart = reel - theo;
    
    const label = row.find('.ecart-label');
    label.text(ecart > 0 ? '+' + ecart : ecart);
    label.css('color', ecart < 0 ? 'red' : (ecart > 0 ? 'green' : 'black'));
}

// 4. Valider et envoyer l'ajustement
function validerAjustement() {
    let ajustements = [];
    $('#inv-body-lots tr').each(function() {
        const theo = parseInt($(this).find('.theo-qte').val());
        const reel = parseInt($(this).find('.reel-qte').val());
        
        if (theo !== reel) {
            ajustements.push({
                id_stock: $(this).data('id-stock'),
                id_produit: $(this).data('id-prod'),
                quantite_reelle: reel
            });
        }
    });

    if (ajustements.length === 0) {
        Swal.fire('Info', 'Aucun écart constaté.', 'info');
        return;
    }

    $.post('ajax_produits.php', { action: 'valider_inventaire', donnees: ajustements }, function(res) {
        if (res.status === 'success') {
            Swal.fire('Succès', 'Inventaire mis à jour !', 'success').then(() => location.reload());
        }
    }, 'json');
}

// Fonction pour tout cocher/décocher d'un coup
function toggleAllChecks(source) {
    let checkboxes = document.querySelectorAll('.commande-check');
    checkboxes.forEach(cb => {
        cb.checked = source.checked;
    });
}

// Fonction pour compter les produits sélectionnés (Visuel)
function majCompteur() {
    let n = $('.commande-check:checked').length;
    if(n > 0) {
        $('#btn-envoi-texte').html(`ENVOYER LA COMMANDE (${n} produits)`);
    } else {
        $('#btn-envoi-texte').html(`ENVOYER LA COMMANDE`);
    }
}

$(document).on('change', '.commande-check', function() {
    majCompteur();
})

function chargerCommandePourReception(id_commande) {
    $('#panel-reception').show(); // On affiche le panneau
    $('#liste-reception-active').html('<tr><td colspan="6" style="text-align:center;">Chargement des lignes...</td></tr>');

    $.post('ajax_produits.php', {
        action: 'get_details_reception',
        id_commande: id_commande
    }, function(res) {
        if(res.status === 'success') {
            let html = '';
            res.data.forEach(item => {
                html += `
                <tr data-id-ligne="${item.id_ligne}" data-id-p="${item.id_produit}">
                    <td><b>${item.nom_commercial}</b></td>
                    <td style="text-align:center;">${item.quantite_commandee}</td>
                    <td>
                        <input type="number" class="qte-recue" value="${item.quantite_commandee}" style="width:70px;">
                    </td>
                    <td>
                        <input type="text" class="lot-reception" placeholder="Ex: LOT-123" style="width:100px;">
                    </td>
                    <td>
                        <input type="date" class="peremption-reception" value="${res.date_suggested}">
                    </td>
                    <td>
                        <button onclick="validerLigneUnique(this)" class="tab-btn" style="background:#27ae60; color:white;">
                            <i class="fas fa-check"></i>
                        </button>
                    </td>
                </tr>`;
            });
            $('#liste-reception-active').html(html);
        }
    }, 'json');
}



// 2. VALIDER LA RÉCEPTION ET METTRE À JOUR LE STOCK
function validerTouteLaReception() {
    // On récupère l'ID de la commande que nous avons stocké dans le champ caché
    let id_cmd = $('#id_commande_active').val();
    let reception = [];
    //console.log(id_cmd)
    if (!id_cmd || id_cmd === "") {
        Swal.fire("Erreur", "ID de commande introuvable.", "error");
        return;
    }

    // On boucle sur chaque ligne du tableau de détails
    $('#corps-detail-commande tr.ligne-reception').each(function() {
        let row = $(this);
        let qte = row.find('.qte-recue').val();
        let lot = row.find('.lot-reception').val();
        let peremption = row.find('.peremption-reception').val();

        if (qte > 0) {
            reception.push({
                id_ligne: row.data('id-ligne'),
                id_p: row.data('id-p'),
                qte: qte,
                lot: lot,
                peremption: peremption
            });
        }
    });

    if (reception.length === 0) {
        Swal.fire("Attention", "Veuillez saisir au moins une quantité reçue.", "warning");
        return;
    }

    // Vérification : tous les lots doivent être remplis pour valider
    let lotManquant = reception.some(item => item.lot.trim() === "");
    if (lotManquant) {
        Swal.fire("Lots manquants", "Veuillez renseigner un numéro de lot pour chaque produit reçu.", "info");
        return;
    }

    // Envoi final au serveur
    $.post('ajax_produits.php', {
        action: 'enregistrer_reception_finale',
        id_commande: id_cmd,
        lignes: reception
    }, function(res) {
       // ... (dans le succès de l'AJAX de validerTouteLaReception)
if (res.status === 'success') {
    Swal.fire({
        title: "Réception validée !",
        text: "Voulez-vous imprimer le bon de réception ?",
        icon: "success",
        showCancelButton: true,
        confirmButtonText: 'Imprimer le Bon',
        cancelButtonText: 'Fermer'
    }).then((print) => {
        // On ouvre le nouveau fichier PDF de réception
        window.open('generer_pdf_reception.php?id_commande=' + id_cmd, '_blank');
        location.reload(); 
    });
} else {
            Swal.fire("Erreur", res.message, "error");
        }
    }, 'json');
}

// 1. Charger la liste initiale
function chargerToutesLesCommandes() {
    $.post('ajax_produits.php', { action: 'get_commandes_en_cours' }, function(res) {
       // console.log(res)
        if(res.status === 'success') {
            let html = '';
            res.data.forEach(c => {
                html += `
                <tr>
                    <td><b>#${c.id_commande}</b></td>
                    <td>${c.nom_fournisseur}</td>
                    <td>${c.date_envoi}</td>
                    <td><span class="badge" style="background:#f1c40f;">${c.statut}</span></td>
                    <td>
                        <button onclick="voirDetails(${c.id_commande})" class="tab-btn" style="background:#0984e3; color:white;">
                            <i class="fas fa-eye"></i> Détails
                        </button>
                    </td>
                </tr>`;
            });
            $('#liste-commandes-en-cours').html(html);
        }
    }, 'json');
}

// 2. Voir les détails pour modifier/valider
let commandeActive = null;



// 3. Annuler la commande
function annulerCommande() {
    Swal.fire({
        title: 'Êtes-vous sûr ?',
        text: "Cette commande sera marquée comme annulée.",
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        confirmButtonText: 'Oui, annuler !'
    }).then((result) => {
        if (result.isConfirmed) {
            $.post('ajax_produits.php', { action: 'changer_statut_commande', id_commande: commandeActive, statut: 'annulé' }, function() {
                Swal.fire('Annulée', 'La commande a été annulée.', 'success');
                location.reload();
            });
        }
    });
}

chargerToutesLesCommandes()
    </script>


    <script>
function rechercherProduitAchat(val) {
    if (val.length < 2) { $('#res-search').hide(); return; }
    $.post('ajax_produits.php', { action: 'rechercher_produit_achat', query: val }, function(data) {
        let html = '';
        data.forEach(p => {
            // Conversion immédiate pour l'affichage utilisateur
            let paUnitaire = parseFloat(p.prix_achat) || 0;
            let coef = parseFloat(p.coefficient_division) || 1;
            let prixBoiteAffiche = paUnitaire;

            html += `<div class="result-item" onclick="ajouterLigne(${p.id_produit}, '${p.nom_commercial.replace(/'/g, "\\")}', ${coef}, ${prixBoiteAffiche})">
                        ${p.nom_commercial} <small>(Dernier PA Boîte: ${prixBoiteAffiche.toLocaleString()} FCFA)</small>
                    </div>`;
        });
        $('#res-search').html(html).show();
    }, 'json');
}

function ajouterLigne(id, nom, coef, prixBoite) {
    $('#res-search').hide();
    $('#search-prod').val('');
    
    let html = `
        <tr class="item-row" data-id="${id}" data-coef="${coef}" data-ancien-pa-unitaire="${prixBoite / coef}">
            <td><b>${nom}</b></td>
            <td><input type="text" class="form-control form-control-sm in-lot" required></td>
            <td><input type="date" class="form-control form-control-sm in-peremp" required></td>
            <td class="text-muted text-center">${prixBoite}</td> 
            <td><input type="number" class="form-control form-control-sm in-qty-boite" value="1" min="1" oninput="calculerTotaux()"></td>
            <td>
                <input type="number" class="form-control form-control-sm in-prix-boite" value="${prixBoite}" oninput="calculerTotaux()">
            </td>
            <td class="row-total fw-bold">0</td>
            <td><button type="button" class="btn btn-sm btn-outline-danger" onclick="$(this).closest('tr').remove(); calculerTotaux();">&times;</button></td>
        </tr>`;
    $('#table-items tbody').append(html);
    calculerTotaux();
}

function verifierChangementPrix(input) {
    let row = $(input).closest('tr');
    let coef = parseFloat(row.data('coef'));
    let ancienPaUnitaire = parseFloat(row.data('ancien-pa-unitaire'));
    let nouveauPaUnitaire = parseFloat($(input).val()) / coef;

    // Comparaison sur les prix unitaires pour plus de précision
    if (Math.abs(nouveauPaUnitaire - ancienPaUnitaire) > 0.1) {
        $(input).css({'border': '2px solid #f39c12', 'background': '#fff3cd'});
    } else {
        $(input).css({'border': '1px solid #ccc', 'background': '#fff'});
    }
    calculerTotaux();
}

function calculerTotaux() {
    let globalTotal = 0;
    $('.item-row').each(function() {
        let q = parseFloat($(this).find('.in-qty-boite').val()) || 0; // Nb de boîtes
        let p = parseFloat($(this).find('.in-prix-boite').val()) || 0; // Prix de la boîte
        let t = q * p;
        $(this).find('.row-total').text(t.toLocaleString());
        globalTotal += t;
    });
    $('#grand-total').text(globalTotal.toLocaleString());
    
    // Mise à jour automatique du montant versé si mode comptant
    if($('#mode_reglement').val() === 'comptant') {
        $('#montant_verse').val(globalTotal);
    }
}

// La fonction finaliserAchat() reste la même que précédemment

async function finaliserAchat() {
    let items = [];
    let prixModifie = false;

    // 1. Parcours des lignes du tableau
    $('.item-row').each(function() {
        let id = $(this).data('id');
        let coef = parseFloat($(this).data('coef')) || 1;
        let qteBoite = parseFloat($(this).find('.in-qty-boite').val()) || 0;
        let prixBoiteSaisi = parseFloat($(this).find('.in-prix-boite').val()) || 0;
        
        // On récupère l'ancien prix unitaire stocké dans le data-attribute de la ligne
        let ancienPaUnitaire = parseFloat($(this).data('ancien-pa-unitaire')) || 0;
        let nouveauPaUnitaire = prixBoiteSaisi / coef;

        // Détection de changement de prix (seuil de 0.1 pour éviter les arrondis JS)
        if(Math.abs(nouveauPaUnitaire - ancienPaUnitaire) > 0.1) {
            prixModifie = true;
        }

        items.push({
            id_produit: id,
            numero_lot: $(this).find('.in-lot').val(),
            date_peremption: $(this).find('.in-peremp').val(),
            coef: coef,
            quantite_unitaire: qteBoite * coef, // On envoie le détail au PHP
            prix_achat_boite: prixBoiteSaisi    // On envoie le prix boîte saisi
        });
    });

    if(items.length === 0) {
        Swal.fire('Attention', 'Votre panier est vide', 'warning');
        return;
    }

    let methodeCalcul = 'remplacer'; 

    // 2. Gestion du changement de prix avec SweetAlert2
    if (prixModifie) {
        const result = await Swal.fire({
            title: 'Changement de prix détecté',
            text: "Comment voulez-vous mettre à jour le prix d'achat dans la fiche produit ?",
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: 'Calculer le PMP (Moyenne)',
            cancelButtonText: 'Remplacer (Dernier PA)',
            confirmButtonColor: '#3085d6',
            cancelButtonColor: '#aaa',
        });
        
        // Si l'utilisateur clique sur "Calculer PMP", result.isConfirmed sera vrai
        methodeCalcul = result.isConfirmed ? 'pmp' : 'remplacer';
        
        // Si on ferme la boîte sans choisir (ESC ou clic dehors), on annule tout
        if (result.isDismissed && result.dismiss === Swal.DismissReason.backdrop) return;
    }

    // 3. Envoi AJAX avec TOUS les champs du formulaire
    $.post('ajax_produits.php', {
    action:          'valider_reception_achat',
    id_fournisseur:  $('#id_fournisseur').val(),
    num_facture:     $('#num_facture').val(),
    date_achat:      $('#date_achat').val(),
    mode_reglement:  $('#mode_reglement').val(),
    montant_verse:   $('#montant_verse').val(),
    date_echeance:   $('input[name="date_echeance"]').val(),
    total_global:    $('#grand-total').text().replace(/\s/g, ''),
    methode_pa:      methodeCalcul,
    lignes:          items
}, function(res) {

    if (res.status !== 'success') {
        Swal.fire('Erreur', res.message, 'error');
        return;
    }

    // -------------------------------------------------------
    // Pas de changement de prix de vente a proposer ?
    // -> Succes direct
    // -------------------------------------------------------
    if (!res.produits_pa_modifie || res.produits_pa_modifie.length === 0) {
        Swal.fire({
            icon: 'success',
            title:'Reception validee',
            text: 'Le stock et les charges ont ete mis a jour.',
            confirmButtonText: 'OK'
        }).then(() => location.reload());
        return;
    }

    // -------------------------------------------------------
    // Construire la modale de revision des prix de vente
    // -------------------------------------------------------
    afficherModalePrixVente(res.produits_pa_modifie);

}, 'json');

}

function afficherModalePrixVente(produits) {

    // Construction des lignes du tableau
    let lignesHtml = '';
    produits.forEach((p, i) => {
        const hausse = p.nouveau_pa_boite > p.ancien_pa_boite;
        const fleche = hausse
            ? '<span style="color:#dc2626;font-size:11px;font-weight:700;">+</span>'
            : '<span style="color:#16a34a;font-size:11px;font-weight:700;">-</span>';

        const hasDetail = p.coef > 1 && p.prix_vente_detail > 0;

        lignesHtml += `
        <div class="pvente-row" data-index="${i}" style="
            background:#fff;
            border:1px solid #e5e7eb;
            border-radius:7px;
            padding:12px 14px;
            margin-bottom:10px;
            font-family:'DM Sans',sans-serif;
        ">
            <!-- Nom produit -->
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:10px;">
                <div>
                    <div style="font-size:13px;font-weight:700;color:#111827;">${p.nom_commercial}</div>
                    <div style="font-size:10px;color:#9ca3af;margin-top:1px;">
                        PA boite : 
                        <span style="font-family:'DM Mono',monospace;color:#6b7280;text-decoration:line-through;">
                            ${p.ancien_pa_boite.toLocaleString()} F
                        </span>
                        &nbsp;${fleche}&nbsp;
                        <span style="font-family:'DM Mono',monospace;font-weight:700;color:${hausse ? '#dc2626' : '#16a34a'};">
                            ${p.nouveau_pa_boite.toLocaleString()} F
                        </span>
                    </div>
                </div>
                <label style="display:flex;align-items:center;gap:6px;cursor:pointer;font-size:11px;color:#6b7280;">
                    <input type="checkbox" class="chk-appliquer" data-index="${i}"
                        checked
                        style="width:14px;height:14px;accent-color:#1d4ed8;cursor:pointer;">
                    Appliquer
                </label>
            </div>

            <!-- Grille prix -->
            <div style="display:grid;grid-template-columns:${hasDetail ? '1fr 1fr' : '1fr'};gap:10px;">

                <!-- Prix vente boite -->
                <div>
                    <div style="font-size:9.5px;font-weight:700;color:#6b7280;text-transform:uppercase;
                                letter-spacing:.4px;margin-bottom:4px;">
                        Prix vente boite
                    </div>
                    <div style="display:flex;align-items:center;gap:5px;">
                        <span style="font-size:10px;color:#9ca3af;font-family:'DM Mono',monospace;
                                     text-decoration:line-through;">
                            ${p.prix_vente_boite.toLocaleString()} F
                        </span>
                        <span style="color:#6b7280;font-size:10px;">-></span>
                        <input type="number"
                            class="in-pvente-boite"
                            data-index="${i}"
                            value="${p.prix_vente_suggere_boite}"
                            style="flex:1;padding:5px 7px;border:1px solid #d1d5db;border-radius:5px;
                                   font-family:'DM Mono',monospace;font-size:12px;font-weight:700;
                                   color:#111827;outline:none;width:100%;
                                   background:#f0fdf4;border-color:#86efac;">
                        <span style="font-size:11px;color:#6b7280;flex-shrink:0;">F</span>
                    </div>
                    <!-- Indicateur marge -->
                    <div class="marge-indicator-boite" data-index="${i}"
                        style="font-size:9.5px;color:#16a34a;margin-top:3px;font-family:'DM Mono',monospace;">
                        ${calculerMargeLabel(p.prix_vente_suggere_boite, p.nouveau_pa_boite)}
                    </div>
                </div>

                <!-- Prix vente detail -->
                ${hasDetail ? `
                <div>
                    <div style="font-size:9.5px;font-weight:700;color:#6b7280;text-transform:uppercase;
                                letter-spacing:.4px;margin-bottom:4px;">
                        Prix vente detail
                    </div>
                    <div style="display:flex;align-items:center;gap:5px;">
                        <span style="font-size:10px;color:#9ca3af;font-family:'DM Mono',monospace;
                                     text-decoration:line-through;">
                            ${p.prix_vente_detail.toLocaleString()} F
                        </span>
                        <span style="color:#6b7280;font-size:10px;">-></span>
                        <input type="number"
                            class="in-pvente-detail"
                            data-index="${i}"
                            value="${p.prix_vente_suggere_detail}"
                            style="flex:1;padding:5px 7px;border:1px solid #d1d5db;border-radius:5px;
                                   font-family:'DM Mono',monospace;font-size:12px;font-weight:700;
                                   color:#111827;outline:none;width:100%;
                                   background:#f0fdf4;border-color:#86efac;">
                        <span style="font-size:11px;color:#6b7280;flex-shrink:0;">F</span>
                    </div>
                    <div class="marge-indicator-detail" data-index="${i}"
                        style="font-size:9.5px;color:#16a34a;margin-top:3px;font-family:'DM Mono',monospace;">
                        ${p.prix_vente_suggere_detail > 0
                            ? calculerMargeDetailLabel(p.prix_vente_suggere_detail, p.nouveau_pa_boite, p.coef)
                            : ''}
                    </div>
                </div>` : ''}
            </div>
        </div>`;
    });

    Swal.fire({
        html: `
            <div style="font-family:'DM Sans',sans-serif; text-align:left;">

                <!-- Header alerte -->
                <div style="background:#fffbeb;border:1px solid #fde68a;border-radius:7px;
                            padding:12px 14px;margin-bottom:16px;display:flex;gap:10px;align-items:flex-start;">
                    <div style="background:#fef3c7;width:32px;height:32px;border-radius:6px;
                                display:flex;align-items:center;justify-content:center;
                                flex-shrink:0;font-size:15px;color:#d97706;">
                        <i class="fas fa-tags"></i>
                    </div>
                    <div>
                        <div style="font-size:13px;font-weight:700;color:#92400e;margin-bottom:2px;">
                            Revision des prix de vente
                        </div>
                        <div style="font-size:11px;color:#b45309;line-height:1.45;">
                            Le prix d'achat de <strong>${produits.length} produit(s)</strong>
                            a change. Verifiez et ajustez vos prix de vente ci-dessous.
                            Les valeurs suggerees conservent votre marge actuelle.
                        </div>
                    </div>
                </div>

                <!-- Lignes produits -->
                <div id="pvente-list" style="max-height:360px;overflow-y:auto;
                                             padding-right:4px;margin-bottom:4px;">
                    ${lignesHtml}
                </div>
            </div>`,
        width: 600,
        showCancelButton: true,
        confirmButtonText: '<i class="fas fa-check" style="margin-right:5px;"></i> Mettre a jour les prix',
        cancelButtonText: 'Ignorer, terminer quand meme',
        confirmButtonColor: '#1d4ed8',
        cancelButtonColor: '#6b7280',
        focusConfirm: false,

        didOpen: () => {

            // Listener : mise a jour indicateur marge en temps reel
            $(document).on('input.pvente', '.in-pvente-boite', function() {
                const idx  = $(this).data('index');
                const pv   = parseFloat($(this).val()) || 0;
                const pa   = produits[idx].nouveau_pa_boite;
                $(`.marge-indicator-boite[data-index="${idx}"]`)
                    .html(calculerMargeLabel(pv, pa));
            });

            $(document).on('input.pvente', '.in-pvente-detail', function() {
                const idx  = $(this).data('index');
                const pv   = parseFloat($(this).val()) || 0;
                const pa   = produits[idx].nouveau_pa_boite;
                const coef = produits[idx].coef;
                $(`.marge-indicator-detail[data-index="${idx}"]`)
                    .html(calculerMargeDetailLabel(pv, pa, coef));
            });

            // Listener : checkbox desactive/grise la ligne
            $(document).on('change.pvente', '.chk-appliquer', function() {
                const idx     = $(this).data('index');
                const checked = $(this).is(':checked');
                const row     = $(`.pvente-row[data-index="${idx}"]`);
                row.css('opacity', checked ? '1' : '0.45');
                row.find('input[type="number"]').prop('disabled', !checked);
            });
        },

        willClose: () => {
            $(document).off('input.pvente change.pvente');
        },

        preConfirm: () => {
            const lignesMAJ = [];

            produits.forEach((p, i) => {
                const checked = $(`.chk-appliquer[data-index="${i}"]`).is(':checked');
                if (!checked) return;

                const pvBoite  = parseFloat($(`.in-pvente-boite[data-index="${i}"]`).val())  || 0;
                const pvDetail = parseFloat($(`.in-pvente-detail[data-index="${i}"]`).val()) || 0;

                if (pvBoite <= 0) {
                    Swal.showValidationMessage(
                        `Prix de vente invalide pour : ${p.nom_commercial}`
                    );
                    return false;
                }

                // Avertissement marge negative
                if (pvBoite < p.nouveau_pa_boite) {
                    Swal.showValidationMessage(
                        `Attention : le prix de vente de "${p.nom_commercial}" (${pvBoite} F) est inferieur au prix d'achat (${p.nouveau_pa_boite} F) !`
                    );
                    return false;
                }

                lignesMAJ.push({
                    id_produit:       p.id_produit,
                    prix_vente_boite:  pvBoite,
                    prix_vente_detail: pvDetail
                });
            });

            return lignesMAJ;
        }

    }).then(result => {

        // Ignorer
        if (result.isDismissed) {
            Swal.fire({
                icon: 'success',
                title:'Reception validee',
                text: 'Les prix de vente n\'ont pas ete modifies.',
                timer: 2000,
                showConfirmButton: false
            }).then(() => location.reload());
            return;
        }

        const lignesMAJ = result.value;

        // Aucune ligne cochee
        if (!lignesMAJ || lignesMAJ.length === 0) {
            location.reload();
            return;
        }

        // Envoi AJAX mise a jour prix de vente
        $.post('ajax_produits.php', {
            action: 'maj_prix_vente',
            lignes: lignesMAJ
        }, function(res2) {
            if (res2.status === 'success') {
                Swal.fire({
                    icon: 'success',

                    html: `
                        <div style="font-size:12px;color:#374151;line-height:1.6;">
                            Reception enregistree <strong>et</strong>
                            prix de vente mis a jour pour
                            <strong>${lignesMAJ.length} produit(s)</strong>.
                        </div>`,
                    confirmButtonText: 'Parfait',
                    confirmButtonColor: '#16a34a'
                }).then(() => location.reload());
            } else {
                Swal.fire('Erreur MAJ prix', res2.message, 'error');
            }
        }, 'json');
    });
}

function calculerMargeLabel(pvBoite, paBoite) {
    if (!pvBoite || !paBoite || paBoite <= 0) return '';
    const marge   = ((pvBoite - paBoite) / paBoite * 100).toFixed(1);
    const couleur = marge < 0 ? '#dc2626' : marge < 15 ? '#d97706' : '#16a34a';
    const icone   = marge < 0 ? 'fa-arrow-down' : 'fa-arrow-up';
    return `<i class="fas ${icone}" style="margin-right:2px;"></i>
            Marge boite : <span style="color:${couleur};font-weight:700;">${marge}%</span>`;
}

function calculerMargeDetailLabel(pvDetail, paBoite, coef) {
    if (!pvDetail || !paBoite || !coef || paBoite <= 0) return '';
    const paDetail = paBoite / coef;
    const marge    = ((pvDetail - paDetail) / paDetail * 100).toFixed(1);
    const couleur  = marge < 0 ? '#dc2626' : marge < 15 ? '#d97706' : '#16a34a';
    const icone    = marge < 0 ? 'fa-arrow-down' : 'fa-arrow-up';
    return `<i class="fas ${icone}" style="margin-right:2px;"></i>
            Marge detail : <span style="color:${couleur};font-weight:700;">${marge}%</span>`;
}


function gererTypePaiement() {
    const mode = $('#mode_reglement').val();
    const total = parseFloat($('#grand-total').text().replace(/\s/g, '')) || 0;

    if (mode === 'comptant') {
        $('#montant_verse').val(total).attr('readonly', true);
        $('#div-echeance').hide();
    } else if (mode === 'credit') {
        $('#montant_verse').val(0).attr('readonly', true);
        $('#div-echeance').show();
    } else { // Partiel
        $('#montant_verse').val(0).attr('readonly', false);
        $('#div-echeance').show();
    }
}

// Ajoutez cet événement dans votre fichier JS
$('#num_facture').on('blur', function() {
    let num = $(this).val();
    let idFournisseur = $('#id_fournisseur').val();

    if (num !== '' && idFournisseur !== '') {
        $.post('ajax_produits.php', { 
            action: 'verifier_doublon_facture', 
            num_facture: num, 
            id_fournisseur: idFournisseur 
        }, function(res) {
            if (res.existe) {
                Swal.fire({
                    icon: 'warning',
                    title: 'Doublon détecté',
                    text: 'Ce numéro de facture a déjà été enregistré pour ce fournisseur.',
                    confirmButtonColor: '#d33'
                });
                $('#num_facture').addClass('is-invalid').val('');
            } else {
                $('#num_facture').removeClass('is-invalid').addClass('is-valid');
            }
        }, 'json');
    }
});

function chargerDerniersAchats() {
    $.post('ajax_produits.php', { action: 'liste_derniers_achats' }, function(data) {
        let html = '';
        data.forEach(a => {
            let badgeClass = a.statut_paiement === 'paye' ? 'bg-success' : (a.statut_paiement === 'partiel' ? 'bg-warning' : 'bg-danger');
            html += `
                <tr>
                    <td>${new Date(a.date_achat).toLocaleDateString()}</td>
                    <td>${a.nom_fournisseur}</td>
                    <td><b>${a.num_facture}</b></td>
                    <td>${parseFloat(a.montant_total).toLocaleString()} FCFA</td>
                    <td><span class="badge ${badgeClass}">${a.statut_paiement}</span></td>
                    <td>
                        <button class="btn btn-sm btn-info" onclick="voirDetailsAchat(${a.id_achat})">
                            <i class="fas fa-eye"></i>
                        </button>
                    </td>
                </tr>`;
        });
        $('#last-achats-body').html(html);
    }, 'json');
}

// Appeler au chargement
$(document).ready(function() {
    chargerDerniersAchats();
});

function voirDetailsAchat(id_achat) {
    $.post('ajax_produits.php', { action: 'details_facture_achat', id_achat: id_achat }, function(data) {
        if(data.error) {
            Swal.fire('Erreur', data.error, 'error');
            return;
        }

        // Remplissage des infos générales
        $('#modal-num-facture').text(data.info.num_facture);
        $('#modal-fournisseur').text(data.info.nom_fournisseur);
        $('#modal-date').text(new Date(data.info.date_achat).toLocaleDateString());

        // Remplissage du tableau des produits
        let html = '';
        data.lignes.forEach(l => {
            html += `
                <tr>
                    <td>${l.nom_commercial}</td>
                    <td><span class="badge bg-light text-dark">${l.numero_lot}</span></td>
                    <td>${l.date_peremption}</td>
                    <td class="text-center">${l.quantite_recue}</td>
                    <td class="text-end">${parseFloat(l.prix_achat_unitaire).toLocaleString()} FCFA</td>
                </tr>`;
        });
        $('#modal-corps-detail').html(html);

        // Affichage de la modal
        new bootstrap.Modal(document.getElementById('modalDetailAchat')).show();
    }, 'json');
}


function chargerDettes() {
    $.post('ajax_produits.php', { action: 'liste_dettes_fournisseurs' }, function(data) {
        let html = '';
        data.forEach(d => {
            let reste = d.montant_total - d.montant_paye;
            html += `
                <tr>
                    <td>${d.nom_fournisseur}</td>
                    <td><b>${d.num_facture}</b></td>
                    <td>${d.date_achat}</td>
                    <td class="text-danger fw-bold">${d.date_echeance || '-'}</td>
                    <td>${parseFloat(d.montant_total).toLocaleString()}</td>
                    <td>${parseFloat(d.montant_paye).toLocaleString()}</td>
                    <td class="text-primary fw-bold">${reste.toLocaleString()}</td>
                    <td>
                        <button class="btn btn-sm btn-success" onclick="ouvrirModalPaiement(${d.id_achat}, '${d.num_facture}', '${d.nom_fournisseur}', ${reste})">
                            <i class="fas fa-hand-holding-usd"></i> Payer
                        </button>
                    </td>
                </tr>`;
        });
        $('#liste-dettes').html(html);
    }, 'json');
}

function ouvrirModalPaiement(id, num, foun, reste) {
    $('#pay-id-achat').val(id);
    $('#pay-num').text(num);
    $('#pay-fournisseur').text(foun);
    $('#pay-reste').text(reste.toLocaleString());
    $('#montant-reglement').val(reste).max = reste;
    new bootstrap.Modal($('#modalPaiement')).show();
}

function validerReglement() {
    let id = $('#pay-id-achat').val();
    let montant = $('#montant-reglement').val();

    $.post('ajax_produits.php', { action: 'enregistrer_reglement_fournisseur', id_achat: id, montant: montant }, function(res) {
        if(res.status === 'success') {
            Swal.fire('Payé !', 'Le règlement a été enregistré.', 'success');
            $('.modal').modal('hide');
            chargerDettes();
        }
    }, 'json');
}


function chercherLotPourRetour(val) {
    if (val.length < 2) { $('#res-search-lot').hide(); return; }
    $.post('ajax_produits.php', { action: 'chercher_lot_stock', query: val }, function(data) {
        let html = '';
        data.forEach(s => {
            html += `
                <div class="result-item" onclick="ajouterLigneRetour(${s.id_stock}, '${s.nom_commercial}', '${s.numero_lot}', ${s.quantite_disponible})">
                    <b>${s.nom_commercial}</b> - Lot: ${s.numero_lot} (Dispo: ${s.quantite_disponible})
                </div>`;
        });
        $('#res-search-lot').html(html).show();
    }, 'json');
}

function ajouterLigneRetour(idStock, nom, lot, dispo) {
    $('#res-search-lot').hide();
    let html = `
        <tr class="row-retour" data-id-stock="${idStock}">
            <td><b>${nom}</b><br><small class="text-muted">Lot: ${lot}</small></td>
            <td><b class="text-primary">${dispo}</b></td>
            <td><input type="number" class="form-control in-qty" max="${dispo}" value="1"></td>
            <td>
                <select class="form-select in-motif">
                    <option value="Avarié/Abîmé">Avarié/Abîmé</option>
                    <option value="Erreur Livraison">Erreur Livraison</option>
                    <option value="Péremption Proche">Péremption Proche</option>
                </select>
            </td>
            <td><button class="btn btn-sm btn-outline-danger" onclick="$(this).closest('tr').remove()">&times;</button></td>
        </tr>`;
    $('#body-retour').append(html);
}

function validerTousLesRetours() {
    let retours = [];
    $('.row-retour').each(function() {
        retours.push({
            id_stock: $(this).data('id-stock'),
            quantite: $(this).find('.in-qty').val(),
            motif: $(this).find('.in-motif').val()
        });
    });

    if(retours.length === 0) return;

    $.post('ajax_produits.php', { action: 'valider_retour_fournisseur', items: retours }, function(res) {
        if(res.status === 'success') {
            Swal.fire('Succès', 'Stock mis à jour et bon de retour généré.', 'success').then(() => location.reload());
        }
    }, 'json');
}

// Variable globale pour stocker le panier temporaire
// Variable globale pour stocker le panier temporaire
let panierAppro = [];

/*function chargerSuggestions() {
    $.post('ajax_produits.php', { action: 'generer_suggestions_appro' }, function(data) {
        let html = '';
        console.log(data)
        data.forEach(p => {
            let totalLogique = parseFloat(p.stock_reel) + parseFloat(p.en_cours);
            let proposition = p.stock_max - totalLogique;
            if(proposition < 0) proposition = 0;

            let badgeRupture = p.stock_reel <= 0 ? '<span class="badge bg-danger">RUPTURE</span>' : '';
            
            html += `
                <tr>
                    <td><b>${p.nom_commercial}</b> ${badgeRupture}</td>
                    <td>${p.stock_reel}</td>
                    <td>${p.seuil_alerte}</td>
                    <td>
                        <input type="number" class="form-control form-control-sm prop-qty" 
                               id="prop-qty-${p.id_produit}" value="${proposition}">
                    </td>
                    <td>
                        <button class="btn btn-sm btn-outline-primary" 
                                onclick="ajouterAuPanier(${p.id_produit}, '${p.nom_commercial.replace(/'/g, "\\'")}', $('#prop-qty-${p.id_produit}').val())">
                            <i class="fas fa-plus"></i>
                        </button>
                    </td>
                </tr>`;
        });
        $('#body-suggestions').html(html);
    }, 'json');
}*/

function ajouterAuPanier(id, nom, qty) {
    if(qty <= 0) return Swal.fire('Erreur', 'La quantité doit être supérieure à 0', 'error');
    
    // Vérifier si déjà présent
    let index = panierAppro.findIndex(i => i.id === id);
    if(index > -1) {
        panierAppro[index].qty = qty;
    } else {
        panierAppro.push({ id: id, nom: nom, qty: qty });
    }
    renderPanier();
}

function renderPanier() {
    let html = '';
    panierAppro.forEach((item, index) => {
        html += `
            <li class="list-group-item d-flex justify-content-between align-items-center small">
                ${item.nom} <b>x${item.qty}</b>
                <button class="btn btn-sm text-danger" onclick="panierAppro.splice(${index}, 1); renderPanier();">&times;</button>
            </li>`;
    });
    $('#panier-liste').html(html || '<li class="list-group-item text-center text-muted">Vide</li>');
}

function genererBonDeCommande() {
    let fdr = $('#appro-fournisseur').val();
    if(!fdr) return Swal.fire('Attention', 'Sélectionnez un fournisseur', 'warning');
    if(panierAppro.length === 0) return Swal.fire('Vide', 'Le panier est vide', 'info');

    $.post('ajax_produits.php', { 
        action: 'creer_bon_commande', 
        id_fournisseur: fdr, 
        lignes: panierAppro 
    }, function(res) {
        if(res.status === 'success') {
            Swal.fire('Succès', 'Le bon de commande a été généré.', 'success');
            panierAppro = [];
            renderPanier();
            chargerSuggestions(); // Rafraîchir la liste
        }
    }, 'json');
}

// Modifier votre fonction showPanel pour charger les suggestions au clic
function showPanel(panelId) {
    $('.panel').hide();
    $('#panel-' + panelId).show();
    
    if(panelId === 'suggestions') {
        chargerSuggestions();
        //console.log(panelId)
    }
}

chargerDettes();

function ajouterLigneReception() {
    const html = `
    <tr>
        <td>
            <input type="text" class="form-control p-search" placeholder="Nom du produit...">
        </td>
        <td><input type="number" class="form-control qte-fact" value="0" onchange="calculerManquant(this)"></td>
        <td><input type="number" class="form-control qte-rec" value="0" onchange="calculerManquant(this)"></td>
        <td><input type="text" class="form-control" placeholder="Ex: LOT24-B1"></td>
        <td><input type="date" class="form-control"></td>
        <td>
            <select class="form-select">
                <option value="conforme">✅ Conforme</option>
                <option value="abime">📦 Colis Abîmé</option>
                <option value="peremption_proche">⚠️ Péremption Courte</option>
                <option value="erreur_dosage">❌ Erreur Dosage</option>
            </select>
        </td>
        <td class="text-center">
            <span class="badge-manquant bg-light text-dark">0</span>
        </td>
    </tr>`;
    document.getElementById('corps-reception').insertAdjacentHTML('beforeend', html);
}

function calculerManquant(input) {
    const row = input.closest('tr');
    const qteFact = parseInt(row.querySelector('.qte-fact').value) || 0;
    const qteRec = parseInt(row.querySelector('.qte-rec').value) || 0;
    const diff = qteFact - qteRec;
    
    const badge = row.querySelector('.badge-manquant');
    badge.innerText = diff;
    
    if(diff > 0) {
        badge.className = "badge bg-danger"; // Manquant détecté
    } else if (diff < 0) {
        badge.className = "badge bg-warning"; // Surplus reçu
    } else {
        badge.className = "badge bg-success"; // Conforme
    }
}

let itemsBL = [];

function rechercheProduitBL(val) {
    if(val.length < 2) return;
    // Ici, vous appelez votre API de recherche existante
    // Simulation d'ajout pour l'exemple :
    // ajouterProduitAuBL(id, nom, prix);
}

function ajouterProduitAuBL(id, nom, prix) {
    const table = document.getElementById('table-bl-items');
    const row = `
        <tr id="row-bl-${id}">
            <td><strong>${nom}</strong><br><small class="text-muted">ID: ${id}</small></td>
            <td><select class="form-select form-select-sm"><option>Boîte</option><option>Unité</option></select></td>
            <td><input type="number" class="form-control qte-bl" value="1" onchange="calculerTotauxBL()"></td>
            <td><input type="number" class="form-control prix-bl" value="${prix}" onchange="calculerTotauxBL()"></td>
            <td class="total-ligne-bl fw-bold">${prix}</td>
            <td><button onclick="this.closest('tr').remove(); calculerTotauxBL();" class="btn btn-link text-danger"><i class="fas fa-trash"></i></button></td>
        </tr>
    `;
    table.insertAdjacentHTML('beforeend', row);
    calculerTotauxBL();
}

function calculerTotauxBL() {
    let grandTotal = 0;
    document.querySelectorAll('#table-bl-items tr').forEach(row => {
        const qte = parseFloat(row.querySelector('.qte-bl').value) || 0;
        const prix = parseFloat(row.querySelector('.prix-bl').value) || 0;
        const totalLigne = qte * prix;
        row.querySelector('.total-ligne-bl').innerText = totalLigne.toFixed(2);
        grandTotal += totalLigne;
    });
    document.getElementById('total-bl-ht').innerText = grandTotal.toLocaleString() + " FCFA";
}

function transformerEnReception() {
    // 1. Récupérer les informations d'en-tête
    const fournisseur = document.getElementById('bl-fournisseur').value;
    const numBL = document.getElementById('bl-ref').value;
    
    if(!numBL) {
        Swal.fire('Attention', 'Veuillez saisir un numéro de BL avant de continuer', 'warning');
        return;
    }

    // 2. Préparer le transfert vers le panel Réception
    const itemsBL = document.querySelectorAll('#table-bl-items tr');
    const corpsReception = document.getElementById('corps-reception');
    corpsReception.innerHTML = ''; // Nettoyer la table de réception

    itemsBL.forEach(row => {
        const nom = row.cells[0].querySelector('strong').innerText;
        const qteAttendue = row.querySelector('.qte-bl').value;
        const prixAchat = row.querySelector('.prix-bl').value;

        // Création de la ligne de contrôle qualité
        const ligneRec = `
            <tr>
                <td>
                    <strong>${nom}</strong>
                    <input type="hidden" class="rec-prix" value="${prixAchat}">
                </td>
                <td><input type="number" class="form-control qte-fact" value="${qteAttendue}" readonly style="background:#eee;"></td>
                <td><input type="number" class="form-control qte-rec" value="${qteAttendue}" onchange="calculerManquant(this)"></td>
                <td><input type="text" class="form-control rec-lot" placeholder="N° Lot" required></td>
                <td><input type="date" class="form-control rec-peremption" required></td>
                <td>
                    <select class="form-select rec-etat">
                        <option value="conforme">✅ Conforme</option>
                        <option value="abime">📦 Colis Abîmé</option>
                        <option value="peremption_proche">⚠️ Péremption Courte</option>
                    </select>
                </td>
                <td class="text-center"><span class="badge bg-success badge-manquant">0</span></td>
            </tr>`;
        corpsReception.insertAdjacentHTML('beforeend', ligneRec);
    });

    // 3. Basculer l'affichage des panels
    showPanel('reception');
    
    // Remplir les champs d'en-tête du panel réception
    document.getElementById('rec-fournisseur').value = fournisseur;
    document.getElementById('rec-bl').value = numBL;
    
    Swal.fire({
        title: 'Transfert réussi',
        text: 'Vérifiez maintenant les quantités physiques et saisissez les numéros de lots.',
        icon: 'success',
        timer: 2000,
        showConfirmButton: false
    });
}
    </script>

    <script>

      function htmlEsc(str) {
    if (!str) return '';
    return String(str)
        .replace(/&/g,'&amp;').replace(/</g,'&lt;')
        .replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function kpiCell(label, value, color, icon) {
    return `
    <div style="padding:14px 16px;border-right:1px solid #e2e8f0;text-align:center;">
        <div style="font-size:10px;text-transform:uppercase;letter-spacing:1px;color:#94a3b8;margin-bottom:4px;">
            <i class="fas ${icon}" style="margin-right:3px;color:${color};"></i>${label}
        </div>
        <div style="font-size:16px;font-weight:800;color:${color};">${value}</div>
    </div>`;
}
      
function voirDetailsCommande(id) {

    // Ouvrir le modal immédiatement avec un skeleton loader
    Swal.fire({
        title: '',
        html: `
        <div id="cmd-modal-root" style="font-family:'Segoe UI',sans-serif;text-align:left;">

            <!-- EN-TETE SKELETON -->
            <div style="background:#f8fafc;border-radius:10px;padding:16px;margin-bottom:16px;animation:pulse 1.5s infinite;">
                <div style="height:14px;background:#e2e8f0;border-radius:4px;width:60%;margin-bottom:8px;"></div>
                <div style="height:10px;background:#e2e8f0;border-radius:4px;width:40%;"></div>
            </div>
            <div style="height:160px;background:#f8fafc;border-radius:10px;animation:pulse 1.5s infinite;"></div>

            <style>
                @keyframes pulse {
                    0%,100%{opacity:1} 50%{opacity:.5}
                }
            </style>
        </div>`,
        width          : '780px',
        padding        : '0',
        showConfirmButton: false,
        showCloseButton: true,
        customClass    : { popup: 'swal-details-commande' }
    });

    // Requête AJAX
    $.ajax({
        url      : 'ajax_produits.php',
        type     : 'POST',
        dataType : 'json',
        data     : { action: 'get_details_commande_full', id: id },
        success  : function(res) {
            if (!res.success) {
                $('#cmd-modal-root').html(
                    `<div style="text-align:center;padding:30px;color:#dc2626;">
                        <i class="fas fa-exclamation-triangle fa-2x"></i>
                        <p style="margin-top:10px;">${res.message}</p>
                    </div>`
                );
                return;
            }

            const c      = res.commande;
            const lignes = res.lignes;

            // ── Couleurs statut ──
            const statutConfig = {
                'en_attente' : { bg: '#fff7ed', color: '#c2410c', label: 'EN ATTENTE',  icon: 'fa-clock'         },
                'livree'     : { bg: '#f0fdf4', color: '#15803d', label: 'LIVREE',       icon: 'fa-check-double'  },
                'terminee'   : { bg: '#f0fdf4', color: '#15803d', label: 'TERMINEE',     icon: 'fa-check-double'  },
                'annulee'    : { bg: '#fff1f2', color: '#be123c', label: 'ANNULEE',      icon: 'fa-ban'           },
                'partielle'  : { bg: '#eff6ff', color: '#1d4ed8', label: 'PARTIELLE',    icon: 'fa-hourglass-half'},
            };
            const sc = statutConfig[c.statut] || { bg:'#f1f5f9', color:'#475569', label: c.statut.toUpperCase(), icon:'fa-circle' };

            // ── Calculs globaux ──
            let totalCmd    = 0;
            let totalRecu   = 0;
            let totalEcart  = 0;
            let nbManquants = 0;
            let nbExces     = 0;
            let nbConforme  = 0;

            lignes.forEach(l => {
                const qCmd   = parseFloat(l.quantite_commandee) || 0;
                const qRecu  = parseFloat(l.quantite_recue)     || 0;
                const paRef  = parseFloat(l.pa_reference)       || 0;
                totalCmd   += qCmd * paRef;
                totalRecu  += qRecu * paRef;
                if (qRecu === 0 && qCmd > 0) nbManquants++;
                else if (qRecu > qCmd)        nbExces++;
                else if (qRecu === qCmd)      nbConforme++;
                totalEcart += (qRecu - qCmd);
            });

            const tauxReception = totalCmd > 0 ? Math.min(100, (totalRecu / totalCmd) * 100).toFixed(0) : 0;
            const barColor      = tauxReception >= 100 ? '#16a34a' : (tauxReception >= 50 ? '#d97706' : '#dc2626');

            // ── Lignes produits ──
            let lignesHtml = '';
            lignes.forEach((l, idx) => {
                const qCmd   = parseFloat(l.quantite_commandee) || 0;
                const qRecu  = parseFloat(l.quantite_recue)     || 0;
                const paRef  = parseFloat(l.pa_reference)       || 0;
                const diff   = qRecu - qCmd;
                const pctRec = qCmd > 0 ? ((qRecu / qCmd) * 100).toFixed(0) : 0;

                // Badge état ligne
                let ligneBadge = '';
                let ligneBg    = '';
                if (l.reception_faite && qRecu === 0) {
                    ligneBadge = `<span style="background:#fee2e2;color:#b91c1c;padding:2px 8px;border-radius:99px;font-size:10px;font-weight:700;">NON RECU</span>`;
                    ligneBg = 'background:#fff5f5;';
                } else if (l.reception_faite && diff > 0) {
                    ligneBadge = `<span style="background:#dbeafe;color:#1d4ed8;padding:2px 8px;border-radius:99px;font-size:10px;font-weight:700;">EXCES +${diff}</span>`;
                    ligneBg = 'background:#f0f7ff;';
                } else if (l.reception_faite && diff < 0) {
                    ligneBadge = `<span style="background:#fef3c7;color:#b45309;padding:2px 8px;border-radius:99px;font-size:10px;font-weight:700;">MANQUE ${diff}</span>`;
                    ligneBg = 'background:#fffbeb;';
                } else if (l.reception_faite) {
                    ligneBadge = `<span style="background:#dcfce7;color:#15803d;padding:2px 8px;border-radius:99px;font-size:10px;font-weight:700;">OK</span>`;
                }

                // Mini barre progression réception
                const barW = Math.min(100, pctRec);
                const barC = barW >= 100 ? '#16a34a' : (barW > 0 ? '#f59e0b' : '#e5e7eb');

                const sousTotalCmd = (qCmd * paRef);

                lignesHtml += `
                <tr style="border-bottom:1px solid #f1f5f9;${ligneBg}transition:background .15s;">
                    <td style="padding:10px 8px;">
                        <div style="font-weight:600;font-size:13px;color:#0f172a;">${htmlEsc(l.nom_commercial)}</div>
                        ${l.molecule ? `<div style="font-size:10px;color:#94a3b8;margin-top:2px;">${htmlEsc(l.molecule)}</div>` : ''}
                        ${l.numero_lot ? `<div style="font-size:10px;color:#64748b;margin-top:2px;">Lot : <strong>${htmlEsc(l.numero_lot)}</strong></div>` : ''}
                        ${l.date_peremption ? `<div style="font-size:10px;color:#64748b;">Peremp. : ${l.date_peremption_fmt}</div>` : ''}
                    </td>
                    <td style="padding:10px 8px;text-align:center;font-weight:700;font-size:14px;color:#0f172a;">
                        ${qCmd}
                    </td>
                    <td style="padding:10px 8px;text-align:center;">
                        ${l.reception_faite
                            ? `<span style="font-weight:700;font-size:14px;color:${diff < 0 ? '#d97706' : (diff > 0 ? '#2563eb' : '#16a34a')};">${qRecu}</span>`
                            : `<span style="color:#94a3b8;font-size:12px;">—</span>`
                        }
                    </td>
                    <td style="padding:10px 8px;text-align:center;">
                        ${ligneBadge}
                        ${l.reception_faite ? `
                        <div style="margin-top:5px;">
                            <div style="background:#e9ecef;border-radius:4px;height:4px;overflow:hidden;width:60px;margin:auto;">
                                <div style="width:${barW}%;background:${barC};height:4px;"></div>
                            </div>
                            <div style="font-size:9px;color:#94a3b8;margin-top:2px;">${pctRec}%</div>
                        </div>` : ''}
                    </td>
                    <td style="padding:10px 8px;text-align:right;font-size:12px;color:#475569;">
                        ${paRef > 0 ? paRef.toLocaleString('fr-FR') + ' F' : '<span style="color:#cbd5e1">—</span>'}
                    </td>
                    <td style="padding:10px 8px;text-align:right;font-weight:600;font-size:13px;color:#0f172a;">
                        ${sousTotalCmd > 0 ? sousTotalCmd.toLocaleString('fr-FR') + ' F' : '<span style="color:#cbd5e1">—</span>'}
                    </td>
                </tr>`;
            });

            // ── HTML final du modal ──
            const html = `
            <div id="cmd-modal-root" style="font-family:'Segoe UI',system-ui,sans-serif;text-align:left;">
                <style>
                    .cmd-modal-root tr:hover td { background:rgba(9,132,227,.04) !important; }
                </style>

                <!-- ══ HEADER ══ -->
                <div style="background:linear-gradient(135deg,#0f172a 0%,#1e3a5f 100%);
                            padding:20px 24px;color:#fff;margin:-1px -1px 0;border-radius:8px 8px 0 0;">
                    <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:10px;">
                        <div>
                            <div style="font-size:11px;opacity:.6;text-transform:uppercase;letter-spacing:2px;margin-bottom:4px;">
                                Bon de Commande
                            </div>
                            <div style="font-size:24px;font-weight:800;letter-spacing:-0.5px;">
                                #${String(c.id_commande).padStart(5,'0')}
                            </div>
                            <div style="margin-top:6px;font-size:13px;opacity:.8;">
                                <i class="fas fa-calendar-alt" style="margin-right:5px;"></i>${c.date_commande_fmt}
                                &nbsp;&nbsp;
                                <i class="fas fa-user" style="margin-right:5px;"></i>${htmlEsc(c.nom_caissier || 'N/A')}
                            </div>
                        </div>
                        <div style="text-align:right;">
                            <div style="background:${sc.bg};color:${sc.color};
                                        border-radius:99px;padding:5px 14px;
                                        font-size:12px;font-weight:700;display:inline-block;">
                                <i class="fas ${sc.icon}" style="margin-right:5px;"></i>${sc.label}
                            </div>
                            <div style="margin-top:10px;font-size:12px;opacity:.7;">
                                <i class="fas fa-building" style="margin-right:4px;"></i>${htmlEsc(c.nom_fournisseur || 'N/A')}
                            </div>
                            ${c.telephone_fournisseur
                                ? `<div style="font-size:11px;opacity:.6;margin-top:3px;">
                                    <i class="fas fa-phone" style="margin-right:4px;"></i>${htmlEsc(c.telephone_fournisseur)}</div>`
                                : ''}
                        </div>
                    </div>
                </div>

                <!-- ══ KPI BAR ══ -->
                <div style="display:grid;grid-template-columns:repeat(4,1fr);border-bottom:1px solid #e2e8f0;">
                    ${kpiCell('Produits', lignes.length, '#0f172a', 'fa-boxes')}
                    ${kpiCell('Conforme(s)', nbConforme, '#16a34a', 'fa-check-circle')}
                    ${kpiCell('Manquant(s)', nbManquants, '#dc2626', 'fa-exclamation-triangle')}
                    ${kpiCell('Total estimé', totalCmd.toLocaleString('fr-FR') + ' F', '#0984e3', 'fa-money-bill-wave')}
                </div>

                <!-- ══ BARRE TAUX DE RECEPTION ══ -->
                ${c.reception_faite ? `
                <div style="padding:12px 20px;background:#f8fafc;border-bottom:1px solid #e2e8f0;">
                    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:5px;">
                        <span style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:1px;color:#64748b;">
                            Taux de réception
                        </span>
                        <span style="font-size:14px;font-weight:800;color:${barColor};">${tauxReception}%</span>
                    </div>
                    <div style="background:#e9ecef;border-radius:99px;height:8px;overflow:hidden;">
                        <div style="width:${tauxReception}%;background:${barColor};height:8px;border-radius:99px;
                                    transition:width .6s ease;"></div>
                    </div>
                    <div style="display:flex;justify-content:space-between;margin-top:4px;font-size:10px;color:#94a3b8;">
                        <span>Valeur commandée : ${totalCmd.toLocaleString('fr-FR')} FCFA</span>
                        <span>Valeur reçue : ${totalRecu.toLocaleString('fr-FR')} FCFA</span>
                    </div>
                </div>` : ''}

                <!-- ══ TABLE LIGNES ══ -->
                <div style="max-height:340px;overflow-y:auto;">
                    <table style="width:100%;border-collapse:collapse;font-size:13px;" class="cmd-modal-root">
                        <thead>
                            <tr style="background:#f8fafc;position:sticky;top:0;z-index:2;border-bottom:2px solid #e2e8f0;">
                                <th style="padding:10px 8px;text-align:left;font-size:10px;text-transform:uppercase;letter-spacing:1px;color:#64748b;font-weight:700;">Produit</th>
                                <th style="padding:10px 8px;text-align:center;font-size:10px;text-transform:uppercase;letter-spacing:1px;color:#64748b;font-weight:700;">Qte Cmd</th>
                                <th style="padding:10px 8px;text-align:center;font-size:10px;text-transform:uppercase;letter-spacing:1px;color:#64748b;font-weight:700;">Qte Reçue</th>
                                <th style="padding:10px 8px;text-align:center;font-size:10px;text-transform:uppercase;letter-spacing:1px;color:#64748b;font-weight:700;">Etat</th>
                                <th style="padding:10px 8px;text-align:right;font-size:10px;text-transform:uppercase;letter-spacing:1px;color:#64748b;font-weight:700;">PA Ref</th>
                                <th style="padding:10px 8px;text-align:right;font-size:10px;text-transform:uppercase;letter-spacing:1px;color:#64748b;font-weight:700;">Sous-Total</th>
                            </tr>
                        </thead>
                        <tbody>${lignesHtml}</tbody>
                        <tfoot>
                            <tr style="background:#f8fafc;border-top:2px solid #0f172a;">
                                <td colspan="5" style="padding:12px 8px;text-align:right;font-weight:800;font-size:13px;color:#0f172a;">
                                    TOTAL ESTIME :
                                </td>
                                <td style="padding:12px 8px;text-align:right;font-weight:800;font-size:15px;color:#0984e3;">
                                    ${totalCmd.toLocaleString('fr-FR')} FCFA
                                </td>
                            </tr>
                        </tfoot>
                    </table>
                </div>

                <!-- ══ PIED DU MODAL : ACTIONS ══ -->
                <div style="padding:14px 20px;background:#f8fafc;border-top:1px solid #e2e8f0;
                            display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;
                            border-radius:0 0 8px 8px;">
                    <div style="font-size:11px;color:#94a3b8;">
                        <i class="fas fa-info-circle" style="margin-right:4px;"></i>
                        Créée le ${c.date_commande_fmt} — Référence interne #${c.id_commande}
                        ${c.date_reception ? ` — Reçue le ${c.date_reception_fmt}` : ''}
                    </div>
                    <div style="display:flex;gap:8px;flex-wrap:wrap;">
                        ${c.statut === 'en_attente' ? `
                        <button onclick="Swal.close();ouvrirReception(${c.id_commande});"
                            style="background:#16a34a;color:#fff;border:none;border-radius:6px;
                                   padding:8px 14px;cursor:pointer;font-size:12px;font-weight:600;">
                            <i class="fas fa-truck" style="margin-right:5px;"></i>Receptionner
                        </button>
                        <a href="generer_pdf_commande.php?id_commande=${c.id_commande}" target="_blank"
                            style="background:#0984e3;color:#fff;border-radius:6px;
                                   padding:8px 14px;cursor:pointer;font-size:12px;font-weight:600;
                                   text-decoration:none;display:inline-flex;align-items:center;gap:5px;">
                            <i class="fas fa-file-pdf"></i>PDF
                        </a>
                        <button onclick="Swal.close();annulerCommande(${c.id_commande});"
                            style="background:#dc2626;color:#fff;border:none;border-radius:6px;
                                   padding:8px 14px;cursor:pointer;font-size:12px;font-weight:600;">
                            <i class="fas fa-times-circle" style="margin-right:5px;"></i>Annuler
                        </button>` : ''}
                        ${c.statut === 'terminee' || c.statut === 'livree' ? `
                        <a href="generer_pdf_commande.php?id_commande=${c.id_commande}" target="_blank"
                            style="background:#0984e3;color:#fff;border-radius:6px;
                                   padding:8px 14px;cursor:pointer;font-size:12px;font-weight:600;
                                   text-decoration:none;display:inline-flex;align-items:center;gap:5px;">
                            <i class="fas fa-file-pdf"></i>PDF
                        </a>` : ''}
                        <button onclick="Swal.close();"
                            style="background:#f1f5f9;color:#64748b;border:none;border-radius:6px;
                                   padding:8px 14px;cursor:pointer;font-size:12px;font-weight:600;">
                            Fermer
                        </button>
                    </div>
                </div>

            </div>`;

            // Injecter dans le modal déjà ouvert
            Swal.update({ html: html });
        },
        error: function() {
            $('#cmd-modal-root').html(
                `<div style="text-align:center;padding:30px;color:#dc2626;">
                    <i class="fas fa-plug fa-2x"></i>
                    <p style="margin-top:10px;">Erreur de connexion au serveur.</p>
                </div>`
            );
        }
    });
}

</script>

<script>
function validerReception(idCmd) {
    let lignes = [];
    $('.ligne-reception').each(function() {
        lignes.push({
            id_p: $(this).data('id-p'),
            qte: $(this).find('.qte-recue').val(),
            lot: $(this).find('.lot').val(),
            peremption: $(this).find('.peremption').val()
        });
    });

    $.post('ajax_produits.php', {
        action: 'finaliser_reception_stock',
        id_commande: idCmd,
        lignes: lignes
    }, function(res) {
        if(res.status === 'success') {
            Swal.fire("Succès", "Stock mis à jour et commande clôturée !", "success").then(() => {
                window.location.href = 'liste_commandes.php';
            });
        }
    }, 'json');
}

function annulerCommande(id) {
    Swal.fire({
        title: 'Annuler la commande #' + id + ' ?',
        text: "Cette action est irréversible et la commande sera désactivée.",
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        cancelButtonColor: '#3085d6',
        confirmButtonText: 'Oui, annuler !',
        cancelButtonText: 'Retour'
    }).then((result) => {
        if (result.isConfirmed) {
            $.post('ajax_produits.php', { 
                action: 'annuler_commande', 
                id_commande: id 
            }, function(res) {
                if(res.status === 'success') {
                    Swal.fire('Annulée !', 'La commande a été marquée comme annulée.', 'success').then(() => {
                        location.reload(); // On recharge pour voir le changement de style
                    });
                } else {
                    Swal.fire('Erreur', res.message, 'error');
                }
            }, 'json');
        }
    });
}

function filtrerCarnet() {
    let recherche = $('#recherche_commande').val().toLowerCase();
    let statutChoisi = $('#filtre_statut').val().toLowerCase();
    let debut = $('#date_debut').val();
    let fin = $('#date_fin').val();
    let count = 0;

    $('.ligne-commande').each(function() {
        let texteLigne = $(this).text().toLowerCase();
        let statutLigne = $(this).find('.statut-badge').text().trim().toLowerCase();
        let dateLigne = $(this).data('date'); // Format YYYY-MM-DD

        // Logique des filtres
        let matchTexte = texteLigne.indexOf(recherche) > -1;
        let matchStatut = (statutChoisi === 'tous' || statutLigne === statutChoisi);
        
        // Comparaison des dates
        let matchDate = true;
        if (debut && dateLigne < debut) matchDate = false;
        if (fin && dateLigne > fin) matchDate = false;

        if (matchTexte && matchStatut && matchDate) {
            $(this).show();
            count++;
        } else {
            $(this).hide();
        }
    });
    
    $('#compteur_commandes').html(count + " BC");
}



function exporterExcel() {
    let csv = [];
    // 1. Récupération des entêtes de colonnes
    let rows = document.querySelectorAll("#panel-Carnet table tr");
    
    // On parcourt toutes les lignes du tableau
    for (let i = 0; i < rows.length; i++) {
        // On vérifie si la ligne est affichée (non masquée par le filtre)
        if (rows[i].style.display !== 'none') {
            let row = [], cols = rows[i].querySelectorAll("td, th");
            
            for (let j = 0; j < cols.length - 1; j++) { // -1 pour ne pas exporter la colonne 'Actions'
                // Nettoyage du texte (suppression des espaces et virgules gênantes)
                let data = cols[j].innerText.replace(/(\r\n|\n|\r)/gm, "").replace(/,/g, ".");
                row.push(data);
            }
            csv.push(row.join(","));
        }
    }

    // 2. Création et téléchargement du fichier
    let csv_string = csv.join("\n");
    let filename = 'export_commandes_' + new Date().toLocaleDateString() + '.csv';
    let link = document.createElement("a");
    
    // Ajout du BOM UTF-8 pour que les accents s'affichent bien dans Excel
    let blob = new Blob(["\ufeff", csv_string], { type: 'text/csv;charset=utf-8;' });
    
    if (navigator.msSaveBlob) { // IE 10+
        navigator.msSaveBlob(blob, filename);
    } else {
        link.href = URL.createObjectURL(blob);
        link.setAttribute("download", filename);
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
    }
}

// Lancer le calcul au chargement de la page
$(document).ready(function() {
    filtrerCarnet();
});

function ouvrirReception(id) {
    // 1. On change de panel (vers ton panel de réception)
    showPanel('reception'); 
    
    // 2. On peut aussi remplir un champ caché ou lancer le chargement des données
    // de la commande dans le panel de réception
    if (typeof chargerDonneesReception === "function") {
        chargerDonneesReception(id);
    } else {
        console.log("Redirection vers la réception de la commande #" + id);
        // Si ton système utilise des pages séparées, utilise :
        // window.location.href = 'reception_commande.php?id=' + id;
    }
}

function chargerDonneesReception(id) {
    $('#num_commande_reception').text('#' + id);
    $('#corps_reception').html('<tr><td colspan="5" style="padding:20px; text-align:center;">Chargement...</td></tr>');

    $.post('ajax_produits.php', { 
        action: 'get_lignes_pour_reception', 
        id_commande: id 
    }, function(res) {
        let html = '';
        res.forEach(function(ligne) {
            let pa = parseFloat(ligne.prix_achat) || 0;
            let coef = parseFloat(ligne.coefficient_division) || 1; // Assurez-vous d'avoir le coefficient ici
            let totalLigne = pa * ligne.quantite_commandee; // Ajustez le total selon le coefficient

            html += `
            <tr class="ligne-stock" data-id-p="${ligne.id_produit}" style="border-bottom:1px solid #ddd;">
                <td style="padding:10px;">${ligne.nom_commercial}</td>
                <td style="padding:10px; text-align:center;">
                    <input type="number" class="pa_r" value="${pa}" style="width:80px; padding:5px; border:1px solid #27ae60;" 
                        oninput="recalculerTotalReception(this)">
                </td>
                <td style="padding:10px; text-align:center;">
                    <input type="number" class="qte_r" value="${ligne.quantite_commandee}" style="width:70px; padding:5px;" 
                        oninput="recalculerTotalReception(this)">
                </td>
                <td style="padding:10px; text-align:right; font-weight:bold;">
                    <span class="sous_total_r">${totalLigne.toLocaleString()}</span> F
                </td>
                <td style="padding:10px;">
                    <input type="text" class="lot_r" placeholder="Lot" style="width:90px; padding:5px;">
                    <input type="date" class="date_p_r" style="padding:4px;">
                </td>
            </tr>`;
        });
        $('#corps_reception').html(html);
        calculerSommeTouteLaReception();
    }, 'json');
}

// Fonction pour mettre à jour le prix total de la ligne si la quantité ou le prix change
function recalculerTotalReception(element) {
    let tr = $(element).closest('tr');
    let pa = parseFloat(tr.find('.pa_r').val()) || 0;
    let qte = parseInt(tr.find('.qte_r').val()) || 0;
    let coef = parseFloat(tr.data('coef')) || 1; // Récupérer le coefficient de division
    let total = (pa * qte) / coef; // Calculer le sous-total

    tr.find('.sous_total_r').text(total.toLocaleString());
    calculerSommeTouteLaReception(); // Recalculer le total global ici
}

function calculerSommeTouteLaReception() {
    let grandTotal = 0;

    $('.ligne-stock').each(function() {
        let pa = parseFloat($(this).find('.pa_r').val()) || 0;
        let qte = parseInt($(this).find('.qte_r').val()) || 0;
        let coef = parseFloat($(this).data('coef')) || 1; // Assurez-vous de récupérer le coefficient
        grandTotal += (pa * qte) / coef; // Inclure le coefficient dans le grand total
    });

    $('#total_global_reception').text(grandTotal.toLocaleString());
}


async function validerStock() {

    const id_commande = $('#num_commande_reception').text().replace('#', '').trim();
    if (!id_commande) {
        Swal.fire('Erreur', 'Aucune commande sélectionnée.', 'error');
        return;
    }

    // ──────────────────────────────────────────────────────────────
    // ETAPE 1 — Collecter les données saisies dans le formulaire
    // ──────────────────────────────────────────────────────────────
    let lignesSaisies = [];

    $('.ligne-stock').each(function() {
        const $row = $(this);
        lignesSaisies.push({
            id_produit  : parseInt($row.data('id-p'))          || 0,
            nom_produit : $row.data('nom-produit')             || 'Produit',
            qte_recue   : parseFloat($row.find('.qte_r').val()) || 0,
            pa_saisi    : parseFloat($row.find('.pa_r').val())  || 0,
            lot         : $row.find('.lot_r').val()             || '',
            peremption  : $row.find('.date_p_r').val()          || ''
        });
    });

    if (lignesSaisies.length === 0) {
        Swal.fire('Attention', 'Aucune ligne à valider.', 'warning');
        return;
    }

    // ──────────────────────────────────────────────────────────────
    // ETAPE 2 — Charger le bon de commande original pour comparaison
    // ──────────────────────────────────────────────────────────────
    let bonOriginal;
    try {
        bonOriginal = await $.post('get_commande_originale.php',
            { id_commande }, null, 'json');
    } catch (e) {
        Swal.fire('Erreur', 'Impossible de charger le bon de commande.', 'error');
        return;
    }

    if (!bonOriginal.success) {
        Swal.fire('Erreur', bonOriginal.message, 'error');
        return;
    }

    // ──────────────────────────────────────────────────────────────
    // ETAPE 3 — Analyser les écarts (quantité + prix)
    // ──────────────────────────────────────────────────────────────
    const originalMap = {};
    bonOriginal.lignes.forEach(l => {
        originalMap[l.id_produit] = l;
    });

    let ecarts              = [];
    let aPrixModifie        = false;
    let aQteModifiee        = false;
    let aProduitManquant    = false;
    let methodeCalcul       = 'remplacer';

    lignesSaisies.forEach(ligne => {
        const orig = originalMap[ligne.id_produit];
        if (!orig) return;

        const diffQte  = ligne.qte_recue - parseFloat(orig.quantite_commandee);
        const diffPrix = ligne.pa_saisi  - parseFloat(orig.pa_reference);
        const pctPrix  = orig.pa_reference > 0
            ? ((diffPrix / orig.pa_reference) * 100).toFixed(1)
            : 0;

        const hasEcartQte  = Math.abs(diffQte)  > 0.001;
        const hasEcartPrix = Math.abs(diffPrix) > 0.1;

        if (hasEcartQte)  { aQteModifiee  = true; }
        if (hasEcartPrix) { aPrixModifie  = true; }
        if (ligne.qte_recue === 0) { aProduitManquant = true; }

        if (hasEcartQte || hasEcartPrix) {
            ecarts.push({
                nom        : ligne.nom_produit,
                qte_cmd    : parseFloat(orig.quantite_commandee),
                qte_recue  : ligne.qte_recue,
                diff_qte   : diffQte,
                pa_orig    : parseFloat(orig.pa_reference),
                pa_saisi   : ligne.pa_saisi,
                diff_prix  : diffPrix,
                pct_prix   : pctPrix,
                has_qte    : hasEcartQte,
                has_prix   : hasEcartPrix
            });
        }
    });

    // ──────────────────────────────────────────────────────────────
    // ETAPE 4 — Si des écarts existent, afficher la modale de diff
    // ──────────────────────────────────────────────────────────────
    if (ecarts.length > 0) {

        // Construire le tableau HTML des écarts
        let lignesHtml = ecarts.map(e => {
            let qteHtml = '';
            let prixHtml = '';

            if (e.has_qte) {
                const sens    = e.diff_qte > 0 ? '+' : '';
                const couleur = e.diff_qte > 0 ? '#16a34a' : (e.qte_recue === 0 ? '#dc2626' : '#d97706');
                const icone   = e.diff_qte > 0 ? 'up' : 'down';
                qteHtml = `
                    <div style="font-size:13px;">
                        <span style="color:#64748b;">${e.qte_cmd}</span>
                        <span style="margin:0 6px;color:#94a3b8;">-&gt;</span>
                        <strong style="color:${couleur};">${e.qte_recue}</strong>
                        <small style="color:${couleur};margin-left:4px;">(${sens}${e.diff_qte})</small>
                    </div>`;
            } else {
                qteHtml = `<span style="color:#94a3b8;font-size:12px;">${e.qte_cmd} -- OK</span>`;
            }

            if (e.has_prix) {
                const couleur = e.diff_prix > 0 ? '#dc2626' : '#16a34a';
                const sens    = e.diff_prix > 0 ? '+' : '';
                prixHtml = `
                    <div style="font-size:13px;">
                        <span style="color:#64748b;">${e.pa_orig.toLocaleString('fr-FR')} F</span>
                        <span style="margin:0 6px;color:#94a3b8;">-&gt;</span>
                        <strong style="color:${couleur};">${e.pa_saisi.toLocaleString('fr-FR')} F</strong>
                        <small style="color:${couleur};margin-left:4px;">(${sens}${e.pct_prix}%)</small>
                    </div>`;
            } else {
                prixHtml = `<span style="color:#94a3b8;font-size:12px;">${e.pa_saisi.toLocaleString('fr-FR')} F -- OK</span>`;
            }

            const bgRow = e.qte_recue === 0 ? '#fff1f2' : (e.has_prix ? '#fffbeb' : '#f0fdf4');

            return `
            <tr style="background:${bgRow};border-bottom:1px solid #e2e8f0;">
                <td style="padding:8px 10px;font-weight:600;font-size:13px;max-width:160px;">${e.nom}</td>
                <td style="padding:8px 10px;text-align:center;">${qteHtml}</td>
                <td style="padding:8px 10px;text-align:center;">${prixHtml}</td>
            </tr>`;
        }).join('');

        // Badges résumé
        const badgeQte   = aQteModifiee     ? `<span style="background:#fef3c7;color:#b45309;padding:3px 10px;border-radius:99px;font-size:11px;font-weight:700;margin:2px;">Quantites modifiees</span>` : '';
        const badgePrix  = aPrixModifie     ? `<span style="background:#fee2e2;color:#b91c1c;padding:3px 10px;border-radius:99px;font-size:11px;font-weight:700;margin:2px;">Prix modifies</span>` : '';
        const badgeManq  = aProduitManquant ? `<span style="background:#fce7f3;color:#9d174d;padding:3px 10px;border-radius:99px;font-size:11px;font-weight:700;margin:2px;">Produit(s) non recu(s)</span>` : '';

        const diffHtml = `
        <div style="font-family:'DM Sans',sans-serif;">
            <div style="margin-bottom:12px;display:flex;flex-wrap:wrap;gap:4px;justify-content:center;">
                ${badgeQte}${badgePrix}${badgeManq}
            </div>
            <p style="font-size:12px;color:#64748b;margin-bottom:10px;text-align:center;">
                La livraison reelle differe du bon de commande initial sur <strong>${ecarts.length} produit(s)</strong>.
            </p>
            <div style="max-height:260px;overflow-y:auto;border:1px solid #e2e8f0;border-radius:8px;">
                <table style="width:100%;border-collapse:collapse;">
                    <thead>
                        <tr style="background:#f8fafc;position:sticky;top:0;z-index:1;">
                            <th style="padding:8px 10px;font-size:11px;text-align:left;color:#64748b;text-transform:uppercase;letter-spacing:1px;border-bottom:1px solid #e2e8f0;">Produit</th>
                            <th style="padding:8px 10px;font-size:11px;text-align:center;color:#64748b;text-transform:uppercase;letter-spacing:1px;border-bottom:1px solid #e2e8f0;">Qte (cmd vs recu)</th>
                            <th style="padding:8px 10px;font-size:11px;text-align:center;color:#64748b;text-transform:uppercase;letter-spacing:1px;border-bottom:1px solid #e2e8f0;">Prix Achat</th>
                        </tr>
                    </thead>
                    <tbody>${lignesHtml}</tbody>
                </table>
            </div>
            <p style="font-size:11px;color:#94a3b8;margin-top:10px;text-align:center;">
                Ces modifications seront enregistrees dans l'historique des achats.
            </p>
        </div>`;

        const confirmDiff = await Swal.fire({
            title          : 'Ecarts detectes sur la livraison',
            html           : diffHtml,
            icon           : 'warning',
            width          : '640px',
            showCancelButton    : true,
            confirmButtonText   : 'Continuer malgre les ecarts',
            cancelButtonText    : 'Annuler — corriger les saisies',
            confirmButtonColor  : '#0f172a',
            cancelButtonColor   : '#94a3b8',
            customClass    : { popup: 'swal-wide' }
        });

        if (!confirmDiff.isConfirmed) return; // L'utilisateur corrige
    }

    // ──────────────────────────────────────────────────────────────
    // ETAPE 5 — Choix PMP / Remplacer (si prix modifié)
    // ──────────────────────────────────────────────────────────────
    if (aPrixModifie) {
        const resPrix = await Swal.fire({
            title    : 'Mise a jour du Prix d\'Achat',
            html     : `
                <p style="font-size:13px;color:#475569;margin-bottom:16px;">
                    Le prix d'achat de certains produits a change.<br>
                    Choisissez la methode de mise a jour du <strong>prix de reference</strong> dans la fiche produit.
                </p>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;text-align:left;">
                    <div style="border:2px solid #e2e8f0;border-radius:10px;padding:14px;cursor:pointer;" id="card-remplacer">
                        <div style="font-weight:700;color:#dc2626;margin-bottom:4px;">Remplacer</div>
                        <div style="font-size:12px;color:#64748b;">Le nouveau prix ecrase directement l'ancien. Adapte si l'ancien PA n'est plus representatif.</div>
                    </div>
                    <div style="border:2px solid #e2e8f0;border-radius:10px;padding:14px;cursor:pointer;" id="card-pmp">
                        <div style="font-weight:700;color:#16a34a;margin-bottom:4px;">PMP (Prix Moyen Pondere)</div>
                        <div style="font-size:12px;color:#64748b;">Calcule la moyenne ponderee entre le stock existant et le nouvel arrivage. Recommande.</div>
                    </div>
                </div>`,
            icon           : 'question',
            width          : '520px',
            showDenyButton : true,
            showCancelButton    : true,
            confirmButtonText   : 'Remplacer',
            denyButtonText      : 'Utiliser le PMP',
            cancelButtonText    : 'Annuler',
            confirmButtonColor  : '#dc2626',
            denyButtonColor     : '#16a34a',
            cancelButtonColor   : '#94a3b8',
        });

        if (resPrix.isConfirmed)     { methodeCalcul = 'remplacer'; }
        else if (resPrix.isDenied)   { methodeCalcul = 'pmp'; }
        else                         { return; } // Annulé
    }

    // ──────────────────────────────────────────────────────────────
    // ETAPE 6 — Confirmation finale avec récapitulatif
    // ──────────────────────────────────────────────────────────────
    const totalFacture = parseFloat(
        $('#total_global_reception').text().replace(/\s/g, '').replace('F','')
    ) || 0;

    const confirmFinal = await Swal.fire({
        title  : 'Confirmer la reception',
        html   : `
            <div style="font-family:'DM Sans',sans-serif;font-size:13px;color:#475569;">
                <div style="display:flex;justify-content:space-between;padding:8px 0;border-bottom:1px solid #e2e8f0;">
                    <span>Commande</span><strong>#${id_commande}</strong>
                </div>
                <div style="display:flex;justify-content:space-between;padding:8px 0;border-bottom:1px solid #e2e8f0;">
                    <span>Fournisseur</span><strong>${bonOriginal.nom_fournisseur}</strong>
                </div>
                <div style="display:flex;justify-content:space-between;padding:8px 0;border-bottom:1px solid #e2e8f0;">
                    <span>Nb produits recus</span><strong>${lignesSaisies.length}</strong>
                </div>
                <div style="display:flex;justify-content:space-between;padding:8px 0;border-bottom:1px solid #e2e8f0;">
                    <span>Total facture</span><strong style="color:#16a34a;">${totalFacture.toLocaleString('fr-FR')} FCFA</strong>
                </div>
                <div style="display:flex;justify-content:space-between;padding:8px 0;border-bottom:1px solid #e2e8f0;">
                    <span>Methode PA</span>
                    <strong style="color:${methodeCalcul==='pmp'?'#16a34a':'#dc2626'};">${methodeCalcul === 'pmp' ? 'PMP' : 'Remplacement direct'}</strong>
                </div>
                ${ecarts.length > 0 ? `
                <div style="margin-top:10px;background:#fffbeb;border-radius:6px;padding:8px 12px;font-size:11px;color:#92400e;">
                    ${ecarts.length} ecart(s) seront consignes dans l'historique.
                </div>` : `
                <div style="margin-top:10px;background:#f0fdf4;border-radius:6px;padding:8px 12px;font-size:11px;color:#15803d;">
                    Aucun ecart — livraison conforme au bon de commande.
                </div>`}
            </div>`,
        icon            : 'info',
        showCancelButton: true,
        confirmButtonText: 'Valider la reception',
        cancelButtonText : 'Retour',
        confirmButtonColor: '#0f172a',
    });

    if (!confirmFinal.isConfirmed) return;

    // ──────────────────────────────────────────────────────────────
    // ETAPE 7 — Envoi AJAX au serveur
    // ──────────────────────────────────────────────────────────────

    // Spinner
    Swal.fire({
        title             : 'Traitement en cours...',
        html              : 'Mise a jour du stock et des achats.',
        allowOutsideClick : false,
        didOpen           : () => Swal.showLoading()
    });

    $.ajax({
        url      : 'ajax_produits.php',
        type     : 'POST',
        dataType : 'json',
        data     : {
            action       : 'valider_reception_finale',
            id_commande  : id_commande,
            mode_prix    : methodeCalcul,
            total_facture: totalFacture,
            lignes       : lignesSaisies
        },
        success  : function(res) {
            Swal.close();

            if (res.status !== 'success') {
                Swal.fire('Erreur', res.message, 'error');
                return;
            }

            // Afficher le rapport de réception
            afficherRapportReception(res);
        },
        error    : function() {
            Swal.fire('Erreur', 'Connexion impossible au serveur.', 'error');
        }
    });
}

// ═══════════════════════════════════════════════════════════════════
//  Affichage du rapport post-réception avec révision prix de vente
// ═══════════════════════════════════════════════════════════════════
function afficherRapportReception(res) {

    // Lignes produits avec PA modifié (pour révision prix de vente)
    const hasPrixVente = res.produits_pa_modifie && res.produits_pa_modifie.length > 0;

    // Résumé de l'opération
    let resumeHtml = `
        <div style="font-family:'DM Sans',sans-serif;">
            <div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;padding:14px;margin-bottom:14px;text-align:left;">
                <div style="font-weight:700;color:#15803d;margin-bottom:8px;font-size:14px;">Reception enregistree avec succes</div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:6px;font-size:12px;color:#475569;">
                    <div>Achat cree : <strong>#${res.id_achat}</strong></div>
                    <div>Lots crees : <strong>${res.nb_stocks}</strong></div>
                    <div>Mvts stock : <strong>${res.nb_mouvements}</strong></div>
                    <div>Statut : <strong style="color:#16a34a;">Livree</strong></div>
                </div>
            </div>`;

    if (hasPrixVente) {
        // Tableau des produits dont le PA a changé → proposition de mise à jour PV
        let lignesPV = res.produits_pa_modifie.map(p => {
            const pctSuggere = ((p.nouveau_pa / (p.ancien_pa || 1)) * (p.ancien_pv > 0 ? p.ancien_pv / (p.ancien_pa || 1) : 1.3)).toFixed(0);
            return `
            <tr style="border-bottom:1px solid #e2e8f0;">
                <td style="padding:7px 8px;font-size:12px;font-weight:600;">${p.nom}</td>
                <td style="padding:7px 8px;text-align:center;font-size:12px;">${p.ancien_pa.toLocaleString('fr-FR')} F</td>
                <td style="padding:7px 8px;text-align:center;font-size:12px;color:#dc2626;font-weight:700;">${p.nouveau_pa.toLocaleString('fr-FR')} F</td>
                <td style="padding:7px 8px;text-align:center;">
                    <input type="number"
                           class="swal2-input input-pv-revision"
                           data-id-produit="${p.id_produit}"
                           data-id-stock="${p.id_stock}"
                           value="${p.ancien_pv}"
                           min="1" step="1"
                           style="width:100px;margin:0;height:32px;font-size:12px;text-align:center;">
                </td>
            </tr>`;
        }).join('');

        resumeHtml += `
            <div style="margin-top:4px;">
                <div style="font-size:12px;font-weight:700;color:#92400e;margin-bottom:8px;text-align:left;">
                    Prix de vente a reviser (PA modifie) :
                </div>
                <div style="max-height:200px;overflow-y:auto;border:1px solid #e2e8f0;border-radius:8px;">
                    <table style="width:100%;border-collapse:collapse;">
                        <thead>
                            <tr style="background:#f8fafc;">
                                <th style="padding:7px 8px;font-size:10px;text-align:left;color:#64748b;text-transform:uppercase;border-bottom:1px solid #e2e8f0;">Produit</th>
                                <th style="padding:7px 8px;font-size:10px;text-align:center;color:#64748b;text-transform:uppercase;border-bottom:1px solid #e2e8f0;">Ancien PA</th>
                                <th style="padding:7px 8px;font-size:10px;text-align:center;color:#64748b;text-transform:uppercase;border-bottom:1px solid #e2e8f0;">Nouveau PA</th>
                                <th style="padding:7px 8px;font-size:10px;text-align:center;color:#64748b;text-transform:uppercase;border-bottom:1px solid #e2e8f0;">Nouveau PV</th>
                            </tr>
                        </thead>
                        <tbody>${lignesPV}</tbody>
                    </table>
                </div>
                <p style="font-size:11px;color:#94a3b8;margin-top:6px;text-align:left;">Modifiez les prix de vente si nécessaire, puis cliquez sur "Enregistrer les PV".</p>
            </div>`;
    }

    resumeHtml += '</div>';

    Swal.fire({
        title            : 'Reception terminee',
        html             : resumeHtml,
        icon             : 'success',
        width            : '580px',
        showCancelButton : hasPrixVente,
        confirmButtonText: hasPrixVente ? 'Enregistrer les PV' : 'Fermer',
        cancelButtonText : 'Ignorer les PV',
        confirmButtonColor: '#0f172a',
        cancelButtonColor : '#94a3b8',
    }).then(result => {
        if (hasPrixVente && result.isConfirmed) {
            // Collecter les nouveaux PV saisis
            let miseAJourPV = [];
            $('.input-pv-revision').each(function() {
                miseAJourPV.push({
                    id_produit : $(this).data('id-produit'),
                    id_stock   : $(this).data('id-stock'),
                    nouveau_pv : parseFloat($(this).val()) || 0
                });
            });

            if (miseAJourPV.length > 0) {
                $.post('ajax_produits.php', {
                    action  : 'update_prix_vente_batch',
                    produits: miseAJourPV
                }, function(r) {
                    if (r.status === 'success') {
                        Swal.fire({
                            icon : 'success',
                            title:'a jour',
                            timer: 2000,
                            showConfirmButton: false
                        }).then(() => location.reload());
                    } else {
                        Swal.fire('Erreur', r.message, 'error');
                    }
                }, 'json');
            } else {
                location.reload();
            }
        } else {
            location.reload();
        }
    });
}


// FONCTION POUR MODIFIER TOUS LES CHAMPS
function ouvrirModifProduit(produit) {
    Swal.fire({
        title: 'Modifier : ' + produit.nom_commercial,
        html: `
            <div style="text-align:left;">
                <label>Nom Commercial</label>
                <input id="edit-nom" class="swal2-input" value="${produit.nom_commercial}">
                
                <label>Prix de Vente (FCFA)</label>
                <input id="edit-prix" type="number" class="swal2-input" value="${produit.prix_unitaire}">
                
                <label>Emplacement (Rayon)</label>
                <input id="edit-emp" class="swal2-input" value="${produit.emplacement || ''}">
                
                <label>Seuil d'Alerte</label>
                <input id="edit-seuil" type="number" class="swal2-input" value="${produit.seuil_alerte || 0}">
            </div>
        `,
        focusConfirm: false,
        showCancelButton: true,
        confirmButtonText: 'Enregistrer',
        cancelButtonText: 'Annuler',
        preConfirm: () => {
            return {
                id: produit.id_produit,
                nom: document.getElementById('edit-nom').value,
                prix: document.getElementById('edit-prix').value,
                emp: document.getElementById('edit-emp').value,
                seuil: document.getElementById('edit-seuil').value
            }
        }
    }).then((result) => {
        if (result.isConfirmed) {
            $.post('ajax_produits.php', {
                action: 'modifier_produit_complet',
                data: result.value
            }, function(res) {
                if(res.status === 'success') location.reload();
            }, 'json');
        }
    });
}

// FONCTION POUR SUPPRIMER
function supprimerProduit(id, name, stockActuel) {
    // Sécurité : Bloquer si le stock est positif
    if (parseInt(stockActuel) > 0) {
        Swal.fire({
            title: 'Action impossible',
            text: `Le produit "${name}" possède encore ${stockActuel} unité(s) en stock. Vous devez vider le stock avant de le supprimer.`,
            icon: 'error',
            confirmButtonColor: '#3085d6'
        });
        return;
    }

    // Si le stock est à 0, on demande confirmation
    Swal.fire({
        title: 'Supprimer ' + name + ' ?',
        text: "Cette action est irréversible.",
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#e74c3c',
        confirmButtonText: 'Oui, supprimer !',
        cancelButtonText: 'Annuler'
    }).then((result) => {
        if (result.isConfirmed) {
            $.post('ajax_produits.php', { action: 'supprimer_produit', id: id }, function(res) {
                console.log(res)
                if(res.status === 'success') {
                    Swal.fire('Supprimé', 'Le produit a été retiré du catalogue.', 'success').then(() => {
                        location.reload();
                    });
                }
            }, 'json');
        }
    });
}

// FONCTION ARCHIVER (Simple et réversible)
function archiverProduit(id, name) {
    Swal.fire({
        title: 'Archiver ' + name + ' ?',
        text: "Le produit sera masqué du catalogue mais conservé dans les statistiques.",
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#f39c12',
        confirmButtonText: 'Oui, archiver'
    }).then((result) => {
        if (result.isConfirmed) {
            $.post('ajax_produits.php', { action: 'archiver_produit', id: id }, function(res) {
                console.log(res)
                if(res.status === 'success') ;
            }, 'json');
        }
    });
}

function changerStatutActif(id, nouvelEtat) {
    const actionTexte = (nouvelEtat === 1) ? "restaurer" : "archiver";
    
    Swal.fire({
        title: 'Voulez-vous ' + actionTexte + ' ce produit ?',
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: (nouvelEtat === 1) ? '#27ae60' : '#f39c12',
        confirmButtonText: 'Oui, ' + actionTexte
    }).then((result) => {
        if (result.isConfirmed) {
            $.post('ajax_produits.php', { 
                action: 'changer_statut_actif', 
                id: id, 
                actif: nouvelEtat 
            }, function(res) {
                if(res.status === 'success') location.reload();
            }, 'json');
        }
    });
}

function rafraichirCompteurArchives() {
    // On compte combien de lignes ont l'attribut data-actif à 0
    let nbArchives = $('.searchable-row[data-actif="0"]').length;
    
    // On met à jour le texte de l'option dans le menu déroulant
    $('#option-archive-texte').text("Produits Archivés (" + nbArchives + ")");
}

// Appeler la fonction au chargement de la page
$(document).ready(function() {
    rafraichirCompteurArchives();
});

function basculerVueArchive() {
    const vue = document.getElementById('filter-archive').value;
    const select = document.getElementById('filter-archive');
    const btnPrint = document.getElementById('btn-print-archive');
    
    if (vue === "0") {
        select.style.background = "#fff5f5"; 
        select.style.borderColor = "#e74c3c";
        btnPrint.style.display = "block"; // On montre le bouton d'impression
    } else {
        select.style.background = "#fffbe6";
        select.style.borderColor = "#ccc";
        btnPrint.style.display = "none"; // On cache le bouton
    }

    appliquerFiltres();
    rafraichirCompteurArchives();
}

function imprimerArchives() {
    const titre = "LISTE DES PRODUITS ARCHIVÉS - " + new Date().toLocaleDateString();
    
    // On crée une fenêtre temporaire
    let fenetreImpression = window.open('', '', 'height=700,width=900');
    
    fenetreImpression.document.write('<html><head><title>' + titre + '</title>');
    fenetreImpression.document.write('<style>table{width:100%; border-collapse:collapse;} th,td{border:1px solid #ddd; padding:8px; text-align:left;} th{background:#f2f2f2;}</style>');
    fenetreImpression.document.write('</head><body>');
    fenetreImpression.document.write('<h2 style="text-align:center;">' + titre + '</h2>');
    
    // On récupère uniquement les lignes visibles (les archivées)
    let tableauHTML = "<table><thead>" + $('.win-table thead').html() + "</thead><tbody>";
    
    $('.searchable-row:visible').each(function() {
        // On ne prend pas la dernière colonne (Actions)
        let ligne = $(this).clone();
        ligne.find('td:last-child').remove(); 
        tableauHTML += "<tr>" + ligne.html() + "</tr>";
    });
    
    tableauHTML += "</tbody></table>";
    
    fenetreImpression.document.write(tableauHTML);
    fenetreImpression.document.write('</body></html>');
    
    fenetreImpression.document.close();
    fenetreImpression.print();
}

function appliquerFiltres() {
    const nameVal = document.getElementById('filter-name').value.toLowerCase();
    const famVal = document.getElementById('filter-family').value;
    const locVal = document.getElementById('filter-location').value;
    const vueArchive = document.getElementById('filter-archive').value; // <--- IMPORTANT

    $('.searchable-row').each(function() {
        const rowNom = $(this).data('nom');
        const rowFam = $(this).data('famille');
        const rowLoc = $(this).data('emplacement');
        const rowActif = $(this).data('actif').toString(); // <--- IMPORTANT

        const matchNom = rowNom.includes(nameVal);
        const matchFam = (famVal === "" || rowFam === famVal);
        const matchLoc = (locVal === "" || rowLoc === locVal);
        const matchArchive = (rowActif === vueArchive); // <--- IMPORTANT

        if (matchNom && matchFam && matchLoc && matchArchive) {
            $(this).show();
        } else {
            $(this).hide();
        }
    });
}

function ouvrirDetailFlux(id_produit, nom) {
    Swal.fire({
        title: 'Historique des flux : ' + nom,
        width: '800px',
         html: `
            <div style="display:flex; justify-content:flex-end; margin-bottom:10px;">
                <button onclick="imprimerFluxProduit(${id_produit}, '${addslashes(nom)}')" class="btn-action" style="background:#e74c3c; color:white; padding:5px 15px; border-radius:4px; border:none; cursor:pointer;">
                    <i class="fas fa-file-pdf"></i> Exporter PDF
                </button>
            </div>
            <div id="loading-flux">Chargement...</div>
            <div id="container-flux" style="max-height:400px; overflow-y:auto; text-align:left;">
                <table style="width:100%; font-size:12px; border-collapse:collapse;" id="table-detail-flux">
                    <thead>
                        <tr style="background:#f1f5f9;">
                            <th style="padding:8px; border-bottom:1px solid #ddd;">Date</th>
                            <th style="padding:8px; border-bottom:1px solid #ddd;">Type</th>
                            <th style="padding:8px; border-bottom:1px solid #ddd;">Qté</th>
                            <th style="padding:8px; border-bottom:1px solid #ddd;">Motif / Auteur</th>
                        </tr>
                    </thead>
                    <tbody id="body-detail-flux"></tbody>
                </table>
            </div>
        `,
        showConfirmButton: false,
        showCloseButton: true
    });

    // Appel AJAX pour récupérer les flux du produit
    $.post('ajax_produits.php', { action: 'get_detail_flux', id: id_produit }, function(res) {
        $('#loading-flux').hide();
        let html = '';
        
        if(res.length === 0) {
            html = '<tr><td colspan="4" style="text-align:center; padding:20px;">Aucun flux trouvé.</td></tr>';
        } else {
            res.forEach(function(f) {
                let color = f.quantite > 0 ? 'green' : 'red';
                let signe = f.quantite > 0 ? '+' : '';
                html += `
                <tr style="border-bottom:1px solid #eee;">
                    <td style="padding:8px;">${f.date_m}</td>
                    <td style="padding:8px;"><small>${f.type_mouvement.replace('_', ' ')}</small></td>
                    <td style="padding:8px; font-weight:bold; color:${color}">${signe}${f.quantite}</td>
                    <td style="padding:8px; color:#666;">
                        ${f.motif}<br>
                        <small><b>Par: ${f.utilisateur}</b></small>
                    </td>
                </tr>`;
            });
        }
        $('#body-detail-flux').html(html);
    }, 'json');
}

// Filtrage par période
function filtrerFluxParPeriode() {
    let debut = $('#flux-date-debut').val();
    let fin = $('#flux-date-fin').val();
    
    if(!debut || !fin) {
        Swal.fire('Info', 'Veuillez sélectionner les deux dates', 'info');
        return;
    }
    
    // On peut soit recharger la page avec des paramètres GET, soit filtrer en AJAX.
    // Pour rester simple, on recharge avec les paramètres :
    window.location.href = `produits_gestion.php?panel=mouvements&debut=${debut}&fin=${fin}`;
}

function imprimerFluxProduit(id, nom) {
    window.open(`generer_pdf_flux.php?id_produit=${id}&nom=${encodeURIComponent(nom)}`, '_blank');
}

function calculerEcart(idLigne) {
    let theo = parseInt($(`#theo-${idLigne}`).text());
    let reel = parseInt($(`#reel-${idLigne}`).val());
    let ecart = reel - theo;
    
    let cellEcart = $(`#ecart-${idLigne}`);
    cellEcart.text(ecart);
    
    // Style visuel Winpharma
    if(ecart < 0) cellEcart.addClass('text-danger fw-bold');
    else if(ecart > 0) cellEcart.addClass('text-success fw-bold');
    else cellEcart.removeClass('text-danger text-success');
}

let panierInventaire = []; // Stockage temporaire avant validation


function chargerProduitsInventaire() {

    $.post('ajax_produits.php', { action: 'liste_produits_mouvement' }, function(data) {
        let html = '';
        //console.log(data)
        data.forEach(p => {
            html += `<tr>
                <td><b>${p.nom_commercial}</b></td>
                <td>${p.stock_total}</td>
                <td><button class="btn btn-sm btn-outline-primary" onclick="ouvrirModalSaisieLot(${p.id_produit}, '${p.nom_commercial.replace(/'/g, "\\'")}')">Compter les lots</button></td>
            </tr>`;
        });
        $('#body-selection-inventaire').html(html);
    }, 'json');
}

function ouvrirModalSaisieLot(id, nom) {
    $('#modal-titre-produit').text(nom);
    $.post('ajax_produits.php', { action: 'get_lots_produit', id_produit: id }, function(lots) {
        let html = '';
        lots.forEach(l => {
            html += `<tr>
                <td>Lot: ${l.numero_lot}</td>
                <td id="theo-${l.id_stock}">${l.quantite_disponible}</td>
                <td><input type="number" class="form-control" id="reel-${l.id_stock}" value="${l.quantite_disponible}" onkeyup="majEcart(${l.id_stock})"></td>
                <td id="ecart-${l.id_stock}" class="fw-bold">0</td>
            </tr>`;
        });
        $('#body-lots-inventaire').html(html);
        $('#modalSaisieInventaire').modal('show');
    }, 'json');
}

function majEcart(id) {
    let theo = parseInt($(`#theo-${id}`).text());
    let reel = parseInt($(`#reel-${id}`).val()) || 0;
    let ecart = reel - theo;
    $(`#ecart-${id}`).text(ecart).css('color', ecart < 0 ? 'red' : 'green');
}

function enregistrerSaisieTemporaire() {
    // On récupère chaque ligne de la modal pour l'ajouter au panier de validation
    $('#body-lots-inventaire tr').each(function() {
        let idStock = $(this).find('input').attr('id').split('-')[1];
        let qteReel = $(this).find('input').val();
        let nomProd = $('#modal-titre-produit').text();
        
        // Mise à jour du panier de validation
        panierInventaire.push({ id_stock: idStock, nom: nomProd, reel: qteReel });
    });
    $('#modalSaisieInventaire').modal('hide');
    afficherRecapitulatif();
}

function afficherRecapitulatif() {
    let html = '';
    panierInventaire.forEach((item, index) => {
        html += `<tr>
            <td>${item.nom} (Lot ID: ${item.id_stock})</td>
            <td>...</td> 
            <td><input type="number" class="form-control sm" value="${item.reel}" onchange="panierInventaire[${index}].reel=this.value"></td>
            <td>---</td>
            <td><button class="btn btn-danger btn-sm" onclick="panierInventaire.splice(${index},1); afficherRecapitulatif();">&times;</button></td>
        </tr>`;
    });
    $('#body-validation-inventaire').html(html);
}

function finaliserInventaire() {
    // Vérifier si le panier est vide
    if (panierInventaire.length === 0) {
        return Swal.fire('Action impossible', 'Votre liste d\'inventaire est vide. Veuillez d\'abord compter des produits.', 'warning');
    }

    // Demander une confirmation finale
    Swal.fire({
        title: 'Valider l\'inventaire ?',
        text: "Cette action va mettre à jour vos stocks réels et générer des mouvements d'ajustement. C'est irréversible.",
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#28a745',
        cancelButtonColor: '#d33',
        confirmButtonText: 'Oui, valider et ajuster',
        cancelButtonText: 'Annuler'
    }).then((result) => {
        if (result.isConfirmed) {
            // Afficher un indicateur de chargement
            Swal.fire({
                title: 'Traitement en cours...',
                allowOutsideClick: false,
                didOpen: () => { Swal.showLoading(); }
            });

            // Envoi des données au PHP
            $.post('ajax_produits.php', { 
                action: 'finaliser_inventaire', 
                lignes: panierInventaire 
            }, function(res) {
                console.log(res)
                if (res.status === 'success') {
                    Swal.fire('Succès !', 'L\'inventaire a été validé. Les stocks ont été mis à jour.', 'success').then(() => {
                        // Réinitialiser tout
                        panierInventaire = [];
                        $('#body-validation-inventaire').empty();
                        showPanel('inv_historique'); // Rediriger vers l'historique pour voir le rapport
                        rafraichirBadgeCommandes(); // Optionnel : mettre à jour les alertes stock
                    });
                } else {
                    Swal.fire('Erreur', res.message || 'Une erreur est survenue lors de la validation.', 'error');
                }
            }, 'json').fail(function() {
                Swal.fire('Erreur Serveur', 'Impossible de joindre le serveur.', 'error');
            });
        }
    });
}

function calculerProgression() {
    let total = $('.ligne-inv').length;
    let valides = $('.row-validated').length;
    let pourcentage = total > 0 ? Math.round((valides / total) * 100) : 0;

    // Mise à jour des textes
    $('#count-valides').text(valides);
    $('#count-total').text(total);

    // Mise à jour de la barre de progression
    $('#progress-bar-inv').css('width', pourcentage + '%').text(pourcentage + '%');
    
    // Si c'est fini, on change la couleur de la barre
    if(pourcentage === 100) {
        $('#progress-bar-inv').removeClass('bg-success').addClass('bg-primary');
    } else {
        $('#progress-bar-inv').removeClass('bg-primary').addClass('bg-success');
    }
}

function chargerHistoriqueInventaires() {
    $.post('ajax_produits.php', { action: 'liste_historique' }, function(data) {
        let html = '';
        if(data.length === 0) html = '<tr><td colspan="4" class="text-center">Aucun inventaire archivé.</td></tr>';
        
        data.forEach(inv => {
            html += `
                <tr>
                    <td><b>${new Date(inv.date_debut).toLocaleString()}</b></td>
                    <td>${inv.type_inventaire.toUpperCase()}</td>
                    <td><span class="badge bg-success">${inv.statut}</span></td>
                    <td>
                        <button class="btn btn-sm btn-info" onclick="voirRapportDetaille(${inv.id_inventaire}, '${inv.date_debut}')">
                            <i class="fas fa-eye"></i> Voir Rapport
                        </button>
                    </td>
                </tr>`;
        });
        $('#body-historique-inventaire').html(html);
    }, 'json');
}

function voirRapportDetaille(idInv, dateInv) {
    $.post('ajax_produits.php', { action: 'details_inventaire', id_inventaire: idInv }, function(lignes) {
        let html = `
            <table class="table table-striped table-sm">
                <thead>
                    <tr class="table-secondary">
                        <th>Produit (Lot)</th>
                        <th>Théorique</th>
                        <th>Réel</th>
                        <th>Écart</th>
                    </tr>
                </thead>
                <tbody>`;
        
        lignes.forEach(l => {
            let couleurEcart = l.ecart < 0 ? 'text-danger' : (l.ecart > 0 ? 'text-success' : '');
            html += `
                <tr>
                    <td>${l.nom_commercial} <br><small class="text-muted">Lot: ${l.numero_lot || 'N/A'}</small></td>
                    <td>${l.stock_theorique}</td>
                    <td><b>${l.stock_reel}</b></td>
                    <td class="fw-bold ${couleurEcart}">${l.ecart}</td>
                </tr>`;
        });

        html += `</tbody></table>`;

        Swal.fire({
            title: `Rapport d'inventaire du ${new Date(dateInv).toLocaleDateString()}`,
            html: html,
            width: '800px',
            confirmButtonText: 'Fermer'
        });
    }, 'json');
}

function chargerInventaireDirect() {
    $.post('ajax_produits.php', { action: 'get_tous_les_lots_actifs' }, function(data) {
        console.log(data)
        let html = '';
        data.forEach(l => {
            html += `
            <tr class="ligne-inv" data-id-stock="${l.id_stock}" data-id-prod="${l.id_produit}">
                <td>${l.nom_commercial}</td>
                <td>${l.numero_lot}</td>
                <td class="theo">${l.quantite_disponible}</td>
                <td><input type="number" class="form-control input-reel" value="${l.quantite_disponible}" onkeyup="calculerEcartLigne(this)"></td>
                <td class="ecart">0</td>
                <td>
                    <button class="btn btn-sm btn-primary" onclick="validerLigneInventaire(this, ${l.id_stock})" title="Valider">
                        <i class="fas fa-check"></i>
                    </button>
                    <button class="btn btn-sm btn-danger" onclick="supprimerLigneTableau(this)" title="Ignorer">
                        <i class="fas fa-trash"></i>
                    </button>
                </td>
            </tr>`;
        });
        $('#body-inventaire-direct').html(html);
    }, 'json');

    calculerProgression();
}

function calculerEcartLigne(input) {
    let row = $(input).closest('tr');
    let theo = parseInt(row.find('.theo').text());
    let reel = parseInt($(input).val()) || 0;
    let ecart = reel - theo;
    
    row.find('.ecart').text(ecart).css('color', ecart < 0 ? 'red' : (ecart > 0 ? 'green' : 'black'));
}

function validerToutLInventaire() {
    let lignes = [];
    $('.ligne-inv').each(function() {
        lignes.push({
            id_stock: $(this).data('id-stock'),
            reel: $(this).find('.input-reel').val()
        });
    });

    // On utilise ton script PHP "finaliser_inventaire" qui fonctionne déjà
    $.post('ajax_produits.php', { action: 'finaliser_inventaire', lignes: lignes }, function(res) {
        if(res.status === 'success') {
            Swal.fire('Terminé', 'Le stock a été mis à jour.', 'success');
        }
    }, 'json');


}

// 1. Valider une ligne spécifique
function validerLigneInventaire(btn, idStock) {
    let row = $(btn).closest('tr');
    let reel = row.find('.input-reel').val();
    let idProd = row.data('id-prod');

    $.post('ajax_produits.php', { 
        action: 'ajouter_session_inventaire', 
        id_stock: idStock, 
        id_produit: idProd,
        reel: reel 
    }, function(res) {
        if(res.status === 'success') {
            // Marquer la ligne en rouge/grisé pour dire "Déjà traité"
            row.addClass('row-validated'); 
            row.find('input').prop('disabled', true);
            $(btn).prop('disabled', true).addClass('btn-success');
            // Optionnel : masquer la ligne après 1 seconde si tu veux qu'elle disparaisse
            // setTimeout(() => { row.fadeOut(); }, 1000);
        }
    }, 'json');

    calculerProgression();
}

// 2. Effacer une ligne du tableau (ne sera pas compté)
function supprimerLigneTableau(btn) {
    $(btn).closest('tr').remove();
}

function filtrerTableauInventaire() {
    let search = $('#recherche-inventaire').val().toLowerCase();
    $('#body-inventaire-direct tr').each(function() {
        let text = $(this).text().toLowerCase();
        $(this).toggle(text.indexOf(search) > -1);
    });
}

function reinitialiserSessionInventaire() {
    Swal.fire({
        title: 'Vider le travail en cours ?',
        text: "Toutes les lignes que vous avez validées en rouge seront perdues. Vous devrez recommencer le comptage.",
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        cancelButtonColor: '#3085d6',
        confirmButtonText: 'Oui, tout effacer',
        cancelButtonText: 'Annuler'
    }).then((result) => {
        if (result.isConfirmed) {
            $.post('ajax_produits.php', { action: 'vider_session_inventaire' }, function(res) {
                if(res.status === 'success') {
                    Swal.fire('Réinitialisé', 'Le brouillon d\'inventaire est vide.', 'success');
                    // On recharge le tableau pour que tout redevienne blanc
                    chargerInventaireDirect(); 
                    // On remet les compteurs à zéro
                    calculerProgression();
                }
            }, 'json');
        }
    });
}

// On écoute les changements sur le prix boîte et le coefficient
$(document).on('input', 'input[name="prix_unitaire"], #coefficient_division', function() {
    console.log("est_detail")
    calculerPrixDetailSuggere();
});

function calculerPrixDetailSuggere() {
    const prixBoite = parseFloat($('input[name="prix_unitaire"]').val()) || 0;
    const coef = parseInt($('#coefficient_division').val()) || 1;
    const estDetail = $('#est_detail').val();

    if (estDetail === "1" && coef > 0) {
        const prixTheorique = Math.ceil(prixBoite / coef); // On arrondit à l'unité supérieure
        
        // On met à jour l'affichage d'aide
        if ($('#aide-prix-detail').length === 0) {
            $('#prix_unitaire_detail').after('<small id="aide-prix-detail" style="display:block; color:#718096; margin-top:5px;"></small>');
        }
        
        $('#aide-prix-detail').html(
            `<i class="fas fa-info-circle"></i> Prix de revient : <b>${prixTheorique} F</b> / unité.<br>` +
            `<span style="color:#2b6cb0;">Saisis un prix égal ou supérieur (ex: ${prixTheorique + 50} F)</span>`
        );
    } else {
        $('#aide-prix-detail').remove();
    }
}

// Mettre à jour aussi quand on change le mode "Vente au détail"
$('#est_detail').on('change', function() {
    setTimeout(calculerPrixDetailSuggere, 100);
});

function filtrerFluxParPeriode() {
    let debut = $('#flux-date-debut').val();
    let fin = $('#flux-date-fin').val();
    
    if(!debut || !fin) {
        Swal.fire('Info', 'Veuillez sélectionner les deux dates', 'info');
        return;
    }

    // Afficher un indicateur de chargement sur le bouton
    const btn = $('.btn-action i.fa-search').parent();
    btn.html('<i class="fas fa-spinner fa-spin"></i> Filtrage...');

    $.ajax({
        url: 'ajax_flux.php', // On crée ce petit fichier dédié
        type: 'POST',
        data: { 
            action: 'filtrer_mouvements',
            debut: debut,
            fin: fin 
        },
        success: function(response) {
            // On remplace le contenu du tbody par la réponse du serveur
            $('#panel-mouvements table tbody').html(response);
            btn.html('<i class="fas fa-search"></i> Filtrer');
        },
        error: function() {
            Swal.fire('Erreur', 'Impossible de charger les flux', 'error');
            btn.html('<i class="fas fa-search"></i> Filtrer');
        }
    });
}

function exporterFlux(format) {
    let debut = $('#flux-date-debut').val();
    let fin = $('#flux-date-fin').val();
    
    if(!debut || !fin) {
        Swal.fire('Attention', 'Sélectionnez une période', 'warning');
        return;
    }

    let url = `export_flux.php?format=${format}&debut=${debut}&fin=${fin}`;
    
    if(format === 'pdf') {
        // On ouvre dans une petite fenêtre popup pour l'impression
        window.open(url, 'Impression', 'width=1000,height=800');
    } else {
        window.location.href = url;
    }
}



function chargerAlertesStock() {
    $('#tbody-alertes').html('<tr><td colspan="5" style="text-align:center; padding:30px;"><i class="fas fa-spinner fa-spin"></i> Analyse des stocks en cours...</td></tr>');

    $.post('ajax_alertes.php', { action: 'get_all_alerts' }, function(response) {
        console.log(response)
        if(response.status === 'success') {
            $('#tbody-alertes').html(response.html_table);
            $('#container-perimes-liste').html(`<b>${response.count_perimes}</b> lots à surveiller.`);
            $('#container-ruptures-liste').html(`<b>${response.count_ruptures}</b> produits bientôt épuisés.`);
        }
    }, 'json');
}


    // Fonction pour charger les données
    function loadMouvements() {
        let formData = $('#filter-form').serialize();

        $('#body-mouvements').html(
            '<tr><td colspan="7" class="text-center py-4">' +
            '<i class="fas fa-spinner fa-spin me-2"></i> Chargement...</td></tr>'
        );
        $('#badge-count').text('');

        $.ajax({
            url: 'fetch_mouvements_grouped.php',
            type: 'POST',
            data: formData,
            success: function (response) {
                $('#body-mouvements').html(response);

                // Compte le nombre de lignes produit chargées
                let nb = $('#body-mouvements tr[data-id-produit]').length;
                if (nb > 0) {
                    $('#badge-count').text(nb + ' produit' + (nb > 1 ? 's' : ''));
                }
            },
            error: function () {
                $('#body-mouvements').html(
                    '<tr><td colspan="7" class="text-center text-danger py-3">' +
                    '<i class="fas fa-exclamation-triangle me-2"></i>Erreur de connexion.</td></tr>'
                );
            }
        });
    }

    // Charger au démarrage
    

    // Intercepter la soumission du formulaire
      $('#filter-form').on('submit', function (e) {
        e.preventDefault();
        loadMouvements();
    });


    
    // --- EXPORT EXCEL ---
    $('#export-excel').on('click', function(e) {
        e.preventDefault();
        let table = document.querySelector("#panel-mouvements table");
        let wb = XLSX.utils.table_to_book(table, { sheet: "Mouvements" });
        XLSX.writeFile(wb, "Flux_Stocks_" + new Date().toLocaleDateString() + ".xlsx");
    });


        $(document).on('click', 'tr[data-id-produit]', function () {
        let idProduit    = $(this).data('id-produit');
        let nomProduit   = $(this).data('nom-produit');
        let nbMvt        = $(this).data('nb-mvt');
        let totalEntrees = $(this).data('total-entrees');
        let totalSorties = $(this).data('total-sorties');
        let stockActuel  = $(this).data('stock-actuel');

        // Pré-remplir le résumé
        $('#modal-product-name').text(nomProduit);
        $('#modal-total-entrees').text('+' + totalEntrees);
        $('#modal-total-sorties').text('-' + totalSorties);
        $('#modal-nb-mvt').text(nbMvt);
        $('#modal-stock-actuel').text(stockActuel);

        // Couleur stock actuel (positif/nul/négatif)
        let sA = parseFloat(String(stockActuel).replace(/\s/g, ''));
        $('#modal-stock-actuel')
            .removeClass('text-success text-danger text-warning')
            .addClass(sA > 0 ? 'text-success' : (sA < 0 ? 'text-danger' : 'text-warning'));

        // Récupérer les filtres de période actifs
        let fDebut = $('[name="f_debut"]').val();
        let fFin   = $('[name="f_fin"]').val();
        let fType  = $('[name="f_type"]').val();
        let infoFiltre = '';
        if (fDebut && fFin) infoFiltre += 'Periode : ' + fDebut + ' au ' + fFin + '  ';
        if (fType)          infoFiltre += 'Type : ' + fType.replace(/_/g, ' ').toUpperCase();
        $('#modal-filter-info').text(infoFiltre || 'Tous les mouvements');

        // Vider et afficher le spinner
        $('#modal-body-detail').html(
            '<tr><td colspan="7" class="text-center py-4">' +
            '<i class="fas fa-spinner fa-spin me-2"></i> Chargement des mouvements...</td></tr>'
        );

        // Ouvrir le modal
        let modal = new bootstrap.Modal(document.getElementById('modalDetailMouvements'));
        modal.show();

        // Charger les mouvements détaillés du produit
        $.ajax({
            url: 'fetch_mouvements_detail.php',
            type: 'POST',
            data: {
                id_produit: idProduit,
                f_debut:    fDebut,
                f_fin:      fFin,
                f_type:     fType
            },
            success: function (response) {
                $('#modal-body-detail').html(response);
            },
            error: function () {
                $('#modal-body-detail').html(
                    '<tr><td colspan="7" class="text-center text-danger py-3">' +
                    '<i class="fas fa-exclamation-triangle me-2"></i>Erreur de chargement.</td></tr>'
                );
            }
        });
    });


          $('#modal-print-btn').on('click', function () {
        let produit = $('#modal-product-name').text();
        let contenu = document.getElementById('modalDetailMouvements').innerHTML;
        let win = window.open('', '_blank');
        win.document.write(
            '<html><head><title>Mouvements - ' + produit + '</title>' +
            '<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">' +
            '</head><body class="p-4">' + contenu + '</body></html>'
        );
        win.document.close();
        win.focus();
        setTimeout(function () { win.print(); win.close(); }, 800);
    });

    // --- EXPORT PDF ---
    $('#export-pdf').on('click', function(e) {
        e.preventDefault();
        const { jsPDF } = window.jspdf;
        let doc = new jsPDF('p', 'pt', 'a4');
        
        doc.text("Rapport des Flux de Stocks - PharmAssist", 40, 30);
        
        doc.autoTable({
            html: '#panel-mouvements table',
            startY: 50,
            theme: 'grid',
            styles: { fontSize: 8 },
            headStyles: { fillColor: [41, 128, 185] }
        });
        
        doc.save("Flux_Stocks.pdf");
    });

    // --- IMPRESSION DIRECTE ---
    $('#print-table').on('click', function(e) {
        e.preventDefault()        
        let divToPrint = document.getElementById("panel-mouvements");
        let newWin = window.open("");
        
        // On clone le CSS pour l'impression
        let style = "<style>table { width: 100%; border-collapse: collapse; font-family: sans-serif; }";
        style += "th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }";
        style += "th { background-color: #f2f2f2; } .btn, #filter-form, .dropdown { display: none; }</style>";

        newWin.document.write(style);
        newWin.document.write(divToPrint.outerHTML);
        newWin.print();
        newWin.close();
    });


    function recalculerLigne(input) {
    let qte = parseInt($(input).val()) || 0;
    let prix = parseFloat($(input).data('prix')) || 0;
    let total = qte * prix;
    
    // On trouve la cellule de total dans la même ligne (tr) et on met à jour
    $(input).closest('tr').find('.ligne-total').text(total.toLocaleString());
    
    // Optionnel : Mettre à jour le bouton d'envoi avec le total global
    recalculerTotalGlobal();
}

function recalculerTotalGlobal() {
    let global = 0;
    $('.commande-check:checked').each(function() {
        let tr = $(this).closest('tr');
        let qte = parseInt(tr.find('.qte-suggeree').val()) || 0;
        let prix = parseFloat(tr.find('.qte-suggeree').data('prix')) || 0;
        global += (qte * prix);
    });
    
    // Si tu as un élément pour afficher le total global, tu le mets à jour ici
    $('#btn-envoi-texte').html(`ENVOYER LA COMMANDE (${global.toLocaleString()} F)`);
}

// Ajouter aussi l'écouteur sur les cases à cocher pour mettre à jour le total global
$(document).on('change', '.commande-check', function() {
    recalculerTotalGlobal();
});

function toggleSidebar() {
    document.querySelector('.sidebar').classList.toggle('collapsed');
    document.querySelector('.content').classList.toggle('collapsed');
}


loadMouvements();
chargerAlertesStock()
chargerInventaireDirect()
chargerProduitsInventaire()
afficherRecapitulatif()
chargerHistoriqueInventaires()
</script>

<script>
$(function () {

    // ── 1. Charger la liste des fournisseurs dans le select ──
    $.post('ajax_produits.php', { action: 'liste_fournisseurs' }, function (data) {
        if (!Array.isArray(data)) return;
        data.forEach(f => {
            $('#f_fournisseur_achats').append(
                `<option value="${f.id_fournisseur}">${f.nom_fournisseur}</option>`
            );
        });
    }, 'json');


    // ── 2. Chargement de la vue groupée ──
    function loadAchatsGrouped() {
        const formData = $('#filter-achats-form').serialize();

        $('#body-achats-grouped').html(
            `<tr><td colspan="8" class="text-center py-3 text-muted">
                <i class="fas fa-spinner fa-spin me-2"></i> Chargement...
            </td></tr>`
        );

        $.ajax({
            url: 'fetch_achats_grouped.php',
            type: 'POST',
            data: formData,
            dataType: 'json',
            success: function (response) {
                $('#body-achats-grouped').html(response.html);
                $('#achats-count-badge').text(response.total + ' produits');

                // Attacher les clics sur les lignes
                bindRowClicks();
            },
            error: function () {
                $('#body-achats-grouped').html(
                    `<tr><td colspan="8" class="text-center text-danger">
                        <i class="fas fa-exclamation-triangle me-1"></i> Erreur de connexion.
                    </td></tr>`
                );
            }
        });
    }


    // ── 3. Clic sur une ligne → ouvrir le modal ──
    function bindRowClicks() {
        $('#body-achats-grouped tr.row-produit-achat').on('click', function () {
            const idProduit   = $(this).data('id-produit');
            const nomProduit  = $(this).data('nom-produit');
            const molecule    = $(this).data('molecule') || '';

            // Remplir le header modal
            $('#modal-produit-nom').text(nomProduit);
            $('#modal-produit-molecule').text(molecule ? '(' + molecule + ')' : '');
            $('#modal-id-produit').val(idProduit);

            // Propager les dates du filtre global
            $('#modal-f-debut').val($('#f_debut_achats').val());
            $('#modal-f-fin').val($('#f_fin_achats').val());

            // Réinitialiser les stats
            $('#stat-nb-factures, #stat-qte-totale, #stat-montant-total, #stat-prix-moyen')
                .text('--');

            // Ouvrir le modal
            const modal = new bootstrap.Modal(document.getElementById('modalDetailAchats'));
            modal.show();

            // Charger le détail
            loadDetailAchats();
        });
    }


    // ── 4. Chargement du détail dans le modal ──
    function loadDetailAchats() {
        const formData = $('#modal-filter-form').serialize();

        $('#body-detail-achats').html(
            `<tr><td colspan="10" class="text-center py-3 text-muted">
                <i class="fas fa-spinner fa-spin me-2"></i> Chargement...
            </td></tr>`
        );

        $.ajax({
            url: 'fetch_achats_detail.php',
            type: 'POST',
            dataType: 'json',
            data: formData,
            success: function (response) {
                $('#body-detail-achats').html(response.html);

                // Mettre à jour les stats
                if (response.stats) {
                    const s = response.stats;
                    $('#stat-nb-factures').text(s.nb_factures);
                    $('#stat-qte-totale').text(
                        parseFloat(s.qte_totale || 0).toLocaleString('fr-FR')
                    );
                    $('#stat-montant-total').text(
                        parseFloat(s.montant_total || 0).toLocaleString('fr-FR') + ' FCFA'
                    );
                    $('#stat-prix-moyen').text(
                        parseFloat(s.prix_moyen || 0).toLocaleString('fr-FR') + ' FCFA'
                    );
                }
            },
            error: function () {
                $('#body-detail-achats').html(
                    `<tr><td colspan="10" class="text-center text-danger">
                        Erreur de connexion.
                    </td></tr>`
                );
            }
        });
    }


    // ── 5. Recherche live avec debounce ──
    let debounceTimer;
    $('#search-produit-achats').on('input', function () {
        clearTimeout(debounceTimer);
        debounceTimer = setTimeout(function () {
            loadAchatsGrouped();
        }, 400);
    });

    $('#clear-search-achats').on('click', function () {
        $('#search-produit-achats').val('');
        loadAchatsGrouped();
    });


    // ── 6. Soumission des formulaires ──
    $('#filter-achats-form').on('submit', function (e) {
        e.preventDefault();
        loadAchatsGrouped();
    });

    $('#modal-filter-form').on('submit', function (e) {
        e.preventDefault();
        loadDetailAchats();
    });

    $('#reset-filter-achats').on('click', function () {
        $('#filter-achats-form')[0].reset();
        loadAchatsGrouped();
    });


    // ── 7. Chargement initial ──
    loadAchatsGrouped();

function openEditAchatModal(idProduit, nomProduit) {
    $('#modal-produit-nom').text(nomProduit);
    $('#edit-achat-alert').addClass('d-none').text('');
    $('#body-edit-achat-lignes').html(
        '<tr><td colspan="8" class="text-center py-4 text-muted">' +
        '<i class="fas fa-spinner fa-spin me-1"></i> Chargement...</td></tr>'
    );

    $('#modalEditAchat').modal('show');

    $.ajax({
        url: 'get_detail_achat_lignes.php',
        type: 'POST',
        data: { id_produit: idProduit },
        dataType: 'json',
        success: function(res) {
            if (res.success) {
                $('#body-edit-achat-lignes').html(res.html);
                bindSaveLigneButtons();
            } else {
                $('#body-edit-achat-lignes').html(
                    '<tr><td colspan="8" class="text-center text-danger py-3">' + res.message + '</td></tr>'
                );
            }
        },
        error: function() {
            $('#body-edit-achat-lignes').html(
                '<tr><td colspan="8" class="text-center text-danger py-3">' +
                '<i class="fas fa-exclamation-triangle me-1"></i> Erreur de connexion.</td></tr>'
            );
        }
    });
}

/**
 * Attache les boutons "Enregistrer" dans le tableau d'edition
 */
function bindSaveLigneButtons() {
    $('.btn-save-ligne-achat').off('click').on('click', function() {
        const $btn    = $(this);
        const idLigne = $btn.data('id-detail');
        const idAchat = $btn.data('id-achat');
        const idStock = $btn.data('id-stock');

        const newQte   = parseFloat($('#qte_'   + idLigne).val());
        const newPrix  = parseFloat($('#prix_'  + idLigne).val());
        const oldQte   = parseFloat($('#qte_'   + idLigne).data('original'));
        const oldPrix  = parseFloat($('#prix_'  + idLigne).data('original'));

        // Aucune modification
        if (newQte === oldQte && newPrix === oldPrix) {
            showEditAlert('info', 'Aucune modification detectee pour cette ligne.');
            return;
        }

        // Validation basique
        if (isNaN(newQte) || newQte <= 0) {
            showEditAlert('danger', 'La quantite doit etre un nombre positif.');
            return;
        }
        if (isNaN(newPrix) || newPrix <= 0) {
            showEditAlert('danger', 'Le prix doit etre un nombre positif.');
            return;
        }

        // Confirmation
        if (!confirm('Confirmer la modification ?\n\nQte : ' + oldQte + ' → ' + newQte +
                     '\nPrix : ' + oldPrix + ' → ' + newPrix + ' FCFA')) {
            return;
        }

        $btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i>');

        $.ajax({
            url: 'update_detail_achat.php',
            type: 'POST',
            dataType: 'json',
            data: {
                id_detail_achat : idLigne,
                id_achat        : idAchat,
                id_stock        : idStock,
                new_quantite    : newQte,
                new_prix        : newPrix,
                old_quantite    : oldQte,
                old_prix        : oldPrix
            },
            success: function(res) {
                if (res.success) {
                    showEditAlert('success', res.message);
                    // Mettre a jour les valeurs "original" pour eviter double-sauvegarde
                    $('#qte_'  + idLigne).data('original', newQte);
                    $('#prix_' + idLigne).data('original', newPrix);
                    // Rafraichir le tableau principal
                    loadAchatsGrouped();
                } else {
                    showEditAlert('danger', res.message);
                }
                $btn.prop('disabled', false).html('<i class="fas fa-save"></i>');
            },
            error: function() {
                showEditAlert('danger', 'Erreur de connexion au serveur.');
                $btn.prop('disabled', false).html('<i class="fas fa-save"></i>');
            }
        });
    });
}

/**
 * Affiche une alerte dans le modal
 */
function showEditAlert(type, msg) {
    const icons = {
        success : 'fas fa-check-circle',
        danger  : 'fas fa-exclamation-circle',
        info    : 'fas fa-info-circle',
        warning : 'fas fa-exclamation-triangle'
    };
    $('#edit-achat-alert')
        .removeClass('d-none alert-success alert-danger alert-info alert-warning')
        .addClass('alert-' + type)
        .html('<i class="' + icons[type] + ' me-2"></i>' + msg);
}

// ── Mise a jour de bindRowClicks pour ouvrir le modal ──
function bindRowClicks() {
    // Bouton oeil => ouvre le modal d'edition
    $(document).off('click', '.btn-edit-achat-ligne').on('click', '.btn-edit-achat-ligne', function(e) {
        e.stopPropagation();
        const $tr = $(this).closest('tr');
        openEditAchatModal(
            $tr.data('id-produit'),
            $tr.data('nom-produit')
        );
    });
}
});
</script>
</body>
</html>

