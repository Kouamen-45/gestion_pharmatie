<?php
session_start();
require_once 'db.php';
header('Content-Type: application/json');

// On récupère l'ID de l'utilisateur connecté pour la traçabilité
$id_user = $_SESSION['user_id'] ?? null;

function enregistrerLog($pdo, $action, $description) {
    // Si tu as un système de session, remplace 'Admin' par $_SESSION['nom_utilisateur']
    $utilisateur = $_SESSION['username']; 
    $ip = $_SERVER['REMOTE_ADDR'];
    
    $stmt = $pdo->prepare("INSERT INTO logs_activites (utilisateur, action_type, description, ip_adresse) VALUES (?, ?, ?, ?)");
    $stmt->execute([$utilisateur, $action, $description, $ip]);
}

try {
    $action = $_POST['action'] ?? '';

    // --- ENTRÉE DE STOCK (ACHAT) AVEC LOG ---
    if ($action == 'add_stock_entry') {
        $pdo->beginTransaction();
        
        // 1. Insertion du lot
        $stmt = $pdo->prepare("INSERT INTO stocks (id_produit, numero_lot, quantite_disponible, date_peremption, date_reception) VALUES (?, ?, ?, ?, CURDATE())");
        $stmt->execute([$_POST['id_produit'], $_POST['numero_lot'], $_POST['quantite'], $_POST['date_peremption']]);
        $id_stock = $pdo->lastInsertId();

        // 2. Création du mouvement (Log)
        $log = $pdo->prepare("INSERT INTO mouvements_stock (id_produit, id_stock, type_mouvement, quantite, id_utilisateur, motif) VALUES (?, ?, 'entree_achat', ?, ?, 'Réception de commande')");
        $log->execute([$_POST['id_produit'], $id_stock, $_POST['quantite'], $id_user]);

        $pdo->commit();
        echo json_encode(['status' => 'success', 'message' => 'Stock mis à jour et mouvement enregistré !']);
    }

    // --- AJUSTEMENT D'INVENTAIRE (STYLE WINPHARMA) ---
    elseif ($action == 'ajuster_stock') {
        $pdo->beginTransaction();
        
        $id_stock = $_POST['id_stock'];
        $nouvelle_qte = (int)$_POST['quantite_reelle'];
        $motif = $_POST['motif'] ?? 'Ajustement inventaire';

        // 1. Récupérer l'ancienne quantité pour calculer l'écart
        $stmt_old = $pdo->prepare("SELECT id_produit, quantite_disponible FROM stocks WHERE id_stock = ?");
        $stmt_old->execute([$id_stock]);
        $old_data = $stmt_old->fetch();

        if ($old_data) {
            $ecart = $old_data['quantite_disponible'] - $nouvelle_qte;

            // 2. Mise à jour de la quantité réelle dans le lot
            $upd = $pdo->prepare("UPDATE stocks SET quantite_disponible = ? WHERE id_stock = ?");
            $upd->execute([$ecart, $id_stock]);

            // 3. Enregistrer l'écart dans les mouvements
            if ($ecart != 0) {
                $type_mouv = ($ecart > 0) ? 'ajustement' : 'casse'; // Ou simplement 'ajustement'
                $log = $pdo->prepare("INSERT INTO mouvements_stock (id_produit, id_stock, type_mouvement, quantite, id_utilisateur, motif) VALUES (?, ?, ?, ?, ?, ?)");
                $log->execute([$old_data['id_produit'], $id_stock, $type_mouv, $nouvelle_qte, $id_user, $motif]);
            }
        }

        $pdo->commit();
        echo json_encode(['status' => 'success', 'message' => 'Ajustement enregistré']);
    }

if (isset($_POST['action']) && $_POST['action'] == 'add_prod') {
    
    $pdo->beginTransaction();

    try {
        // --- COLLECTE DES DONNÉES ---
        $est_divers = $_POST['est_divers'] ?? 0;
        $nom = $_POST['nom_commercial'];
        $prix_vente = floatval($_POST['prix_unitaire']); 
        $prix_achat = floatval($_POST['prix_achat'] ?? 0);
        $seuil = intval($_POST['seuil_alerte'] ?? 5);
        $emplacement = !empty($_POST['emplacement']) ? $_POST['emplacement'] : 'RAYON A';

        // Logique produit divers vs standard
        if ($est_divers == '1') {
            $molecule = 'DIVERS';
            $dosage = NULL;
            $id_sf = NULL;
            $id_f = NULL;
            $peremption_mois = 120; 
            $est_detail = 0;
            $coef = 1;
            $prix_detail = 0;
        } else {
            $molecule = $_POST['molecule'] ?? '';
            $dosage = $_POST['dosage'] ?? '';
            $id_sf = !empty($_POST['id_sous_famille']) ? $_POST['id_sous_famille'] : NULL;
            $id_f = !empty($_POST['id_fournisseur_pref']) ? $_POST['id_fournisseur_pref'] : NULL;
            $peremption_mois = intval($_POST['delai_peremption'] ?? 6);
            $est_detail = $_POST['est_detail'] ?? 0;
            $coef = ($est_detail == '1') ? intval($_POST['coefficient_division'] ?? 1) : 1;
            $prix_detail = ($est_detail == '1') ? floatval($_POST['prix_unitaire_detail'] ?? 0) : 0;
        }

        // --- ÉTAPE 1 : INSERTION DU PRODUIT ---
        $sqlProd = "INSERT INTO produits (
            nom_commercial, molecule, dosage, id_sous_famille, id_fournisseur_pref, 
            prix_unitaire, prix_unitaire_detail, prix_achat, seuil_alerte, 
            delai_peremption, est_divers, emplacement, est_detail, coefficient_division
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        
        $pdo->prepare($sqlProd)->execute([
            $nom, $molecule, $dosage, $id_sf, $id_f, 
            $prix_vente, $prix_detail, $prix_achat, $seuil, 
            $peremption_mois, $est_divers, $emplacement, $est_detail, $coef
        ]);

        $id_produit = $pdo->lastInsertId();

        // --- ÉTAPE 2 : GESTION DU STOCK ---
        $qty_boites = intval($_POST['qty_lot_boites'] ?? 0);
        $total_unites = $qty_boites * $coef;
        $id_stock = null;

        if ($total_unites >= 0) {
            $num_lot = !empty($_POST['num_lot']) ? $_POST['num_lot'] : 'INITIAL-' . date('dmY');
            $date_peremp = !empty($_POST['peremp_lot']) ? $_POST['peremp_lot'] : date('Y-m-d', strtotime('+2 years'));

            $sqlStock = "INSERT INTO stocks (id_produit, numero_lot, prix_achat, date_peremption, quantite_disponible, date_reception) 
                         VALUES (?, ?, ?, ?, ?, NOW())";
            $stmtStock = $pdo->prepare($sqlStock);
            $stmtStock->execute([$id_produit, $num_lot, $prix_achat, $date_peremp, $total_unites]);
            $id_stock = $pdo->lastInsertId();

            // --- ÉTAPE 3 : MOUVEMENT DE STOCK (Vérification colonne par colonne) ---
            $sqlMvt = "INSERT INTO mouvements_stock (
                id_produit, id_stock, type_mouvement, quantite, date_mouvement, id_utilisateur, commentaire, motif
            ) VALUES (?, ?, 'Initialisation du stock', ?, NOW(), ?, ?, ?)";
            
            $pdo->prepare($sqlMvt)->execute([
                $id_produit,
                $id_stock,
                $total_unites,
                $_SESSION['user_id'],
                "Initialisation du stock pour : $nom",
                "Création nouveau produit"
            ]);
        }

        // --- ÉTAPE 4 : INVENTAIRE UNIQUE (1 entête, 1 ligne) ---
        // On crée l'inventaire
        $sqlInv = "INSERT INTO inventaires (date_debut, type_inventaire, statut, id_utilisateur, commentaire) 
                   VALUES (NOW(), 'partiel', 'valide', ?, ?)";
        $pdo->prepare($sqlInv)->execute([
            $_SESSION['user_id'], 
            "Inventaire de création : $nom"
        ]);
        $id_inv = $pdo->lastInsertId();

        // On crée LA ligne d'inventaire unique
        $sqlInvLigne = "INSERT INTO inventaire_lignes (id_inventaire, id_produit, id_stock, stock_theorique, stock_reel, ecart) 
                        VALUES (?, ?, ?, ?, ?, 0)";
        $pdo->prepare($sqlInvLigne)->execute([
            $id_inv,
            $id_produit,
            $id_stock,
            $total_unites, // Théorique
            $total_unites  // Réel
        ]);

        $pdo->commit();
        echo json_encode(['status' => 'success', 'message' => 'Produit créé. Stock, Mouvement et Inventaire générés.']);

    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['status' => 'error', 'message' => 'Erreur : ' . $e->getMessage()]);
    }
    exit;
}
    // --- SUPPRESSION (CATÉGORIES / FOURNISSEURS) ---
    elseif ($action == 'delete') {
        $id = $_POST['id'];
        if ($_POST['type'] == 'produit') {
            // Attention : Winpharma ne supprime jamais vraiment, il archive. Ici on garde votre delete.
            $pdo->prepare("DELETE FROM produits WHERE id_produit = ?")->execute([$id]);
        } 
        elseif ($_POST['type'] == 'categorie') $pdo->prepare("DELETE FROM categories WHERE id_categorie = ?")->execute([$id]);
        elseif ($_POST['type'] == 'fournisseur') $pdo->prepare("DELETE FROM fournisseurs WHERE id_fournisseur = ?")->execute([$id]);
        echo json_encode(['status' => 'success']);
    }

    // --- MODIF PRIX ---
    elseif ($action == 'edit_price') {
        $pdo->prepare("UPDATE produits SET prix_unitaire = ? WHERE id_produit = ?")->execute([$_POST['prix'], $_POST['id']]);
        echo json_encode(['status' => 'success']);
    }

    // --- AJOUT CAT / FOURN ---
    elseif ($action == 'add_cat') {
        $pdo->prepare("INSERT INTO categories (nom_categorie) VALUES (?)")->execute([$_POST['nom_categorie']]);
        echo json_encode(['status' => 'success', 'message' => 'Catégorie ajoutée']);
    }
    elseif ($action == 'add_fourn') {
        $pdo->prepare("INSERT INTO fournisseurs (nom_fournisseur, telephone) VALUES (?, ?)")->execute([$_POST['nom_fournisseur'], $_POST['telephone']]);
        echo json_encode(['status' => 'success', 'message' => 'Fournisseur ajouté']);
    }

    // --- RÉCUPÉRER LES LOTS D'UN PRODUIT ---
    elseif ($action == 'get_lots') {
        $id = $_POST['id'];
        $stmt = $pdo->prepare("SELECT id_stock, numero_lot, quantite_disponible, date_peremption FROM stocks WHERE id_produit = ? AND quantite_disponible > 0 ORDER BY date_peremption ASC");
        $stmt->execute([$id]);
        $lots = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['status' => 'success', 'data' => $lots]);
    }

    // --- SUPPRIMER UN LOT (PÉRIMÉ OU ERREUR) AVEC TRACE ---
    elseif ($action == 'delete_lot') {
        $pdo->beginTransaction();
        $id_stock = $_POST['id_stock'];

        // On récupère les infos avant de supprimer pour le log
        $info = $pdo->prepare("SELECT id_produit, quantite_disponible FROM stocks WHERE id_stock = ?");
        $info->execute([$id_stock]);
        $data = $info->fetch();

        if ($data) {
            // Log de la sortie totale (Péremption/Retrait)
            $log = $pdo->prepare("INSERT INTO mouvements_stock (id_produit, id_stock, type_mouvement, quantite, id_utilisateur, motif) VALUES (?, ?, 'perime', ?, ?, 'Retrait définitif du lot')");
            $log->execute([$data['id_produit'], $id_stock, -$data['quantite_disponible'], $id_user]);

            $stmt = $pdo->prepare("DELETE FROM stocks WHERE id_stock = ?");
            $stmt->execute([$id_stock]);
        }

        $pdo->commit();
        echo json_encode(['status' => 'success']);
    }

    // --- RÉCUPÉRATION DU RAPPORT DES PERTES ---
