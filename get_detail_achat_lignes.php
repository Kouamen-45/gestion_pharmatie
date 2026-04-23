<?php
/**
 * get_detail_achat_lignes.php
 * Retourne toutes les lignes detail_achats d'un produit (HTML pour edition)
 */
include 'db.php';

header('Content-Type: application/json');

$idProduit = intval($_POST['id_produit'] ?? 0);

if ($idProduit <= 0) {
    echo json_encode(['success' => false, 'message' => 'ID produit invalide.']);
    exit;
}

$sql = "
    SELECT
        da.id_detail_achat,
        da.id_achat,
        da.quantite_recue,
        da.prix_achat_unitaire,
        da.date_peremption,
        a.date_achat,
        a.num_facture,
        f.nom_fournisseur,
        -- Stock lie (meme produit + meme lot si possible)
        s.id_stock,
        s.numero_lot,
        s.quantite_disponible AS stock_actuel,
        s.prix_achat          AS stock_prix_achat
    FROM detail_achats da
    JOIN achats      a ON a.id_achat        = da.id_achat
    LEFT JOIN fournisseurs f ON f.id_fournisseur = a.id_fournisseur
    -- Jointure sur le stock correspondant au meme achat + produit
    LEFT JOIN stocks s ON  s.id_produit = da.id_produit
                       AND s.date_reception = DATE(a.date_achat)
    WHERE da.id_produit = ?
    ORDER BY a.date_achat DESC
";

try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$idProduit]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($rows)) {
        echo json_encode(['success' => false, 'message' => 'Aucune ligne d\'achat trouvee pour ce produit.']);
        exit;
    }

    $html = '';
    foreach ($rows as $r) {
        $dateAchat = date('d/m/Y', strtotime($r['date_achat']));
        $datePerem = $r['date_peremption']
            ? date('d/m/Y', strtotime($r['date_peremption']))
            : '<span class="text-muted">—</span>';
        $facture   = htmlspecialchars($r['num_facture'] ?? 'N/A');
        $fournisseur = htmlspecialchars($r['nom_fournisseur'] ?? 'N/A');
        $lot       = htmlspecialchars($r['numero_lot'] ?? '—');
        $idLigne   = (int)$r['id_detail_achat'];
        $idAchat   = (int)$r['id_achat'];
        $idStock   = (int)($r['id_stock'] ?? 0);
        $qte       = floatval($r['quantite_recue']);
        $prix      = floatval($r['prix_achat_unitaire']);
        $stockActuel = $r['stock_actuel'] !== null
            ? '<span class="badge bg-info text-dark">' . floatval($r['stock_actuel']) . '</span>'
            : '<span class="text-muted small">N/A</span>';

        // Badge avertissement si stock introuvable
        $stockWarning = $idStock === 0
            ? '<br><small class="text-warning"><i class="fas fa-exclamation-triangle"></i> Stock non lie</small>'
            : '';

        $html .= "
        <tr id='row-ligne-{$idLigne}'>
            <td class='small'><span class='badge bg-secondary'>{$facture}</span></td>
            <td class='small text-muted'>{$dateAchat}</td>
            <td class='small'>{$fournisseur}</td>
            <td class='text-center small'>
                {$lot}<br>
                <small class='text-muted'>Stock dispo : </small>{$stockActuel}
                {$stockWarning}
            </td>
            <td class='text-center'>
                <input type='number'
                       id='qte_{$idLigne}'
                       class='form-control form-control-sm text-center'
                       value='{$qte}'
                       data-original='{$qte}'
                       min='0.01'
                       step='0.01'
                       style='width:110px; margin:auto;'>
            </td>
            <td class='text-center'>
                <div class='input-group input-group-sm' style='width:140px; margin:auto;'>
                    <input type='number'
                           id='prix_{$idLigne}'
                           class='form-control form-control-sm text-center'
                           value='{$prix}'
                           data-original='{$prix}'
                           min='0.01'
                           step='1'>
                    <span class='input-group-text small px-1'>F</span>
                </div>
            </td>
            <td class='text-center small'>{$datePerem}</td>
            <td class='text-center'>
                <button class='btn btn-sm btn-success btn-save-ligne-achat px-2'
                        data-id-detail='{$idLigne}'
                        data-id-achat='{$idAchat}'
                        data-id-stock='{$idStock}'
                        title='Enregistrer'>
                    <i class='fas fa-save'></i>
                </button>
            </td>
        </tr>";
    }

    echo json_encode(['success' => true, 'html' => $html]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Erreur SQL : ' . htmlspecialchars($e->getMessage())
    ]);
}
?>