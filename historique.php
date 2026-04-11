<?php
session_start();
require_once 'db.php';
if (!isset($_SESSION['user_id'])) { header('Location: login.php'); exit(); }

// Requête avec jointure pour récupérer les noms des produits vendus (GROUP_CONCAT pour la recherche globale)
$ventes = $pdo->query("SELECT v.*, u.username as nom_utilisateur, 
                       GROUP_CONCAT(p.nom_commercial SEPARATOR ', ') as produits_vendus
                       FROM ventes v 
                       LEFT JOIN utilisateurs u ON v.id_utilisateur = u.id_user 
                       LEFT JOIN details_ventes dv ON v.id_vente = dv.id_vente
                       LEFT JOIN produits p ON dv.id_produit = p.id_produit
                       GROUP BY v.id_vente
                       ORDER BY v.date_vente DESC")->fetchAll();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>PharmAssist - Historique des Ventes</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <style>
        :root {
            --sidebar-width: 250px;
            --primary: #2c3e50;
            --secondary: #27ae60;
            --warning: #f39c12;
            --danger: #e74c3c;
            --info: #3498db;
            --light: #f4f7f6;
        }
        body { margin: 0; font-family: 'Segoe UI', sans-serif; background: var(--light); display: flex; }

        /* Sidebar - Identique au Dashboard */
        .sidebar { 
            width: var(--sidebar-width); 
            background: var(--primary); 
            height: 100vh; 
            color: white; 
            position: fixed; 
            overflow-y: auto; 
            display: flex; 
            flex-direction: column; 
        }
        .sidebar-header { padding: 20px; text-align: center; background: rgba(0,0,0,0.1); border-bottom: 1px solid rgba(255,255,255,0.1); }
        .sidebar-menu { list-style: none; padding: 0; margin: 0; }
        .sidebar-menu li { border-bottom: 1px solid rgba(255,255,255,0.05); }
        .sidebar-menu a { color: white; text-decoration: none; padding: 15px 20px; display: flex; align-items: center; gap: 12px; transition: 0.3s; }
        .sidebar-menu a:hover { background: rgba(255,255,255,0.1); }
        .sidebar-menu a.active { background: var(--secondary); }
        .sidebar::-webkit-scrollbar { width: 5px; }
        .sidebar::-webkit-scrollbar-thumb { background: rgba(255, 255, 255, 0.2); border-radius: 10px; }

        /* Main Content */
        .main-content { margin-left: var(--sidebar-width); flex: 1; padding: 30px; }
        
        /* Filtres */
        .filter-section { background: white; padding: 20px; border-radius: 12px; margin-bottom: 25px; display: flex; gap: 20px; align-items: flex-end; box-shadow: 0 4px 15px rgba(0,0,0,0.05); }
        .filter-group { display: flex; flex-direction: column; gap: 8px; }
        .filter-group label { font-size: 0.85rem; font-weight: bold; color: var(--primary); }
        .filter-group input { padding: 10px; border: 1px solid #ddd; border-radius: 8px; outline: none; }
        .filter-group input:focus { border-color: var(--info); }

        /* Table */
        .table-container { background: white; padding: 25px; border-radius: 12px; box-shadow: 0 4px 15px rgba(0,0,0,0.05); }
        table { width: 100%; border-collapse: collapse; }
        th { text-align: left; padding: 15px; color: #7f8c8d; border-bottom: 2px solid var(--light); font-size: 14px; }
        td { padding: 15px; border-bottom: 1px solid var(--light); color: var(--primary); font-size: 15px; }
        
        .badge-price { background: #eafaf1; color: var(--secondary); padding: 6px 12px; border-radius: 6px; font-weight: bold; }
        
        .btn-action { width: 35px; height: 35px; border-radius: 8px; border: none; cursor: pointer; transition: 0.2s; display: inline-flex; align-items: center; justify-content: center; }
        .btn-view { background: var(--info); color: white; }
        .btn-cancel { background: var(--danger); color: white; }
        .btn-print { background: #95a5a6; color: white; }
        .btn-action:hover { opacity: 0.8; transform: translateY(-2px); }
        
        h2 { color: var(--primary); margin-bottom: 25px; display: flex; align-items: center; gap: 10px; }
    </style>
</head>
<body>

    <nav class="sidebar">
        <div class="sidebar-header">
           <img src="logo_pharmassist.png" style="width: 80px;height: 80px; object-fit: contain;" />
        </div>
        <ul class="sidebar-menu">
            <li><a href="dashboard.php"><i class="fas fa-th-large"></i> Dashboard</a></li>
            <li><a href="ventes.php"><i class="fas fa-shopping-cart"></i> Ventes</a></li>
            <li><a href="caisse.php"><i class="fas fa-cash-register"></i> Ma Caisse</a></li>
            <li><a href="stocks_inventaire.php"><i class="fas fa-boxes"></i> Inventaire Stock</a></li>
            <li><a href="rapport_mensuel.php"><i class="fas fa-chart-bar"></i> Rapports</a></li>
            <li><a href="achat_nouveau.php"><i class="fas fa-cart-plus"></i> Achats</a></li>
            <li><a href="achats_historique.php"><i class="fas fa-history"></i> Historique Achats</a></li>
            <li><a href="archives_caisse.php"><i class="fas fa-archive"></i> Archive Caisse</a></li>
            <li><a href="facture.php" class="active"><i class="fas fa-file-invoice"></i> Historique Ventes</a></li>
            <li style="margin-top: 30px;"><a href="logout.php" style="color: #ff7675;"><i class="fas fa-sign-out-alt"></i> Déconnexion</a></li>
        </ul>
    </nav>

    <div class="main-content">
        <h2><i class="fas fa-history"></i> Historique des Ventes</h2>

        <div class="filter-section">
            <div class="filter-group">
                <label><i class="fas fa-calendar-alt"></i> Filtrer par Date</label>
                <input type="date" id="filter-date">
            </div>
            <div class="filter-group" style="flex: 1;">
                <label><i class="fas fa-search"></i> Recherche globale</label>
                <input type="text" id="search-sale" placeholder="Chercher un médicament, un ID de vente ou un vendeur...">
            </div>
            <button onclick="resetFilters()" style="padding: 10px 20px; border-radius: 8px; border: 1px solid #ddd; cursor: pointer; background: #fff;">
                <i class="fas fa-sync"></i> Réinitialiser
            </button>
        </div>

        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th>ID VENTE</th>
                        <th>DATE & HEURE</th>
                        <th>VENDEUR</th>
                        <th>MONTANT TOTAL</th>
                        <th>ACTIONS</th>
                    </tr>
                </thead>
                <tbody id="sales-tbody">
                    <?php foreach($ventes as $v): ?>
                    <tr class="sale-row" 
                        data-date="<?= date('Y-m-d', strtotime($v['date_vente'])) ?>" 
                        data-produits="<?= strtolower(htmlspecialchars($v['produits_vendus'])) ?>">
                        <td><b>#<?= $v['id_vente'] ?></b></td>
                        <td><?= date('d/m/Y à H:i', strtotime($v['date_vente'])) ?></td>
                        <td><i class="fas fa-user-circle"></i> <?= htmlspecialchars($v['nom_utilisateur'] ?? 'Système') ?></td>
                        <td><span class="badge-price"><?= number_format($v['total'], 0, '.', ' ') ?> F</span></td>
                        <td>
                            <button class="btn-action btn-view" onclick="voirDetails(<?= $v['id_vente'] ?>)" title="Détails">
                                <i class="fas fa-eye"></i>
                            </button>
                            <button class="btn-action btn-print" onclick="window.open('facture.php?id=<?= $v['id_vente'] ?>', '_blank')" title="Imprimer">
                                <i class="fas fa-print"></i>
                            </button>
                            <button class="btn-action btn-cancel" onclick="annulerVente(<?= $v['id_vente'] ?>)" title="Annuler la vente">
                                <i class="fas fa-undo"></i>
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <script>
    function applyFilters() {
        let dateVal = $('#filter-date').val();
        let searchVal = $('#search-sale').val().toLowerCase();

        $('.sale-row').each(function() {
            let rowDate = $(this).data('date');
            let rowText = $(this).text().toLowerCase();
            let listProduits = $(this).data('produits');
            
            let matchDate = (dateVal === "") || (rowDate === dateVal);
            let matchSearch = (searchVal === "") || (rowText.includes(searchVal)) || (listProduits.includes(searchVal));

            $(this).toggle(matchDate && matchSearch);
        });
    }

    $('#filter-date, #search-sale').on('input change', applyFilters);

    function resetFilters() {
        $('#filter-date').val('');
        $('#search-sale').val('');
        $('.sale-row').show();
    }

    function voirDetails(id) {
        $.get('ajax_ventes.php', { action: 'get_details', id_vente: id }, function(res) {
            let html = '<table style="width:100%; border-collapse:collapse; margin-top:10px;"><thead><tr style="border-bottom:2px solid #eee"><th style="text-align:left; padding:10px">Produit</th><th style="padding:10px">Qté</th><th style="padding:10px">Prix</th></tr></thead><tbody>';
            res.forEach(item => {
                html += `<tr style="border-bottom:1px solid #eee"><td style="text-align:left; padding:10px">${item.nom_commercial}</td><td style="padding:10px; font-weight:bold;">${item.quantite}</td><td style="padding:10px">${parseInt(item.prix_unitaire).toLocaleString()} F</td></tr>`;
            });
            html += '</tbody></table>';
            Swal.fire({ title: 'Détails de la vente #' + id, html: html, width: '600px', confirmButtonColor: '#3498db' });
        }, 'json');
    }

    function annulerVente(id) {
        Swal.fire({
            title: 'Annuler cette vente ?',
            text: "Attention : les stocks seront remis à jour et la vente sera supprimée de la caisse.",
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#e74c3c',
            confirmButtonText: 'Oui, annuler',
            cancelButtonText: 'Retour'
        }).then((result) => {
            if (result.isConfirmed) {
                $.post('ajax_ventes.php', { action: 'cancel_sale', id_vente: id }, function(res) {
                    if(res.status === 'success') {
                        Swal.fire('Succès', 'Vente annulée et stocks restaurés.', 'success').then(() => location.reload());
                    } else {
                        Swal.fire('Erreur', res.message, 'error');
                    }
                }, 'json');
            }
        });
    }
    </script>
</body>
</html>