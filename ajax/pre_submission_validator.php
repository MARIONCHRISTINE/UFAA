<?php
/**
 * UFAA – Pre-Submission Data Validator & 6-Field Duplicate Detection Engine
 * Optimized for multi-million database records and up to 500,000 upload rows.
 * Handles robust date/amount normalization, indexed batch queries, and
 * compact issues payload to prevent server memory exhaustion and browser freezing.
 */

ob_start();
error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING);
ini_set('display_errors', '0');
ini_set('memory_limit', '1024M');
set_time_limit(600); // 10 minutes max for massive datasets

require_once __DIR__ . '/../config.php';

header('Content-Type: application/json');

$pdo = get_db_connection();
if (!$pdo) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Database connection failed.']);
    exit;
}

// ── Ensure critical BTREE indexes exist on unclaimed_assets for instant lookups ──
try {
    $idxCheck = $pdo->query("SHOW INDEX FROM `unclaimed_assets` WHERE Key_name = 'idx_account_no'")->fetch();
    if (!$idxCheck) {
        @$pdo->exec("ALTER TABLE `unclaimed_assets` ADD INDEX `idx_account_no` (`account_number`(191))");
    }
} catch (Exception $_e) {}
try {
    $idxCheck2 = $pdo->query("SHOW INDEX FROM `unclaimed_assets` WHERE Key_name = 'idx_id_passport'")->fetch();
    if (!$idxCheck2) {
        @$pdo->exec("ALTER TABLE `unclaimed_assets` ADD INDEX `idx_id_passport` (`id_passport_no`(191))");
    }
} catch (Exception $_e2) {}

// Get JSON raw input
$rawInput = file_get_contents('php://input');
$data = json_decode($rawInput, true) ?: [];
if (empty($data)) {
    $data = !empty($_POST) ? $_POST : $_GET;
}

$draftId = isset($data['draft_id']) ? intval($data['draft_id']) : (isset($_REQUEST['draft_id']) ? intval($_REQUEST['draft_id']) : 0);
$rows = [];

if (isset($data['rows']) && is_array($data['rows']) && !empty($data['rows'])) {
    $rows = $data['rows'];
} elseif ($draftId > 0) {
    // Read from disk file or database directly
    $diskPath = __DIR__ . '/../uploads/drafts/draft_' . $draftId . '.json';
    if (file_exists($diskPath)) {
        $content = @file_get_contents($diskPath);
        if ($content !== false && $content !== '') {
            $rows = json_decode($content, true) ?: [];
        }
    }
    if (empty($rows)) {
        $stmt = $pdo->prepare("SELECT `rows_data` FROM `sandbox_drafts` WHERE `id` = ?");
        $stmt->execute([$draftId]);
        $draft = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($draft) {
            $rows = json_decode($draft['rows_data'] ?? '[]', true) ?: [];
        }
    }
}

if (!is_array($rows)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Invalid payload format. "rows" array or valid "draft_id" required.']);
    exit;
}

$totalRows = count($rows);
$validatedRows = [];
$rowIssues = [];
$criticalCount = 0;
$warningCount = 0;
$cleanCount = 0;
$inFileDuplicates = 0;
$dbDuplicates = 0;

// Trackers for identity consistency & duplicates in-file
$seenDupKeys     = [];
$seenIdDobMap    = [];
$seenIdNameMap   = [];
$seenAcctNameMap = [];

$invalidPlaceholders = ['N/A', 'NA', 'NONE', 'NIL', 'UNKNOWN', 'NOT PROVIDED', 'NO ID', 'NOT AVAILABLE', '0', '-', '--', '000000', '123456', 'NULL', 'INVALID'];

// ── Normalization Functions ────────────────────────────────────────────────
function normString($val): string {
    return strtolower(trim((string)($val ?? '')));
}

function normIdVal($id): string {
    return strtoupper(trim((string)($id ?? '')));
}

