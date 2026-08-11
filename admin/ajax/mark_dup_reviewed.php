<?php
/**
 * UFAA Admin AJAX — Save audit resolution and lock True Duplicate group.
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../includes/admin_auth.php';

$pdo = admin_get_pdo();
if (!$pdo) { echo json_encode(['success' => false, 'error' => 'DB error']); exit; }

$groupKey   = trim($_POST['group_key']   ?? '');
$resolution = trim($_POST['resolution']  ?? $_POST['note'] ?? '');
$notedBy    = $_SESSION['admin_user']['fullname'] ?? $_SESSION['admin_user']['username'] ?? 'Admin';

if (!$groupKey) { 
    echo json_encode(['success' => false, 'error' => 'Missing group key']); 
    exit; 
}

$allowedResolutions = ['Confirmed True Duplicate', 'False Duplicate / Cleared'];
if (!in_array($resolution, $allowedResolutions)) {
    echo json_encode(['success' => false, 'error' => 'Please select a valid audit decision (Confirmed True Duplicate or False Duplicate / Cleared)']);
    exit;
}

// Prevent modifying an already reviewed and locked group
$chkStmt = $pdo->prepare("SELECT reviewed FROM duplicate_notes WHERE group_key = ? LIMIT 1");
$chkStmt->execute([$groupKey]);
$isAlreadyRev = (int)$chkStmt->fetchColumn();

if ($isAlreadyRev === 1) {
    echo json_encode(['success' => false, 'error' => 'This duplicate claim has already been reviewed and locked']);
    exit;
}

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
        ':note'  => $resolution,
        ':by'    => $notedBy,
        ':by2'   => $notedBy,
        ':at'    => $now,
        ':note2' => $resolution,
        ':by3'   => $notedBy,
        ':by4'   => $notedBy,
        ':at2'   => $now,
    ]);

    if (function_exists('log_activity')) {
        log_activity('Duplicate Audit Resolved', "Group {$groupKey} resolved as: {$resolution}");
    }

    echo json_encode(['success' => true, 'resolution' => $resolution]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

