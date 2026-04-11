<?php
require_once 'db.php';

if (isset($_POST['action']) && $_POST['action'] == 'get_all_alerts') {
    $html = "";
    
    // 1. Requête pour les produits dont la date expire dans moins de 6 mois
    $sql = "SELECT p.nom_commercial, p.emplacement, s.numero_lot, s.date_peremption, s.quantite_disponible,
            DATEDIFF(s.date_peremption, NOW()) as jours_restants
            FROM stocks s
            JOIN produits p ON s.id_produit = p.id_produit
            WHERE s.quantite_disponible > 0 
            AND s.date_peremption <= DATE_ADD(NOW(), INTERVAL 6 MONTH)
            ORDER BY s.date_peremption ASC";
            
    $stmt = $pdo->query($sql);
    $alertes = $stmt->fetchAll();

    foreach ($alertes as $a) {
        $color = ($a['jours_restants'] < 30) ? '#e53e3e' : '#dd6b20'; // Rouge si < 1 mois, orange sinon
        $label = ($a['jours_restants'] < 0) ? 'PÉRIMÉ' : $a['jours_restants'] . ' jours';

        $html .= "<tr>";
        $html .= "<td><strong>" . htmlspecialchars($a['nom_commercial']) . "</strong></td>";
        $html .= "<td><small>" . $a['numero_lot'] . " / " . $a['emplacement'] . "</small></td>";
        $html .= "<td style='color:$color; font-weight:bold;'>" . date('d/m/Y', strtotime($a['date_peremption'])) . "</td>";
        $html .= "<td>" . $a['quantite_disponible'] . " unités</td>";
        $html .= "<td style='text-align:center;'><span style='background:$color; color:white; padding:3px 8px; border-radius:10px; font-size:11px;'>$label</span></td>";
        $html .= "</tr>";
    }

    if (empty($alertes)) {
        $html = "<tr><td colspan='5' style='text-align:center; padding:20px;'>Aucune alerte critique pour le moment.</td></tr>";
    }

    echo json_encode([
        'status' => 'success',
        'html_table' => $html,
        'count_perimes' => count($alertes),
        'count_ruptures' => 0 // Tu peux ajouter une autre requête pour le seuil_alerte ici
    ]);
    exit;
}