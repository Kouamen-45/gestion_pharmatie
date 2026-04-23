<?php
require_once 'db.php';
header('Content-Type: application/json');

$id      = intval($_GET['id']      ?? 0);
$mol     = trim($_GET['molecule']  ?? '');
$desc    = trim($_GET['description'] ?? '');

if (!$id) { echo json_encode(['status'=>'error','message'=>'ID manquant']); exit; }

// Mots-cles extraits de la molecule (retire les doses ex: 500mg)
$keywords = array_filter(
    preg_split('/[\s\/\+\-]+/', preg_replace('/\d+\s*mg|\d+\s*ml|\d+\s*g/i', '', $mol)),
    fn($w) => strlen($w) >= 3
);

if (empty($keywords) && empty($desc)) {
    echo json_encode(['status'=>'success','equivalents'=>[]]);
    exit;
}

// Construction de la clause LIKE sur molecule + description
$clauses = [];
$params  = [];

foreach ($keywords as $kw) {
    $clauses[] = "(p.molecule LIKE ? OR p.description LIKE ?)";
    $params[]  = "%$kw%";
    $params[]  = "%$kw%";
}

// Mots cles de la description aussi (max 2 premiers mots significatifs)
$descWords = array_filter(
    preg_split('/\s+/', $desc),
    fn($w) => strlen($w) >= 4
);
foreach (array_slice($descWords, 0, 2) as $dw) {
    $clauses[] = "(p.molecule LIKE ? OR p.description LIKE ?)";
    $params[]  = "%$dw%";
    $params[]  = "%$dw%";
}

$whereOr = implode(' OR ', $clauses);

$sql = "
    SELECT p.*,
           IFNULL((
               SELECT SUM(s.quantite_disponible)
               FROM stocks s
               WHERE s.id_produit = p.id_produit
                 AND s.date_peremption > CURDATE()
           ), 0) AS stock_dispo
    FROM produits p
    WHERE p.id_produit != ?
      AND ($whereOr)
    HAVING stock_dispo > 0
    ORDER BY stock_dispo DESC
    LIMIT 12
";

array_unshift($params, $id);

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo json_encode(['status' => 'success', 'equivalents' => $rows]);