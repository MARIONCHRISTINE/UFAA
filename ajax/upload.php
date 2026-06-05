<?php
/**
 * UFAA - Multi-Format File Upload Parser Endpoint (AJAX)
 * Supports: .xlsx (SimpleXLSX), .xls (SimpleXLSX fallback), .csv (native fgetcsv)
 * Parses data horizontally row-by-row and inserts into the database within a transaction.
 */

require_once '../config.php';
require_once '../includes/SimpleXLSX.php';

use Shuchkin\SimpleXLSX;

header('Content-Type: application/json');

$pdo = get_db_connection();
if (!$pdo) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Database connection failed. Please run init_db.php first.']);
    exit;
}

if (!isset($_FILES['excel']) || $_FILES['excel']['error'] !== UPLOAD_ERR_OK) {
    $uploadError = $_FILES['excel']['error'] ?? 'No file uploaded';
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Upload failed. Error Code: ' . $uploadError]);
    exit;
}

$fileTmpPath = $_FILES['excel']['tmp_name'];
$fileName    = $_FILES['excel']['name'];
$fileExt     = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

$allowedFormats = ['xlsx', 'xls', 'csv'];
if (!in_array($fileExt, $allowedFormats)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Invalid format. Please upload a .xlsx, .xls, or .csv file.']);
    exit;
}

// ─── Duplicate filename check (Reject duplicate files) ────────────────
$cleanFileName = trim($fileName);
$dupCheck = $pdo->prepare("SELECT `id`, `uploaded_at` FROM `uploaded_files` WHERE `file_name` = ? LIMIT 1");
$dupCheck->execute([$cleanFileName]);
$existing = $dupCheck->fetch();

if ($existing) {
    http_response_code(400);
    echo json_encode([
        'status' => 'error',
        'message' => 'This file has already been uploaded.'
    ]);
    exit;
}

// Helper function to format and clean Excel/CSV dates into YYYY-MM-DD
function cleanUploadedDate($dateStr) {
    if ($dateStr === null) return null;
    $dateStr = trim((string)$dateStr);
    if ($dateStr === '') return null;

    // Check if it's already in YYYY-MM-DD format (optionally with time)
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})(?:\s+\d{2}:\d{2}:\d{2})?$/', $dateStr, $matches)) {
        return "{$matches[1]}-{$matches[2]}-{$matches[3]}";
    }

    // Try standard formats
    $formats = [
        'Y-m-d H:i:s', 'Y-m-d',
        'd/m/Y H:i:s', 'd/m/Y',
        'd-m-Y H:i:s', 'd-m-Y',
        'd-b-Y', 'd-M-Y',
        'd/b/Y', 'd/M/Y'
    ];

    foreach ($formats as $fmt) {
        $d = DateTime::createFromFormat($fmt, $dateStr);
        if ($d !== false) {
            return $d->format('Y-m-d');
        }
    }

    // Try strtotime fallback
    $time = strtotime($dateStr);
    if ($time !== false) {
        return date('Y-m-d', $time);
    }

    return null;
}

// ─── Shared DB insert helper ───────────────────────────────────────────────
function insertRows(PDO $pdo, array $rows): array
{
    $inserted = 0;
    $skipped  = 0;

    $stmt = $pdo->prepare("
        INSERT INTO `unclaimed_assets`
            (`owner_name`, `id_passport_no`, `date_of_birth`,
             `account_number`, `last_transaction`, `due_amount`,
             `compilation_date`, `status`, `letter_received`, `letter_date`)
        VALUES (?, ?, ?, ?, ?, ?, ?, 'Unclaimed', 'No', NULL)
    ");

    foreach ($rows as $row) {
        $ownerName       = isset($row[0]) && trim((string)$row[0]) !== '' ? trim((string)$row[0]) : null;
        $idPassportNo    = isset($row[1]) && trim((string)$row[1]) !== '' ? trim((string)$row[1]) : null;
        $dateOfBirth     = isset($row[2]) && trim((string)$row[2]) !== '' ? cleanUploadedDate($row[2]) : null;
        $accountNumber   = isset($row[3]) && trim((string)$row[3]) !== '' ? trim((string)$row[3]) : null;
        $lastTransaction = isset($row[4]) && trim((string)$row[4]) !== '' ? trim((string)$row[4]) : null;
        $dueAmount       = isset($row[5]) && trim((string)$row[5]) !== '' ? trim((string)$row[5]) : null;
        $compilationDate = isset($row[6]) && trim((string)$row[6]) !== '' ? cleanUploadedDate($row[6]) : null;

        // Skip completely empty rows
        if ($ownerName === null && $idPassportNo === null && $dateOfBirth === null &&
            $accountNumber === null && $lastTransaction === null && $dueAmount === null) {
            $skipped++;
            continue;
        }

        $stmt->execute([$ownerName, $idPassportNo, $dateOfBirth, $accountNumber, $lastTransaction, $dueAmount, $compilationDate]);
        $inserted++;
    }

    return ['inserted' => $inserted, 'skipped' => $skipped];
}

// ─── Parse based on file extension ────────────────────────────────────────
try {
    $pdo->beginTransaction();
    $allRows = [];

    if ($fileExt === 'csv') {
        // ── CSV: native PHP fgetcsv ──
        $handle = fopen($fileTmpPath, 'r');
        if (!$handle) {
            throw new Exception('Could not open the uploaded CSV file.');
        }

        $lineIndex = 0;
        while (($row = fgetcsv($handle, 0, ',')) !== false) {
            $lineIndex++;
            if ($lineIndex === 1) continue; // Skip header row
            $allRows[] = $row;
        }
        fclose($handle);

    } elseif ($fileExt === 'xlsx' || $fileExt === 'xls') {
        // ── XLSX / XLS: SimpleXLSX library ──
        $xlsx = SimpleXLSX::parse($fileTmpPath);

        if (!$xlsx) {
            $errMsg = SimpleXLSX::parseError() ?: 'Unknown parsing error.';

            if ($fileExt === 'xls') {
                $errMsg = 'Could not parse the .xls file. The old Excel 97-2003 format may not be supported directly. '
                        . 'Please open the file in Excel and re-save it as "Excel Workbook (.xlsx)" then upload again.';
            }

            throw new Exception($errMsg);
        }

        $rowIndex = 0;
        foreach ($xlsx->rows() as $row) {
            if ($rowIndex === 0) { $rowIndex++; continue; } // Skip header
            $allRows[] = $row;
            $rowIndex++;
        }
    }

    // ─── Insert all parsed rows ───────────────────────────────────────────
    $result = insertRows($pdo, $allRows);
    $pdo->commit();

    // ─── Record the filename to prevent future re-uploads ─────────────────
    $pdo->prepare("INSERT IGNORE INTO `uploaded_files` (`file_name`) VALUES (?)")->execute([$cleanFileName]);

    $totalParsed = count($allRows);
    echo json_encode([
        'status'         => 'success',
        'inserted_count' => $result['inserted'],
        'message'        => "Import complete! Parsed {$totalParsed} data rows, "
                          . "inserted {$result['inserted']} records"
                          . ($result['skipped'] > 0 ? ", skipped {$result['skipped']} blank rows." : ".")
    ]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
