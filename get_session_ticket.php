<?php
/**
 * get_session_ticket.php
 * Construit le rapport HTML complet d'une session de caisse
 * Tables utilisées :
 *   sessions_caisse, ventes, detail_ventes, produits,
 *   caisse, charges, clotures, utilisateurs, clients, assurances
 */
include 'db.php';
header('Content-Type: application/json');

$idSession = intval($_POST['id_session'] ?? 0);
if ($idSession <= 0) {
    echo json_encode(['success' => false, 'message' => 'ID session invalide.']);
    exit;
}

try {

    // ════════════════════════════════════════════════════
    // 1. INFOS PRINCIPALE DE LA SESSION
    // ════════════════════════════════════════════════════
    $stmtSession = $pdo->prepare("
        SELECT
            sc.*,
            u.nom_complet AS nom_caissier
        FROM sessions_caisse sc
        LEFT JOIN utilisateurs u ON u.id_user = sc.id_utilisateur
        WHERE sc.id_session = ?
    ");
    $stmtSession->execute([$idSession]);
    $session = $stmtSession->fetch(PDO::FETCH_ASSOC);

    if (!$session) {
        echo json_encode(['success' => false, 'message' => 'Session introuvable.']);
        exit;
    }

    $dateOuverture = $session['date_ouverture']
        ? date('d/m/Y H:i', strtotime($session['date_ouverture'])) : 'N/A';
    $dateCloture   = $session['date_cloture']
        ? date('d/m/Y H:i', strtotime($session['date_cloture'])) : 'En cours';
    $isOpen        = $session['statut'] === 'ouvert' || !$session['date_cloture'];

    // Duree de session
    $dureeStr = 'En cours';
    if ($session['date_ouverture'] && $session['date_cloture']) {
        $debut    = new DateTime($session['date_ouverture']);
        $fin      = new DateTime($session['date_cloture']);
        $interval = $debut->diff($fin);
        $dureeStr = sprintf('%02dh %02dmin', $interval->h + ($interval->days * 24), $interval->i);
    } elseif ($session['date_ouverture']) {
        $debut    = new DateTime($session['date_ouverture']);
        $now      = new DateTime();
        $interval = $debut->diff($now);
        $dureeStr = sprintf('%02dh %02dmin (en cours)', $interval->h + ($interval->days * 24), $interval->i);
    }

    // ════════════════════════════════════════════════════
    // 2. VENTES DE LA SESSION (perimetre par date)
    // ════════════════════════════════════════════════════
    $dateDebut = $session['date_ouverture'];
    $dateFin   = $session['date_cloture'] ?? date('Y-m-d H:i:s');

    $stmtVentes = $pdo->prepare("
        SELECT
            v.id_vente,
            v.date_vente,
            v.total,
            v.mode_paiement,
            v.remise_montant,
            v.part_patient,
            v.part_assurance,
            v.statut_paiement,
            v.remise,
            CONCAT(c.nom, ' ', COALESCE(c.prenom,'')) AS nom_client,
            a.nom_assurance
        FROM ventes v
        LEFT JOIN clients    c ON c.id_client    = v.id_client
        LEFT JOIN assurances a ON a.id_assurance = v.id_assurance
        WHERE v.id_utilisateur = ?
          AND v.date_vente BETWEEN ? AND ?
        ORDER BY v.date_vente ASC
    ");
    $stmtVentes->execute([$session['id_utilisateur'], $dateDebut, $dateFin]);
    $ventes = $stmtVentes->fetchAll(PDO::FETCH_ASSOC);

    // ────── Agrégats ventes ──────
    $totalVentes      = 0;
    $totalEspeces     = 0;
    $totalMobileMoney = 0;
    $totalAssurance   = 0;
    $totalCheque      = 0;
    $totalRemise      = 0;
    $totalPartPatient = 0;
    $nbVentes         = count($ventes);
    $nbVentesAnnulees = 0;
    $modePaiementStats = [];

    foreach ($ventes as $v) {
        if (strtolower($v['statut_paiement'] ?? '') === 'annule') {
            $nbVentesAnnulees++;
            continue;
        }
        $totalVentes  += floatval($v['total']);
        $totalRemise  += floatval($v['remise_montant']);
        $mode = strtolower(trim($v['mode_paiement'] ?? 'especes'));
        $modePaiementStats[$mode] = ($modePaiementStats[$mode] ?? 0) + floatval($v['total']);
        if (str_contains($mode, 'espece') || $mode === 'cash') {
            $totalEspeces += floatval($v['total']);
        } elseif (str_contains($mode, 'mobile') || str_contains($mode, 'orange') || str_contains($mode, 'momo') || str_contains($mode, 'wave')) {
            $totalMobileMoney += floatval($v['total']);
        } elseif (str_contains($mode, 'cheque')) {
            $totalCheque += floatval($v['total']);
        }
        $totalAssurance   += floatval($v['part_assurance'] ?? 0);
        $totalPartPatient += floatval($v['part_patient']   ?? 0);
    }

    // Panier moyen
    $nbVentesValides = $nbVentes - $nbVentesAnnulees;
    $panierMoyen     = $nbVentesValides > 0 ? $totalVentes / $nbVentesValides : 0;

    // ════════════════════════════════════════════════════
    // 3. TOP 10 PRODUITS VENDUS
    // ════════════════════════════════════════════════════
    $stmtTop = $pdo->prepare("
        SELECT
            p.nom_commercial,
            p.molecule,
            SUM(dv.quantite)                   AS qte_totale,
            SUM(dv.quantite * dv.prix_unitaire) AS ca_produit,
            COUNT(DISTINCT dv.id_vente)         AS nb_ventes
        FROM detail_ventes dv
        JOIN ventes v  ON v.id_vente   = dv.id_vente
        JOIN produits p ON p.id_produit = dv.id_produit
        WHERE v.id_utilisateur = ?
          AND v.date_vente BETWEEN ? AND ?
          AND (v.statut_paiement IS NULL OR v.statut_paiement != 'annule')
        GROUP BY dv.id_produit, p.nom_commercial, p.molecule
        ORDER BY ca_produit DESC
        LIMIT 10
    ");
    $stmtTop->execute([$session['id_utilisateur'], $dateDebut, $dateFin]);
    $topProduits = $stmtTop->fetchAll(PDO::FETCH_ASSOC);

    // ════════════════════════════════════════════════════
    // 4. MOUVEMENTS CAISSE DE LA SESSION
    // ════════════════════════════════════════════════════
    $stmtCaisse = $pdo->prepare("
        SELECT
            cm.date_mouvement,
            cm.type_mouvement,
            cm.montant,
            cm.motif
        FROM caisse cm
        WHERE cm.id_utilisateur = ?
          AND cm.date_mouvement BETWEEN ? AND ?
          AND cm.id_vente IS NULL
        ORDER BY cm.date_mouvement ASC
    ");
    $stmtCaisse->execute([$session['id_utilisateur'], $dateDebut, $dateFin]);
    $mouvements = $stmtCaisse->fetchAll(PDO::FETCH_ASSOC);

    $totalEntrees = 0;
    $totalSorties = 0;
    foreach ($mouvements as $mv) {
        if (strtolower($mv['type_mouvement']) === 'entree') {
            $totalEntrees += floatval($mv['montant']);
        } else {
            $totalSorties += floatval($mv['montant']);
        }
    }

    // ════════════════════════════════════════════════════
    // 5. REPARTITION HORAIRE DES VENTES
    // ════════════════════════════════════════════════════
    $heuresStats = array_fill(0, 24, ['nb' => 0, 'ca' => 0]);
    foreach ($ventes as $v) {
        if (strtolower($v['statut_paiement'] ?? '') === 'annule') continue;
        $heure = (int)date('G', strtotime($v['date_vente']));
        $heuresStats[$heure]['nb']++;
        $heuresStats[$heure]['ca'] += floatval($v['total']);
    }
    $heurePointe = 0;
    $caPointe    = 0;
    foreach ($heuresStats as $h => $stat) {
        if ($stat['ca'] > $caPointe) {
            $caPointe    = $stat['ca'];
            $heurePointe = $h;
        }
    }

    // ════════════════════════════════════════════════════
    // 6. SOLDE THEORIQUE vs REEL
    // ════════════════════════════════════════════════════
    $fondDepart     = floatval($session['fond_caisse_depart'] ?? 0);
    $montantTheo    = floatval($session['montant_theorique']  ?? 0);
    $montantReel    = floatval($session['montant_final_reel'] ?? 0);
    $ecartCaisse    = $montantReel - $montantTheo;
    $soldeCalc      = $fondDepart + $totalEspeces + $totalEntrees - $totalSorties;

    // ════════════════════════════════════════════════════
    // 7. CONSTRUCTION HTML DU TICKET
    // ════════════════════════════════════════════════════

    $f = function(float $n, int $dec = 0): string {
        return number_format($n, $dec, ',', ' ');
    };

    // Barres progress pour repartition paiements
    function pctBar(float $part, float $total, string $color): string {
        $pct = $total > 0 ? min(100, ($part / $total) * 100) : 0;
        return "<div style='background:#e9ecef;border-radius:4px;height:8px;overflow:hidden;'>
                    <div style='width:{$pct}%;background:{$color};height:8px;'></div>
                </div>";
    }

    // Minibar pour top produits
    $maxCaProduit = !empty($topProduits) ? floatval($topProduits[0]['ca_produit']) : 1;

    $now     = date('d/m/Y H:i:s');
    $statut  = $isOpen
        ? '<span style="background:#22c55e;color:#fff;padding:3px 10px;border-radius:99px;font-size:11px;font-weight:700;">SESSION OUVERTE</span>'
        : '<span style="background:#6b7280;color:#fff;padding:3px 10px;border-radius:99px;font-size:11px;font-weight:700;">SESSION CLOTUREE</span>';

    // ── Lignes ventes recentes (10 dernières) ──
    $ventesRecentes = array_slice(array_reverse($ventes), 0, 10);
    $lignesVentes   = '';
    foreach ($ventesRecentes as $v) {
        $isAnnule   = strtolower($v['statut_paiement'] ?? '') === 'annule';
        $styleAnnul = $isAnnule ? 'text-decoration:line-through;color:#999;' : '';
        $heure      = date('H:i', strtotime($v['date_vente']));
        $client     = trim($v['nom_client']) ?: 'Anonyme';
        $mode       = strtoupper($v['mode_paiement'] ?? 'ESPECES');
        $lignesVentes .= "
        <tr>
            <td style='padding:4px 6px;{$styleAnnul}'>{$heure}</td>
            <td style='padding:4px 6px;{$styleAnnul}'>{$client}</td>
            <td style='padding:4px 6px;text-align:right;{$styleAnnul}'>" . number_format(floatval($v['total']),0,',',' ') . "</td>
            <td style='padding:4px 6px;text-align:center;{$styleAnnul}'><span style='font-size:10px;background:#e5e7eb;padding:1px 5px;border-radius:4px;'>{$mode}</span></td>
            <td style='padding:4px 6px;text-align:right;color:#f97316;{$styleAnnul}'>" . ($v['remise_montant'] > 0 ? '-'.number_format(floatval($v['remise_montant']),0,',',' ') : '—') . "</td>
        </tr>";
    }

    // ── Lignes mouvements caisse ──
    $lignesMvt = '';
    if (empty($mouvements)) {
        $lignesMvt = "<tr><td colspan='4' style='text-align:center;color:#9ca3af;padding:8px;'>Aucun mouvement hors-vente</td></tr>";
    } else {
        foreach ($mouvements as $mv) {
            $isEntree  = strtolower($mv['type_mouvement']) === 'entree';
            $couleur   = $isEntree ? '#16a34a' : '#dc2626';
            $signe     = $isEntree ? '+' : '-';
            $heureMvt  = date('H:i', strtotime($mv['date_mouvement']));
            $lignesMvt .= "
            <tr>
                <td style='padding:4px 6px;'>{$heureMvt}</td>
                <td style='padding:4px 6px;font-weight:600;color:{$couleur};'>" . strtoupper($mv['type_mouvement']) . "</td>
                <td style='padding:4px 6px;'>" . htmlspecialchars($mv['motif'] ?? '—') . "</td>
                <td style='padding:4px 6px;text-align:right;font-weight:700;color:{$couleur};'>{$signe}" . number_format(floatval($mv['montant']),0,',',' ') . " FCFA</td>
            </tr>";
        }
    }

    // ── Lignes top produits ──
    $lignesTop = '';
    $rankColors = ['#f59e0b','#94a3b8','#b45309'];
    foreach ($topProduits as $idx => $prod) {
        $pct      = $maxCaProduit > 0 ? ($prod['ca_produit'] / $maxCaProduit) * 100 : 0;
        $rankCol  = $idx < 3 ? $rankColors[$idx] : '#d1d5db';
        $rankNum  = $idx + 1;
        $lignesTop .= "
        <tr style='border-bottom:1px solid #f1f5f9;'>
            <td style='padding:5px 6px;text-align:center;'>
                <span style='display:inline-block;width:20px;height:20px;border-radius:50%;background:{$rankCol};
                             color:#fff;font-size:10px;font-weight:800;line-height:20px;text-align:center;'>{$rankNum}</span>
            </td>
            <td style='padding:5px 6px;'>
                <span style='font-weight:600;font-size:12px;'>" . htmlspecialchars($prod['nom_commercial']) . "</span>
                " . ($prod['molecule'] ? "<br><span style='font-size:10px;color:#9ca3af;'>" . htmlspecialchars($prod['molecule']) . "</span>" : "") . "
            </td>
            <td style='padding:5px 6px;text-align:center;font-size:12px;'>" . $f(floatval($prod['qte_totale'])) . "</td>
            <td style='padding:5px 8px;min-width:130px;'>
                <div style='font-size:11px;font-weight:700;margin-bottom:2px;'>" . $f(floatval($prod['ca_produit'])) . " FCFA</div>
                <div style='background:#e9ecef;border-radius:4px;height:5px;overflow:hidden;'>
                    <div style='width:{$pct}%;background:#3b82f6;height:5px;'></div>
                </div>
            </td>
        </tr>";
    }

    // ── Repartition horaire (mini chart ascii) ──
    $chartHeures = '';
    for ($h = 7; $h <= 21; $h++) {
        $stat     = $heuresStats[$h];
        $pctH     = $caPointe > 0 ? min(100, ($stat['ca'] / $caPointe) * 100) : 0;
        $bgH      = $h === $heurePointe ? '#f59e0b' : '#3b82f6';
        $chartHeures .= "
        <div style='display:flex;flex-direction:column;align-items:center;flex:1;'>
            <span style='font-size:9px;color:#6b7280;margin-bottom:2px;font-weight:600;'>{$stat['nb']}</span>
            <div style='flex:1;display:flex;align-items:flex-end;width:100%;'>
                <div style='width:100%;background:{$bgH};height:{$pctH}%;min-height:" . ($stat['nb']>0?3:1) . "px;border-radius:2px 2px 0 0;'></div>
            </div>
            <span style='font-size:8px;color:#9ca3af;margin-top:2px;'>{$h}h</span>
        </div>";
    }

    // ── Ecart caisse ──
    $ecartStyle = $ecartCaisse == 0
        ? 'color:#16a34a;'
        : ($ecartCaisse > 0 ? 'color:#2563eb;' : 'color:#dc2626;');
    $ecartLabel = $ecartCaisse == 0 ? 'Equilibre' : ($ecartCaisse > 0 ? 'Excedent' : 'Manquant');

    // ══════════════════════════════════════════════════
    // TEMPLATE HTML FINAL
    // ══════════════════════════════════════════════════
    ob_start();
    ?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<title>Rapport Session #<?= $idSession ?> — <?= date('d/m/Y') ?></title>
<style>
    @import url('https://fonts.googleapis.com/css2?family=DM+Serif+Display&family=DM+Mono:wght@400;500&family=DM+Sans:wght@400;500;600;700&display=swap');

    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

    :root {
        --ink:      #0f172a;
        --muted:    #64748b;
        --border:   #e2e8f0;
        --surface:  #f8fafc;
        --accent:   #0f172a;
        --success:  #16a34a;
        --danger:   #dc2626;
        --warn:     #d97706;
        --blue:     #2563eb;
        --paper:    #ffffff;
        --w:        760px;
    }

    body {
        font-family: 'DM Sans', sans-serif;
        background: #e2e8f0;
        color: var(--ink);
        font-size: 13px;
        line-height: 1.5;
    }

    .page {
        width: var(--w);
        margin: 20px auto;
        background: var(--paper);
        box-shadow: 0 4px 32px rgba(0,0,0,.12);
    }

    /* ── EN-TETE ── */
    .header {
        background: var(--accent);
        color: #fff;
        padding: 28px 32px 22px;
        position: relative;
        overflow: hidden;
    }
    .header::before {
        content: '';
        position: absolute;
        top: -40px; right: -40px;
        width: 160px; height: 160px;
        border-radius: 50%;
        background: rgba(255,255,255,.05);
    }
    .header::after {
        content: '';
        position: absolute;
        bottom: -60px; right: 60px;
        width: 240px; height: 240px;
        border-radius: 50%;
        background: rgba(255,255,255,.04);
    }
    .header-top {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        position: relative; z-index: 1;
    }
    .pharma-name {
        font-family: 'DM Serif Display', serif;
        font-size: 26px;
        letter-spacing: -0.5px;
        line-height: 1.1;
    }
    .pharma-sub {
        font-size: 11px;
        opacity: .6;
        margin-top: 4px;
        letter-spacing: 2px;
        text-transform: uppercase;
    }
    .session-badge {
        background: rgba(255,255,255,.15);
        border: 1px solid rgba(255,255,255,.25);
        border-radius: 8px;
        padding: 8px 16px;
        text-align: right;
        position: relative; z-index: 1;
    }
    .session-badge .num {
        font-family: 'DM Mono', monospace;
        font-size: 18px;
        font-weight: 500;
    }
    .session-badge .lbl {
        font-size: 10px;
        opacity: .6;
        text-transform: uppercase;
        letter-spacing: 1.5px;
    }
    .header-meta {
        display: flex;
        gap: 32px;
        margin-top: 20px;
        padding-top: 18px;
        border-top: 1px solid rgba(255,255,255,.15);
        position: relative; z-index: 1;
    }
    .meta-item .label {
        font-size: 10px;
        opacity: .55;
        text-transform: uppercase;
        letter-spacing: 1px;
        margin-bottom: 2px;
    }
    .meta-item .value {
        font-size: 13px;
        font-weight: 600;
    }

    /* ── BANNER STATUT ── */
    .status-bar {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 10px 32px;
        background: var(--surface);
        border-bottom: 1px solid var(--border);
        font-size: 11px;
        color: var(--muted);
    }

    /* ── KPI CARDS ── */
    .kpi-grid {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: 0;
        border-bottom: 1px solid var(--border);
    }
    .kpi-card {
        padding: 18px 20px;
        border-right: 1px solid var(--border);
        position: relative;
    }
    .kpi-card:last-child { border-right: none; }
    .kpi-card .kpi-label {
        font-size: 10px;
        text-transform: uppercase;
        letter-spacing: 1.2px;
        color: var(--muted);
        font-weight: 600;
        margin-bottom: 6px;
    }
    .kpi-card .kpi-value {
        font-size: 20px;
        font-weight: 700;
        letter-spacing: -0.5px;
        line-height: 1;
    }
    .kpi-card .kpi-sub {
        font-size: 10px;
        color: var(--muted);
        margin-top: 4px;
    }
    .kpi-card .kpi-accent-bar {
        position: absolute;
        bottom: 0; left: 0;
        width: 100%; height: 3px;
    }

    /* ── SECTIONS ── */
    .section {
        padding: 22px 32px;
        border-bottom: 1px solid var(--border);
    }
    .section:last-child { border-bottom: none; }
    .section-title {
        display: flex;
        align-items: center;
        gap: 8px;
        font-size: 11px;
        text-transform: uppercase;
        letter-spacing: 2px;
        font-weight: 700;
        color: var(--muted);
        margin-bottom: 14px;
        padding-bottom: 8px;
        border-bottom: 1px solid var(--border);
    }
    .section-title::before {
        content: '';
        display: block;
        width: 3px; height: 14px;
        background: var(--accent);
        border-radius: 2px;
    }

    /* ── PAIEMENT CARDS ── */
    .paiement-grid {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: 10px;
        margin-bottom: 12px;
    }
    .paiement-card {
        border: 1px solid var(--border);
        border-radius: 8px;
        padding: 12px 14px;
    }
    .paiement-card .p-label {
        font-size: 10px;
        color: var(--muted);
        text-transform: uppercase;
        letter-spacing: 1px;
        margin-bottom: 4px;
    }
    .paiement-card .p-amount {
        font-size: 15px;
        font-weight: 700;
        margin-bottom: 6px;
    }
    .paiement-card .p-pct {
        font-size: 10px;
        color: var(--muted);
        margin-top: 4px;
    }

    /* ── TABLE GENERIC ── */
    .rtable {
        width: 100%;
        border-collapse: collapse;
        font-size: 12px;
    }
    .rtable thead tr {
        background: var(--surface);
    }
    .rtable thead th {
        padding: 7px 8px;
        font-size: 10px;
        text-transform: uppercase;
        letter-spacing: 1px;
        color: var(--muted);
        font-weight: 700;
        text-align: left;
        border-bottom: 1px solid var(--border);
    }
    .rtable tbody tr:hover { background: #f8fafc; }
    .rtable tbody tr { border-bottom: 1px solid #f1f5f9; }

    /* ── CAISSE BALANCE ── */
    .balance-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 16px;
    }
    .balance-row {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 7px 0;
        border-bottom: 1px dashed var(--border);
        font-size: 13px;
    }
    .balance-row:last-child { border-bottom: none; }
    .balance-row.total-row {
        border-top: 2px solid var(--ink);
        border-bottom: none;
        padding-top: 10px;
        margin-top: 6px;
        font-weight: 700;
        font-size: 15px;
    }
    .balance-row.ecart-row {
        font-weight: 700;
        font-size: 14px;
        margin-top: 4px;
        padding: 8px 10px;
        border-radius: 6px;
        border: none;
    }

    /* ── CHART HEURES ── */
    .chart-heures {
        display: flex;
        gap: 3px;
        height: 60px;
        align-items: flex-end;
        padding: 0;
        margin-top: 8px;
    }

    /* ── FOOTER ── */
    .footer {
        background: var(--surface);
        padding: 16px 32px;
        display: flex;
        justify-content: space-between;
        align-items: center;
        border-top: 1px solid var(--border);
        font-size: 10px;
        color: var(--muted);
    }
    .footer .generated {
        font-family: 'DM Mono', monospace;
    }

    /* ── PRINT ── */
    @media print {
        body { background: #fff; }
        .page { box-shadow: none; margin: 0; width: 100%; }
        .no-print { display: none !important; }
        @page { margin: 10mm; size: A4; }
    }

    /* ── PRINT BUTTON ── */
    .print-btn {
        position: fixed;
        top: 20px; right: 20px;
        background: var(--accent);
        color: #fff;
        border: none;
        border-radius: 8px;
        padding: 10px 20px;
        font-family: 'DM Sans', sans-serif;
        font-size: 13px;
        font-weight: 600;
        cursor: pointer;
        display: flex;
        align-items: center;
        gap: 8px;
        box-shadow: 0 4px 16px rgba(0,0,0,.25);
        z-index: 100;
    }
    .print-btn:hover { background: #1e293b; }
    @media print { .print-btn { display: none; } }

    .tag {
        display: inline-block;
        font-size: 10px;
        font-weight: 700;
        padding: 1px 7px;
        border-radius: 99px;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }
    .tag-success { background: #dcfce7; color: #15803d; }
    .tag-danger  { background: #fee2e2; color: #b91c1c; }
    .tag-warn    { background: #fef3c7; color: #b45309; }
    .tag-blue    { background: #dbeafe; color: #1d4ed8; }
    .tag-gray    { background: #f1f5f9; color: #475569; }

    /* Divider */
    .divider {
        border: none;
        border-top: 1px dashed var(--border);
        margin: 14px 0;
    }
</style>
</head>
<body>

<button class="print-btn no-print" onclick="window.print()">
    Imprimer le rapport
</button>

<div class="page">

    <!-- ══ EN-TETE ══ -->
    <div class="header">
        <div class="header-top">
            <div>
                <div class="pharma-name">Pharmacie</div>
                <div class="pharma-sub">Rapport de Session de Caisse</div>
            </div>
            <div class="session-badge">
                <div class="lbl">Session</div>
                <div class="num">#<?= str_pad($idSession, 5, '0', STR_PAD_LEFT) ?></div>
                <div style="margin-top:6px;"><?= $statut ?></div>
            </div>
        </div>
        <div class="header-meta">
            <div class="meta-item">
                <div class="label">Caissier</div>
                <div class="value"><?= htmlspecialchars($session['nom_caissier'] ?? 'N/A') ?></div>
            </div>
            <div class="meta-item">
                <div class="label">Ouverture</div>
                <div class="value"><?= $dateOuverture ?></div>
            </div>
            <div class="meta-item">
                <div class="label">Cloture</div>
                <div class="value"><?= $dateCloture ?></div>
            </div>
            <div class="meta-item">
                <div class="label">Duree</div>
                <div class="value"><?= $dureeStr ?></div>
            </div>
            <div class="meta-item">
                <div class="label">Fond de caisse</div>
                <div class="value"><?= $f($fondDepart) ?> FCFA</div>
            </div>
        </div>
    </div>

    <!-- ══ BARRE STATUT ══ -->
    <div class="status-bar">
        <span>Imprime le <?= $now ?></span>
        <span>
            Document provisoire — non contractuel
        </span>
        <span>ID Session : <strong><?= $idSession ?></strong></span>
    </div>

    <!-- ══ KPI PRINCIPAUX ══ -->
    <div class="kpi-grid">
        <div class="kpi-card">
            <div class="kpi-label">Chiffre d'affaires</div>
            <div class="kpi-value" style="color:#0f172a;"><?= $f($totalVentes) ?></div>
            <div class="kpi-sub">FCFA encaisses</div>
            <div class="kpi-accent-bar" style="background:#0f172a;"></div>
        </div>
        <div class="kpi-card">
            <div class="kpi-label">Nb Ventes</div>
            <div class="kpi-value" style="color:#2563eb;"><?= $nbVentesValides ?></div>
            <div class="kpi-sub"><?= $nbVentesAnnulees > 0 ? $nbVentesAnnulees . ' annulee(s)' : 'Aucune annulation' ?></div>
            <div class="kpi-accent-bar" style="background:#2563eb;"></div>
        </div>
        <div class="kpi-card">
            <div class="kpi-label">Panier moyen</div>
            <div class="kpi-value" style="color:#d97706;"><?= $f($panierMoyen) ?></div>
            <div class="kpi-sub">FCFA / vente</div>
            <div class="kpi-accent-bar" style="background:#d97706;"></div>
        </div>
        <div class="kpi-card">
            <div class="kpi-label">Remises accordees</div>
            <div class="kpi-value" style="color:#dc2626;"><?= $f($totalRemise) ?></div>
            <div class="kpi-sub">FCFA total remises</div>
            <div class="kpi-accent-bar" style="background:#dc2626;"></div>
        </div>
    </div>

    <!-- ══ REPARTITION PAR MODE DE PAIEMENT ══ -->
    <div class="section">
        <div class="section-title">Repartition par Mode de Paiement</div>
        <div class="paiement-grid">
            <!-- Especes -->
            <div class="paiement-card">
                <div class="p-label">Especes / Cash</div>
                <div class="p-amount" style="color:var(--success);"><?= $f($totalEspeces) ?> <small style="font-size:11px;font-weight:400;color:var(--muted);">FCFA</small></div>
                <?= pctBar($totalEspeces, $totalVentes, '#16a34a') ?>
                <div class="p-pct"><?= $totalVentes > 0 ? $f(($totalEspeces/$totalVentes)*100, 1) : '0' ?>% du CA</div>
            </div>
            <!-- Mobile Money -->
            <div class="paiement-card">
                <div class="p-label">Mobile Money</div>
                <div class="p-amount" style="color:#f97316;"><?= $f($totalMobileMoney) ?> <small style="font-size:11px;font-weight:400;color:var(--muted);">FCFA</small></div>
                <?= pctBar($totalMobileMoney, $totalVentes, '#f97316') ?>
                <div class="p-pct"><?= $totalVentes > 0 ? $f(($totalMobileMoney/$totalVentes)*100, 1) : '0' ?>% du CA</div>
            </div>
            <!-- Assurance -->
            <div class="paiement-card">
                <div class="p-label">Part Assurance</div>
                <div class="p-amount" style="color:var(--blue);"><?= $f($totalAssurance) ?> <small style="font-size:11px;font-weight:400;color:var(--muted);">FCFA</small></div>
                <?= pctBar($totalAssurance, $totalVentes, '#2563eb') ?>
                <div class="p-pct">Part patient : <?= $f($totalPartPatient) ?> FCFA</div>
            </div>
            <!-- Cheque -->
            <div class="paiement-card">
                <div class="p-label">Cheque</div>
                <div class="p-amount" style="color:#7c3aed;"><?= $f($totalCheque) ?> <small style="font-size:11px;font-weight:400;color:var(--muted);">FCFA</small></div>
                <?= pctBar($totalCheque, $totalVentes, '#7c3aed') ?>
                <div class="p-pct"><?= $totalVentes > 0 ? $f(($totalCheque/$totalVentes)*100, 1) : '0' ?>% du CA</div>
            </div>
        </div>
    </div>

    <!-- ══ BALANCE CAISSE ══ -->
    <div class="section">
        <div class="section-title">Balance de Caisse</div>
        <div class="balance-grid">
            <!-- Colonne gauche : calcul theorique -->
            <div>
                <div style="font-size:11px;text-transform:uppercase;letter-spacing:1px;color:var(--muted);margin-bottom:10px;font-weight:700;">Calcul theorique</div>
                <div class="balance-row">
                    <span>Fond de caisse depart</span>
                    <span><?= $f($fondDepart) ?> FCFA</span>
                </div>
                <div class="balance-row">
                    <span>+ Ventes especes</span>
                    <span style="color:var(--success);">+<?= $f($totalEspeces) ?> FCFA</span>
                </div>
                <div class="balance-row">
                    <span>+ Entrees diverses</span>
                    <span style="color:var(--success);">+<?= $f($totalEntrees) ?> FCFA</span>
                </div>
                <div class="balance-row">
                    <span>- Sorties / Depenses</span>
                    <span style="color:var(--danger);">-<?= $f($totalSorties) ?> FCFA</span>
                </div>
                <div class="balance-row total-row">
                    <span>Solde calcule</span>
                    <span><?= $f($soldeCalc) ?> FCFA</span>
                </div>
            </div>

            <!-- Colonne droite : comparaison theorique vs reel -->
            <div>
                <div style="font-size:11px;text-transform:uppercase;letter-spacing:1px;color:var(--muted);margin-bottom:10px;font-weight:700;">Comparaison Theorique / Reel</div>
                <div class="balance-row">
                    <span>Montant theorique systeme</span>
                    <span><?= $f($montantTheo) ?> FCFA</span>
                </div>
                <div class="balance-row">
                    <span>Montant reel compte</span>
                    <span><?= $f($montantReel) ?> FCFA</span>
                </div>
                <hr class="divider">
                <div class="balance-row ecart-row" style="background:<?= $ecartCaisse == 0 ? '#dcfce7' : ($ecartCaisse > 0 ? '#dbeafe' : '#fee2e2') ?>;">
                    <span><?= $ecartLabel ?></span>
                    <span style="<?= $ecartStyle ?>"><?= ($ecartCaisse >= 0 ? '+' : '') . $f($ecartCaisse) ?> FCFA</span>
                </div>
                <?php if ($isOpen): ?>
                <div style="margin-top:10px;background:#fef3c7;border-radius:6px;padding:8px 12px;font-size:11px;color:#92400e;">
                    Session encore ouverte — les montants sont provisoires et susceptibles d'evoluer.
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- ══ GRAPHE HEURES ══ -->
    <div class="section">
        <div class="section-title">Activite Horaire des Ventes (07h–21h)</div>
        <div style="display:flex;align-items:flex-end;justify-content:center;gap:4px;height:80px;padding:0 8px;">
            <?= $chartHeures ?>
        </div>
        <div style="text-align:center;font-size:10px;color:var(--muted);margin-top:6px;">
            Heure de pointe : <strong style="color:#f59e0b;"><?= $heurePointe ?>h</strong>
            — CA : <strong><?= $f($caPointe) ?> FCFA</strong>
        </div>
    </div>

    <!-- ══ TOP 10 PRODUITS ══ -->
    <?php if (!empty($topProduits)): ?>
    <div class="section">
        <div class="section-title">Top <?= count($topProduits) ?> Produits Vendus</div>
        <table class="rtable">
            <thead>
                <tr>
                    <th style="width:30px;">#</th>
                    <th>Produit</th>
                    <th style="text-align:center;">Qte</th>
                    <th>CA Produit</th>
                </tr>
            </thead>
            <tbody><?= $lignesTop ?></tbody>
        </table>
    </div>
    <?php endif; ?>

    <!-- ══ DERNIERES VENTES ══ -->
    <div class="section">
        <div class="section-title">10 Dernieres Ventes de la Session</div>
        <table class="rtable">
            <thead>
                <tr>
                    <th>Heure</th>
                    <th>Client</th>
                    <th style="text-align:right;">Montant</th>
                    <th style="text-align:center;">Mode</th>
                    <th style="text-align:right;">Remise</th>
                </tr>
            </thead>
            <tbody><?= $lignesVentes ?></tbody>
        </table>
        <?php if ($nbVentes > 10): ?>
        <div style="text-align:center;margin-top:8px;font-size:11px;color:var(--muted);">
            ... et <?= $nbVentes - 10 ?> autre(s) vente(s) non affichee(s)
        </div>
        <?php endif; ?>
    </div>

    <!-- ══ MOUVEMENTS CAISSE ══ -->
    <div class="section">
        <div class="section-title">Mouvements Caisse Hors-Ventes</div>
        <table class="rtable">
            <thead>
                <tr>
                    <th>Heure</th>
                    <th>Type</th>
                    <th>Motif</th>
                    <th style="text-align:right;">Montant</th>
                </tr>
            </thead>
            <tbody><?= $lignesMvt ?></tbody>
        </table>
        <?php if (!empty($mouvements)): ?>
        <div style="display:flex;gap:24px;margin-top:10px;justify-content:flex-end;">
            <span style="font-size:12px;">Total entrees : <strong style="color:var(--success);">+<?= $f($totalEntrees) ?> FCFA</strong></span>
            <span style="font-size:12px;">Total sorties : <strong style="color:var(--danger);">-<?= $f($totalSorties) ?> FCFA</strong></span>
            <span style="font-size:12px;">Net : <strong><?= $f($totalEntrees - $totalSorties) ?> FCFA</strong></span>
        </div>
        <?php endif; ?>
    </div>

    <!-- ══ FOOTER ══ -->
    <div class="footer">
        <div>
            <span class="tag tag-gray">PROVISOIRE</span>
            <span style="margin-left:8px;">Ce document est genere automatiquement</span>
        </div>
        <div class="generated">Genere le <?= $now ?> — Session #<?= $idSession ?></div>
    </div>

</div><!-- /page -->
</body>
</html>
    <?php
    $html = ob_get_clean();

    echo json_encode(['success' => true, 'html' => $html]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Erreur SQL : ' . htmlspecialchars($e->getMessage())
    ]);
}
?>