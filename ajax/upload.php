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

// ─── Duplicate filename check (TEMPORARY SMART UPDATE MODE) ────────────────
$cleanFileName = trim($fileName);
$dupCheck = $pdo->prepare("SELECT `id`, `uploaded_at` FROM `uploaded_files` WHERE `file_name` = ? LIMIT 1");
$dupCheck->execute([$cleanFileName]);
$existing = $dupCheck->fetch();

$isUpdateMode = false;
if ($existing) {
    // Instead of exiting, we proceed to update compilation_dates on existing records
    $isUpdateMode = true;
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
        $dateOfBirth     = isset($row[2]) && trim((string)$row[2]) !== '' ? trim((string)$row[2]) : null;
        $accountNumber   = isset($row[3]) && trim((string)$row[3]) !== '' ? trim((string)$row[3]) : null;
        $lastTransaction = isset($row[4]) && trim((string)$row[4]) !== '' ? trim((string)$row[4]) : null;
        $dueAmount       = isset($row[5]) && trim((string)$row[5]) !== '' ? trim((string)$row[5]) : null;
        $compilationDate = isset($row[6]) && trim((string)$row[6]) !== '' ? trim((string)$row[6]) : null;

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

// ─── Shared DB update helper (Smart Re-upload Mode) ────────────────────────
function updateCompilationDates(PDO $pdo, array $rows): array
{
    $updated  = 0;
    $notFound = 0;
    $skipped  = 0;

    $updateStmt = $pdo->prepare("
        UPDATE `unclaimed_assets`
        SET `compilation_date` = ?
        WHERE `record_id` = ?
    ");

    $fields = ['owner_name', 'id_passport_no', 'date_of_birth', 'account_number', 'last_transaction', 'due_amount'];

    foreach ($rows as $row) {
        $ownerName       = isset($row[0]) && trim((string)$row[0]) !== '' ? trim((string)$row[0]) : null;
        $idPassportNo    = isset($row[1]) && trim((string)$row[1]) !== '' ? trim((string)$row[1]) : null;
        $dateOfBirth     = isset($row[2]) && trim((string)$row[2]) !== '' ? trim((string)$row[2]) : null;
        $accountNumber   = isset($row[3]) && trim((string)$row[3]) !== '' ? trim((string)$row[3]) : null;
        $lastTransaction = isset($row[4]) && trim((string)$row[4]) !== '' ? trim((string)$row[4]) : null;
        $dueAmount       = isset($row[5]) && trim((string)$row[5]) !== '' ? trim((string)$row[5]) : null;
        $compilationDate = isset($row[6]) && trim((string)$row[6]) !== '' ? trim((string)$row[6]) : null;

        // Skip completely empty rows
        if ($ownerName === null && $idPassportNo === null && $dateOfBirth === null &&
            $accountNumber === null && $lastTransaction === null && $dueAmount === null) {
            $skipped++;
            continue;
        }

        // Dynamically build a query that compares each field:
        // If Excel is empty (null), match NULL or empty string in DB.
        // If Excel has a value, match it exactly.
        $whereClauses = [];
        $params = [];
        foreach ($fields as $idx => $field) {
            $val = isset($row[$idx]) && trim((string)$row[$idx]) !== '' ? trim((string)$row[$idx]) : null;
            if ($val === null) {
                $whereClauses[] = "(`$field` IS NULL OR TRIM(`$field`) = '')";
            } else {
                $paramName = ":" . $field . "_val";
                $whereClauses[] = "`$field` = $paramName";
                $params[$paramName] = $val;
            }
        }
        $whereClauses[] = "`compilation_date` IS NULL";

        $selectQuery = "SELECT `record_id` FROM `unclaimed_assets` WHERE " . implode(' AND ', $whereClauses) . " ORDER BY `record_id` ASC LIMIT 1";
        $selectStmt = $pdo->prepare($selectQuery);
        $selectStmt->execute($params);

        $match = $selectStmt->fetch();
        if ($match) {
            $updateStmt->execute([$compilationDate, $match['record_id']]);
            $updated++;
        } else {
            $notFound++;
        }
    }

    return ['updated' => $updated, 'notfound' => $notFound, 'skipped' => $skipped];
}

// ─── Parse based on file extension ────────────────────────────────────────
try {
    $pdo->beginTransaction();
    $allRows = [];

    if ($fileExt === 'csv') {
        // ── CSV: native PHP fgetcsv — zero dependencies, handles any CSV ──
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
        // ── XLSX / XLS: SimpleXLSX library ───────────────────────────────
        $xlsx = SimpleXLSX::parse($fileTmpPath);

        if (!$xlsx) {
            $errMsg = SimpleXLSX::parseError() ?: 'Unknown parsing error.';

            // Give a friendly hint for .xls files which may need resaving
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

    if ($isUpdateMode) {
        // Optimize with a lookup index if it doesn't exist to speed up search on millions of rows
        $indexQuery = $pdo->query("SHOW INDEX FROM `unclaimed_assets` WHERE Key_name = 'idx_lookup'");
        if (!$indexQuery->fetch()) {
            try {
                $pdo->exec("ALTER TABLE `unclaimed_assets` ADD INDEX `idx_lookup` (`owner_name`(100), `id_passport_no`(100))");
            } catch (PDOException $e) {
                // Ignore index errors (e.g. key length, lock time) to avoid blocking the flow
            }
        }

        $result = updateCompilationDates($pdo, $allRows);
        $pdo->commit();

        $totalParsed = count($allRows);
        echo json_encode([
            'status'         => 'success',
            'updated_count'  => $result['updated'],
            'message'        => "Smart Re-upload Complete! Parsed {$totalParsed} rows. "
                              . "Updated compilation date for {$result['updated']} matching records"
                              . ($result['notfound'] > 0 ? ", failed to match {$result['notfound']} rows" : "")
                              . ($result['skipped'] > 0 ? ", skipped {$result['skipped']} blank rows" : "")
                              . "."
        ]);
    } else {
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
    }

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
