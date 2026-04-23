<?php
/**
 * fetch_achats_detail.php
 * Retourne TOUTES les lignes d'achat d'un produit + stats JSON
 * Tables : detail_achats, achats, fournisseurs, stocks
 */
include 'db.php';

// ── Validation entrées ──
$id_produit = intval($_POST['id_produit'] ?? 0);
if ($id_produit <= 0) {
    echo json_encode(['html' => "<tr><td colspan='10' class='text-danger'>Produit invalide.</td></tr>", 'stats' => null]);
    exit;
}

$params     = [$id_produit];
$conditions = ["da.id_produit = ?"];

// ── Filtre : dates ──
if (!empty($_POST['f_debut']) && !empty($_POST['f_fin'])) {
    $conditions[] = "a.date_achat BETWEEN ? AND ?";
    $params[]     = $_POST['f_debut'] . " 00:00:00";
    $params[]     = $_POST['f_fin']   . " 23:59:59";
}

// ── Filtre : statut paiement ──
if (!empty($_POST['f_statut'])) {
    $conditions[] = "a.statut_paiement = ?";
    $params[]     = $_POST['f_statut'];
}

// ── Filtre : mode règlement ──
if (!empty($_POST['f_mode'])) {
    $conditions[] = "a.mode_reglement = ?";
    $params[]     = $_POST['f_mode'];
}

$where = implode(" AND ", $conditions);

// ── Requête principale ──
$sql = "
    SELECT
        da.id_detail_achat,
        da.quantite_recue,
        da.prix_achat_unitaire,
        da.date_peremption,
        (da.quantite_recue * da.prix_achat_unitaire) AS sous_total,

        a.id_achat,
        a.num_facture,
        a.date_achat,
        a.statut_paiement,
        a.mode_reglement,
        a.montant_total        AS total_facture,
        a.montant_paye,
        a.date_echeance,

        f.nom_fournisseur,
        f.telephone            AS tel_fournisseur,

        -- Stock lié (numéro de lot)
        s.numero_lot,
        s.quantite_disponible  AS stock_actuel

    FROM detail_achats da
    JOIN achats      a  ON da.id_achat      = a.id_achat
    JOIN fournisseurs f ON f.id_fournisseur  = a.id_fournisseur
    LEFT JOIN stocks  s ON s.id_produit      = da.id_produit
                       AND s.date_reception  = a.date_achat

    WHERE {$where}

    ORDER BY a.date_achat ASC
";

// ── Requête stats ──
$sqlStats = "
    SELECT
        COUNT(DISTINCT a.id_achat)                            AS nb_factures,
        SUM(da.quantite_recue)                                AS qte_totale,
        SUM(da.quantite_recue * da.prix_achat_unitaire)       AS montant_total,
        AVG(da.prix_achat_unitaire)                           AS prix_moyen

    FROM detail_achats da
    JOIN achats a ON da.id_achat = a.id_achat

    WHERE {$where}
";