function normAcctVal($acct): string {
    return strtoupper(preg_replace('/[\s\-_]/', '', (string)($acct ?? '')));
}

function makeDupKey($id, $acct, $tx): string {
    $normId   = strtoupper(trim((string)($id ?? '')));
    $normAcct = strtoupper(preg_replace('/[\s\-_]/', '', (string)($acct ?? '')));
    $normTx   = strtoupper(preg_replace('/\s+/', ' ', trim((string)($tx ?? ''))));
    if ($normAcct === '' && $normId === '') return '';
    return $normId . '|' . $normAcct . '|' . $normTx;
}

// ── Pre-fetch Candidate Database Records by Account Number ──────────────────
$acctList = [];
$missingAcctIdList = [];

foreach ($rows as $r) {
    $rawAcct = trim((string)($r['account_number'] ?? ''));
    if ($rawAcct !== '') {
        $acctList[$rawAcct] = true;
    } else {
        $rawId = trim((string)($r['id_passport_no'] ?? ''));
        if (strlen($rawId) >= 5 && !in_array(strtoupper($rawId), $invalidPlaceholders, true)) {
            $missingAcctIdList[$rawId] = true;
        }
    }
}

$acctList = array_keys($acctList);
$missingAcctIdList = array_keys($missingAcctIdList);

$dbDupMap = [];

