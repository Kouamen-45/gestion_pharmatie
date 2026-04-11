<?php
require_once 'db.php';

$q = $_GET['q'] ?? '';

// Utilisation de 'prix_unitaire' selon ton schéma de base de données
$stmt = $pdo->prepare("
    SELECT p.id_produit, p.nom_commercial, p.prix_unitaire, 
           COALESCE(SUM(s.quantite_disponible), 0) as stock_total
    FROM produits p
    LEFT JOIN stocks s ON p.id_produit = s.id_produit
    WHERE p.nom_commercial LIKE ?
    GROUP BY p.id_produit
    LIMIT 10
");

$search = "%$q%";
$stmt->execute([$search]);
$results = $stmt->fetchAll();

if ($results) {
    echo '<table style="width:100%; border-collapse:collapse;">';
    foreach ($results as $r) {
        $color = ($r['stock_total'] <= 0) ? '#e74c3c' : '#2ecc71';
        echo "<tr style='cursor:pointer; border-bottom:1px solid #eee;' onclick=\"window.location='produit_mouvements.php?id=".$r['id_produit']."'\">
                <td style='padding:12px;'><b>".htmlspecialchars($r['nom_commercial'])."</b></td>
                <td style='text-align:right;'>Prix: <b>".number_format($r['prix_unitaire'], 0, '.', ' ')." F</b></td>
                <td style='text-align:right;'>Stock: <b style='color:$color;'>".$r['stock_total']."</b></td>
                <td style='text-align:right; color:#3498db;'><i class='fas fa-arrow-right'></i></td>
              </tr>";
    }
    echo '</table>';
} else {
    echo '<div style="padding:15px; color:#7f8c8d;">Aucun produit trouvé.</div>';
}