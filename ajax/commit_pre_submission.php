<?php
ob_start();
error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING);
ini_set('display_errors', '0');

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/SimpleXLSXGen.php';

use Shuchkin\SimpleXLSXGen;

header('Content-Type: application/json');

$pdo = get_db_connection();
if (!$pdo) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Database connection failed.']);
    exit;
}

// Get JSON input
$rawInput = file_get_contents('php://input');
$data = json_decode($rawInput, true);

if (!isset($data['rows']) || !is_array($data['rows']) || empty($data['rows'])) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'No clean rows provided for submission.']);
    exit;
}

$rows = $data['rows'];
$draftId = (int)($data['draft_id'] ?? $_POST['draft_id'] ?? 0);
$uploadedBy = $_SESSION['admin_user']['username'] ?? $_SESSION['username'] ?? 'Compliance Officer';
$userRole   = $_SESSION['admin_user']['role'] ?? $_SESSION['role'] ?? 'officer';

// Ownership check: Only file owner or admin can submit
if ($draftId > 0) {
    $chkStmt = $pdo->prepare("SELECT `created_by` FROM `sandbox_drafts` WHERE `id` = ?");
    $chkStmt->execute([$draftId]);
    $draftInfo = $chkStmt->fetch(PDO::FETCH_ASSOC);
    if ($draftInfo) {
        $owner = $draftInfo['created_by'];
        if ($owner !== $uploadedBy && strtolower($userRole) !== 'admin') {
            http_response_code(403);
            echo json_encode(['status' => 'error', 'message' => "Only the file owner ({$owner}) is authorized to submit this batch to UFAA."]);
            exit;
        }
    }
}

// Generate dynamic password: UFAA-AIRTEL-XXXXXX
$randomCode = strtoupper(bin2hex(random_bytes(3))); // 6 hex chars
$protectionPassword = 'UFAA-AIRTEL-' . $randomCode;

// Base file name
$timestamp = date('Y-m-d_His');
$baseFileName = 'UFAA_Submission_' . date('Y-m-d') . '_' . $randomCode . '.xlsx';

