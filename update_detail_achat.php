<?php
/**
 * update_detail_achat.php
 * Mise a jour en cascade :
 *   1. detail_achats  → quantite_recue + prix_achat_unitaire
 *   2. stocks         → quantite_disponible (delta) + prix_achat
 *   3. achats         → montant_total (recalcul complet)
 *   4. logs_activites → trace de l'action
 */
include 'db.php';
session_start();

header('Content-Type: application/json');

// ── Lecture et validation des entrées ──
$idDetail   = intval($_POST['id_detail_achat'] ?? 0);
$idAchat    = intval($_POST['id_achat']        ?? 0);
$idStock    = intval($_POST['id_stock']        ?? 0);
$newQte     = floatval($_POST['new_quantite']  ?? 0);
$newPrix    = floatval($_POST['new_prix']      ?? 0);
$oldQte     = floatval($_POST['old_quantite']  ?? 0);
$oldPrix    = floatval($_POST['old_prix']      ?? 0);

if ($idDetail <= 0 || $idAchat <= 0 || $newQte <= 0 || $newPrix <= 0) {
    echo json_encode(['success' => false, 'message' => 'Parametres invalides.']);
    exit;
}

$idUtilisateur = $_SESSION['id_user'] ?? null;

try {
    $pdo->beginTransaction();

    // ══════════════════════════════════════
    // ETAPE 1 : Mettre a jour detail_achats
    // ══════════════════════════════════════
    $stmtDetail = $pdo->prepare("
        UPDATE detail_achats
        SET quantite_recue       = ?,
            prix_achat_unitaire  = ?
        WHERE id_detail_achat    = ?
    ");
    $stmtDetail->execute([$newQte, $newPrix, $idDetail]);

    if ($stmtDetail->rowCount() === 0) {
        throw new Exception('Ligne detail_achats introuvable (id=' . $idDetail . ').');
    }

    // ══════════════════════════════════════
    // ETAPE 2 : Mettre a jour le stock lie
    // ══════════════════════════════════════
    if ($idStock > 0) {

        // 2a. Mise a jour du prix_achat dans stocks
        if ($newPrix !== $oldPrix) {
            $pdo->prepare("
                UPDATE stocks SET prix_achat = ? WHERE id_stock = ?
            ")->execute([$newPrix, $idStock]);
        }

        // 2b. Ajustement de la quantite_disponible par delta
        if ($newQte !== $oldQte) {
            $delta = $newQte - $oldQte;   // positif = on ajoute, negatif = on retire
            $pdo->prepare("
                UPDATE stocks
                SET quantite_disponible = GREATEST(0, quantite_disponible + ?)
                WHERE id_stock = ?
            ")->execute([$delta, $idStock]);
        }

    }

    // ══════════════════════════════════════
    // ETAPE 3 : Recalcul montant_total achats
    // ══════════════════════════════════════
    $stmtTotal = $pdo->prepare("
        SELECT SUM(quantite_recue * prix_achat_unitaire) AS total
        FROM detail_achats
        WHERE id_achat = ?
    ");
    $stmtTotal->execute([$idAchat]);
    $nouveauTotal = floatval($stmtTotal->fetchColumn() ?? 0);

    $pdo->prepare("
        UPDATE achats SET montant_total = ? WHERE id_achat = ?
    ")->execute([$nouveauTotal, $idAchat]);

    // ══════════════════════════════════════
    // ETAPE 4 : Log de l'activite
    // ══════════════════════════════════════
    if ($idUtilisateur) {
        $description = sprintf(
            'Modification detail_achat #%d : qte %s->%s | prix %s->%s FCFA | achat #%d | nouveau total achat : %s FCFA',
            $idDetail,
            $oldQte, $newQte,
            $oldPrix, $newPrix,
            $idAchat,
            number_format($nouveauTotal, 0, ',', ' ')
        );
        $pdo->prepare("
            INSERT INTO logs_activites
                (utilisateur, action_type, description, date_action, ip_adresse)
            VALUES (?, 'MODIFICATION_ACHAT', ?, NOW(), ?)
        ")->execute([
            $idUtilisateur,
            $description,
            $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'
        ]);
    }

    $pdo->commit();

    echo json_encode([
        'success'       => true,
        'message'       => 'Modification enregistree avec succes. Nouveau montant total commande : '
                           . number_format($nouveauTotal, 0, ',', ' ') . ' FCFA',
        'nouveau_total' => $nouveauTotal
    ]);

} catch (Exception $e) {
    $pdo->rollBack();
    echo json_encode([
        'success' => false,
        'message' => 'Erreur : ' . htmlspecialchars($e->getMessage())
    ]);
}
?>