<?php
/**
 * UFAA Admin AJAX — Get records with a missing critical field
 * GET params: field (owner_name | id_passport_no | date_of_birth | account_number)
 * Returns up to 200 records with that field null/empty.
 */

header('Content-Type: application/json');

require_once __DIR__ . '/../includes/admin_auth.php';

$pdo = admin_get_pdo();
if (!$pdo) { echo json_encode(['success' => false, 'error' => 'DB error']); exit; }

$allowed = ['owner_name', 'id_passport_no', 'date_of_birth', 'account_number'];
$field   = trim($_GET['field'] ?? '');

if (!in_array($field, $allowed)) {
    echo json_encode(['success' => false, 'error' => 'Invalid field']);
    exit;
}

try {
    // Build WHERE based on field type (date fields can only be NULL, text fields can also be empty string)
    if ($field === 'date_of_birth') {
        $where = "date_of_birth IS NULL";
    } else {
        $where = "$field IS NULL OR TRIM($field) = ''";
    }

    $stmt = $pdo->query(
        "SELECT id, owner_name, id_passport_no, account_number, date_of_birth, status
         FROM unclaimed_assets
         WHERE $where
         ORDER BY id ASC
         LIMIT 200"
    );

    echo json_encode([
        'success' => true,
        'records' => $stmt->fetchAll(),
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
