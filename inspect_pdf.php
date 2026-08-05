<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/holder_letter_pdf.php';

$pdo = get_db_connection();
if (!$pdo) {
    die("Database connection error.");
}

$recordId = 631;
$stmt = $pdo->prepare("SELECT * FROM `unclaimed_assets` WHERE `record_id` = ?");
$stmt->execute([$recordId]);
$asset = $stmt->fetch();

if (!$asset) {
    die("Asset 631 not found.");
}

$records = [$asset];
$customerData = [
    'owner_name' => $asset['owner_name'],
    'id_passport_no' => $asset['id_passport_no']
];
$refNo = 'UFAA/HL/2026/00631';
$dateStr = date('d F Y');

$pdf = new UFAA_Clean_Letter_PDF($refNo, $dateStr);
$pdf->buildLetter($customerData, $records);
$rawPdf = $pdf->Output('S');

echo "<h2>PDF File Binary Verification Result</h2>";
echo "<p><strong>Total PDF Buffer Size:</strong> " . strlen($rawPdf) . " bytes</p>";

$header = substr($rawPdf, 0, 15);
$trailer = substr($rawPdf, -15);

echo "<p><strong>Header:</strong> <code>" . htmlspecialchars($header) . "</code></p>";
echo "<p><strong>Trailer:</strong> <code>" . htmlspecialchars($trailer) . "</code></p>";

if (strpos($header, '%PDF') === 0 && strpos($trailer, '%%EOF') !== false) {
    echo "<h3 style='color:green;'>VALID PDF STRUCTURE CONFIRMED!</h3>";
    echo "<p>The PDF binary stream starts cleanly with <code>%PDF</code> and ends cleanly with <code>%%EOF</code>.</p>";
} else {
    echo "<h3 style='color:red;'>CORRUPT PDF BINARY STREAM!</h3>";
}