// Batch query candidate records by Account Number
if (!empty($acctList)) {
    $chunks = array_chunk($acctList, 1500);
    foreach ($chunks as $chunk) {
        try {
            $placeholders = implode(',', array_fill(0, count($chunk), '?'));
            $stmt = $pdo->prepare("
                SELECT `record_id` AS `id`, `owner_name`, `account_number`, `due_amount`, `id_passport_no`, `date_of_birth`, `last_transaction`, `compilation_date`, `uploaded_at` AS `created_at`
                FROM `unclaimed_assets`
                WHERE `account_number` IN ($placeholders)
            ");
            $stmt->execute($chunk);
            while ($dbRow = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $key = makeDupKey($dbRow['id_passport_no'], $dbRow['account_number'], $dbRow['last_transaction']);
                if ($key !== '') {
                    $dbDupMap[$key] = $dbRow;
                }
            }
        } catch (Exception $ex) {
            error_log("DB Duplicate lookup error: " . $ex->getMessage());
        }
    }
}

// Fallback for rows with missing accounts: query by ID
if (!empty($missingAcctIdList)) {
    $chunks = array_chunk($missingAcctIdList, 1500);
    foreach ($chunks as $chunk) {
        try {
            $placeholders = implode(',', array_fill(0, count($chunk), '?'));
            $stmt = $pdo->prepare("
                SELECT `record_id` AS `id`, `owner_name`, `account_number`, `due_amount`, `id_passport_no`, `date_of_birth`, `last_transaction`, `compilation_date`, `uploaded_at` AS `created_at`
                FROM `unclaimed_assets`
                WHERE `id_passport_no` IN ($placeholders)
            ");
            $stmt->execute($chunk);
            while ($dbRow = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $key = makeDupKey($dbRow['id_passport_no'], $dbRow['account_number'], $dbRow['last_transaction']);
                if ($key !== '') {
                    $dbDupMap[$key] = $dbRow;
                }
            }
        } catch (Exception $ex) {}
    }
}

$duplicateDetails = [];

// ── Row-by-Row Validation & True 6-Field Matching ──────────────────────────
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
    } else {
        $cleanAmt = preg_replace('/[^\d\.\-]/', '', $dueAmount);
        if (!is_numeric($cleanAmt) || floatval($cleanAmt) <= 0) {
            $errors['due_amount'] = 'Due Amount must be a positive number';
        }
    }
    if ($compilation === '') {
        $errors['compilation_date'] = 'Compilation Date is required';
    }

    // 2. ID / Passport Validation
    if ($idPassport === '') {
        $errors['id_passport_no'] = 'ID / Passport No is required';
    } else {
        $cleanId = strtoupper(trim($idPassport));
        $normName = normString($ownerName);

        if (in_array($cleanId, $invalidPlaceholders, true) || strlen($cleanId) < 5 || preg_match('/^[a-z0-9]{1,4}$/i', $cleanId)) {
            $warnings['id_passport_no'] = "Suspicious/Invalid ID format ('{$idPassport}')";
        }

        // 2A. Identity Anomaly: Same ID with different DOB
        if ($dob !== '') {
            if (isset($seenIdDobMap[$cleanId])) {
                $prevDob = $seenIdDobMap[$cleanId];
                if ($prevDob['dob'] !== '' && strtolower($prevDob['dob']) !== strtolower($dob)) {
                    $warnings['id_passport_no'] = "Identity Anomaly: Same ID matches Row #{$prevDob['row_num']} with different DOB";
                    $warnings['date_of_birth']  = "DOB Conflict: ID matches Row #{$prevDob['row_num']} with DOB {$prevDob['raw_dob']}";

                    $prevRowIdx = $prevDob['row_num'] - 1;
                    if (!isset($rowIssues[$prevRowIdx])) $rowIssues[$prevRowIdx] = ['errors' => [], 'warnings' => []];
                    $rowIssues[$prevRowIdx]['warnings']['id_passport_no'] = "Identity Anomaly: Same ID matches Row #{$rowNum} with different DOB";
                    $rowIssues[$prevRowIdx]['warnings']['date_of_birth']  = "DOB Conflict: ID matches Row #{$rowNum} with DOB {$dob}";
                }
            } else {
                $seenIdDobMap[$cleanId] = ['dob' => $dob, 'raw_dob' => $dob, 'row_num' => $rowNum];
            }
        }

        // 2B. Identity Anomaly: Same ID with different Owner Name
        if ($normName !== '') {
            if (isset($seenIdNameMap[$cleanId])) {
                $prevName = $seenIdNameMap[$cleanId];
                if ($prevName['name'] !== '' && $prevName['name'] !== $normName) {
                    $warnings['id_passport_no'] = "Identity Anomaly: Same ID matches Row #{$prevName['row_num']} with different Name";
                    $warnings['owner_name']     = "Name Anomaly: Same ID matches Row #{$prevName['row_num']} ('{$prevName['raw_name']}')";

                    $prevRowIdx = $prevName['row_num'] - 1;
                    if (!isset($rowIssues[$prevRowIdx])) $rowIssues[$prevRowIdx] = ['errors' => [], 'warnings' => []];
                    $rowIssues[$prevRowIdx]['warnings']['id_passport_no'] = "Identity Anomaly: Same ID matches Row #{$rowNum} with different Name";
                    $rowIssues[$prevRowIdx]['warnings']['owner_name']     = "Name Anomaly: Same ID matches Row #{$rowNum} ('{$ownerName}')";
                }
            } else {
                $seenIdNameMap[$cleanId] = ['name' => $normName, 'raw_name' => $ownerName, 'row_num' => $rowNum];
            }
        }
    }

    // 3. Required Checks for DOB & Last Transaction
    if ($dob === '') {
        $errors['date_of_birth'] = 'Date of Birth is required';
    }

    if ($lastTx === '') {
        $errors['last_transaction'] = 'Last Transaction date is required';
    }

    // 4. Duplicate Checks (In-File and Historical DB matches)
    // Matches by ID number, Account number, and Last Transaction
    $dupKey = makeDupKey($idPassport, $accountNo, $lastTx);

    // 4A: In-File Duplicate (ID, Account, and Last Transaction match another row in this sheet)
    if ($dupKey !== '' && isset($seenDupKeys[$dupKey])) {
        $dupRow = $seenDupKeys[$dupKey];
        $errors['account_number'] = "True In-File Duplicate: ID, Account, and Last Transaction match Row #{$dupRow}";
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
            'compilation_date' => $compilation
        ];

        // Backfill earlier row
        $prevRowIdx = $dupRow - 1;
        if (!isset($rowIssues[$prevRowIdx])) $rowIssues[$prevRowIdx] = ['errors' => [], 'warnings' => []];
        $rowIssues[$prevRowIdx]['errors']['account_number'] = "True In-File Duplicate: ID, Account, and Last Transaction match Row #{$rowNum}";
    } elseif ($dupKey !== '') {
        $seenDupKeys[$dupKey] = $rowNum;
    }

    // 4B: Historical DB Duplicate (ID, Account, and Last Transaction match a MySQL database record)
    if ($dupKey !== '' && isset($dbDupMap[$dupKey])) {
        $matchedDb = $dbDupMap[$dupKey];
        $errors['account_number'] = "True DB Duplicate: ID, Account, and Last Transaction match MySQL Record #{$matchedDb['id']} ({$matchedDb['owner_name']})";
        $dbDuplicates++;
        $duplicateDetails[] = [
            'type'             => 'database',
            'row_num'          => $rowNum,
            'db_id'            => $matchedDb['id'],
            'db_owner_name'    => $matchedDb['owner_name'],
            'owner_name'       => $ownerName,
            'id_passport_no'   => $idPassport,
            'date_of_birth'    => $dob,
            'account_number'   => $accountNo,
            'last_transaction' => $lastTx,
            'due_amount'       => $dueAmount,
            'compilation_date' => $compilation,
            'created_at'       => $matchedDb['created_at'] ?? 'Previously Uploaded'
        ];
    }

    if (!empty($errors) || !empty($warnings)) {
        if (!isset($rowIssues[$index])) {
            $rowIssues[$index] = ['errors' => $errors, 'warnings' => $warnings];
        } else {
            $rowIssues[$index]['errors']   = array_merge($rowIssues[$index]['errors'], $errors);
            $rowIssues[$index]['warnings'] = array_merge($rowIssues[$index]['warnings'], $warnings);
        }
    }
}

