<?php
session_start();
require_once 'db.php';
if (!isset($_SESSION['user_id'])) { header('Location: login.php'); exit(); }

// --- CODE DE BLOCAGE SESSION CAISSE ---
$checkCaisse = $pdo->query("SELECT id_session FROM sessions_caisse WHERE statut = 'ouvert' LIMIT 1")->fetch();
if (!$checkCaisse) {
    header('Location: caisse.php?error=no_session');
    exit();
}
$id_session = $checkCaisse['id_session'];

// --- STATS DU JOUR ---
$stats = $pdo->query("SELECT COUNT(id_vente) as nb, SUM(total) as ca FROM ventes WHERE DATE(date_vente) = CURDATE()")->fetch();

// --- RÉCUPÉRATION DES PRODUITS (Correction SQL selon ton schéma) ---
// Utilisation de produits et stocks
$sql = "SELECT p.*, 
        IFNULL((SELECT SUM(s.quantite_disponible) FROM stocks s WHERE s.id_produit = p.id_produit AND s.date_peremption > CURDATE()), 0) as stock_dispo
        FROM produits p 
        HAVING stock_dispo > 0";
$produits = $pdo->query($sql)->fetchAll();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>PharmAssist - Ventes</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <style>
        :root { --sidebar-width: 250px; --primary: #2c3e50; --secondary: #27ae60; --info: #3498db; --light: #f4f7f6; --danger: #e74c3c; --warning: #f39c12; }
        body { margin: 0; font-family: 'Segoe UI', sans-serif; background: var(--light); display: flex; height: 100vh; overflow: hidden; }
        
        .sidebar { width: var(--sidebar-width); background: var(--primary); height: 100vh; color: white; position: fixed; }
        .sidebar-header { padding: 20px; text-align: center; border-bottom: 1px solid rgba(255,255,255,0.1); }
        .sidebar-menu { list-style: none; padding: 0; }
        .sidebar-menu a { color: white; text-decoration: none; padding: 15px 20px; display: flex; align-items: center; gap: 12px; transition: 0.3s; }
        .sidebar-menu a.active { background: var(--secondary); }

        .main-sales { margin-left: var(--sidebar-width); flex: 1; display: flex; height: 100vh; }
        .content-area { flex: 1; display: flex; flex-direction: column; overflow: hidden; padding: 20px; }

        /* Stats */
        .stats-row { display: grid; grid-template-columns: repeat(3, 1fr); gap: 20px; margin-bottom: 20px; }
        .stat-card { background: white; padding: 15px; border-radius: 10px; display: flex; align-items: center; gap: 15px; box-shadow: 0 2px 5px rgba(0,0,0,0.05); }
        .stat-card i { font-size: 22px; color: var(--info); }
        .stat-card h4 { margin: 0; font-size: 13px; color: #7f8c8d; }
        .stat-card p { margin: 0; font-size: 17px; font-weight: bold; }

        /* Tabs */
        .tabs-navigation { display: flex; gap: 10px; border-bottom: 2px solid #ddd; margin-bottom: 20px; }
        .tab-btn { padding: 10px 15px; border: none; background: none; cursor: pointer; font-weight: bold; color: #7f8c8d; }
        .tab-btn.active { color: var(--secondary); border-bottom: 3px solid var(--secondary); }
        .v-panel { display: none; height: 100%; overflow-y: auto; padding-bottom: 50px; }
        .v-panel.active { display: block; }

        /* Grid */
        .product-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); gap: 15px; }
        .product-card { background: white; padding: 12px; border-radius: 10px; cursor: pointer; box-shadow: 0 2px 4px rgba(0,0,0,0.05); border: 1px solid transparent; position: relative; }
        .product-card:hover { border-color: var(--info); transform: translateY(-2px); }
        .stock-badge { position: absolute; top: 5px; right: 5px; background: #eee; padding: 2px 6px; border-radius: 4px; font-size: 11px; }

        /* Cart */
        .cart-section { width: 380px; background: white; border-left: 1px solid #ddd; display: flex; flex-direction: column; }
        .cart-header { padding: 20px; background: var(--primary); color: white; font-weight: bold; display: flex; justify-content: space-between; }
        .cart-items { flex: 1; overflow-y: auto; padding: 10px; }
        .cart-item { border-bottom: 1px solid #eee; padding: 10px 5px; font-size: 14px; }
        .cart-footer { padding: 20px; background: #f9f9f9; border-top: 1px solid #eee; }
        .btn-pay { width: 100%; padding: 15px; background: var(--secondary); color: white; border: none; border-radius: 8px; font-weight: bold; cursor: pointer; font-size: 16px; }
        
        .unit-toggle { font-size: 10px; background: var(--info); color: white; padding: 2px 4px; border-radius: 3px; cursor: help; }

        .win-table { width: 100%; border-collapse: collapse; }
        .win-table th { text-align: left; padding: 8px; border-bottom: 1px solid #ddd; color: #555; font-size: 12px; }
        .win-table td { padding: 8px; border-bottom: 1px solid #f5f5f5; font-size: 13px; }

        .badge-mode { 
            background: #f0f0f0; 
            padding: 2px 6px; 
            border-radius: 4px; 
            font-size: 10px; 
            border: 1px solid #ccc; 
        }

        .btn-reimprimer { 
            background: none; 
            border: none; 
            color: #007bff; 
            cursor: pointer; 
            padding: 0;
        }

        .btn-reimprimer:hover { color: #0056b3; }
    </style>
</head>
<body>

    <nav class="sidebar">
        <div class="sidebar-header"><h3>PharmAssist</h3></div>
        <ul class="sidebar-menu">
            <li><a href="dashboard.php"><i class="fas fa-th-large"></i> Dashboard</a></li>
            <li><a href="ventes.php" class="active"><i class="fas fa-shopping-cart"></i> Ventes</a></li>
            <li><a href="caisse.php"><i class="fas fa-shopping-cart"></i> Caisse</a></li>
            <li><a href="produits_gestion.php"><i class="fas fa-boxes"></i> Stocks & Flux</a></li>
            <li><a href="logout.php" style="color: #ff7675;"><i class="fas fa-sign-out-alt"></i> Déconnexion</a></li>
        </ul>
    </nav>

    <div class="main-sales">
        <div class="content-area">
            <!-- Stats Rows -->
            <div class="stats-row">
                <div class="stat-card">
                    <i class="fas fa-receipt"></i>
                    <div><h4>Ventes</h4><p><?= $stats['nb'] ?? 0 ?></p></div>
                </div>
                <div class="stat-card">
                    <i class="fas fa-cash-register" style="color:var(--secondary)"></i>
                    <div><h4>CA Jour</h4><p><?= number_format($stats['ca'] ?? 0, 0, '.', ' ') ?> F</p></div>
                </div>
                <div class="stat-card">
                    <i class="fas fa-user-shield" style="color:var(--warning)"></i>
                    <div><h4>Session</h4><p>#<?= $id_session ?> (Active)</p></div>
                </div>
            </div>

            <!-- Tabs -->
            <div class="tabs-navigation">
                <button class="tab-btn active" onclick="showPanel('v-comptoir')"><i class="fas fa-cash-register"></i> Comptoir</button>
                <button class="tab-btn" onclick="showPanel('v-attente')"><i class="fas fa-pause"></i> En attente (<span id="count-attente">0</span>)</button>
                <button class="tab-btn" onclick="showPanel('v-historique')"><i class="fas fa-list"></i> Historique</button>
                <button class="tab-btn" onclick="showPanel('v-cloture')"><i class="fas fa-lock"></i> Clôture</button>
            </div>

            <!-- Panel Comptoir -->
            <div id="v-comptoir" class="v-panel active">
                <div style="margin-bottom:20px;">
                    <input type="text" id="search-prod" style="width:100%; padding:15px; border-radius:10px; border:1px solid #ddd;" placeholder="Rechercher nom commercial ou molécule...">
                </div>
                <div class="product-grid" id="results-area">
                    <?php foreach($produits as $p): ?>
                        <div class="product-card" 
                                onclick="addToCart(<?= $p['id_produit'] ?>, '<?= addslashes($p['nom_commercial']) ?>', <?= $p['prix_unitaire'] ?>, <?= $p['stock_dispo'] ?>, <?= $p['prix_unitaire_detail'] ?? 0 ?>, <?= $p['coefficient_division'] ?? 1 ?> )">
                            <span class="stock-badge"><?= $p['stock_dispo'] ?></span>
                            <strong><?= htmlspecialchars($p['nom_commercial']) ?></strong><br>
                            <small class="text-muted"><?= htmlspecialchars($p['molecule']) ?></small>
                            <div style="color:var(--secondary); font-weight:bold; margin-top:10px;"><?= number_format($p['prix_unitaire'], 0, '.', ' ') ?> F</div>
                            <?php if($p['prix_unitaire_detail'] > 0): ?>
                                <span class="unit-toggle">Détail possible</span>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Panel Attente -->
            <div id="v-attente" class="v-panel">
                <h4>Paniers mis en pause</h4>
                <div id="list-attente" class="row g-3 mt-2"></div>
            </div>

            <!-- Panel Historique -->
            <div id="v-historique" class="v-panel">
                <table class="win-table">
                    <thead><tr><th>Heure</th><th>Total</th><th>Mode</th><th>Action</th></tr></thead>
                    <tbody id="body-histo-jour">
           
                    </tbody>
                </table>
            </div>

            <!-- Panel Clôture -->
            <div id="v-cloture" class="v-panel text-center">
                <div class="card p-5 mx-auto" style="max-width:500px; background:white;">
                    <i class="fas fa-lock-open fa-3x mb-3 text-warning"></i>
                    <h4>Fin de Session</h4>
                    <p>En clôturant, vous générez le rapport financier et videz votre tiroir-caisse.</p>
                    <button class="btn-pay" style="background:var(--danger)" onclick="cloturerSession()">FERMER LA CAISSE</button>
                </div>
            </div>
        </div>

        <!-- Right Cart -->
        <div class="cart-section">
            <div class="cart-header">
                <span><i class="fas fa-shopping-basket"></i> PANIER</span>
                <span id="cart-count">0 art.</span>
            </div>
            <div class="cart-items" id="cart-list">
                <p style="text-align:center; color:#999; margin-top:50px;">Panier vide</p>
            </div>
            <div class="cart-footer">
                <div class="d-flex justify-content-between mb-2">
                    <span>Sous-total:</span>
                    <span id="st-val">0 F</span>
                </div>
                <div class="d-flex justify-content-between mb-2 text-warning" onclick="appliquerRemise()" style="cursor:pointer">
                    <span>Remise: <i class="fas fa-edit small"></i></span>
                    <span id="remise-val">0 F</span>
                </div>
                <div style="font-size:22px; font-weight:bold; margin-bottom:15px; border-top:1px solid #ddd; padding-top:10px;">
                    TOTAL: <span id="total-val" class="text-primary" style="float:right">0</span>
                </div>
                
                <div class="row g-2 mb-3">
                    <div class="col-6">
                        <small>Reçu:</small>
                        <input type="number" id="cash-in" onkeyup="calcChange()" class="form-control" placeholder="0">
                    </div>
                    <div class="col-6">
                        <small>Rendu:</small>
                        <div id="cash-change" class="fw-bold text-danger pt-2">0 F</div>
                    </div>
                </div>

                <button class="btn btn-outline-warning w-100 mb-2" onclick="mettreEnAttente()">
                    <i class="fas fa-pause"></i> METTRE EN ATTENTE
                </button>
               <div class="client-selection mb-3" style="background: #f8f9fa; padding: 10px; border-radius: 5px; border: 1px solid #ddd;">
    <label class="small fw-bold text-muted"><i class="fas fa-user-tag"></i> CLIENT :</label>
    <select id="id_client" class="form-select form-select-sm shadow-none border-primary">
        <option value="1" selected>--- CLIENT DIVERS ---</option>
        <?php 
            // On récupère les clients enregistrés (excluant le client divers ID 1)
            $stmtC = $pdo->query("SELECT id_client, nom FROM clients WHERE id_client > 1 ORDER BY nom ASC");
            while($c = $stmtC->fetch()) {
                echo "<option value='{$c['id_client']}'>".htmlspecialchars($c['nom'])."</option>";
            }
        ?>
    </select>
</div>

<button class="btn-pay" onclick="processPayment()">ENCAISSER (F9)</button>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
        let cart = [];
        let remiseGlobal = 0;
        let attentes = JSON.parse(localStorage.getItem('paniers_attente')) || [];

        function showPanel(id) {
            $('.tab-btn').removeClass('active');
            $(`.tab-btn[onclick="showPanel('${id}')"]`).addClass('active');
            $('.v-panel').removeClass('active');
            $('#' + id).addClass('active');
            if(id === 'v-attente') chargerPaniersAttente();
        }
// Modifiez la fonction addToCart pour demander le mode ou définir par défaut
function addToCart(id, name, price, stock, priceDetail, coef) {
    coef = parseInt(coef) || 1; 

    Swal.fire({
        title: 'Sélectionner le mode et le prix',
        html: `
            <div style="margin-bottom: 15px;">
                <p><strong>${name}</strong></p>
                <div class="btn-group" role="group" style="display: flex; gap: 10px; justify-content: center;">
                    <button type="button" id="btn-boite" class="btn" style="background:#27ae60; color:white; padding:10px;">
                        <i class="fas fa-box"></i> BOÎTE (${price} F)
                    </button>
                    <button type="button" id="btn-detail" class="btn" style="background:#3498db; color:white; padding:10px;">
                        <i class="fas fa-pills"></i> DÉTAIL (${priceDetail} F)
                    </button>
                </div>
            </div>
            <div style="text-align: left;">
                <label style="font-size: 12px; font-weight: bold;">PRIX DE VENTE APPLIQUÉ (F) :</label>
                <input type="number" id="custom-price" class="swal2-input" value="${price}" style="margin-top:5px;">
            </div>
        `,
        showCancelButton: true,
        confirmButtonText: 'Ajouter au panier',
        didOpen: () => {
            const inputPrice = document.getElementById('custom-price');
            const btnBoite = document.getElementById('btn-boite');
            const btnDetail = document.getElementById('btn-detail');
            
            let currentMode = 'boite';

            // Action clic Boîte
            btnBoite.addEventListener('click', () => {
                currentMode = 'boite';
                inputPrice.value = price; // Remet le prix par défaut boîte
                btnBoite.style.border = "3px solid black";
                btnDetail.style.border = "none";
            });

            // Action clic Détail
            btnDetail.addEventListener('click', () => {
                currentMode = 'detail';
                inputPrice.value = priceDetail; // Remet le prix par défaut détail
                btnDetail.style.border = "3px solid black";
                btnBoite.style.border = "none";
            });

            // On stocke le mode dans une variable globale temporaire au Swal
            window.tempMode = 'boite'; 
            btnBoite.style.border = "3px solid black"; // Sélection par défaut
            
            btnBoite.onclick = () => { window.tempMode = 'boite'; };
            btnDetail.onclick = () => { window.tempMode = 'detail'; };
        },
        preConfirm: () => {
            return {
                mode: window.tempMode,
                finalPrice: document.getElementById('custom-price').value
            };
        }
    }).then((result) => {
        if (result.isConfirmed) {
            let mode = result.value.mode;
            let prixVente = parseFloat(result.value.finalPrice);
            
            let besoinUnitaire = (mode === 'boite') ? coef : 1;

            if (stock < besoinUnitaire) {
                return Swal.fire('Stock insuffisant', 'Il reste ' + stock + ' unités.', 'error');
            }

            // Pour permettre plusieurs prix différents pour le même produit dans le panier
            // On ajoute le prix dans la recherche de l'index
            let itemIndex = cart.findIndex(i => i.id === id && i.mode === mode && i.price === prixVente);
            
            if (itemIndex > -1) {
                if (stock < (cart[itemIndex].qty + 1) * besoinUnitaire) {
                    return Swal.fire('Stock limite', 'Pas assez de stock.', 'error');
                }
                cart[itemIndex].qty++;
            } else {
                cart.push({ 
                    id: id, 
                    name: name + (mode === 'boite' ? ' (Bt)' : ' (Dt)'), 
                    price: prixVente, 
                    qty: 1, 
                    mode: mode,
                    coef: coef,
                    stock_max: stock 
                });
            }
            updateUI();
        }
    });
}

function updateUI() {
    let html = ''; let subtotal = 0;
    cart.forEach((item, index) => {
        subtotal += item.price * item.qty;
        html += `<div class="cart-item">
            <div class="d-flex justify-content-between">
                <strong>${item.name}</strong>
                <span class="badge bg-light text-dark">${item.mode}</span>
                <i class="fas fa-trash text-danger" onclick="removeItem(${index})" style="cursor:pointer"></i>
            </div>
            <div class="d-flex justify-content-between align-items-center mt-1">
                <input type="number" value="${item.qty}" onchange="updateQty(${index}, this.value)" style="width:50px;">
                <span>${(item.price * item.qty).toLocaleString()} F</span>
            </div>
        </div>`;
    });
    $('#cart-list').html(html || '<p style="text-align:center; color:#999; margin-top:50px;">Panier vide</p>');
            $('#st-val').text(subtotal.toLocaleString() + ' F');
            let total = subtotal - remiseGlobal;
            $('#total-val').text(total.toLocaleString() + ' F');
            $('#cart-count').text(cart.length + ' art.');
            calcChange();
}

        function updateQty(idx, val) {
            if(val > cart[idx].stock) {
                Swal.fire('Erreur', 'Max dispo: ' + cart[idx].stock, 'error');
                cart[idx].qty = cart[idx].stock;
            } else {
                cart[idx].qty = parseInt(val) || 1;
            }
            updateUI();
        }

        function removeItem(idx) { cart.splice(idx, 1); updateUI(); }

        function appliquerRemise() {
            Swal.fire({
                title: 'Montant de la remise (F)',
                input: 'number',
                showCancelButton: true
            }).then((res) => {
                if(res.isConfirmed) {
                    remiseGlobal = parseInt(res.value) || 0;
                    $('#remise-val').text(remiseGlobal.toLocaleString() + ' F');
                    updateUI();
                }
            });
        }

        function calcChange() {
            let total = parseInt($('#total-val').text().replace(/\s/g, '')) || 0;
            let recu = parseInt($('#cash-in').val()) || 0;
            let rendu = recu - total;
            $('#cash-change').text((rendu > 0 ? rendu.toLocaleString() : 0) + ' F');
        }

        // --- GESTION ATTENTE ---
        function mettreEnAttente() {
            if (cart.length === 0) return;
            Swal.fire({ title: 'Note client', input: 'text' }).then((res) => {
                if(res.isConfirmed) {
                    attentes.push({ id: Date.now(), nom: res.value || "Client", contenu: cart, date: new Date().toLocaleTimeString() });
                    localStorage.setItem('paniers_attente', JSON.stringify(attentes));
                    cart = []; updateUI(); updateAttenteCount();
                }
            });
        }

        function updateAttenteCount() { $('#count-attente').text(attentes.length); }

        function chargerPaniersAttente() {
            let html = '';
            attentes.forEach((p, i) => {
                html += `<div class="col-md-6"><div class="card p-3">
                    <h6>${p.nom} <small class="text-muted">(${p.date})</small></h6>
                    <button class="btn btn-sm btn-success w-100" onclick="reprendrePanier(${i})">Reprendre</button>
                </div></div>`;
            });
            $('#list-attente').html(html || 'Aucun panier en attente');
        }

        function reprendrePanier(i) {
            if(cart.length > 0) return Swal.fire('Erreur', 'Videz le panier actuel', 'warning');
            cart = attentes[i].contenu;
            attentes.splice(i, 1);
            localStorage.setItem('paniers_attente', JSON.stringify(attentes));
            updateUI(); updateAttenteCount(); showPanel('v-comptoir');
        }
 
function processPayment() {
    if (cart.length === 0) { 
        Swal.fire('Attention', 'Le panier est vide', 'warning');
        return; 
    }
    
    let total = parseInt($('#total-val').text().replace(/\s/g, ''));
    let idClient = $('#id_client').val(); // Récupère l'ID choisi (1 par défaut)

    $.post('ajax_produits.php', { 
        action: 'save_vente', 
        cart: JSON.stringify(cart),
        remise: remiseGlobal,
        total: total,
        id_client: idClient, // Envoi de l'ID client au PHP
        mode_paiement: $('#mode_paiement').val() || 'Espèces' 
    }, function(res) {
        if(res.status === 'success') {
            Swal.fire({
                title: 'Vente Enregistrée !',
                text: 'Facture #' + res.id_vente,
                icon: 'success',
                showCancelButton: true,
                confirmButtonText: 'Imprimer Ticket',
                cancelButtonText: 'Nouvelle Vente'
            }).then((result) => {
                imprimerTicket(res.id_vente);
                setTimeout(() => { location.reload(); }, 500);
            });
        } else {
            Swal.fire('Erreur', res.message, 'error');
        }
    }, 'json');
}

// Fonction pour ouvrir la fenêtre d'impression
function imprimerTicket(idVente) {
    const width = 400;
    const height = 600;
    const left = (screen.width / 2) - (width / 2);
    const top = (screen.height / 2) - (height / 2);
    
    window.open('imprimer_ticket.php?id=' + idVente, 'Ticket', 
                `width=${width},height=${height},top=${top},left=${left},toolbar=no,location=no,status=no,menubar=no`);
}
        

        // Recherche Molecule/Nom
        $("#search-prod").on("keyup", function() {
            let v = $(this).val().toLowerCase();
            $(".product-card").each(function() {
                $(this).toggle($(this).text().toLowerCase().indexOf(v) > -1);
            });
        });


// Fonction pour rafraîchir ce panel automatiquement
function refreshHisto() {
    $.get('ajax_historique.php', function(data) {
        $('#body-histo-jour').html(data);
    });
}

function cloturerSession() {
    Swal.fire({
        title: 'Clôture de caisse',
        text: "Voulez-vous générer le rapport financier et vider le tiroir-caisse ?",
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        cancelButtonColor: '#3085d6',
        confirmButtonText: 'Oui, Clôturer',
        cancelButtonText: 'Annuler'
    }).then((result) => {
        if (result.isConfirmed) {
            // Affichage d'un loader pendant le calcul
            Swal.showLoading();

            $.post('ajax_cloture.php', { action: 'cloturer_journee' }, function(res) {
                if(res.status === 'success') {
                    Swal.fire({
                        title: 'Session Clôturée !',
                        html: `
                            <div style="text-align:left; background:#f8f9fa; padding:15px; border-radius:8px; font-family:monospace;">
                                <p>💵 Espèces : <b>${res.data.especes} F</b></p>
                                <p>📱 Mobile : <b>${res.data.mobile} F</b></p>
                                <p>🏥 Assurance : <b>${res.data.assurance} F</b></p>
                                <hr>
                                <p style="font-size:18px; color:#28a745;">TOTAL : <b>${res.data.total} F</b></p>
                            </div>
                        `,
                        icon: 'success',
                        showCancelButton: true,
                        confirmButtonText: '<i class="fas fa-print"></i> Imprimer Rapport',
                        cancelButtonText: 'Fermer'
                    }).then((printRes) => {
                        if (printRes.isConfirmed) {
                            // Ouvre le ticket de clôture (Rapport Z)
                            window.open('imprimer_cloture.php', '_blank', 'width=400,height=600');
                        }
                        // Recharge la page pour vider l'interface et le panier
                        location.reload();
                    });
                } else {
                    Swal.fire('Erreur', res.message, 'error');
                }
            }, 'json');
        }
    });
}

// Optionnel : Rafraîchir toutes les 30 secondes pour voir les nouvelles ventes des collègues
setInterval(refreshHisto, 30000);

        updateAttenteCount();
    </script>
</body>
</html>