<?php
ob_start();
error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING);
ini_set('display_errors', '0');

require_once __DIR__ . '/../config.php';

header('Content-Type: application/json');

$pdo = get_db_connection();
if (!$pdo) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Database connection failed.']);
    exit;
}

// Get JSON raw input
$rawInput = file_get_contents('php://input');
$data = json_decode($rawInput, true);

if (!isset($data['rows']) || !is_array($data['rows'])) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Invalid payload format. "rows" array required.']);
    exit;
}

$rows = $data['rows'];
$validatedRows = [];
$totalRows = count($rows);
$criticalCount = 0;
$warningCount = 0;
$cleanCount = 0;
$inFileDuplicates = 0;
$dbDuplicates = 0;

// Trackers for identity consistency & duplicates in-file
$seen6FieldKeys = [];
$seenIdDobMap   = [];
$seenIdNameMap  = [];
$seenAcctNameMap= [];

$invalidPlaceholders = ['N/A', 'NA', 'NONE', 'NIL', 'UNKNOWN', 'NOT PROVIDED', 'NO ID', 'NOT AVAILABLE', '0', '-', '--', '000000', '123456', 'NULL', 'INVALID'];

// Helper to normalize strings for exact 6-field comparison
function norm($val) {
    return strtolower(trim((string)($val ?? '')));
}

function flexDateNorm($s) {
    if (!$s) return '';
    $s = trim((string)$s);
    $d = date_create($s);
    return $d ? date_format($d, 'Y-m-d') : strtolower($s);
}

function make6FieldKey($id, $dob, $acct, $tx, $amt, $comp) {
    return norm($id) . '|' . norm($dob) . '|' . norm($acct) . '|' . norm($tx) . '|' . norm($amt) . '|' . norm($comp);
}

// Pre-fetch candidate database records by Account Number or ID/Passport Number
$acctList = array_values(array_unique(array_filter(array_map(function($r) {
    return trim((string)($r['account_number'] ?? ''));
}, $rows))));

$idList = array_values(array_unique(array_filter(array_map(function($r) {
    $id = trim((string)($r['id_passport_no'] ?? ''));
    return (strlen($id) >= 5 && !in_array(strtoupper($id), ['N/A','NONE','NULL','0','-','123456'], true)) ? $id : null;
}, $rows))));

