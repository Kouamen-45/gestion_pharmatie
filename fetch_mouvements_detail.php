<?php
// fetch_mouvements_detail.php
include 'db.php';

if (empty($_POST['id_produit'])) {
    echo "<tr><td colspan='7' class='text-center text-danger'>ID produit manquant.</td></tr>";
    exit;
}

$id_produit = intval($_POST['id_produit']);
$params     = [$id_produit];
$conditions = ["m.id_produit = ?"];

// Filtre type (optionnel, vient du formulaire principal)
if (!empty($_POST['f_type'])) {
    $conditions[] = "m.type_mouvement = ?";
    $params[]     = $_POST['f_type'];
}
// Filtre période (optionnel)
if (!empty($_POST['f_debut']) && !empty($_POST['f_fin'])) {
    $conditions[] = "m.date_mouvement BETWEEN ? AND ?";
    $params[]     = $_POST['f_debut'] . " 00:00:00";
    $params[]     = $_POST['f_fin']   . " 23:59:59";
}

$sql = "
    SELECT m.*, p.nom_commercial, p.id_produit, s.numero_lot
    FROM mouvements_stock m
    JOIN produits p  ON m.id_produit = p.id_produit
    LEFT JOIN stocks s ON m.id_stock  = s.id_stock
    WHERE " . implode(" AND ", $conditions) . "
    ORDER BY m.date_mouvement ASC
    LIMIT 500
";

try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    if ($stmt->rowCount() == 0) {
        echo "<tr><td colspan='7' class='text-center text-muted py-4'>
                <i class='fas fa-inbox me-2'></i>Aucun mouvement trouvé pour ce produit.
              </td></tr>";
        exit;
    }

    $entrees_types = ['entree_achat', 'ajustement_inventaire', 'retour_fournisseur'];

    while ($m = $stmt->fetch(PDO::FETCH_ASSOC)) {

        $id_p    = $m['id_produit'];
        $date_mvt = $m['date_mouvement'];
        $qte_mvt  = floatval($m['quantite']);

        // ---- Dernier inventaire validé AVANT ce mouvement ----
        $stmtInv = $pdo->prepare("
            SELECT il.stock_reel, i.date_debut
            FROM inventaire_lignes il
            JOIN inventaires i ON il.id_inventaire = i.id_inventaire
            WHERE il.id_produit = ?
              AND i.statut      = 'valide'
              AND i.date_debut  <= ?
            ORDER BY i.date_debut ASC
            LIMIT 1
        ");
        $stmtInv->execute([$id_p, $date_mvt]);
        $inv         = $stmtInv->fetch();
        $stock_base  = $inv ? floatval($inv['stock_reel']) : 0;
        $date_inv    = $inv ? $inv['date_debut']           : '1970-01-01 00:00:00';

        // ---- Flux intermédiaires entre inventaire et ce mouvement ----
        $stmtFlux = $pdo->prepare("
            SELECT
                SUM(CASE WHEN type_mouvement IN ('entree_achat','ajustement_inventaire','retour_fournisseur')
                         THEN quantite ELSE 0 END) AS total_e,
                SUM(CASE WHEN type_mouvement IN ('sortie_vente','casse','perime')
                         THEN quantite ELSE 0 END) AS total_s
            FROM mouvements_stock
            WHERE id_produit     = ?
              AND date_mouvement > ?
              AND date_mouvement < ?
        ");
        $stmtFlux->execute([$id_p, $date_inv, $date_mvt]);
        $flux = $stmtFlux->fetch();

        $stock_initial = $stock_base + ($flux['total_e'] ?? 0) - ($flux['total_s'] ?? 0);
        $isEntree      = in_array($m['type_mouvement'], $entrees_types);
        $stock_final   = $isEntree ? ($stock_initial + $qte_mvt) : ($stock_initial - $qte_mvt);

        // Classes CSS
        $badge_class   = $isEntree ? 'bg-success-subtle text-success border-success' : 'bg-danger-subtle text-danger border-danger';
        $mvt_class     = $isEntree ? 'text-success' : 'text-danger';
        $mvt_prefix    = $isEntree ? '+' : '-';
        $type_label    = str_replace('_', ' ', strtoupper($m['type_mouvement']));

        // Mise en évidence si stock final négatif
        $row_class     = $stock_final < 0 ? 'table-warning' : '';
        ?>
        <tr class="<?= $row_class ?>">
            <!-- Date -->
            <td class="text-muted" style="white-space:nowrap;">
                <?= date('d/m/y', strtotime($date_mvt)) ?>
                <div style="font-size:11px;"><?= date('H:i', strtotime($date_mvt)) ?></div>
            </td>

            <!-- Lot -->
            <td>
                <span class="badge bg-light text-dark border" style="font-size:11px;">
                    <?= htmlspecialchars($m['numero_lot'] ?? 'N/A') ?>
                </span>
            </td>

            <!-- Type -->
            <td>
                <span class="badge border <?= $badge_class ?>" style="font-size:11px;">
                    <?= $type_label ?>
                </span>
            </td>

            <!-- Qte Initiale -->
            <td class="text-center text-muted">
                <?= number_format($stock_initial, 0) ?>
            </td>

            <!-- Mouvement -->
            <td class="text-center <?= $mvt_class ?> fw-bold">
                <?= $mvt_prefix . number_format($qte_mvt, 0) ?>
            </td>

            <!-- Qte Finale -->
            <td class="text-center fw-bold <?= $stock_final < 0 ? 'text-danger' : '' ?>" style="background:#f8f9fa;">
                <?= number_format($stock_final, 0) ?>
                <?php if ($stock_final < 0): ?>
                    <i class="fas fa-exclamation-triangle text-warning ms-1" title="Stock negatif"></i>
                <?php endif; ?>
            </td>

            <!-- Note / Commentaire -->
            <td class="text-muted" style="font-size:12px; max-width:150px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">
                <?= htmlspecialchars($m['note'] ?? '—') ?>
            </td>
        </tr>
        <?php
    }

} catch (Exception $e) {
    echo "<tr><td colspan='7' class='text-center text-danger'>Erreur : " . htmlspecialchars($e->getMessage()) . "</td></tr>";
}
?>