<?php
session_start();
require_once 'db.php';
if (!isset($_SESSION['user_id'])) { header('Location: login.php'); exit(); }

$checkCaisse = $pdo->query("SELECT id_session FROM sessions_caisse WHERE statut = 'ouvert' LIMIT 1")->fetch();
if (!$checkCaisse) { header('Location: caisse.php?error=no_session'); exit(); }
$id_session = $checkCaisse['id_session'];

/* ════════════════════════════════════════════════════════════════
   AJAX HANDLERS  (appelés en POST avec ?action=...)
════════════════════════════════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['action'])) {
    header('Content-Type: application/json; charset=utf-8');

    $action = $_GET['action'];

    /* ── liste ── */
    if ($action === 'list') {
        $where  = ['1=1'];
        $params = [];

        if (!empty($_POST['search'])) {
            $where[]  = '(libelle_operation LIKE :search OR commentaire LIKE :search2 OR CAST(montant AS CHAR) LIKE :search3)';
            $params[':search']  = '%'.$_POST['search'].'%';
            $params[':search2'] = '%'.$_POST['search'].'%';
            $params[':search3'] = '%'.$_POST['search'].'%';
        }
        if (!empty($_POST['date_from'])) {
            $where[] = 'date_operation >= :df';
            $params[':df'] = $_POST['date_from'];
        }
        if (!empty($_POST['date_to'])) {
            $where[] = 'date_operation <= :dt';
            $params[':dt'] = $_POST['date_to'];
        }
        if (!empty($_POST['mode'])) {
            $where[] = 'mode_paiement = :mode';
            $params[':mode'] = $_POST['mode'];
        }
        if (!empty($_POST['compte'])) {
            $where[] = 'code_compte = :compte';
            $params[':compte'] = $_POST['compte'];
        }

        $allowedSort = ['date_operation','libelle_operation','montant','code_compte'];
        $sortKey = in_array($_POST['sort_key'] ?? '', $allowedSort) ? $_POST['sort_key'] : 'date_operation';
        $sortDir = ($_POST['sort_dir'] ?? 'desc') === 'asc' ? 'ASC' : 'DESC';

        $sql = 'SELECT * FROM charges WHERE '.implode(' AND ', $where)." ORDER BY $sortKey $sortDir";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        /* KPIs */
        $now      = new DateTime();
        $y        = $now->format('Y');
        $m        = $now->format('m');
        $weekAgo  = (clone $now)->modify('-7 days')->format('Y-m-d');
        $todayStr = $now->format('Y-m-d');

        $kpiStmt = $pdo->query("SELECT date_operation, montant, code_compte, libelle_operation FROM charges");
        $all     = $kpiStmt->fetchAll(PDO::FETCH_ASSOC);

        $totalMois  = 0; $countMois  = 0;
        $totalSem   = 0; $countSem   = 0;
        $maxVal     = 0; $maxLib     = '--';
        $comptes    = [];

        foreach ($all as $c) {
            $d = $c['date_operation'];
            if (substr($d,0,4)===$y && substr($d,5,2)===$m) { $totalMois+=$c['montant']; $countMois++; }
            if ($d >= $weekAgo && $d <= $todayStr)           { $totalSem +=$c['montant']; $countSem++; }
            if ($c['montant'] > $maxVal) { $maxVal=$c['montant']; $maxLib=$c['libelle_operation']; }
            $comptes[$c['code_compte']] = ($comptes[$c['code_compte']] ?? 0) + $c['montant'];
        }

        arsort($comptes);
        $topComptes = array_slice($comptes, 0, 6, true);

        /* libelles des comptes pour les barres */
        $libMap = [];
        if ($topComptes) {
            $inKeys = implode(',', array_fill(0, count($topComptes), '?'));
            $lStmt  = $pdo->prepare("SELECT code_compte, libelle FROM compte_charges WHERE code_compte IN ($inKeys)");
            $lStmt->execute(array_keys($topComptes));
            foreach ($lStmt->fetchAll(PDO::FETCH_ASSOC) as $l) {
                $libMap[$l['code_compte']] = $l['libelle'];
            }
        }

        echo json_encode([
            'success' => true,
            'charges' => $rows,
            'kpi'     => [
                'total_mois'  => $totalMois,
                'count_mois'  => $countMois,
                'total_sem'   => $totalSem,
                'count_sem'   => $countSem,
                'nb_comptes'  => count($comptes),
                'max_val'     => $maxVal,
                'max_lib'     => $maxLib,
            ],
            'bars'    => array_map(fn($code, $total) => [
                'code'  => $code,
                'total' => $total,
                'lib'   => $libMap[$code] ?? $code,
            ], array_keys($topComptes), $topComptes),
        ]);
        exit();
    }

    /* ── create ── */
    if ($action === 'create') {
        $date    = $_POST['date_operation']    ?? '';
        $libelle = trim($_POST['libelle_operation'] ?? '');
        $montant = floatval($_POST['montant']  ?? 0);
        $compte  = $_POST['code_compte']       ?? '';
        $mode    = $_POST['mode_paiement']     ?? '';
        $comment = trim($_POST['commentaire']  ?? '');

        if (!$date || !$libelle || $montant <= 0 || !$compte || !$mode) {
            echo json_encode(['success'=>false,'message'=>'Champs obligatoires manquants.','data'=>$date]); exit();
        }

        $stmt = $pdo->prepare("INSERT INTO charges (date_operation, libelle_operation, montant, code_compte, mode_paiement, commentaire, created_at) VALUES (:d,:l,:m,:c,:mp,:co, NOW())");
        $stmt->execute([':d'=>$date,':l'=>$libelle,':m'=>$montant,':c'=>$compte,':mp'=>$mode,':co'=>$comment]);
        $newId = $pdo->lastInsertId();

        /* log activite */
        $pdo->prepare("INSERT INTO logs_activites (utilisateur, action_type, description, date_action, ip_adresse) VALUES (:u,'CHARGE_CREATE',:desc,NOW(),:ip)")
            ->execute([':u'=>$_SESSION['user_id'],':desc'=>"Charge creee: $libelle ($montant FCFA)",':ip'=>$_SERVER['REMOTE_ADDR']??'']);

        echo json_encode(['success'=>true,'id'=>$newId,'message'=>'Charge enregistree avec succes.']); exit();
    }

    /* ── update ── */
    if ($action === 'update') {
        $id      = intval($_POST['id_charge']        ?? 0);
        $date    = $_POST['date_operation']           ?? '';
        $libelle = trim($_POST['libelle_operation']   ?? '');
        $montant = floatval($_POST['montant']         ?? 0);
        $compte  = $_POST['code_compte']              ?? '';
        $mode    = $_POST['mode_paiement']            ?? '';
        $comment = trim($_POST['commentaire']         ?? '');

        if (!$id || !$date || !$libelle || $montant <= 0 || !$compte || !$mode) {
            echo json_encode(['success'=>false,'message'=>'Champs obligatoires manquants.']); exit();
        }

        $stmt = $pdo->prepare("UPDATE charges SET date_operation=:d, libelle_operation=:l, montant=:m, code_compte=:c, mode_paiement=:mp, commentaire=:co WHERE id_charge=:id");
        $stmt->execute([':d'=>$date,':l'=>$libelle,':m'=>$montant,':c'=>$compte,':mp'=>$mode,':co'=>$comment,':id'=>$id]);

        $pdo->prepare("INSERT INTO logs_activites (utilisateur, action_type, description, date_action, ip_adresse) VALUES (:u,'CHARGE_UPDATE',:desc,NOW(),:ip)")
            ->execute([':u'=>$_SESSION['user_id'],':desc'=>"Charge #$id modifiee: $libelle",':ip'=>$_SERVER['REMOTE_ADDR']??'']);

        echo json_encode(['success'=>true,'message'=>'Charge mise a jour avec succes.']); exit();
    }

    /* ── delete ── */
    if ($action === 'delete') {
        $id = intval($_POST['id_charge'] ?? 0);
        if (!$id) { echo json_encode(['success'=>false,'message'=>'ID invalide.']); exit(); }

        $row = $pdo->prepare("SELECT libelle_operation FROM charges WHERE id_charge=?");
        $row->execute([$id]);
        $lib = $row->fetchColumn() ?: "ID $id";

        $pdo->prepare("DELETE FROM charges WHERE id_charge=?")->execute([$id]);

        $pdo->prepare("INSERT INTO logs_activites (utilisateur, action_type, description, date_action, ip_adresse) VALUES (:u,'CHARGE_DELETE',:desc,NOW(),:ip)")
            ->execute([':u'=>$_SESSION['user_id'],':desc'=>"Charge supprimee: $lib",':ip'=>$_SERVER['REMOTE_ADDR']??'']);

        echo json_encode(['success'=>true,'message'=>'Charge supprimee.']); exit();
    }

    /* ── export csv ── */
    if ($action === 'export') {
        $stmt = $pdo->query("SELECT id_charge,date_operation,libelle_operation,montant,code_compte,mode_paiement,commentaire,created_at FROM charges ORDER BY date_operation DESC");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="charges_export_'.date('Ymd').'.csv"');
        $out = fopen('php://output','w');
        fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF));
        fputcsv($out, ['ID','Date','Libelle','Montant','Compte','Mode paiement','Commentaire','Cree le']);
        foreach ($rows as $r) fputcsv($out, $r);
        fclose($out);
        exit();
    }

    echo json_encode(['success'=>false,'message'=>'Action inconnue.']); exit();
}

