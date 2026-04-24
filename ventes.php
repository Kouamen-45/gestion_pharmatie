<?php
session_start();
require_once 'db.php';
if (!isset($_SESSION['user_id'])) { header('Location: login.php'); exit(); }

$checkCaisse = $pdo->query("SELECT id_session FROM sessions_caisse WHERE statut = 'ouvert' LIMIT 1")->fetch();
if (!$checkCaisse) { header('Location: caisse.php?error=no_session'); exit(); }
$id_session = $checkCaisse['id_session'];

$stats = $pdo->query("SELECT COUNT(id_vente) as nb, SUM(total) as ca FROM ventes WHERE DATE(date_vente) = CURDATE()")->fetch();

$sql = "SELECT p.*, 
        IFNULL((SELECT SUM(s.quantite_disponible) FROM stocks s WHERE s.id_produit = p.id_produit AND s.date_peremption > CURDATE()), 0) as stock_dispo
        FROM produits p";
$produits = $pdo->query($sql)->fetchAll();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<title>PharmAssist - Ventes</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
<style>
/* =============================================
   DESIGN SYSTEM — PharmAssist v2
   Cohérent avec caisse.php
============================================= */
:root {
  --bg:        #f0f2f5;
  --surface:   #ffffff;
  --sidebar:   #111827;
  --sidebar-w: 200px;
  --cart-w:    320px;

  --primary:   #1d4ed8;
  --success:   #16a34a;
  --warning:   #d97706;
  --danger:    #dc2626;
  --info:      #0891b2;
  --purple:    #7c3aed;

  --text-1:    #111827;
  --text-2:    #6b7280;
  --text-3:    #9ca3af;
  --border:    #e5e7eb;

  --radius:    6px;
  --shadow:    0 1px 3px rgba(0,0,0,.07), 0 1px 2px rgba(0,0,0,.04);
}

*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

html, body { height: 100%; }

body {
  font-family: 'DM Sans', sans-serif;
  font-size: 12px;
  line-height: 1.4;
  background: var(--bg);
  color: var(--text-1);
  display: flex;
  overflow: hidden;
}

/* ---- SIDEBAR ---- */
.sidebar {
  width: var(--sidebar-w);
  background: var(--sidebar);
  height: 100vh;
  position: fixed;
  top: 0; left: 0;
  display: flex;
  flex-direction: column;
  z-index: 100;
  flex-shrink: 0;
}

.sidebar-logo {
  padding: 16px 14px 12px;
  border-bottom: 1px solid rgba(255,255,255,.06);
}

.sidebar-logo .logo-mark {
  display: flex;
  align-items: center;
  gap: 8px;
}

.logo-icon {
  width: 28px; height: 28px;
  background: var(--primary);
  border-radius: 6px;
  display: flex; align-items: center; justify-content: center;
  color: white;
  font-size: 13px;
  flex-shrink: 0;
}

.logo-text {
  font-size: 13px;
  font-weight: 600;
  color: #f9fafb;
  letter-spacing: -.2px;
}

.logo-sub {
  font-size: 9px;
  color: rgba(255,255,255,.35);
  margin-top: 1px;
  text-transform: uppercase;
  letter-spacing: .5px;
}

.sidebar-nav {
  flex: 1;
  padding: 10px 0;
  list-style: none;
}

.sidebar-nav li a {
  display: flex;
  align-items: center;
  gap: 9px;
  padding: 8px 14px;
  color: rgba(255,255,255,.55);
  text-decoration: none;
  font-size: 11.5px;
  font-weight: 400;
  border-left: 2px solid transparent;
  transition: all .15s;
}

.sidebar-nav li a i {
  width: 14px;
  text-align: center;
  font-size: 11px;
  flex-shrink: 0;
}

.sidebar-nav li a:hover {
  color: rgba(255,255,255,.85);
  background: rgba(255,255,255,.04);
}

.sidebar-nav li a.active {
  color: #ffffff;
  background: rgba(255,255,255,.07);
  border-left-color: var(--primary);
  font-weight: 500;
}

.sidebar-nav li a.nav-danger {
  color: rgba(239,68,68,.7);
}

.sidebar-nav li a.nav-danger:hover {
  color: #ef4444;
  background: rgba(239,68,68,.06);
}

.sidebar-footer {
  padding: 12px 14px;
  border-top: 1px solid rgba(255,255,255,.06);
}

.session-pill {
  display: flex;
  align-items: center;
  gap: 7px;
  background: rgba(255,255,255,.05);
  border-radius: 6px;
  padding: 7px 10px;
}

.session-dot {
  width: 6px; height: 6px;
  border-radius: 50%;
  background: #22c55e;
  box-shadow: 0 0 0 2px rgba(34,197,94,.25);
  flex-shrink: 0;
}

.session-label {
  font-size: 10px;
  color: rgba(255,255,255,.4);
}

.session-val {
  font-size: 10.5px;
  color: rgba(255,255,255,.8);
  font-weight: 500;
  font-family: 'DM Mono', monospace;
}

/* ---- MAIN LAYOUT ---- */
.main-sales {
  margin-left: var(--sidebar-w);
  display: flex;
  width: calc(100% - var(--sidebar-w));
  height: 100vh;
  overflow: hidden;
}

.content-area {
  flex: 1;
  padding: 16px;
  overflow-y: auto;
  display: flex;
  flex-direction: column;
  gap: 14px;
  min-width: 0;
}

/* ---- STATS ROW ---- */
.stats-row {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 10px;
  flex-shrink: 0;
}

.stat-card {
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: var(--radius);
  padding: 11px 13px;
  display: flex;
  align-items: center;
  gap: 11px;
  box-shadow: var(--shadow);
  border-left: 3px solid var(--border);
}

.stat-card.c-blue  { border-left-color: var(--primary); }
.stat-card.c-green { border-left-color: var(--success); }
.stat-card.c-amber { border-left-color: var(--warning); }

.stat-icon {
  width: 30px; height: 30px;
  border-radius: 6px;
  display: flex; align-items: center; justify-content: center;
  font-size: 13px;
  flex-shrink: 0;
}

.stat-icon.blue  { background: #eff6ff; color: var(--primary); }
.stat-icon.green { background: #f0fdf4; color: var(--success); }
.stat-icon.amber { background: #fffbeb; color: var(--warning); }

.stat-label {
  font-size: 10px;
  color: var(--text-2);
  text-transform: uppercase;
  letter-spacing: .4px;
  font-weight: 500;
}

.stat-value {
  font-size: 16px;
  font-weight: 600;
  color: var(--text-1);
  font-family: 'DM Mono', monospace;
  letter-spacing: -.5px;
  line-height: 1.2;
  margin-top: 1px;
}

/* ---- TABS ---- */
.tabs-nav {
  display: flex;
  gap: 2px;
  border-bottom: 1px solid var(--border);
  flex-shrink: 0;
}

.tab-btn {
  padding: 7px 14px;
  border: none;
  background: none;
  cursor: pointer;
  font-family: 'DM Sans', sans-serif;
  font-size: 11.5px;
  font-weight: 500;
  color: var(--text-2);
  border-bottom: 2px solid transparent;
  margin-bottom: -1px;
  transition: all .15s;
  display: flex;
  align-items: center;
  gap: 6px;
  white-space: nowrap;
}

.tab-btn i { font-size: 10px; }

.tab-btn:hover { color: var(--text-1); }

.tab-btn.active {
  color: var(--primary);
  border-bottom-color: var(--primary);
}

.tab-count {
  background: #e0e7ff;
  color: var(--primary);
  font-size: 9px;
  font-weight: 600;
  padding: 1px 5px;
  border-radius: 10px;
  font-family: 'DM Mono', monospace;
}

/* ---- PANELS ---- */
.v-panel { display: none; flex: 1; min-height: 0; overflow-y: auto; }
.v-panel.active { display: block; }

/* ---- SEARCH ---- */
.search-wrap {
  position: relative;
  margin-bottom: 12px;
}

.search-wrap i {
  position: absolute;
  left: 10px;
  top: 50%;
  transform: translateY(-50%);
  color: var(--text-3);
  font-size: 11px;
  pointer-events: none;
}

#search-prod {
  width: 100%;
  padding: 8px 10px 8px 30px;
  border: 1px solid var(--border);
  border-radius: var(--radius);
  font-family: 'DM Sans', sans-serif;
  font-size: 12px;
  color: var(--text-1);
  background: var(--surface);
  outline: none;
  transition: border-color .15s;
}

#search-prod:focus {
  border-color: var(--primary);
  box-shadow: 0 0 0 3px rgba(29,78,216,.08);
}

#search-prod::placeholder { color: var(--text-3); }

/* ---- PRODUCT GRID ---- */
.product-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(145px, 1fr));
  gap: 8px;
}

.product-card {
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: var(--radius);
  padding: 10px;
  cursor: pointer;
  position: relative;
  transition: all .15s;
  box-shadow: var(--shadow);
}

.product-card:hover {
  border-color: var(--primary);
  box-shadow: 0 4px 12px rgba(29,78,216,.1);
  transform: translateY(-1px);
}

.product-card:active {
  transform: translateY(0);
}

.stock-badge {
  position: absolute;
  top: 7px; right: 7px;
  background: #f0fdf4;
  color: var(--success);
  font-size: 9.5px;
  font-weight: 600;
  padding: 2px 5px;
  border-radius: 4px;
  font-family: 'DM Mono', monospace;
  border: 1px solid #dcfce7;
}

.product-name {
  font-size: 11.5px;
  font-weight: 600;
  color: var(--text-1);
  margin-bottom: 2px;
  padding-right: 28px;
  line-height: 1.35;
}

.product-mol {
  font-size: 10px;
  color: var(--text-3);
  margin-bottom: 6px;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.product-price {
  font-size: 13px;
  font-weight: 600;
  color: var(--primary);
  font-family: 'DM Mono', monospace;
}

.unit-tag {
  display: inline-block;
  font-size: 9px;
  background: #eff6ff;
  color: var(--primary);
  padding: 1px 5px;
  border-radius: 3px;
  margin-top: 4px;
  font-weight: 500;
}

/* ---- PANEL ATTENTE ---- */
.attente-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
  gap: 10px;
  margin-top: 10px;
}

.attente-card {
  background: var(--surface);
  border: 1px solid var(--border);
  border-left: 3px solid var(--warning);
  border-radius: var(--radius);
  padding: 12px;
}

.attente-card h6 {
  font-size: 12px;
  font-weight: 600;
  color: var(--text-1);
  margin-bottom: 3px;
}

.attente-card small {
  color: var(--text-3);
  font-size: 10px;
  display: block;
  margin-bottom: 8px;
}

.btn-reprendre {
  width: 100%;
  padding: 6px;
  background: var(--success);
  color: white;
  border: none;
  border-radius: 4px;
  font-size: 11px;
  font-weight: 500;
  cursor: pointer;
  font-family: 'DM Sans', sans-serif;
}

