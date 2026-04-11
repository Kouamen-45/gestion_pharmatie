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
    // Tentative de connexion
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (PDOException $e) {
    // Si la connexion échoue, on affiche l'erreur et on arrête tout
    die("Erreur de connexion à PharmAssist : " . $e->getMessage());
}
?>