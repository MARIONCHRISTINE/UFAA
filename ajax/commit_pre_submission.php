<?php
ob_start();
error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING);
ini_set('display_errors', '0');

ini_set('memory_limit', '1024M');
set_time_limit(600);

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
$data = json_decode($rawInput, true) ?: [];

$draftId = (int)($data['draft_id'] ?? $_POST['draft_id'] ?? 0);
$rows = [];

if (isset($data['rows']) && is_array($data['rows']) && !empty($data['rows'])) {
    $rows = $data['rows'];
} elseif ($draftId > 0) {
    // Load directly from disk or database to prevent large HTTP payloads
    $diskPath = __DIR__ . '/../uploads/drafts/draft_' . $draftId . '.json';
    if (file_exists($diskPath)) {
        $content = @file_get_contents($diskPath);
        if ($content !== false && $content !== '') {
            $rows = json_decode($content, true) ?: [];
        }
    }
    if (empty($rows)) {
        $stmtDraftRows = $pdo->prepare("SELECT `rows_data` FROM `sandbox_drafts` WHERE `id` = ?");
        $stmtDraftRows->execute([$draftId]);
        $draftRecord = $stmtDraftRows->fetch(PDO::FETCH_ASSOC);
        if ($draftRecord) {
            $rows = json_decode($draftRecord['rows_data'] ?? '[]', true) ?: [];
        }
    }
}

if (empty($rows)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'No clean rows found for submission.']);
    exit;
}
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

    // Immediately lock the draft in database so no concurrent edits can occur while uploading
    $lockDraftStmt = $pdo->prepare("UPDATE `sandbox_drafts` SET `status` = 'submitted', `last_edited_by` = ?, `updated_at` = NOW() WHERE `id` = ?");
    $lockDraftStmt->execute([$uploadedBy, $draftId]);
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
    $batchSize = 1000;
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

        // Normalize date of birth to YYYY-MM-DD (or NULL) to support hosted DATE column
        $formattedDob = null;
        if ($dob !== '') {
            $parsedDob = date_create($dob);
            if ($parsedDob) {
                $formattedDob = date_format($parsedDob, 'Y-m-d');
            }
        }

        // Normalize compilation date to YYYY-MM-DD (fallback to today if missing/invalid to satisfy DB constraints)
        $formattedDate = null;
        if ($compilation !== '') {
            $parsed = date_create($compilation);
            if ($parsed) {
                $formattedDate = date_format($parsed, 'Y-m-d');
            }
        }
        if ($formattedDate === null) {
            $formattedDate = date('Y-m-d');
        }

        $cleanDue = preg_replace('/[^\d\.\-]/', '', $dueAmount);
        if ($cleanDue === '' || !is_numeric($cleanDue)) {
            $cleanDue = '0.00';
        }

        $batchValues[] = "(?, ?, ?, ?, ?, ?, ?, 'Unclaimed', 'No', NOW())";
        $batchParams[] = $ownerName !== '' ? $ownerName : 'Unknown';
        $batchParams[] = $idPassport !== '' ? $idPassport : null;
        $batchParams[] = $formattedDob;
        $batchParams[] = $accountNo !== '' ? $accountNo : 'N/A';
        $batchParams[] = $lastTx !== '' ? $lastTx : null;
        $batchParams[] = $cleanDue;
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

} catch (\Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Database transaction failed: ' . $e->getMessage()]);
    exit;
}

// 2. Generate Password-Locked Single-Sheet Excel Output
try {
    $uploadDir = __DIR__ . '/../uploads/generated_submissions/';
    if (!is_dir($uploadDir)) {
        @mkdir($uploadDir, 0777, true);
    }
    @chmod($uploadDir, 0777);

    $targetPath = $uploadDir . $baseFileName;

    $header = [
        'Owner Name',
        'ID/Passport No',
        'Date of Birth',
        'Account Number',
        'Last Transaction Date/Time',
        'Due Amount',
        'Compilation Date'
    ];

    $xlsx = SimpleXLSXGen::fromArrayWithHeader($header, $rows, 'UFAA Submission');
    $xlsx->setProtectionPassword($protectionPassword);
    $saved = $xlsx->saveAs($targetPath);

    if ($saved && file_exists($targetPath) && filesize($targetPath) > 0) {
        $fileUrl = 'download_submission.php?file=' . urlencode($baseFileName);
        echo json_encode([
            'status'              => 'success',
            'has_file'            => true,
            'message'             => "Successfully imported {$countInserted} clean compliance records!",
            'record_count'        => $countInserted,
            'file_name'           => $baseFileName,
            'file_url'            => $fileUrl,
            'protection_password' => $protectionPassword,
            'session_id'          => $sessionId
        ]);
    } else {
        throw new \Exception("Unable to write generated file to disk. Check folder permissions on uploads/generated_submissions.");
    }

} catch (\Throwable $ex) {
    // Database committed, return success with warning about file generation
    echo json_encode([
        'status'              => 'success',
        'has_file'            => false,
        'message'             => "Database updated with {$countInserted} records, but locked Excel generation failed: " . $ex->getMessage(),
        'record_count'        => $countInserted,
        'file_name'           => null,
        'file_url'            => null,
        'protection_password' => $protectionPassword,
        'session_id'          => $sessionId
    ]);
}
