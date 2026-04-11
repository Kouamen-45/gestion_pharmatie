<?php
require('fpdf/fpdf.php');
include('db.php');

$id = intval($_GET['id_produit']);
$nom = $_GET['nom'];

$stmt = $pdo->prepare("SELECT m.*, u.nom_complet as utilisateur 
                       FROM mouvements_stock m 
                       LEFT JOIN utilisateurs u ON m.id_utilisateur = u.id_user 
                       WHERE m.id_produit = ? ORDER BY date_mouvement DESC");
$stmt->execute([$id]);
$flux = $stmt->fetchAll();

$pdf = new FPDF();
$pdf->AddPage();
$pdf->SetFont('Arial', 'B', 14);
$pdf->Cell(0, 10, utf8_decode("HISTORIQUE DES FLUX : $nom"), 0, 1, 'C');
$pdf->Ln(5);

$pdf->SetFont('Arial', 'B', 10);
$pdf->SetFillColor(240, 240, 240);
$pdf->Cell(40, 10, 'Date', 1, 0, 'C', true);
$pdf->Cell(40, 10, 'Type', 1, 0, 'C', true);
$pdf->Cell(30, 10, 'Quantite', 1, 0, 'C', true);
$pdf->Cell(80, 10, 'Motif / Utilisateur', 1, 1, 'C', true);

$pdf->SetFont('Arial', '', 9);
foreach($flux as $f) {
    $date = date('d/m/Y H:i', strtotime($f['date_mouvement']));
    $type = str_replace('_', ' ', $f['type_mouvement']);
    $qte = ($f['quantite'] > 0 ? '+' : '') . $f['quantite'];
    
    $pdf->Cell(40, 8, $date, 1);
    $pdf->Cell(40, 8, utf8_decode($type), 1);
    $pdf->Cell(30, 8, $qte, 1, 0, 'C');
    $pdf->Cell(80, 8, utf8_decode($f['motif'] . " (" . $f['utilisateur'] . ")"), 1, 1);
}

$pdf->Output('I', 'Flux_'.str_replace(' ', '_', $nom).'.pdf');