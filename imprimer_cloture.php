<?php
require_once 'db.php';
session_start();

// On récupère la dernière clôture effectuée avec toutes les nouvelles colonnes
$stmt = $pdo->prepare("SELECT c.*, u.nom_complet as caissier 
                       FROM clotures c 
                       LEFT JOIN utilisateurs u ON c.id_utilisateur = u.id_user 
                       ORDER BY c.id_cloture DESC LIMIT 1");
$stmt->execute();
$cloture = $stmt->fetch();

if (!$cloture) die("Aucune clôture trouvée.");

// Calcul du panier moyen pour l'affichage
$panier_moyen = ($cloture['nb_ventes'] > 0) ? ($cloture['montant_final'] / $cloture['nb_ventes']) : 0;
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Rapport de Clôture #<?php echo $cloture['id_cloture']; ?></title>
    <style>
        body { font-family: 'Courier New', monospace; font-size: 12px; width: 75mm; margin: 0; padding: 5mm; color: #000; }
        .center { text-align: center; }
        .bold { font-weight: bold; }
        .dashed { border-top: 1px dashed #000; margin: 8px 0; }
        .double-dashed { border-top: 3px double #000; margin: 10px 0; }
        .flex { display: flex; justify-content: space-between; margin: 4px 0; }
        .kpi-box { border: 1px solid #000; padding: 5px; margin: 10px 0; font-size: 11px; }
        @media print { .no-print { display: none; } }
    </style>
</head>
<body onload="window.print();">

    <div class="center">
        <h3 style="margin:0;">PHARMASSIST</h3>
        <p style="margin:2px 0; font-size:14px;" class="bold">RAPPORT DE CLÔTURE Z</p>
        <p style="font-size:11px;">Date: <?php echo date('d/m/Y H:i', strtotime($cloture['date_cloture'])); ?></p>
        <p style="font-size:11px;">ID Clôture: #<?php echo $cloture['id_cloture']; ?></p>
    </div>

    <div class="dashed"></div>

    <div class="bold">RÉCAPITULATIF FINANCIER</div>
    
    <div class="flex">
        <span>Ventes ESPÈCES :</span>
        <span><?php echo number_format($cloture['total_especes'], 0, '.', ' '); ?> F</span>
    </div>
    <div class="flex">
        <span>Ventes MOBILE :</span>
        <span><?php echo number_format($cloture['total_mobile_money'], 0, '.', ' '); ?> F</span>
    </div>
    <div class="flex">
        <span>Ventes ASSURANCE :</span>
        <span><?php echo number_format($cloture['total_assurance'], 0, '.', ' '); ?> F</span>
    </div>

    <div class="dashed"></div>

    <div class="flex" style="font-size: 15px; font-weight: bold;">
        <span>TOTAL ENCAISSÉ :</span>
        <span><?php echo number_format($cloture['montant_final'], 0, '.', ' '); ?> F</span>
    </div>

    <div class="double-dashed"></div>

    <div class="bold">PERFORMANCE DU JOUR</div>
    
    <div class="flex">
        <span>Nombre de ventes :</span>
        <span><?php echo $cloture['nb_ventes']; ?></span>
    </div>
    <div class="flex">
        <span>Panier Moyen :</span>
        <span><?php echo number_format($panier_moyen, 0, '.', ' '); ?> F</span>
    </div>
    <div class="flex">
        <span>Marge Brute :</span>
        <span class="bold"><?php echo number_format($cloture['marge_brute'], 0, '.', ' '); ?> F</span>
    </div>

    <?php if(!empty($cloture['top_produit'])): ?>
    <div class="kpi-box">
        <div class="center bold">PRODUIT PHARE</div>
        <div class="center"><?php echo strtoupper($cloture['top_produit']); ?></div>
    </div>
    <?php endif; ?>

    <div class="dashed"></div>

    <div style="margin-top: 10px; font-size: 11px;">
        <p>Caissier : <?php echo strtoupper($cloture['caissier']); ?></p>
        <div style="margin-top: 20px; border: 1px solid #ccc; height: 50px; position: relative;">
            <span style="font-size: 9px; position: absolute; top: 2px; left: 5px;">Signature & Tampon :</span>
        </div>
        <br>
        <p class="center" style="font-size: 10px;">*** Fin du rapport journalier ***</p>
    </div>

    <div class="no-print" style="margin-top: 20px; text-align:center;">
        <hr>
        <button onclick="window.print()" style="padding: 10px;">Imprimer</button>
        <button onclick="window.close()" style="padding: 10px;">Fermer</button>
    </div>

</body>
</html>