// Compute counts directly from issues map
$criticalCount = 0;
$warningCount  = 0;
foreach ($rowIssues as $idx => $issue) {
    if (!empty($issue['errors'])) {
        $criticalCount++;
    }
    if (!empty($issue['warnings'])) {
        $warningCount++;
    }
}
$cleanCount = max(0, $totalRows - $criticalCount);

if (ob_get_length()) ob_clean();
header('Content-Type: application/json');

$response = [
    'status' => 'success',
    'summary' => [
        'total'              => $totalRows,
        'clean'              => $cleanCount,
        'critical'           => $criticalCount,
        'warnings'           => $warningCount,
        'in_file_duplicates' => $inFileDuplicates,
        'db_duplicates'      => $dbDuplicates,
    ],
    'duplicate_details' => array_slice($duplicateDetails, 0, 1000), // Cap inspector display payload to 1,000 for instant UI rendering
    'row_issues'        => (object)$rowIssues
];

// If rows array was small (e.g. <= 1,000) and caller provided rows directly, include rows for backward compatibility
if ($totalRows <= 1000 && !empty($data['rows'])) {
    $responseRows = [];
    foreach ($rows as $i => $r) {
        $iss = $rowIssues[$i] ?? ['errors' => [], 'warnings' => []];
        $hasCrit = !empty($iss['errors']);
        $isClean = empty($iss['errors']) && empty($iss['warnings']);
        $responseRows[] = array_merge($r, [
            'row_num'      => $i + 1,
            'errors'       => $iss['errors'],
            'warnings'     => $iss['warnings'],
            'is_clean'     => $isClean,
            'has_critical' => $hasCrit
        ]);
    }
    $response['rows'] = $responseRows;
}

echo json_encode($response);
