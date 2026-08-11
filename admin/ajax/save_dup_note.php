<?php
/**
 * UFAA Admin AJAX — Save/Update Duplicate Group Note
 * Saves an officer's review note for a confirmed true duplicate group.
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../includes/admin_auth.php';

$pdo = admin_get_pdo();
if (!$pdo) { echo json_encode(['success' => false, 'error' => 'DB error']); exit; }

$groupKey = trim($_POST['group_key'] ?? '');
$note     = trim($_POST['note']      ?? '');
$notedBy  = $_SESSION['admin_user']['fullname'] ?? $_SESSION['admin_user']['username'] ?? 'Admin';

if (!$groupKey) { echo json_encode(['success' => false, 'error' => 'Missing group key']); exit; }
if (!$note)     { echo json_encode(['success' => false, 'error' => 'Note cannot be empty']); exit; }

try {
    $stmt = $pdo->prepare("
        INSERT INTO duplicate_notes (group_key, note, noted_by)
        VALUES (:gk, :note, :by)
        ON DUPLICATE KEY UPDATE note = :note2, noted_by = :by2, noted_at = NOW()
    ");
    $stmt->execute([
        ':gk'    => $groupKey,
        ':note'  => $note,
        ':by'    => $notedBy,
        ':note2' => $note,
        ':by2'   => $notedBy,
    ]);

    // Log activity
    if (function_exists('log_activity')) {
        log_activity('Duplicate Note Saved', "Noted duplicate group: {$groupKey}");
    }

    echo json_encode(['success' => true]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
