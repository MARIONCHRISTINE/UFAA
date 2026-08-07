<?php
/**
 * UFAA Admin AJAX — Paginated records with a missing critical field
 * GET params: field, page, per_page (default 100)
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

$page    = max(1, (int)($_GET['page']     ?? 1));
$perPage = min(500, max(25, (int)($_GET['per_page'] ?? 100)));
$offset  = ($page - 1) * $perPage;

try {
    $where = $field === 'date_of_birth'
        ? "date_of_birth IS NULL"
        : "$field IS NULL OR TRIM($field) = ''";

    $total = (int)$pdo->query("SELECT COUNT(*) FROM unclaimed_assets WHERE $where")->fetchColumn();

    $stmt = $pdo->prepare(
        "SELECT record_id, owner_name, id_passport_no, account_number, date_of_birth,
                last_transaction, due_amount, compilation_date, status,
                letter_generated, letter_received, uploaded_at
         FROM unclaimed_assets
         WHERE $where
         ORDER BY record_id ASC
         LIMIT :lim OFFSET :off"
    );
    $stmt->bindValue(':lim', $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':off', $offset,  PDO::PARAM_INT);
    $stmt->execute();

    echo json_encode([
        'success'     => true,
        'records'     => $stmt->fetchAll(),
        'total'       => $total,
        'page'        => $page,
        'per_page'    => $perPage,
        'total_pages' => max(1, (int)ceil($total / $perPage)),
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
