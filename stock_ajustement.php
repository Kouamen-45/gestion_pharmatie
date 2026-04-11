<?php
session_start();
require_once 'db.php';
if (!isset($_SESSION['user_id'])) { header('Location: login.php'); exit(); }

// On récupère les infos du lot si l'ID est passé en paramètre
$id_stock = $_GET['id_stock'] ?? null;
$info = null;

if ($id_stock) {
    $stmt = $pdo->prepare("SELECT s.*, p.nom_commercial FROM stocks s JOIN produits p ON s.id_produit = p.id_produit WHERE s.id_stock = ?");
    $stmt->execute([$id_stock]);
    $info = $stmt->fetch();
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Régularisation de Stock - PharmAssist</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        :root { --main-blue: #3498db; --bg: #f4f7f6; }
        body { margin: 0; font-family: 'Segoe UI', sans-serif; background: var(--bg); display: flex; }
        .main-content { margin-left: 220px; flex: 1; padding: 30px; }
        .card { background: white; padding: 25px; border-radius: 10px; max-width: 500px; margin: auto; box-shadow: 0 4px 15px rgba(0,0,0,0.1); }
        .form-group { margin-bottom: 15px; }
        input, select, textarea { width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 5px; box-sizing: border-box; }
        .btn-save { background: #2ecc71; color: white; border: none; padding: 12px; width: 100%; border-radius: 5px; cursor: pointer; font-weight: bold; }
    </style>
</head>
<body>
<div class="main-content">
    <div class="card">
        <h3><i class="fas fa-tools"></i> Régulariser le Stock</h3>
        <p>Produit : <b><?= htmlspecialchars($info['nom_commercial']) ?></b><br>
        Lot : <code><?= htmlspecialchars($info['numero_lot']) ?></code> | Stock actuel : <b><?= $info['quantite_disponible'] ?></b></p>
        
        <form id="formAjustement">
            <input type="hidden" name="id_stock" value="<?= $id_stock ?>">
            <input type="hidden" name="id_produit" value="<?= $info['id_produit'] ?>">
            
            <div class="form-group">
                <label>Action :</label>
                <select name="type_ajustement" id="type_aj">
                    <option value="retrait">Retirer du stock (-)</option>
                    <option value="ajout">Ajouter au stock (+)</option>
                </select>
            </div>
            
            <div class="form-group">
                <label>Quantité à ajuster :</label>
                <input type="number" name="quantite" required min="1">
            </div>
            
            <div class="form-group">
                <label>Motif de la régularisation :</label>
                <select name="motif">
                    <option value="Casse / Dommage">Casse / Dommage</option>
                    <option value="Péremption">Péremption</option>
                    <option value="Erreur d'inventaire">Erreur d'inventaire</option>
                    <option value="Don / Échantillon">Don / Échantillon</option>
                </select>
            </div>

            <button type="button" class="btn-save" onclick="validerAjustement()">APPLIQUER LA CORRECTION</button>
        </form>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
function validerAjustement() {
    $.post('ajax_ajustements.php', $('#formAjustement').serialize(), function(res) {
        if(res.status === 'success') {
            Swal.fire('Mis à jour', 'Le stock a été régularisé avec succès.', 'success')
                .then(() => window.location.href = 'stocks_inventaire.php');
        } else {
            Swal.fire('Erreur', res.message, 'error');
        }
    }, 'json');
}
</script>
</body>
</html>