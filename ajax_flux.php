<?php
require_once 'db.php'; // Ta connexion PDO

if (isset($_POST['action']) && $_POST['action'] == 'filtrer_mouvements') {
    $debut = $_POST['debut'] . " 00:00:00";
    $fin = $_POST['fin'] . " 23:59:59";

    // Ta requête SQL de synthèse groupée par produit
    $sql = "SELECT p.id_produit, p.nom_commercial, 
                   MAX(m.date_mouvement) as derniere_date,
                   COUNT(m.id_mouvement) as nb_operations,
                   SUM(CASE WHEN m.type_mouvement = 'ENTREE' THEN m.quantite ELSE 0 END) as total_entrees,
                   SUM(CASE WHEN m.type_mouvement = 'SORTIE' THEN m.quantite ELSE 0 END) as total_sorties
            FROM produits p
            INNER JOIN mouvements_stock m ON p.id_produit = m.id_produit
            WHERE m.date_mouvement BETWEEN ? AND ?
            GROUP BY p.id_produit
            ORDER BY derniere_date DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$debut, $fin]);
    $mouvements = $stmt->fetchAll();

    if (count($mouvements) > 0) {
        foreach ($mouvements as $m) {
            echo '<tr class="row-flux" ondblclick="ouvrirDetailFlux('.$m['id_produit'].', \''.addslashes($m['nom_commercial']).'\')" style="cursor:pointer;">';
            echo '<td><strong>' . htmlspecialchars($m['nom_commercial']) . '</strong></td>';
            echo '<td style="font-size:0.85rem">' . date('d/m/y H:i', strtotime($m['derniere_date'])) . '</td>';
            echo '<td style="text-align:center;"><span class="badge-cat">' . $m['nb_operations'] . ' flux</span></td>';
            echo '<td style="color:green; font-weight:bold;">+ ' . $m['total_entrees'] . '</td>';
            echo '<td style="color:red; font-weight:bold;">- ' . $m['total_sorties'] . '</td>';
            echo '</tr>';
        }
    } else {
        echo '<tr><td colspan="5" style="text-align:center; padding:20px;">Aucun mouvement trouvé pour cette période.</td></tr>';
    }
    exit;
}