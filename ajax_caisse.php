<?php
session_start();
require_once 'db.php';
header('Content-Type: application/json');

$action = $_POST['action'] ?? '';
$userId = $_SESSION['user_id'] ?? null;

if (!$userId) {
    echo json_encode(['status' => 'error', 'message' => 'Session expirée']);
    exit;
}

// ACTION : OUVRIR LA CAISSE
if ($action === 'ouvrir') {
    $m = $_POST['montant'];

    // 1. Créer la session
    $pdo->prepare("INSERT INTO sessions_caisse (fond_caisse_depart, id_utilisateur, statut, date_ouverture) VALUES (?, ?, 'ouvert', NOW())")
        ->execute([$m, $userId]);
    
    // 2. Enregistrer le mouvement d'ouverture
    $pdo->prepare("INSERT INTO caisse (type_mouvement, montant, motif, id_utilisateur, date_mouvement) VALUES ('ouverture', ?, 'Fond de caisse initial', ?, NOW())")
        ->execute([$m, $userId]);

    echo json_encode(['status' => 'success']);
}

// ACTION : ENREGISTRER UN MOUVEMENT (DÉPENSE/ENTRÉE)
elseif ($action === 'mouvement') {
    // Ajout de id_utilisateur pour le suivi
    $pdo->prepare("INSERT INTO caisse (type_mouvement, montant, motif, id_utilisateur, date_mouvement) VALUES (?, ?, ?, ?, NOW())")
        ->execute([$_POST['type'], $_POST['montant'], $_POST['motif'], $userId]);
    echo json_encode(['status' => 'success']);
}

// ACTION : CLÔTURER LA SESSION
if ($_POST['action'] == 'cloturer') {
    $reel = $_POST['reel'];
    $theo = $_POST['theorique'];
    $ecart = $reel - $theo;
    $user_id = $_SESSION['user_id'];

    $sql = "UPDATE sessions_caisse SET 
            date_fermeture = NOW(), 
            montant_reel = ?, 
            montant_theorique = ?, 
            ecart = ?, 
            statut = 'fermé' 
            WHERE statut = 'ouvert' AND id_utilisateur = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$reel, $theo, $ecart, $user_id]);
    echo json_encode(['success' => true]);
}

// ACTION : RÉCUPÉRER LES DÉTAILS (GET)
if (isset($_GET['action']) && $_GET['action'] === 'get_session_details') {
    $id_session = $_GET['id_session'];
    
    $stmtS = $pdo->prepare("SELECT date_ouverture, date_cloture FROM sessions_caisse WHERE id_session = ?");
    $stmtS->execute([$id_session]);
    $sess = $stmtS->fetch();

    if ($sess) {
        $stmtM = $pdo->prepare("SELECT * FROM caisse 
                                WHERE date_mouvement >= ? AND (date_mouvement <= ? OR ? IS NULL)
                                ORDER BY date_mouvement ASC");
        $stmtM->execute([$sess['date_ouverture'], $sess['date_cloture'], $sess['date_cloture']]);
        $mouvements = $stmtM->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode($mouvements);
    } else {
        echo json_encode([]);
    }
    exit;
}