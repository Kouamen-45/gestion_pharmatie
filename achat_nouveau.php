<?php
session_start();
require_once 'db.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

// Récupération des fournisseurs pour le menu déroulant
$fournisseurs = $pdo->query("SELECT id_fournisseur, nom_fournisseur FROM fournisseurs ORDER BY nom_fournisseur")->fetchAll();

// Statistiques Achats (Exemple)
$total_achats_mois = $pdo->query("SELECT SUM(montant_total) FROM achats WHERE MONTH(date_achat) = MONTH(CURRENT_DATE)")->fetchColumn() ?: 0;

// 1. Total des produits référencés
$stmt = $pdo->query("SELECT COUNT(*) FROM produits");
$total_p = $stmt->fetchColumn();

// 2. Ruptures et Alertes (Stock total <= seuil_alerte)
// On somme les quantités de la table stocks pour chaque produit
$stmt = $pdo->query("
    SELECT COUNT(*) FROM produits p 
    LEFT JOIN (SELECT id_produit, SUM(quantite_disponible) as total_stock FROM stocks GROUP BY id_produit) s 
    ON p.id_produit = s.id_produit 
    WHERE IFNULL(s.total_stock, 0) <= p.seuil_alerte
");
$ruptures = $stmt->fetchColumn();

// 3. Produits Périmés (date_peremption < aujourd'hui)
$stmt = $pdo->query("SELECT COUNT(DISTINCT id_produit) FROM stocks WHERE date_peremption < CURDATE()");
$nb_perimes = $stmt->fetchColumn();

// 4. Proches péremption (périment dans les 3 prochains mois / 90 jours)
$stmt = $pdo->query("SELECT COUNT(DISTINCT id_produit) FROM stocks WHERE date_peremption BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 90 DAY)");
$nb_proches = $stmt->fetchColumn();

 $all_fournisseurs = $pdo->query("SELECT id_fournisseur, nom_fournisseur FROM fournisseurs ORDER BY nom_fournisseur ASC")->fetchAll();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>PharmAssist - Achats & Approvisionnement</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
        /* COPIE EXACTE DU DESIGN "GESTION STOCK" */
        :root { --sidebar-width: 250px; --primary: #2c3e50; --secondary: #27ae60; --warning: #f39c12; --danger: #e74c3c; --info: #3498db; --light: #f4f7f6; --win-blue: #0984e3; }
        body { margin: 0; font-family: 'Segoe UI', sans-serif; background: var(--light); display: flex; }
        
        .sidebar { width: var(--sidebar-width); background: var(--primary); height: 100vh; color: white; position: fixed; overflow-y: auto; display: flex; flex-direction: column; z-index: 1000; }
        .sidebar-header { padding: 20px; text-align: center; }
        .sidebar-menu { list-style: none; padding: 0; }
        .sidebar-menu a { color: white; text-decoration: none; padding: 15px 20px; display: flex; align-items: center; gap: 12px; transition: 0.3s; }
        .sidebar-menu a:hover { background: rgba(255,255,255,0.1); }
        .sidebar-menu a.active { background: var(--secondary); }

        .content { margin-left: var(--sidebar-width); flex: 1; padding: 25px; width: calc(100% - var(--sidebar-width)); }
        
        /* Stats Row */
        .stats-row { display: grid; grid-template-columns: repeat(4, 1fr); gap: 15px; margin-bottom: 25px; }
        .stat-card { background: white; padding: 15px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.05); display: flex; justify-content: space-between; align-items: center; }
        .stat-label { font-size: 0.8rem; color: #7f8c8d; font-weight: bold; text-transform: uppercase; }
        .stat-val { font-size: 1.5rem; font-weight: bold; color: var(--primary); }

        /* Navigation Dropdown Style */
        .tab-navbar { display: flex; background-color: #f8f9fa; border-bottom: 2px solid #dee2e6; overflow: visible; position: relative; z-index: 100; border-radius: 8px 8px 0 0; }
        .nav-item-dropdown { position: relative; display: inline-block; }
        .dropbtn { background-color: transparent; color: #2c3e50; padding: 15px 20px; font-size: 14px; font-weight: 600; border: none; cursor: pointer; transition: 0.3s; border-right: 1px solid #eee; }
        .dropbtn:hover { background-color: #e9ecef; }
        
        .dropdown-content { display: none; position: absolute; top: 100%; left: 0; background-color: white; min-width: 220px; box-shadow: 0px 8px 16px rgba(0,0,0,0.2); z-index: 9999; border: 1px solid #ddd; }
        .dropdown-content a { color: #333; padding: 12px 16px; text-decoration: none; display: block; font-size: 13px; border-bottom: 1px solid #f1f1f1; }
        .dropdown-content a:hover { background-color: #f1f1f1; color: var(--win-blue); }
        .nav-item-dropdown:hover .dropdown-content { display: block; }

        /* Panels & Tables */
        .panel { display: none; background: white; border-radius: 0 0 8px 8px; padding: 25px; box-shadow: 0 4px 15px rgba(0,0,0,0.05); min-height: 450px; animation: fadeIn 0.3s; }
        @keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }
        
        .win-table { width: 100%; border-collapse: collapse; font-size: 0.9rem; }
        .win-table th { text-align: left; padding: 12px 10px; border-bottom: 2px solid #eee; color: #7f8c8d; font-size: 0.85rem; text-transform: uppercase; }
        .win-table td { padding: 12px 10px; border-bottom: 1px solid #eee; }
        .win-table tr:hover { background-color: #f7fafc; }

        .search-results-floating { position: absolute; width: 100%; background: white; z-index: 1000; border: 1px solid #ddd; box-shadow: 0 4px 10px rgba(0,0,0,0.1); max-height: 200px; overflow-y: auto; }
    </style>
</head>
<body>

    <nav class="sidebar">
        <div class="sidebar-header" style="padding:20px; text-align:center;">
             <h2 style="color:var(--secondary)">PharmAssist</h2>
        </div>
        <ul class="sidebar-menu">
            <li><a href="dashboard.php"><i class="fas fa-th-large"></i> Dashboard</a></li>
            <li><a href="ventes.php"><i class="fas fa-shopping-cart"></i> Ventes</a></li>
            <li><a href="stocks_inventaire.php"><i class="fas fa-boxes"></i> Stocks & Flux</a></li>
            <li><a href="achats_appro.php" class="active"><i class="fas fa-truck-loading"></i> Achats & Appro</a></li>
            <li><a href="logout.php" style="color: #ff7675;"><i class="fas fa-sign-out-alt"></i> Déconnexion</a></li>
        </ul>
    </nav>
<div class="main-content">
    <div class="content">
        <h1>Approvisionnement & Commandes</h1>

        <div class="stats-row">
        <div class="stat-card">
            <div><div class="stat-label">Produits</div><div class="stat-val"><?= $total_p ?></div></div>
            <div style="color:var(--info)"><i class="fas fa-pills fa-2x"></i></div>
        </div>
        <div class="stat-card" style="border-left: 5px solid var(--danger)">
            <div><div class="stat-label">Besoins / Ruptures</div><div class="stat-val" style="color:var(--danger)"><?= $ruptures ?></div></div>
            <div style="color:var(--danger)"><i class="fas fa-exclamation-circle fa-2x"></i></div>
        </div>
        <div class="stat-card">
            <div><div class="stat-label">Périmés</div><div class="stat-val"><?= $nb_perimes ?></div></div>
            <div style="color:var(--danger)"><i class="fas fa-calendar-times fa-2x"></i></div>
        </div>
        <div class="stat-card">
            <div><div class="stat-label">À commander d'urgence</div><div class="stat-val"><?= $nb_proches ?></div></div>
            <div style="color:var(--warning)"><i class="fas fa-hourglass-half fa-2x"></i></div>
        </div>
    </div>

        <nav class="tab-navbar">
            <button class="dropbtn" onclick="showPanel('facture')"><i class="fas fa-file-download"></i>/Facture</button>
            <button class="dropbtn" onclick="showPanel('suggestions')"><i class="fas fa-brain"></i> Appro Intelligent</button> 
            <button class="dropbtn" onclick="showPanel('historique')"><i class="fas fa-history"></i> Historique Achats</button>
            <button class="dropbtn" onclick="showPanel('dettes')"><i class="fas fa-money-bill-wave"></i> Dettes Fournisseurs</button>
            <button class="dropbtn" onclick="showPanel('retours')"><i class="fas fa-undo"></i> Retour de Produits</button>
            <button class="dropbtn" onclick="showPanel('bon-livraison')"><i class="fas fa-address-book"></i>BL</button>
            <button class="dropbtn" onclick="showPanel('reception')"><i class="fas fa-address-book"></i> Réception/Contrôle</button>
        </nav>

     <div class="">

    <div id="panel-facture" class="panel">
        <form id="form-reception">
            <div class="row mb-4">
                <div class="col-md-4">
                    <label>Fournisseur</label>
                    <select class="form-select" name="id_fournisseur" id="id_fournisseur" required>
                        <option value="">-- Sélectionner Fournisseur --</option>
                        <?php 
                        $res = $pdo->query("SELECT id_fournisseur, nom_fournisseur FROM fournisseurs");
                        while($f = $res->fetch()) echo "<option value='{$f['id_fournisseur']}'>{$f['nom_fournisseur']}</option>";
                        ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label>N° Facture / BL</label>
                    <input type="text" class="form-control" name="num_facture" id="num_facture" required>
                </div>
                <div class="col-md-4">
                    <label>Date Réception</label>
                    <input type="date" class="form-control" name="date_achat" id="date_achat" value="<?= date('Y-m-d') ?>">
                </div>

                <div class="row mt-4 p-3" style="background: #f1f2f6; border-radius: 8px;">
                    <div class="col-md-3">
                        <label><b>Mode de Règlement</b></label>
                        <select class="form-select" name="mode_reglement" id="mode_reglement" onchange="gererTypePaiement()">
                            <option value="comptant">Comptant (Espèces/Chèque)</option>
                            <option value="credit">Crédit Fournisseur</option>
                            <option value="partiel">Paiement Partiel</option>
                        </select>
                    </div>
                    
                    <div class="col-md-3" id="div-montant-paye">
                        <label><b>Montant Versé</b></label>
                        <input type="number" class="form-control" name="montant_verse" id="montant_verse" value="0">
                    </div>

                    <div class="col-md-3" id="div-echeance" style="display:none;">
                        <label><b>Date d'échéance</b></label>
                        <input type="date" class="form-control" name="date_echeance">
                    </div>
</div>
            </div>

            <div style="position: relative;" class="mb-4">
                <label><b>Rechercher un produit à ajouter :</b></label>
                <input type="text" id="search-prod" class="form-control" placeholder="Scanner ou taper le nom..." onkeyup="rechercherProduitAchat(this.value)">
                <div id="res-search" class="search-results-floating"></div>
            </div>

            <table class="win-table" id="table-items">
                <thead>
                    <tr class="table-dark">
                        <th>Désignation</th>
                        <th>N° Lot</th>
                        <th>Péremption</th>
                        <th>Dernier P.A (Brt)</th> 
                        <th>Qté (Brt)</th>
                        <th>Nouveau P.A (Brt)</th>
                        <th>Total</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody></tbody>
                <tfoot>
                    <tr class="table-light">
                        <td colspan="5" class="text-end"><strong>MONTANT TOTAL FACTURE :</strong></td>
                        <td id="grand-total" class="fw-bold text-primary">0</td>
                        <td class="fw-bold text-primary">FCFA</td>
                    </tr>
                </tfoot>
            </table>

            <div class="text-end mt-4">
                <button type="button" class="btn btn-success btn-lg" onclick="finaliserAchat()">
                    <i class="fas fa-check-circle"></i> Valider l'Entrée en Stock
                </button>
            </div>
        </form>
    </div>

   
</div>


 <div id="panel-historique" class="panel mt-5">
    <h3><i class="fas fa-history"></i> 5 Dernières Factures Réceptionnées</h3>
    <table class="win-table">
        <thead>
            <tr class="table-secondary">
                <th>Date</th>
                <th>Fournisseur</th>
                <th>N° Facture</th>
                <th>Montant Total</th>
                <th>Statut</th>
                <th>Action</th>
            </tr>
        </thead>
        <tbody id="last-achats-body">
            </tbody>
    </table>
</div>

    <div class="panel mt-4" id="panel-dettes">
        <table class="win-table">
            <thead>
                <tr class="table-dark">
                    <th>Fournisseur</th>
                    <th>N° Facture</th>
                    <th>Date Achat</th>
                    <th>Échéance</th>
                    <th>Total</th>
                    <th>Déjà Payé</th>
                    <th>Reste à Payer</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody id="liste-dettes">
                </tbody>
        </table>
</div>

<div class="panel" id="panel-retours">
        <div class="row mb-3">
            <div class="col-md-6">
                <label>Rechercher le lot à retourner :</label>
                <input type="text" id="search-lot" class="form-control" placeholder="Scanner le lot ou taper le nom du produit..." onkeyup="chercherLotPourRetour(this.value)">
                <div id="res-search-lot" class="search-results-floating"></div>
            </div>
        </div>

        <table class="win-table" id="table-retour">
            <thead>
                <tr class="table-dark">
                    <th>Produit / Lot</th>
                    <th>Stock Actuel</th>
                    <th>Quantité à Retourner</th>
                    <th>Motif</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody id="body-retour"></tbody>
        </table>
        
        <div class="text-end mt-3">
            <button class="btn btn-danger btn-lg" onclick="validerTousLesRetours()">
                <i class="fas fa-shipping-fast"></i> Confirmer l'expédition du retour
            </button>
        </div>
    </div>








   <div id="panel-suggestions" class="panel">
    <div class="row">
        <div class="col-md-8">
            <div class="">
                <h3><i class="fas fa-lightbulb text-warning"></i> Suggestions d'achat</h3>
                <p class="text-muted small">Basé sur le stock mini, max et les commandes en cours.</p>
                <table class="win-table">
                    <thead>
                        <tr class="table-primary">
                            <th>Produit</th>
                            <th>Stock</th>
                            <th>Alerte</th>
                            <th>Proposition</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody id="body-suggestions">
                        </tbody>
                </table>
            </div>
        </div>

        <div class="col-md-4">
            <div class="border-primary" style="background: #f8f9fa;">
                <h4><i class="fas fa-shopping-cart"></i> Panier de Commande</h4>
                <div class="mb-2">
                    <select id="appro-fournisseur" class="form-select">
                        <option value="">-- Choisir Fournisseur --</option>
                         <?php foreach ($fournisseurs as $f): ?>
                    <option value="<?= $f['id_fournisseur'] ?>" <?= ($f['id_fournisseur'] == $f['id_fournisseur']) ? 'selected' : '' ?>>
                        <?= htmlspecialchars($f['nom_fournisseur']) ?>
                    </option>
                <?php endforeach; ?>
                        </select>
                </div>
                <ul id="panier-liste" class="list-group mb-3" style="max-height: 300px; overflow-y: auto;">
                    </ul>
                <button class="btn btn-primary w-100" onclick="genererBonDeCommande()">
                    <i class="fas fa-check-circle"></i> Créer le Bon de Commande
                </button>
            </div>
        </div>
    </div>
</div>




</div>


</div>

    <div class="modal fade" id="modalDetailAchat" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-info text-white">
                <h5 class="modal-title">Détails de la Facture : <span id="modal-num-facture"></span></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="row mb-3">
                    <div class="col-6"><strong>Fournisseur :</strong> <span id="modal-fournisseur"></span></div>
                    <div class="col-6 text-end"><strong>Date :</strong> <span id="modal-date"></span></div>
                </div>
                <table class="table table-sm table-bordered">
                    <thead class="table-light">
                        <tr>
                            <th>Produit</th>
                            <th>N° Lot</th>
                            <th>Péremption</th>
                            <th class="text-center">Qté (Détail)</th>
                            <th class="text-end">P.A Unitaire</th>
                        </tr>
                    </thead>
                    <tbody id="modal-corps-detail">
                        </tbody>
                </table>
            </div>
        </div>
    </div>
</div>


<div class="modal fade" id="modalPaiement" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header"><h5>Enregistrer un règlement</h5></div>
            <div class="modal-body">
                <input type="hidden" id="pay-id-achat">
                <p>Facture : <b id="pay-num"></b> (<span id="pay-fournisseur"></span>)</p>
                <div class="mb-3">
                    <label>Montant restant : <b id="pay-reste"></b> FCFA</label>
                    <input type="number" id="montant-reglement" class="form-control" placeholder="Saisir le montant versé">
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-success" onclick="validerReglement()">Confirmer le paiement</button>
            </div>
        </div>
    </div>
</div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

   
</body>
</html>