.btn-reprendre:hover { background: #15803d; }

/* ---- PANEL VIDE ---- */
.panel-section-title {
  font-size: 11px;
  font-weight: 600;
  color: var(--text-2);
  text-transform: uppercase;
  letter-spacing: .5px;
  margin-bottom: 10px;
}

/* ---- TABLE ---- */
.data-table {
  width: 100%;
  border-collapse: collapse;
  background: var(--surface);
  border-radius: var(--radius);
  overflow: hidden;
  border: 1px solid var(--border);
  box-shadow: var(--shadow);
}

.data-table thead th {
  background: #f9fafb;
  padding: 7px 11px;
  font-size: 10px;
  font-weight: 600;
  color: var(--text-2);
  text-transform: uppercase;
  letter-spacing: .4px;
  text-align: left;
  border-bottom: 1px solid var(--border);
}

.data-table tbody td {
  padding: 8px 11px;
  font-size: 11.5px;
  color: var(--text-1);
  border-bottom: 1px solid #f3f4f6;
}

.data-table tbody tr:last-child td { border-bottom: none; }

.data-table tbody tr:hover td { background: #f9fafb; }

/* ---- BADGES ---- */
.badge {
  display: inline-block;
  padding: 2px 7px;
  border-radius: 4px;
  font-size: 9.5px;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: .3px;
}

.badge-especes { background: #f0fdf4; color: #16a34a; border: 1px solid #dcfce7; }
.badge-mobile  { background: #eff6ff; color: #1d4ed8; border: 1px solid #dbeafe; }
.badge-assurance { background: #fdf4ff; color: #7c3aed; border: 1px solid #f3e8ff; }

.btn-action-sm {
  padding: 4px 8px;
  border: 1px solid var(--border);
  background: var(--surface);
  border-radius: 4px;
  font-size: 10px;
  cursor: pointer;
  color: var(--text-2);
  font-family: 'DM Sans', sans-serif;
  transition: all .12s;
}

.btn-action-sm:hover {
  border-color: var(--primary);
  color: var(--primary);
  background: #eff6ff;
}

/* ---- PANEL CLOTURE ---- */
.cloture-box {
  max-width: 420px;
  margin: 20px auto;
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: var(--radius);
  padding: 28px;
  text-align: center;
  box-shadow: var(--shadow);
}

.cloture-icon {
  width: 48px; height: 48px;
  background: #fef3c7;
  border-radius: 10px;
  display: flex; align-items: center; justify-content: center;
  margin: 0 auto 14px;
  font-size: 22px;
  color: var(--warning);
}

.cloture-box h4 {
  font-size: 14px;
  font-weight: 600;
  color: var(--text-1);
  margin-bottom: 6px;
}

.cloture-box p {
  font-size: 11.5px;
  color: var(--text-2);
  margin-bottom: 18px;
  line-height: 1.5;
}

/* ---- CART SECTION ---- */
.cart-section {
  width: var(--cart-w);
  background: var(--surface);
  border-left: 1px solid var(--border);
  display: flex;
  flex-direction: column;
  height: 100vh;
  flex-shrink: 0;
}

.cart-header {
  background: var(--sidebar);
  color: white;
  padding: 13px 14px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  border-bottom: 1px solid rgba(255,255,255,.08);
  flex-shrink: 0;
}

.cart-header-left {
  display: flex;
  align-items: center;
  gap: 8px;
}

.cart-header-left i { font-size: 12px; color: rgba(255,255,255,.6); }

.cart-header-title {
  font-size: 11.5px;
  font-weight: 600;
  color: white;
  text-transform: uppercase;
  letter-spacing: .5px;
}

.cart-badge {
  background: rgba(255,255,255,.12);
  color: rgba(255,255,255,.8);
  font-size: 9.5px;
  font-weight: 600;
  padding: 2px 7px;
  border-radius: 10px;
  font-family: 'DM Mono', monospace;
}

.cart-items {
  flex: 1;
  overflow-y: auto;
  padding: 10px;
  min-height: 0;
}

.cart-items::-webkit-scrollbar { width: 4px; }
.cart-items::-webkit-scrollbar-thumb { background: #d1d5db; border-radius: 4px; }

/* ---- CART ITEM ---- */
.cart-item {
  background: #fafafa;
  border: 1px solid var(--border);
  border-radius: 5px;
  padding: 8px 9px;
  margin-bottom: 6px;
}

.cart-item-top {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  margin-bottom: 6px;
  gap: 6px;
}

.cart-item-name {
  font-size: 11px;
  font-weight: 600;
  color: var(--text-1);
  line-height: 1.3;
  flex: 1;
}

.cart-item-mode {
  font-size: 9px;
  background: #e0e7ff;
  color: var(--primary);
  padding: 1px 5px;
  border-radius: 3px;
  font-weight: 500;
  white-space: nowrap;
  flex-shrink: 0;
}

.cart-item-bottom {
  display: flex;
  justify-content: space-between;
  align-items: center;
}

.qty-ctrl {
  display: flex;
  align-items: center;
  gap: 0;
}

.qty-btn {
  width: 22px; height: 22px;
  border: 1px solid var(--border);
  background: var(--surface);
  cursor: pointer;
  font-size: 12px;
  color: var(--text-2);
  display: flex; align-items: center; justify-content: center;
  transition: all .1s;
}

.qty-btn:first-child { border-radius: 4px 0 0 4px; }
.qty-btn:last-child  { border-radius: 0 4px 4px 0; }
.qty-btn:hover { background: #f3f4f6; }

.qty-input {
  width: 30px; height: 22px;
  border: 1px solid var(--border);
  border-left: none; border-right: none;
  text-align: center;
  font-size: 11px;
  font-family: 'DM Mono', monospace;
  font-weight: 500;
  color: var(--text-1);
  background: white;
  outline: none;
}

.cart-item-total {
  font-size: 12px;
  font-weight: 600;
  color: var(--text-1);
  font-family: 'DM Mono', monospace;
}

.btn-remove {
  background: none;
  border: none;
  cursor: pointer;
  color: var(--text-3);
  padding: 2px 4px;
  font-size: 10px;
  border-radius: 3px;
  transition: all .12s;
}

.btn-remove:hover { color: var(--danger); background: #fef2f2; }

/* ---- EMPTY CART ---- */
.empty-cart {
  text-align: center;
  color: var(--text-3);
  padding: 40px 20px;
}

.empty-cart i { font-size: 28px; margin-bottom: 10px; display: block; }
.empty-cart p { font-size: 12px; margin-bottom: 4px; }
.empty-cart small { font-size: 10.5px; }

/* ---- CART FOOTER ---- */
.cart-footer {
  padding: 12px;
  background: #f9fafb;
  border-top: 1px solid var(--border);
  flex-shrink: 0;
}

/* TOTALS */
.totals-box {
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: 5px;
  padding: 9px 11px;
  margin-bottom: 10px;
}

.total-line-item {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 5px;
  font-size: 11.5px;
  color: var(--text-2);
}

.total-line-item:last-child { margin-bottom: 0; }

.total-line-item.remise-line {
  color: var(--warning);
  cursor: pointer;
}

.total-line-item.remise-line:hover { opacity: .8; }

.totals-divider {
  border: none;
  border-top: 1px solid var(--border);
  margin: 7px 0;
}

.grand-total {
  display: flex;
  justify-content: space-between;
  align-items: center;
}

.grand-total-label {
  font-size: 11px;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: .5px;
  color: var(--text-1);
}

.grand-total-value {
  font-size: 18px;
  font-weight: 700;
  color: var(--text-1);
  font-family: 'DM Mono', monospace;
  letter-spacing: -.5px;
}

/* PAYMENT */
.payment-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 6px;
  margin-bottom: 8px;
}

.pay-field {
  display: flex;
  flex-direction: column;
  gap: 3px;
}

.pay-label {
  font-size: 9.5px;
  font-weight: 600;
  color: var(--text-2);
  text-transform: uppercase;
  letter-spacing: .3px;
}

.pay-input {
  padding: 6px 8px;
  border: 1px solid var(--border);
  border-radius: 4px;
  font-family: 'DM Mono', monospace;
  font-size: 12px;
  color: var(--text-1);
  outline: none;
  transition: border-color .12s;
}

.pay-input:focus { border-color: var(--primary); }

.change-display {
  padding: 6px 8px;
  border: 1px solid var(--border);
  border-radius: 4px;
  font-family: 'DM Mono', monospace;
  font-size: 12px;
  font-weight: 600;
  background: #f9fafb;
}

.change-display.positive { color: var(--success); }
.change-display.negative { color: var(--danger); }

.pay-select {
  width: 100%;
  padding: 6px 8px;
  border: 1px solid var(--border);
  border-radius: 4px;
  font-family: 'DM Sans', sans-serif;
  font-size: 11.5px;
  color: var(--text-1);
  background: white;
  outline: none;
  margin-bottom: 6px;
  cursor: pointer;
}

.pay-select:focus { border-color: var(--primary); }

.client-select-wrap {
  position: relative;
  margin-bottom: 10px;
}

.client-select-wrap i {
  position: absolute;
  left: 8px;
  top: 50%;
  transform: translateY(-50%);
  font-size: 10px;
  color: var(--text-3);
  pointer-events: none;
}

.client-select {
  width: 100%;
  padding: 6px 8px 6px 24px;
  border: 1px solid var(--border);
  border-radius: 4px;
  font-family: 'DM Sans', sans-serif;
  font-size: 11.5px;
  color: var(--text-1);
  background: white;
  outline: none;
  cursor: pointer;
}

.client-select:focus { border-color: var(--primary); }

/* BUTTONS */
.btn-hold {
  width: 100%;
  padding: 8px;
  border: 1px solid var(--border);
  background: var(--surface);
  color: var(--warning);
  font-family: 'DM Sans', sans-serif;
  font-size: 11.5px;
  font-weight: 600;
  border-radius: 5px;
  cursor: pointer;
  display: flex; align-items: center; justify-content: center;
  gap: 6px;
  margin-bottom: 6px;
  transition: all .12s;
}

.btn-hold:hover {
  background: #fffbeb;
  border-color: var(--warning);
}

.btn-pay {
  width: 100%;
  padding: 10px;
  border: none;
  background: var(--success);
  color: white;
  font-family: 'DM Sans', sans-serif;
  font-size: 12.5px;
  font-weight: 700;
  border-radius: 5px;
  cursor: pointer;
  display: flex; align-items: center; justify-content: center;
  gap: 8px;
  letter-spacing: .2px;
  transition: background .15s;
}

.btn-pay:hover { background: #15803d; }

.btn-danger-full {
  width: 100%;
  padding: 9px;
  border: none;
  background: var(--danger);
  color: white;
  font-family: 'DM Sans', sans-serif;
  font-size: 12px;
  font-weight: 700;
  border-radius: 5px;
  cursor: pointer;
  display: flex; align-items: center; justify-content: center;
  gap: 6px;
  transition: background .12s;
}

.btn-danger-full:hover { background: #b91c1c; }

/* Scrollbar content */
.content-area::-webkit-scrollbar { width: 5px; }
.content-area::-webkit-scrollbar-thumb { background: #d1d5db; border-radius: 4px; }

/* ---- PRODUIT HORS STOCK ---- */
.product-card.out-of-stock {
  opacity: .72;
  border-color: #fecaca;
  background: #fffafa;
  cursor: default;
}
.product-card.out-of-stock:hover {
  border-color: #fca5a5;
  box-shadow: 0 2px 8px rgba(220,38,38,.08);
  transform: none;
}
.stock-badge.rupture {
  background: #fef2f2;
  color: #dc2626;
  border-color: #fecaca;
}
.rupture-label {
  font-size: 9px;
  color: #dc2626;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: .4px;
  margin-top: 4px;
  display: block;
}

/* Bouton "Voir equivalents" */
.btn-equiv {
  position: absolute;
  bottom: 8px; right: 8px;
  width: 22px; height: 22px;
  background: #fef3c7;
  border: 1px solid #fde68a;
  border-radius: 4px;
  display: flex; align-items: center; justify-content: center;
  cursor: pointer;
  color: #d97706;
  font-size: 10px;
  transition: all .15s;
  z-index: 2;
}
.btn-equiv:hover {
  background: #d97706;
  border-color: #d97706;
  color: white;
  transform: scale(1.1);
}

/* Tooltip "Equivalents dispo" */
.equiv-tooltip {
  position: absolute;
  bottom: 32px; right: 4px;
  background: #111827;
  color: white;
  font-size: 9px;
  padding: 3px 7px;
  border-radius: 4px;
  white-space: nowrap;
  pointer-events: none;
  opacity: 0;
  transition: opacity .15s;
  font-family: 'DM Sans', sans-serif;
}
.btn-equiv:hover + .equiv-tooltip,
.btn-equiv:focus + .equiv-tooltip {
  opacity: 1;
}

/* Modal equivalents — carte */
.equiv-card {
  background: #f9fafb;
  border: 1px solid #e5e7eb;
  border-radius: 6px;
  padding: 10px 12px;
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 10px;
  margin-bottom: 8px;
  transition: border-color .12s;
}
.equiv-card:hover { border-color: #1d4ed8; background: #eff6ff; }
.equiv-card:last-child { margin-bottom: 0; }
.equiv-card-left { flex: 1; min-width: 0; }
.equiv-card-name {
  font-size: 12px;
  font-weight: 700;
  color: #111827;
  margin-bottom: 2px;
}
.equiv-card-mol {
  font-size: 10px;
  color: #9ca3af;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.equiv-card-right { text-align: right; flex-shrink: 0; }
.equiv-card-price {
  font-size: 13px;
  font-weight: 700;
  color: #1d4ed8;
  font-family: 'DM Mono', monospace;
}
.equiv-stock-pill {
  display: inline-block;
  font-size: 9px;
  font-weight: 600;
  padding: 1px 6px;
  border-radius: 10px;
  background: #f0fdf4;
  color: #16a34a;
  border: 1px solid #dcfce7;
  font-family: 'DM Mono', monospace;
  margin-top: 3px;
}
.btn-add-equiv {
  padding: 5px 10px;
  background: #16a34a;
  color: white;
  border: none;
  border-radius: 4px;
  font-size: 10.5px;
  font-weight: 600;
  cursor: pointer;
  font-family: 'DM Sans', sans-serif;
  white-space: nowrap;
  transition: background .12s;
}
.btn-add-equiv:hover { background: #15803d; }

/* ════════════════════════════
   SUB-TABS & STATS
════════════════════════════ */
.sub-tab-btn {
  padding: 6px 13px;
  border: none;
  background: none;
  cursor: pointer;
  font-family: 'DM Sans', sans-serif;
  font-size: 11px;
  font-weight: 500;
  color: var(--text-2);
  border-bottom: 2px solid transparent;
  margin-bottom: -1px;
  transition: all .15s;
  display: flex;
  align-items: center;
  gap: 5px;
  white-space: nowrap;
}
.sub-tab-btn i { font-size: 10px; }
.sub-tab-btn:hover { color: var(--text-1); }
.sub-tab-btn.active {
  color: var(--success);
  border-bottom-color: var(--success);
  font-weight: 600;
}

.sub-panel { display: none; }
.sub-panel.active { display: block; }

/* KPI cards */
.kpi-card {
  border-radius: var(--radius);
  padding: 12px 14px;
  border: 1px solid var(--border);
  box-shadow: var(--shadow);
}
.kpi-card.kpi-blue   { background: #eff6ff; border-left: 3px solid var(--primary); }
.kpi-card.kpi-green  { background: #f0fdf4; border-left: 3px solid var(--success); }
.kpi-card.kpi-amber  { background: #fffbeb; border-left: 3px solid var(--warning); }
.kpi-card.kpi-purple { background: #fdf4ff; border-left: 3px solid var(--purple); }
.kpi-card.kpi-red    { background: #fef2f2; border-left: 3px solid var(--danger); }

.kpi-label {
  font-size: 9.5px;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: .4px;
  color: var(--text-2);
  margin-bottom: 5px;
  display: flex;
  align-items: center;
  gap: 5px;
}
.kpi-value {
  font-size: 18px;
  font-weight: 700;
  font-family: 'DM Mono', monospace;
  color: var(--text-1);
  letter-spacing: -.5px;
  line-height: 1.2;
}
.kpi-delta {
  margin-top: 4px;
  font-size: 10px;
  font-weight: 600;
}
.kpi-delta.up   { color: var(--success); }
.kpi-delta.down { color: var(--danger); }
.kpi-delta.flat { color: var(--text-3); }

/* Stat box */
.stat-box {
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: var(--radius);
  padding: 14px;
  box-shadow: var(--shadow);
  margin-bottom: 0;
}
.stat-box-title {
  font-size: 11px;
  font-weight: 700;
  color: var(--text-1);
  text-transform: uppercase;
  letter-spacing: .4px;
  margin-bottom: 4px;
}

/* Barre horizontale CSS */
.bar-row {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-bottom: 7px;
}
.bar-label {
  font-size: 10.5px;
  color: var(--text-2);
  width: 90px;
  flex-shrink: 0;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.bar-track {
  flex: 1;
  background: #f3f4f6;
  border-radius: 99px;
  height: 7px;
  overflow: hidden;
}
.bar-fill {
  height: 7px;
  border-radius: 99px;
  transition: width .5s ease;
}
.bar-val {
  font-size: 10px;
  font-family: 'DM Mono', monospace;
  font-weight: 600;
  color: var(--text-1);
  width: 80px;
  text-align: right;
  flex-shrink: 0;
}

/* Performance badge */
.perf-badge {
  display: inline-flex;
  align-items: center;
  gap: 3px;
  padding: 2px 8px;
  border-radius: 99px;
  font-size: 9.5px;
  font-weight: 700;
  text-transform: uppercase;
}
.perf-star   { background:#fef3c7; color:#b45309; border:1px solid #fde68a; }
.perf-good   { background:#f0fdf4; color:#16a34a; border:1px solid #bbf7d0; }
.perf-avg    { background:#eff6ff; color:#1d4ed8; border:1px solid #bfdbfe; }
.perf-low    { background:#fff7ed; color:#c2410c; border:1px solid #fed7aa; }
.perf-dead   { background:#fef2f2; color:#dc2626; border:1px solid #fecaca; }

/* Famille card */
.fam-card {
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: var(--radius);
  padding: 12px;
  cursor: pointer;
  transition: all .15s;
  box-shadow: var(--shadow);
  border-top: 3px solid var(--primary);
  position: relative;
  overflow: hidden;
}
.fam-card:hover {
  border-top-color: var(--success);
  box-shadow: 0 4px 12px rgba(0,0,0,.08);
  transform: translateY(-1px);
}
.fam-card.selected {
  border-top-color: var(--success);
  background: #f0fdf4;
}
.fam-card-name {
  font-size: 12px;
  font-weight: 700;
  color: var(--text-1);
  margin-bottom: 3px;
}
.fam-card-ca {
  font-size: 15px;
  font-weight: 700;
  font-family: 'DM Mono', monospace;
  color: var(--primary);
}
.fam-card-sub {
  font-size: 10px;
  color: var(--text-3);
  margin-top: 2px;
}
.fam-card-part-bar {
  margin-top: 8px;
  background: #f3f4f6;
  border-radius: 99px;
  height: 4px;
  overflow: hidden;
}
.fam-card-part-fill {
  height: 4px;
  border-radius: 99px;
  background: var(--primary);
  transition: width .4s ease;
}

/* Container de données */
.marge-container { padding: 20px; background: #f8f9fa; border-radius: 8px; }

/* Cartes de résumé */
.stats-cards { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin-bottom: 30px; }
.stat-card { background: white; padding: 20px; border-radius: 12px; box-shadow: 0 4px 6px rgba(0,0,0,0.05); border-left: 5px solid #4e73df; }
.stat-card h4 { color: #858796; font-size: 0.8rem; text-transform: uppercase; margin: 0; }
.stat-card .value { font-size: 1.5rem; font-weight: bold; color: #333; }

/* Table style */
.pro-table { width: 100%; border-collapse: collapse; background: white; border-radius: 10px; overflow: hidden; box-shadow: 0 0 20px rgba(0,0,0,0.05); }
.pro-table thead { background: #4e73df; color: white; }
.pro-table th, .pro-table td { padding: 15px 20px; text-align: left; border-bottom: 1px solid #eee; }
.pro-table tbody tr:hover { background-color: #f1f4ff; transition: 0.3s; }

/* Badges de marge */
.badge-marge { padding: 5px 10px; border-radius: 20px; font-weight: bold; font-size: 0.9rem; }
.positive { background: #d4edda; color: #155724; }
.negative { background: #f8d7da; color: #721c24; }
.pro-input {
    padding: 8px 15px;
    border: 1px solid #d1d3e2;
    border-radius: 5px;
    color: #6e707e;
    outline: none;
}

.pro-input:focus {
    border-color: #4e73df;
    box-shadow: 0 0 0 0.2rem rgba(78, 115, 223, 0.25);
}

.btn-filter {
    background-color: #4e73df;
    color: white;
    border: none;
    padding: 9px 20px;
    border-radius: 5px;
    cursor: pointer;
    font-weight: bold;
    transition: 0.3s;
}

.btn-filter:hover {
    background-color: #2e59d9;
}
</style>
</head>
<body>

<!-- ============================
     SIDEBAR
============================= -->
<nav class="sidebar">
  <div class="sidebar-logo">
    <div class="logo-mark">
      <div class="logo-icon"><i class="fas fa-pills"></i></div>
      <div>
        <div class="logo-text">PharmAssist</div>
        <div class="logo-sub">Pharmacie</div>
      </div>
    </div>
  </div>

  <ul class="sidebar-nav">
    <li><a href="dashboard.php"><i class="fas fa-th-large"></i>Dashboard</a></li>
    <li><a href="ventes.php" class="active"><i class="fas fa-shopping-cart"></i>Ventes</a></li>
    <li><a href="caisse.php"><i class="fas fa-cash-register"></i>Caisse</a></li>
    <li><a href="produits_gestion.php"><i class="fas fa-boxes"></i>Stocks &amp; Flux</a></li>
    <li><a href="logout.php" class="nav-danger"><i class="fas fa-sign-out-alt"></i>Déconnexion</a></li>
  </ul>

  <div class="sidebar-footer">
    <div class="session-pill">
      <div class="session-dot"></div>
      <div>
        <div class="session-label">Session active</div>
        <div class="session-val">#<?= $id_session ?></div>
      </div>
    </div>
  </div>
</nav>

<!-- ============================
     MAIN
============================= -->
<div class="main-sales">

  <!-- CONTENT AREA -->
  <div class="content-area">

    <!-- STATS -->
    <div class="stats-row">
      <div class="stat-card c-blue">
        <div class="stat-icon blue"><i class="fas fa-receipt"></i></div>
        <div>
          <div class="stat-label">Tickets</div>
          <div class="stat-value"><?= $stats['nb'] ?? 0 ?></div>
        </div>
      </div>
      <div class="stat-card c-green">
        <div class="stat-icon green"><i class="fas fa-coins"></i></div>
        <div>
          <div class="stat-label">CA Jour</div>
          <div class="stat-value"><?= number_format($stats['ca'] ?? 0, 0, '.', ' ') ?> F</div>
        </div>
      </div>
      <div class="stat-card c-amber">
        <div class="stat-icon amber"><i class="fas fa-user-shield"></i></div>
        <div>
          <div class="stat-label">Session</div>
          <div class="stat-value">#<?= $id_session ?></div>
        </div>
      </div>
    </div>

    <!-- TABS -->
    <div class="tabs-nav">
      <button class="tab-btn active" onclick="showPanel('v-comptoir')">
        <i class="fas fa-cash-register"></i> Comptoir
      </button>
      <button class="tab-btn" onclick="showPanel('v-attente')">
        <i class="fas fa-pause-circle"></i> En attente
        <span class="tab-count" id="count-attente">0</span>
      </button>
      <button class="tab-btn" onclick="showPanel('v-historique')">
        <i class="fas fa-list-alt"></i> Historique
      </button>
      <button class="tab-btn" onclick="showPanel('v-cloture')">
        <i class="fas fa-lock"></i> Cloture
      </button>

      <button class="tab-btn" onclick="showPanel('v-stats')">
        <i class="fas fa-calendar-alt"></i> STATS
      </button>

      <button class="tab-btn" onclick="showPanel('marges')">
        <i class="fas fa-chart-line"></i> Marges
    </button>
    </div>

    <!-- ---- PANEL COMPTOIR ---- -->
    <div id="v-comptoir" class="v-panel active">
      <div class="search-wrap">
        <i class="fas fa-search"></i>
        <input type="text" id="search-prod" placeholder="Rechercher nom commercial ou molecule...">
      </div>

      <div class="product-grid" id="results-area">
        <?php foreach($produits as $p): 
  $enStock = $p['stock_dispo'] > 0;
  $mol     = addslashes($p['molecule'] ?? '');
  $desc    = addslashes($p['description'] ?? '');
?>
  <div class="product-card <?= !$enStock ? 'out-of-stock' : '' ?>"
    <?php if($enStock): ?>
      onclick="addToCart(
        <?= $p['id_produit'] ?>,
        '<?= addslashes($p['nom_commercial']) ?>',
        <?= $p['prix_unitaire'] ?>,
        <?= $p['stock_dispo'] ?>,
        <?= $p['prix_unitaire_detail'] ?? 0 ?>,
        <?= $p['coefficient_division'] ?? 1 ?>
      )"
    <?php endif; ?>>

    <!-- Badge stock -->
    <?php if($enStock): ?>
      <span class="stock-badge"><?= $p['stock_dispo'] ?></span>
    <?php else: ?>
      <span class="stock-badge rupture">0</span>
    <?php endif; ?>

    <div class="product-name"><?= htmlspecialchars($p['nom_commercial']) ?></div>
    <div class="product-mol"><?= htmlspecialchars($p['molecule']) ?></div>

    <?php if($enStock): ?>
      <div class="product-price"><?= number_format($p['prix_unitaire'], 0, '.', ' ') ?> F</div>
      <?php if($p['prix_unitaire_detail'] > 0): ?>
        <span class="unit-tag">Detail possible</span>
      <?php endif; ?>
    <?php else: ?>
      <div class="product-price" style="color:#9ca3af; font-size:11px;">Rupture de stock</div>
      <span class="rupture-label">Indisponible</span>

      <!-- Bouton equivalents -->
      <button class="btn-equiv"
        onclick="event.stopPropagation(); voirEquivalents(
          <?= $p['id_produit'] ?>,
          '<?= $mol ?>',
          '<?= $desc ?>',
          '<?= addslashes($p['nom_commercial']) ?>'
        )"
        title="Chercher equivalents">
        <i class="fas fa-exchange-alt"></i>
      </button>
      <div class="equiv-tooltip">Voir equivalents</div>
    <?php endif; ?>
  </div>
<?php endforeach; ?>

      </div>
    </div>

    <!-- ════════════════════════════════════════════════
     PANEL STATS CA — sous-navigation
════════════════════════════════════════════════ -->
<div id="v-stats" class="v-panel">

  <!-- Sous-navigation -->
  <div style="display:flex;gap:4px;border-bottom:1px solid #e5e7eb;margin-bottom:14px;overflow-x:auto;flex-shrink:0;">
    <button class="sub-tab-btn active" onclick="showSubStat('s-jour')"    id="stab-s-jour">
      <i class="fas fa-calendar-day"></i> Jour
    </button>
    <button class="sub-tab-btn"        onclick="showSubStat('s-semaine')" id="stab-s-semaine">
      <i class="fas fa-calendar-week"></i> Semaine
    </button>
    <button class="sub-tab-btn"        onclick="showSubStat('s-mois')"    id="stab-s-mois">
      <i class="fas fa-calendar-alt"></i> Mois
    </button>
    <button class="sub-tab-btn"        onclick="showSubStat('s-produits')" id="stab-s-produits">
      <i class="fas fa-pills"></i> Produits
    </button>
    <button class="sub-tab-btn"        onclick="showSubStat('s-familles')" id="stab-s-familles">
      <i class="fas fa-layer-group"></i> Familles
    </button>
  </div>

  <!-- ── SUB-PANEL : JOUR ─────────────────────── -->
  <div id="s-jour" class="sub-panel active">

    <!-- KPI du jour -->
    <div id="kpi-jour" style="display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-bottom:14px;">
      <div class="kpi-card kpi-blue">
        <div class="kpi-label"><i class="fas fa-coins"></i> CA Total</div>
        <div class="kpi-value" id="kpi-j-ca">—</div>
      </div>
      <div class="kpi-card kpi-green">
        <div class="kpi-label"><i class="fas fa-receipt"></i> Tickets</div>
        <div class="kpi-value" id="kpi-j-tickets">—</div>
      </div>
      <div class="kpi-card kpi-amber">
        <div class="kpi-label"><i class="fas fa-shopping-basket"></i> Panier moyen</div>
        <div class="kpi-value" id="kpi-j-panier">—</div>
      </div>
      <div class="kpi-card kpi-purple">
        <div class="kpi-label"><i class="fas fa-tag"></i> Remises</div>
        <div class="kpi-value" id="kpi-j-remise">—</div>
      </div>
    </div>

    <!-- Modes de paiement -->
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:14px;">
      <!-- Repartition modes -->
      <div class="stat-box">
        <div class="stat-box-title">Repartition par mode de paiement</div>
        <div id="modes-jour-bars"></div>
      </div>
      <!-- Heure de pointe -->
      <div class="stat-box">
        <div class="stat-box-title">Ventes par tranche horaire</div>
        <div id="heures-jour-bars"></div>
      </div>
    </div>

    <!-- Top produits du jour -->
    <div class="stat-box">
      <div class="stat-box-title">
        Top 10 produits vendus aujourd'hui
        <span id="date-label-jour" style="float:right;font-size:10px;color:#9ca3af;font-weight:400;"></span>
      </div>
      <table class="data-table" style="margin-top:8px;">
        <thead>
          <tr>
            <th>#</th>
            <th>Produit</th>
            <th style="text-align:center;">Qte vendue</th>
            <th style="text-align:right;">CA</th>
            <th style="text-align:right;">Part du CA</th>
          </tr>
        </thead>
        <tbody id="top-produits-jour">
          <tr><td colspan="5" style="text-align:center;padding:20px;color:#9ca3af;">Chargement...</td></tr>
        </tbody>
      </table>
    </div>

  </div><!-- end s-jour -->

  <!-- ── SUB-PANEL : SEMAINE ──────────────────── -->
  <div id="s-semaine" class="sub-panel">

    <!-- KPI semaine -->
    <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-bottom:14px;">
      <div class="kpi-card kpi-blue">
        <div class="kpi-label"><i class="fas fa-coins"></i> CA Semaine</div>
        <div class="kpi-value" id="kpi-s-ca">—</div>
        <div class="kpi-delta" id="kpi-s-vs-prev"></div>
      </div>
      <div class="kpi-card kpi-green">
        <div class="kpi-label"><i class="fas fa-receipt"></i> Tickets</div>
        <div class="kpi-value" id="kpi-s-tickets">—</div>
      </div>
      <div class="kpi-card kpi-amber">
        <div class="kpi-label"><i class="fas fa-calendar-day"></i> Meilleur jour</div>
        <div class="kpi-value" id="kpi-s-best-day" style="font-size:12px;">—</div>
      </div>
      <div class="kpi-card kpi-red">
        <div class="kpi-label"><i class="fas fa-arrow-down"></i> Jour le + faible</div>
        <div class="kpi-value" id="kpi-s-worst-day" style="font-size:12px;">—</div>
      </div>
    </div>

    <!-- Barres jour par jour -->
    <div class="stat-box" style="margin-bottom:14px;">
      <div class="stat-box-title">CA par jour — 7 derniers jours</div>
      <div id="chart-semaine" style="margin-top:10px;"></div>
    </div>

    <!-- Table jours -->
    <div class="stat-box">
      <div class="stat-box-title">Detail par jour</div>
      <table class="data-table" style="margin-top:8px;">
        <thead>
          <tr>
            <th>Jour</th>
            <th style="text-align:center;">Tickets</th>
            <th style="text-align:right;">CA</th>
            <th style="text-align:right;">Remises</th>
            <th style="text-align:right;">CA Net</th>
            <th style="text-align:center;">Tendance</th>
          </tr>
        </thead>
        <tbody id="table-semaine">
          <tr><td colspan="6" style="text-align:center;padding:20px;color:#9ca3af;">Chargement...</td></tr>
        </tbody>
      </table>
    </div>

  </div><!-- end s-semaine -->

  <!-- ── SUB-PANEL : MOIS ─────────────────────── -->
  <div id="s-mois" class="sub-panel">

    <!-- Selecteur mois -->
    <div style="display:flex;align-items:center;gap:10px;margin-bottom:14px;flex-wrap:wrap;">
      <div style="display:flex;align-items:center;gap:6px;">
        <label style="font-size:11px;font-weight:600;color:#6b7280;text-transform:uppercase;letter-spacing:.4px;">Mois :</label>
        <select id="sel-mois"
          style="padding:5px 8px;border:1px solid #e5e7eb;border-radius:5px;font-family:'DM Sans',sans-serif;font-size:11.5px;color:#111827;background:#fff;outline:none;cursor:pointer;">
          <?php
            for ($m = 1; $m <= 12; $m++) {
              $sel = ($m == date('n')) ? 'selected' : '';
              $mois_noms = ['','Janvier','Fevrier','Mars','Avril','Mai','Juin',
                            'Juillet','Aout','Septembre','Octobre','Novembre','Decembre'];
              echo "<option value='$m' $sel>{$mois_noms[$m]}</option>";
            }
          ?>
        </select>
        <select id="sel-annee"
          style="padding:5px 8px;border:1px solid #e5e7eb;border-radius:5px;font-family:'DM Sans',sans-serif;font-size:11.5px;color:#111827;background:#fff;outline:none;cursor:pointer;">
          <?php for ($y = date('Y'); $y >= date('Y')-3; $y--): ?>
            <option value="<?= $y ?>" <?= $y == date('Y') ? 'selected' : '' ?>><?= $y ?></option>
          <?php endfor; ?>
        </select>
        <button onclick="chargerStatsMois()"
          style="padding:5px 12px;background:#1d4ed8;color:white;border:none;border-radius:5px;font-size:11px;font-weight:600;cursor:pointer;font-family:'DM Sans',sans-serif;">
          <i class="fas fa-sync-alt"></i> OK
        </button>
      </div>

      <!-- Comparaison vs mois precedent -->
      <div id="badge-vs-prev-mois" style="margin-left:auto;"></div>
    </div>

    <!-- KPI mois -->
    <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-bottom:14px;">
      <div class="kpi-card kpi-blue">
        <div class="kpi-label"><i class="fas fa-coins"></i> CA du mois</div>
        <div class="kpi-value" id="kpi-m-ca">—</div>
      </div>
      <div class="kpi-card kpi-green">
        <div class="kpi-label"><i class="fas fa-receipt"></i> Tickets</div>
        <div class="kpi-value" id="kpi-m-tickets">—</div>
      </div>
      <div class="kpi-card kpi-amber">
        <div class="kpi-label"><i class="fas fa-shopping-basket"></i> Panier moyen</div>
        <div class="kpi-value" id="kpi-m-panier">—</div>
      </div>
      <div class="kpi-card kpi-purple">
        <div class="kpi-label"><i class="fas fa-tag"></i> Total remises</div>
        <div class="kpi-value" id="kpi-m-remise">—</div>
      </div>
    </div>

    <!-- Barres evolution semaine par semaine -->
    <div class="stat-box" style="margin-bottom:14px;">
      <div class="stat-box-title">Evolution CA — semaine par semaine</div>
      <div id="chart-mois-semaines" style="margin-top:10px;"></div>
    </div>

    <!-- Table hebdo -->
    <div class="stat-box">
      <div class="stat-box-title">Detail par semaine</div>
      <table class="data-table" style="margin-top:8px;">
        <thead>
          <tr>
            <th>Semaine</th>
            <th style="text-align:center;">Jours actifs</th>
            <th style="text-align:center;">Tickets</th>
            <th style="text-align:right;">CA</th>
            <th style="text-align:right;">Moyenne/jour</th>
          </tr>
        </thead>
        <tbody id="table-mois-semaines">
          <tr><td colspan="5" style="text-align:center;padding:20px;color:#9ca3af;">Chargement...</td></tr>
        </tbody>
      </table>
    </div>

  </div><!-- end s-mois -->

  <!-- ── SUB-PANEL : PRODUITS ─────────────────── -->
  <div id="s-produits" class="sub-panel">

    <!-- Filtres -->
    <div style="display:flex;gap:8px;align-items:center;margin-bottom:12px;flex-wrap:wrap;">
      <select id="prod-periode"
        style="padding:6px 8px;border:1px solid #e5e7eb;border-radius:5px;font-family:'DM Sans',sans-serif;font-size:11.5px;color:#111827;background:#fff;outline:none;cursor:pointer;">
        <option value="jour">Aujourd'hui</option>
        <option value="semaine" selected>Cette semaine</option>
        <option value="mois">Ce mois</option>
        <option value="trimestre">Ce trimestre</option>
        <option value="annee">Cette annee</option>
      </select>
      <select id="prod-tri"
        style="padding:6px 8px;border:1px solid #e5e7eb;border-radius:5px;font-family:'DM Sans',sans-serif;font-size:11.5px;color:#111827;background:#fff;outline:none;cursor:pointer;">
        <option value="ca">Trier par CA</option>
        <option value="qte">Trier par Quantite</option>
        <option value="tickets">Trier par Nb tickets</option>
      </select>
      <select id="prod-afficher"
        style="padding:6px 8px;border:1px solid #e5e7eb;border-radius:5px;font-family:'DM Sans',sans-serif;font-size:11.5px;color:#111827;background:#fff;outline:none;cursor:pointer;">
        <option value="top">Top vendeurs</option>
        <option value="flop">Flop vendeurs</option>
        <option value="tous">Tous les produits</option>
      </select>
      <div style="position:relative;flex:1;min-width:160px;">
        <i class="fas fa-search" style="position:absolute;left:8px;top:50%;transform:translateY(-50%);color:#9ca3af;font-size:10px;pointer-events:none;"></i>
        <input type="text" id="prod-search-stats" placeholder="Filtrer produit..."
          style="width:100%;padding:6px 8px 6px 24px;border:1px solid #e5e7eb;border-radius:5px;font-family:'DM Sans',sans-serif;font-size:11.5px;outline:none;color:#111827;">
      </div>
      <button onclick="chargerStatsProduits()"
        style="padding:6px 12px;background:#1d4ed8;color:white;border:none;border-radius:5px;font-size:11px;font-weight:600;cursor:pointer;font-family:'DM Sans',sans-serif;">
        <i class="fas fa-sync-alt"></i>
      </button>
    </div>

    <!-- KPI produits -->
    <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-bottom:14px;">
      <div class="kpi-card kpi-blue">
        <div class="kpi-label"><i class="fas fa-boxes"></i> Produits vendus</div>
        <div class="kpi-value" id="kpi-p-nb">—</div>
      </div>
      <div class="kpi-card kpi-green">
        <div class="kpi-label"><i class="fas fa-star"></i> Meilleure vente</div>
        <div class="kpi-value" id="kpi-p-top" style="font-size:11px;line-height:1.3;">—</div>
      </div>
      <div class="kpi-card kpi-red">
        <div class="kpi-label"><i class="fas fa-exclamation-triangle"></i> Non vendus (periode)</div>
        <div class="kpi-value" id="kpi-p-zero">—</div>
      </div>
    </div>

    <!-- Table produits -->
    <div class="stat-box">
      <div class="stat-box-title" id="table-prod-title">Analyse par produit</div>
      <div style="overflow-x:auto;max-height:420px;overflow-y:auto;">
        <table class="data-table" style="margin-top:8px;min-width:640px;">
          <thead>
            <tr>
              <th style="position:sticky;top:0;background:#f9fafb;">#</th>
              <th style="position:sticky;top:0;background:#f9fafb;">Produit</th>
              <th style="position:sticky;top:0;background:#f9fafb;text-align:center;">Qte vendue</th>
              <th style="position:sticky;top:0;background:#f9fafb;text-align:center;">Tickets</th>
              <th style="position:sticky;top:0;background:#f9fafb;text-align:right;">CA Total</th>
              <th style="position:sticky;top:0;background:#f9fafb;text-align:right;">PA Moyen</th>
              <th style="position:sticky;top:0;background:#f9fafb;text-align:right;">Marge est.</th>
              <th style="position:sticky;top:0;background:#f9fafb;text-align:center;">Part CA</th>
              <th style="position:sticky;top:0;background:#f9fafb;text-align:center;">Performance</th>
            </tr>
          </thead>
          <tbody id="table-produits-stats">
            <tr><td colspan="9" style="text-align:center;padding:20px;color:#9ca3af;">Chargement...</td></tr>
          </tbody>
        </table>
      </div>
    </div>

  </div><!-- end s-produits -->

  <!-- ── SUB-PANEL : FAMILLES ─────────────────── -->
  <div id="s-familles" class="sub-panel">

    <!-- Filtres -->
    <div style="display:flex;gap:8px;align-items:center;margin-bottom:12px;flex-wrap:wrap;">
      <select id="fam-periode"
        style="padding:6px 8px;border:1px solid #e5e7eb;border-radius:5px;font-family:'DM Sans',sans-serif;font-size:11.5px;color:#111827;background:#fff;outline:none;cursor:pointer;">
        <option value="jour">Aujourd'hui</option>
        <option value="semaine" selected>Cette semaine</option>
        <option value="mois">Ce mois</option>
        <option value="trimestre">Ce trimestre</option>
        <option value="annee">Cette annee</option>
      </select>
      <button onclick="chargerStatsFamilles()"
        style="padding:6px 12px;background:#1d4ed8;color:white;border:none;border-radius:5px;font-size:11px;font-weight:600;cursor:pointer;font-family:'DM Sans',sans-serif;">
        <i class="fas fa-sync-alt"></i> Actualiser
      </button>
    </div>

    <!-- Grille familles -->
    <div id="familles-grid" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:10px;margin-bottom:14px;"></div>

    <!-- Detail sous-familles -->
    <div class="stat-box">
      <div class="stat-box-title">
        Detail par sous-famille
        <span id="fam-selected-label" style="font-size:10px;color:#1d4ed8;font-weight:600;margin-left:8px;"></span>
      </div>
      <div id="sous-familles-content" style="padding:20px;text-align:center;color:#9ca3af;font-size:12px;">
        Cliquez sur une famille pour voir le detail
      </div>
    </div>

  </div><!-- end s-familles -->

</div><!-- end v-stats -->

    <!-- ---- PANEL ATTENTE ---- -->
    <div id="v-attente" class="v-panel">
      <div class="panel-section-title">Paniers en pause</div>
      <div id="list-attente" class="attente-grid"></div>
    </div>


<div id="marges" class="v-panel">

  <!-- Sous-navigation -->
  <div style="display:flex;gap:4px;border-bottom:1px solid #e5e7eb;margin-bottom:0;overflow-x:auto;flex-shrink:0;">
    <button class="sub-tab-btn active" id="mtab-jours"    onclick="showMargesSubPanel('jours')">
      <i class="fas fa-calendar-day"></i> Jour
    </button>
    <button class="sub-tab-btn" id="mtab-semaines"        onclick="showMargesSubPanel('semaines')">
      <i class="fas fa-calendar-week"></i> Semaines
    </button>
    <button class="sub-tab-btn" id="mtab-mois"            onclick="showMargesSubPanel('mois')">
      <i class="fas fa-calendar-alt"></i> Mois
    </button>
    <button class="sub-tab-btn" id="mtab-produits"        onclick="showMargesSubPanel('produits')">
      <i class="fas fa-pills"></i> Produits
    </button>
    <button class="sub-tab-btn" id="mtab-familles"        onclick="showMargesSubPanel('familles')">
      <i class="fas fa-layer-group"></i> Familles
    </button>
    <button class="sub-tab-btn" id="mtab-total"           onclick="showMargesSubPanel('total')">
      <i class="fas fa-sigma"></i> Total
    </button>
    <button class="sub-tab-btn" id="mtab-evolution"       onclick="showMargesSubPanel('evolution')">
      <i class="fas fa-chart-line"></i> Evolution
    </button>
  </div>

  <!-- ── Filtre date unique (Jour) ─────────────────────────── -->
  <div id="marge-date-filter"
    style="display:none;align-items:center;gap:10px;padding:10px 14px;background:#f9fafb;border-bottom:1px solid #e5e7eb;flex-wrap:wrap;">
    <label style="font-size:11px;font-weight:600;color:#6b7280;text-transform:uppercase;letter-spacing:.4px;">
      <i class="fas fa-calendar-day"></i> Date :
    </label>
    <input type="date" id="marge_date_picker"
      style="padding:5px 8px;border:1px solid #e5e7eb;border-radius:5px;font-family:'DM Sans',sans-serif;font-size:11.5px;color:#111827;outline:none;"
      value="<?php echo date('Y-m-d'); ?>">
    <button onclick="filterMargeByDate()"
      style="padding:5px 12px;background:#1d4ed8;color:#fff;border:none;border-radius:5px;font-size:11px;font-weight:600;cursor:pointer;font-family:'DM Sans',sans-serif;">
      <i class="fas fa-search"></i> Analyser
    </button>
  </div>

  <!-- ── Filtre plage de dates (Semaines / Mois / Evolution) ── -->
  <div id="marge-range-filter"
    style="display:none;align-items:center;gap:10px;padding:10px 14px;background:#f9fafb;border-bottom:1px solid #e5e7eb;flex-wrap:wrap;">
    <label style="font-size:11px;font-weight:600;color:#6b7280;text-transform:uppercase;letter-spacing:.4px;">
      <i class="fas fa-calendar-alt"></i> Periode :
    </label>
    <input type="date" id="marge_date_debut"
      style="padding:5px 8px;border:1px solid #e5e7eb;border-radius:5px;font-family:'DM Sans',sans-serif;font-size:11.5px;color:#111827;outline:none;"
      value="<?php echo date('Y-m-01'); ?>">
    <span style="font-size:11px;color:#9ca3af;">au</span>
    <input type="date" id="marge_date_fin"
      style="padding:5px 8px;border:1px solid #e5e7eb;border-radius:5px;font-family:'DM Sans',sans-serif;font-size:11.5px;color:#111827;outline:none;"
      value="<?php echo date('Y-m-d'); ?>">
    <button onclick="filterMargeByRange()"
      style="padding:5px 12px;background:#1d4ed8;color:#fff;border:none;border-radius:5px;font-size:11px;font-weight:600;cursor:pointer;font-family:'DM Sans',sans-serif;">
      <i class="fas fa-sync-alt"></i> Appliquer
    </button>
  </div>

  <!-- ── Contenu dynamique ─────────────────────────────────── -->
  <div id="marges-content" style="padding:14px;">
    <!-- Chargé dynamiquement par showMargesSubPanel() -->
  </div>

</div>

    <!-- ---- PANEL HISTORIQUE ---- -->
<!-- ---- PANEL HISTORIQUE ---- -->
<div id="v-historique" class="v-panel">

  <!-- Barre de filtres -->
  <div style="display:flex; align-items:center; gap:8px; margin-bottom:12px; flex-wrap:wrap;">
    <div style="position:relative; flex:1; min-width:160px;">
      <i class="fas fa-search" style="position:absolute;left:9px;top:50%;transform:translateY(-50%);color:#9ca3af;font-size:10px;pointer-events:none;"></i>
      <input type="text" id="histo-search" placeholder="Chercher client, mode..." 
        style="width:100%;padding:6px 8px 6px 26px;border:1px solid #e5e7eb;border-radius:5px;
               font-family:'DM Sans',sans-serif;font-size:11.5px;outline:none;color:#111827;background:#fff;">
    </div>
    <select id="histo-filter-mode" 
      style="padding:6px 8px;border:1px solid #e5e7eb;border-radius:5px;font-family:'DM Sans',sans-serif;
             font-size:11.5px;color:#374151;background:#fff;outline:none;cursor:pointer;">
      <option value="">Tous modes</option>
      <option value="ESPECES">Especes</option>
      <option value="MOBILE MONEY">Mobile Money</option>
      <option value="ASSURANCE">Assurance</option>
    </select>
    <button onclick="refreshHisto()" 
      style="padding:6px 10px;background:#1d4ed8;color:white;border:none;border-radius:5px;
             font-size:11px;font-weight:600;cursor:pointer;font-family:'DM Sans',sans-serif;
             display:flex;align-items:center;gap:5px;">
      <i class="fas fa-sync-alt"></i> Actualiser
    </button>
  </div>

  <!-- Compteurs rapides -->
  <div id="histo-summary" style="display:grid;grid-template-columns:repeat(3,1fr);gap:8px;margin-bottom:12px;"></div>

  <!-- Table -->
  <div style="overflow-x:auto;">
    <table class="data-table" style="min-width:560px;">
      <thead>
        <tr>
          <th style="width:52px;">Heure</th>
          <th>Client</th>
          <th>Articles</th>
          <th>Mode</th>
          <th style="text-align:right;">Total</th>
          <th style="text-align:center;">Actions</th>
        </tr>
      </thead>
      <tbody id="body-histo-jour">
        <tr><td colspan="6" style="text-align:center;color:#9ca3af;padding:24px;font-size:12px;">
          Chargement...
        </td></tr>
      </tbody>
    </table>
  </div>

</div>

    <!-- ---- PANEL CLOTURE ---- -->
    <div id="v-cloture" class="v-panel">
      <div class="cloture-box">
        <div class="cloture-icon"><i class="fas fa-lock-open"></i></div>
        <h4>Cloture de Session</h4>
        <p>En cloturant, vous generez le rapport financier et videz votre tiroir-caisse. Cette action est irreversible.</p>
        <button class="btn-danger-full" onclick="cloturerSession()">
          <i class="fas fa-lock"></i> Fermer la Caisse
        </button>
      </div>
    </div>

  </div><!-- end content-area -->

  <!-- ============================
       CART SECTION
  ============================= -->
  <div class="cart-section">

    <div class="cart-header">
      <div class="cart-header-left">
        <i class="fas fa-shopping-basket"></i>
        <span class="cart-header-title">Panier</span>
      </div>
      <span class="cart-badge" id="cart-count">0 art.</span>
    </div>

    <div class="cart-items" id="cart-list">
      <div class="empty-cart">
        <i class="fas fa-shopping-cart"></i>
        <p>Panier vide</p>
        <small>Selectionnez un produit</small>
      </div>
    </div>

    <div class="cart-footer">

      <!-- TOTAUX -->
      <div class="totals-box">
        <div class="total-line-item">
          <span>Sous-total</span>
          <span id="st-val" style="font-family:'DM Mono',monospace; font-weight:500;">0 F</span>
        </div>
        <div class="total-line-item remise-line" onclick="appliquerRemise()">
          <span><i class="fas fa-tag" style="margin-right:4px;"></i>Remise</span>
          <span id="remise-val" style="font-family:'DM Mono',monospace;">0 F</span>
        </div>
        <hr class="totals-divider">
        <div class="grand-total">
          <span class="grand-total-label">Total</span>
          <span class="grand-total-value" id="total-val">0 F</span>
        </div>
      </div>

      <!-- PAIEMENT -->
      <div class="payment-grid">
        <div class="pay-field">
          <span class="pay-label">Recu</span>
          <input type="number" id="cash-in" class="pay-input" onkeyup="calcChange()" placeholder="0">
        </div>
        <div class="pay-field">
          <span class="pay-label">Rendu</span>
          <div id="cash-change" class="change-display">0 F</div>
        </div>
      </div>

      <select id="mode_paiement" class="pay-select">
        <option value="Espèces">Especes</option>
        <option value="Mobile Money">Mobile Money</option>
        <option value="Assurance">Assurance</option>
      </select>

      <div class="client-select-wrap">
        <i class="fas fa-user"></i>
        <select id="id_client" class="client-select">
          <option value="1">CLIENT DIVERS</option>
          <?php
            $stmtC = $pdo->query("SELECT id_client, nom FROM clients WHERE id_client > 1 ORDER BY nom ASC");
            while($c = $stmtC->fetch()) {
              echo "<option value='{$c['id_client']}'>".htmlspecialchars($c['nom'])."</option>";
            }
          ?>
        </select>
      </div>

      <button class="btn-hold" onclick="mettreEnAttente()">
        <i class="fas fa-pause"></i> Attente <span style="color:#9ca3af; font-weight:400; font-size:10px;">(F4)</span>
      </button>

      <button class="btn-pay" onclick="processPayment()">
        <i class="fas fa-check-circle"></i> Encaisser
        <span style="font-weight:400; opacity:.75; font-size:10.5px;">(F9)</span>
      </button>

    </div>
  </div><!-- end cart-section -->

</div><!-- end main-sales -->

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
let cart = [];
let remiseGlobal = 0;
let attentes = JSON.parse(localStorage.getItem('paniers_attente')) || [];


function showPanel(id) {
  $('.tab-btn').removeClass('active');
  $(`.tab-btn[onclick="showPanel('${id}')"]`).addClass('active');
  $('.v-panel').removeClass('active');
  $('#' + id).addClass('active');
  if(id === 'v-attente') chargerPaniersAttente();
  if(id === 'v-historique') refreshHisto();
  if (id === 'v-stats') showSubStat('s-jour');
}



function addToCart(id, name, price, stock, priceDetail, coef) {
  coef = parseInt(coef) || 1;
  Swal.fire({
    title: 'Mode de vente',
    html: `
      <p style="font-size:13px; font-weight:600; margin-bottom:12px;">${name}</p>
      <div style="display:flex; gap:8px; justify-content:center; margin-bottom:14px;">
        <button type="button" id="btn-boite" style="padding:8px 14px; background:#1d4ed8; color:white; border:2px solid #1d4ed8; border-radius:5px; font-size:12px; cursor:pointer; font-weight:600;">
          Boite — ${price.toLocaleString()} F
        </button>
        <button type="button" id="btn-detail" style="padding:8px 14px; background:white; color:#0891b2; border:2px solid #0891b2; border-radius:5px; font-size:12px; cursor:pointer; font-weight:600;">
          Detail — ${priceDetail > 0 ? priceDetail.toLocaleString() + ' F' : 'N/A'}
        </button>
      </div>
      <label style="font-size:11px; font-weight:600; color:#6b7280; text-transform:uppercase; letter-spacing:.3px;">Prix applique (F)</label>
      <input type="number" id="custom-price" class="swal2-input" value="${price}" style="margin-top:6px; font-size:13px;">
    `,
    showCancelButton: true,
    confirmButtonText: 'Ajouter',
    cancelButtonText: 'Annuler',
    confirmButtonColor: '#16a34a',
    didOpen: () => {
      const input = document.getElementById('custom-price');
      const bBoite = document.getElementById('btn-boite');
      const bDetail = document.getElementById('btn-detail');
      window.tempMode = 'boite';
      bBoite.addEventListener('click', () => {
        window.tempMode = 'boite';
        input.value = price;
        bBoite.style.background = '#1d4ed8'; bBoite.style.color = 'white';
        bDetail.style.background = 'white'; bDetail.style.color = '#0891b2';
      });
      bDetail.addEventListener('click', () => {
        window.tempMode = 'detail';
        input.value = priceDetail;
        bDetail.style.background = '#0891b2'; bDetail.style.color = 'white';
        bBoite.style.background = 'white'; bBoite.style.color = '#1d4ed8';
      });
    },
    preConfirm: () => ({
      mode: window.tempMode,
      finalPrice: parseFloat(document.getElementById('custom-price').value)
    })
  }).then((result) => {
    if (result.isConfirmed) {
      const { mode, finalPrice } = result.value;
      const besoin = (mode === 'boite') ? coef : 1;
      if (stock < besoin) {
        return Swal.fire('Stock insuffisant', 'Il reste ' + stock + ' unites.', 'error');
      }
      let idx = cart.findIndex(i => i.id === id && i.mode === mode && i.price === finalPrice);
      if (idx > -1) {
        if (stock < (cart[idx].qty + 1) * besoin) {
          return Swal.fire('Stock limite', 'Pas assez de stock.', 'error');
        }
        cart[idx].qty++;
      } else {
        cart.push({
          id, price: finalPrice, qty: 1, mode, coef, stock_max: stock,
          name: name + (mode === 'boite' ? ' (Bt)' : ' (Dt)')
        });
      }
      updateUI();
    }
  });
}

function updateUI() {
  let html = '', subtotal = 0;
  cart.forEach((item, i) => {
    subtotal += item.price * item.qty;
    html += `
      <div class="cart-item">
        <div class="cart-item-top">
          <span class="cart-item-name">${item.name}</span>
          <span class="cart-item-mode">${item.mode}</span>
          <button class="btn-remove" onclick="removeItem(${i})"><i class="fas fa-times"></i></button>
        </div>
        <div class="cart-item-bottom">
          <div class="qty-ctrl">
            <button class="qty-btn" onclick="changeQty(${i}, -1)">-</button>
            <input type="number" class="qty-input" value="${item.qty}" onchange="updateQty(${i}, this.value)" min="1">
            <button class="qty-btn" onclick="changeQty(${i}, 1)">+</button>
          </div>
          <span class="cart-item-total">${(item.price * item.qty).toLocaleString()} F</span>
        </div>
      </div>`;
  });

  $('#cart-list').html(html || `
    <div class="empty-cart">
      <i class="fas fa-shopping-cart"></i>
      <p>Panier vide</p>
      <small>Selectionnez un produit</small>
    </div>`);

  let total = subtotal - remiseGlobal;
  $('#st-val').text(subtotal.toLocaleString() + ' F');
  $('#total-val').text(total.toLocaleString() + ' F');
  $('#cart-count').text(cart.length + ' art.');
  calcChange();
}

function changeQty(idx, delta) {
  let newQty = cart[idx].qty + delta;
  if (newQty < 1) return;
  if (newQty > cart[idx].stock_max) {
    return Swal.fire('Stock limite', 'Max: ' + cart[idx].stock_max, 'warning');
  }
  cart[idx].qty = newQty;
  updateUI();
}

function updateQty(idx, val) {
  let v = parseInt(val) || 1;
  if (v > cart[idx].stock_max) { v = cart[idx].stock_max; }
  cart[idx].qty = v;
  updateUI();
}

function removeItem(idx) { cart.splice(idx, 1); updateUI(); }

function appliquerRemise() {
  Swal.fire({ title: 'Montant de la remise (F)', input: 'number', showCancelButton: true, confirmButtonColor: '#1d4ed8' })
    .then((res) => {
      if (res.isConfirmed) {
        remiseGlobal = parseInt(res.value) || 0;
        $('#remise-val').text(remiseGlobal.toLocaleString() + ' F');
        updateUI();
      }
    });
}

function calcChange() {
  let total = parseInt($('#total-val').text().replace(/\s/g, '').replace('F', '')) || 0;
  let recu  = parseInt($('#cash-in').val()) || 0;
  let rendu = recu - total;
  let el = $('#cash-change');
  el.text((rendu > 0 ? rendu.toLocaleString() : 0) + ' F');
  el.removeClass('positive negative');
  el.addClass(rendu >= 0 ? 'positive' : 'negative');
}

function mettreEnAttente() {
  if (cart.length === 0) return;
  Swal.fire({ title: 'Note client', input: 'text', confirmButtonColor: '#d97706', inputPlaceholder: 'Nom ou note...' })
    .then((res) => {
      if (res.isConfirmed) {
        attentes.push({ id: Date.now(), nom: res.value || 'Client', contenu: [...cart], date: new Date().toLocaleTimeString() });
        localStorage.setItem('paniers_attente', JSON.stringify(attentes));
        cart = []; remiseGlobal = 0;
        $('#remise-val').text('0 F');
        updateUI(); updateAttenteCount();
      }
    });
}

function updateAttenteCount() { $('#count-attente').text(attentes.length); }

function chargerPaniersAttente() {
  let html = '';
  attentes.forEach((p, i) => {
    html += `<div class="attente-card">
      <h6>${p.nom}</h6>
      <small>${p.date} &mdash; ${p.contenu.length} article(s)</small>
      <button class="btn-reprendre" onclick="reprendrePanier(${i})">
        <i class="fas fa-play" style="margin-right:4px;"></i>Reprendre
      </button>
    </div>`;
  });
  $('#list-attente').html(html || '<p style="font-size:12px; color:#9ca3af;">Aucun panier en attente</p>');
}

function reprendrePanier(i) {
  if (cart.length > 0) return Swal.fire('Attention', 'Videz le panier actuel d\'abord', 'warning');
  cart = attentes[i].contenu;
  attentes.splice(i, 1);
  localStorage.setItem('paniers_attente', JSON.stringify(attentes));
  updateUI(); updateAttenteCount(); showPanel('v-comptoir');
}

function processPayment() {
  if (cart.length === 0) { Swal.fire('Panier vide', 'Ajoutez des produits', 'warning'); return; }
  let total    = parseInt($('#total-val').text().replace(/\s/g, '').replace('F', '')) || 0;
  let idClient = $('#id_client').val();
  $.post('ajax_produits.php', {
    action: 'save_vente',
    cart: JSON.stringify(cart),
    remise: remiseGlobal,
    total: total,
    id_client: idClient,
    mode_paiement: $('#mode_paiement').val() || 'Especes'
  }, function(res) {
    if (res.status === 'success') {
      Swal.fire({
        title: 'Vente enregistree',
        text: 'Facture #' + res.id_vente,
        icon: 'success',
        showCancelButton: true,
        confirmButtonText: 'Imprimer le ticket',
        cancelButtonText: 'Nouvelle vente',
        confirmButtonColor: '#1d4ed8'
      }).then((r) => {
        if (r.isConfirmed) imprimerTicket(res.id_vente);
        setTimeout(() => location.reload(), 400);
      });
    } else {
      Swal.fire('Erreur', res.message, 'error');
    }
  }, 'json');
}

function imprimerTicket(idVente) {
  const w = 400, h = 600;
  window.open('imprimer_ticket.php?id=' + idVente, 'Ticket',
    `width=${w},height=${h},top=${(screen.height-h)/2},left=${(screen.width-w)/2},toolbar=no,menubar=no`);
}

let histoData = [];

function refreshHisto() {
  $('#body-histo-jour').html(
    '<tr><td colspan="6" style="text-align:center;padding:20px;color:#9ca3af;font-size:12px;">' +
    '<i class="fas fa-spinner fa-spin" style="margin-right:6px;"></i>Chargement...</td></tr>'
  );

  $.get('ajax_historique.php', function(res) {
    if (!res || res.status === 'error') {
      $('#body-histo-jour').html(
        '<tr><td colspan="6" style="color:#dc2626;padding:16px;font-size:12px;">Erreur de chargement</td></tr>'
      );
      return;
    }

    histoData = res.ventes || [];
    renderHisto(histoData);
    renderHistoSummary(res.totaux || {});
  }, 'json');
}


function renderHistoSummary(totaux) {
  const html = `
    <div style="background:#f0fdf4;border:1px solid #dcfce7;border-radius:5px;padding:9px 12px;">
      <div style="font-size:9.5px;color:#16a34a;font-weight:700;text-transform:uppercase;letter-spacing:.4px;margin-bottom:3px;">Especes</div>
      <div style="font-size:14px;font-weight:700;color:#111827;font-family:'DM Mono',monospace;">${(totaux.especes||0).toLocaleString()} F</div>
    </div>
    <div style="background:#eff6ff;border:1px solid #dbeafe;border-radius:5px;padding:9px 12px;">
      <div style="font-size:9.5px;color:#1d4ed8;font-weight:700;text-transform:uppercase;letter-spacing:.4px;margin-bottom:3px;">Mobile Money</div>
      <div style="font-size:14px;font-weight:700;color:#111827;font-family:'DM Mono',monospace;">${(totaux.mobile||0).toLocaleString()} F</div>
    </div>
    <div style="background:#fdf4ff;border:1px solid #f3e8ff;border-radius:5px;padding:9px 12px;">
      <div style="font-size:9.5px;color:#7c3aed;font-weight:700;text-transform:uppercase;letter-spacing:.4px;margin-bottom:3px;">Assurance</div>
      <div style="font-size:14px;font-weight:700;color:#111827;font-family:'DM Mono',monospace;">${(totaux.assurance||0).toLocaleString()} F</div>
    </div>`;
  $('#histo-summary').html(html);
}

function renderHisto(data) {
  if (!data.length) {
    $('#body-histo-jour').html(
      '<tr><td colspan="6" style="text-align:center;padding:28px;color:#9ca3af;font-size:12px;">' +
      '<i class="fas fa-receipt" style="display:block;font-size:22px;margin-bottom:8px;"></i>Aucune vente enregistree</td></tr>'
    );
    return;
  }

  const modeBadge = {
    'ESPECES':      { bg:'#f0fdf4', color:'#16a34a', border:'#dcfce7' },
    'MOBILE MONEY': { bg:'#eff6ff', color:'#1d4ed8', border:'#dbeafe' },
    'ASSURANCE':    { bg:'#fdf4ff', color:'#7c3aed', border:'#f3e8ff' }
  };

  let html = '';
  data.forEach(v => {
    const mode  = (v.mode_paiement || 'ESPECES').toUpperCase();
    const style = modeBadge[mode] || { bg:'#f9fafb', color:'#374151', border:'#e5e7eb' };
    const nom   = v.id_client == 1 ? 'Client divers' : ((v.client_nom || '') + ' ' + (v.client_prenom || '')).trim();
    const remise = parseFloat(v.remise || 0);
    const total  = parseFloat(v.total || 0);

    // Ligne assurance
    let assuranceInfo = '';
    if (v.id_assurance && parseFloat(v.part_assurance) > 0) {
      assuranceInfo = `<span style="display:inline-block;margin-top:2px;font-size:9px;color:#7c3aed;">
        Ass. ${parseFloat(v.part_assurance).toLocaleString()} F &bull; Patient ${parseFloat(v.part_patient).toLocaleString()} F
      </span>`;
    }

    // Remise info
    let remiseInfo = '';
    if (remise > 0) {
      remiseInfo = `<span style="display:inline-block;font-size:9px;color:#d97706;margin-top:1px;">
        Remise: -${remise.toLocaleString()} F
      </span>`;
    }

    html += `
      <tr data-mode="${mode}" data-client="${nom.toLowerCase()}" data-id="${v.id_vente}">
        <td style="font-family:'DM Mono',monospace;font-size:12px;color:#374151;white-space:nowrap;">
          ${v.heure}
          <div style="font-size:9.5px;color:#9ca3af;">#${v.id_vente}</div>
        </td>
        <td>
          <div style="font-size:11.5px;font-weight:600;color:#111827;">${nom}</div>
          ${v.telephone ? `<div style="font-size:10px;color:#9ca3af;"><i class="fas fa-phone" style="font-size:9px;margin-right:3px;"></i>${v.telephone}</div>` : ''}
        </td>
        <td>
          <div style="display:flex;flex-wrap:wrap;gap:3px;max-width:140px;">
            ${renderArticlesBadges(v.articles)}
          </div>
        </td>
        <td>
          <span style="display:inline-block;padding:2px 8px;border-radius:4px;font-size:9.5px;font-weight:700;
                       background:${style.bg};color:${style.color};border:1px solid ${style.border};
                       text-transform:uppercase;letter-spacing:.3px;">
            ${mode}
          </span>
          <div>${assuranceInfo}</div>
        </td>
        <td style="text-align:right;">
          <div style="font-size:13px;font-weight:700;font-family:'DM Mono',monospace;color:#111827;">
            ${total.toLocaleString()} F
          </div>
          <div>${remiseInfo}</div>
        </td>
        <td style="text-align:center;">
          <div style="display:flex;gap:4px;justify-content:center;align-items:center;">
            <button onclick="voirDetailVente(${v.id_vente})"
              title="Voir le detail"
              style="padding:4px 7px;border:1px solid #e5e7eb;background:#fff;border-radius:4px;
                     cursor:pointer;color:#374151;font-size:10px;transition:all .12s;"
              onmouseover="this.style.borderColor='#1d4ed8';this.style.color='#1d4ed8';this.style.background='#eff6ff';"
              onmouseout="this.style.borderColor='#e5e7eb';this.style.color='#374151';this.style.background='#fff';">
              <i class="fas fa-eye"></i>
            </button>
            <button onclick="imprimerTicket(${v.id_vente})"
              title="Reimprimer le ticket"
              style="padding:4px 7px;border:1px solid #e5e7eb;background:#fff;border-radius:4px;
                     cursor:pointer;color:#374151;font-size:10px;transition:all .12s;"
              onmouseover="this.style.borderColor='#16a34a';this.style.color='#16a34a';this.style.background='#f0fdf4';"
              onmouseout="this.style.borderColor='#e5e7eb';this.style.color='#374151';this.style.background='#fff';">
              <i class="fas fa-print"></i>
            </button>
          </div>
        </td>
      </tr>`;
  });

  $('#body-histo-jour').html(html);
}

function renderArticlesBadges(articles) {
  if (!articles || !articles.length) return '<span style="color:#9ca3af;font-size:10px;">—</span>';
  // Afficher max 2 articles puis un compteur
  let html = '';
  const max = 2;
  articles.slice(0, max).forEach(a => {
    html += `<span style="background:#f3f4f6;color:#374151;font-size:9.5px;padding:1px 5px;
                          border-radius:3px;white-space:nowrap;max-width:90px;overflow:hidden;
                          text-overflow:ellipsis;display:inline-block;">
               ${a.nom} x${a.quantite}
             </span>`;
  });
  if (articles.length > max) {
    html += `<span style="background:#e0e7ff;color:#1d4ed8;font-size:9.5px;padding:1px 5px;border-radius:3px;font-weight:600;">
               +${articles.length - max}
             </span>`;
  }
  return html;
}

// ----- FILTRES HISTORIQUE -----
$(document).on('keyup', '#histo-search', function() {
  filtrerHisto();
});
$(document).on('change', '#histo-filter-mode', function() {
  filtrerHisto();
});

function filtrerHisto() {
  const search = $('#histo-search').val().toLowerCase().trim();
  const mode   = $('#histo-filter-mode').val().toUpperCase();

  let filtered = histoData.filter(v => {
    const nom = v.id_client == 1
      ? 'client divers'
      : ((v.client_nom || '') + ' ' + (v.client_prenom || '')).trim().toLowerCase();
    const modeV = (v.mode_paiement || '').toUpperCase();

    const matchSearch = !search || nom.includes(search) || String(v.id_vente).includes(search);
    const matchMode   = !mode   || modeV === mode;
    return matchSearch && matchMode;
  });

  renderHisto(filtered);
}

// ----- DETAIL VENTE (modale) -----
function voirDetailVente(idVente) {
  Swal.fire({
    title:'DETAIL DE LA VENTE #' + idVente,
    html: '<div id="swal-detail-content" style="text-align:left;min-height:80px;">' +
          '<div style="text-align:center;padding:20px;color:#9ca3af;">' +
          '<i class="fas fa-spinner fa-spin"></i> Chargement...</div></div>',
    showCancelButton: true,
    cancelButtonText: 'Fermer',
    showConfirmButton: true,
    confirmButtonText: '<i class="fas fa-print"></i> Reimprimer',
    confirmButtonColor: '#1d4ed8',
    width: 480,
    didOpen: () => {
      $.get('ajax_detail_vente.php', { id: idVente }, function(res) {
        if (res.status !== 'success') {
          $('#swal-detail-content').html('<p style="color:#dc2626;">Erreur : ' + res.message + '</p>');
          return;
        }
        const v = res.vente;
        const nom = v.id_client == 1
          ? 'Client divers'
          : ((v.client_nom || '') + ' ' + (v.client_prenom || '')).trim();

        let lignesHtml = '';
        (res.articles || []).forEach(a => {
          lignesHtml += `
            <tr>
              <td style="padding:5px 8px;font-size:11.5px;border-bottom:1px solid #f3f4f6;">
                ${a.nom_commercial}
                <span style="font-size:9.5px;color:#9ca3af;display:block;">${a.type_unite}</span>
              </td>
              <td style="padding:5px 8px;font-size:11.5px;text-align:center;border-bottom:1px solid #f3f4f6;font-family:'DM Mono',monospace;">${a.quantite}</td>
              <td style="padding:5px 8px;font-size:11.5px;text-align:right;border-bottom:1px solid #f3f4f6;font-family:'DM Mono',monospace;">${parseFloat(a.prix_unitaire).toLocaleString()} F</td>
              <td style="padding:5px 8px;font-size:12px;font-weight:700;text-align:right;border-bottom:1px solid #f3f4f6;font-family:'DM Mono',monospace;">${(a.prix_unitaire * a.quantite).toLocaleString()} F</td>
            </tr>`;
        });

        const modeStyle = {
          'especes':      'background:#f0fdf4;color:#16a34a;border:1px solid #dcfce7;',
          'mobile money': 'background:#eff6ff;color:#1d4ed8;border:1px solid #dbeafe;',
          'assurance':    'background:#fdf4ff;color:#7c3aed;border:1px solid #f3e8ff;'
        };
        const modeKey = (v.mode_paiement || 'especes').toLowerCase();
        const mStyle  = modeStyle[modeKey] || 'background:#f9fafb;color:#374151;border:1px solid #e5e7eb;';

        const html = `
          <div style="font-family:'DM Sans',sans-serif;">

            <!-- En-tete -->
            <div style="background:#f9fafb;border:1px solid #e5e7eb;border-radius:5px;padding:10px 12px;margin-bottom:12px;display:flex;justify-content:space-between;align-items:center;">
              <div>
                <div style="font-size:10px;color:#9ca3af;text-transform:uppercase;letter-spacing:.4px;margin-bottom:2px;">Client</div>
                <div style="font-size:13px;font-weight:700;color:#111827;">${nom}</div>
                ${v.telephone ? `<div style="font-size:10px;color:#6b7280;">${v.telephone}</div>` : ''}
              </div>
              <div style="text-align:right;">
                <div style="font-size:10px;color:#9ca3af;text-transform:uppercase;letter-spacing:.4px;margin-bottom:2px;">Date</div>
                <div style="font-size:12px;font-weight:600;color:#374151;">${v.date_affich}</div>
                <span style="display:inline-block;padding:2px 8px;border-radius:4px;font-size:10px;font-weight:700;${mStyle}text-transform:uppercase;margin-top:3px;">
                  ${v.mode_paiement}
                </span>
              </div>
            </div>

            <!-- Articles -->
            <table style="width:100%;border-collapse:collapse;background:#fff;border:1px solid #e5e7eb;border-radius:5px;overflow:hidden;margin-bottom:10px;">
              <thead>
                <tr style="background:#f3f4f6;">
                  <th style="padding:6px 8px;font-size:10px;font-weight:700;color:#6b7280;text-transform:uppercase;text-align:left;">Produit</th>
                  <th style="padding:6px 8px;font-size:10px;font-weight:700;color:#6b7280;text-transform:uppercase;text-align:center;">Qte</th>
                  <th style="padding:6px 8px;font-size:10px;font-weight:700;color:#6b7280;text-transform:uppercase;text-align:right;">P.U.</th>
                  <th style="padding:6px 8px;font-size:10px;font-weight:700;color:#6b7280;text-transform:uppercase;text-align:right;">Total</th>
                </tr>
              </thead>
              <tbody>${lignesHtml}</tbody>
            </table>

            <!-- Totaux -->
            <div style="background:#f9fafb;border:1px solid #e5e7eb;border-radius:5px;padding:10px 12px;">
              ${parseFloat(v.remise||0) > 0 ? `
              <div style="display:flex;justify-content:space-between;margin-bottom:5px;font-size:11.5px;color:#d97706;">
                <span>Remise</span>
                <span style="font-family:'DM Mono',monospace;">-${parseFloat(v.remise).toLocaleString()} F</span>
              </div>` : ''}
              ${parseFloat(v.part_assurance||0) > 0 ? `
              <div style="display:flex;justify-content:space-between;margin-bottom:5px;font-size:11.5px;color:#7c3aed;">
                <span>Part assurance</span>
                <span style="font-family:'DM Mono',monospace;">${parseFloat(v.part_assurance).toLocaleString()} F</span>
              </div>
              <div style="display:flex;justify-content:space-between;margin-bottom:5px;font-size:11.5px;color:#374151;">
                <span>Part patient</span>
                <span style="font-family:'DM Mono',monospace;">${parseFloat(v.part_patient).toLocaleString()} F</span>
              </div>` : ''}
              <div style="border-top:1px solid #e5e7eb;margin:7px 0;"></div>
              <div style="display:flex;justify-content:space-between;align-items:center;">
                <span style="font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;">TOTAL</span>
                <span style="font-size:18px;font-weight:700;font-family:'DM Mono',monospace;color:#111827;">${parseFloat(v.total).toLocaleString()} F</span>
              </div>
            </div>

          </div>`;

        $('#swal-detail-content').html(html);
      }, 'json');
    }
  }).then(r => {
    if (r.isConfirmed) imprimerTicket(idVente);
  });
}

function cloturerSession() {
  Swal.fire({
    title: 'Cloture de caisse',
    text: 'Voulez-vous generer le rapport financier et fermer la session ?',
    icon: 'warning',
    showCancelButton: true,
    confirmButtonColor: '#dc2626',
    confirmButtonText: 'Oui, Cloturer',
    cancelButtonText: 'Annuler'
  }).then((r) => {
    if (r.isConfirmed) {
      Swal.showLoading();
      $.post('ajax_cloture.php', { action: 'cloturer_journee' }, function(res) {
        if (res.status === 'success') {
          Swal.fire({
            title: 'Session Cloturee',
            html: `<div style="text-align:left; font-family:'DM Mono',monospace; font-size:12px; background:#f9fafb; padding:14px; border-radius:6px; border:1px solid #e5e7eb;">
              <div style="display:flex; justify-content:space-between; margin-bottom:6px;">
                <span style="color:#6b7280;">Especes</span><b>${res.data.especes} F</b>
              </div>
              <div style="display:flex; justify-content:space-between; margin-bottom:6px;">
                <span style="color:#6b7280;">Mobile</span><b>${res.data.mobile} F</b>
              </div>
              <div style="display:flex; justify-content:space-between; margin-bottom:6px;">
                <span style="color:#6b7280;">Assurance</span><b>${res.data.assurance} F</b>
              </div>
              <hr style="border:none; border-top:1px solid #e5e7eb; margin:8px 0;">
              <div style="display:flex; justify-content:space-between; font-size:14px; color:#16a34a;">
                <b>TOTAL</b><b>${res.data.total} F</b>
              </div>
            </div>`,
            icon: 'success',
            showCancelButton: true,
            confirmButtonText: 'Imprimer rapport',
            cancelButtonText: 'Fermer',
            confirmButtonColor: '#1d4ed8'
          }).then((pr) => {
            if (pr.isConfirmed) window.open('imprimer_cloture.php', '_blank', 'width=400,height=600');
            location.reload();
          });
        } else {
          Swal.fire('Erreur', res.message, 'error');
        }
      }, 'json');
    }
  });
}

/* Search */
$('#search-prod').on('keyup', function() {
  let v = $(this).val().toLowerCase();
  $('.product-card').each(function() {
    $(this).toggle($(this).text().toLowerCase().includes(v));
  });
});

/* Keyboard shortcuts */
$(document).on('keydown', function(e) {
  if (e.key === 'F4') { e.preventDefault(); mettreEnAttente(); }
  if (e.key === 'F9') { e.preventDefault(); processPayment(); }
});

function voirEquivalents(idProduit, molecule, description, nomOrigine) {
  Swal.fire({
    html: `
      <div style="text-align:left; margin-bottom:12px;">
        <div style="font-size:10px; color:#9ca3af; text-transform:uppercase; letter-spacing:.4px; margin-bottom:3px;">Produit en rupture</div>
        <div style="font-size:13px; font-weight:700; color:#dc2626;">${nomOrigine}</div>
        <div style="font-size:10px; color:#6b7280; margin-top:2px;">
          <i class="fas fa-atom" style="margin-right:3px;"></i>${molecule || 'Molecule non renseignee'}
        </div>
      </div>
      <div id="equiv-content" style="text-align:left; min-height:80px;">
        <div style="text-align:center; padding:24px; color:#9ca3af;">
          <i class="fas fa-spinner fa-spin" style="margin-right:6px;"></i>Recherche en cours...
        </div>
      </div>`,
    showConfirmButton: false,
    showCancelButton: true,
    cancelButtonText: 'Fermer',
    width: 520,
    didOpen: () => {
      $.get('ajax_equivalents.php', {
        id:          idProduit,
        molecule:    molecule,
        description: description
      }, function(res) {
        if (res.status !== 'success' || !res.equivalents.length) {
          $('#equiv-content').html(`
            <div style="text-align:center; padding:24px; color:#9ca3af;">
              <i class="fas fa-search-minus" style="font-size:22px; display:block; margin-bottom:8px;"></i>
              <div style="font-size:12px; font-weight:600; color:#374151; margin-bottom:4px;">Aucun equivalent trouve</div>
              <div style="font-size:11px;">Aucun produit similaire n'est disponible en stock.</div>
            </div>`);
          return;
        }

        let html = `
          <div style="font-size:10px; color:#16a34a; font-weight:700; text-transform:uppercase;
                      letter-spacing:.4px; margin-bottom:10px; display:flex; align-items:center; gap:5px;">
            <i class="fas fa-check-circle"></i>
            ${res.equivalents.length} equivalent(s) disponible(s)
          </div>`;

        res.equivalents.forEach(eq => {
          const detailBtn = eq.prix_unitaire_detail > 0
            ? `<button class="btn-add-equiv" style="background:#0891b2;"
                onclick="Swal.close(); addToCart(${eq.id_produit},'${escJs(eq.nom_commercial)}',${eq.prix_unitaire},${eq.stock_dispo},${eq.prix_unitaire_detail},${eq.coefficient_division||1})">
                + Detail
               </button>`
            : '';

          html += `
            <div class="equiv-card">
              <div class="equiv-card-left">
                <div class="equiv-card-name">${escHtml(eq.nom_commercial)}</div>
                <div class="equiv-card-mol">${escHtml(eq.molecule || '')}</div>
                <div class="equiv-stock-pill">${eq.stock_dispo} en stock</div>
              </div>
              <div class="equiv-card-right">
                <div class="equiv-card-price">${parseFloat(eq.prix_unitaire).toLocaleString()} F</div>
                <div style="display:flex; gap:4px; margin-top:6px; justify-content:flex-end;">
                  <button class="btn-add-equiv"
                    onclick="Swal.close(); addToCart(${eq.id_produit},'${escJs(eq.nom_commercial)}',${eq.prix_unitaire},${eq.stock_dispo},${eq.prix_unitaire_detail||0},${eq.coefficient_division||1})">
                    <i class="fas fa-plus" style="margin-right:3px;"></i>Ajouter
                  </button>
                  ${detailBtn}
                </div>
              </div>
            </div>`;
        });

        $('#equiv-content').html(html);
      }, 'json');
    }
  });
}

/* Helpers XSS */
function escHtml(str) {
  return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
function escJs(str) {
  return String(str).replace(/'/g,"\\'").replace(/\\/g,'\\\\');
}

function fmtXAF(n) {
  n = parseFloat(n) || 0;
  return new Intl.NumberFormat('fr-FR', { minimumFractionDigits: 0 }).format(Math.round(n)) + ' F';
}
function fmtPct(n) {
  return (parseFloat(n) || 0).toFixed(1) + '%';
}

const STAT_COLORS = ['#1d4ed8','#16a34a','#d97706','#7c3aed','#0891b2',
                     '#dc2626','#059669','#f59e0b','#8b5cf6','#06b6d4'];
const MARGE_COLORS = ['#1d4ed8','#16a34a','#d97706','#7c3aed',
                      '#0891b2','#dc2626','#059669','#f59e0b','#8b5cf6','#06b6d4'];

function tendanceMarge(val, prev) {
  if (!prev || prev == 0) return '<span style="color:#9ca3af;font-size:10px;">—</span>';
  const d = ((val - prev) / Math.abs(prev) * 100).toFixed(1);
  if (d > 0) return `<span style="color:#16a34a;font-size:10px;font-weight:700;"><i class="fas fa-arrow-up"></i> +${d}%</span>`;
  if (d < 0) return `<span style="color:#dc2626;font-size:10px;font-weight:700;"><i class="fas fa-arrow-down"></i> ${d}%</span>`;
  return `<span style="color:#6b7280;font-size:10px;">=</span>`;
}
function miniBarH(val, max, color) {
  const pct = max > 0 ? Math.min((val / max) * 100, 100).toFixed(1) : 0;
  return `<div style="width:80px;background:#f3f4f6;border-radius:99px;height:6px;overflow:hidden;display:inline-block;vertical-align:middle;">
            <div style="width:${pct}%;background:${color};height:6px;"></div>
          </div>`;
}

function margeBadge(taux) {
  const t = parseFloat(taux);
  if (t >= 30) return `<span style="background:#dcfce7;color:#15803d;padding:2px 7px;border-radius:99px;font-size:9.5px;font-weight:800;">${fmtPct(t)}</span>`;
  if (t >= 15) return `<span style="background:#fef9c3;color:#854d0e;padding:2px 7px;border-radius:99px;font-size:9.5px;font-weight:800;">${fmtPct(t)}</span>`;
  if (t >= 0)  return `<span style="background:#fff7ed;color:#c2410c;padding:2px 7px;border-radius:99px;font-size:9.5px;font-weight:800;">${fmtPct(t)}</span>`;
  return `<span style="background:#fee2e2;color:#dc2626;padding:2px 7px;border-radius:99px;font-size:9.5px;font-weight:800;">${fmtPct(t)}</span>`;
}

function showSubStat(id) {
  document.querySelectorAll('.sub-tab-btn').forEach(b => b.classList.remove('active'));
  const activeBtn = document.getElementById('stab-' + id);
  if (activeBtn) activeBtn.classList.add('active');
  document.querySelectorAll('.sub-panel').forEach(p => p.classList.remove('active'));
  document.getElementById(id).classList.add('active');

  // Charger les données du sous-panel
  const loaders = {
    's-jour'     : chargerStatsJour,
    's-semaine'  : chargerStatsSemaine,
    's-mois'     : chargerStatsMois,
    's-produits' : chargerStatsProduits,
    's-familles' : chargerStatsFamilles,
  };
  if (loaders[id]) loaders[id]();
}

// ── Initialisation quand on ouvre le panel stats ──

function fmtF(n) {
  return Number(n || 0).toLocaleString('fr-FR') + ' F';
}

function renderBars(containerId, items, colorFn) {
  const max = Math.max(...items.map(i => i.val), 1);
  let html = '';
  items.forEach((item, idx) => {
    const pct = ((item.val / max) * 100).toFixed(1);
    const color = typeof colorFn === 'function' ? colorFn(idx) : colorFn;
    html += `
      <div class="bar-row">
        <span class="bar-label" title="${item.label}">${item.label}</span>
        <div class="bar-track">
          <div class="bar-fill" style="width:${pct}%;background:${color};"></div>
        </div>
        <span class="bar-val">${fmtF(item.val)}</span>
      </div>`;
  });
  document.getElementById(containerId).innerHTML = html || '<p style="font-size:11px;color:#9ca3af;text-align:center;padding:10px;">Aucune donnee</p>';
}

function perfBadge(rang, total) {
  const pct = rang / total;
  if (pct <= 0.1)  return '<span class="perf-badge perf-star"><i class="fas fa-star"></i> TOP</span>';
  if (pct <= 0.3)  return '<span class="perf-badge perf-good"><i class="fas fa-thumbs-up"></i> BON</span>';
  if (pct <= 0.6)  return '<span class="perf-badge perf-avg"><i class="fas fa-minus"></i> MOYEN</span>';
  if (pct <= 0.85) return '<span class="perf-badge perf-low"><i class="fas fa-arrow-down"></i> FAIBLE</span>';
  return '<span class="perf-badge perf-dead"><i class="fas fa-times"></i> INACTIF</span>';
}

function tendanceBadge(current, prev) {
  if (!prev || prev == 0) return '<span style="color:#9ca3af;font-size:10px;">—</span>';
  const delta = ((current - prev) / prev * 100).toFixed(1);
  if (delta > 5)   return `<span style="color:#16a34a;font-size:10px;font-weight:700;"><i class="fas fa-arrow-up"></i> +${delta}%</span>`;
  if (delta < -5)  return `<span style="color:#dc2626;font-size:10px;font-weight:700;"><i class="fas fa-arrow-down"></i> ${delta}%</span>`;
  return `<span style="color:#6b7280;font-size:10px;font-weight:600;">= ${delta}%</span>`;
}

// ─────────────────────────────────────────────────
//  1. CA JOUR
// ─────────────────────────────────────────────────
function chargerStatsJour() {
  $('#kpi-j-ca,#kpi-j-tickets,#kpi-j-panier,#kpi-j-remise').text('...');
  $('#top-produits-jour').html('<tr><td colspan="5" style="text-align:center;padding:16px;color:#9ca3af;"><i class="fas fa-spinner fa-spin"></i></td></tr>');
  $('#modes-jour-bars,#heures-jour-bars').html('<div style="text-align:center;padding:14px;color:#9ca3af;font-size:11px;"><i class="fas fa-spinner fa-spin"></i></div>');

  $.get('ajax_stats_ventes.php', { action: 'stats_jour' }, function(res) {
    console.log(res)
    if (!res.success) return;
    const d = res.data;

    // KPI
    $('#kpi-j-ca').text(fmtF(d.ca_total));
    $('#kpi-j-tickets').text(d.nb_tickets);
    $('#kpi-j-panier').text(fmtF(d.panier_moyen));
    $('#kpi-j-remise').text(fmtF(d.total_remises));
    $('#date-label-jour').text(new Date().toLocaleDateString('fr-FR', {weekday:'long',day:'numeric',month:'long'}));

    // Modes de paiement
    const modesItems = [
      { label: 'Especes',      val: d.modes.especes      || 0 },
      { label: 'Mobile Money', val: d.modes.mobile_money  || 0 },
      { label: 'Assurance',    val: d.modes.assurance     || 0 },
    ].filter(m => m.val > 0);
    renderBars('modes-jour-bars', modesItems, (i) => ['#16a34a','#1d4ed8','#7c3aed'][i]);

    // Tranches horaires
    const heuresItems = (d.heures || []).map(h => ({
      label: h.heure + 'h',
      val: h.ca
    }));
    renderBars('heures-jour-bars', heuresItems, () => '#0891b2');

    // Top produits jour
    const total_ca = d.ca_total || 1;
    let html = '';
    (d.top_produits || []).forEach((p, i) => {
      const part = ((p.ca / total_ca) * 100).toFixed(1);
      const w    = part;
      html += `
        <tr>
          <td style="padding:7px 10px;font-size:11px;color:#9ca3af;font-family:'DM Mono',monospace;">${i+1}</td>
          <td style="padding:7px 10px;">
            <div style="font-weight:600;font-size:11.5px;color:#111827;">${escHtml(p.nom)}</div>
            <div style="font-size:9.5px;color:#9ca3af;">${escHtml(p.famille || '')}</div>
          </td>
          <td style="padding:7px 10px;text-align:center;font-family:'DM Mono',monospace;font-weight:600;">${p.qte}</td>
          <td style="padding:7px 10px;text-align:right;font-family:'DM Mono',monospace;font-weight:700;color:#111827;">${fmtF(p.ca)}</td>
          <td style="padding:7px 10px;text-align:right;">
            <div style="display:flex;align-items:center;gap:6px;justify-content:flex-end;">
              <div style="width:60px;background:#f3f4f6;border-radius:99px;height:5px;overflow:hidden;">
                <div style="width:${w}%;background:${STAT_COLORS[i % STAT_COLORS.length]};height:5px;"></div>
              </div>
              <span style="font-size:10px;font-weight:700;color:${STAT_COLORS[i % STAT_COLORS.length]};width:34px;text-align:right;">${part}%</span>
            </div>
          </td>
        </tr>`;
    });
    $('#top-produits-jour').html(html || '<tr><td colspan="5" style="text-align:center;color:#9ca3af;padding:16px;">Aucune vente enregistree</td></tr>');

  }, 'json');
}

// ─────────────────────────────────────────────────
//  2. CA SEMAINE
// ─────────────────────────────────────────────────
function chargerStatsSemaine() {
  $.get('ajax_stats_ventes.php', { action: 'stats_semaine' }, function(res) {
    if (!res.success) return;
    const d = res.data;

    // KPI
    $('#kpi-s-ca').text(fmtF(d.ca_total));
    $('#kpi-s-tickets').text(d.nb_tickets);
    $('#kpi-s-best-day').text(d.best_day_label || '—');
    $('#kpi-s-worst-day').text(d.worst_day_label || '—');

    // Delta vs semaine precedente
    const el = document.getElementById('kpi-s-vs-prev');
    if (d.delta_vs_prev !== undefined && d.prev_ca > 0) {
      const delta = d.delta_vs_prev.toFixed(1);
      const cls   = delta > 0 ? 'up' : (delta < 0 ? 'down' : 'flat');
      const arrow = delta > 0 ? 'fa-arrow-up' : (delta < 0 ? 'fa-arrow-down' : 'fa-minus');
      el.className = 'kpi-delta ' + cls;
      el.innerHTML = `<i class="fas ${arrow}"></i> ${delta > 0 ? '+' : ''}${delta}% vs sem. precedente`;
    }

    // Graphe barres — jours
    const items = (d.jours || []).map(j => ({ label: j.label, val: j.ca }));
    renderBars('chart-semaine', items, (i) => STAT_COLORS[i % STAT_COLORS.length]);

    // Table jours
    let html = '';
    (d.jours || []).forEach((j, i) => {
      html += `
        <tr>
          <td style="padding:7px 10px;font-weight:600;font-size:12px;">${j.label}</td>
          <td style="padding:7px 10px;text-align:center;">${j.nb_tickets}</td>
          <td style="padding:7px 10px;text-align:right;font-family:'DM Mono',monospace;font-weight:700;">${fmtF(j.ca)}</td>
          <td style="padding:7px 10px;text-align:right;font-family:'DM Mono',monospace;color:#d97706;">${fmtF(j.remises)}</td>
          <td style="padding:7px 10px;text-align:right;font-family:'DM Mono',monospace;font-weight:700;color:#16a34a;">${fmtF(j.ca - j.remises)}</td>
          <td style="padding:7px 10px;text-align:center;">${tendanceBadge(j.ca, d.jours[i-1]?.ca)}</td>
        </tr>`;
    });
    // Ligne total
    html += `
      <tr style="background:#f8fafc;border-top:2px solid #1d4ed8;">
        <td style="padding:8px 10px;font-weight:800;font-size:12px;">TOTAL</td>
        <td style="padding:8px 10px;text-align:center;font-weight:700;">${d.nb_tickets}</td>
        <td style="padding:8px 10px;text-align:right;font-family:'DM Mono',monospace;font-weight:800;color:#1d4ed8;">${fmtF(d.ca_total)}</td>
        <td style="padding:8px 10px;text-align:right;font-family:'DM Mono',monospace;color:#d97706;">${fmtF(d.total_remises)}</td>
        <td style="padding:8px 10px;text-align:right;font-family:'DM Mono',monospace;font-weight:800;color:#16a34a;">${fmtF(d.ca_total - d.total_remises)}</td>
        <td></td>
      </tr>`;
    $('#table-semaine').html(html);

  }, 'json');
}

// ─────────────────────────────────────────────────
//  3. CA MOIS
// ─────────────────────────────────────────────────
function chargerStatsMois() {
  const mois   = $('#sel-mois').val()   || new Date().getMonth() + 1;
  const annee  = $('#sel-annee').val()  || new Date().getFullYear();

  $.get('ajax_stats_ventes.php', { action: 'stats_mois', mois, annee }, function(res) {
    if (!res.success) return;
    const d = res.data;

    // KPI
    $('#kpi-m-ca').text(fmtF(d.ca_total));
    $('#kpi-m-tickets').text(d.nb_tickets);
    $('#kpi-m-panier').text(fmtF(d.panier_moyen));
    $('#kpi-m-remise').text(fmtF(d.total_remises));

    // Badge vs mois precedent
    if (d.prev_ca > 0) {
      const delta = (((d.ca_total - d.prev_ca) / d.prev_ca) * 100).toFixed(1);
      const color = delta >= 0 ? '#16a34a' : '#dc2626';
      const arrow = delta >= 0 ? 'fa-arrow-up' : 'fa-arrow-down';
      $('#badge-vs-prev-mois').html(`
        <span style="background:${color}15;color:${color};border:1px solid ${color}40;border-radius:99px;
                     padding:4px 10px;font-size:10.5px;font-weight:700;">
          <i class="fas ${arrow}"></i> ${delta > 0 ? '+' : ''}${delta}% vs mois precedent (${fmtF(d.prev_ca)})
        </span>`);
    } else {
      $('#badge-vs-prev-mois').html('');
    }

    // Barres semaine par semaine
    const swItems = (d.semaines || []).map(s => ({ label: 'S' + s.semaine, val: s.ca }));
    renderBars('chart-mois-semaines', swItems, () => '#1d4ed8');

    // Table hebdo
    let html = '';
    (d.semaines || []).forEach(s => {
      const moy = s.jours_actifs > 0 ? (s.ca / s.jours_actifs) : 0;
      html += `
        <tr>
          <td style="padding:7px 10px;font-weight:600;">Semaine ${s.semaine} <small style="color:#9ca3af;">(${s.debut} - ${s.fin})</small></td>
          <td style="padding:7px 10px;text-align:center;">${s.jours_actifs}</td>
          <td style="padding:7px 10px;text-align:center;">${s.nb_tickets}</td>
          <td style="padding:7px 10px;text-align:right;font-family:'DM Mono',monospace;font-weight:700;">${fmtF(s.ca)}</td>
          <td style="padding:7px 10px;text-align:right;font-family:'DM Mono',monospace;color:#6b7280;">${fmtF(moy)}</td>
        </tr>`;
    });
    html += `
      <tr style="background:#f8fafc;border-top:2px solid #1d4ed8;">
        <td style="padding:8px 10px;font-weight:800;">TOTAL</td>
        <td></td>
        <td style="padding:8px 10px;text-align:center;font-weight:700;">${d.nb_tickets}</td>
        <td style="padding:8px 10px;text-align:right;font-family:'DM Mono',monospace;font-weight:800;color:#1d4ed8;">${fmtF(d.ca_total)}</td>
        <td style="padding:8px 10px;text-align:right;font-family:'DM Mono',monospace;color:#16a34a;">${fmtF(d.panier_moyen)}/ticket</td>
      </tr>`;
    $('#table-mois-semaines').html(html);

  }, 'json');
}

// ─────────────────────────────────────────────────
//  4. CA PRODUITS
// ─────────────────────────────────────────────────
function chargerStatsProduits() {
  const periode  = $('#prod-periode').val()  || 'semaine';
  const tri      = $('#prod-tri').val()      || 'ca';
  const afficher = $('#prod-afficher').val() || 'top';
  const search   = $('#prod-search-stats').val() || ''; 

  $('#table-prod-title').text(` — periode : ${periode}`);
  $('#table-produits-stats').html('<tr><td colspan="9" style="text-align:center;padding:16px;color:#9ca3af;"><i class="fas fa-spinner fa-spin"></i></td></tr>');

  $.get('ajax_stats_ventes.php', { action: 'stats_produits', periode, tri, afficher, search }, function(res) {
    console.log(res)
    if (!res.success) return;
    const d = res.data;

    // KPI
    $('#kpi-p-nb').text(d.nb_produits_vendus);
    $('#kpi-p-top').text(d.top_produit || '—');
    $('#kpi-p-zero').text(d.nb_non_vendus);

    // Table
    const total_ca = d.ca_total || 1;
    let html = '';
    (d.produits || []).forEach((p, i) => {
      const cpv = p.pa_moyen * p.qte; // Coût des produits vendus
      const margeBrute = p.ca - cpv; // Marge brute
      const tauxMarge = cpv > 0 ? ((margeBrute / p.ca) * 100).toFixed(1) : 0; // Taux de marge

      html += `
        <tr>
          <td style="padding:7px 8px;font-size:10.5px;color:#9ca3af;font-family:'DM Mono',monospace;">${i+1}</td>
          <td style="padding:7px 8px;">
            <div style="font-weight:600;font-size:11.5px;color:#111827;">${escHtml(p.nom)}</div>
            <div style="font-size:9.5px;color:#9ca3af;">${escHtml(p.molecule || '')}</div>
          </td>
          <td style="padding:7px 8px;text-align:center;font-family:'DM Mono',monospace;font-weight:700;font-size:13px;">${p.qte}</td>
          <td style="padding:7px 8px;text-align:center;font-size:11.5px;color:#6b7280;">${p.nb_tickets}</td>
          <td style="padding:7px 8px;text-align:right;font-family:'DM Mono',monospace;font-weight:700;color:#111827;">${fmtF(p.ca)}</td>
          <td style="padding:7px 8px;text-align:right;font-family:'DM Mono',monospace;">${p.pa_moyen > 0 ? fmtF(p.pa_moyen) : '—'}</td>
          <td style="padding:7px 8px;text-align:right;font-family:'DM Mono',monospace;font-weight:700;color:#16a34a;">${fmtF(margeBrute)}</td>
          <td style="padding:7px 8px;text-align:right;">${tauxMarge}%</td>
          <td style="padding:7px 8px;text-align:center;">${perfBadge(i + 1, d.produits.length)}</td>
        </tr>`;
    });

    if (!html) {
      html = '<tr><td colspan="9" style="text-align:center;color:#9ca3af;padding:20px;">Aucun produit trouve pour cette periode</td></tr>';
    }
    $('#table-produits-stats').html(html);
  }, 'json');
}


// Filtrage live produits
$(document).on('keyup', '#prod-search-stats', function() {
  const term = $(this).val().toLowerCase();
  $('#table-produits-stats tr').each(function() {
    $(this).toggle($(this).text().toLowerCase().includes(term));
  });
});

// ─────────────────────────────────────────────────
//  5. CA FAMILLES
// ─────────────────────────────────────────────────
let famSelId = null;

function chargerStatsFamilles() {
  const periode = $('#fam-periode').val() || 'semaine';
  $('#familles-grid').html('<div style="grid-column:1/-1;text-align:center;padding:20px;color:#9ca3af;"><i class="fas fa-spinner fa-spin fa-2x"></i></div>');
  $('#sous-familles-content').html('<div style="text-align:center;padding:20px;color:#9ca3af;">Chargement...</div>');
  $('#fam-selected-label').text('');
  famSelId = null;

  $.get('ajax_stats_ventes.php', { action: 'stats_familles', periode }, function(res) {
    if (!res.success) return;
    const familles = res.data.familles || [];
    const total_ca = res.data.ca_total || 1;

    let html = '';
    const famColors = ['#1d4ed8','#16a34a','#d97706','#7c3aed','#0891b2','#dc2626','#059669','#f59e0b'];

    familles.forEach((f, i) => {
      const part = ((f.ca / total_ca) * 100).toFixed(1);
      const col  = famColors[i % famColors.length];
      html += `
        <div class="fam-card" id="fcard-${f.id}" onclick="afficherSousFamilles(${f.id}, '${escJs(f.nom)}', '${$('#fam-periode').val()}', '${col}')">
          <div style="font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:${col};margin-bottom:4px;">
            <i class="fas fa-layer-group"></i> Famille
          </div>
          <div class="fam-card-name">${escHtml(f.nom)}</div>
          <div class="fam-card-ca">${fmtF(f.ca)}</div>
          <div class="fam-card-sub">${f.nb_produits} produit(s) &bull; ${f.nb_tickets} ticket(s)</div>
          <div class="fam-card-part-bar">
            <div class="fam-card-part-fill" style="width:${part}%;background:${col};"></div>
          </div>
          <div style="display:flex;justify-content:space-between;align-items:center;margin-top:4px;">
            <span style="font-size:9.5px;color:#9ca3af;">${f.nb_produits_vendus}/${f.nb_produits} ref. vendues</span>
            <span style="font-size:9.5px;font-weight:700;color:${col};">${part}%</span>
          </div>
        </div>`;
    });

    $('#familles-grid').html(html || '<p style="font-size:12px;color:#9ca3af;grid-column:1/-1;text-align:center;padding:20px;">Aucune famille trouvee</p>');
    $('#sous-familles-content').html('<div style="text-align:center;padding:20px;color:#9ca3af;font-size:12px;">Cliquez sur une famille pour voir le detail</div>');
  }, 'json');
}

function afficherSousFamilles(id_famille, nom, periode, color) {
  // Marquer la carte selectionnee
  document.querySelectorAll('.fam-card').forEach(c => c.classList.remove('selected'));
  const card = document.getElementById('fcard-' + id_famille);
  if (card) card.classList.add('selected');

  $('#fam-selected-label').text('— ' + nom);
  $('#sous-familles-content').html('<div style="text-align:center;padding:20px;color:#9ca3af;"><i class="fas fa-spinner fa-spin"></i> Chargement...</div>');

  $.get('ajax_stats_ventes.php', {
    action     : 'stats_sous_familles',
    id_famille : id_famille,
    periode    : periode || $('#fam-periode').val()
  }, function(res) {
    if (!res.success) {
      $('#sous-familles-content').html('<p style="color:#dc2626;text-align:center;padding:16px;">Erreur de chargement</p>');
      return;
    }
    const sf = res.data.sous_familles || [];
    const total_ca = res.data.ca_total || 1;

    if (!sf.length) {
      $('#sous-familles-content').html('<p style="text-align:center;color:#9ca3af;padding:20px;font-size:12px;">Aucune sous-famille ou aucune vente pour cette periode</p>');
      return;
    }

    let html = `
      <div style="overflow-x:auto;">
        <table class="data-table" style="margin-top:8px;">
          <thead>
            <tr>
              <th>Sous-famille</th>
              <th style="text-align:center;">Produits</th>
              <th style="text-align:center;">Ref. vendues</th>
              <th style="text-align:center;">Qte vendue</th>
              <th style="text-align:center;">Tickets</th>
              <th style="text-align:right;">CA</th>
              <th style="text-align:center;">Part famille</th>
            </tr>
          </thead>
          <tbody>`;

    sf.forEach((s, i) => {
      const part = ((s.ca / total_ca) * 100).toFixed(1);
      html += `
        <tr>
          <td style="padding:7px 10px;font-weight:600;">
            ${escHtml(s.nom)}
            <div style="font-size:9.5px;color:#9ca3af;margin-top:1px;">${s.description || ''}</div>
          </td>
          <td style="padding:7px 10px;text-align:center;">${s.nb_produits}</td>
          <td style="padding:7px 10px;text-align:center;">
            <span style="font-weight:700;color:${s.nb_produits_vendus > 0 ? '#16a34a' : '#dc2626'};">${s.nb_produits_vendus}</span>
          </td>
          <td style="padding:7px 10px;text-align:center;font-family:'DM Mono',monospace;font-weight:700;">${s.qte_vendue}</td>
          <td style="padding:7px 10px;text-align:center;">${s.nb_tickets}</td>
          <td style="padding:7px 10px;text-align:right;font-family:'DM Mono',monospace;font-weight:700;color:#111827;">${fmtF(s.ca)}</td>
          <td style="padding:7px 10px;text-align:center;">
            <div style="display:flex;align-items:center;gap:4px;justify-content:center;">
              <div style="width:55px;background:#f3f4f6;border-radius:99px;height:5px;overflow:hidden;">
                <div style="width:${part}%;background:${color};height:5px;"></div>
              </div>
              <span style="font-size:9.5px;font-weight:700;color:${color};">${part}%</span>
            </div>
          </td>
        </tr>`;
    });

    // Ligne TOP produits de la famille
    html += `</tbody></table></div>`;

    // Top 5 produits de cette famille
    if (res.data.top_produits && res.data.top_produits.length) {
      html += `
        <div style="margin-top:14px;">
          <div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:#6b7280;margin-bottom:8px;">
            Top produits — ${escHtml(nom)}
          </div>`;
      res.data.top_produits.forEach((p, i) => {
        const pct = ((p.ca / total_ca) * 100).toFixed(1);
        html += `
          <div class="bar-row" style="margin-bottom:6px;">
            <span class="bar-label" title="${escHtml(p.nom)}">${i+1}. ${escHtml(p.nom)}</span>
            <div class="bar-track"><div class="bar-fill" style="width:${pct}%;background:${color};"></div></div>
            <span class="bar-val">${fmtF(p.ca)}</span>
          </div>`;
      });
      html += '</div>';
    }

    $('#sous-familles-content').html(html);
  }, 'json');
}

function showMargesSubPanel(type, extra = {}) {

  // ── 1. Onglets actifs ──────────────────────────────────────
  document.querySelectorAll('#marges .sub-tab-btn').forEach(b => b.classList.remove('active'));
  const activeBtn = document.getElementById('mtab-' + type);
  if (activeBtn) activeBtn.classList.add('active');

  // ── 2. Filtres contextuels (avec garde-fous null-safe) ─────
  const dateFilter  = document.getElementById('marge-date-filter');
  const rangeFilter = document.getElementById('marge-range-filter');

  // Sécurité : on ne touche aux éléments que s'ils existent
  if (dateFilter && rangeFilter) {
    if (type === 'jours') {
      dateFilter.style.display  = 'flex';
      rangeFilter.style.display = 'none';

      // Valeur par défaut = aujourd'hui
      const picker = document.getElementById('marge_date_picker');
      if (picker && !picker.value) {
        picker.value = new Date().toISOString().split('T')[0];
      }

    } else if (['semaines', 'mois', 'evolution'].includes(type)) {
      dateFilter.style.display  = 'none';
      rangeFilter.style.display = 'flex';

    } else {
      // produits, familles, total → aucun filtre date
      dateFilter.style.display  = 'none';
      rangeFilter.style.display = 'none';
    }
  }

  // ── 3. Loader ──────────────────────────────────────────────
  const container = $('#marges-content');
  container.html(`
    <div style="text-align:center;padding:40px;color:#9ca3af;">
      <i class="fas fa-spinner fa-spin" style="font-size:22px;"></i>
      <div style="margin-top:10px;font-size:12px;">Chargement des analyses...</div>
    </div>`);

  // ── 4. Params AJAX ─────────────────────────────────────────
  let params = { type };

  if (extra.date)  params.date  = extra.date;
  if (extra.debut) params.debut = extra.debut;
  if (extra.fin)   params.fin   = extra.fin;

  // Pour le type 'jours' sans extra.date → lire le picker
  if (type === 'jours' && !params.date) {
    const picker = document.getElementById('marge_date_picker');
    if (picker && picker.value) params.date = picker.value;
  }

  // Pour les types avec plage → lire les inputs début/fin
  if (['semaines', 'mois', 'evolution'].includes(type) && !params.debut) {
    const debut = document.getElementById('marge_date_debut');
    const fin   = document.getElementById('marge_date_fin');
    if (debut && debut.value) params.debut = debut.value;
    if (fin   && fin.value)   params.fin   = fin.value;
  }

  // ── 5. Requête AJAX ────────────────────────────────────────
  $.get('ajax_marges.php', params, function(res) {
    console.log(res)
    try {
      const data = res;

      if (!data || (Array.isArray(data) && data.length === 0)) {
        container.html(`
          <div style="text-align:center;padding:40px;color:#9ca3af;">
            <i class="fas fa-inbox" style="font-size:28px;opacity:.3;display:block;margin-bottom:10px;"></i>
            Aucune donnee trouvee pour cette periode
          </div>`);
        return;
      }

      renderMargesPanel(type, data);

    } catch(e) {
      container.html(`
        <div style="color:#dc2626;padding:20px;font-size:12px;border-left:3px solid #dc2626;background:#fef2f2;border-radius:5px;margin:14px;">
          <i class="fas fa-exclamation-triangle"></i>
          Erreur de traitement des donnees. Verifiez la console.
        </div>`);
      console.error('[showMargesSubPanel] Erreur parse JSON :', e, '\nReponse brute :', res);
    }
  }).fail(function(xhr, status, err) {
    container.html(`
      <div style="color:#dc2626;padding:20px;font-size:12px;border-left:3px solid #dc2626;background:#fef2f2;border-radius:5px;margin:14px;">
        <i class="fas fa-exclamation-triangle"></i>
        Echec de la requete AJAX : ${status} — ${err}
      </div>`);
    console.error('[showMargesSubPanel] AJAX fail :', status, err);
  });
}


function filterMargeByDate() {
  const picker = document.getElementById('marge_date_picker');
  const date   = picker ? picker.value : new Date().toISOString().split('T')[0];
  showMargesSubPanel('jours', { date });
}

function filterMargeByRange() {
  const activeBtnEl = document.querySelector('#marges .sub-tab-btn.active');
  const type  = activeBtnEl ? activeBtnEl.id.replace('mtab-', '') : 'semaines';
  const debut = (document.getElementById('marge_date_debut') || {}).value || '';
  const fin   = (document.getElementById('marge_date_fin')   || {}).value || '';
  showMargesSubPanel(type, { debut, fin });
}

function renderMargesPanel(type, data) {
  const renderers = {
    jours    : renderMargesJour,
    semaines : renderMargesSemaines,
    mois     : renderMargesMois,
    produits : renderMargesProduits,
    familles : renderMargesFamilles,
    total    : renderMargesTotale,
    evolution: renderMargesEvolution,
  };
  const fn = renderers[type];
  if (fn) fn(data);
  else $('#marges-content').html('<p style="color:#dc2626;padding:20px;">Type non reconnu : ' + type + '</p>');
}

// ── KPI Cards builder ─────────────────────────────────────────
function buildKpiRow(cards) {
  const cols = cards.length <= 3 ? cards.length : 4;
  let html = `<div style="display:grid;grid-template-columns:repeat(${cols},1fr);gap:10px;margin-bottom:14px;">`;
  cards.forEach(c => {
    html += `
      <div class="kpi-card ${c.cls || 'kpi-blue'}">
        <div class="kpi-label"><i class="fas ${c.icon}"></i> ${c.label}</div>
        <div class="kpi-value" style="${c.size ? 'font-size:'+c.size+';' : ''}">${c.value}</div>
        ${c.sub ? `<div style="font-size:9.5px;color:#9ca3af;margin-top:2px;">${c.sub}</div>` : ''}
      </div>`;
  });
  html += '</div>';
  return html;
}

function renderMargesJour(data) {
  const d        = data[0] || {};
  const ca       = parseFloat(d.ca       || 0);
  const marge    = parseFloat(d.marge    || 0);
  const coût     = parseFloat(d.cout_achat || ca - marge);
  const remises  = parseFloat(d.remises  || 0);
  const tickets  = parseInt(d.nb_ventes  || 0);
  const tauxB    = ca > 0 ? (marge / ca * 100) : 0;
  const panier   = tickets > 0 ? (ca / tickets) : 0;
  const margeN   = marge - remises;
  const products = data[0]?.produits || [];

  let html = buildKpiRow([
    { cls:'kpi-blue',   icon:'fa-coins',          label:'CA du jour',       value: fmtXAF(ca) },
    { cls:'kpi-green',  icon:'fa-chart-line',     label:'Marge brute',      value: fmtXAF(marge),   sub: fmtPct(tauxB) + ' du CA' },
    { cls:'kpi-amber',  icon:'fa-tag',            label:'Impact remises',   value: fmtXAF(remises), sub: 'marge nette : ' + fmtXAF(margeN) },
    { cls:'kpi-purple', icon:'fa-receipt',        label:'Tickets / Panier', value: tickets + ' / ' + fmtXAF(panier), size:'12px' },
  ]);

  // Barre de decomposition CA
  const coutPct  = ca > 0 ? (coût  / ca * 100).toFixed(1) : 0;
  const margePct = ca > 0 ? (marge / ca * 100).toFixed(1) : 0;
  html += `
    <div class="stat-box" style="margin-bottom:14px;">
      <div class="stat-box-title"><i class="fas fa-chart-bar"></i> Decomposition du CA</div>
      <div style="margin-top:12px;">
        <div style="display:flex;gap:0;border-radius:8px;overflow:hidden;height:28px;margin-bottom:8px;">
          <div style="width:${coutPct}%;background:#dc2626;display:flex;align-items:center;justify-content:center;">
            <span style="font-size:10px;color:white;font-weight:700;white-space:nowrap;padding:0 6px;">${coutPct > 8 ? 'Cout ' + coutPct + '%' : ''}</span>
          </div>
          <div style="width:${margePct}%;background:#16a34a;display:flex;align-items:center;justify-content:center;">
            <span style="font-size:10px;color:white;font-weight:700;white-space:nowrap;padding:0 6px;">${margePct > 5 ? 'Marge ' + margePct + '%' : ''}</span>
          </div>
        </div>
        <div style="display:flex;gap:14px;flex-wrap:wrap;">
          <span style="font-size:10px;"><span style="display:inline-block;width:10px;height:10px;background:#dc2626;border-radius:2px;margin-right:4px;"></span>Cout achat ${fmtXAF(coût)} (${coutPct}%)</span>
          <span style="font-size:10px;"><span style="display:inline-block;width:10px;height:10px;background:#16a34a;border-radius:2px;margin-right:4px;"></span>Marge brute ${fmtXAF(marge)} (${margePct}%)</span>
          <span style="font-size:10px;"><span style="display:inline-block;width:10px;height:10px;background:#d97706;border-radius:2px;margin-right:4px;"></span>Remises ${fmtXAF(remises)}</span>
        </div>
      </div>
    </div>`;

  // Top produits par marge du jour
  if (products.length) {
    const maxMarge = Math.max(...products.map(p => parseFloat(p.marge) || 0), 1);
    html += `
      <div class="stat-box">
        <div class="stat-box-title"><i class="fas fa-star"></i> Top produits — Marge du jour</div>
        <table class="data-table" style="margin-top:8px;">
          <thead>
            <tr>
              <th>#</th>
              <th>Produit</th>
              <th style="text-align:center;">Qte</th>
              <th style="text-align:right;">CA</th>
              <th style="text-align:right;">Marge brute</th>
              <th style="text-align:center;">Taux</th>
              <th style="text-align:center;">Contribution</th>
            </tr>
          </thead>
          <tbody>`;
    products.forEach((p, i) => {
      const pm = parseFloat(p.marge || 0);
      const pc = parseFloat(p.ca    || 0);
      const pt = pc > 0 ? pm / pc * 100 : 0;
      html += `
        <tr>
          <td style="font-size:10px;color:#9ca3af;font-family:'DM Mono',monospace;">${i+1}</td>
          <td><div style="font-weight:600;font-size:11.5px;">${escHtml(p.nom)}</div></td>
          <td style="text-align:center;font-family:'DM Mono',monospace;font-weight:700;">${p.qte}</td>
          <td style="text-align:right;font-family:'DM Mono',monospace;">${fmtXAF(pc)}</td>
          <td style="text-align:right;font-family:'DM Mono',monospace;font-weight:700;color:${pm >= 0 ? '#16a34a' : '#dc2626'};">${fmtXAF(pm)}</td>
          <td style="text-align:center;">${margeBadge(pt)}</td>
          <td style="text-align:center;">${miniBarH(pm, maxMarge, MARGE_COLORS[i % MARGE_COLORS.length])}</td>
        </tr>`;
    });
    html += '</tbody></table></div>';
  }

  $('#marges-content').html(html);
}

// ══════════════════════════════════════════════════════════════
//  2. MARGE / SEMAINES
// ══════════════════════════════════════════════════════════════
function renderMargesSemaines(data) {
  const totCA    = data.reduce((s, r) => s + parseFloat(r.ca    || 0), 0);
  const totMarge = data.reduce((s, r) => s + parseFloat(r.marge || 0), 0);
  const maxMarge = Math.max(...data.map(r => parseFloat(r.marge || 0)), 1);
  const best     = [...data].sort((a,b) => parseFloat(b.marge) - parseFloat(a.marge))[0] || {};

  let html = buildKpiRow([
    { cls:'kpi-blue',   icon:'fa-coins',       label:'CA Total',        value: fmtXAF(totCA) },
    { cls:'kpi-green',  icon:'fa-chart-line',  label:'Marge Totale',    value: fmtXAF(totMarge), sub: fmtPct(totCA > 0 ? totMarge/totCA*100 : 0) + ' du CA' },
    { cls:'kpi-amber',  icon:'fa-trophy',      label:'Meilleure sem.',  value: 'S' + (best.semaine || '—'), sub: fmtXAF(best.marge || 0), size:'14px' },
    { cls:'kpi-purple', icon:'fa-calculator',  label:'Marge moy./sem.', value: fmtXAF(data.length ? totMarge / data.length : 0) },
  ]);

  // Barres semaine
  html += '<div class="stat-box" style="margin-bottom:14px;"><div class="stat-box-title">Evolution marge par semaine</div><div style="margin-top:10px;">';
  data.forEach((r, i) => {
    const m   = parseFloat(r.marge || 0);
    const c   = parseFloat(r.ca    || 0);
    const t   = c > 0 ? m/c*100 : 0;
    const pct = (m / maxMarge * 100).toFixed(1);
    html += `
      <div class="bar-row">
        <span class="bar-label">Sem. ${r.semaine || r.label}</span>
        <div class="bar-track">
          <div class="bar-fill" style="width:${pct}%;background:${m >= 0 ? '#16a34a' : '#dc2626'};"></div>
        </div>
        <span class="bar-val" style="color:${m >= 0 ? '#16a34a' : '#dc2626'};font-weight:700;">${fmtXAF(m)}</span>
        <span style="margin-left:8px;">${margeBadge(t)}</span>
      </div>`;
  });
  html += '</div></div>';

  // Table
  html += `
    <div class="stat-box">
      <div class="stat-box-title">Detail par semaine</div>
      <table class="data-table" style="margin-top:8px;">
        <thead>
          <tr>
            <th>Semaine</th>
            <th style="text-align:center;">Periode</th>
            <th style="text-align:center;">Tickets</th>
            <th style="text-align:right;">CA</th>
            <th style="text-align:right;">Cout achat</th>
            <th style="text-align:right;">Marge brute</th>
            <th style="text-align:right;">Remises</th>
            <th style="text-align:right;">Marge nette</th>
            <th style="text-align:center;">Taux</th>
            <th style="text-align:center;">Tendance</th>
          </tr>
        </thead>
        <tbody>`;
  data.forEach((r, i) => {
    const ca  = parseFloat(r.ca     || 0);
    const mg  = parseFloat(r.marge  || 0);
    const rm  = parseFloat(r.remises|| 0);
    const ct  = parseFloat(r.cout_achat || ca - mg);
    const mn  = mg - rm;
    const tB  = ca > 0 ? mg/ca*100 : 0;
    const tN  = ca > 0 ? mn/ca*100 : 0;
    html += `
      <tr>
        <td style="font-weight:700;font-family:'DM Mono',monospace;">S${r.semaine || r.label}</td>
        <td style="text-align:center;font-size:10px;color:#9ca3af;">${r.debut || ''} ${r.fin ? '- ' + r.fin : ''}</td>
        <td style="text-align:center;">${r.nb_ventes || '—'}</td>
        <td style="text-align:right;font-family:'DM Mono',monospace;font-weight:700;">${fmtXAF(ca)}</td>
        <td style="text-align:right;font-family:'DM Mono',monospace;color:#dc2626;">${fmtXAF(ct)}</td>
        <td style="text-align:right;font-family:'DM Mono',monospace;font-weight:700;color:${mg >= 0 ? '#16a34a' : '#dc2626'};">${fmtXAF(mg)}</td>
        <td style="text-align:right;font-family:'DM Mono',monospace;color:#d97706;">${fmtXAF(rm)}</td>
        <td style="text-align:right;font-family:'DM Mono',monospace;font-weight:800;color:${mn >= 0 ? '#1d4ed8' : '#dc2626'};">${fmtXAF(mn)}</td>
        <td style="text-align:center;">${margeBadge(tB)}</td>
        <td style="text-align:center;">${tendanceMarge(mg, data[i-1]?.marge)}</td>
      </tr>`;
  });
  // Ligne total
  const totCout = data.reduce((s,r) => s + parseFloat(r.cout_achat || 0), 0);
  const totRem  = data.reduce((s,r) => s + parseFloat(r.remises    || 0), 0);
  html += `
      <tr style="background:#f8fafc;border-top:2px solid #1d4ed8;">
        <td colspan="3" style="padding:8px 10px;font-weight:800;">TOTAL</td>
        <td style="padding:8px 10px;text-align:right;font-family:'DM Mono',monospace;font-weight:800;color:#1d4ed8;">${fmtXAF(totCA)}</td>
        <td style="padding:8px 10px;text-align:right;font-family:'DM Mono',monospace;color:#dc2626;">${fmtXAF(totCout)}</td>
        <td style="padding:8px 10px;text-align:right;font-family:'DM Mono',monospace;font-weight:800;color:#16a34a;">${fmtXAF(totMarge)}</td>
        <td style="padding:8px 10px;text-align:right;font-family:'DM Mono',monospace;color:#d97706;">${fmtXAF(totRem)}</td>
        <td style="padding:8px 10px;text-align:right;font-family:'DM Mono',monospace;font-weight:800;color:#1d4ed8;">${fmtXAF(totMarge - totRem)}</td>
        <td style="padding:8px 10px;text-align:center;">${margeBadge(totCA > 0 ? totMarge/totCA*100 : 0)}</td>
        <td></td>
      </tr>
    </tbody></table></div>`;

  $('#marges-content').html(html);
}

// ══════════════════════════════════════════════════════════════
//  3. MARGE / MOIS
// ══════════════════════════════════════════════════════════════
function renderMargesMois(data) {
  const totCA    = data.reduce((s,r) => s + parseFloat(r.ca    || 0), 0);
  const totMarge = data.reduce((s,r) => s + parseFloat(r.marge || 0), 0);
  const totRem   = data.reduce((s,r) => s + parseFloat(r.remises|| 0), 0);
  const maxMarge = Math.max(...data.map(r => parseFloat(r.marge || 0)), 1);
  const MOIS_FR  = ['','Jan','Fev','Mar','Avr','Mai','Jun','Jul','Aou','Sep','Oct','Nov','Dec'];

  let html = buildKpiRow([
    { cls:'kpi-blue',   icon:'fa-coins',       label:'CA Annuel',        value: fmtXAF(totCA) },
    { cls:'kpi-green',  icon:'fa-chart-line',  label:'Marge Totale',     value: fmtXAF(totMarge), sub: fmtPct(totCA > 0 ? totMarge/totCA*100 : 0) },
    { cls:'kpi-amber',  icon:'fa-tag',         label:'Remises totales',  value: fmtXAF(totRem) },
    { cls:'kpi-purple', icon:'fa-calculator',  label:'Marge nette tot.', value: fmtXAF(totMarge - totRem) },
  ]);

  // Graphe mensuel double barre CA vs Marge
  html += '<div class="stat-box" style="margin-bottom:14px;"><div class="stat-box-title">CA vs Marge — vue mensuelle</div><div style="margin-top:12px;">';
  const maxCA = Math.max(...data.map(r => parseFloat(r.ca || 0)), 1);
  data.forEach((r, i) => {
    const ca  = parseFloat(r.ca    || 0);
    const mg  = parseFloat(r.marge || 0);
    const t   = ca > 0 ? mg/ca*100 : 0;
    const label = r.nom_mois || MOIS_FR[parseInt(r.mois)] || r.mois;
    html += `
      <div style="margin-bottom:8px;">
        <div style="display:flex;align-items:center;gap:8px;margin-bottom:3px;">
          <span style="width:32px;font-size:10px;font-weight:700;color:#6b7280;text-align:right;">${label}</span>
          <div style="flex:1;display:flex;flex-direction:column;gap:3px;">
            <div style="background:#e0e7ff;border-radius:3px;height:8px;overflow:hidden;">
              <div style="width:${(ca/maxCA*100).toFixed(1)}%;background:#1d4ed8;height:8px;border-radius:3px;"></div>
            </div>
            <div style="background:#dcfce7;border-radius:3px;height:8px;overflow:hidden;">
              <div style="width:${(Math.max(mg,0)/maxCA*100).toFixed(1)}%;background:#16a34a;height:8px;border-radius:3px;"></div>
            </div>
          </div>
          <span style="width:90px;font-size:10px;text-align:right;font-family:'DM Mono',monospace;color:#1d4ed8;">${fmtXAF(ca)}</span>
          <span style="width:80px;font-size:10px;text-align:right;font-family:'DM Mono',monospace;color:#16a34a;font-weight:700;">${fmtXAF(mg)}</span>
          <span style="width:50px;">${margeBadge(t)}</span>
        </div>
      </div>`;
  });
  html += `<div style="display:flex;gap:16px;margin-top:6px;padding-left:40px;">
    <span style="font-size:9.5px;"><span style="display:inline-block;width:10px;height:6px;background:#1d4ed8;border-radius:2px;margin-right:4px;"></span>CA</span>
    <span style="font-size:9.5px;"><span style="display:inline-block;width:10px;height:6px;background:#16a34a;border-radius:2px;margin-right:4px;"></span>Marge brute</span>
  </div></div></div>`;

  // Table mensuelle
  html += `
    <div class="stat-box">
      <div class="stat-box-title">Detail mensuel</div>
      <table class="data-table" style="margin-top:8px;">
        <thead>
          <tr>
            <th>Mois</th>
            <th style="text-align:center;">Tickets</th>
            <th style="text-align:right;">CA</th>
            <th style="text-align:right;">Cout achat</th>
            <th style="text-align:right;">Marge brute</th>
            <th style="text-align:right;">Remises</th>
            <th style="text-align:right;">Marge nette</th>
            <th style="text-align:center;">Tx brut</th>
            <th style="text-align:center;">Tx net</th>
            <th style="text-align:center;">Evol.</th>
          </tr>
        </thead>
        <tbody>`;
  data.forEach((r, i) => {
    const ca  = parseFloat(r.ca      || 0);
    const mg  = parseFloat(r.marge   || 0);
    const rm  = parseFloat(r.remises || 0);
    const ct  = parseFloat(r.cout_achat || ca - mg);
    const mn  = mg - rm;
    const tB  = ca > 0 ? mg/ca*100 : 0;
    const tN  = ca > 0 ? mn/ca*100 : 0;
    const label = r.nom_mois || MOIS_FR[parseInt(r.mois)] || r.mois;
    html += `
      <tr>
        <td style="font-weight:700;">${label} ${r.annee || ''}</td>
        <td style="text-align:center;">${r.nb_ventes || '—'}</td>
        <td style="text-align:right;font-family:'DM Mono',monospace;font-weight:700;">${fmtXAF(ca)}</td>
        <td style="text-align:right;font-family:'DM Mono',monospace;color:#dc2626;">${fmtXAF(ct)}</td>
        <td style="text-align:right;font-family:'DM Mono',monospace;font-weight:700;color:${mg >= 0 ? '#16a34a' : '#dc2626'};">${fmtXAF(mg)}</td>
        <td style="text-align:right;font-family:'DM Mono',monospace;color:#d97706;">${fmtXAF(rm)}</td>
        <td style="text-align:right;font-family:'DM Mono',monospace;font-weight:800;color:#1d4ed8;">${fmtXAF(mn)}</td>
        <td style="text-align:center;">${margeBadge(tB)}</td>
        <td style="text-align:center;">${margeBadge(tN)}</td>
        <td style="text-align:center;">${tendanceMarge(mg, data[i-1]?.marge)}</td>
      </tr>`;
  });
  html += `</tbody></table></div>`;
  $('#marges-content').html(html);
}

// ══════════════════════════════════════════════════════════════
//  4. MARGE / PRODUITS
// ══════════════════════════════════════════════════════════════
function renderMargesProduits(data) {
  const totCA    = data.reduce((s,r) => s + parseFloat(r.ca    || 0), 0);
  const totMarge = data.reduce((s,r) => s + parseFloat(r.marge || 0), 0);
  const topMarge = [...data].sort((a,b) => parseFloat(b.marge) - parseFloat(a.marge))[0] || {};
  const topTaux  = [...data].sort((a,b) => {
    const tA = parseFloat(a.ca) > 0 ? parseFloat(a.marge)/parseFloat(a.ca) : 0;
    const tB = parseFloat(b.ca) > 0 ? parseFloat(b.marge)/parseFloat(b.ca) : 0;
    return tB - tA;
  })[0] || {};
  const negatifs = data.filter(r => parseFloat(r.marge) < 0).length;
  const maxMarge = Math.max(...data.map(r => parseFloat(r.marge || 0)), 1);

  let html = buildKpiRow([
    { cls:'kpi-blue',   icon:'fa-coins',              label:'CA Total',            value: fmtXAF(totCA) },
    { cls:'kpi-green',  icon:'fa-chart-line',         label:'Marge Totale',        value: fmtXAF(totMarge), sub: fmtPct(totCA>0?totMarge/totCA*100:0) },
    { cls:'kpi-amber',  icon:'fa-trophy',             label:'+ Contributeur',      value: escHtml(topMarge.nom || '—'), size:'11px', sub: fmtXAF(topMarge.marge||0) },
    { cls:'kpi-red',    icon:'fa-exclamation-circle', label:'Marges negatives',    value: negatifs + ' produit' + (negatifs>1?'s':'') },
  ]);

  // Filtres tri
  html += `
    <div style="display:flex;gap:8px;align-items:center;margin-bottom:10px;flex-wrap:wrap;">
      <select id="prod-marge-tri" onchange="sortMargesProduits()"
        style="padding:5px 8px;border:1px solid #e5e7eb;border-radius:5px;font-size:11px;font-family:'DM Sans',sans-serif;outline:none;color:#111827;">
        <option value="marge">Trier par marge</option>
        <option value="taux">Trier par taux</option>
        <option value="ca">Trier par CA</option>
        <option value="qte">Trier par quantite</option>
      </select>
      <div style="position:relative;flex:1;min-width:140px;">
        <i class="fas fa-search" style="position:absolute;left:8px;top:50%;transform:translateY(-50%);color:#9ca3af;font-size:10px;"></i>
        <input type="text" id="marge-prod-search" placeholder="Filtrer..." oninput="filterMargeTable()"
          style="width:100%;padding:5px 8px 5px 24px;border:1px solid #e5e7eb;border-radius:5px;font-size:11px;outline:none;color:#111827;">
      </div>
    </div>`;

  html += `
    <div class="stat-box">
      <div class="stat-box-title">Analyse marge par produit</div>
      <div style="overflow-x:auto;max-height:450px;overflow-y:auto;">
        <table class="data-table" id="marge-prod-table" style="margin-top:8px;min-width:720px;">
          <thead>
            <tr>
              <th style="position:sticky;top:0;background:#f9fafb;">#</th>
              <th style="position:sticky;top:0;background:#f9fafb;">Produit</th>
              <th style="position:sticky;top:0;background:#f9fafb;text-align:center;">Qte</th>
              <th style="position:sticky;top:0;background:#f9fafb;text-align:right;">CA</th>
              <th style="position:sticky;top:0;background:#f9fafb;text-align:right;">Cout achat</th>
              <th style="position:sticky;top:0;background:#f9fafb;text-align:right;">Marge brute</th>
              <th style="position:sticky;top:0;background:#f9fafb;text-align:center;">Taux</th>
              <th style="position:sticky;top:0;background:#f9fafb;text-align:center;">Contribution</th>
              <th style="position:sticky;top:0;background:#f9fafb;text-align:center;">Profil</th>
            </tr>
          </thead>
          <tbody id="marge-prod-tbody">`;

  data.forEach((r, i) => {
    const ca  = parseFloat(r.ca    || 0);
    const mg  = parseFloat(r.marge || 0);
    const ct  = ca - mg;
    const tB  = ca > 0 ? mg/ca*100 : 0;
    const contrib = totCA > 0 ? mg/totCA*100 : 0;
    const profil = tB >= 30 ? '<span style="color:#16a34a;font-weight:700;font-size:10px;">Rentable</span>'
                 : tB >= 15 ? '<span style="color:#d97706;font-weight:700;font-size:10px;">Correct</span>'
                 : tB >= 0  ? '<span style="color:#f97316;font-weight:700;font-size:10px;">Faible</span>'
                 : '<span style="color:#dc2626;font-weight:700;font-size:10px;">Negatif</span>';
    html += `
      <tr>
        <td style="font-size:10px;color:#9ca3af;font-family:'DM Mono',monospace;">${i+1}</td>
        <td>
          <div style="font-weight:600;font-size:11.5px;">${escHtml(r.nom || r.nom_commercial || '')}</div>
          <div style="font-size:9.5px;color:#9ca3af;">${escHtml(r.molecule || r.famille || '')}</div>
        </td>
        <td style="text-align:center;font-family:'DM Mono',monospace;font-weight:700;">${r.quantite || r.qte || 0}</td>
        <td style="text-align:right;font-family:'DM Mono',monospace;">${fmtXAF(ca)}</td>
        <td style="text-align:right;font-family:'DM Mono',monospace;color:#dc2626;">${fmtXAF(ct)}</td>
        <td style="text-align:right;font-family:'DM Mono',monospace;font-weight:700;color:${mg >= 0 ? '#16a34a' : '#dc2626'};">${fmtXAF(mg)}</td>
        <td style="text-align:center;">${margeBadge(tB)}</td>
        <td style="text-align:center;">${miniBarH(Math.max(mg,0), maxMarge, MARGE_COLORS[i % MARGE_COLORS.length])}
          <span style="font-size:9px;color:#6b7280;margin-left:4px;">${contrib.toFixed(1)}%</span>
        </td>
        <td style="text-align:center;">${profil}</td>
      </tr>`;
  });

  html += `</tbody></table></div></div>`;
  $('#marges-content').html(html);
}

function filterMargeTable() {
  const term = (document.getElementById('marge-prod-search')?.value || '').toLowerCase();
  document.querySelectorAll('#marge-prod-tbody tr').forEach(tr => {
    tr.style.display = tr.textContent.toLowerCase().includes(term) ? '' : 'none';
  });
}
function sortMargesProduits() {
  // Recharge simplement avec tri serveur — ou peut trier côté client
  const key = document.getElementById('prod-marge-tri')?.value;
  const tbody = document.getElementById('marge-prod-tbody');
  if (!tbody) return;
  const rows = Array.from(tbody.querySelectorAll('tr'));
  const colIdx = { marge:5, ca:3, taux:6, qte:2 };
  rows.sort((a, b) => {
    const va = parseFloat(a.cells[colIdx[key] || 5]?.textContent.replace(/\s/g,'').replace(',','.') || 0);
    const vb = parseFloat(b.cells[colIdx[key] || 5]?.textContent.replace(/\s/g,'').replace(',','.') || 0);
    return vb - va;
  });
  rows.forEach(r => tbody.appendChild(r));
}

// ══════════════════════════════════════════════════════════════
//  5. MARGE / FAMILLES
// ══════════════════════════════════════════════════════════════
function renderMargesFamilles(data) {
  const totCA    = data.reduce((s,r) => s + parseFloat(r.ca    || 0), 0);
  const totMarge = data.reduce((s,r) => s + parseFloat(r.marge || 0), 0);
  const maxMarge = Math.max(...data.map(r => parseFloat(r.marge || 0)), 1);

  let html = buildKpiRow([
    { cls:'kpi-blue',  icon:'fa-layer-group', label:'Familles actives', value: data.length },
    { cls:'kpi-green', icon:'fa-chart-line',  label:'Marge Totale',     value: fmtXAF(totMarge), sub: fmtPct(totCA>0?totMarge/totCA*100:0) },
    { cls:'kpi-amber', icon:'fa-coins',       label:'CA Total',         value: fmtXAF(totCA) },
  ]);

  // Grille familles
  html += '<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:10px;margin-bottom:14px;">';
  data.forEach((r, i) => {
    const ca  = parseFloat(r.ca    || 0);
    const mg  = parseFloat(r.marge || 0);
    const t   = ca > 0 ? mg/ca*100 : 0;
    const pct = (Math.max(mg,0) / maxMarge * 100).toFixed(1);
    const col = MARGE_COLORS[i % MARGE_COLORS.length];
    html += `
      <div style="background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:12px;border-left:3px solid ${col};">
        <div style="font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:${col};margin-bottom:4px;">
          <i class="fas fa-layer-group"></i> Famille
        </div>
        <div style="font-weight:700;font-size:12px;color:#111827;margin-bottom:6px;">${escHtml(r.nom_famille || r.nom || '')}</div>
        <div style="font-family:'DM Mono',monospace;font-weight:800;font-size:13px;color:${mg >= 0 ? '#16a34a' : '#dc2626'};margin-bottom:2px;">${fmtXAF(mg)}</div>
        <div style="font-size:9.5px;color:#9ca3af;margin-bottom:8px;">CA : ${fmtXAF(ca)} &bull; ${fmtPct(t)}</div>
        <div style="background:#f3f4f6;border-radius:99px;height:5px;overflow:hidden;">
          <div style="width:${pct}%;background:${col};height:5px;"></div>
        </div>
        <div style="display:flex;justify-content:space-between;margin-top:4px;">
          <span style="font-size:9.5px;color:#9ca3af;">${r.nb_produits || 0} ref.</span>
          ${margeBadge(t)}
        </div>
      </div>`;
  });
  html += '</div>';

  // Table sous-familles
  html += `
    <div class="stat-box">
      <div class="stat-box-title">Detail par famille et sous-famille</div>
      <table class="data-table" style="margin-top:8px;">
        <thead>
          <tr>
            <th>Famille / Sous-famille</th>
            <th style="text-align:center;">Produits</th>
            <th style="text-align:center;">Qte vendue</th>
            <th style="text-align:right;">CA</th>
            <th style="text-align:right;">Cout achat</th>
            <th style="text-align:right;">Marge brute</th>
            <th style="text-align:center;">Taux</th>
            <th style="text-align:center;">Part CA</th>
          </tr>
        </thead>
        <tbody>`;
  data.forEach((r, i) => {
    const ca  = parseFloat(r.ca    || 0);
    const mg  = parseFloat(r.marge || 0);
    const ct  = ca - mg;
    const t   = ca > 0 ? mg/ca*100 : 0;
    const partCA = totCA > 0 ? ca/totCA*100 : 0;
    html += `
      <tr style="background:#f8fafc;">
        <td style="font-weight:800;font-size:12px;color:#1d4ed8;padding:8px 10px;">
          <i class="fas fa-layer-group" style="margin-right:4px;"></i> ${escHtml(r.nom_famille || r.nom || '')}
        </td>
        <td style="text-align:center;">${r.nb_produits || '—'}</td>
        <td style="text-align:center;font-family:'DM Mono',monospace;font-weight:700;">${r.quantite || r.qte || 0}</td>
        <td style="text-align:right;font-family:'DM Mono',monospace;font-weight:700;">${fmtXAF(ca)}</td>
        <td style="text-align:right;font-family:'DM Mono',monospace;color:#dc2626;">${fmtXAF(ct)}</td>
        <td style="text-align:right;font-family:'DM Mono',monospace;font-weight:800;color:${mg>=0?'#16a34a':'#dc2626'};">${fmtXAF(mg)}</td>
        <td style="text-align:center;">${margeBadge(t)}</td>
        <td style="text-align:center;">
          <div style="display:flex;align-items:center;gap:4px;justify-content:center;">
            ${miniBarH(ca, totCA, MARGE_COLORS[i % MARGE_COLORS.length])}
            <span style="font-size:9px;font-weight:700;">${partCA.toFixed(1)}%</span>
          </div>
        </td>
      </tr>`;
    // Sous-familles
    if (r.sous_familles && r.sous_familles.length) {
      r.sous_familles.forEach(sf => {
        const sca = parseFloat(sf.ca    || 0);
        const smg = parseFloat(sf.marge || 0);
        const sct = sca - smg;
        const st  = sca > 0 ? smg/sca*100 : 0;
        html += `
          <tr>
            <td style="padding:6px 10px 6px 28px;font-size:11px;color:#374151;">
              <i class="fas fa-arrow-right" style="color:#9ca3af;margin-right:4px;font-size:9px;"></i> ${escHtml(sf.nom || '')}
            </td>
            <td style="text-align:center;font-size:11px;">${sf.nb_produits || '—'}</td>
            <td style="text-align:center;font-family:'DM Mono',monospace;">${sf.quantite || 0}</td>
            <td style="text-align:right;font-family:'DM Mono',monospace;font-size:11px;">${fmtXAF(sca)}</td>
            <td style="text-align:right;font-family:'DM Mono',monospace;font-size:11px;color:#dc2626;">${fmtXAF(sct)}</td>
            <td style="text-align:right;font-family:'DM Mono',monospace;font-weight:700;font-size:11px;color:${smg>=0?'#16a34a':'#dc2626'};">${fmtXAF(smg)}</td>
            <td style="text-align:center;">${margeBadge(st)}</td>
            <td></td>
          </tr>`;
      });
    }
  });
  html += '</tbody></table></div>';
  $('#marges-content').html(html);
}

// ══════════════════════════════════════════════════════════════
//  6. MARGE TOTALE (tableau de bord)
// ══════════════════════════════════════════════════════════════
function renderMargesTotale(data) {
  const d      = data[0] || {};
  const ca     = parseFloat(d.ca          || 0);
  const mg     = parseFloat(d.marge       || 0);
  const rm     = parseFloat(d.remises     || 0);
  const ch     = parseFloat(d.charges     || 0);
  const mn     = mg - rm;
  const mo     = mn - ch;
  const tB     = ca > 0 ? mg/ca*100 : 0;
  const tN     = ca > 0 ? mn/ca*100 : 0;
  const tO     = ca > 0 ? mo/ca*100 : 0;

  let html = buildKpiRow([
    { cls:'kpi-blue',   icon:'fa-coins',       label:'CA Global',        value: fmtXAF(ca) },
    { cls:'kpi-green',  icon:'fa-chart-line',  label:'Marge brute',      value: fmtXAF(mg),  sub: fmtPct(tB) + ' du CA' },
    { cls:'kpi-amber',  icon:'fa-minus-circle',label:'Marge nette',      value: fmtXAF(mn),  sub: fmtPct(tN) + ' (apres remises)' },
    { cls:'kpi-purple', icon:'fa-building',    label:'Marge operationnelle', value: fmtXAF(mo), sub: fmtPct(tO) + ' (apres charges)' },
  ]);

  // Cascade waterfall visuelle
  const items = [
    { label: 'CA Total',      val: ca,  color: '#1d4ed8', type: 'revenue' },
    { label: 'Cout des ventes',val: -(ca - mg), color: '#dc2626', type: 'cost' },
    { label: 'Marge brute',   val: mg,  color: '#16a34a', type: 'sub' },
    { label: 'Remises',       val: -rm, color: '#d97706', type: 'cost' },
    { label: 'Marge nette',   val: mn,  color: '#0891b2', type: 'sub' },
    { label: 'Charges',       val: -ch, color: '#7c3aed', type: 'cost' },
    { label: 'Marge oper.',   val: mo,  color: '#059669', type: 'total' },
  ];
  const absMax = Math.max(...items.map(it => Math.abs(it.val)), 1);
  html += `
    <div class="stat-box" style="margin-bottom:14px;">
      <div class="stat-box-title"><i class="fas fa-stream"></i> Cascade de rentabilite</div>
      <div style="margin-top:14px;">`;
  items.forEach(it => {
    const pct = (Math.abs(it.val) / absMax * 100).toFixed(1);
    const isNeg = it.val < 0;
    html += `
      <div style="display:flex;align-items:center;gap:8px;margin-bottom:8px;">
        <span style="width:130px;font-size:11px;font-weight:${it.type==='sub'||it.type==='total'?'700':'500'};color:${it.type==='cost'?'#dc2626':'#111827'};text-align:right;">${it.label}</span>
        <div style="flex:1;position:relative;height:20px;background:#f9fafb;border-radius:4px;overflow:hidden;">
          <div style="position:absolute;${isNeg?'right:0':'left:0'};top:0;width:${pct}%;height:100%;background:${it.color};opacity:${it.type==='sub'||it.type==='total'?1:0.7};border-radius:4px;"></div>
        </div>
        <span style="width:100px;font-size:11px;font-family:'DM Mono',monospace;font-weight:700;color:${it.val >= 0 ? it.color : '#dc2626'};text-align:right;">${isNeg ? '-' : ''}${fmtXAF(Math.abs(it.val))}</span>
        <span style="width:40px;">${margeBadge(ca > 0 ? it.val/ca*100 : 0)}</span>
      </div>`;
  });
  html += '</div></div>';

  // Comparaison par periode (si disponible)
  if (data[0]?.periodes) {
    html += `
      <div class="stat-box">
        <div class="stat-box-title">Repartition par periode</div>
        <table class="data-table" style="margin-top:8px;">
          <thead>
            <tr><th>Periode</th><th style="text-align:right;">CA</th>
            <th style="text-align:right;">Marge brute</th><th style="text-align:center;">Taux</th></tr>
          </thead>
          <tbody>`;
    data[0].periodes.forEach(p => {
      const pc = parseFloat(p.ca||0), pm = parseFloat(p.marge||0);
      html += `<tr>
        <td style="font-weight:600;">${escHtml(p.label)}</td>
        <td style="text-align:right;font-family:'DM Mono',monospace;">${fmtXAF(pc)}</td>
        <td style="text-align:right;font-family:'DM Mono',monospace;font-weight:700;color:#16a34a;">${fmtXAF(pm)}</td>
        <td style="text-align:center;">${margeBadge(pc>0?pm/pc*100:0)}</td>
      </tr>`;
    });
    html += '</tbody></table></div>';
  }

  $('#marges-content').html(html);
}

// ══════════════════════════════════════════════════════════════
//  7. EVOLUTION (graphe temporel avance)
// ══════════════════════════════════════════════════════════════
function renderMargesEvolution(data) {
  const totCA    = data.reduce((s,r) => s + parseFloat(r.ca    || 0), 0);
  const totMarge = data.reduce((s,r) => s + parseFloat(r.marge || 0), 0);
  const maxCA    = Math.max(...data.map(r => parseFloat(r.ca    || 0)), 1);
  const maxMarge = Math.max(...data.map(r => Math.abs(parseFloat(r.marge || 0))), 1);

  // Tendance lineaire simple
  const n = data.length;
  const avgMarge = n > 0 ? totMarge / n : 0;
  const trend = n >= 2
    ? parseFloat(data[n-1].marge||0) > parseFloat(data[0].marge||0) ? 'hausse' : 'baisse'
    : 'stable';
  const trendColor = trend === 'hausse' ? '#16a34a' : trend === 'baisse' ? '#dc2626' : '#6b7280';
  const trendIcon  = trend === 'hausse' ? 'fa-arrow-trend-up' : trend === 'baisse' ? 'fa-arrow-trend-down' : 'fa-minus';

  let html = buildKpiRow([
    { cls:'kpi-blue',   icon:'fa-coins',       label:'CA Total periode', value: fmtXAF(totCA) },
    { cls:'kpi-green',  icon:'fa-chart-line',  label:'Marge cumulee',    value: fmtXAF(totMarge), sub: fmtPct(totCA>0?totMarge/totCA*100:0) },
    { cls:'kpi-amber',  icon:'fa-calendar-day',label:'Marge moy./jour',  value: fmtXAF(avgMarge) },
    { cls: trend === 'hausse' ? 'kpi-green' : 'kpi-red', icon: trendIcon, label: 'Tendance', value: trend.charAt(0).toUpperCase() + trend.slice(1) },
  ]);

  // Graphe evolution double courbe (CA vs Marge)
  html += `
    <div class="stat-box" style="margin-bottom:14px;">
      <div class="stat-box-title"><i class="fas fa-chart-line"></i> Evolution CA et Marge — vue temporelle</div>
      <div style="margin-top:12px;overflow-x:auto;">
        <div style="min-width:${Math.max(data.length * 40, 400)}px;">`;

  // Legende
  html += `
      <div style="display:flex;gap:16px;margin-bottom:8px;">
        <span style="font-size:9.5px;"><span style="display:inline-block;width:16px;height:3px;background:#1d4ed8;border-radius:2px;margin-right:4px;vertical-align:middle;"></span>CA</span>
        <span style="font-size:9.5px;"><span style="display:inline-block;width:16px;height:3px;background:#16a34a;border-radius:2px;margin-right:4px;vertical-align:middle;"></span>Marge brute</span>
        <span style="font-size:9.5px;"><span style="display:inline-block;width:16px;height:3px;background:#dc2626;border-radius:2px;vertical-align:middle;"></span> Marge negative</span>
      </div>`;

  // Barres groupees
  html += `<div style="display:flex;align-items:flex-end;gap:3px;height:120px;border-bottom:1px solid #e5e7eb;">`;
  data.forEach((r, i) => {
    const ca  = parseFloat(r.ca    || 0);
    const mg  = parseFloat(r.marge || 0);
    const hCA = (ca  / maxCA    * 110).toFixed(0);
    const hMG = (Math.abs(mg) / maxCA * 110).toFixed(0);
    const mgCol = mg >= 0 ? '#16a34a' : '#dc2626';
    html += `
      <div style="display:flex;align-items:flex-end;gap:1px;flex:1;position:relative;" title="${r.date || r.label || ''} | CA: ${fmtXAF(ca)} | Marge: ${fmtXAF(mg)}">
        <div style="width:48%;background:#1d4ed8;height:${hCA}px;border-radius:2px 2px 0 0;opacity:.8;"></div>
        <div style="width:48%;background:${mgCol};height:${hMG}px;border-radius:2px 2px 0 0;"></div>
      </div>`;
  });
  html += `</div>`;

  // Labels dates
  html += `<div style="display:flex;gap:3px;">`;
  const step = Math.ceil(data.length / 12);
  data.forEach((r, i) => {
    const label = i % step === 0 ? (r.date || r.label || '').slice(5) : '';
    html += `<div style="flex:1;text-align:center;font-size:8px;color:#9ca3af;margin-top:2px;overflow:hidden;">${label}</div>`;
  });
  html += '</div></div></div></div>';

  // Table evolution avec tendance
  html += `
    <div class="stat-box">
      <div class="stat-box-title">Detail journalier</div>
      <div style="max-height:360px;overflow-y:auto;">
        <table class="data-table" style="margin-top:8px;">
          <thead>
            <tr>
              <th>Date</th>
              <th style="text-align:center;">Tickets</th>
              <th style="text-align:right;">CA</th>
              <th style="text-align:right;">Cout achat</th>
              <th style="text-align:right;">Marge brute</th>
              <th style="text-align:right;">Remises</th>
              <th style="text-align:right;">Marge nette</th>
              <th style="text-align:center;">Taux</th>
              <th style="text-align:center;">Evol.</th>
            </tr>
          </thead>
          <tbody>`;
  let cumCA = 0, cumMG = 0;
  data.forEach((r, i) => {
    const ca  = parseFloat(r.ca      || 0);
    const mg  = parseFloat(r.marge   || 0);
    const rm  = parseFloat(r.remises || 0);
    const ct  = ca - mg;
    const mn  = mg - rm;
    const t   = ca > 0 ? mg/ca*100 : 0;
    cumCA += ca; cumMG += mg;
    html += `
      <tr>
        <td style="font-weight:600;font-size:11px;">${r.date || r.label || ''}</td>
        <td style="text-align:center;">${r.nb_ventes || '—'}</td>
        <td style="text-align:right;font-family:'DM Mono',monospace;">${fmtXAF(ca)}</td>
        <td style="text-align:right;font-family:'DM Mono',monospace;color:#dc2626;">${fmtXAF(ct)}</td>
        <td style="text-align:right;font-family:'DM Mono',monospace;font-weight:700;color:${mg>=0?'#16a34a':'#dc2626'};">${fmtXAF(mg)}</td>
        <td style="text-align:right;font-family:'DM Mono',monospace;color:#d97706;">${fmtXAF(rm)}</td>
        <td style="text-align:right;font-family:'DM Mono',monospace;font-weight:700;color:#1d4ed8;">${fmtXAF(mn)}</td>
        <td style="text-align:center;">${margeBadge(t)}</td>
        <td style="text-align:center;">${tendanceMarge(mg, data[i-1]?.marge)}</td>
      </tr>`;
  });
  // Cumul
  html += `
    <tr style="background:#f0f9ff;border-top:2px solid #1d4ed8;">
      <td colspan="2" style="padding:8px 10px;font-weight:800;">CUMUL PERIODE</td>
      <td style="padding:8px 10px;text-align:right;font-family:'DM Mono',monospace;font-weight:800;color:#1d4ed8;">${fmtXAF(cumCA)}</td>
      <td style="padding:8px 10px;text-align:right;font-family:'DM Mono',monospace;color:#dc2626;">${fmtXAF(cumCA - cumMG)}</td>
      <td style="padding:8px 10px;text-align:right;font-family:'DM Mono',monospace;font-weight:800;color:#16a34a;">${fmtXAF(cumMG)}</td>
      <td colspan="2"></td>
      <td style="padding:8px 10px;text-align:center;">${margeBadge(cumCA > 0 ? cumMG/cumCA*100 : 0)}</td>
      <td></td>
    </tr>`;
  html += '</tbody></table></div></div>';
  $('#marges-content').html(html);
}


function formatMoney(num) {
    return new Intl.NumberFormat('fr-FR', { style: 'currency', currency: 'XAF' }).format(num);
}

function generateHeaders(type) {
    const headers = {
        'jours': '<th>Date</th><th>Chiffre d\'Affaires</th><th>Marge brute</th><th>Rentabilité</th>',
        'produits': '<th>Désignation</th><th>Quantité vendue</th><th>CA</th><th>Marge</th>',
        'familles': '<th>Catégorie</th><th>Volume</th><th>CA Total</th><th>Marge</th>'
    };
    return `<tr>${headers[type] || headers['jours']}</tr>`;
}

function generateRows(type, data) {
    return data.map(item => {
        const ca = parseFloat(item.ca || 0);
        const marge = parseFloat(item.marge || 0);
        const taux = ca > 0 ? ((marge / ca) * 100).toFixed(1) : 0;
        
        return `
            <tr>
                <td>${item.date || item.nom_commercial || item.nom_famille || item.mois}</td>
                <td>${formatMoney(ca)}</td>
                <td class="fw-bold">${formatMoney(marge)}</td>
                <td><span class="badge-marge ${marge >= 0 ? 'positive' : 'negative'}">${taux}%</span></td>
            </tr>`;
    }).join('');
}

function generateQuickStats(data) {
    const totalCA = data.reduce((sum, item) => sum + parseFloat(item.ca), 0);
    const totalMarge = data.reduce((sum, item) => sum + parseFloat(item.marge), 0);
    
    return `
        <div class="stat-card">
            <h4>CA Total Période</h4>
            <div class="value">${formatMoney(totalCA)}</div>
        </div>
        <div class="stat-card" style="border-left-color: #1cc88a;">
            <h4>Marge Totale</h4>
            <div class="value">${formatMoney(totalMarge)}</div>
        </div>
        <div class="stat-card" style="border-left-color: #36b9cc;">
            <h4>Taux Moyen</h4>
            <div class="value">${totalCA > 0 ? ((totalMarge/totalCA)*100).toFixed(2) : 0}%</div>
        </div>
    `;
}
chargerStatsJour()
setInterval(refreshHisto, 30000);
updateAttenteCount();
</script>
</body>
</html>