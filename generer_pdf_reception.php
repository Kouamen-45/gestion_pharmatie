<?php
require('fpdf/fpdf.php');
include('db.php');

$id_commande = intval($_GET['id_commande']);

// 1. Infos commande et fournisseur
$sql = "SELECT c.*, f.nom_fournisseur, f.telephone FROM commandes c 
        JOIN fournisseurs f ON c.id_fournisseur = f.id_fournisseur 
        WHERE c.id_commande = ?";
$stmt = $pdo->prepare($sql);
$stmt->execute([$id_commande]);
$cmd = $stmt->fetch();

// 2. Lignes avec prix d'achat
// On récupère le prix d'achat unitaire (PA) pour calculer les totaux
$sql_lignes = "SELECT cl.*, p.nom_commercial, p.prix_unitaire, s.numero_lot, s.date_peremption 
               FROM commande_lignes cl 
               JOIN commandes c ON cl.id_commande = c.id_commande
               JOIN produits p ON cl.id_produit = p.id_produit 
               JOIN stocks s ON s.id_produit = p.id_produit AND s.date_reception >= DATE(c.date_commande)
               WHERE cl.id_commande = ?";
$stmt_l = $pdo->prepare($sql_lignes);
$stmt_l->execute([$id_commande]);
$lignes = $stmt_l->fetchAll();

// --- CONFIGURATION PDF ---
$pdf = new FPDF();
$pdf->AddPage();
$pdf->SetAutoPageBreak(true, 15);

// --- EN-TÊTE ---
$pdf->SetFont('Arial', 'B', 20);
$pdf->SetTextColor(39, 174, 96); // Vert PharmAssist
$pdf->Cell(100, 10, 'PHARMASSIST', 0, 0);
$pdf->SetTextColor(0,0,0);
$pdf->SetFont('Arial', 'B', 12);
$pdf->Cell(90, 10, utf8_decode('BON DE RÉCEPTION #') . $id_commande, 0, 1, 'R');

$pdf->SetFont('Arial', '', 10);
$pdf->Cell(100, 5, utf8_decode('Yaoundé, Cameroun'), 0, 0);
$pdf->Cell(90, 5, 'Date: ' . date('d/m/Y H:i'), 0, 1, 'R');
$pdf->Ln(10);

// --- INFOS FOURNISSEUR ---
$pdf->SetFillColor(240, 240, 240);
$pdf->SetFont('Arial', 'B', 11);
$pdf->Cell(0, 8, utf8_decode(' DÉTAILS DU FOURNISSEUR'), 0, 1, 'L', true);
$pdf->SetFont('Arial', '', 11);
$pdf->Cell(0, 7, 'Nom : ' . strtoupper($cmd['nom_fournisseur']), 0, 1);
$pdf->Cell(0, 7, utf8_decode('Contact : ') . ($cmd['telephone'] ?? 'N/A'), 0, 1);
$pdf->Ln(5);

// --- TABLEAU DES PRODUITS ---
$pdf->SetFillColor(39, 174, 96);
$pdf->SetTextColor(255, 255, 255);
$pdf->SetFont('Arial', 'B', 9);

// Largeurs des colonnes (Total 190)
$w = [60, 20, 25, 30, 25, 30]; 

$pdf->Cell($w[0], 10, 'PRODUIT', 1, 0, 'C', true);
$pdf->Cell($w[1], 10, 'QTE REC.', 1, 0, 'C', true);
$pdf->Cell($w[2], 10, 'P.A. UNIT', 1, 0, 'C', true);
$pdf->Cell($w[3], 10, 'LOT / PEREMP.', 1, 0, 'C', true);
$pdf->Cell($w[4], 10, 'MARGE', 1, 0, 'C', true); // Bonus : Espace vide pour notes
$pdf->Cell($w[5], 10, 'TOTAL LIGNE', 1, 1, 'C', true);

$pdf->SetTextColor(0, 0, 0);
$pdf->SetFont('Arial', '', 9);

$grandTotal = 0;

foreach ($lignes as $l) {
    $qte = $l['quantite_recue'];
    $pa = $l['prix_unitaire'];
    $sousTotal = $qte * $pa;
    $grandTotal += $sousTotal;

    // On mémorise la position pour gérer le multi-ligne sur le nom du produit
    $x = $pdf->GetX();
    $y = $pdf->GetY();

    $pdf->Cell($w[0], 8, utf8_decode($l['nom_commercial']), 1);
    $pdf->Cell($w[1], 8, $qte, 1, 0, 'C');
    $pdf->Cell($w[2], 8, number_format($pa, 0, '.', ' ') . ' F', 1, 0, 'R');
    
    // Lot et Péremption (format court)
    $lotInfo = $l['numero_lot'] . ' (' . date('m/y', strtotime($l['date_peremption'])) . ')';
    $pdf->Cell($w[3], 8, $lotInfo, 1, 0, 'C');
    
    $pdf->Cell($w[4], 8, '', 1, 0); // Case vide pour pointage manuel
    $pdf->Cell($w[5], 8, number_format($sousTotal, 0, '.', ' ') . ' F', 1, 1, 'R');
}

// --- TOTAL GÉNÉRAL ---
$pdf->Ln(5);
$pdf->SetFont('Arial', 'B', 12);
$pdf->Cell($w[0]+$w[1]+$w[2]+$w[3]+$w[4], 10, 'MONTANT TOTAL FACTURE : ', 0, 0, 'R');
$pdf->SetTextColor(39, 174, 96);
$pdf->Cell($w[5], 10, number_format($grandTotal, 0, '.', ' ') . ' FCFA', 1, 1, 'R');

// --- PIED DE PAGE (Signatures) ---
$pdf->Ln(20);
$pdf->SetTextColor(0,0,0);
$pdf->SetFont('Arial', 'U', 10);
$pdf->Cell(95, 10, 'Le Livreur (Fournisseur)', 0, 0, 'C');
$pdf->Cell(95, 10, utf8_decode('Le Réceptionnaire (Pharmacie)'), 0, 1, 'C');

$pdf->Output('I', 'reception_'.$id_commande.'.pdf');