<?php
require_once 'db.php';

try {
    // Jointure entre ventes et clients
    $sql = "SELECT v.*, c.nom as client_nom, c.prenom as client_prenom 
            FROM ventes v 
            LEFT JOIN clients c ON v.id_client = c.id_client 
            WHERE DATE(v.date_vente) = CURDATE() 
            ORDER BY v.date_vente DESC LIMIT 50";
    
    $stmt = $pdo->query($sql);

    while ($v = $stmt->fetch()) {
        $heure = date('H:i', strtotime($v['date_vente']));
        $total = number_format($v['total'], 0, '.', ' ');
        $mode = strtoupper($v['mode_paiement']);
        
        // Logique d'affichage du nom
        $nomClient = ($v['id_client'] == 1) ? "Passage" : $v['client_nom'] . ' ' . $v['client_prenom'];

        echo "<tr>
                <td class='text-muted'>$heure</td>
                <td>
                    <span style='font-weight:600;'>$total F</span><br>
                    <small style='color:#888; font-size:11px;'><i class='fas fa-user-circle'></i> $nomClient</small>
                </td>
                <td><span class='badge border text-dark' style='font-size:10px;'>$mode</span></td>
                <td class='text-center'>
                    <button class='btn btn-sm p-0 text-primary' onclick='imprimerTicket({$v['id_vente']})'>
                        <i class='fas fa-print fa-lg'></i>
                    </button>
                </td>
              </tr>";
    }
} catch (Exception $e) {
    echo "<tr><td colspan='4'>Erreur : ".$e->getMessage()."</td></tr>";
}
?>