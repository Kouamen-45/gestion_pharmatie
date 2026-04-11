<?php
require('fpdf/fpdf.php');
include('db.php');

$id_commande = isset($_GET['id_commande']) ? intval($_GET['id_commande']) : 0;

if ($id_commande == 0) {
    die("ID de commande invalide.");
}

// 1. Récupération des données
$sql_cmd = "SELECT c.*, f.nom_fournisseur 
            FROM commandes c 
            JOIN fournisseurs f ON c.id_fournisseur = f.id_fournisseur 
            WHERE c.id_commande = ?";

$stmt_cmd = $pdo->prepare($sql_cmd);
$stmt_cmd->execute([$id_commande]);
$cmd = $stmt_cmd->fetch();

if (!$cmd) die("Commande introuvable.");

$sql_lignes = "SELECT cl.*, p.nom_commercial 
               FROM commande_lignes cl 
               JOIN produits p ON cl.id_produit = p.id_produit 
               WHERE cl.id_commande = ?";

$stmt_lignes = $pdo->prepare($sql_lignes);
$stmt_lignes->execute([$id_commande]);
$lignes = $stmt_lignes->fetchAll();

// --- Génération du PDF ---
$pdf = new FPDF();
$pdf->AddPage();

// --- LOGO ET EN-TÊTE ---
// Paramètres : 'chemin/fichier', x, y, largeur (la hauteur est calculée auto)
if (file_exists('logo.png')) {
    $pdf->Image('logo.png', 10, 10, 30); 
    $pdf->SetXY(45, 12); // On décale le texte à côté du logo
} else {
    $pdf->SetXY(10, 12);
}

$pdf->SetFont('Arial', 'B', 14);
$pdf->SetTextColor(44, 62, 80);
$pdf->Cell(100, 7, utf8_decode('PHARMACIE DE LA PAIX'), 0, 1);
$pdf->SetX($pdf->GetX() > 10 ? 45 : 10); // Ajustement selon présence logo
$pdf->SetFont('Arial', '', 9);
$pdf->Cell(100, 4, utf8_decode('Quartier Central - BP 1234'), 0, 1);
$pdf->SetX($pdf->GetX() > 10 ? 45 : 10);
$pdf->Cell(100, 4, utf8_decode('Tél : +237 6XX XX XX XX'), 0, 1);

// Ligne de séparation
$pdf->SetDrawColor(9, 132, 227);
$pdf->SetLineWidth(0.8);
$pdf->Line(10, 42, 200, 42);
$pdf->Ln(25);

// --- TITRE DU DOCUMENT ---
$pdf->SetFont('Arial', 'B', 18);
$pdf->SetTextColor(9, 132, 227); 
$pdf->Cell(0, 10, utf8_decode('BON DE COMMANDE #' . $id_commande), 0, 1, 'C');
$pdf->Ln(5);

// --- BLOC INFOS (Client vs Fournisseur) ---
$pdf->SetTextColor(0, 0, 0);
$current_y = $pdf->GetY();

// À gauche : Détails commande
$pdf->SetFont('Arial', 'B', 10);
$pdf->Cell(40, 6, utf8_decode('Date : '), 0, 0);
$pdf->SetFont('Arial', '', 10);
$pdf->Cell(50, 6, date('d/m/Y H:i', strtotime($cmd['date_commande'])), 0, 1);
$pdf->SetFont('Arial', 'B', 10);
$pdf->Cell(40, 6, utf8_decode('Statut : '), 0, 0);
$pdf->SetFont('Arial', '', 10);
$pdf->Cell(50, 6, strtoupper($cmd['statut']), 0, 1);

// À droite : Infos Fournisseur
$pdf->SetXY(120, $current_y);
$pdf->SetFont('Arial', 'B', 11);
$pdf->SetFillColor(245, 245, 245);
$pdf->Cell(80, 7, utf8_decode('DESTINATAIRE :'), 0, 1, 'L', true);
$pdf->SetX(120);
$pdf->SetFont('Arial', 'B', 12);
$pdf->MultiCell(80, 7, utf8_decode(strtoupper($cmd['nom_fournisseur'])), 0, 'L');

$pdf->Ln(15);

// --- TABLEAU ---
$pdf->SetFillColor(9, 132, 227); 
$pdf->SetTextColor(255, 255, 255); 
$pdf->SetFont('Arial', 'B', 11);
$pdf->Cell(140, 10, utf8_decode('Désignation Produit'), 1, 0, 'C', true);
$pdf->Cell(50, 10, 'Quantité', 1, 1, 'C', true);

$pdf->SetTextColor(0, 0, 0);
$pdf->SetFont('Arial', '', 11);

foreach ($lignes as $l) {
    $pdf->Cell(140, 10, utf8_decode($l['nom_commercial']), 1);
    $pdf->Cell(50, 10, $l['quantite_commandee'], 1, 1, 'C');
}

// --- TOTAL & SIGNATURE ---
if($cmd['total_prevu'] > 0) {
    $pdf->Ln(5);
    $pdf->SetFont('Arial', 'B', 12);
    $pdf->Cell(140, 10, 'TOTAL ESTIMÉ ', 0, 0, 'R');
    $pdf->Cell(50, 10, number_format($cmd['total_prevu'], 0, '.', ' ') . ' FCFA', 1, 1, 'C');
}

$pdf->SetY(-40);
$pdf->SetFont('Arial', 'B', 10);
$pdf->Cell(0, 10, utf8_decode('Le Pharmacien Responsable'), 0, 1, 'R');
$pdf->SetFont('Arial', 'I', 8);
$pdf->Cell(0, 5, utf8_decode('(Signature et cachet faisant foi)'), 0, 0, 'R');

$pdf->Output();