$db6FieldMap = [];
if (!empty($acctList)) {
    $chunks = array_chunk($acctList, 500);
    foreach ($chunks as $chunk) {
        try {
            $chunkValues = array_values($chunk);
            $placeholders = implode(',', array_fill(0, count($chunkValues), '?'));
            $stmt = $pdo->prepare("
                SELECT `record_id` AS `id`, `owner_name`, `account_number`, `due_amount`, `id_passport_no`, `date_of_birth`, `last_transaction`, `compilation_date`, `uploaded_at` AS `created_at`
                FROM `unclaimed_assets`
                WHERE `account_number` IN ($placeholders)
            ");
            $stmt->execute($chunkValues);
            while ($dbRow = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $compStr = $dbRow['compilation_date'] ? date_format(date_create($dbRow['compilation_date']), 'Y-m-d') : '';
                $sixKey  = make6FieldKey($dbRow['id_passport_no'], $dbRow['date_of_birth'], $dbRow['account_number'], $dbRow['last_transaction'], $dbRow['due_amount'], $compStr);
                $db6FieldMap[$sixKey] = $dbRow;
            }
        } catch (Exception $ex) {
            error_log("DB Duplicate lookup error: " . $ex->getMessage());
        }
    }
}

if (!empty($idList)) {
    $chunks = array_chunk($idList, 500);
    foreach ($chunks as $chunk) {
        try {
            $chunkValues = array_values($chunk);
            $placeholders = implode(',', array_fill(0, count($chunkValues), '?'));
            $stmt = $pdo->prepare("
                SELECT `record_id` AS `id`, `owner_name`, `account_number`, `due_amount`, `id_passport_no`, `date_of_birth`, `last_transaction`, `compilation_date`, `uploaded_at` AS `created_at`
                FROM `unclaimed_assets`
                WHERE `id_passport_no` IN ($placeholders)
            ");
            $stmt->execute($chunkValues);
            while ($dbRow = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $compStr = $dbRow['compilation_date'] ? date_format(date_create($dbRow['compilation_date']), 'Y-m-d') : '';
                $sixKey  = make6FieldKey($dbRow['id_passport_no'], $dbRow['date_of_birth'], $dbRow['account_number'], $dbRow['last_transaction'], $dbRow['due_amount'], $compStr);
                $db6FieldMap[$sixKey] = $dbRow;
            }
        } catch (Exception $ex) {}
    }
}

$duplicateDetails = [];

foreach ($rows as $index => $row) {
    $rowNum = $index + 1;
    $errors = [];
    $warnings = [];

    $ownerName   = trim((string)($row['owner_name'] ?? ''));
    $idPassport  = trim((string)($row['id_passport_no'] ?? ''));
    $dob         = trim((string)($row['date_of_birth'] ?? ''));
    $accountNo   = trim((string)($row['account_number'] ?? ''));
    $lastTx      = trim((string)($row['last_transaction'] ?? ''));
    $dueAmount   = trim((string)($row['due_amount'] ?? ''));
    $compilation = trim((string)($row['compilation_date'] ?? ''));

    // 1. Mandatory Field Checks
    if ($ownerName === '') {
        $errors['owner_name'] = 'Owner Name is required';
    }
    if ($accountNo === '') {
        $errors['account_number'] = 'Account Number is required';
    }
    if ($dueAmount === '') {
        $errors['due_amount'] = 'Due Amount is required';
    } elseif (!is_numeric(str_replace(',', '', $dueAmount)) || floatval(str_replace(',', '', $dueAmount)) <= 0) {
        $errors['due_amount'] = 'Due Amount must be a positive number';
    }
    if ($compilation === '') {
        $errors['compilation_date'] = 'Compilation Date is required';
    } else {
        $parsedDate = date_create($compilation);
        if (!$parsedDate) {
            $errors['compilation_date'] = 'Invalid Compilation Date format';
        }
    }

    // 2. ID / Passport Validation (Missing, Suspicious or DOB Anomaly)
    if ($idPassport === '') {
        $errors['id_passport_no'] = 'ID / Passport No is required';
    } else {
        $cleanId = strtoupper(trim($idPassport));
        $normDob  = norm($dob);
        $normName = norm($ownerName);

        if (in_array($cleanId, $invalidPlaceholders, true) || strlen($cleanId) < 5 || preg_match('/^[a-z0-9]{1,4}$/i', $cleanId)) {
            $warnings['id_passport_no'] = "Suspicious/Invalid ID format ('{$idPassport}')";
        }

        // 2A. Identity Anomaly: Same ID / Passport No with different Date of Birth
        if ($normDob !== '') {
            if (isset($seenIdDobMap[$cleanId])) {
                $prevDob = $seenIdDobMap[$cleanId];
                if ($prevDob['dob'] !== '' && $prevDob['dob'] !== $normDob) {
                    $warnings['id_passport_no'] = "Identity Anomaly: Same ID '{$idPassport}' matches Row #{$prevDob['row_num']} with different DOB ({$dob} vs {$prevDob['raw_dob']})";
                    $warnings['date_of_birth']  = "DOB Conflict: ID '{$idPassport}' registered with {$prevDob['raw_dob']} on Row #{$prevDob['row_num']}";

                    // Back-fill earlier row so BOTH rows highlight!
                    $prevRowIdx = $prevDob['row_num'] - 1;
                    if (isset($validatedRows[$prevRowIdx])) {
                        $validatedRows[$prevRowIdx]['warnings']['id_passport_no'] = "Identity Anomaly: Same ID '{$idPassport}' matches Row #{$rowNum} with different DOB ({$prevDob['raw_dob']} vs {$dob})";
                        $validatedRows[$prevRowIdx]['warnings']['date_of_birth']  = "DOB Conflict: ID '{$idPassport}' matches Row #{$rowNum} with DOB {$dob}";
                        $validatedRows[$prevRowIdx]['is_clean'] = false;
                    }
                }
            } else {
                $seenIdDobMap[$cleanId] = [
                    'dob'     => $normDob,
                    'raw_dob' => $dob,
                    'row_num' => $rowNum
                ];
            }
        }

        // 2B. Identity Anomaly: Same ID / Passport No with different Owner Name
        if ($normName !== '') {
            if (isset($seenIdNameMap[$cleanId])) {
                $prevName = $seenIdNameMap[$cleanId];
                if ($prevName['name'] !== '' && $prevName['name'] !== $normName) {
                    $warnings['id_passport_no'] = "Identity Anomaly: Same ID '{$idPassport}' matches Row #{$prevName['row_num']} with different Owner Name ('{$ownerName}' vs '{$prevName['raw_name']}')";
                    $warnings['owner_name']     = "Name Anomaly: Same ID '{$idPassport}' matches Row #{$prevName['row_num']} ('{$prevName['raw_name']}')";

                    // Back-fill earlier row so BOTH rows highlight!
                    $prevRowIdx = $prevName['row_num'] - 1;
                    if (isset($validatedRows[$prevRowIdx])) {
                        $validatedRows[$prevRowIdx]['warnings']['id_passport_no'] = "Identity Anomaly: Same ID '{$idPassport}' matches Row #{$rowNum} with different Owner Name ('{$prevName['raw_name']}' vs '{$ownerName}')";
                        $validatedRows[$prevRowIdx]['warnings']['owner_name']     = "Name Anomaly: Same ID '{$idPassport}' matches Row #{$rowNum} ('{$ownerName}')";
                        $validatedRows[$prevRowIdx]['is_clean'] = false;
                    }
                }
            } else {
                $seenIdNameMap[$cleanId] = [
                    'name'     => $normName,
                    'raw_name' => $ownerName,
                    'row_num'  => $rowNum
                ];
            }
        }
    }

    // 3. Date Format & Missing Checks for DOB & Last Transaction
    if ($dob === '') {
        $errors['date_of_birth'] = 'Date of Birth is required';
    } else {
        if (!date_create($dob) && !preg_match('/^\d{1,2}[\/\-\.]\d{1,2}[\/\-\.]\d{2,4}$/', $dob)) {
            $warnings['date_of_birth'] = "Unrecognized Date of Birth format ('{$dob}')";
        }
    }

    if ($lastTx === '') {
        $errors['last_transaction'] = 'Last Transaction date is required';
    } else {
        if (!date_create($lastTx) && !preg_match('/^\d{1,2}[\/\-\.]\d{1,2}[\/\-\.]\d{2,4}/', $lastTx)) {
            $warnings['last_transaction'] = "Unrecognized Last Transaction date format ('{$lastTx}')";
        }
    }

    // 4. True 6-Field Duplicate Checks (In-File and Historical DB matches)
    // A record is ONLY a true duplicate when ALL 6 fields match 100%:
    // ID/Passport No, Date of Birth, Account Number, Last Transaction, Due Amount, Compilation Date
    $compilationStr = flexDateNorm($compilation);
    $sixKey = make6FieldKey($idPassport, $dob, $accountNo, $lastTx, $dueAmount, $compilationStr);

    // Check 4A: True In-File Duplicate (All 6 fields match another row in this sheet)
    if (isset($seen6FieldKeys[$sixKey])) {
        $dupRow = $seen6FieldKeys[$sixKey];
        $errors['account_number'] = "True In-File Duplicate: All 6 fields match Row #{$dupRow}";
        $inFileDuplicates++;
        $duplicateDetails[] = [
            'type'             => 'in_file',
            'row_num'          => $rowNum,
            'matched_with_row' => $dupRow,
            'owner_name'       => $ownerName,
            'id_passport_no'   => $idPassport,
            'date_of_birth'    => $dob,
            'account_number'   => $accountNo,
            'last_transaction' => $lastTx,
            'due_amount'       => $dueAmount,
            'compilation_date' => $compilationStr
        ];

        // Back-fill earlier row so BOTH rows highlight in red!
        $prevRowIdx = $dupRow - 1;
        if (isset($validatedRows[$prevRowIdx])) {
            $validatedRows[$prevRowIdx]['errors']['account_number'] = "True In-File Duplicate: All 6 fields match Row #{$rowNum}";
            $validatedRows[$prevRowIdx]['is_clean'] = false;
            $validatedRows[$prevRowIdx]['has_critical'] = true;
        }
    } else {
        $seen6FieldKeys[$sixKey] = $rowNum;
    }

    // Check 4B: True Historical DB Duplicate (All 6 fields match a MySQL database record)
    if (isset($db6FieldMap[$sixKey])) {
        $matchedDb = $db6FieldMap[$sixKey];
        $errors['account_number'] = "True DB Duplicate: All 6 fields match MySQL Record #{$matchedDb['id']} ({$matchedDb['owner_name']})";
        $dbDuplicates++;
        $duplicateDetails[] = [
            'type'             => 'database',
            'row_num'          => $rowNum,
            'db_id'            => $matchedDb['id'],
            'db_owner_name'    => $matchedDb['owner_name'],
            // Sheet row fields
            'owner_name'       => $ownerName,
            'id_passport_no'   => $idPassport,
            'date_of_birth'    => $dob,
            'account_number'   => $accountNo,
            'last_transaction' => $lastTx,
            'due_amount'       => $dueAmount,
            'compilation_date' => $compilationStr,
            'created_at'       => $matchedDb['created_at'] ?? 'Previously Uploaded'
        ];
    }

    $isClean = empty($errors) && empty($warnings);
    $hasCritical = !empty($errors);

    if ($hasCritical) {
        $criticalCount++;
    }
    if (!empty($warnings)) {
        $warningCount++;
    }
    if ($isClean) {
        $cleanCount++;
    }

    $validatedRows[] = [
        'row_num'          => $rowNum,
        'owner_name'       => $ownerName,
        'id_passport_no'   => $idPassport,
        'date_of_birth'    => $dob,
        'account_number'   => $accountNo,
        'last_transaction' => $lastTx,
        'due_amount'       => $dueAmount,
        'compilation_date' => $compilation,
        'errors'           => $errors,
        'warnings'         => $warnings,
        'is_clean'         => $isClean,
        'has_critical'     => $hasCritical
    ];
}

// Recalculate final counts directly from validatedRows to account for retroactive back-fills
$criticalCount = count(array_filter($validatedRows, function($r) {
    return !empty($r['errors']);
}));
$warningCount = count(array_filter($validatedRows, function($r) {
    return !empty($r['warnings']);
}));
$cleanCount = count(array_filter($validatedRows, function($r) {
    return empty($r['errors']) && empty($r['warnings']);
}));

if (ob_get_length()) ob_clean();
header('Content-Type: application/json');
echo json_encode([
    'status' => 'success',
    'summary' => [
        'total'              => $totalRows,
        'clean'              => $cleanCount,
        'critical'           => $criticalCount,
        'warnings'           => $warningCount,
        'in_file_duplicates' => $inFileDuplicates,
        'db_duplicates'      => $dbDuplicates,
    ],
    'duplicate_details' => $duplicateDetails,
    'rows' => $validatedRows
]);
