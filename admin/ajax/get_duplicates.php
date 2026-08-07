<?php
/**
 * UFAA Admin AJAX — Duplicate Detection
 * Returns records with duplicate id_passport_no values.
 */

header('Content-Type: application/json');

require_once __DIR__ . '/../includes/admin_auth.php';

$pdo = admin_get_pdo();
if (!$pdo) { echo json_encode(['success' => false, 'error' => 'DB error']); exit; }

try {
    $stmt = $pdo->query("
        SELECT id_passport_no, COUNT(*) as cnt
        FROM unclaimed_assets
        WHERE id_passport_no IS NOT NULL AND TRIM(id_passport_no) != ''
        GROUP BY id_passport_no
        HAVING COUNT(*) > 1
        ORDER BY cnt DESC
        LIMIT 50
    ");

    $rows = $stmt->fetchAll();

    echo json_encode(['success' => true, 'duplicates' => $rows]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