try {
    // ── Exécuter stats ──
    $stmtStats = $pdo->prepare($sqlStats);
    $stmtStats->execute($params);
    $stats = $stmtStats->fetch(PDO::FETCH_ASSOC);

    // ── Exécuter lignes ──
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($rows)) {
        echo json_encode([
            'html'  => "<tr><td colspan='10' class='text-center text-muted py-4'>Aucun achat trouvé pour ce produit.</td></tr>",
            'stats' => $stats
        ]);
        exit;
    }

    // ── Calcul prix moyen pour détection tendance ──
    $prix_moyen_global = floatval($stats['prix_moyen']);

    $html = '';
    foreach ($rows as $r) {

        // Badge statut paiement
        $statut = $r['statut_paiement'];
        $badge_statut = match($statut) {
            'paye'    => '<span class="badge bg-success">Payé</span>',
            'partiel' => '<span class="badge bg-warning text-dark">Partiel</span>',
            'impaye'  => '<span class="badge bg-danger">Impayé</span>',
            default   => '<span class="badge bg-secondary">' . htmlspecialchars($statut) . '</span>',
        };

        // Badge mode règlement
        $mode_label = match($r['mode_reglement']) {
            'especes'      => '<i class="fas fa-money-bill-wave text-success me-1"></i>Espèces',
            'cheque'       => '<i class="fas fa-money-check text-primary me-1"></i>Chèque',
            'virement'     => '<i class="fas fa-university text-info me-1"></i>Virement',
            'mobile_money' => '<i class="fas fa-mobile-alt text-warning me-1"></i>Mobile',
            default        => htmlspecialchars($r['mode_reglement'] ?? 'N/A'),
        };

        // Date péremption avec alerte
        $peremption_html = 'N/A';
        if (!empty($r['date_peremption'])) {
            $datePerem = new DateTime($r['date_peremption']);
            $today     = new DateTime();
            $diffDays  = $today->diff($datePerem)->days;
            $isPast    = $today > $datePerem;

            if ($isPast) {
                $peremption_html = "<span class='text-danger fw-bold'><i class='fas fa-exclamation-triangle me-1'></i>"
                                 . $datePerem->format('d/m/Y') . " (Expiré)</span>";
            } elseif ($diffDays <= 90) {
                $peremption_html = "<span class='text-warning fw-bold'>"
                                 . $datePerem->format('d/m/Y') . " ({$diffDays}j)</span>";
            } else {
                $peremption_html = "<span class='text-muted'>" . $datePerem->format('d/m/Y') . "</span>";
            }
        }

        // Tendance prix unitaire vs moyenne
        $prix_unit    = floatval($r['prix_achat_unitaire']);
        $sous_total   = floatval($r['sous_total']);
        $tendance     = '';
        if ($prix_moyen_global > 0) {
            if ($prix_unit > $prix_moyen_global * 1.05) {
                $tendance = " <i class='fas fa-arrow-up text-danger' title='Au-dessus de la moyenne'></i>";
            } elseif ($prix_unit < $prix_moyen_global * 0.95) {
                $tendance = " <i class='fas fa-arrow-down text-success' title='En-dessous de la moyenne'></i>";
            }
        }

        $lot = htmlspecialchars($r['numero_lot'] ?? 'N/A');

        $html .= "
        <tr>
            <td class='text-muted small'>" . date('d/m/Y', strtotime($r['date_achat'])) . "</td>
            <td><strong>" . htmlspecialchars($r['num_facture']) . "</strong></td>
            <td class='small'>" . htmlspecialchars($r['nom_fournisseur']) . "
                <br><small class='text-muted'>" . htmlspecialchars($r['tel_fournisseur'] ?? '') . "</small>
            </td>
            <td class='text-center fw-bold'>" . number_format(floatval($r['quantite_recue']), 0) . "</td>
            <td class='text-center'>" . number_format($prix_unit, 0, ',', ' ') . " FCFA{$tendance}</td>
            <td class='text-center fw-bold text-success'>" . number_format($sous_total, 0, ',', ' ') . " FCFA</td>
            <td class='small text-muted'>{$lot}</td>
            <td>{$peremption_html}</td>
            <td class='text-center'>{$badge_statut}";

        // Afficher montant payé si partiel
        if ($statut === 'partiel') {
            $html .= "<br><small class='text-muted'>"
                   . number_format(floatval($r['montant_paye']), 0, ',', ' ')
                   . " / "
                   . number_format(floatval($r['total_facture']), 0, ',', ' ')
                   . " FCFA</small>";
        }

        $html .= "
            </td>
            <td class='text-center small'>{$mode_label}</td>
        </tr>";
    }

    echo json_encode([
        'html'  => $html,
        'stats' => [
            'nb_factures'  => intval($stats['nb_factures']),
            'qte_totale'   => floatval($stats['qte_totale']),
            'montant_total'=> floatval($stats['montant_total']),
            'prix_moyen'   => floatval($stats['prix_moyen']),
        ]
    ]);

} catch (Exception $e) {
    echo json_encode([
        'html'  => "<tr><td colspan='10' class='text-danger'>Erreur : " . htmlspecialchars($e->getMessage()) . "</td></tr>",
        'stats' => null
    ]);
}
?>