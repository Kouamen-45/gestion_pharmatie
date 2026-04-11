<?php
// Inclure votre connexion PDO ici
 include 'db.php'; 

$params = [];
$conditions = ["1=1"];

// Filtre Nom Produit
if (!empty($_POST['f_nom'])) {
    $conditions[] = "p.nom_commercial LIKE ?";
    $params[] = "%" . $_POST['f_nom'] . "%";
}

// Filtre Type
if (!empty($_POST['f_type'])) {
    $conditions[] = "m.type_mouvement = ?";
    $params[] = $_POST['f_type'];
}

// Filtre Dates
if (!empty($_POST['f_debut']) && !empty($_POST['f_fin'])) {
    $conditions[] = "m.date_mouvement BETWEEN ? AND ?";
    $params[] = $_POST['f_debut'] . " 00:00:00";
    $params[] = $_POST['f_fin'] . " 23:59:59";
}

$sqlM = "SELECT m.*, p.nom_commercial, p.id_produit, s.numero_lot 
         FROM mouvements_stock m 
         JOIN produits p ON m.id_produit = p.id_produit 
         LEFT JOIN stocks s ON m.id_stock = s.id_stock
         WHERE " . implode(" AND ", $conditions) . "
         ORDER BY m.date_mouvement DESC LIMIT 100";

try {
    $stmtM = $pdo->prepare($sqlM);
    $stmtM->execute($params);
    
    if ($stmtM->rowCount() == 0) {
        echo "<tr><td colspan='6' class='text-center'>Aucun mouvement trouvé.</td></tr>";
    }

    while($m = $stmtM->fetch()) {
        // --- VOTRE LOGIQUE DE CALCUL (Gardée identique) ---
        $id_p = $m['id_produit'];
        $date_mvt = $m['date_mouvement'];
        $qte_mvt = floatval($m['quantite']);

        // Dernier Inventaire
        $stmtInv = $pdo->prepare("SELECT il.stock_reel, i.date_debut FROM inventaire_lignes il JOIN inventaires i ON il.id_inventaire = i.id_inventaire WHERE il.id_produit = ? AND i.statut = 'valide' AND i.date_debut <= ? ORDER BY i.date_debut DESC LIMIT 1");
        $stmtInv->execute([$id_p, $date_mvt]);
        $inv = $stmtInv->fetch();
        $stock_depart = $inv ? floatval($inv['stock_reel']) : 0;
        $date_depart = $inv ? $inv['date_debut'] : '1970-01-01 00:00:00';

        // Flux intermediaires
        $stmtFlux = $pdo->prepare("SELECT SUM(CASE WHEN type_mouvement IN ('entree_achat', 'ajustement_inventaire', 'retour_fournisseur') THEN quantite ELSE 0 END) as total_e, SUM(CASE WHEN type_mouvement IN ('sortie_vente', 'casse', 'perime') THEN quantite ELSE 0 END) as total_s FROM mouvements_stock WHERE id_produit = ? AND date_mouvement > ? AND date_mouvement < ?");
        $stmtFlux->execute([$id_p, $date_depart, $date_mvt]);
        $flux = $stmtFlux->fetch();

        $stock_initial_ligne = $stock_depart + ($flux['total_e'] ?? 0) - ($flux['total_s'] ?? 0);
        $entrees = ['entree_achat', 'ajustement_inventaire', 'retour_fournisseur'];
        $isEntree = in_array($m['type_mouvement'], $entrees);
        $color = $isEntree ? 'text-success' : 'text-danger';
        
        $stock_final_ligne = $isEntree ? ($stock_initial_ligne + $qte_mvt) : ($stock_initial_ligne - $qte_mvt);
        ?>
        <tr>
            <td class="text-muted"><?= date('d/m/y H:i', strtotime($m['date_mouvement'])) ?></td>
            <td><strong><?= htmlspecialchars($m['nom_commercial']) ?></strong><br><small>Lot: <?= $m['numero_lot'] ?? 'N/A' ?></small></td>
            <td><span class="badge border <?= $isEntree ? 'bg-light text-success' : 'bg-light text-danger' ?>"><?= str_replace('_', ' ', strtoupper($m['type_mouvement'])) ?></span></td>
            <td class="text-center"><?= number_format($stock_initial_ligne, 0) ?></td>
            <td class="text-center <?= $color ?> fw-bold"><?= ($isEntree ? '+' : '-') . $qte_mvt ?></td>
            <td class="text-center fw-bold bg-light"><?= number_format($stock_final_ligne, 0) ?></td>
        </tr>
        <?php
    }
} catch (Exception $e) { echo "Erreur: " . $e->getMessage(); }