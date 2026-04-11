<?php
require_once 'db.php';
session_start();

header('Content-Type: application/json');

if (isset($_POST['action']) && $_POST['action'] == 'cloturer_journee') {
    try {
        $pdo->beginTransaction();

        // 1. Calcul des statistiques basées sur ton schéma réel
        // On utilise p.prix_achat_unitaire de la table produits pour la marge
        $stmtStats = $pdo->prepare("
            SELECT 
                COUNT(DISTINCT v.id_vente) as nb_tickets,
                SUM(CASE WHEN v.mode_paiement = 'Espèces' THEN v.part_patient ELSE 0 END) as total_cash,
                SUM(CASE WHEN v.mode_paiement != 'Espèces' AND v.mode_paiement != 'Assurance' THEN v.part_patient ELSE 0 END) as total_mobile,
                SUM(v.part_assurance) as total_assur,
                SUM(v.total) as chiffre_affaire,
                -- Calcul de la marge : (Prix Vente - Prix Achat Moyen) * Qté
                SUM((dv.prix_unitaire - p.prix_unitaire_detail) * dv.quantite) as marge_brute
            FROM ventes v
            LEFT JOIN details_ventes dv ON v.id_vente = dv.id_vente
            LEFT JOIN produits p ON dv.id_produit = p.id_produit
            WHERE DATE(v.date_vente) = CURDATE()
        ");
        $stmtStats->execute();
        $stats = $stmtStats->fetch();

        $cash = intval($stats['total_cash'] ?? 0);
        $mobile = intval($stats['total_mobile'] ?? 0);
        $assurance = intval($stats['total_assur'] ?? 0);
        $nb_ventes = intval($stats['nb_tickets'] ?? 0);
        $marge = intval($stats['marge_brute'] ?? 0);
        $grand_total = $cash + $mobile;

        // 2. Récupérer le nom du produit le plus vendu aujourd'hui
        $stmtTop = $pdo->prepare("
            SELECT p.nom_commercial 
            FROM details_ventes dv
            JOIN produits p ON dv.id_produit = p.id_produit
            JOIN ventes v ON dv.id_vente = v.id_vente
            WHERE DATE(v.date_vente) = CURDATE()
            GROUP BY dv.id_produit 
            ORDER BY SUM(dv.quantite) DESC LIMIT 1
        ");
        $stmtTop->execute();
        $top = $stmtTop->fetch();
        $top_produit = $top['nom_commercial'] ?? "Aucun";

        // 3. Enregistrement dans la table CLOTURES
        $sqlCloture = "INSERT INTO clotures 
            (total_especes, total_mobile_money, total_assurance, montant_final, id_utilisateur) 
            VALUES (?, ?, ?, ?, ?)";
        
        $pdo->prepare($sqlCloture)->execute([
            $cash, $mobile, $assurance, $grand_total, $_SESSION['user_id']
        ]);
        $id_cloture = $pdo->lastInsertId();

        // 4. Sortie de caisse automatique (Vider le tiroir)
        if ($cash > 0) {
            $pdo->prepare("INSERT INTO caisse (type_mouvement, montant, motif, id_vente, date_mouvement) 
                           VALUES ('sortie', ?, ?, NULL, NOW())")
                ->execute([$cash, "Clôture Journalière #$id_cloture"]);
        }

        $pdo->commit();

        // 5. Retour des données pour l'affichage SweetAlert
        echo json_encode([
            'status' => 'success',
            'data' => [
                'especes' => number_format($cash, 0, '.', ' '),
                'mobile' => number_format($mobile, 0, '.', ' '),
                'assurance' => number_format($assurance, 0, '.', ' '),
                'total' => number_format($grand_total, 0, '.', ' '),
                'marge' => number_format($marge, 0, '.', ' '),
                'top_produit' => $top_produit,
                'nb_ventes' => $nb_ventes
            ]
        ]);

    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
}