elseif ($action == 'get_rapport_pertes') {
    $debut = $_POST['debut'];
    $fin = $_POST['fin'];

    // On cherche les mouvements négatifs (sorties, casses, périmés, ajustements négatifs)
    $stmt = $pdo->prepare("SELECT m.*, p.nom_commercial, p.prix_unitaire 
                           FROM mouvements_stock m 
                           JOIN produits p ON m.id_produit = p.id_produit 
                           WHERE (m.type_mouvement IN ('casse', 'perime', 'ajustement') OR m.quantite < 0)
                           AND DATE(m.date_mouvement) BETWEEN ? AND ?
                           ORDER BY m.date_mouvement DESC");
    $stmt->execute([$debut, $fin]);
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['status' => 'success', 'data' => $data]);
    exit;
}

elseif ($action == 'enregistrer_envoi_commande') {
    $id_fournisseur = $_POST['id_fournisseur'];
    $total = $_POST['total'];
    
    $stmt = $pdo->prepare("INSERT INTO commandes_envoyees (id_fournisseur, montant_total) VALUES (?, ?)");
    $stmt->execute([$id_fournisseur, $total]);
    
    echo json_encode(['status' => 'success', 'id_commande' => $pdo->lastInsertId()]);
    exit;
}

// Dans ajax_produits.php, modifiez la requête SQL :
elseif ($action == 'get_besoins_autopilote') {
    ob_clean(); 
    $id_f = $_POST['id_fournisseur'] ?? 0;

    $query = "
        SELECT 
            p.id_produit, 
            p.nom_commercial, 
            p.stock_max, 
            p.prix_unitaire, -- ON RÉCUPÈRE LE PRIX D'ACHAT ICI
            (SELECT IFNULL(SUM(quantite_disponible), 0) FROM stocks WHERE id_produit = p.id_produit) as stock_actuel,
            (SELECT IFNULL(SUM(dv.quantite), 0) / 30 
             FROM details_ventes dv 
             JOIN ventes v ON dv.id_vente = v.id_vente 
             WHERE dv.id_produit = p.id_produit 
             AND v.date_vente > DATE_SUB(NOW(), INTERVAL 30 DAY)) as cmj
        FROM produits p
        WHERE p.id_fournisseur_pref = ? 
        ORDER BY cmj DESC, p.nom_commercial ASC";

    try {
        $stmt = $pdo->prepare($query);
        $stmt->execute([$id_f]);
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        header('Content-Type: application/json');
        echo json_encode(['status' => 'success', 'data' => $data]);
        exit;
    } catch (Exception $e) {
        header('Content-Type: application/json');
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        exit;
    }
}

