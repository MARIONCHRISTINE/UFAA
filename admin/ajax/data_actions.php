<?php
/**
 * UFAA Admin AJAX — Data Actions
 * Handles: remove_file (from upload registry), optimise
 */

header('Content-Type: application/json');

require_once __DIR__ . '/../includes/admin_auth.php';

if ($adminUser['role'] !== 'compliance_admin') {
    echo json_encode(['success' => false, 'error' => 'Insufficient permissions']);
    exit;
}

$pdo    = admin_get_pdo();
$action = trim($_POST['action'] ?? '');

if (!$pdo) { echo json_encode(['success' => false, 'error' => 'DB error']); exit; }

try {
    switch ($action) {

        // ── Remove a file from the uploaded_files registry ────────────────
        case 'remove_file':
            $fileId = (int)($_POST['file_id'] ?? 0);
            if (!$fileId) {
                echo json_encode(['success' => false, 'error' => 'Invalid file ID']);
                break;
            }
            // Fetch name for logging before delete
            $nameRow = $pdo->prepare("SELECT file_name FROM uploaded_files WHERE id = ?");
            $nameRow->execute([$fileId]);
            $fileName = $nameRow->fetchColumn() ?: "ID #$fileId";

            $pdo->prepare("DELETE FROM uploaded_files WHERE id = ?")->execute([$fileId]);
            log_activity('file_registry_remove', "Removed '$fileName' from upload registry (allows re-upload)");
            echo json_encode(['success' => true]);
            break;

        // ── Optimise database ─────────────────────────────────────────────
        case 'optimise':
            @$pdo->exec("ANALYZE TABLE unclaimed_assets");
            @$pdo->exec("OPTIMIZE TABLE unclaimed_assets");
            log_activity('optimise_db', 'Ran ANALYZE and OPTIMIZE on unclaimed_assets');
            echo json_encode(['success' => true]);
            break;

        default:
            echo json_encode(['success' => false, 'error' => 'Unknown action']);
    }

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
