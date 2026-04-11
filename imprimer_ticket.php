<?php
require_once 'db.php';

if(!isset($_GET['id'])) die("ID Vente manquant");
$id_vente = intval($_GET['id']);

// 1. Récupérer les infos de la vente + Vendeur + Client
$stmt = $pdo->prepare("SELECT v.*, u.nom_complet as vendeur, c.nom as client_nom 
                       FROM ventes v 
                       LEFT JOIN utilisateurs u ON v.id_utilisateur = u.id_user 
                       LEFT JOIN clients c ON v.id_client = c.id_client
                       WHERE v.id_vente = ?");
$stmt->execute([$id_vente]);
$vente = $stmt->fetch();

if(!$vente) die("Vente introuvable");

// 2. Récupérer les articles (on ajoute dv.type_unite dans la sélection)
$stmtItems = $pdo->prepare("SELECT dv.*, p.nom_commercial 
                            FROM details_ventes dv 
                            JOIN produits p ON dv.id_produit = p.id_produit 
                            WHERE dv.id_vente = ?");
$stmtItems->execute([$id_vente]);
$items = $stmtItems->fetchAll();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Ticket #<?php echo $id_vente; ?></title>
    <style>
        body { font-family: 'Courier New', monospace; font-size: 13px; width: 75mm; margin: 0; padding: 5mm; color: #000; }
        .center { text-align: center; }
        .bold { font-weight: bold; }
        .dashed-line { border-top: 1px dashed #000; margin: 10px 0; }
        table { width: 100%; border-collapse: collapse; }
        th { text-align: left; font-size: 11px; border-bottom: 1px solid #000; }
        td { padding: 5px 0; vertical-align: top; border-bottom: 0.5px solid #eee; }
        .total-section { margin-top: 10px; font-size: 14px; }
        .footer { margin-top: 20px; font-size: 11px; }
        .unit-type { font-size: 10px; font-style: italic; color: #333; display: block; }
        @media print { .no-print { display: none; } }
    </style>
</head>
<body onload="window.print();">

    <div class="center">
        <h3 style="margin:0;">PHARMASSIST</h3>
        <p style="margin:2px 0;">Yaoundé, Marché Central<br>Tél: +237 6XX XXX XXX</p>
        <div class="dashed-line"></div>
        <p class="bold" style="margin:5px 0;">TICKET #<?php echo $id_vente; ?></p>
        <p style="font-size:11px;">Date: <?php echo date('d/m/Y H:i', strtotime($vente['date_vente'])); ?></p>
        
        <?php if($vente['id_client'] > 1): ?>
            <p class="bold" style="text-transform: uppercase;">Client: <?php echo htmlspecialchars($vente['client_nom']); ?></p>
        <?php endif; ?>
    </div>

    <table>
        <thead>
            <tr>
                <th>Désignation</th>
                <th style="text-align:center;">Qté</th>
                <th style="text-align:right;">Total</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach($items as $item): ?>
            <tr>
                <td>
                    <span class="bold"><?php echo strtoupper($item['nom_commercial']); ?></span>
                    <span class="unit-type">
                        (<?php echo ($item['type_unite'] === 'boite') ? 'VENTE EN GROS' : 'VENTE AU DÉTAIL'; ?>)
                    </span>
                </td>
                <td style="text-align:center;"><?php echo $item['quantite']; ?></td>
                <td style="text-align:right;"><?php echo number_format($item['prix_unitaire'] * $item['quantite'], 0, '.', ' '); ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <div class="dashed-line"></div>

    <div class="total-section">
        <div style="display: flex; justify-content: space-between;">
            <span>TOTAL BRUT :</span>
            <span><?php 
                // Calcul du total avant remise si tu as stocké le total net en base
                $total_affiche = $vente['total'];
                if($vente['remise'] > 0) $total_affiche += $vente['remise'];
                echo number_format($total_affiche, 0, '.', ' '); 
            ?> F</span>
        </div>

        <?php if($vente['remise'] > 0): ?>
            <div style="display: flex; justify-content: space-between; font-size: 12px; color: red;">
                <span>REMISE :</span>
                <span>-<?php echo number_format($vente['remise'], 0, '.', ' '); ?> F</span>
            </div>
        <?php endif; ?>

        <div style="display: flex; justify-content: space-between; margin-top: 5px; border-top: 1px solid #000; padding-top: 5px;">
            <span class="bold">TOTAL NET :</span>
            <span class="bold" style="font-size: 18px;"><?php echo number_format($vente['total'], 0, '.', ' '); ?> F</span>
        </div>

        <?php if($vente['part_assurance'] > 0): ?>
            <div style="display: flex; justify-content: space-between; font-size: 12px; margin-top: 3px;">
                <span>Dont Part Assurance :</span>
                <span><?php echo number_format($vente['part_assurance'], 0, '.', ' '); ?> F</span>
            </div>
            <div style="display: flex; justify-content: space-between; font-weight: bold;">
                <span>NET À PAYER (PATIENT) :</span>
                <span><?php echo number_format($vente['part_patient'], 0, '.', ' '); ?> F</span>
            </div>
        <?php endif; ?>
    </div>

    <div class="footer center">
        <p>Mode de règlement : <?php echo strtoupper($vente['mode_paiement']); ?></p>
        <p>Vendeur : <?php echo strtoupper($vente['vendeur']); ?></p>
        <div class="dashed-line"></div>
        <p class="bold">MERCI DE VOTRE CONFIANCE !</p>
        <p style="font-size: 9px;"><i>Les médicaments vendus ne sont ni repris ni échangés conformément à la réglementation pharmaceutique.</i></p>
    </div>

    <div class="no-print" style="margin-top: 30px; text-align: center;">
        <button onclick="window.print()" style="padding: 10px; cursor: pointer;">Imprimer</button>
        <button onclick="window.close()" style="padding: 10px; cursor: pointer;">Fermer</button>
    </div>

</body>
</html>