<?php
session_start();
require_once 'db.php';
if (!isset($_SESSION['user_id'])) { header('Location: login.php'); exit(); }

$id_produit = $_GET['id'] ?? null;
$date_debut = $_GET['debut'] ?? date('Y-m-01');
$date_fin = $_GET['fin'] ?? date('Y-m-d');

$mouvements = [];
$produit_info = null;
$stock_initial = 0;

if ($id_produit) {
    // 1. Infos du produit
    $stmtP = $pdo->prepare("SELECT * FROM produits WHERE id_produit = ?");
    $stmtP->execute([$id_produit]);
    $produit_info = $stmtP->fetch();

    // 2. Calcul du stock AVANT la date de début (Somme entrées - Somme sorties)
    // Entrées passées
    $stmtE = $pdo->prepare("SELECT SUM(da.quantite_recue) FROM detail_achats da JOIN achats a ON da.id_achat = a.id_achat WHERE da.id_produit = ? AND DATE(a.date_achat) < ?");
    $stmtE->execute([$id_produit, $date_debut]);
    $entrees_avant = $stmtE->fetchColumn() ?: 0;

    // Sorties passées
    $stmtS = $pdo->prepare("SELECT SUM(dv.quantite) FROM detail_ventes dv JOIN ventes v ON dv.id_vente = v.id_vente WHERE dv.id_produit = ? AND DATE(v.date_vente) < ?");
    $stmtS->execute([$id_produit, $date_debut]);
    $sorties_avant = $stmtS->fetchColumn() ?: 0;

    $stock_initial = $entrees_avant - $sorties_avant;

    // 3. Récupérer les mouvements de la période
    $stmtAchats = $pdo->prepare("
        SELECT 'ACHAT' as type, a.date_achat as date_mouv, da.quantite_recue as qte, a.fournisseur as ref
        FROM detail_achats da JOIN achats a ON da.id_achat = a.id_achat
        WHERE da.id_produit = ? AND DATE(a.date_achat) BETWEEN ? AND ?
    ");
    $stmtAchats->execute([$id_produit, $date_debut, $date_fin]);
    $mouv_achats = $stmtAchats->fetchAll(PDO::FETCH_ASSOC);

    $stmtVentes = $pdo->prepare("
        SELECT 'VENTE' as type, v.date_vente as date_mouv, dv.quantite as qte, CONCAT('Vente #', v.id_vente) as ref
        FROM detail_ventes dv JOIN ventes v ON dv.id_vente = v.id_vente
        WHERE dv.id_produit = ? AND DATE(v.date_vente) BETWEEN ? AND ?
    ");
    $stmtVentes->execute([$id_produit, $date_debut, $date_fin]);
    $mouv_ventes = $stmtVentes->fetchAll(PDO::FETCH_ASSOC);

    $mouvements = array_merge($mouv_achats, $mouv_ventes);
    
    // Trier par date ASCENDANTE pour calculer le reste au fur et à mesure
    usort($mouvements, function($a, $b) {
        return strtotime($a['date_mouv']) - strtotime($b['date_mouv']);
    });
}

// Récupérer les AJUSTEMENTS
$stmtAjust = $pdo->prepare("
    SELECT IF(type_ajustement='ajout', 'ENTREE (AJUST)', 'SORTIE (AJUST)') as type, 
    date_ajustement as date_mouv, quantite_ajustee as qte, motif as reference
    FROM ajustements
    WHERE id_produit = ? AND DATE(date_ajustement) BETWEEN ? AND ?
");
$stmtAjust->execute([$id_produit, $date_debut, $date_fin]);
$mouvements = array_merge($mouvements, $stmtAjust->fetchAll(PDO::FETCH_ASSOC));
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Fiche de Stock - <?= htmlspecialchars($produit_info['nom_commercial']) ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        :root { --main-blue: #3498db; --bg: #f4f7f6; }
        body { margin: 0; font-family: 'Segoe UI', sans-serif; background: var(--bg); display: flex; }
        .sidebar { width: 220px; background: white; height: 100vh; position: fixed; border-right: 1px solid #ddd; }
        .main-content { margin-left: 220px; flex: 1; padding: 30px; }
        .card { background: white; padding: 20px; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        th, td { padding: 12px; border-bottom: 1px solid #eee; text-align: left; }
        .stock-highlight { background: #34495e; color: white; padding: 15px; border-radius: 8px; display: inline-block; margin-bottom: 20px; }
        .badge-in { color: #2ecc71; font-weight: bold; }
        .badge-out { color: #e74c3c; font-weight: bold; }
    </style>
</head>
<body>

<div class="sidebar">
    <div style="padding: 20px; font-weight: bold; color: var(--main-blue);">PHARMASSIST</div>
    <a href="stocks_inventaire.php" class="sidebar-item"><i class="fas fa-arrow-left"></i> Retour Inventaire</a>
</div>

<div class="main-content">
    <h2>Fiche de Stock : <?= htmlspecialchars($produit_info['nom_commercial']) ?></h2>

    <div class="stock-highlight">
        <i class="fas fa-archive"></i> Stock au <?= date('d/m/Y', strtotime($date_debut)) ?> : <b><?= $stock_initial ?> unités</b>
    </div>

    <form class="card" method="GET" style="display: flex; gap: 15px; align-items: flex-end; margin-bottom: 20px;">
        <input type="hidden" name="id" value="<?= $id_produit ?>">
        <div><label>Début</label><br><input type="date" name="debut" value="<?= $date_debut ?>"></div>
        <div><label>Fin</label><br><input type="date" name="fin" value="<?= $date_fin ?>"></div>
        <button type="submit" style="background:var(--main-blue); color:white; border:none; padding:10px 20px; border-radius:4px; cursor:pointer;">Filtrer</button>
        <button type="button" onclick="window.print()" style="background:#95a5a6; color:white; border:none; padding:10px 20px; border-radius:4px; cursor:pointer;"><i class="fas fa-print"></i> Imprimer</button>
    </form>

    <div class="card">
        <table>
            <thead>
                <tr style="background: #f8f9fa;">
                    <th>Date & Heure</th>
                    <th>Type de Mouvement</th>
                    <th>Référence / Tiers</th>
                    <th>Entrée (+)</th>
                    <th>Sortie (-)</th>
                    <th style="background: #edf2f7;">Reste en Stock</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td colspan="5"><i>Report de stock (Avant le <?= date('d/m/Y', strtotime($date_debut)) ?>)</i></td>
                    <td style="background: #edf2f7;"><b><?= $stock_initial ?></b></td>
                </tr>
                <?php 
                $cumul = $stock_initial;
                foreach($mouvements as $m): 
                    if($m['type'] == 'ACHAT') {
                        $cumul += $m['qte'];
                        $entree = $m['qte'];
                        $sortie = '-';
                    } else {
                        $cumul -= $m['qte'];
                        $entree = '-';
                        $sortie = $m['qte'];
                    }
                ?>
                <tr>
                    <td><?= date('d/m/Y H:i', strtotime($m['date_mouv'])) ?></td>
                    <td><?= $m['type'] ?></td>
                    <td><?= htmlspecialchars($m['ref']) ?></td>
                    <td class="badge-in"><?= $entree ?></td>
                    <td class="badge-out"><?= $sortie ?></td>
                    <td style="background: #edf2f7;"><b><?= $cumul ?></b></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

</body>
</html>