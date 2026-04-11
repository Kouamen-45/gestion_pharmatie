<?php
// Configuration des paramètres de la base de données
$host    = 'localhost';
$db_name = 'pharmassist';
$user    = 'root';
$pass    = ''; // Par défaut vide sur XAMPP/WAMP
$charset = 'utf8mb4';

// Création du DSN (Data Source Name)
$dsn = "mysql:host=$host;dbname=$db_name;charset=$charset";

// Options PDO pour la sécurité et la gestion des erreurs
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION, // Lance une erreur si la requête échoue
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,       // Retourne les données sous forme de tableau associatif
    PDO::ATTR_EMULATE_PREPARES   => false,                  // Désactive l'émulation pour plus de sécurité contre les injections SQL
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);

    // 1. Définition des 3 emplacements fixes
    $emplacements = [
        'Rayon A (Comprimés)',
        'Rayon B (Sirops)',
        'Frigo (Vaccins/Insuline)'
    ];

    // 2. Récupération de tous les produits
    $stmt = $pdo->query("SELECT id_produit FROM produits");
    $produits = $stmt->fetchAll(PDO::FETCH_COLUMN);

    if (empty($produits)) {
        die("Aucun produit trouvé dans votre base de données.");
    }

    // 3. Mise à jour massive
    $updateStmt = $pdo->prepare("UPDATE produits SET emplacement = ? WHERE id_produit = ?");

    foreach ($produits as $id) {
        // Sélection aléatoire parmi les 3
        $lieu = $emplacements[array_rand($emplacements)];
        $updateStmt->execute([$lieu, $id]);
    }

    echo "✅ Terminé ! Les produits ont été répartis dans les 3 emplacements suivants :<br>";
    echo "<ul><li>" . implode("</li><li>", $emplacements) . "</li></ul>";

} catch (PDOException $e) {
    die("Erreur : " . $e->getMessage());
}
?>