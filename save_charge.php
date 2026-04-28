<?php
session_start();
require_once 'db.php';
$action = $_POST['action'];

   /* ── update ── */
    if ($action === 'update') {
        $id      = intval($_POST['id_charge']        ?? 0);
        $date    = $_POST['date_operation']           ?? '';
        $libelle = trim($_POST['libelle_operation']   ?? '');
        $montant = floatval($_POST['montant']         ?? 0);
        $compte  = $_POST['code_compte']              ?? '';
        $mode    = $_POST['mode_paiement']            ?? '';
        $comment = trim($_POST['commentaire']         ?? '');

        if (!$id || !$date || !$libelle || $montant <= 0 || !$compte || !$mode) {
            echo json_encode(['success'=>false,'message'=>'Champs obligatoires manquants.']); exit();
        }

        $stmt = $pdo->prepare("UPDATE charges SET date_operation=:d, libelle_operation=:l, montant=:m, code_compte=:c, mode_paiement=:mp, commentaire=:co WHERE id_charge=:id");
        $stmt->execute([':d'=>$date,':l'=>$libelle,':m'=>$montant,':c'=>$compte,':mp'=>$mode,':co'=>$comment,':id'=>$id]);

        $pdo->prepare("INSERT INTO logs_activites (utilisateur, action_type, description, date_action, ip_adresse) VALUES (:u,'CHARGE_UPDATE',:desc,NOW(),:ip)")
            ->execute([':u'=>$_SESSION['user_id'],':desc'=>"Charge #$id modifiee: $libelle",':ip'=>$_SERVER['REMOTE_ADDR']??'']);

        echo json_encode(['success'=>true,'message'=>'Charge mise a jour avec succes.']); exit();
    }

    /* ── delete ── */
    if ($action === 'delete') {
        $id = intval($_POST['id_charge'] ?? 0);
        if (!$id) { echo json_encode(['success'=>false,'message'=>'ID invalide.']); exit(); }

        $row = $pdo->prepare("SELECT libelle_operation FROM charges WHERE id_charge=?");
        $row->execute([$id]);
        $lib = $row->fetchColumn() ?: "ID $id";

        $pdo->prepare("DELETE FROM charges WHERE id_charge=?")->execute([$id]);

        $pdo->prepare("INSERT INTO logs_activites (utilisateur, action_type, description, date_action, ip_adresse) VALUES (:u,'CHARGE_DELETE',:desc,NOW(),:ip)")
            ->execute([':u'=>$_SESSION['user_id'],':desc'=>"Charge supprimee: $lib",':ip'=>$_SERVER['REMOTE_ADDR']??'']);

        echo json_encode(['success'=>true,'message'=>'Charge supprimee.']); exit();
    }

  /* ── create ── */
    if ($action === 'create') {
    	//var_dump($_POST);
       $date    = $_POST['date_operation']    ?? '';
        $libelle = trim($_POST['libelle_operation'] ?? '');
        $montant = floatval($_POST['montant']  ?? 0);
        $compte  = $_POST['code_compte']       ?? '';
        $mode    = $_POST['mode_paiement']     ?? 'Espèces';
        $comment = trim($_POST['commentaire']  ?? '');

        if (!$date || !$libelle || $montant <= 0 || !$compte || !$mode) {
            echo json_encode(['success'=>false,'message'=>'Champs obligatoires manquants.','data'=>$date]); exit();
        }

        $stmt = $pdo->prepare("INSERT INTO charges (date_operation, libelle_operation, montant, code_compte, mode_paiement, commentaire, created_at) VALUES (:d,:l,:m,:c,:mp,:co, NOW())");
        $stmt->execute([':d'=>$date,':l'=>$libelle,':m'=>$montant,':c'=>$compte,':mp'=>$mode,':co'=>$comment]);
        $newId = $pdo->lastInsertId();

       
        $pdo->prepare("INSERT INTO logs_activites (utilisateur, action_type, description, date_action, ip_adresse) VALUES (:u,'CHARGE_CREATE',:desc,NOW(),:ip)")
            ->execute([':u'=>$_SESSION['user_id'],':desc'=>"Charge creee: $libelle ($montant FCFA)",':ip'=>$_SERVER['REMOTE_ADDR']??'']);

        echo json_encode(['success'=>true,'id'=>$newId,'message'=>'Charge enregistree avec succes.']); exit();
    }

?>