// 1. Begin Database Transaction
try {
    $pdo->beginTransaction();

    $countInserted = 0;
    $batchSize = 250;
    $batchValues = [];
    $batchParams = [];

    foreach ($rows as $row) {
        $ownerName   = trim((string)($row['owner_name'] ?? ''));
        $idPassport  = trim((string)($row['id_passport_no'] ?? ''));
        $dob         = trim((string)($row['date_of_birth'] ?? ''));
        $accountNo   = trim((string)($row['account_number'] ?? ''));
        $lastTx      = trim((string)($row['last_transaction'] ?? ''));
        $dueAmount   = trim((string)($row['due_amount'] ?? ''));
        $compilation = trim((string)($row['compilation_date'] ?? ''));

        // Normalize compilation date to YYYY-MM-DD
        $formattedDate = null;
        if ($compilation !== '') {
            $parsed = date_create($compilation);
            if ($parsed) {
                $formattedDate = date_format($parsed, 'Y-m-d');
            }
        }

        $batchValues[] = "(?, ?, ?, ?, ?, ?, ?, 'Unclaimed', 'No', NOW())";
        $batchParams[] = $ownerName !== '' ? $ownerName : null;
        $batchParams[] = $idPassport !== '' ? $idPassport : null;
        $batchParams[] = $dob !== '' ? $dob : null;
        $batchParams[] = $accountNo !== '' ? $accountNo : null;
        $batchParams[] = $lastTx !== '' ? $lastTx : null;
        $batchParams[] = $dueAmount !== '' ? $dueAmount : null;
        $batchParams[] = $formattedDate;

        $countInserted++;

        if (count($batchValues) >= $batchSize) {
            $sql = "INSERT INTO `unclaimed_assets` (`owner_name`, `id_passport_no`, `date_of_birth`, `account_number`, `last_transaction`, `due_amount`, `compilation_date`, `status`, `letter_received`, `uploaded_at`) VALUES " . implode(', ', $batchValues);
            $pdo->prepare($sql)->execute($batchParams);
            $batchValues = [];
            $batchParams = [];
        }
    }

    if (!empty($batchValues)) {
        $sql = "INSERT INTO `unclaimed_assets` (`owner_name`, `id_passport_no`, `date_of_birth`, `account_number`, `last_transaction`, `due_amount`, `compilation_date`, `status`, `letter_received`, `uploaded_at`) VALUES " . implode(', ', $batchValues);
        $pdo->prepare($sql)->execute($batchParams);
    }

    // Insert into upload_sessions table
    $stmtSession = $pdo->prepare("
        INSERT INTO `upload_sessions` (`uploaded_by`, `file_name`, `record_count`, `status`, `protection_password`, `notes`, `uploaded_at`)
        VALUES (?, ?, ?, 'success', ?, 'Pre-submission sanitized upload via officer portal', NOW())
    ");
    $stmtSession->execute([$uploadedBy, $baseFileName, $countInserted, $protectionPassword]);
    $sessionId = $pdo->lastInsertId();

    // Mark sandbox_draft as submitted so it leaves active draft list immediately
    if ($draftId > 0) {
        $stmtDraft = $pdo->prepare("UPDATE `sandbox_drafts` SET `status` = 'submitted', `last_edited_by` = ?, `updated_at` = NOW() WHERE `id` = ?");
        $stmtDraft->execute([$uploadedBy, $draftId]);
    }

    // Insert into uploaded_files tracking table
    $stmtFile = $pdo->prepare("INSERT IGNORE INTO `uploaded_files` (`file_name`) VALUES (?)");
    $stmtFile->execute([$baseFileName]);

    // Log to activity_logs
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $stmtLog = $pdo->prepare("
        INSERT INTO `activity_logs` (`user_id`, `username`, `action`, `description`, `ip_address`, `created_at`)
        VALUES (NULL, ?, 'upload', ?, ?, NOW())
    ");
    $stmtLog->execute([
        $uploadedBy,
        "Successfully submitted {$countInserted} sanitized compliance rows via Pre-Submission Sandbox. Protection key: {$protectionPassword}",
        $ip
    ]);

    $pdo->commit();

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Database transaction failed: ' . $e->getMessage()]);
    exit;
}

// 2. Generate Password-Locked Single-Sheet Excel Output
try {
    $exportRows = [];
    // Header Row
    $exportRows[] = [
        'Owner Name',
        'ID/Passport No',
        'Date of Birth',
        'Account Number',
        'Last Transaction Date/Time',
        'Due Amount',
        'Compilation Date'
    ];

    foreach ($rows as $r) {
        $exportRows[] = [
            (string)($r['owner_name'] ?? ''),
            (string)($r['id_passport_no'] ?? ''),
            (string)($r['date_of_birth'] ?? ''),
            (string)($r['account_number'] ?? ''),
            (string)($r['last_transaction'] ?? ''),
            (string)($r['due_amount'] ?? ''),
            (string)($r['compilation_date'] ?? '')
        ];
    }

    $uploadDir = __DIR__ . '/../uploads/generated_submissions/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }

    $targetPath = $uploadDir . $baseFileName;

    $xlsx = SimpleXLSXGen::fromArray($exportRows, 'UFAA Submission');
    $xlsx->setProtectionPassword($protectionPassword);
    $xlsx->saveAs($targetPath);

    $fileUrl = 'uploads/generated_submissions/' . $baseFileName;

    echo json_encode([
        'status'              => 'success',
        'message'             => "Successfully imported {$countInserted} clean compliance records!",
        'record_count'        => $countInserted,
        'file_name'           => $baseFileName,
        'file_url'            => $fileUrl,
        'protection_password' => $protectionPassword,
        'session_id'          => $sessionId
    ]);

} catch (Exception $ex) {
    // Database committed, return success with warning about file generation
    echo json_encode([
        'status'              => 'success',
        'message'             => "Database updated with {$countInserted} records, but Excel generation encountered an error: " . $ex->getMessage(),
        'record_count'        => $countInserted,
        'protection_password' => $protectionPassword,
        'session_id'          => $sessionId
    ]);
}
