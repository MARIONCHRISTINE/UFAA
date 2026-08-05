<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/holder_letter_pdf.php';

$pdo = get_db_connection();
if (!$pdo) {
    die("PDO connection failed!\n");
}

$recordId = $_GET['id'] ?? 631;
$stmt = $pdo->prepare("SELECT * FROM `unclaimed_assets` WHERE `record_id` = ?");
$stmt->execute([(int)$recordId]);
$asset = $stmt->fetch();

echo "<h3>Asset Record #$recordId</h3><pre>";
var_dump($asset);
echo "</pre>";

if ($asset) {
    $records = [$asset];
    $idPassport = trim((string)($asset['id_passport_no'] ?? ''));
    if ($idPassport !== '') {
        $stmtSame = $pdo->prepare("SELECT * FROM `unclaimed_assets` WHERE TRIM(`id_passport_no`) = ? AND TRIM(`id_passport_no`) != '' AND `record_id` != ?");
        $stmtSame->execute([$idPassport, (int)$recordId]);
        $sameRecords = $stmtSame->fetchAll();
        echo "<h4>Associated Records Count: " . count($sameRecords) . "</h4>";
        if (!empty($sameRecords)) {
            $records = array_merge($records, $sameRecords);
        }
    }
    echo "<h4>Total Records Compiled into PDF: " . count($records) . "</h4><pre>";
    print_r($records);
    echo "</pre>";

    echo "<h4>Testing PDF Generation...</h4>";
    try {
        $customerData = [
            'owner_name' => $asset['owner_name'],
            'id_passport_no' => $asset['id_passport_no']
        ];
        $refNo = 'UFAA/HL/' . date('Y') . '/' . str_pad($asset['record_id'], 5, '0', STR_PAD_LEFT);
        $dateStr = date('d F Y');

        $pdf = new UFAA_Clean_Letter_PDF($refNo, $dateStr);
        $pdf->buildLetter($customerData, $records);
        $pdfData = $pdf->Output('S');
        echo "<p style='color:green;font-weight:bold;'>SUCCESS! PDF generated cleanly. Binary size: " . strlen($pdfData) . " bytes.</p>";
    } catch (Throwable $e) {
        echo "<p style='color:red;font-weight:bold;'>ERROR GENERATING PDF: " . htmlspecialchars($e->getMessage()) . "</p>";
        echo "<pre>" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
    }
}