elseif ($action == 'update_product_supplier') {
    try {
        $id_p = $_POST['id_produit'];
        $id_f = $_POST['id_fournisseur'];
        
        $stmt = $pdo->prepare("UPDATE produits SET id_fournisseur_pref = ? WHERE id_produit = ?");
        $stmt->execute([$id_f, $id_p]);
        
        echo json_encode(['status' => 'success', 'message' => 'Fournisseur mis à jour']);
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
}

elseif ($action == 'recevoir_commande_complete') {
    try {
        $pdo->beginTransaction();

        // Récupération des données POST
        $lignes = $_POST['lignes'] ?? [];
        $id_f = $_POST['id_fournisseur'] ?? 0;
        $total_prevu = $_POST['total_prevu'] ?? 0; // Calculé par le JS
        $id_user = $_SESSION['user_id'] ?? 1;

        if (empty($lignes) || $id_f == 0) {
            throw new Exception("Données de commande incomplètes.");
        }

        // 1. Insertion dans la table maîtresse 'commandes'
        // Statut 'en_attente' car c'est un Bon de Commande (pas encore reçu)
        $stmt = $pdo->prepare("INSERT INTO commandes 
                               (id_fournisseur, date_commande, statut, total_prevu) 
                               VALUES (?, NOW(), 'en_attente', ?)");
        $stmt->execute([$id_f, $total_prevu]);
        
        $id_cmd = $pdo->lastInsertId(); // Récupère l'ID auto-incrémenté

        // 2. Insertion des lignes de détails
        $stmtL = $pdo->prepare("INSERT INTO commande_lignes 
                               (id_commande, id_produit, quantite_commandee, quantite_recue) 
                               VALUES (?, ?, ?, 0)");

        foreach ($lignes as $l) {
            // $l['id_p'] et $l['qte'] viennent du JSON envoyé par le JS
            $stmtL->execute([
                $id_cmd, 
                $l['id_p'], 
                $l['qte']
            ]);
        }

        // Validation de la transaction
        $pdo->commit();

        echo json_encode([
            'status' => 'success', 
            'id_commande' => $id_cmd,
            'message' => 'Bon de commande généré avec succès.'
        ]);

    } catch (Exception $e) {
        // En cas d'erreur, on annule tout pour éviter des données orphelines
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        echo json_encode([
            'status' => 'error', 
            'message' => 'Erreur SQL : ' . $e->getMessage()
        ]);
    }
    exit;
}

elseif ($action == 'enregistrer_envoi_commande') {
    try {
        $pdo->beginTransaction();
        $id_fournisseur = $_POST['id_fournisseur'];
        $total = $_POST['total'];
        $lignes = $_POST['lignes']; // On reçoit maintenant les détails

        // 1. Création de l'en-tête de commande
        $stmt = $pdo->prepare("INSERT INTO commandes_envoyees (id_fournisseur, montant_total, date_envoi, statut) VALUES (?, ?, NOW(), 'envoyé')");
        $stmt->execute([$id_fournisseur, $total]);
        $id_commande = $pdo->lastInsertId();

        // 2. Enregistrement de chaque ligne pour la comparaison future
        $stmtLigne = $pdo->prepare("INSERT INTO commande_lignes (id_commande, id_produit, quantite_commandee) VALUES (?, ?, ?)");
        foreach ($lignes as $l) {
            $stmtLigne->execute([$id_commande, $l['id_p'], $l['qte']]);
        }
        
        $pdo->commit();
        echo json_encode(['status' => 'success', 'id_commande' => $id_commande]);
    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
}


elseif ($action == 'creer_retour') {
    try {
        $pdo->beginTransaction();
        $id_p = $_POST['id_produit'];
        $qte = (int)$_POST['quantite'];
        $motif = $_POST['motif'];
        $id_user = $_SESSION['user_id'];

        // 1. On vérifie si le stock est suffisant
        $check = $pdo->prepare("SELECT IFNULL(SUM(quantite_disponible), 0) as total FROM stocks WHERE id_produit = ?");
        $check->execute([$id_p]);
        $stock_actuel = $check->fetchColumn();

        if ($stock_actuel < $qte) {
            throw new Exception("Stock insuffisant pour effectuer ce retour.");
        }

        // 2. Sortie de stock (On retire des lots les plus anciens d'abord - FIFO)
        $lots = $pdo->prepare("SELECT id_stock, quantite_disponible FROM stocks WHERE id_produit = ? AND quantite_disponible > 0 ORDER BY date_peremption ASC");
        $lots->execute([$id_p]);
        $reste_a_retirer = $qte;

        while ($reste_a_retirer > 0 && $lot = $lots->fetch()) {
            $retrait = min($lot['quantite_disponible'], $reste_a_retirer);
            
            $upd = $pdo->prepare("UPDATE stocks SET quantite_disponible = quantite_disponible - ? WHERE id_stock = ?");
            $upd->execute([$retrait, $lot['id_stock']]);

            // 3. Log du mouvement de sortie
            $log = $pdo->prepare("INSERT INTO mouvements_stock (id_produit, id_stock, type_mouvement, quantite, motif, id_utilisateur) 
                                   VALUES (?, ?, 'retour_fournisseur', ?, ?, ?)");
            $log->execute([$id_p, $lot['id_stock'], -$retrait, "Retour : $motif", $id_user]);

            $reste_a_retirer -= $retrait;
        }

        $pdo->commit();
        echo json_encode(['status' => 'success', 'message' => 'Produit retiré du stock. Prêt pour expédition fournisseur.']);
    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
}

elseif ($action == 'nettoyage_automatique_perimes') {
    try {
        $pdo->beginTransaction();
        
        // 1. Identifier tous les lots périmés avec une quantité > 0
        $stmt = $pdo->query("SELECT s.*, p.nom_commercial 
                             FROM stocks s 
                             JOIN produits p ON s.id_produit = p.id_produit 
                             WHERE s.date_peremption < CURDATE() 
                             AND s.quantite_disponible > 0");
        $perimes = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $count = 0;

        foreach ($perimes as $lot) {
            // 2. Créer le mouvement de sortie pour la traçabilité
            $log = $pdo->prepare("INSERT INTO mouvements_stock (id_produit, id_stock, type_mouvement, quantite, motif, id_utilisateur) 
                                   VALUES (?, ?, 'perime', ?, 'Nettoyage automatique des périmés', ?)");
            $log->execute([$lot['id_produit'], $lot['id_stock'], -$lot['quantite_disponible'], $id_user]);

            // 3. Mettre le stock à zéro (on ne supprime pas la ligne pour garder l'historique du lot)
            $upd = $pdo->prepare("UPDATE stocks SET quantite_disponible = 0 WHERE id_stock = ?");
            $upd->execute([$lot['id_stock']]);
            
            $count++;
        }

        $pdo->commit();
        echo json_encode(['status' => 'success', 'message' => "$count lots périmés ont été retirés du stock et archivés."]);
    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
}

// --- AJOUT PRODUIT AVEC EMPLACEMENT ET DESCRIPTION ---
/*elseif ($action == 'add_prod') {
    try {
        $stmt = $pdo->prepare("INSERT INTO produits 
            (nom_commercial, molecule, id_categorie, id_fournisseur_pref, prix_unitaire, seuil_alerte, description, emplacement) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        
        $stmt->execute([
            $_POST['nom_commercial'],
            $_POST['molecule'] ?? null,
            $_POST['id_categorie'],
            $_POST['id_fournisseur'],
            $_POST['prix_unitaire'],
            $_POST['seuil_alerte'],
            $_POST['description'] ?? null,
            $_POST['emplacement']
        ]);

        echo json_encode(['status' => 'success', 'message' => 'Fiche produit créée avec emplacement défini.']);
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => 'Erreur lors de la création : ' . $e->getMessage()]);
    }
    exit;
}*/

elseif ($action == 'maj_emplacements_groupe') {
    try {
        $ids = $_POST['ids']; // Tableau d'IDs
        $emplacement = $_POST['emplacement'];

        // Sécurisation : on transforme le tableau en chaîne de caractères pour le SQL
        $placeholders = str_repeat('?,', count($ids) - 1) . '?';
        $stmt = $pdo->prepare("UPDATE produits SET emplacement = ? WHERE id_produit IN ($placeholders)");
        
        // On fusionne l'emplacement avec les IDs pour l'exécution
        $params = array_merge([$emplacement], $ids);
        $stmt->execute($params);

        echo json_encode(['status' => 'success', 'message' => count($ids) . " produits déplacés vers : $emplacement"]);
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
}

elseif ($action == 'get_produits_par_rayon') {
    $emplacement = $_POST['emplacement'];

    // On récupère les produits et on calcule la somme des stocks disponibles dans la table stocks
    $stmt = $pdo->prepare("
        SELECT p.nom_commercial, 
               (SELECT IFNULL(SUM(quantite_disponible), 0) FROM stocks WHERE id_produit = p.id_produit) as total_stock
        FROM produits p
        WHERE p.emplacement = ?
        ORDER BY p.nom_commercial ASC");
    
    $stmt->execute([$emplacement]);
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['status' => 'success', 'data' => $data]);
    exit;
}

elseif ($action == 'get_produits_par_rayon') {
    $emplacement = $_POST['emplacement'];

    // On récupère les produits et on calcule la somme des stocks disponibles dans la table stocks
    $stmt = $pdo->prepare("
        SELECT p.nom_commercial, 
               (SELECT IFNULL(SUM(quantite_disponible), 0) FROM stocks WHERE id_produit = p.id_produit) as total_stock
        FROM produits p
        WHERE p.emplacement = ?
        ORDER BY p.nom_commercial ASC");
    
    $stmt->execute([$emplacement]);
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['status' => 'success', 'data' => $data]);
    exit;
}

elseif ($action == 'sync_catalogue_update') {
    try {
        $id_produit = $_POST['id_produit'];
        $nouveau_prix = $_POST['nouveau_prix'];

        // Mise à jour de la fiche sans toucher aux stocks ou aux mouvements
        $stmt = $pdo->prepare("UPDATE produits SET prix_unitaire = ?, date_maj = NOW() WHERE id_produit = ?");
        $stmt->execute([$nouveau_prix, $id_produit]);

        echo json_encode(['status' => 'success', 'message' => 'Fiche produit actualisée.']);
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
}

// 1. Récupérer les produits qui ont besoin d'être indexés
if ($action == 'get_produits_incomplets') {
    $stmt = $pdo->query("SELECT id_produit, nom_commercial FROM produits WHERE molecule IS NULL OR molecule = '' LIMIT 20");
    echo json_encode(['data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    exit;
}

// 2. Enregistrer la molécule
if ($action == 'update_molecule') {
    $stmt = $pdo->prepare("UPDATE produits SET molecule = ?, dosage = ? WHERE id_produit = ?");
    $stmt->execute([$_POST['molecule'], $_POST['dosage'], $_POST['id_produit']]);
    echo json_encode(['status' => 'success']);
    exit;
}

// Dans ajax_produits.php
if ($action == 'get_liste_molecules') {
    // On récupère les noms uniques de molécules déjà présentes
    $stmt = $pdo->query("SELECT DISTINCT molecule FROM produits WHERE molecule IS NOT NULL AND molecule != '' ORDER BY molecule ASC");
    $list = $stmt->fetchAll(PDO::FETCH_COLUMN);
    echo json_encode($list);
    exit;
}
// ... (après les autres blocs elseif)

elseif ($action == 'finaliser_reception_ligne') {
    try {
        $pdo->beginTransaction(); // Sécurité : transaction atomique

        $id_ligne = $_POST['id_ligne'];
        $id_produit = $_POST['id_produit'];
        $qte = $_POST['quantite'];
        $lot = $_POST['lot'];
        $date_exp = $_POST['date_exp']; // Format attendu : YYYY-MM-DD

        // A. Création de l'entrée dans la table STOCKS
        $stmt1 = $pdo->prepare("INSERT INTO stocks (id_produit, numero_lot, date_peremption, quantite_disponible, date_entree) 
                                VALUES (?, ?, ?, ?, NOW())");
        $stmt1->execute([$id_produit, $lot, $date_exp, $qte]);

        // B. Enregistrement du MOUVEMENT pour la traçabilité historique
        $stmt2 = $pdo->prepare("INSERT INTO mouvements_stock (id_produit, type_mouvement, quantite, commentaire, date_mouvement) 
                                VALUES (?, 'ENTREE_ACHAT', ?, ?, NOW())");
        $stmt2->execute([$id_produit, $qte, "Réception via winAutopilote - Lot: $lot"]);

        // C. Mise à jour du statut de la commande pour winAutopilote
        $stmt3 = $pdo->prepare("UPDATE lignes_commande SET qte_reçue = qte_reçue + ?, statut = 'LIVRE' WHERE id_ligne = ?");
        $stmt3->execute([$qte, $id_ligne]);

        $pdo->commit(); // Validation définitive des changements
        echo json_encode(['status' => 'success', 'message' => 'Produit intégré au stock avec succès']);

    } catch (Exception $e) {
        $pdo->rollBack(); // En cas d'erreur, on annule tout pour éviter les doublons ou erreurs
        echo json_encode(['status' => 'error', 'message' => 'Erreur lors de l\'intégration : ' . $e->getMessage()]);
    }
    exit;
}

elseif ($action == 'generer_bon_reception') {
    $id_commande = $_POST['id_commande'];

    // On récupère les infos de la commande, du fournisseur et les lignes reçues
    $stmt = $pdo->prepare("
        SELECT p.nom_commercial, lc.qte_reçue, s.numero_lot, s.date_peremption, f.nom_fournisseur
        FROM lignes_commande lc
        JOIN produits p ON lc.id_produit = p.id_produit
        JOIN stocks s ON (s.id_produit = p.id_produit AND s.date_entree >= (SELECT date_reception FROM commandes WHERE id_commande = ?))
        JOIN fournisseurs f ON f.id_fournisseur = (SELECT id_fournisseur FROM commandes WHERE id_commande = ?)
        WHERE lc.id_commande = ? AND lc.statut = 'LIVRE'
    ");
    $stmt->execute([$id_commande, $id_commande, $id_commande]);
    $details = $stmt->fetchAll();

    echo json_encode(['status' => 'success', 'data' => $details]);
    exit;
}

// Récupérer les sous-familles selon la famille choisie
if ($action == 'get_sous_familles') {
    $id_famille = $_POST['id_famille'];
    $stmt = $pdo->prepare("SELECT * FROM sous_familles WHERE id_famille = ? ORDER BY nom_sous_famille ASC");
    $stmt->execute([$id_famille]);
    echo json_encode($stmt->fetchAll());
    exit;
}

// Ajouter une nouvelle sous-famille
if ($action == 'add_sous_famille') {
    $stmt = $pdo->prepare("INSERT INTO sous_familles (id_famille, nom_sous_famille) VALUES (?, ?)");
    $stmt->execute([$_POST['id_famille'], $_POST['nom']]);
    echo json_encode(['status' => 'success']);
    exit;
}

// ... (début du fichier identique)

if ($action == 'get_familles') {
    $stmt = $pdo->query("SELECT * FROM familles ORDER BY nom_famille ASC");
    echo json_encode($stmt->fetchAll());
    exit;
}

elseif ($action == 'add_famille') {
    $stmt = $pdo->prepare("INSERT INTO familles (nom_famille) VALUES (?)");
    $stmt->execute([$_POST['nom']]);
    echo json_encode(['status' => 'success']);
    exit;
}

elseif ($action == 'add_sous_famille') {
    $stmt = $pdo->prepare("INSERT INTO sous_familles (id_famille, nom_sous_famille) VALUES (?, ?)");
    $stmt->execute([$_POST['id_famille'], $_POST['nom']]);
    echo json_encode(['status' => 'success']);
    exit;
}

elseif ($action == 'ajouter_produit') {

    // 🔹 Récupération
    $nom = $_POST['nom_commercial'] ?? '';
    $molecule = $_POST['molecule'] ?? 'N/A';
    $dosage = $_POST['dosage'] ?? '';
    $description = $_POST['description'] ?? '';

    $id_sf = !empty($_POST['id_sous_famille']) ? $_POST['id_sous_famille'] : null;

    $prix = $_POST['prix_unitaire'] ?? 0;
    $prix_achat = $_POST['prix_achat'] ?? 0;

    $seuil = $_POST['seuil_alerte'] ?? 0;
    $stock_max = $_POST['stock_max'] ?? 0;

    $delai_p = $_POST['delai_peremption'] ?? 0;

    $id_fournisseur = !empty($_POST['id_fournisseur']) ? $_POST['id_fournisseur'] : null;

    $emplacement = $_POST['emplacement'] ?? 'NON DÉFINI';

    $est_divers = $_POST['est_divers'] ?? 0;

    // 🔴 VALIDATION (uniquement médical)
    if ($est_divers == 0) {
        if ($prix_achat <= 0 || $stock_max <= 0 || empty($emplacement)) {
            echo json_encode([
                'status' => 'error',
                'message' => 'Veuillez remplir Prix d\'achat, Stock max et Emplacement'
            ]);
            exit;
        }
    }

    // 🔥 INSERT adapté à ta table
    $sql = "INSERT INTO produits (
        nom_commercial,
        molecule,
        dosage,
        description,
        id_sous_famille,
        prix_unitaire,
        prix_achat,
        seuil_alerte,
        stock_max,
        delai_peremption,
        emplacement,
        id_fournisseur,
        est_divers
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

    try {

        $stmt = $pdo->prepare($sql);

        $stmt->execute([
            $nom,
            $molecule,
            $dosage,
            $description,
            $id_sf,
            $prix,
            $prix_achat,
            $seuil,
            $stock_max,
            $delai_p,
            $emplacement,
            $id_fournisseur,
            $est_divers
        ]);

        echo json_encode([
            'status' => 'success',
            'message' => 'Produit enregistré avec succès'
        ]);

    } catch (PDOException $e) {

        echo json_encode([
            'status' => 'error',
            'message' => 'Erreur SQL: ' . $e->getMessage()
        ]);
    }

    exit;
}

// ACTION : MISE À JOUR EMPLACEMENT SEULE
elseif ($action == 'update_emplacement') {
    $id = $_POST['id_produit'];
    $loc = $_POST['emplacement'];
    
    $stmt = $pdo->prepare("UPDATE produits SET emplacement = ? WHERE id_produit = ?");
    $stmt->execute([$loc, $id]);
    echo json_encode(['status' => 'success']);
    exit;
}

elseif ($action == 'get_equivalents') {
    $id_produit = $_POST['id_produit'];

    try {
        // 1. On récupère d'abord la molécule et le dosage du produit cible
        $stmtRef = $pdo->prepare("SELECT molecule, dosage FROM produits WHERE id_produit = ?");
        $stmtRef->execute([$id_produit]);
        $reference = $stmtRef->fetch(PDO::FETCH_ASSOC);

        if (!$reference || empty($reference['molecule'])) {
            echo json_encode(['status' => 'error', 'message' => 'Molécule non renseignée pour ce produit.']);
            exit;
        }

        // 2. On cherche les équivalents (même molécule, même dosage, ID différent)
        // On inclut une jointure avec la table stocks pour avoir les quantités réelles
        $sql = "SELECT 
                    p.id_produit, 
                    p.nom_commercial, 
                    p.dosage, 
                    p.emplacement,
                    IFNULL(SUM(s.quantite_disponible), 0) as stock_total
                FROM produits p
                LEFT JOIN stocks s ON p.id_produit = s.id_produit
                WHERE p.molecule = :molecule 
                AND p.dosage = :dosage 
                AND p.id_produit != :id_actuel
                GROUP BY p.id_produit
                ORDER BY stock_total DESC";

        $stmtEq = $pdo->prepare($sql);
        $stmtEq->execute([
            'molecule' => $reference['molecule'],
            'dosage'   => $reference['dosage'],
            'id_actuel' => $id_produit
        ]);
        
        $equivalents = $stmtEq->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'status' => 'success', 
            'data'   => $equivalents
        ]);

    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
}

elseif ($action == 'get_details_lots') {
    $id = $_POST['id_produit'];

    try {
        // 1. Infos du produit (on s'assure d'avoir id_produit, designation, molecule, dosage, coef, etc.)
        $stmtP = $pdo->prepare("SELECT * FROM produits WHERE id_produit = ?");
        $stmtP->execute([$id]);
        $produit = $stmtP->fetch(PDO::FETCH_ASSOC);

        if (!$produit) {
            throw new Exception("Produit introuvable.");
        }

        // 2. Liste des lots (Ajout de id_stock pour permettre les actions sur la ligne)
        $stmtL = $pdo->prepare("SELECT id_stock, numero_lot, date_peremption, quantite_disponible 
                                FROM stocks 
                                WHERE id_produit = ? AND quantite_disponible > 0 
                                ORDER BY date_peremption ASC");
        $stmtL->execute([$id]);
        $lots = $stmtL->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'status' => 'success',
            'produit' => $produit, // Contient le 'coef'
            'lots' => $lots       // Contient 'quantite_disponible' (en unités)
        ]);
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
}

elseif ($action == 'declarer_sortie') {
    $d = $_POST['data'];
    $id_stock = $d['id_stock'];
    $id_prod = $d['id_produit'];
    $qte = intval($d['quantite']);
    $motif = $d['motif']; // Correspond à l'enum de mouvements_stock
    $user_id = $_SESSION['user_id'] ?? 1; // ID de l'utilisateur connecté

    try {
        $pdo->beginTransaction();

        // 1. Vérifier si le stock est suffisant
        $check = $pdo->prepare("SELECT quantite_disponible FROM stocks WHERE id_stock = ?");
        $check->execute([$id_stock]);
        $current = $check->fetchColumn();

        if($current < $qte) {
            throw new Exception("Quantité insuffisante en stock.");
        }

        // 2. Mise à jour de la table STOCKS
        $upd = $pdo->prepare("UPDATE stocks SET quantite_disponible = quantite_disponible - ? WHERE id_stock = ?");
        $upd->execute([$qte, $id_stock]);

        // 3. Enregistrement du mouvement (Table mouvements_stock de votre schéma)
        $log = $pdo->prepare("INSERT INTO mouvements_stock (id_produit, id_stock, type_mouvement, quantite, date_mouvement, id_utilisateur, commentaire) 
                             VALUES (?, ?, ?, ?, NOW(), ?, ?)");
        $log->execute([$id_prod, $id_stock, $motif, $qte, $user_id, "Sortie manuelle pour $motif"]);

        $pdo->commit();
        echo json_encode(['status' => 'success']);
    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
}

elseif ($action == 'comparer_catalogue') {
    try {
        // Cette requête cherche les produits dont le prix de vente actuel 
        // est différent du prix conseillé dans le catalogue référentiel
       // Version améliorée pour la comparaison
        $sql = "SELECT 
            p.id_produit, 
            p.nom_commercial, 
            p.prix_unitaire as prix_actuel, 
            ref.prix_conseille as prix_catalogue
        FROM produits p
        INNER JOIN catalogue_referentiel ref ON TRIM(UPPER(p.nom_commercial)) = TRIM(UPPER(ref.nom_produit))
        WHERE p.prix_unitaire != ref.prix_conseille";

        $stmt = $pdo->query($sql);
        $diffs = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'status' => 'success',
            'data' => $diffs
        ]);
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
}

// Action pour mettre à jour le prix suite au clic sur le bouton
elseif ($action == 'appliquer_maj_prix') {
    $id = $_POST['id_produit'];
    $nouveau_prix = $_POST['nouveau_prix'];

    try {
        $stmt = $pdo->prepare("UPDATE produits SET prix_unitaire = ? WHERE id_produit = ?");
        $stmt->execute([$nouveau_prix, $id]);
        echo json_encode(['status' => 'success']);
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
}

// ACTION : RÉCUPÉRER LES ALERTES DE RUPTURE
elseif ($action == 'get_alertes_critiques') {
    try {
        // La requête sélectionne les produits dont la somme des stocks est inférieure ou égale au seuil
        $sql = "SELECT 
                    p.id_produit,
                    p.nom_commercial, 
                    p.seuil_alerte,
                    p.dosage,
                    IFNULL(SUM(s.quantite_disponible), 0) as total 
                FROM produits p 
                LEFT JOIN stocks s ON p.id_produit = s.id_produit 
                GROUP BY p.id_produit 
                HAVING total <= p.seuil_alerte";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute();
        $alertes = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // On renvoie la liste et le nombre total d'alertes pour le badge
        echo json_encode([
            'status' => 'success',
            'count' => count($alertes),
            'data' => $alertes
        ]);
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
}
elseif ($action == 'supprimer_famille') {
    $id_famille = $_POST['id_famille'];

    try {
        // 1. Vérifier si des sous-familles sont liées à cette famille
        $checkSF = $pdo->prepare("SELECT COUNT(*) FROM sous_familles WHERE id_famille = ?");
        $checkSF->execute([$id_famille]);
        if ($checkSF->fetchColumn() > 0) {
            echo json_encode(['status' => 'error', 'message' => 'Impossible : cette famille contient encore des sous-familles.']);
            exit;
        }

        // 2. Si vide, on supprime
        $stmt = $pdo->prepare("DELETE FROM familles WHERE id_famille = ?");
        $stmt->execute([$id_famille]);

        echo json_encode(['status' => 'success']);
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => 'Erreur SQL : ' . $e->getMessage()]);
    }
    exit;
}

elseif ($action == 'supprimer_sous_famille') {
    $id_sf = $_POST['id_sous_famille'];

    try {
        // 1. Vérification : y a-t-il des produits dans cette sous-famille ?
        $checkProd = $pdo->prepare("SELECT COUNT(*) FROM produits WHERE id_sous_famille = ?");
        $checkProd->execute([$id_sf]);
        $nbProduits = $checkProd->fetchColumn();

        if ($nbProduits > 0) {
            echo json_encode([
                'status' => 'error', 
                'message' => "Impossible de supprimer : $nbProduits produit(s) sont encore rattachés à cette catégorie."
            ]);
            exit;
        }

        // 2. Si aucun produit n'est lié, on procède à la suppression
        $stmt = $pdo->prepare("DELETE FROM sous_familles WHERE id_sous_famille = ?");
        $stmt->execute([$id_sf]);

        echo json_encode(['status' => 'success']);
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
}

// --- RECHERCHE POUR INVENTAIRE ---
// --- RECHERCHE POUR INVENTAIRE CORRIGÉE ---
if ($action == 'rechercher_inv') {
    ob_clean(); 
    header('Content-Type: application/json');

    try {
        $query = "%".$_POST['query']."%";
        // Correction : On utilise SUM(s.quantite_disponible) car stock_total n'existe pas
        $sql = "SELECT p.id_produit, p.nom_commercial, 
                COALESCE(SUM(s.quantite_disponible), 0) as stock_reel
                FROM produits p
                LEFT JOIN stocks s ON p.id_produit = s.id_produit
                WHERE p.nom_commercial LIKE ? OR p.molecule LIKE ? 
                GROUP BY p.id_produit
                LIMIT 5";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$query, $query]);
        $resultats = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode($resultats ? $resultats : []);
        
    } catch (Exception $e) {
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// --- RECUPERER LOTS D'UN PRODUIT ---
if ($action == 'get_lots_inv') {
    $id = $_POST['id_produit'];
    $stmt = $pdo->prepare("SELECT id_stock, numero_lot, quantite_disponible, date_peremption FROM stocks WHERE id_produit = ? AND quantite_disponible > 0");
    $stmt->execute([$id]);
    echo json_encode($stmt->fetchAll());
    exit;
}

// --- VALIDER L'INVENTAIRE (AJUSTEMENT MASSIF) ---
if ($action == 'valider_inventaire') {
    $donnees = $_POST['donnees'];
    $pdo->beginTransaction();
    try {
        foreach ($donnees as $adj) {
            $id_s = $adj['id_stock'];
            $id_p = $adj['id_produit'];
            $q_reel = $adj['quantite_reelle'];

            // 1. Récupérer l'ancienne quantité pour l'écart
            $st = $pdo->prepare("SELECT quantite_disponible FROM stocks WHERE id_stock = ?");
            $st->execute([$id_s]);
            $old = $st->fetchColumn();
            $ecart = $q_reel - $old;

            // 2. Update du Lot
            $pdo->prepare("UPDATE stocks SET quantite_disponible = ? WHERE id_stock = ?")->execute([$q_reel, $id_s]);

            // 3. Log du mouvement
            $pdo->prepare("INSERT INTO mouvements_stock (id_produit, id_stock, type_mouvement, quantite, id_utilisateur, commentaire, date_mouvement) 
                           VALUES (?, ?, 'ajustement_inventaire', ?, ?, 'Inventaire tournant', NOW())")
                ->execute([$id_p, $id_s, abs($ecart), $_SESSION['id_user'] ?? 1]);
            
            // 4. Update du total produit
            $pdo->prepare("UPDATE produits SET stock_total = (SELECT SUM(quantite_disponible) FROM stocks WHERE id_produit = ?) WHERE id_produit = ?")
                ->execute([$id_p, $id_p]);
        }
        $pdo->commit();
        echo json_encode(['status' => 'success']);
    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
}

// --- 1. RECHERCHE PRODUIT POUR ACHAT (AVEC COEF) ---
// --- 1. RECHERCHE PRODUIT POUR ACHAT (CORRIGÉE) ---
// --- 1. RECHERCHE PRODUIT (AVEC PRIX ACTUEL) ---
if ($action == 'rechercher_produit_achat') {
    $query = "%".$_POST['query']."%";
    // On récupère prix_unitaire (qui doit être le prix d'achat de base)
    $sql = "SELECT id_produit, nom_commercial, coefficient_division, prix_achat, prix_unitaire 
            FROM produits 
            WHERE nom_commercial LIKE ? LIMIT 5";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$query]);
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    exit;
}

// --- 1. RECHERCHE PRODUIT (AVEC PRIX ACTUEL) ---
if ($action == 'rechercher_produit_achat') {
    ob_clean();
    header('Content-Type: application/json');
    try {
        $query = "%".$_POST['query']."%";
        // Correction : on s'assure de prendre le prix unitaire stocké
        $sql = "SELECT id_produit, nom_commercial, coefficient_division, prix_achat 
                FROM produits 
                WHERE nom_commercial LIKE ? OR molecule LIKE ? LIMIT 5";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$query, $query]);
        $resultats = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode($resultats);
    } catch (Exception $e) {
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

if ($action == 'valider_reception_achat') {
    ob_clean();
    header('Content-Type: application/json');
    
    // 1. VÉRIFICATION DOUBLON (Fournisseur + N° Facture)
    $stmtCheck = $pdo->prepare("SELECT id_achat FROM achats WHERE id_fournisseur = ? AND num_facture = ?");
    $stmtCheck->execute([$_POST['id_fournisseur'], $_POST['num_facture']]);
    
    if ($stmtCheck->fetch()) {
        echo json_encode([
            'status' => 'error', 
            'message' => "Erreur : La facture n° " . $_POST['num_facture'] . " existe déjà pour ce fournisseur."
        ]);
        exit;
    }

    $pdo->beginTransaction();
    try {
        // Correction : On utilise $_POST['total_global'] si envoyé, sinon on peut le recalculer au besoin
        $total_global = floatval($_POST['total_global'] ?? 0); 
        $montant_verse = floatval($_POST['montant_verse']);
        $date_achat = $_POST['date_achat'];
        $num_facture = $_POST['num_facture'];
        $id_fournisseur = $_POST['id_fournisseur'];
        $methode_pa = $_POST['methode_pa'] ?? 'remplacer'; 
        
        $statut_paiement = ($montant_verse >= $total_global) ? 'paye' : (($montant_verse > 0) ? 'partiel' : 'non_paye');

        // --- ÉTAPE 1 : Insertion de l'achat ---
        $stmtA = $pdo->prepare("INSERT INTO achats (
            id_fournisseur, num_facture, date_achat, montant_total, 
            montant_paye, statut_paiement, mode_reglement, date_echeance, id_utilisateur
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        
        $stmtA->execute([
            $id_fournisseur,
            $num_facture,
            $date_achat,
            $total_global,
            $montant_verse,
            $statut_paiement,
            $_POST['mode_reglement'],
            !empty($_POST['date_echeance']) ? $_POST['date_echeance'] : null,
            $_SESSION['user_id']
        ]);
        
        $id_achat = $pdo->lastInsertId();

        // --- ÉTAPE 2 : Insertion dans la table CHARGES ---
        if ($montant_verse > 0) {
            $libelle = "Achat Stock - Facture: " . $num_facture;
            $stmtC = $pdo->prepare("INSERT INTO charges (date_operation, libelle_operation, montant, mode_paiement, commentaire) VALUES (?, ?, ?, ?, ?)");
            $stmtC->execute([
                $date_achat,
                $libelle,
                $montant_verse,
                $_POST['mode_reglement'],
                "Fournisseur ID: " . $id_fournisseur
            ]);
        }

        // --- ÉTAPE 3 : Traitement des lignes de produits ---
        foreach ($_POST['lignes'] as $l) {
            $id_prod = $l['id_produit'];
            $coef = floatval($l['coef']) ?: 1;
            
            // Nouveau prix d'achat converti à l'unité de détail (Prix Boite / Coef)
            $nouveau_pa_unitaire = floatval($l['prix_achat_boite']);
            $quantite_recue = floatval($l['quantite_unitaire']);

            $nouveau_prix_final = $nouveau_pa_unitaire;

            if ($methode_pa === 'pmp') {
                // Récupérer les données actuelles DEPUIS la table produits
                $stmtGetPA = $pdo->prepare("
                    SELECT prix_achat, 
                           (SELECT IFNULL(SUM(quantite_disponible), 0) FROM stocks WHERE id_produit = ?) as stock_actuel
                    FROM produits WHERE id_produit = ?
                ");
                $stmtGetPA->execute([$id_prod, $id_prod]);
                $prod_data = $stmtGetPA->fetch();

                $pa_actuel_unitaire = floatval($prod_data['prix_achat'] ?? 0);
                $stock_actuel = floatval($prod_data['stock_actuel'] ?? 0);

                // Correction Formule PMP : 
                // On ne calcule le PMP que s'il y a déjà du stock, sinon le nouveau prix est simplement le nouveau PA
                if ($stock_actuel > 0) {
                    $nouveau_prix_final = (($stock_actuel * $pa_actuel_unitaire) + ($quantite_recue * $nouveau_pa_unitaire)) / ($stock_actuel + $quantite_recue);
                } else {
                    $nouveau_prix_final = $nouveau_pa_unitaire;
                }
            }

            // A. Enregistrement du détail de l'achat (CONSERVÉ)
            $stmtD = $pdo->prepare("INSERT INTO detail_achats (id_achat, id_produit, quantite_recue, prix_achat_unitaire, date_peremption) VALUES (?, ?, ?, ?, ?)");
            $stmtD->execute([$id_achat, $id_prod, $quantite_recue, $nouveau_pa_unitaire, $l['date_peremption']]);

            // B. Mise à jour de la fiche PRODUIT
            $stmtUpd = $pdo->prepare("UPDATE produits SET prix_achat = ? WHERE id_produit = ?");
            $stmtUpd->execute([$nouveau_prix_final, $id_prod]);

            // C. Création du LOT en stock
            $stmtS = $pdo->prepare("INSERT INTO stocks (id_produit, numero_lot, prix_achat, date_peremption, quantite_disponible, date_reception) VALUES (?, ?, ?, ?, ?, ?)");
            $stmtS->execute([
                $id_prod, 
                $l['numero_lot'], 
                $nouveau_pa_unitaire, // Prix réel du lot
                $l['date_peremption'], 
                $quantite_recue, 
                $date_achat
            ]);
            $id_stock = $pdo->lastInsertId();

            // D. Mouvement de stock
            $stmtM = $pdo->prepare("INSERT INTO mouvements_stock (id_produit, id_stock, type_mouvement, quantite, id_utilisateur, commentaire, date_mouvement) VALUES (?, ?, 'entree_achat', ?, ?, ?, NOW())");
            $stmtM->execute([
                $id_prod, 
                $id_stock, 
                $quantite_recue, 
                $_SESSION['user_id'], 
                "Réception Facture: " . $num_facture
            ]);
        }

        $pdo->commit();
        echo json_encode(['status' => 'success']);
        
    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['status' => 'error', 'message' => "Erreur : " . $e->getMessage()]);
    }
    exit;
}


if ($action == 'verifier_doublon_facture') {
    $stmt = $pdo->prepare("SELECT id_achat FROM achats WHERE num_facture = ? AND id_fournisseur = ?");
    $stmt->execute([$_POST['num_facture'], $_POST['id_fournisseur']]);
    echo json_encode(['existe' => (bool)$stmt->fetch()]);
    exit;
}

if ($action == 'liste_derniers_achats') {
    ob_clean();
    header('Content-Type: application/json');
    try {
        $sql = "SELECT a.id_achat, a.date_achat, a.num_facture, a.montant_total, a.statut_paiement, f.nom_fournisseur 
                FROM achats a
                JOIN fournisseurs f ON a.id_fournisseur = f.id_fournisseur
                ORDER BY a.date_achat DESC, a.id_achat DESC 
                LIMIT 5";
        $stmt = $pdo->query($sql);
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    } catch (Exception $e) {
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

if ($action == 'details_facture_achat') {
    ob_clean();
    header('Content-Type: application/json');
    try {
        $id = intval($_POST['id_achat']);

        // 1. Infos générales de la facture
        $stmt1 = $pdo->prepare("SELECT a.*, f.nom_fournisseur 
                                FROM achats a 
                                JOIN fournisseurs f ON a.id_fournisseur = f.id_fournisseur 
                                WHERE a.id_achat = ?");
        $stmt1->execute([$id]);
        $info = $stmt1->fetch(PDO::FETCH_ASSOC);

        // 2. Liste des produits avec leurs lots (Jointure detail_achats et stocks)
        // Note: On lie via le produit et la date car detail_achats n'a pas toujours l'id_stock direct
        $stmt2 = $pdo->prepare("SELECT d.*, p.nom_commercial, s.numero_lot 
                                FROM detail_achats d
                                JOIN produits p ON d.id_produit = p.id_produit
                                LEFT JOIN stocks s ON d.id_produit = s.id_produit AND d.date_peremption = s.date_peremption
                                WHERE d.id_achat = ?");
        $stmt2->execute([$id]);
        $lignes = $stmt2->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(['info' => $info, 'lignes' => $lignes]);
    } catch (Exception $e) {
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// 1. Récupérer les factures impayées
if ($action == 'liste_dettes_fournisseurs') {
    $sql = "SELECT a.*, f.nom_fournisseur 
            FROM achats a 
            JOIN fournisseurs f ON a.id_fournisseur = f.id_fournisseur 
            WHERE a.statut_paiement != 'paye' 
            ORDER BY a.date_echeance ASC";
    $stmt = $pdo->query($sql);
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    exit;
}

// 2. Enregistrer un nouveau règlement
if ($action == 'enregistrer_reglement_fournisseur') {
    $id = $_POST['id_achat'];
    $versement = floatval($_POST['montant']);

    // Récupérer l'état actuel
    $stmt = $pdo->prepare("SELECT montant_total, montant_paye FROM achats WHERE id_achat = ?");
    $stmt->execute([$id]);
    $achat = $stmt->fetch();

    $nouveau_total_paye = $achat['montant_paye'] + $versement;
    $statut = ($nouveau_total_paye >= $achat['montant_total']) ? 'paye' : 'partiel';

    $upd = $pdo->prepare("UPDATE achats SET montant_paye = ?, statut_paiement = ? WHERE id_achat = ?");
    $upd->execute([$nouveau_total_paye, $statut, $id]);

    // OPTIONNEL : Enregistrer le mouvement dans une table 'caisse' ou 'paiements_fournisseurs'
    
    echo json_encode(['status' => 'success']);
    exit;
}

// 1. Chercher un lot spécifique
if ($action == 'chercher_lot_stock') {
    $q = "%".$_POST['query']."%";
    $sql = "SELECT s.*, p.nom_commercial 
            FROM stocks s 
            JOIN produits p ON s.id_produit = p.id_produit 
            WHERE s.numero_lot LIKE ? OR p.nom_commercial LIKE ? 
            AND s.quantite_disponible > 0 LIMIT 5";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$q, $q]);
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    exit;
}

// 2. Valider le retour
if ($action == 'valider_retour_fournisseur') {
    $pdo->beginTransaction();
    try {
        foreach ($_POST['items'] as $item) {
            // A. On récupère les infos du lot pour le mouvement
            $st = $pdo->prepare("SELECT id_produit FROM stocks WHERE id_stock = ?");
            $st->execute([$item['id_stock']]);
            $info = $st->fetch();

            // B. Mise à jour du stock (Soustraction)
            $upd = $pdo->prepare("UPDATE stocks SET quantite_disponible = quantite_disponible - ? WHERE id_stock = ?");
            $upd->execute([$item['quantite'], $item['id_stock']]);

            // C. Enregistrement du mouvement (Sortie)
            $mov = $pdo->prepare("INSERT INTO mouvements_stock (id_produit, id_stock, type_mouvement, quantite, id_utilisateur, commentaire, date_mouvement) 
                                 VALUES (?, ?, 'retour_fournisseur', ?, ?, ?, NOW())");
            $mov->execute([
                $info['id_produit'], 
                $item['id_stock'], 
                $item['quantite'], 
                $_SESSION['user_id'], 
                "Motif: " . $item['motif']
            ]);
        }
        $pdo->commit();
        echo json_encode(['status' => 'success']);
    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
}

if ($action == 'creer_bon_commande') {
    $pdo->beginTransaction();
    try {
        // 1. Entête de commande
        $stmtC = $pdo->prepare("INSERT INTO commandes (id_fournisseur, date_commande, statut, total_prevu) VALUES (?, NOW(), 'en_attente', 0)");
        $stmtC->execute([$_POST['id_fournisseur']]);
        $id_commande = $pdo->lastInsertId();

        // 2. Lignes de commande
        foreach ($_POST['lignes'] as $l) {
            $stmtL = $pdo->prepare("INSERT INTO commande_lignes (id_commande, id_produit, quantite_commandee, quantite_recue) VALUES (?, ?, ?, 0)");
            $stmtL->execute([$id_commande, $l['id'], $l['qty']]);
        }

        $pdo->commit();
        echo json_encode(['status' => 'success']);
    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
}

// --- ACTION : GÉNÉRER LES SUGGESTIONS D'ACHAT ---
if ($action == 'generer_suggestions_appro') {
    ob_clean(); // Nettoie le tampon pour éviter des caractères parasites
    header('Content-Type: application/json');
    
    try {
        // Cette requête analyse 4 points clés :
        // 1. Le stock actuel (somme des lots dans la table 'stocks')
        // 2. Le seuil d'alerte défini dans la fiche produit
        // 3. Le stock maximum autorisé
        // 4. Les quantités déjà commandées mais non réceptionnées (commandes 'en_attente')

        $sql = "SELECT 
                    p.id_produit, 
                    p.nom_commercial, 
                    p.seuil_alerte, 
                    p.stock_max,
                    p.coefficient_division,
                    -- Calcul du stock total actuel en unités
                    IFNULL((SELECT SUM(s.quantite_disponible) FROM stocks s WHERE s.id_produit = p.id_produit), 0) as stock_reel,
                    -- Calcul des reliquats de commandes déjà envoyées
                    IFNULL((
                        SELECT SUM(cl.quantite_commandee - cl.quantite_recue) 
                        FROM commande_lignes cl 
                        JOIN commandes c ON cl.id_commande = c.id_commande 
                        WHERE cl.id_produit = p.id_produit 
                        AND c.statut IN ('en_attente', 'recue_partielle')
                    ), 0) as en_cours
                FROM produits p
                WHERE p.id_produit IS NOT NULL
                HAVING (stock_reel + en_cours) <= p.seuil_alerte OR stock_reel = 0
                ORDER BY stock_reel ASC";

        $stmt = $pdo->prepare($sql);
        $stmt->execute();
        $suggestions = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode($suggestions);
        
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
}

elseif ($action == 'get_details_reception') {
    $id_cmd = $_POST['id_commande'];
    
    // Jointure pour avoir le nom du produit
    $query = "SELECT cl.*, p.nom_commercial 
              FROM commande_lignes cl 
              JOIN produits p ON cl.id_produit = p.id_produit 
              WHERE cl.id_commande = ?";
              
    $stmt = $pdo->prepare($query);
    $stmt->execute([$id_cmd]);
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // On suggère une date de péremption à +2 ans par défaut
    $date_suggested = date('Y-m-d', strtotime('+24 months'));
    
    echo json_encode([
        'status' => 'success', 
        'data' => $data, 
        'date_suggested' => $date_suggested
    ]);
    exit;
}

// Action pour lister les commandes en cours
elseif ($action == 'get_commandes_en_cours') {
    $query = "SELECT c.*, f.nom_fournisseur 
              FROM commandes_envoyees c
              JOIN fournisseurs f ON c.id_fournisseur = f.id_fournisseur
              WHERE c.statut = 'envoyé' OR c.statut = 'en_attente'
              ORDER BY c.date_envoi DESC";
    $stmt = $pdo->query($query);
    echo json_encode(['status' => 'success', 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    exit;
}

// Action pour annuler ou changer le statut
elseif ($action == 'changer_statut_commande') {
    $id = $_POST['id_commande'];
    $statut = $_POST['statut'];
    $stmt = $pdo->prepare("UPDATE commandes_envoyees SET statut = ? WHERE id_commande = ?");
    $stmt->execute([$statut, $id]);
    echo json_encode(['status' => 'success']);
    exit;
}

elseif ($action == 'get_lignes_pour_reception') {
    $id = intval($_POST['id_commande']);
    
    // On ajoute p.prix_achat_unitaire à la requête
    $stmt = $pdo->prepare("SELECT cl.*, p.nom_commercial, p.prix_unitaire 
                           FROM commande_lignes cl 
                           JOIN produits p ON cl.id_produit = p.id_produit 
                           WHERE cl.id_commande = ?");
    $stmt->execute([$id]);
    $lignes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    header('Content-Type: application/json');
    echo json_encode($lignes);
    exit;
}

elseif ($action == 'enregistrer_reception_finale') {
    try {
        $pdo->beginTransaction(); // Sécurité : tout passe ou rien ne passe
        
        $id_cmd = intval($_POST['id_commande']);
        $lignes = $_POST['lignes']; // Le tableau envoyé par le JS
        $id_user = $_SESSION['user_id'] ?? 1;

        foreach ($lignes as $l) {
            // 1. Mise à jour de la quantité réellement reçue dans les lignes de commande
            $stmtUpd = $pdo->prepare("UPDATE commande_lignes 
                                     SET quantite_recue = ? 
                                     WHERE id_ligne = ?");
            $stmtUpd->execute([$l['qte'], $l['id_ligne']]);

            // 2. Insertion dans la table des STOCKS (Création du lot physique)
            // On utilise les colonnes de votre schéma : id_produit, numero_lot, quantite_disponible, date_peremption
            $stmtStock = $pdo->prepare("INSERT INTO stocks 
                (id_produit, numero_lot, quantite_disponible, date_reception, date_peremption) 
                VALUES (?, ?, ?, NOW(), ?)");
            $stmtStock->execute([
                $l['id_p'], 
                $l['lot'], 
                $l['qte'], 
                $l['peremption']
            ]);
            
            // 3. Historique dans les MOUVEMENTS de stock
            $stmtMouv = $pdo->prepare("INSERT INTO mouvements_stock 
                (id_produit, type_mouvement, quantite, date_mouvement, id_utilisateur, motif) 
                VALUES (?, 'entree_achat', ?, NOW(), ?, ?)");
            $stmtMouv->execute([
                $l['id_p'], 
                $l['qte'], 
                $id_user, 
                "Réception finale Commande #$id_cmd"
            ]);
        }

        // 4. On change le statut de la commande pour qu'elle disparaisse des "En cours"
        // Selon votre schéma, le statut passe à 'reçu' (ou 'termine' selon votre ENUM)
        $stmtStatus = $pdo->prepare("UPDATE commandes_envoyees 
                                    SET statut = 'reçu' 
                                    WHERE id_commande = ?");
        $stmtStatus->execute([$id_cmd]);

        $pdo->commit();
        echo json_encode(['status' => 'success', 'message' => 'Stock mis à jour avec succès']);

    } catch (Exception $e) {
        $pdo->rollBack(); // En cas d'erreur, on annule tout pour éviter les doublons
        echo json_encode(['status' => 'error', 'message' => 'Erreur SQL : ' . $e->getMessage()]);
    }
    exit;
}

// Dans ajax_produits.php
if ($_POST['action']== 'get_details_commande') {
     $id = intval($_POST['id']);
    
    $stmt = $pdo->prepare("SELECT cl.*, p.nom_commercial 
                           FROM commande_lignes cl 
                           JOIN produits p ON cl.id_produit = p.id_produit 
                           WHERE cl.id_commande = ?");
    $stmt->execute([$id]);
    $lignes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (count($lignes) > 0) {
        $html = '<table style="width:100%; border-collapse:collapse; margin-top:10px;">';
        $html .= '<tr style="background:#0984e3; color:white;"><th style="padding:10px;">Produit</th><th style="padding:10px;">Quantité</th></tr>';
        foreach($lignes as $l) {
            $html .= "<tr style='border-bottom:1px solid #ddd;'><td style='padding:10px;'>" . htmlspecialchars($l['nom_commercial']) . "</td><td style='padding:10px; text-align:center;'><b>" . $l['quantite_commandee'] . "</b></td></tr>";
        }
        $html .= '</table>';
        //echo $html;
         echo json_encode(['status' => 'success', 'data' => $html]);
    } else {
         echo json_encode(['status' => 'Erreur', 'message' => 'Aucun produit trouvé pour cette commande. !']);

    }

    exit;
}

elseif ($action == 'valider_reception_finale') {
    try {
        $pdo->beginTransaction();
        
        $lignes = $_POST['lignes'] ?? [];
        $mode_prix = $_POST['mode_prix']; // 'remplacer' ou 'pmp'
        $id_cmd = intval($_POST['id_commande']);

        foreach ($lignes as $l) {
            $id_p = intval($l['id_p']);
            $nouveau_pa = floatval($l['pa_r']);
            $qte_recue = intval($l['qte_r']);

            // 1. Récupérer l'ancien PA et le stock actuel total
            $stmt = $pdo->prepare("
                SELECT prix_unitaire, 
                (SELECT IFNULL(SUM(quantite_disponible), 0) FROM stocks WHERE id_produit = ?) as stock_actuel 
                FROM produits WHERE id_produit = ?
            ");
            $stmt->execute([$id_p, $id_p]);
            $prod_info = $stmt->fetch();

            $ancien_pa = floatval($prod_info['prix_unitaire']);
            $stock_actuel = intval($prod_info['stock_actuel']);
            
            $pa_final = $nouveau_pa; // Valeur par défaut (Remplacer)

            // 2. Calcul du PMP (Prix Moyen Pondéré) si l'option est choisie
            if ($mode_prix == 'pmp') {
                $total_quantite = $stock_actuel + $qte_recue;
                if ($total_quantite > 0) {
                    $valeur_stock_ancien = $stock_actuel * $ancien_pa;
                    $valeur_nouvel_arrivage = $qte_recue * $nouveau_pa;
                    $pa_final = ($valeur_stock_ancien + $valeur_nouvel_arrivage) / $total_quantite;
                }
            }

            // 3. Mise à jour de la fiche produit (Le PA de référence)
            $upd_prod = $pdo->prepare("UPDATE produits SET prix_unitaire = ? WHERE id_produit = ?");
            $upd_prod->execute([round($pa_final, 2), $id_p]);

            // 4. Insertion dans la table stocks (Le nouveau lot)
            $ins_stock = $pdo->prepare("
                INSERT INTO stocks (id_produit, quantite_disponible, prix_achat, numero_lot, date_peremption, date_reception) 
                VALUES (?, ?, ?, ?, ?, NOW())
            ");
            $ins_stock->execute([$id_p, $qte_recue, $nouveau_pa, $l['lot'], $l['peremp']]);

            // 5. Mise à jour de la ligne de commande
            $upd_cmd_L = $pdo->prepare("UPDATE commande_lignes SET quantite_recue = ? WHERE id_commande = ? AND id_produit = ?");
            $upd_cmd_L->execute([$qte_recue, $id_cmd, $id_p]);
        }

        // Finaliser la commande
        $pdo->prepare("UPDATE commandes SET statut = 'livree' WHERE id_commande = ?")->execute([$id_cmd]);

        $pdo->commit();
        echo json_encode(['status' => 'success']);

    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
}

elseif ($action == 'annuler_commande') {
    try {
        $id = intval($_POST['id_commande']);
        
        // On met simplement à jour le statut
        $stmt = $pdo->prepare("UPDATE commandes SET statut = 'annulee' WHERE id_commande = ?");
        $stmt->execute([$id]);

        echo json_encode(['status' => 'success']);
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
}

// --- MODIFICATION COMPLÈTE ---
elseif ($action == 'modifier_produit_complet') {
    $d = $_POST['data'];
    $sql = "UPDATE produits SET 
            nom_commercial = ?, 
            prix_unitaire = ?, 
            emplacement = ?, 
            seuil_alerte = ? 
            WHERE id_produit = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$d['nom'], $d['prix'], $d['emp'], $d['seuil'], $d['id']]);
    echo json_encode(['status' => 'success']);
    exit;
}



// CAS 1 : ARCHIVAGE (Update de la colonne actif)
if ($action == 'archiver_produit') {
    $stmt = $pdo->prepare("UPDATE produits SET actif = 0 WHERE id_produit = ?");
    $stmt->execute([$_POST['id']]);

    enregistrerLog($pdo, "STATUT", "Le produit ID#$id a été $verbe.");
    echo json_encode(['status' => 'success']);
}

elseif ($action == 'supprimer_produit') {
    $id = intval($_POST['id']);
    
    try {
        $pdo->beginTransaction();

        // 1. Vérifier le stock réel
        $stmtStock = $pdo->prepare("SELECT SUM(quantite_disponible) FROM stocks WHERE id_produit = ?");
        $stmtStock->execute([$id]);
        $totalStock = $stmtStock->fetchColumn();

        if ($totalStock > 0) {
            echo json_encode(['status' => 'error', 'message' => "Le stock n'est pas vide ($totalStock unités restantes)"]);
            $pdo->rollBack();
            exit;
        }

        // 2. Récupérer le nom pour le log avant la suppression
        $nom = $pdo->query("SELECT nom_commercial FROM produits WHERE id_produit = $id")->fetchColumn();

        // 3. Supprimer les dépendances (IMPORTANT)
        // Supprime les lots en stock (même à 0)
        $pdo->prepare("DELETE FROM stocks WHERE id_produit = ?")->execute([$id]);
        
        // Supprime les mouvements de stock liés
        $pdo->prepare("DELETE FROM mouvements_stock WHERE id_produit = ?")->execute([$id]);

        // 4. Enfin, supprimer le produit
        $pdo->prepare("DELETE FROM produits WHERE id_produit = ?")->execute([$id]);

        // Log
        enregistrerLog($pdo, "SUPPRESSION", "Suppression définitive du produit : $nom (ID#$id)");

        $pdo->commit();
        echo json_encode(['status' => 'success']);

    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['status' => 'error', 'message' => "Erreur SQL : " . $e->getMessage()]);
    }
}

elseif ($action == 'changer_statut_actif') {
    $id = intval($_POST['id']);
    $etat = intval($_POST['actif']);
    $verbe = ($etat == 0) ? "archivé" : "restauré";
    
    $stmt = $pdo->prepare("UPDATE produits SET actif = ? WHERE id_produit = ?");
    $stmt->execute([$etat, $id]);
    
    echo json_encode(['status' => "success"]);
}

elseif ($action == 'get_detail_flux') {
    $id = intval($_POST['id']);
    
    // On récupère TOUS les flux, ordonnés par date décroissante
    $stmt = $pdo->prepare("SELECT m.*, DATE_FORMAT(m.date_mouvement, '%d/%m/%Y %H:%i') as date_m, u.nom_complet as utilisateur 
                           FROM mouvements_stock m 
                           LEFT JOIN utilisateurs u ON m.id_utilisateur = u.id_user 
                           WHERE m.id_produit = ? 
                           ORDER BY m.date_mouvement DESC");
    $stmt->execute([$id]);
    $flux = $stmt->fetchAll(PDO::FETCH_ASSOC);

    header('Content-Type: application/json');
    echo json_encode($flux);
    exit;
}

if ($action == 'valider_inventaire') {
    $pdo->beginTransaction();
    try {
        $id_inv = $_POST['id_inventaire'];
        $lignes = $pdo->prepare("SELECT * FROM inventaire_lignes WHERE id_inventaire = ?");
        $lignes->execute([$id_inv]);

        foreach ($lignes->fetchAll() as $l) {
            // 1. Mettre à jour la table 'stocks'
            $upd = $pdo->prepare("UPDATE stocks SET quantite_disponible = ? WHERE id_stock = ?");
            $upd->execute([$l['stock_reel'], $l['id_stock']]);

            // 2. Créer un mouvement de régularisation
            $type_mouv = ($l['ecart'] < 0) ? 'perte_inventaire' : 'gain_inventaire';
            $mov = $pdo->prepare("INSERT INTO mouvements_stock (id_produit, id_stock, type_mouvement, quantite, date_mouvement, commentaire) 
                                 VALUES (?, ?, ?, ?, NOW(), 'Régularisation Inventaire')");
            $mov->execute([$l['id_produit'], $l['id_stock'], $type_mouv, abs($l['ecart'])]);
        }

        $pdo->prepare("UPDATE inventaires SET statut = 'valide' WHERE id_inventaire = ?")->execute([$id_inv]);
        $pdo->commit();
        echo json_encode(['status' => 'success']);
    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
}

if ($action == 'finaliser_inventaire') {
    $pdo->beginTransaction();
    try {
        // 1. Créer l'entête d'inventaire
        $stmtI = $pdo->prepare("INSERT INTO inventaires (date_debut, type_inventaire, statut, id_utilisateur) VALUES (NOW(), 'general', 'valide', ?)");
        $stmtI->execute([$_SESSION['user_id']]);
        $id_inv = $pdo->lastInsertId();

        if (isset($_POST['lignes']) && is_array($_POST['lignes'])) {
            foreach ($_POST['lignes'] as $l) {
                // Récupérer l'ancien stock pour l'écart
                $old = $pdo->prepare("SELECT quantite_disponible, id_produit FROM stocks WHERE id_stock = ?");
                $old->execute([$l['id_stock']]);
                $s = $old->fetch();

                if (!$s) continue; // Sécurité si le lot n'existe plus

                $ecart = $l['reel'] - $s['quantite_disponible'];

                // 2. Enregistrer la ligne d'inventaire
                $stmtL = $pdo->prepare("INSERT INTO inventaire_lignes (id_inventaire, id_produit, id_stock, stock_theorique, stock_reel, ecart) VALUES (?, ?, ?, ?, ?, ?)");
                $stmtL->execute([$id_inv, $s['id_produit'], $l['id_stock'], $s['quantite_disponible'], $l['reel'], $ecart]);

                // 3. METTRE A JOUR LE STOCK REEL
                $upd = $pdo->prepare("UPDATE stocks SET quantite_disponible = ? WHERE id_stock = ?");
                $upd->execute([$l['reel'], $l['id_stock']]);

                // 4. Créer le mouvement de stock (CORRIGÉ ICI)
                $commentaire = "Inventaire ID: " . $id_inv; 
                $mov = $pdo->prepare("INSERT INTO mouvements_stock (id_produit, id_stock, type_mouvement, quantite, date_mouvement, id_utilisateur, commentaire) VALUES (?, ?, 'ajustement_inventaire', ?, NOW(), ?, ?)");
                $mov->execute([
                    $s['id_produit'], 
                    $l['id_stock'], 
                    abs($ecart), 
                    $_SESSION['user_id'], 
                    $commentaire
                ]);
            }
        }
        
        $pdo->commit();
        echo json_encode(['status' => 'success']);
    } catch (Exception $e) { 
        $pdo->rollBack(); 
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]); 
    }
    exit;
}

if ($action == 'liste_produits_mouvement') {
    ob_clean();
    header('Content-Type: application/json');
    try {
        // Cette requête sélectionne les produits avec leur stock total théorique
        // Elle s'assure de lister ceux qui sont en stock (stocks) 
        // ou qui apparaissent dans l'historique (mouvements_stock)
        $sql = "SELECT 
                    p.id_produit, 
                    p.nom_commercial, 
                    IFNULL(SUM(s.quantite_disponible), 0) as stock_total
                FROM produits p
                LEFT JOIN stocks s ON p.id_produit = s.id_produit
                WHERE p.id_produit IN (SELECT DISTINCT id_produit FROM stocks WHERE quantite_disponible > 0)
                   OR p.id_produit IN (SELECT DISTINCT id_produit FROM mouvements_stock)
                GROUP BY p.id_produit
                ORDER BY p.nom_commercial ASC";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute();
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    } catch (Exception $e) {
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

if ($action == 'get_lots_produit') {
    ob_clean();
    header('Content-Type: application/json');
    try {
        $id_produit = intval($_POST['id_produit']);
        
        // On récupère tous les lots actifs pour ce produit précis
        $sql = "SELECT 
                    id_stock, 
                    numero_lot, 
                    date_peremption, 
                    quantite_disponible 
                FROM stocks 
                WHERE id_produit = ? 
                AND quantite_disponible >= 0 
                ORDER BY date_peremption ASC";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$id_produit]);
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    } catch (Exception $e) {
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// 1. Liste simplifiée pour le tableau principal
if ($action == 'liste_historique') {
    ob_clean();
    header('Content-Type: application/json');
    $stmt = $pdo->query("SELECT i.*, u.username 
                         FROM inventaires i 
                         LEFT JOIN utilisateurs u ON i.id_utilisateur = u.id_user 
                         ORDER BY i.date_debut DESC");
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    exit;
}

// 2. Détails d'un inventaire spécifique (Rapport)
if ($action == 'details_inventaire') {
    ob_clean();
    header('Content-Type: application/json');
    $id_inv = intval($_POST['id_inventaire']);
    
    $sql = "SELECT il.*, p.nom_commercial, s.numero_lot 
            FROM inventaire_lignes il
            JOIN produits p ON il.id_produit = p.id_produit
            LEFT JOIN stocks s ON il.id_stock = s.id_stock
            WHERE il.id_inventaire = ?";
            
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$id_inv]);
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    exit;
}

if ($action == 'get_tous_les_lots_actifs') {
    $sql = "SELECT s.*, p.nom_commercial 
            FROM stocks s 
            JOIN produits p ON s.id_produit = p.id_produit 
            WHERE s.quantite_disponible >= 0 
            ORDER BY p.nom_commercial ASC";
    $stmt = $pdo->query($sql);
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    exit;
}

// Action pour stocker une ligne en session
if ($action == 'ajouter_session_inventaire') {
    if (!isset($_SESSION['inventaire_temp'])) {
        $_SESSION['inventaire_temp'] = [];
    }
    
    // On stocke ou on met à jour le lot dans la session
    $_SESSION['inventaire_temp'][$_POST['id_stock']] = [
        'id_stock' => $_POST['id_stock'],
        'id_produit' => $_POST['id_produit'],
        'reel' => $_POST['reel']
    ];
    
    echo json_encode(['status' => 'success']);
    exit;
}

if ($action == 'finaliser_inventaire_session') {
    ob_clean();
    header('Content-Type: application/json');

    // Vérifier si la session contient des données
    if (!isset($_SESSION['inventaire_temp']) || empty($_SESSION['inventaire_temp'])) {
        echo json_encode(['status' => 'error', 'message' => 'Aucun produit validé en session.']);
        exit;
    }

    $pdo->beginTransaction();

    try {
        // 1. Créer l'entête de l'inventaire
        $stmtI = $pdo->prepare("INSERT INTO inventaires (date_debut, type_inventaire, statut, id_utilisateur) 
                                VALUES (NOW(), 'general', 'valide', ?)");
        $stmtI->execute([$_SESSION['user_id']]);
        $id_inv = $pdo->lastInsertId();

        // 2. Parcourir les produits validés dans la session
        foreach ($_SESSION['inventaire_temp'] as $id_stock => $data) {
            $reel = floatval($data['reel']);
            
            // Récupérer les infos actuelles du lot (Théorique et ID Produit)
            $stmtS = $pdo->prepare("SELECT id_produit, quantite_disponible FROM stocks WHERE id_stock = ?");
            $stmtS->execute([$id_stock]);
            $stock_actuel = $stmtS->fetch(PDO::FETCH_ASSOC);

            if (!$stock_actuel) continue; // Sécurité si le lot a été supprimé entre temps

            $theo = floatval($stock_actuel['quantite_disponible']);
            $ecart = $reel - $theo;
            $id_produit = $stock_actuel['id_produit'];

            // 3. Enregistrer la ligne dans 'inventaire_lignes'
            $stmtL = $pdo->prepare("INSERT INTO inventaire_lignes (id_inventaire, id_produit, id_stock, stock_theorique, stock_reel, ecart) 
                                    VALUES (?, ?, ?, ?, ?, ?)");
            $stmtL->execute([$id_inv, $id_produit, $id_stock, $theo, $reel, $ecart]);

            // 4. Mettre à jour le stock réel dans la table 'stocks'
            $upd = $pdo->prepare("UPDATE stocks SET quantite_disponible = ? WHERE id_stock = ?");
            $upd->execute([$reel, $id_stock]);

            // 5. Créer le mouvement de stock pour la traçabilité
            if ($ecart != 0) {
                $type_mouv = 'ajustement_inventaire';
                $comm = "Inventaire ID: " . $id_inv . " (Ecart constaté)";
                
                $stmtM = $pdo->prepare("INSERT INTO mouvements_stock (id_produit, id_stock, type_mouvement, quantite, date_mouvement, id_utilisateur, commentaire) 
                                        VALUES (?, ?, ?, ?, NOW(), ?, ?)");
                $stmtM->execute([
                    $id_produit, 
                    $id_stock, 
                    $type_mouv, 
                    abs($ecart), 
                    $_SESSION['user_id'], 
                    $comm
                ]);
            }
        }

        // Tout s'est bien passé, on valide en base de données
        $pdo->commit();

        // IMPORTANT : Vider la session après la réussite
        unset($_SESSION['inventaire_temp']);

        echo json_encode(['status' => 'success', 'message' => 'Inventaire clôturé avec succès.']);

    } catch (Exception $e) {
        // En cas d'erreur, on annule tout
        $pdo->rollBack();
        echo json_encode(['status' => 'error', 'message' => 'Erreur technique : ' . $e->getMessage()]);
    }
    exit;
}

if ($action == 'vider_session_inventaire') {
    ob_clean();
    header('Content-Type: application/json');
    
    if (isset($_SESSION['inventaire_temp'])) {
        unset($_SESSION['inventaire_temp']);
    }
    
    echo json_encode(['status' => 'success']);
    exit;
}

// --- ACTION 1 : ENREGISTRER UNE VENTE (FEFO - AVANCÉ) ---


if ($action == 'save_vente') {
    // Récupération et décodage du panier
    $cart_items = json_decode($_POST['cart'], true);
    
    // Paramètres de la vente
    $id_client = !empty($_POST['id_client']) ? intval($_POST['id_client']) : 1;
    $id_assurance = !empty($_POST['id_assurance']) ? intval($_POST['id_assurance']) : null;
    $taux_couverture = !empty($_POST['taux_couverture']) ? intval($_POST['taux_couverture']) : 0;
    $mode_paiement = $_POST['mode_paiement'] ?? 'Espèces';
    $remise_globale = !empty($_POST['remise']) ? floatval($_POST['remise']) : 0;

    if (empty($cart_items)) {
        echo json_encode(['status' => 'error', 'message' => 'Le panier est vide']);
        exit;
    }

    try {
        $pdo->beginTransaction();

        // 1. Calcul du montant total brut du panier
        $total_brut = 0;
        foreach ($cart_items as $item) {
            $total_brut += floatval($item['price']) * intval($item['qty']);
        }

        // Application de la remise et calcul des parts
        $total_net = $total_brut - $remise_globale;
        $part_assurance = ($total_net * $taux_couverture) / 100;
        $part_patient = $total_net - $part_assurance;

        // 2. Insertion de l'entête de la vente
        $sqlVente = "INSERT INTO ventes (total, id_utilisateur, id_client, date_vente, id_assurance, 
                                        part_assurance, part_patient, mode_paiement, remise) 
                     VALUES (?, ?, ?, NOW(), ?, ?, ?, ?, ?)";
        $stmtVente = $pdo->prepare($sqlVente);
        $stmtVente->execute([
            $total_net, 
            $_SESSION['user_id'], 
            $id_client, 
            $id_assurance, 
            $part_assurance, 
            $part_patient, 
            $mode_paiement,
            $remise_globale
        ]);
        $id_vente = $pdo->lastInsertId();

        // 3. Traitement de chaque ligne du panier
        foreach ($cart_items as $item) {
            $id_p = intval($item['id']);
            $qty_affichée = intval($item['qty']); 
            $mode_vente = strtolower($item['mode']); // 'boite' ou 'detail'
            $prix_unitaire = floatval($item['price']);

            // Récupérer le coefficient de division (ex: 4 pour le Trabar)
            $stmtP = $pdo->prepare("SELECT coefficient_division FROM produits WHERE id_produit = ?");
            $stmtP->execute([$id_p]);
            $prod = $stmtP->fetch();
            
            // Si coefficient_division est vide ou 0, on utilise 1
            $rapport = ($prod && $prod['coefficient_division'] > 0) ? intval($prod['coefficient_division']) : 1;

            // CALCUL DU DÉSTOCKAGE RÉEL
            // Si c'est une boîte, on multiplie la quantité par le coefficient (ex: 1 boite * 4 ampoules)detail_ventes
            $qty_a_reduire = ($mode_vente === 'boite') ? ($qty_affichée * $rapport) : $qty_affichée;

            // Enregistrement du détail de la vente
            $sqlDet = "INSERT INTO detail_ventes (id_vente, id_produit, quantite, prix_unitaire, type_unite) 
                       VALUES (?, ?, ?, ?, ?)";
            $pdo->prepare($sqlDet)->execute([$id_vente, $id_p, $qty_affichée, $prix_unitaire, $mode_vente]);

            // 4. ALGORITHME FEFO (Déstockage par lot)
            $sqlLots = "SELECT id_stock, quantite_disponible FROM stocks 
                        WHERE id_produit = ? AND quantite_disponible > 0 AND date_peremption > CURDATE() 
                        ORDER BY date_peremption ASC";
            $stmtLots = $pdo->prepare($sqlLots);
            $stmtLots->execute([$id_p]);

            while ($qty_a_reduire > 0 && $lot = $stmtLots->fetch()) {
                $qte_sortie_lot = 0;

                if ($lot['quantite_disponible'] >= $qty_a_reduire) {
                    $qte_sortie_lot = $qty_a_reduire;
                    $qty_a_reduire = 0;
                } else {
                    $qte_sortie_lot = $lot['quantite_disponible'];
                    $qty_a_reduire -= $qte_sortie_lot;
                }

                // Mise à jour de la ligne de stock spécifique
                $pdo->prepare("UPDATE stocks SET quantite_disponible = quantite_disponible - ? WHERE id_stock = ?")
                    ->execute([$qte_sortie_lot, $lot['id_stock']]);

                // Historique du mouvement de stock
                $sqlMvt = "INSERT INTO mouvements_stock (id_produit, id_stock, type_mouvement, quantite, 
                                                       id_utilisateur, commentaire, date_mouvement) 
                           VALUES (?, ?, 'sortie_vente', ?, ?, ?, NOW())";
                $pdo->prepare($sqlMvt)->execute([
                    $id_p, 
                    $lot['id_stock'], 
                    $qte_sortie_lot, 
                    $_SESSION['user_id'], 
                    "Vente #$id_vente ($mode_vente)"
                ]);
            }

            // Vérification finale du stock
            if ($qty_a_reduire > 0) {
                throw new Exception("Erreur : Stock insuffisant pour le produit ID: $id_p");
            }
        }

        // 5. Enregistrement en Caisse
        if ($part_patient > 0) {
            $sqlCaisse = "INSERT INTO caisse (type_mouvement, montant, motif, id_vente, date_mouvement) 
                          VALUES ('entree', ?, ?, ?, NOW())";
            $pdo->prepare($sqlCaisse)->execute([
                $part_patient, 
                "Vente #$id_vente ($mode_paiement)", 
                $id_vente
            ]);
        }

        $pdo->commit();
        echo json_encode(['status' => 'success', 'id_vente' => $id_vente]);

    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
}


} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}