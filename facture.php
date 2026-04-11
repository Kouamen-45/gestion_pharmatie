<?php
require_once 'db.php';
$id = $_GET['id'] ?? 0;
$vente = $pdo->prepare("SELECT * FROM ventes WHERE id_vente = ?");
$vente->execute([$id]);
$v = $vente->fetch();
if(!$v) exit('Vente non trouvée');

$details = $pdo->prepare("SELECT dv.*, p.nom_commercial FROM detail_ventes dv JOIN produits p ON dv.id_produit = p.id_produit WHERE id_vente = ?");
$details->execute([$id]);
?>
<!DOCTYPE html>
<html>
<head>
    <style>
        body { width: 80mm; font-family: monospace; font-size: 12px; padding: 5px; }
        .center { text-align: center; }
        table { width: 100%; margin-top: 10px; }
        @media print { .btn { display: none; } }
    </style>
</head>
<body onload="window.print()">
    <div class="center">
        <h2>PHARMASSIST</h2>
        <p>Ticket #<?= $v['id_vente'] ?> du <?= $v['date_vente'] ?></p>
    </div>
    <hr>
    <table>
        <?php while($d = $details->fetch()): ?>
        <tr>
            <td><?= $d['nom_commercial'] ?> x<?= $d['quantite'] ?></td>
            <td align="right"><?= $d['prix_unitaire'] * $d['quantite'] ?> F</td>
        </tr>
        <?php endwhile; ?>
    </table>
    <hr>
    <h3 class="center">TOTAL: <?= $v['total'] ?> FCFA</h3>
    <p class="center">Merci de votre confiance !</p>
</body>
</html>