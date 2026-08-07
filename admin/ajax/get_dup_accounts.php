<?php
/**
 * UFAA Admin AJAX — Duplicate Account Numbers
 * Returns records that share an account_number with at least one other record.
 * Supports pagination: page, per_page (default 100)
 */

header('Content-Type: application/json');

require_once __DIR__ . '/../includes/admin_auth.php';

$pdo = admin_get_pdo();
if (!$pdo) { echo json_encode(['success' => false, 'error' => 'DB error']); exit; }

$page    = max(1, (int)($_GET['page']     ?? 1));
$perPage = min(500, max(25, (int)($_GET['per_page'] ?? 100)));
$offset  = ($page - 1) * $perPage;

try {
    // Count total records involved in duplicate account numbers
    $total = (int)$pdo->query("
        SELECT COUNT(*) FROM unclaimed_assets
        WHERE account_number IS NOT NULL
          AND TRIM(account_number) != ''
          AND account_number IN (
              SELECT account_number FROM unclaimed_assets
              WHERE account_number IS NOT NULL AND TRIM(account_number) != ''
              GROUP BY account_number HAVING COUNT(*) > 1
          )
    ")->fetchColumn();

    // Count distinct duplicate account numbers
    $distinctDups = (int)$pdo->query("
        SELECT COUNT(*) FROM (
            SELECT account_number FROM unclaimed_assets
            WHERE account_number IS NOT NULL AND TRIM(account_number) != ''
            GROUP BY account_number HAVING COUNT(*) > 1
        ) t
    ")->fetchColumn();

    // Paginated records
    $stmt = $pdo->prepare("
        SELECT record_id, owner_name, id_passport_no, account_number,
               date_of_birth, last_transaction, due_amount, compilation_date, status
        FROM unclaimed_assets
        WHERE account_number IS NOT NULL
          AND TRIM(account_number) != ''
          AND account_number IN (
              SELECT account_number FROM unclaimed_assets
              WHERE account_number IS NOT NULL AND TRIM(account_number) != ''
              GROUP BY account_number HAVING COUNT(*) > 1
          )
        ORDER BY account_number ASC, record_id ASC
        LIMIT :lim OFFSET :off
    ");
    $stmt->bindValue(':lim', $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':off', $offset,  PDO::PARAM_INT);
    $stmt->execute();

    echo json_encode([
        'success'      => true,
        'records'      => $stmt->fetchAll(),
        'total'        => $total,
        'distinct_dups'=> $distinctDups,
        'page'         => $page,
        'per_page'     => $perPage,
        'total_pages'  => max(1, (int)ceil($total / $perPage)),
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
