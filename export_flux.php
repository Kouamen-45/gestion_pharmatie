<?php
require_once 'db.php';

$format = $_GET['format'] ?? 'excel';
$debut_raw = $_GET['debut'];
$fin_raw = $_GET['fin'];
$debut = $debut_raw . " 00:00:00";
$fin = $fin_raw . " 23:59:59";

// Requête SQL
$sql = "SELECT p.nom_commercial, 
               MAX(m.date_mouvement) as derniere_date,
               SUM(CASE WHEN m.type_mouvement = 'ENTREE' THEN m.quantite ELSE 0 END) as total_entrees,
               SUM(CASE WHEN m.type_mouvement = 'SORTIE' THEN m.quantite ELSE 0 END) as total_sorties
        FROM produits p
        INNER JOIN mouvements_stock m ON p.id_produit = m.id_produit
        WHERE m.date_mouvement BETWEEN ? AND ?
        GROUP BY p.id_produit
        ORDER BY p.nom_commercial ASC";

$stmt = $pdo->prepare($sql);
$stmt->execute([$debut, $fin]);
$data = $stmt->fetchAll(PDO::FETCH_ASSOC);

// --- CAS EXCEL (CSV) ---
if ($format == 'excel') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=flux_'.$debut_raw.'.csv');
    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    fputcsv($output, ['Produit', 'Dernier Flux', 'Entrées', 'Sorties', 'Différence']);
    foreach ($data as $row) {
        fputcsv($output, [$row['nom_commercial'], $row['derniere_date'], $row['total_entrees'], $row['total_sorties'], ($row['total_entrees'] - $row['total_sorties'])]);
    }
    fclose($output);
    exit;
}

// --- CAS PDF (VERSION IMPRIMABLE) ---
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Rapport de Flux - <?= $debut_raw ?></title>
    <style>
        body { font-family: sans-serif; font-size: 12px; color: #333; padding: 20px; }
        .header { text-align: center; margin-bottom: 30px; border-bottom: 2px solid #333; padding-bottom: 10px; }
        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        th, td { border: 1px solid #ddd; padding: 10px; text-align: left; }
        th { background-color: #f4f4f4; }
        .footer { margin-top: 30px; font-size: 10px; text-align: right; }
        @media print {
            .no-print { display: none; }
            body { padding: 0; }
        }
    </style>
</head>
<body onload="window.print()">
    <div class="no-print" style="background:#fff3cd; padding:10px; margin-bottom:20px; text-align:center;">
        <b>Mode Aperçu :</b> Utilisez Ctrl+P ou le bouton d'impression pour enregistrer en PDF.
    </div>

    <div class="header">
        <h1>RAPPORT SYNTHÉTIQUE DES MOUVEMENTS</h1>
        <p>Période du <strong><?= date('d/m/Y', strtotime($debut_raw)) ?></strong> au <strong><?= date('d/m/Y', strtotime($fin_raw)) ?></strong></p>
    </div>

    <table>
        <thead>
            <tr>
                <th>Produit</th>
                <th>Dernière Activité</th>
                <th style="text-align:right;">Entrées</th>
                <th style="text-align:right;">Sorties</th>
                <th style="text-align:right;">Flux Net</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($data as $row): 
                $net = $row['total_entrees'] - $row['total_sorties'];
            ?>
            <tr>
                <td><strong><?= htmlspecialchars($row['nom_commercial']) ?></strong></td>
                <td><?= date('d/m/Y H:i', strtotime($row['derniere_date'])) ?></td>
                <td style="text-align:right; color:green;">+ <?= $row['total_entrees'] ?></td>
                <td style="text-align:right; color:red;">- <?= $row['total_sorties'] ?></td>
                <td style="text-align:right; font-weight:bold;"><?= ($net > 0 ? '+' : '').$net ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <div class="footer">
        Document généré le <?= date('d/m/Y H:i') ?> - Logiciel de Gestion Pharmacie
    </div>
</body>
</html>