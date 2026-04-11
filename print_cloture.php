<?php
session_start();
require_once 'db.php';

$id_session = $_GET['id'] ?? null;
if (!$id_session) die("Session non spécifiée.");

// Récupération des infos de la session
$stmt = $pdo->prepare("SELECT s.*, u.nom_complet 
                       FROM sessions_caisse s 
                       JOIN utilisateurs u ON s.id_utilisateur = u.id_user 
                       WHERE s.id_session = ?");
$stmt->execute([$id_session]);
$s = $stmt->fetch();

if (!$s) die("Session introuvable.");

// Calcul des totaux par mode de paiement pour cette session
$stmtPay = $pdo->prepare("SELECT mode_paiement, SUM(total) as total 
                          FROM ventes 
                          WHERE date_vente BETWEEN ? AND ? 
                          GROUP BY mode_paiement");
$stmtPay->execute([$s['date_ouverture'], $s['date_cloture']]);
$paiements = $stmtPay->fetchAll(PDO::FETCH_KEY_PAIR);
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Ticket de Clôture #<?= $s['id_session'] ?></title>
    <style>
        body { font-family: 'Courier New', Courier, monospace; width: 80mm; margin: 0; padding: 5px; font-size: 13px; }
        .text-center { text-align: center; }
        .text-right { text-align: right; }
        .header { border-bottom: 1px dashed #000; padding-bottom: 10px; margin-bottom: 10px; }
        .section { margin-bottom: 10px; padding-bottom: 5px; border-bottom: 1px dashed #000; }
        table { width: 100%; }
        .bold { font-weight: bold; }
        @media print { .no-print { display: none; } }
    </style>
</head>
<body onload="window.print()">
    <div class="no-print">
        <button onclick="window.print()">Imprimer</button>
        <hr>
    </div>

    <div class="header text-center">
        <h2 style="margin:0;">PharmAssist</h2>
        <p>Yaoundé, Cameroun<br>
        Clôture de Caisse #<?= $s['id_session'] ?></p>
    </div>

    <div class="section">
        <p><b>Caissier:</b> <?= $s['nom_complet'] ?><br>
        <b>Ouverture:</b> <?= date('d/m H:i', strtotime($s['date_ouverture'])) ?><br>
        <b>Clôture:</b> <?= date('d/m H:i', strtotime($s['date_cloture'])) ?></p>
    </div>

    <div class="section">
        <p class="bold">RÉSUMÉ DES VENTES</p>
        <table>
            <tr><td>Espèces:</td><td class="text-right"><?= number_format($paiements['Espèces'] ?? 0, 0, '.', ' ') ?> F</td></tr>
            <tr><td>MoMo/OM:</td><td class="text-right"><?= number_format($paiements['Mobile Money'] ?? 0, 0, '.', ' ') ?> F</td></tr>
            <tr><td>Carte:</td><td class="text-right"><?= number_format($paiements['Carte'] ?? 0, 0, '.', ' ') ?> F</td></tr>
            <tr class="bold"><td>TOTAL VENTES:</td><td class="text-right"><?= number_format(array_sum($paiements), 0, '.', ' ') ?> F</td></tr>
        </table>
    </div>

    <div class="section">
        <p class="bold">COMPTAGE TIROIR</p>
        <table>
            <tr><td>Fond Initial:</td><td class="text-right"><?= number_format($s['fond_caisse_depart'], 0, '.', ' ') ?> F</td></tr>
            <tr><td>Théorique (Cash):</td><td class="text-right"><?= number_format($s['montant_theorique'], 0, '.', ' ') ?> F</td></tr>
            <tr><td>Réel Déclaré:</td><td class="text-right"><?= number_format($s['montant_final_reel'], 0, '.', ' ') ?> F</td></tr>
            <tr class="bold">
                <td>ÉCART:</td>
                <td class="text-right"><?= number_format($s['montant_final_reel'] - $s['montant_theorique'], 0, '.', ' ') ?> F</td>
            </tr>
        </table>
    </div>

    <div class="text-center" style="margin-top:20px;">
        <p>Signature Caissier<br><br><br>_________________</p>
    </div>
</body>
</html>