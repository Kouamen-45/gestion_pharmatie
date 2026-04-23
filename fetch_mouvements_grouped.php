<?php
// fetch_mouvements_grouped.php
include 'db.php';

$params     = [];
$conditions = ["1=1"];

if (!empty($_POST['f_nom'])) {
    $conditions[] = "p.nom_commercial LIKE ?";
    $params[]     = "%" . $_POST['f_nom'] . "%";
}
if (!empty($_POST['f_type'])) {
    $conditions[] = "m.type_mouvement = ?";
    $params[]     = $_POST['f_type'];
}
if (!empty($_POST['f_debut']) && !empty($_POST['f_fin'])) {
    $conditions[] = "m.date_mouvement BETWEEN ? AND ?";
    $params[]     = $_POST['f_debut'] . " 00:00:00";
    $params[]     = $_POST['f_fin']   . " 23:59:59";
}

/*
 * On regroupe par produit :
 *  - Nombre de mouvements
 *  - Total entrées (entree_achat, ajustement_inventaire, retour_fournisseur)
 *  - Total sorties (sortie_vente, casse, perime)
 *  - Date du dernier mouvement
 */
$sql = "
    SELECT
        p.id_produit,
        p.nom_commercial,
        COUNT(m.id_mouvement)                                                        AS nb_mvt,
        SUM(CASE WHEN m.type_mouvement IN ('entree_achat','ajustement_inventaire','retour_fournisseur')
                 THEN m.quantite ELSE 0 END)                                         AS total_entrees,
        SUM(CASE WHEN m.type_mouvement IN ('sortie_vente','casse','perime')
                 THEN m.quantite ELSE 0 END)                                         AS total_sorties,
        MAX(m.date_mouvement)                                                        AS dernier_mvt
    FROM mouvements_stock m
    JOIN produits p ON m.id_produit = p.id_produit
    WHERE " . implode(" AND ", $conditions) . "
    GROUP BY p.id_produit, p.nom_commercial
    ORDER BY dernier_mvt DESC
    LIMIT 200
";

try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    if ($stmt->rowCount() == 0) {
        echo "<tr><td colspan='7' class='text-center text-muted py-4'>
                <i class='fas fa-inbox me-2'></i>Aucun mouvement trouvé pour ces critères.
              </td></tr>";
        exit;
    }

    $entrees_types = ['entree_achat', 'ajustement_inventaire', 'retour_fournisseur'];

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {

        $id_p       = $row['id_produit'];
        $nom        = htmlspecialchars($row['nom_commercial']);
        $nb_mvt     = intval($row['nb_mvt']);
        $t_entrees  = floatval($row['total_entrees']);
        $t_sorties  = floatval($row['total_sorties']);
        $dernier    = $row['dernier_mvt'];

        // ---- Calcul du stock actuel via dernier inventaire validé ----
        $stmtInv = $pdo->prepare("
            SELECT il.stock_reel, i.date_debut
            FROM inventaire_lignes il
            JOIN inventaires i ON il.id_inventaire = i.id_inventaire
            WHERE il.id_produit = ?
              AND i.statut = 'valide'
            ORDER BY i.date_debut DESC
            LIMIT 1
        ");
        $stmtInv->execute([$id_p]);
        $inv         = $stmtInv->fetch();
        $stock_base  = $inv ? floatval($inv['stock_reel']) : 0;
        $date_inv    = $inv ? $inv['date_debut']           : '1970-01-01 00:00:00';

        // Mouvements APRES le dernier inventaire (pour le stock actuel)
        $stmtPost = $pdo->prepare("
            SELECT
                SUM(CASE WHEN type_mouvement IN ('entree_achat','ajustement_inventaire','retour_fournisseur')
                         THEN quantite ELSE 0 END) AS post_e,
                SUM(CASE WHEN type_mouvement IN ('sortie_vente','casse','perime')
                         THEN quantite ELSE 0 END) AS post_s
            FROM mouvements_stock
            WHERE id_produit = ?
              AND date_mouvement > ?
        ");
        $stmtPost->execute([$id_p, $date_inv]);
        $post        = $stmtPost->fetch();
        $stock_actuel = $stock_base + ($post['post_e'] ?? 0) - ($post['post_s'] ?? 0);

        // Badge couleur stock
        $stock_class = $stock_actuel > 0 ? 'text-success fw-bold' : ($stock_actuel < 0 ? 'text-danger fw-bold' : 'text-warning fw-bold');

        // Balance visuelle
        $balance     = $t_entrees - $t_sorties;
        $bal_class   = $balance >= 0 ? 'text-success' : 'text-danger';
        ?>
        <tr
            data-id-produit="<?= $id_p ?>"
            data-nom-produit="<?= $nom ?>"
            data-nb-mvt="<?= $nb_mvt ?>"
            data-total-entrees="<?= number_format($t_entrees, 0) ?>"
            data-total-sorties="<?= number_format($t_sorties, 0) ?>"
            data-stock-actuel="<?= number_format($stock_actuel, 0) ?>"
            style="cursor:pointer;"
            title="Cliquer pour voir le détail des mouvements"
        >
            <!-- Produit -->
            <td>
                <strong><?= $nom ?></strong>
                <div>
                    <span class="badge bg-primary-subtle text-primary" style="font-size:11px;">
                        <?= $nb_mvt ?> mouvement<?= $nb_mvt > 1 ? 's' : '' ?>
                    </span>
                </div>
            </td>

            <!-- Nb Mouvements (icone détail) -->
            <td class="text-center">
                <span class="badge bg-secondary"><?= $nb_mvt ?></span>
            </td>

            <!-- Total Entrées -->
            <td class="text-center text-success fw-bold">
                +<?= number_format($t_entrees, 0) ?>
            </td>

            <!-- Total Sorties -->
            <td class="text-center text-danger fw-bold">
                -<?= number_format($t_sorties, 0) ?>
            </td>

            <!-- Stock Actuel -->
            <td class="text-center <?= $stock_class ?>">
                <?= number_format($stock_actuel, 0) ?>
            </td>

            <!-- Dernier Mouvement -->
            <td class="text-center text-muted" style="font-size:12px;">
                <?= $dernier ? date('d/m/y H:i', strtotime($dernier)) : '—' ?>
            </td>

            <!-- Bouton détail -->
            <td class="text-center">
                <span class="btn btn-outline-primary btn-sm py-0 px-2" style="font-size:12px;">
                    <i class="fas fa-eye me-1"></i>Voir
                </span>
            </td>
        </tr>
        <?php
    }

} catch (Exception $e) {
    echo "<tr><td colspan='7' class='text-center text-danger'>Erreur : " . htmlspecialchars($e->getMessage()) . "</td></tr>";
}
?>