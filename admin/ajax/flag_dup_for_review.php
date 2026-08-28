<?php
/**
 * UFAA Admin AJAX — Flag a True Duplicate group for Uploader Review.
 * Finds which uploader submitted the records in this group, records the flag,
 * and creates/re-opens a working draft in sandbox_drafts with status 'flagged'
 * so the submitting officer can edit directly in the Web-Excel Editor!
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../includes/admin_auth.php';

$pdo = admin_get_pdo();
if (!$pdo) { echo json_encode(['success' => false, 'error' => 'DB error']); exit; }

$groupKey  = trim($_POST['group_key'] ?? '');
$flaggedBy = $_SESSION['admin_user']['fullname'] ?? $_SESSION['admin_user']['username'] ?? 'Admin';

if (!$groupKey) {
    echo json_encode(['success' => false, 'error' => 'Missing group key']);
    exit;
}

// Prevent re-flagging an already flagged group
$chkStmt = $pdo->prepare("SELECT reviewed FROM duplicate_notes WHERE group_key = ? LIMIT 1");
$chkStmt->execute([$groupKey]);
$isAlreadyFlagged = (int)$chkStmt->fetchColumn();

if ($isAlreadyFlagged === 1) {
    echo json_encode(['success' => false, 'error' => 'This group has already been flagged for uploader review.']);
    exit;
}

// Parse group key (Format: account_number___id_passport_no___last_transaction___compilation_date)
$parts = explode('___', $groupKey);
$accountNo  = trim($parts[0] ?? '');
$idPassport = trim($parts[1] ?? '');

// Fetch duplicate records from unclaimed_assets
$dupRecords = [];
if ($accountNo !== '' && $idPassport !== '') {
    $recStmt = $pdo->prepare("
        SELECT owner_name, id_passport_no, date_of_birth, account_number, last_transaction, due_amount, compilation_date
        FROM unclaimed_assets
        WHERE account_number = ? AND id_passport_no = ?
    ");
    $recStmt->execute([$accountNo, $idPassport]);
    $dupRecords = $recStmt->fetchAll(PDO::FETCH_ASSOC);
}

// Find the most recent uploader
$uploaderName = null;
try {
    $uploaderStmt = $pdo->prepare("SELECT uploaded_by FROM upload_sessions ORDER BY uploaded_at DESC LIMIT 1");
    $uploaderStmt->execute();
    $uploaderName = $uploaderStmt->fetchColumn();
} catch (Exception $e) { /* silent */ }

if (!$uploaderName) {
    $uploaderName = 'Officer';
}

$flagReason = "Admin flagged potential duplicate claim (Account: {$accountNo}, ID: {$idPassport}) for verification.";

// Check if a sandbox draft already exists for this uploader, or create/re-open one
$draftId = 0;
try {
    $existingDraftStmt = $pdo->prepare("
        SELECT id FROM sandbox_drafts
        WHERE created_by = ? AND (draft_name LIKE ? OR draft_name LIKE '%Duplicate%')
        ORDER BY updated_at DESC LIMIT 1
    ");
    $existingDraftStmt->execute([$uploaderName, "%{$accountNo}%"]);
    $draftId = (int)$existingDraftStmt->fetchColumn();

    if ($draftId > 0) {
        // Re-open existing draft and mark as flagged
        $upd = $pdo->prepare("UPDATE sandbox_drafts SET status = 'flagged', flagged_reason = ?, updated_at = NOW() WHERE id = ?");
        $upd->execute([$flagReason, $draftId]);
    } else {
        // Create a new working draft with the flagged duplicate records so the uploader can edit directly
        $draftName = "Flagged Duplicate Review — Account " . ($accountNo ?: 'Report');
        $rowsData  = json_encode(!empty($dupRecords) ? $dupRecords : [
            [
                'owner_name' => '', 'id_passport_no' => $idPassport,
                'date_of_birth' => '', 'account_number' => $accountNo,
                'last_transaction' => '', 'due_amount' => '', 'compilation_date' => ''
            ]
        ]);

        $ins = $pdo->prepare("
            INSERT INTO sandbox_drafts (`draft_name`, `created_by`, `last_edited_by`, `rows_data`, `status`, `flagged_reason`, `created_at`, `updated_at`)
            VALUES (?, ?, ?, ?, 'flagged', ?, NOW(), NOW())
        ");
        $ins->execute([$draftName, $uploaderName, $uploaderName, $rowsData, $flagReason]);
        $draftId = (int)$pdo->lastInsertId();
    }
} catch (Exception $ex) { /* silent draft creation fallback */ }

try {
    $now = date('Y-m-d H:i:s');
    $stmt = $pdo->prepare("
        INSERT INTO duplicate_notes (group_key, note, noted_by, reviewed, reviewed_by, reviewed_at)
        VALUES (:gk, :note, :by, 1, :by2, :at)
        ON DUPLICATE KEY UPDATE
            note        = :note2,
            noted_by    = :by3,
            reviewed    = 1,
            reviewed_by = :by4,
            reviewed_at = :at2
    ");
    $stmt->execute([
        ':gk'    => $groupKey,
        ':note'  => 'Flagged for Uploader Review',
        ':by'    => $flaggedBy,
        ':by2'   => $flaggedBy,
        ':at'    => $now,
        ':note2' => 'Flagged for Uploader Review',
        ':by3'   => $flaggedBy,
        ':by4'   => $flaggedBy,
        ':at2'   => $now,
    ]);

    if (function_exists('log_activity')) {
        log_activity('Duplicate Flagged', "Group {$groupKey} flagged for uploader review by {$flaggedBy}");
    }

    echo json_encode([
        'success'    => true,
        'uploader'   => $uploaderName,
        'flagged_by' => $flaggedBy,
        'group_key'  => $groupKey,
        'draft_id'   => $draftId
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