/* ── Charger plan comptable depuis compte_charges ── */
$pcStmt   = $pdo->query("SELECT code_compte, libelle, parent FROM compte_charges ORDER BY code_compte ASC");
$planRows = $pcStmt->fetchAll(PDO::FETCH_ASSOC);

/* Construire structure hiérarchique parent → enfants */
$planParents  = [];
$planChildren = [];
foreach ($planRows as $p) {
    if (!$p['parent']) {
        $planParents[$p['code_compte']] = $p['libelle'];
    } else {
        $planChildren[$p['parent']][] = $p;
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<title>PharmAssist - Charges</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
<style>
:root {
  --bg:        #f0f2f5;
  --surface:   #ffffff;
  --surface2:  #f8f9fa;
  --surface3:  #f3f4f6;
  --sidebar:   #111827;
  --sidebar-w: 200px;

  --primary:   #1d4ed8;
  --accent:    #00c9a7;
  --accent2:   #3b82f6;
  --accent-dim:  rgba(0,201,167,0.1);
  --accent2-dim: rgba(59,130,246,0.1);
  --warn:      #f59e0b;
  --warn-dim:  rgba(245,158,11,0.1);
  --danger:    #f43f5e;
  --danger-dim: rgba(244,63,94,0.1);

  --text:      #111827;
  --text-sub:  #374151;
  --text-muted:#9ca3af;
  --border:    #e5e7eb;
  --border-light: #d1d5db;

  --radius:    8px;
  --radius-sm: 6px;
  --shadow:    0 1px 3px rgba(0,0,0,.07), 0 1px 2px rgba(0,0,0,.04);
}

*,*::before,*::after { box-sizing:border-box; margin:0; padding:0; }
html,body { height:100%; }
body {
  font-family:'DM Sans',sans-serif;
  font-size:13px;
  line-height:1.4;
  background:var(--bg);
  color:var(--text);
  display:flex;
  overflow:hidden;
}

/* ── SIDEBAR ── */
.sidebar {
  width:var(--sidebar-w);
  background:var(--sidebar);
  height:100vh;
  position:fixed;
  top:0; left:0;
  display:flex; flex-direction:column;
  z-index:100; flex-shrink:0;
}
.sidebar-logo { padding:16px 14px 12px; border-bottom:1px solid rgba(255,255,255,.06); }
.logo-mark { display:flex; align-items:center; gap:8px; }
.logo-icon { width:28px; height:28px; background:var(--primary); border-radius:6px; display:flex; align-items:center; justify-content:center; color:white; font-size:13px; flex-shrink:0; }
.logo-text { font-size:13px; font-weight:600; color:#f9fafb; letter-spacing:-.2px; }
.logo-sub  { font-size:9px; color:rgba(255,255,255,.35); margin-top:1px; text-transform:uppercase; letter-spacing:.5px; }
.sidebar-nav { flex:1; padding:10px 0; list-style:none; }
.sidebar-nav li a {
  display:flex; align-items:center; gap:9px;
  padding:8px 14px; color:rgba(255,255,255,.55);
  text-decoration:none; font-size:11.5px; font-weight:400;
  border-left:2px solid transparent; transition:all .15s;
}
.sidebar-nav li a i { width:14px; text-align:center; font-size:11px; flex-shrink:0; }
.sidebar-nav li a:hover { color:rgba(255,255,255,.85); background:rgba(255,255,255,.04); }
.sidebar-nav li a.active { color:#fff; background:rgba(255,255,255,.07); border-left-color:var(--primary); font-weight:500; }
.sidebar-nav li a.nav-danger { color:rgba(239,68,68,.7); }
.sidebar-nav li a.nav-danger:hover { color:#ef4444; background:rgba(239,68,68,.06); }
.sidebar-footer { padding:12px 14px; border-top:1px solid rgba(255,255,255,.06); }
.session-pill { display:flex; align-items:center; gap:7px; background:rgba(255,255,255,.05); border-radius:6px; padding:7px 10px; }
.session-dot { width:6px; height:6px; border-radius:50%; background:#22c55e; box-shadow:0 0 0 2px rgba(34,197,94,.25); flex-shrink:0; }
.session-label { font-size:10px; color:rgba(255,255,255,.4); }
.session-val { font-size:10.5px; color:rgba(255,255,255,.8); font-weight:500; font-family:'DM Mono',monospace; }

/* ── LAYOUT ── */
.main-sales { margin-left:var(--sidebar-w); display:flex; width:calc(100% - var(--sidebar-w)); height:100vh; overflow:hidden; }
.content-area { flex:1; padding:20px; overflow-y:auto; display:flex; flex-direction:column; gap:16px; min-width:0; }

/* ── PAGE HEADER ── */
.page-header { display:flex; align-items:flex-start; justify-content:space-between; gap:16px; }
.page-title-wrap h1 { font-size:1.5rem; font-weight:700; letter-spacing:-.5px; color:var(--text); line-height:1.2; }
.page-title-wrap p { font-size:0.82rem; color:var(--text-muted); margin-top:4px; }
.header-actions { display:flex; gap:10px; flex-shrink:0; }

/* ── BUTTONS ── */
.btn {
  display:inline-flex; align-items:center; gap:7px;
  padding:9px 18px; border-radius:var(--radius-sm);
  font-family:'DM Sans',sans-serif; font-size:0.83rem; font-weight:500;
  cursor:pointer; border:none; transition:all .2s; white-space:nowrap;
}
.btn-primary { background:var(--accent); color:#0d1117; }
.btn-primary:hover { background:#00b896; transform:translateY(-1px); box-shadow:0 4px 16px rgba(0,201,167,.3); }
.btn-outline { background:transparent; color:var(--text-sub); border:1px solid var(--border-light); }
.btn-outline:hover { color:var(--text); border-color:var(--text-sub); background:var(--surface3); }
.btn-sm { padding:6px 12px; font-size:0.76rem; }

/* ── KPI CARDS ── */
.kpi-grid { display:grid; grid-template-columns:repeat(4,1fr); gap:14px; }
.kpi-card { background:var(--surface); border:1px solid var(--border); border-radius:var(--radius); padding:18px 20px; position:relative; overflow:hidden; transition:border-color .2s; }
.kpi-card::before { content:''; position:absolute; inset:0; opacity:0; transition:opacity .3s; }
.kpi-card:hover::before { opacity:1; }
.kpi-card.red::before   { background:radial-gradient(ellipse at top left,rgba(244,63,94,.06),transparent 60%); }
.kpi-card.warn::before  { background:radial-gradient(ellipse at top left,rgba(245,158,11,.06),transparent 60%); }
.kpi-card.blue::before  { background:radial-gradient(ellipse at top left,rgba(59,130,246,.06),transparent 60%); }
.kpi-card.green::before { background:radial-gradient(ellipse at top left,rgba(0,201,167,.06),transparent 60%); }
.kpi-label { font-size:0.72rem; font-weight:500; color:var(--text-muted); text-transform:uppercase; letter-spacing:.8px; margin-bottom:10px; }
.kpi-value { font-weight:700; font-size:1.5rem; letter-spacing:-1px; line-height:1; }
.kpi-value.red   { color:var(--danger); }
.kpi-value.warn  { color:var(--warn); }
.kpi-value.blue  { color:var(--accent2); }
.kpi-value.green { color:var(--accent); }
.kpi-sub { font-size:0.72rem; color:var(--text-muted); margin-top:6px; }
.kpi-icon { position:absolute; top:16px; right:16px; opacity:.1; }

/* ── FILTERS BAR ── */
.filters-bar { background:var(--surface); border:1px solid var(--border); border-radius:var(--radius); padding:14px 18px; display:flex; gap:12px; align-items:flex-end; flex-wrap:wrap; }
.filter-group { display:flex; flex-direction:column; gap:4px; min-width:130px; }
.filter-label { font-family:'DM Mono',monospace; font-size:0.63rem; letter-spacing:1px; text-transform:uppercase; color:var(--text-muted); }
.filter-input { background:var(--surface2); border:1px solid var(--border); border-radius:var(--radius-sm); padding:7px 11px; font-family:'DM Sans',sans-serif; font-size:0.82rem; color:var(--text); outline:none; transition:border-color .2s; width:100%; }
.filter-input:focus { border-color:var(--accent); }
.filter-search { flex:1; min-width:200px; }

/* ── BOTTOM GRID ── */
.bottom-grid { display:grid; grid-template-columns:1fr 300px; gap:14px; }

/* ── TABLE ── */
.table-wrap { background:var(--surface); border:1px solid var(--border); border-radius:var(--radius); overflow:hidden; }
.table-header { padding:14px 20px; border-bottom:1px solid var(--border); display:flex; align-items:center; justify-content:space-between; }
.table-title { font-weight:600; font-size:0.95rem; }
.table-meta { font-size:0.76rem; color:var(--text-muted); font-family:'DM Mono',monospace; }
table { width:100%; border-collapse:collapse; font-size:0.83rem; }
thead th { background:var(--surface2); padding:10px 16px; text-align:left; font-family:'DM Mono',monospace; font-size:0.65rem; letter-spacing:1px; text-transform:uppercase; color:var(--text-muted); border-bottom:1px solid var(--border); white-space:nowrap; cursor:pointer; user-select:none; transition:color .2s; }
thead th:hover { color:var(--text-sub); }
thead th .sort-icon { margin-left:4px; opacity:.4; font-size:0.6rem; }
tbody tr { border-bottom:1px solid var(--border); transition:background .15s; }
tbody tr:last-child { border-bottom:none; }
tbody tr:hover { background:var(--surface2); }
tbody td { padding:11px 16px; vertical-align:middle; color:var(--text-sub); }
tbody td.primary { color:var(--text); font-weight:500; }
tbody td.mono { font-family:'DM Mono',monospace; font-size:0.78rem; }
.amount-cell { font-family:'DM Mono',monospace; font-weight:500; color:var(--danger); font-size:0.86rem; }

/* ── BADGES ── */
.badge { display:inline-flex; align-items:center; gap:5px; padding:3px 9px; border-radius:20px; font-size:0.7rem; font-weight:500; white-space:nowrap; }
.badge-especes  { background:var(--accent-dim); color:var(--accent); }
.badge-mobile   { background:var(--accent2-dim); color:var(--accent2); }
.badge-cheque   { background:var(--warn-dim); color:var(--warn); }
.badge-virement { background:rgba(139,92,246,.12); color:#a78bfa; }
.badge-dot { width:6px; height:6px; border-radius:50%; background:currentColor; flex-shrink:0; }
.compte-tag { font-family:'DM Mono',monospace; font-size:0.7rem; background:var(--surface3); border:1px solid var(--border-light); padding:2px 7px; border-radius:4px; color:var(--text-sub); }

/* ── ACTION BTNS ── */
.action-btns { display:flex; gap:6px; opacity:0; transition:opacity .15s; }
tbody tr:hover .action-btns { opacity:1; }
.icon-btn { width:28px; height:28px; border-radius:6px; border:1px solid var(--border); background:var(--surface3); color:var(--text-sub); display:flex; align-items:center; justify-content:center; cursor:pointer; transition:all .2s; }
.icon-btn:hover     { border-color:var(--accent); color:var(--accent); }
.icon-btn.del:hover { border-color:var(--danger); color:var(--danger); }

/* ── PAGINATION ── */
.pagination { padding:12px 20px; border-top:1px solid var(--border); display:flex; align-items:center; justify-content:space-between; }
.pagination-info { font-size:0.76rem; color:var(--text-muted); font-family:'DM Mono',monospace; }
.pagination-controls { display:flex; gap:4px; }
.page-btn { width:30px; height:30px; border-radius:6px; border:1px solid var(--border); background:transparent; color:var(--text-sub); font-size:0.78rem; cursor:pointer; display:flex; align-items:center; justify-content:center; transition:all .15s; }
.page-btn:hover  { border-color:var(--accent); color:var(--accent); }
.page-btn.active { background:var(--accent); border-color:var(--accent); color:#0d1117; font-weight:600; }

/* ── EMPTY STATE ── */
.empty-state { padding:60px 20px; text-align:center; color:var(--text-muted); }
.empty-state h3 { font-weight:600; font-size:1rem; color:var(--text-sub); margin-bottom:6px; }
.empty-state p  { font-size:0.8rem; }

/* ── MINI BAR CHART ── */
.mini-bar-wrap { background:var(--surface); border:1px solid var(--border); border-radius:var(--radius); padding:20px; }
.mini-bar-title { font-weight:600; font-size:0.9rem; margin-bottom:16px; color:var(--text); }
.bar-list { display:flex; flex-direction:column; gap:10px; }
.bar-item { display:flex; flex-direction:column; gap:4px; }
.bar-meta { display:flex; justify-content:space-between; align-items:center; }
.bar-name { font-size:0.78rem; color:var(--text-sub); }
.bar-val  { font-family:'DM Mono',monospace; font-size:0.75rem; color:var(--text); }
.bar-track { height:5px; background:var(--surface3); border-radius:99px; overflow:hidden; }
.bar-fill  { height:100%; border-radius:99px; background:linear-gradient(90deg,var(--accent),var(--accent2)); transition:width .6s cubic-bezier(.34,1.56,.64,1); }

/* ── MODAL ── */
.modal-overlay { position:fixed; inset:0; background:rgba(0,0,0,.7); backdrop-filter:blur(4px); z-index:200; display:flex; align-items:center; justify-content:center; padding:20px; opacity:0; pointer-events:none; transition:opacity .25s; }
.modal-overlay.open { opacity:1; pointer-events:all; }
.modal { background:var(--surface); border:1px solid var(--border-light); border-radius:14px; width:100%; max-width:600px; box-shadow:var(--shadow); transform:translateY(16px) scale(.98); transition:transform .25s; overflow:hidden; max-height:90vh; display:flex; flex-direction:column; }
.modal-overlay.open .modal { transform:translateY(0) scale(1); }
.modal-header { padding:20px 24px 16px; border-bottom:1px solid var(--border); display:flex; align-items:center; justify-content:space-between; flex-shrink:0; }
.modal-title { font-weight:700; font-size:1.05rem; }
.modal-close { width:30px; height:30px; border-radius:6px; border:1px solid var(--border); background:transparent; color:var(--text-sub); cursor:pointer; display:flex; align-items:center; justify-content:center; transition:all .15s; }
.modal-close:hover { border-color:var(--danger); color:var(--danger); }
.modal-body { padding:22px 24px; display:flex; flex-direction:column; gap:16px; overflow-y:auto; }
.form-grid { display:grid; grid-template-columns:1fr 1fr; gap:14px; }
.form-field { display:flex; flex-direction:column; gap:5px; }
.form-field.span2 { grid-column:span 2; }
.form-label { font-size:0.76rem; font-weight:500; color:var(--text-sub); }
.form-label span { color:var(--danger); }
.form-input { background:var(--surface2); border:1px solid var(--border); border-radius:var(--radius-sm); padding:9px 13px; font-family:'DM Sans',sans-serif; font-size:0.85rem; color:var(--text); outline:none; transition:border-color .2s,box-shadow .2s; width:100%; }
.form-input:focus { border-color:var(--accent); box-shadow:0 0 0 3px rgba(0,201,167,.1); }
.form-input::placeholder { color:var(--text-muted); }
.form-hint { font-size:0.7rem; color:var(--text-muted); }
.modal-footer { padding:16px 24px; border-top:1px solid var(--border); display:flex; gap:10px; justify-content:flex-end; flex-shrink:0; }

/* ── CUSTOM SELECT COMPTE ── */
.cs-wrap { position:relative; }
.cs-display {
  background:var(--surface2); border:1px solid var(--border); border-radius:var(--radius-sm);
  padding:9px 36px 9px 13px; font-size:0.85rem; color:var(--text);
  cursor:pointer; user-select:none; transition:border-color .2s,box-shadow .2s;
  white-space:nowrap; overflow:hidden; text-overflow:ellipsis;
}
.cs-display:focus, .cs-display.open { border-color:var(--accent); box-shadow:0 0 0 3px rgba(0,201,167,.1); outline:none; }
.cs-display.placeholder { color:var(--text-muted); }
.cs-arrow { position:absolute; right:11px; top:50%; transform:translateY(-50%); pointer-events:none; color:var(--text-muted); transition:transform .2s; }
.cs-display.open + .cs-arrow { transform:translateY(-50%) rotate(180deg); }
.cs-dropdown {
  position:absolute; left:0; right:0; top:calc(100% + 4px);
  background:var(--surface); border:1px solid var(--border-light); border-radius:var(--radius-sm);
  box-shadow:0 8px 24px rgba(0,0,0,.12); z-index:999; overflow:hidden;
  display:none; flex-direction:column; max-height:320px;
}
.cs-dropdown.open { display:flex; }
.cs-search-wrap { padding:10px; border-bottom:1px solid var(--border); flex-shrink:0; }
.cs-search {
  width:100%; background:var(--surface2); border:1px solid var(--border);
  border-radius:var(--radius-sm); padding:7px 11px;
  font-family:'DM Sans',sans-serif; font-size:0.82rem; color:var(--text); outline:none;
  transition:border-color .2s;
}
.cs-search:focus { border-color:var(--accent); }
.cs-search::placeholder { color:var(--text-muted); }
.cs-list { overflow-y:auto; flex:1; padding:4px 0; }
.cs-group-label {
  padding:7px 12px 4px; font-family:'DM Mono',monospace;
  font-size:0.62rem; letter-spacing:1px; text-transform:uppercase;
  color:var(--text-muted); background:var(--surface2);
  border-top:1px solid var(--border); position:sticky; top:0; z-index:1;
}
.cs-group-label:first-child { border-top:none; }
.cs-option {
  padding:8px 14px 8px 22px; font-size:0.82rem; color:var(--text-sub);
  cursor:pointer; transition:background .12s; display:flex; align-items:center; gap:8px;
}
.cs-option:hover, .cs-option.focused { background:var(--accent-dim); color:var(--text); }
.cs-option.selected { color:var(--accent); font-weight:500; }
.cs-option-code { font-family:'DM Mono',monospace; font-size:0.7rem; background:var(--surface3); border:1px solid var(--border-light); padding:1px 6px; border-radius:3px; flex-shrink:0; }
.cs-no-results { padding:20px; text-align:center; color:var(--text-muted); font-size:0.82rem; }

/* ── TOAST ── */
.toast-container { position:fixed; bottom:24px; right:24px; z-index:500; display:flex; flex-direction:column; gap:10px; }
.toast { background:var(--surface2); border:1px solid var(--border-light); border-left:3px solid var(--accent); border-radius:var(--radius-sm); padding:12px 16px; font-size:0.82rem; color:var(--text); box-shadow:var(--shadow); display:flex; align-items:center; gap:10px; min-width:260px; animation:slideIn .3s ease, fadeOut .4s ease 2.6s forwards; }
.toast.error { border-left-color:var(--danger); }
@keyframes slideIn { from { transform:translateX(20px); opacity:0; } to { transform:translateX(0); opacity:1; } }
@keyframes fadeOut { to { opacity:0; transform:translateX(10px); } }

/* ── LOADING OVERLAY ── */
.loading-row td { text-align:center; padding:30px; color:var(--text-muted); font-size:0.82rem; }
.spinner { width:20px; height:20px; border:2px solid var(--border); border-top-color:var(--accent); border-radius:50%; animation:spin .7s linear infinite; display:inline-block; vertical-align:middle; margin-right:8px; }
@keyframes spin { to { transform:rotate(360deg); } }

@media (max-width:1100px) {
  .kpi-grid { grid-template-columns:repeat(2,1fr); }
  .bottom-grid { grid-template-columns:1fr; }
  .sidebar { display:none; }
  .main-sales { margin-left:0; width:100%; }
}
@media (max-width:640px) {
  .form-grid { grid-template-columns:1fr; }
  .form-field.span2 { grid-column:span 1; }
}
</style>
</head>
<body>

<!-- SIDEBAR -->
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
    <li><a href="ventes.php"><i class="fas fa-shopping-cart"></i>Ventes</a></li>
    <li><a href="caisse.php"><i class="fas fa-cash-register"></i>Caisse</a></li>
    <li><a href="produits_gestion.php"><i class="fas fa-boxes"></i>Stocks &amp; Flux</a></li>
    <li><a href="charges.php" class="active"><i class="fas fa-file-invoice-dollar"></i>Charges</a></li>
    <li><a href="logout.php" class="nav-danger"><i class="fas fa-sign-out-alt"></i>Deconnexion</a></li>
  </ul>
  <div class="sidebar-footer">
    <div class="session-pill">
      <div class="session-dot"></div>
      <div>
        <div class="session-label">Session active</div>
        <div class="session-val">#<?= htmlspecialchars($id_session) ?></div>
      </div>
    </div>
  </div>
</nav>

<!-- MAIN -->
<div class="main-sales">
  <div class="content-area">

    <!-- PAGE HEADER -->
    <div class="page-header">
      <div class="page-title-wrap">
        <h1>Gestion des Charges</h1>
        <p>Suivi des depenses operationnelles et charges comptables</p>
      </div>
      <div class="header-actions">
        <button class="btn btn-outline" onclick="exportCSV()">
          <svg width="13" height="13" viewBox="0 0 13 13" fill="none">
            <path d="M6.5 1v8M4 6l2.5 3 2.5-3M1.5 10v.5a.5.5 0 00.5.5h8a.5.5 0 00.5-.5V10" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
          </svg>
          Exporter CSV
        </button>
        <button class="btn btn-primary" onclick="openModal()">
          <svg width="13" height="13" viewBox="0 0 13 13" fill="none">
            <path d="M6.5 2v9M2 6.5h9" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
          </svg>
          Nouvelle charge
        </button>
      </div>
    </div>

    <!-- KPI CARDS -->
    <div class="kpi-grid">
      <div class="kpi-card red">
        <div class="kpi-icon">
          <svg width="48" height="48" viewBox="0 0 48 48" fill="none">
            <path d="M8 24h32M24 8l16 16-16 16" stroke="#f43f5e" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/>
          </svg>
        </div>
        <div class="kpi-label">Total Charges (mois)</div>
        <div class="kpi-value red" id="kpi-mois">--</div>
        <div class="kpi-sub" id="kpi-mois-sub">-- operations</div>
      </div>
      <div class="kpi-card warn">
        <div class="kpi-icon">
          <svg width="48" height="48" viewBox="0 0 48 48" fill="none">
            <circle cx="24" cy="24" r="16" stroke="#f59e0b" stroke-width="3"/>
            <path d="M24 14v10l5 4" stroke="#f59e0b" stroke-width="3" stroke-linecap="round"/>
          </svg>
        </div>
        <div class="kpi-label">Charges (7 derniers jours)</div>
        <div class="kpi-value warn" id="kpi-semaine">--</div>
        <div class="kpi-sub" id="kpi-sem-sub">-- operations</div>
      </div>
      <div class="kpi-card blue">
        <div class="kpi-icon">
          <svg width="48" height="48" viewBox="0 0 48 48" fill="none">
            <rect x="8" y="8" width="32" height="32" rx="4" stroke="#3b82f6" stroke-width="3"/>
            <path d="M16 24h16M16 31h10" stroke="#3b82f6" stroke-width="2.5" stroke-linecap="round"/>
          </svg>
        </div>
        <div class="kpi-label">Comptes utilises</div>
        <div class="kpi-value blue" id="kpi-comptes">--</div>
        <div class="kpi-sub">Comptes distincts</div>
      </div>
      <div class="kpi-card green">
        <div class="kpi-icon">
          <svg width="48" height="48" viewBox="0 0 48 48" fill="none">
            <path d="M12 36V22M20 36V16M28 36V26M36 36V12" stroke="#00c9a7" stroke-width="3" stroke-linecap="round"/>
          </svg>
        </div>
        <div class="kpi-label">Charge maximale</div>
        <div class="kpi-value green" id="kpi-max">--</div>
        <div class="kpi-sub" id="kpi-max-sub">--</div>
      </div>
    </div>

    <!-- FILTERS BAR -->
    <div class="filters-bar">
      <div class="filter-group filter-search">
        <div class="filter-label">Rechercher</div>
        <input type="text" class="filter-input" id="search-input" placeholder="Libelle, montant, commentaire..." oninput="scheduleFilter()"/>
      </div>
      <div class="filter-group">
        <div class="filter-label">Debut</div>
        <input type="date" class="filter-input" id="filter-from" onchange="loadCharges()"/>
      </div>
      <div class="filter-group">
        <div class="filter-label">Fin</div>
        <input type="date" class="filter-input" id="filter-to" onchange="loadCharges()"/>
      </div>
      <div class="filter-group">
        <div class="filter-label">Mode paiement</div>
        <select class="filter-input" id="filter-mode" onchange="loadCharges()">
          <option value="">Tous</option>
          <option value="especes">Especes</option>
          <option value="mobile_money">Mobile Money</option>
          <option value="cheque">Cheque</option>
          <option value="virement">Virement</option>
        </select>
      </div>
      <div class="filter-group">
        <div class="filter-label">Compte</div>
        <select class="filter-input" id="filter-compte" onchange="loadCharges()">
          <option value="">Tous les comptes</option>
          <?php foreach($planParents as $code => $lib): ?>
            <optgroup label="<?= htmlspecialchars($code.' - '.substr($lib,0,30)) ?>">
              <?php if(!isset($planChildren[$code])): ?>
                <option value="<?= htmlspecialchars($code) ?>"><?= htmlspecialchars($code.' - '.$lib) ?></option>
              <?php endif; ?>
              <?php if(isset($planChildren[$code])): foreach($planChildren[$code] as $child): ?>
                <option value="<?= htmlspecialchars($child['code_compte']) ?>"><?= htmlspecialchars($child['code_compte'].' - '.$child['libelle']) ?></option>
              <?php endforeach; endif; ?>
            </optgroup>
          <?php endforeach; ?>
        </select>
      </div>
      <div style="display:flex;gap:8px;align-items:flex-end;">
        <button class="btn btn-outline btn-sm" onclick="resetFilters()">Reinitialiser</button>
      </div>
    </div>

    <!-- BOTTOM GRID -->
    <div class="bottom-grid">
      <!-- TABLE -->
      <div class="table-wrap">
        <div class="table-header">
          <span class="table-title">Registre des charges</span>
          <span class="table-meta" id="table-count">--</span>
        </div>
        <table>
          <thead>
            <tr>
              <th onclick="sortTable('date_operation')">Date <span class="sort-icon" id="sort-date_operation">v</span></th>
              <th onclick="sortTable('libelle_operation')">Libelle <span class="sort-icon" id="sort-libelle_operation">v</span></th>
              <th>Compte</th>
              <th onclick="sortTable('montant')">Montant <span class="sort-icon" id="sort-montant">v</span></th>
              <th>Mode</th>
              <th>Commentaire</th>
              <th></th>
            </tr>
          </thead>
          <tbody id="charges-tbody">
            <tr class="loading-row"><td colspan="7"><span class="spinner"></span>Chargement...</td></tr>
          </tbody>
        </table>
        <div id="empty-state" class="empty-state" style="display:none;">
          <div style="margin-bottom:12px;opacity:.3;">
            <svg width="48" height="48" viewBox="0 0 48 48" fill="none">
              <rect x="8" y="8" width="32" height="32" rx="6" stroke="#64748b" stroke-width="2"/>
              <path d="M16 24h16M16 30h10" stroke="#64748b" stroke-width="2" stroke-linecap="round"/>
            </svg>
          </div>
          <h3>Aucune charge trouvee</h3>
          <p>Modifiez vos filtres ou ajoutez une nouvelle charge.</p>
        </div>
        <div class="pagination">
          <span class="pagination-info" id="pagination-info">--</span>
          <div class="pagination-controls" id="pagination-controls"></div>
        </div>
      </div>

      <!-- MINI BAR CHART -->
      <div class="mini-bar-wrap">
        <div class="mini-bar-title">Repartition par compte</div>
        <div class="bar-list" id="bar-list">
          <div style="color:var(--text-muted);font-size:0.8rem;">Chargement...</div>
        </div>
      </div>
    </div>

  </div><!-- end content-area -->
</div><!-- end main-sales -->

<!-- ════════════════ MODAL ════════════════ -->
<div class="modal-overlay" id="modal-overlay">
  <div class="modal" role="dialog" aria-modal="true" aria-labelledby="modal-title-text">
    <div class="modal-header">
      <span class="modal-title" id="modal-title-text">Nouvelle charge</span>
      <button class="modal-close" onclick="closeModal()" aria-label="Fermer">
        <svg width="12" height="12" viewBox="0 0 12 12" fill="none">
          <path d="M1 1l10 10M11 1L1 11" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>
        </svg>
      </button>
    </div>
    <div class="modal-body">
      <div class="form-grid">
        <div class="form-field">
          <label class="form-label" for="f-date">Date operation <span>*</span></label>
          <input type="date" id="f-date" class="form-input" required/>
        </div>
        <div class="form-field">
          <label class="form-label" for="f-montant">Montant (FCFA) <span>*</span></label>
          <input type="number" id="f-montant" class="form-input" placeholder="0" min="0" step="1" required/>
        </div>
        <div class="form-field span2">
          <label class="form-label" for="f-libelle">Libelle de l'operation <span>*</span></label>
          <input type="text" id="f-libelle" class="form-input" placeholder="Ex: Loyer mensuel, Electricite..." required/>
        </div>

        <!-- CUSTOM SELECT COMPTE -->
        <div class="form-field">
          <label class="form-label">Compte de charges <span>*</span></label>
          <input type="hidden" id="f-compte-value"/>
          <div class="cs-wrap" id="cs-wrap-modal">
            <div class="cs-display placeholder" id="cs-display-modal" tabindex="0" role="combobox" aria-expanded="false" aria-haspopup="listbox">
              -- Choisir un compte --
            </div>
            <svg class="cs-arrow" width="12" height="12" viewBox="0 0 12 12" fill="none">
              <path d="M2 4l4 4 4-4" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
            <div class="cs-dropdown" id="cs-dropdown-modal" role="listbox">
              <div class="cs-search-wrap">
                <input type="text" class="cs-search" id="cs-search-modal" placeholder="Rechercher un compte..." autocomplete="off"/>
              </div>
              <div class="cs-list" id="cs-list-modal"></div>
            </div>
          </div>
          <span class="form-hint">Selon le plan comptable OHADA</span>
        </div>

        <div class="form-field">
          <label class="form-label" for="f-mode">Mode de paiement <span>*</span></label>
          <select id="f-mode" class="form-input" required>
            <option value="">-- Choisir --</option>
            <option value="especes">Especes</option>
            <option value="mobile_money">Mobile Money</option>
            <option value="cheque">Cheque</option>
            <option value="virement">Virement bancaire</option>
          </select>
        </div>
        <div class="form-field span2">
          <label class="form-label" for="f-commentaire">Commentaire</label>
          <input type="text" id="f-commentaire" class="form-input" placeholder="Precisions supplementaires..."/>
        </div>
      </div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-outline" onclick="closeModal()">Annuler</button>
      <button class="btn btn-primary" id="btn-save" onclick="saveCharge()">
        <svg width="13" height="13" viewBox="0 0 13 13" fill="none">
          <path d="M2 7l3.5 3.5L11 3" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
        </svg>
        Enregistrer
      </button>
    </div>
  </div>
</div>

<!-- TOAST CONTAINER -->
<div class="toast-container" id="toast-container"></div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
/* ═══════════════════════════════════════════════════════
   PLAN COMPTABLE COMPLET (pour le custom select)
   Injecte depuis PHP
═══════════════════════════════════════════════════════ */
const PLAN_COMPTABLE = <?php
  /* Reconstruire la structure pour JS */
  $jsGroups = [];
  foreach ($planParents as $pCode => $pLib) {
      $group = ['code' => $pCode, 'libelle' => $pLib, 'children' => []];
      if (isset($planChildren[$pCode])) {
          foreach ($planChildren[$pCode] as $ch) {
              $group['children'][] = ['code' => $ch['code_compte'], 'libelle' => $ch['libelle']];
          }
      }
      $jsGroups[] = $group;
  }
  echo json_encode($jsGroups, JSON_UNESCAPED_UNICODE);
?>;

/* ═══════════════════════════════════════════════════════
   STATE
═══════════════════════════════════════════════════════ */
let allCharges   = [];
let filteredData = [];
let sortKey      = 'date_operation';
let sortDir      = 'desc';
let currentPage  = 1;
const PER_PAGE   = 8;
let editId       = null;
let filterTimer  = null;

/* ═══════════════════════════════════════════════════════
   INIT
═══════════════════════════════════════════════════════ */
document.addEventListener('DOMContentLoaded', () => {
  setDefaultDates();
  buildCustomSelect('modal');
  loadCharges();
});

function setDefaultDates() {
  const now = new Date();
  const y   = now.getFullYear();
  const m   = String(now.getMonth()+1).padStart(2,'0');
  const last = new Date(y, now.getMonth()+1, 0).getDate();
  document.getElementById('filter-from').value = y+'-'+m+'-01';
  document.getElementById('filter-to').value   = y+'-'+m+'-'+String(last).padStart(2,'0');
}

/* ═══════════════════════════════════════════════════════
   LOAD CHARGES (AJAX)
═══════════════════════════════════════════════════════ */
function loadCharges() {
  const tbody = document.getElementById('charges-tbody');
  tbody.innerHTML = '<tr class="loading-row"><td colspan="7"><span class="spinner"></span>Chargement...</td></tr>';
  document.getElementById('empty-state').style.display = 'none';

  const params = new URLSearchParams({
    search:    document.getElementById('search-input').value.trim(),
    date_from: document.getElementById('filter-from').value,
    date_to:   document.getElementById('filter-to').value,
    mode:      document.getElementById('filter-mode').value,
    compte:    document.getElementById('filter-compte').value,
    sort_key:  sortKey,
    sort_dir:  sortDir,
  });

  fetch('charges.php?action=list', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: params.toString(),
  })
  .then(r => r.json())
  .then(data => {
    if (!data.success) { showToast(data.message || 'Erreur serveur.', true); return; }
    allCharges   = data.charges;
    filteredData = data.charges;
    currentPage  = 1;
    renderTable();
    renderKPIs(data.kpi);
    renderBars(data.bars);
  })
  .catch(() => showToast('Erreur de connexion au serveur.', true));
}

function scheduleFilter() {
  clearTimeout(filterTimer);
  filterTimer = setTimeout(loadCharges, 350);
}

/* ═══════════════════════════════════════════════════════
   SORT
═══════════════════════════════════════════════════════ */
function sortTable(key) {
  if (sortKey === key) sortDir = sortDir === 'asc' ? 'desc' : 'asc';
  else { sortKey = key; sortDir = 'desc'; }
  document.querySelectorAll('.sort-icon').forEach(el => el.textContent = 'v');
  const icon = document.getElementById('sort-'+key);
  if (icon) icon.textContent = sortDir === 'asc' ? '^' : 'v';
  loadCharges();
}

/* ═══════════════════════════════════════════════════════
   RENDER TABLE
═══════════════════════════════════════════════════════ */
function renderTable() {
  const tbody = document.getElementById('charges-tbody');
  const empty = document.getElementById('empty-state');
  const count = document.getElementById('table-count');
  const pinfo = document.getElementById('pagination-info');
  const pctrl = document.getElementById('pagination-controls');

  count.textContent = filteredData.length + ' entree' + (filteredData.length !== 1 ? 's' : '');

  if (filteredData.length === 0) {
    tbody.innerHTML = '';
    empty.style.display = 'block';
    pinfo.textContent = '0-0 sur 0';
    pctrl.innerHTML = '';
    return;
  }
  empty.style.display = 'none';

  const totalPages = Math.ceil(filteredData.length / PER_PAGE);
  if (currentPage > totalPages) currentPage = totalPages;
  const start = (currentPage - 1) * PER_PAGE;
  const end   = Math.min(start + PER_PAGE, filteredData.length);
  const page  = filteredData.slice(start, end);

  tbody.innerHTML = page.map(c => `
    <tr>
      <td class="mono">${formatDate(c.date_operation)}</td>
      <td class="primary">${esc(c.libelle_operation)}</td>
      <td><span class="compte-tag">${esc(c.code_compte)}</span></td>
      <td class="amount-cell">-${fmt(c.montant)}</td>
      <td>${badgePaiement(c.mode_paiement)}</td>
      <td style="max-width:160px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:0.76rem;">${esc(c.commentaire||'-')}</td>
      <td>
        <div class="action-btns">
          <button class="icon-btn" onclick="editCharge(${c.id_charge})" title="Modifier">
            <svg width="11" height="11" viewBox="0 0 11 11" fill="none">
              <path d="M1 10L3.5 9l5.5-5.5-2-2L1.5 7 1 10z" stroke="currentColor" stroke-width="1.2" stroke-linejoin="round"/>
              <path d="M7.5 1.5l2 2" stroke="currentColor" stroke-width="1.2" stroke-linecap="round"/>
            </svg>
          </button>
          <button class="icon-btn del" onclick="deleteCharge(${c.id_charge})" title="Supprimer">
            <svg width="11" height="11" viewBox="0 0 11 11" fill="none">
              <path d="M1 1l9 9M10 1L1 10" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
            </svg>
          </button>
        </div>
      </td>
    </tr>
  `).join('');

  pinfo.textContent = (start+1)+'-'+end+' sur '+filteredData.length;
  pctrl.innerHTML   = '';

  const addBtn = (label, p, active) => {
    const b = document.createElement('button');
    b.className  = 'page-btn' + (active ? ' active' : '');
    b.textContent = label;
    b.onclick = () => { currentPage = p; renderTable(); };
    pctrl.appendChild(b);
  };
  if (currentPage > 1) addBtn('<', currentPage-1, false);
  for (let p = Math.max(1,currentPage-2); p <= Math.min(totalPages,currentPage+2); p++) addBtn(p, p, p===currentPage);
  if (currentPage < totalPages) addBtn('>', currentPage+1, false);
}

/* ═══════════════════════════════════════════════════════
   RENDER KPIs
═══════════════════════════════════════════════════════ */
function renderKPIs(k) {
  document.getElementById('kpi-mois').textContent      = fmt(k.total_mois)+' F';
  document.getElementById('kpi-mois-sub').textContent  = k.count_mois+' operation'+(k.count_mois!==1?'s':'');
  document.getElementById('kpi-semaine').textContent   = fmt(k.total_sem)+' F';
  document.getElementById('kpi-sem-sub').textContent   = k.count_sem+' operation'+(k.count_sem!==1?'s':'');
  document.getElementById('kpi-comptes').textContent   = k.nb_comptes;
  document.getElementById('kpi-max').textContent       = fmt(k.max_val)+' F';
  const ml = k.max_lib || '--';
  document.getElementById('kpi-max-sub').textContent   = ml.length > 24 ? ml.slice(0,24)+'...' : ml;
}

/* ═══════════════════════════════════════════════════════
   RENDER BARS
═══════════════════════════════════════════════════════ */
function renderBars(bars) {
  const barList = document.getElementById('bar-list');
  if (!bars || bars.length === 0) {
    barList.innerHTML = '<div style="color:var(--text-muted);font-size:0.8rem;">Aucune donnee</div>';
    return;
  }
  const max = bars[0].total || 1;
  barList.innerHTML = bars.map(b => {
    const pct = Math.round(b.total / max * 100);
    const lib = (b.lib||b.code).length > 22 ? (b.lib||b.code).slice(0,22)+'...' : (b.lib||b.code);
    return `
      <div class="bar-item">
        <div class="bar-meta">
          <span class="bar-name">${esc(b.code)} - ${esc(lib)}</span>
          <span class="bar-val">${fmt(b.total)} F</span>
        </div>
        <div class="bar-track">
          <div class="bar-fill" style="width:0%" data-target="${pct}"></div>
        </div>
      </div>`;
  }).join('');
  setTimeout(() => {
    document.querySelectorAll('.bar-fill[data-target]').forEach(el => {
      el.style.width = el.dataset.target + '%';
    });
  }, 60);
}

/* ═══════════════════════════════════════════════════════
   RESET FILTERS
═══════════════════════════════════════════════════════ */
function resetFilters() {
  document.getElementById('search-input').value   = '';
  document.getElementById('filter-mode').value    = '';
  document.getElementById('filter-compte').value  = '';
  setDefaultDates();
  loadCharges();
}

/* ═══════════════════════════════════════════════════════
   CUSTOM SELECT (Plan Comptable)
═══════════════════════════════════════════════════════ */
function buildCustomSelect(id) {
  const display    = document.getElementById('cs-display-'+id);
  const dropdown   = document.getElementById('cs-dropdown-'+id);
  const searchInp  = document.getElementById('cs-search-'+id);
  const list       = document.getElementById('cs-list-'+id);
  const hiddenInp  = document.getElementById('f-compte-value');

  function renderList(query) {
    const q = (query||'').toLowerCase().trim();
    list.innerHTML = '';
    let hasResults = false;

    PLAN_COMPTABLE.forEach(group => {
      /* Filtrer les enfants */
      const matchedChildren = (group.children||[]).filter(ch =>
        !q || ch.code.toLowerCase().includes(q) || ch.libelle.toLowerCase().includes(q)
      );
      const parentMatch = !q || group.code.toLowerCase().includes(q) || group.libelle.toLowerCase().includes(q);

      /* Si pas d'enfants, afficher le parent lui-meme comme option selectionnable */
      if (group.children.length === 0 && parentMatch) {
        const label = document.createElement('div');
        label.className = 'cs-group-label';
        label.textContent = group.code + ' - ' + group.libelle;
        list.appendChild(label);

        const opt = document.createElement('div');
        opt.className = 'cs-option' + (hiddenInp.value === group.code ? ' selected' : '');
        opt.dataset.value = group.code;
        opt.dataset.label = group.code + ' - ' + group.libelle;
        opt.innerHTML = `<span class="cs-option-code">${group.code}</span>${group.libelle}`;
        opt.addEventListener('click', () => selectOption(opt.dataset.value, opt.dataset.label));
        list.appendChild(opt);
        hasResults = true;
        return;
      }

      if (matchedChildren.length === 0 && !parentMatch) return;

      /* Label du groupe */
      const label = document.createElement('div');
      label.className = 'cs-group-label';
      label.textContent = group.code + ' - ' + group.libelle;
      list.appendChild(label);

      /* Si groupe est selectionnable aussi (quand pas d'enfants filtres mais match parent) */
      const children = matchedChildren.length > 0 ? matchedChildren : (parentMatch ? group.children : []);

      children.forEach(ch => {
        const opt = document.createElement('div');
        opt.className = 'cs-option' + (hiddenInp.value === ch.code ? ' selected' : '');
        opt.dataset.value = ch.code;
        opt.dataset.label = ch.code + ' - ' + ch.libelle;
        opt.innerHTML = `<span class="cs-option-code">${ch.code}</span>${ch.libelle}`;
        opt.addEventListener('click', () => selectOption(opt.dataset.value, opt.dataset.label));
        list.appendChild(opt);
        hasResults = true;
      });
    });

    if (!hasResults) {
      list.innerHTML = '<div class="cs-no-results">Aucun compte trouve</div>';
    }
  }

  function selectOption(value, label) {
    hiddenInp.value = value;
    display.textContent = label;
    display.classList.remove('placeholder');
    closeDropdown();
  }

  function openDropdown() {
    display.classList.add('open');
    dropdown.classList.add('open');
    display.setAttribute('aria-expanded','true');
    searchInp.value = '';
    renderList('');
    setTimeout(() => searchInp.focus(), 50);
  }

  function closeDropdown() {
    display.classList.remove('open');
    dropdown.classList.remove('open');
    display.setAttribute('aria-expanded','false');
  }

  display.addEventListener('click', () => {
    dropdown.classList.contains('open') ? closeDropdown() : openDropdown();
  });
  display.addEventListener('keydown', e => {
    if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); openDropdown(); }
    if (e.key === 'Escape') closeDropdown();
  });
  searchInp.addEventListener('input', () => renderList(searchInp.value));
  document.addEventListener('click', e => {
    if (!document.getElementById('cs-wrap-'+id).contains(e.target)) closeDropdown();
  });

  /* Exposer pour reset */
  window['csReset_'+id] = () => {
    hiddenInp.value = '';
    display.textContent = '-- Choisir un compte --';
    display.classList.add('placeholder');
  };
  window['csSetValue_'+id] = (value, label) => {
    if (value) { hiddenInp.value = value; display.textContent = label || value; display.classList.remove('placeholder'); }
    else window['csReset_'+id]();
    renderList('');
  };

  renderList('');
}

/* Trouver le libelle d'un code compte */
function findLibelle(code) {
  for (const g of PLAN_COMPTABLE) {
    if (g.code === code) return g.code + ' - ' + g.libelle;
    for (const ch of (g.children||[])) {
      if (ch.code === code) return ch.code + ' - ' + ch.libelle;
    }
  }
  return code;
}

/* ═══════════════════════════════════════════════════════
   MODAL OPEN / CLOSE
═══════════════════════════════════════════════════════ */
function openModal(id = null) {
  editId = id;
  document.getElementById('modal-title-text').textContent = id ? 'Modifier la charge' : 'Nouvelle charge';
  const today = new Date().toISOString().split('T')[0];

  if (id) {
    const c = allCharges.find(x => +x.id_charge === +id);
    if (!c) return;
    document.getElementById('f-date').value        = c.date_operation;
    document.getElementById('f-libelle').value     = c.libelle_operation;
    document.getElementById('f-montant').value     = c.montant;
    document.getElementById('f-mode').value        = c.mode_paiement;
    document.getElementById('f-commentaire').value = c.commentaire || '';
    window.csSetValue_modal(c.code_compte, findLibelle(c.code_compte));
  } else {
    document.getElementById('f-date').value        = today;
    document.getElementById('f-libelle').value     = '';
    document.getElementById('f-montant').value     = '';
    document.getElementById('f-mode').value        = '';
    document.getElementById('f-commentaire').value = '';
    window.csReset_modal();
  }
  document.getElementById('modal-overlay').classList.add('open');
}

function closeModal() {
  document.getElementById('modal-overlay').classList.remove('open');
  editId = null;
}

document.getElementById('modal-overlay').addEventListener('click', function(e) {
  if (e.target === this) closeModal();
});

/* ═══════════════════════════════════════════════════════
   SAVE CHARGE (CREATE / UPDATE)
═══════════════════════════════════════════════════════ */
function saveCharge() {

  const date    = document.getElementById('f-date').value;
  const libelle = document.getElementById('f-libelle').value.trim();
  const montant = parseFloat(document.getElementById('f-montant').value);
  const compte  = document.getElementById('f-compte-value').value;
  const mode    = document.getElementById('f-mode').value;
  const comment = document.getElementById('f-commentaire').value.trim();

  if (!date || !libelle || isNaN(montant) || montant <= 0 || !compte || !mode) {
    showToast('Veuillez remplir tous les champs obligatoires.', true);
    return;
  }

  const btn = document.getElementById('btn-save');
  btn.disabled = true;
  btn.textContent = 'Enregistrement...';

  const action = editId ? 'update' : 'create';

  const body = {
    id_charge: editId || '',
    date_operation: date,
    libelle_operation: libelle,
    montant: montant,
    code_compte: compte,
    mode_paiement: mode,
    commentaire: comment,
    action: action
  };

  $.ajax({
    url: 'save_charge.php',
    type: 'POST',
    data: body,

    success: function(data) {
    	console.log(data)
    	let response = JSON.parse(data);
      if (response.success) {
        showToast(response.message);
        closeModal();
        loadCharges();
      } else {
        showToast(response.message || 'Erreur lors de l\'enregistrement.', true);
      }
    },

    error: function(error) {
    	console.log(error)
      showToast('Erreur de connexion.', true);
    },

    complete: function() {
      btn.disabled = false;
      btn.innerHTML = `<svg width="13" height="13" viewBox="0 0 13 13" fill="none">
        <path d="M2 7l3.5 3.5L11 3" stroke="currentColor" stroke-width="2"
        stroke-linecap="round" stroke-linejoin="round"/>
      </svg> Enregistrer`;
    }
  });
}

/* ═══════════════════════════════════════════════════════
   EDIT / DELETE
═══════════════════════════════════════════════════════ */
function editCharge(id) { openModal(id); }

function deleteCharge(id) {
  if (!confirm('Supprimer definitivement cette charge ?')) return;

  fetch('charges.php?action=delete', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: new URLSearchParams({ id_charge: id }).toString(),
  })
  .then(r => r.json())
  .then(data => {
    if (data.success) { showToast(data.message); loadCharges(); }
    else showToast(data.message || 'Erreur lors de la suppression.', true);
  })
  .catch(() => showToast('Erreur de connexion.', true));
}

/* ═══════════════════════════════════════════════════════
   EXPORT CSV (via form redirect)
═══════════════════════════════════════════════════════ */
function exportCSV() {
  window.location.href = 'charges.php?action=export';
  showToast('Telechargement du fichier CSV...');
}

/* ═══════════════════════════════════════════════════════
   HELPERS
═══════════════════════════════════════════════════════ */
function fmt(n) {
  return Number(n).toLocaleString('fr-FR');
}

function formatDate(d) {
  if (!d) return '--';
  const [y,m,j] = d.split('-');
  return j+'/'+m+'/'+y;
}

function esc(str) {
  return String(str||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}

function badgePaiement(mode) {
  const map = {
    especes:      { cls:'badge-especes',  label:'Especes' },
    mobile_money: { cls:'badge-mobile',   label:'Mobile Money' },
    cheque:       { cls:'badge-cheque',   label:'Cheque' },
    virement:     { cls:'badge-virement', label:'Virement' },
  };
  const m = map[mode] || { cls:'', label: mode||'-' };
  return `<span class="badge ${m.cls}"><span class="badge-dot"></span>${m.label}</span>`;
}

function showToast(msg, error = false) {
  const container = document.getElementById('toast-container');
  const toast     = document.createElement('div');
  toast.className = 'toast' + (error ? ' error' : '');
  toast.innerHTML = `
    <svg width="16" height="16" viewBox="0 0 16 16" fill="none">
      <path d="${error ? 'M1 1l14 14M15 1L1 15' : 'M2 8.5l4 4 8-8'}"
        stroke="${error ? '#f43f5e' : '#00c9a7'}" stroke-width="1.8"
        stroke-linecap="round" stroke-linejoin="round"/>
    </svg>
    ${esc(msg)}
  `;
  container.appendChild(toast);
  setTimeout(() => toast.remove(), 3500);
}
</script>
</body>
</html>