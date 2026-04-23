<?php
require_once 'db.php'; // Assurez-vous d'inclure votre connexion à la base de données

$type = $_GET['type'] ?? '';

switch ($type) {
    case 'jours':
    $dateCible = $_GET['date'] ?? date('Y-m-d'); // Date du jour par défaut
    
    // Requête optimisée (Jointures au lieu de sous-requêtes)
    $query = "SELECT 
                DATE(v.date_vente) AS date, 
                SUM(v.total) AS ca,
                SUM(dv.quantite * (dv.prix_unitaire - COALESCE(pa.prix_achat_unitaire, 0))) AS marge,
                COUNT(DISTINCT v.id_vente) as nb_ventes
              FROM ventes v
              JOIN detail_ventes dv ON v.id_vente = dv.id_vente
              LEFT JOIN detail_achats pa ON dv.id_produit = pa.id_produit
              WHERE DATE(v.date_vente) = :dateCible
              GROUP BY DATE(v.date_vente)";
              
    $stmt = $pdo->prepare($query);
    $stmt->execute(['dateCible' => $dateCible]);
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    break;

    case 'semaines':
        // Calcul des marges par semaine
        $query = "SELECT WEEK(date_vente) AS semaine, SUM(total) AS ca, 
                  SUM(total - (SELECT SUM(pa.prix_achat_unitaire * dv.quantite) 
                                FROM detail_ventes dv 
                                JOIN detail_achats pa ON dv.id_produit = pa.id_produit 
                                WHERE id_vente = ventes.id_vente)) AS marge 
                  FROM ventes 
                  GROUP BY WEEK(date_vente)";
                  $result = $pdo->query($query);
				$data = $result->fetchAll(PDO::FETCH_ASSOC);
        break;

    case 'mois':
        // Calcul des marges par mois
        $query = "SELECT MONTH(date_vente) AS mois, SUM(total) AS ca, 
                  SUM(total - (SELECT SUM(pa.prix_achat_unitaire * dv.quantite) 
                                FROM detail_ventes dv 
                                JOIN detail_achats pa ON dv.id_produit = pa.id_produit 
                                WHERE id_vente = ventes.id_vente)) AS marge 
                  FROM ventes 
                  GROUP BY MONTH(date_vente)";
                  $result = $pdo->query($query);
				  $data = $result->fetchAll(PDO::FETCH_ASSOC);
        break;

    case 'produits':
        // Calcul des marges par produit
        $query = "SELECT p.nom_commercial, SUM(dv.quantite) AS quantite, 
                  SUM(dv.prix_unitaire * dv.quantite) AS ca, 
                  SUM((dv.prix_unitaire - pa.prix_achat_unitaire) * dv.quantite) AS marge 
                  FROM detail_ventes dv 
                  JOIN produits p ON dv.id_produit = p.id_produit 
                  JOIN detail_achats pa ON dv.id_produit = pa.id_produit 
                  GROUP BY p.nom_commercial";
                  $result = $pdo->query($query);
				  $data = $result->fetchAll(PDO::FETCH_ASSOC);
        break;

    case 'familles':
        // Calcul des marges par famille
        $query = "SELECT f.nom_famille, SUM(dv.quantite) AS quantite, 
                  SUM(dv.prix_unitaire * dv.quantite) AS ca, 
                  SUM((dv.prix_unitaire - pa.prix_achat_unitaire) * dv.quantite) AS marge 
                  FROM detail_ventes dv 
                  JOIN produits p ON dv.id_produit = p.id_produit 
                  JOIN familles f ON p.id_famille = f.id_famille 
                  JOIN detail_achats pa ON dv.id_produit = pa.id_produit 
                  GROUP BY f.nom_famille";
                  $result = $pdo->query($query);
				  $data = $result->fetchAll(PDO::FETCH_ASSOC);
        break;

    case 'total':
        // Calcul des marges totales
        $query = "SELECT SUM(total) AS ca, 
                  SUM(total - (SELECT SUM(pa.prix_achat_unitaire * dv.quantite) 
                                FROM detail_ventes dv 
                                JOIN detail_achats pa ON dv.id_produit = pa.id_produit 
                                WHERE id_vente = ventes.id_vente)) AS marge 
                  FROM ventes";
                  $result = $pdo->query($query);
				   $data = $result->fetchAll(PDO::FETCH_ASSOC);
        break;

    case 'evolution':
        // Évolution des marges avec le temps
        $query = "SELECT DATE(date_vente) AS date, SUM(total) AS ca, 
                  SUM(total - (SELECT SUM(pa.prix_achat_unitaire * dv.quantite) 
                                FROM detail_ventes dv 
                                JOIN detail_achats pa ON dv.id_produit = pa.id_produit 
                                WHERE id_vente = ventes.id_vente)) AS marge 
                  FROM ventes 
                  GROUP BY DATE(date_vente)";
                  $result = $pdo->query($query);
				  $data = $result->fetchAll(PDO::FETCH_ASSOC);
        break;

    default:
        echo 'Type de marge non reconnu.';
        exit;
}

// Exécuter la requête
/**/

// Retourner les données sous forme de tableau
echo json_encode($data);
?>