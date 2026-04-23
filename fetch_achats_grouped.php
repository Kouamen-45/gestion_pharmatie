<?php
/**
 * fetch_achats_grouped.php
 * Retourne une ligne résumée par produit + JSON stats
 * Tables utilisées : detail_achats, achats, produits, fournisseurs, stocks
 */
include 'db.php';

$params     = [];
$conditions = ["1=1"];

// ── Filtre : nom produit ──
if (!empty($_POST['f_nom'])) {
    $conditions[] = "p.nom_commercial LIKE ?";
    $params[]     = "%" . trim($_POST['f_nom']) . "%";
}

// ── Filtre : fournisseur ──
if (!empty($_POST['f_fournisseur'])) {
    $conditions[] = "a.id_fournisseur = ?";
    $params[]     = intval($_POST['f_fournisseur']);
}

// ── Filtre : période ──
if (!empty($_POST['f_debut']) && !empty($_POST['f_fin'])) {
    $conditions[] = "a.date_achat BETWEEN ? AND ?";
    $params[]     = $_POST['f_debut'] . " 00:00:00";
    $params[]     = $_POST['f_fin']   . " 23:59:59";
}

$where = implode(" AND ", $conditions);

$sql = "
    SELECT
        p.id_produit,
        p.nom_commercial,
        p.molecule,
        p.prix_unitaire          AS prix_vente,
        p.prix_achat              AS prix_achat_ref,

        -- Fournisseur préféré (dernier achat)
        f_pref.nom_fournisseur   AS fournisseur_pref,

        -- Agrégats sur les lignes d'achat filtrées
        COUNT(DISTINCT a.id_achat)               AS nb_achats,
        SUM(da.quantite_recue)                   AS qte_totale,
        SUM(da.quantite_recue * da.prix_achat_unitaire) AS montant_total,
        AVG(da.prix_achat_unitaire)              AS prix_moyen,
        MIN(da.prix_achat_unitaire)              AS prix_min,
        MAX(da.prix_achat_unitaire)              AS prix_max,
        MAX(a.date_achat)                        AS dernier_achat,

        -- Fournisseur du dernier achat
        (
            SELECT f2.nom_fournisseur
            FROM achats a2
            JOIN detail_achats da2 ON da2.id_achat = a2.id_achat
            JOIN fournisseurs f2   ON f2.id_fournisseur = a2.id_fournisseur
            WHERE da2.id_produit = p.id_produit
            ORDER BY a2.date_achat ASC
            LIMIT 1
        ) AS dernier_fournisseur

    FROM detail_achats da
    JOIN achats  a  ON da.id_achat    = a.id_achat
    JOIN produits p ON da.id_produit  = p.id_produit
    LEFT JOIN fournisseurs f_pref ON f_pref.id_fournisseur = p.id_fournisseur_pref

    WHERE {$where}

    GROUP BY
        p.id_produit,
        p.nom_commercial,
        p.molecule,
        p.prix_unitaire,
        p.prix_achat,
        f_pref.nom_fournisseur

    ORDER BY dernier_achat ASC
";

try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($rows)) {
        $html = "<tr><td colspan='8' class='text-center text-muted py-4'>Aucun achat trouvé.</td></tr>";
        echo json_encode(['html' => $html, 'total' => 0]);
        exit;
    }

    $html  = '';
    $today = new DateTime();

    foreach ($rows as $r) {
        // ── Calcul ancienneté dernier achat ──
        $dernier = new DateTime($r['dernier_achat']);
        $diff    = $today->diff($dernier)->days;

        if ($diff === 0) {
            $badge_age   = '<span class="badge bg-success">Aujourd\'hui</span>';
        } elseif ($diff <= 7) {
            $badge_age   = "<span class='badge bg-success'>{$diff}j</span>";
        } elseif ($diff <= 30) {
            $badge_age   = "<span class='badge bg-warning text-dark'>{$diff}j</span>";
        } else {
            $badge_age   = "<span class='badge bg-danger'>{$diff}j</span>";
        }

        $montant_fmt  = number_format(floatval($r['montant_total']), 0, ',', ' ');
        $prix_moy_fmt = number_format(floatval($r['prix_moyen']),    0, ',', ' ');
        $qte_fmt      = number_format(floatval($r['qte_totale']),    0, ',', ' ');
        $date_fmt     = date('d/m/Y', strtotime($r['dernier_achat']));
        $molecule     = htmlspecialchars($r['molecule'] ?? '');
        $nom          = htmlspecialchars($r['nom_commercial']);
        $fourn_pref   = htmlspecialchars($r['fournisseur_pref'] ?? ($r['dernier_fournisseur'] ?? 'N/A'));

        // Évolution prix (dernier vs prix_achat_ref de la table produits)
        $prix_ref     = floatval($r['prix_achat_ref']);
        $prix_moy_val = floatval($r['prix_moyen']);
        $evolution    = '';
        if ($prix_ref > 0 && $prix_moy_val > 0) {
            $pct = (($prix_moy_val - $prix_ref) / $prix_ref) * 100;
            if ($pct > 2) {
                $evolution = "<small class='text-danger ms-1'><i class='fas fa-arrow-up'></i> " . number_format($pct, 1) . "%</small>";
            } elseif ($pct < -2) {
                $evolution = "<small class='text-success ms-1'><i class='fas fa-arrow-down'></i> " . number_format(abs($pct), 1) . "%</small>";
            }
        }

        $html .= "
        <tr class='row-produit-achat' style='cursor:pointer;'
            data-id-produit='{$r['id_produit']}'
            data-nom-produit='{$nom}'
            data-molecule='{$molecule}'>
            <td>
                <strong>{$nom}</strong>
                " . ($molecule ? "<br><small class='text-muted'>{$molecule}</small>" : "") . "
            </td>
            <td class='text-muted small'>{$fourn_pref}</td>
            <td class='text-center'>
                <span class='badge bg-primary rounded-pill'>{$r['nb_achats']}</span>
            </td>
            <td class='text-center fw-bold'>{$qte_fmt}</td>
            <td class='text-center fw-bold text-success'>{$montant_fmt} <small class='text-muted fw-normal'>FCFA</small></td>
            <td class='text-center'>{$prix_moy_fmt} <small class='text-muted'>FCFA</small>{$evolution}</td>
            <td class='text-center'>{$date_fmt} {$badge_age}</td>
            <td class='text-center'>
                <button class='btn btn-sm btn-outline-primary px-2' title='Voir le détail'>
                    <i class='fas fa-eye'></i>
                </button>
                <button class='btn btn-sm btn-outline-primary px-2 btn-edit-achat-ligne' title='Modifier les lignes'>
                    <i class='fas fa-edit'></i>
                </button>
            </td>
        </tr>";
    }

    echo json_encode(['html' => $html, 'total' => count($rows)]);

} catch (Exception $e) {
    echo json_encode([
        'html'  => "<tr><td colspan='8' class='text-danger'>Erreur : " . htmlspecialchars($e->getMessage()) . "</td></tr>",
        'total' => 0
    ]);
}
?>