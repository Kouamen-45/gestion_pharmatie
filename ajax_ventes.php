<?php
session_start();
require_once 'db.php';
header('Content-Type: application/json');

// Vérification de la session de caisse
$sessionActive = $pdo->query("SELECT id_session FROM sessions_caisse WHERE statut = 'ouvert'")->fetch();
if (!$sessionActive) {
    echo json_encode(['status' => 'error', 'message' => 'Impossible de vendre : La caisse est fermée !']);
    exit;
}

// Vérification de l'utilisateur
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Session expirée']);
    exit;
}

$action = $_REQUEST['action'] ?? '';



// --- ACTION 2 : DÉTAILS D'UNE VENTE ---
elseif ($action === 'get_details') {
    $id = $_GET['id_vente'] ?? 0;
    $stmt = $pdo->prepare("SELECT dv.*, p.nom_commercial FROM details_ventes dv JOIN produits p ON dv.id_produit = p.id_produit WHERE dv.id_vente = ?");
    $stmt->execute([$id]);
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
}

if ($_POST['action'] == 'get_recap_session') {
    // On récupère l'ID de la session ouverte
    $session = $pdo->query("SELECT id_session FROM sessions_caisse WHERE statut = 'ouvert' LIMIT 1")->fetch();
    
    // Somme des ventes en espèces pour cette session
    $sqlEsp = "SELECT SUM(total) as total FROM ventes WHERE id_session = ? AND mode_reglement = 'espece'";
    $especes = $pdo->prepare($sqlEsp);
    $especes->execute([$session['id_session']]);
    
    // Somme des ventes digitales
    $sqlDig = "SELECT SUM(total) as total FROM ventes WHERE id_session = ? AND mode_reglement IN ('orange_money', 'mtn_money')";
    $digital = $pdo->prepare($sqlDig);
    $digital->execute([$session['id_session']]);

    echo json_encode([
        'especes' => (int)$especes->fetch()['total'],
        'digital' => (int)$digital->fetch()['total']
    ]);
    exit;
}

if ($_POST['action'] == 'cloturer_session') {
    $montant_reel = intval($_POST['montant_reel']);
    
    // 1. Mettre à jour la session (Heure de fin, montant réel, statut fermé)
    $stmt = $pdo->prepare("UPDATE sessions_caisse SET 
        date_fin = NOW(), 
        montant_final_reel = ?, 
        statut = 'ferme' 
        WHERE statut = 'ouvert'");
    
    if($stmt->execute([$montant_reel])) {
        echo json_encode(['status' => 'success']);
    }
    exit;
}

// --- ACTION 3 : ANNULER UNE VENTE (Restauration intelligente) ---
elseif ($action === 'cancel_sale') {
    $id = $_POST['id_vente'] ?? 0;
    try {
        $pdo->beginTransaction();
        
        $stmtDetails = $pdo->prepare("SELECT dv.*, p.rapport_boite_detail FROM details_ventes dv JOIN produits p ON dv.id_produit = p.id_produit WHERE dv.id_vente = ?");
        $stmtDetails->execute([$id]);
        
        foreach($stmtDetails->fetchAll() as $item) {
            // Re-convertir en unité de stock pour la restauration
            $qty_a_rendre = ($item['type_unite'] === 'boite') ? ($item['quantite'] * $item['rapport_boite_detail']) : $item['quantite'];

            // Restauration dans le lot le plus récent
            $pdo->prepare("UPDATE stocks SET quantite_disponible = quantite_disponible + ? WHERE id_produit = ? ORDER BY date_peremption DESC LIMIT 1")
                ->execute([$qty_a_rendre, $item['id_produit']]);
        }

        $pdo->prepare("DELETE FROM caisse WHERE id_vente = ?")->execute([$id]);
        $pdo->prepare("UPDATE ventes SET statut_vente = 'annulée' WHERE id_vente = ?")->execute([$id]);

        $pdo->commit();
        echo json_encode(['status' => 'success']);
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
}