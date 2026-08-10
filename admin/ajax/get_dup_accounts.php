<?php
/**
 * UFAA Admin AJAX — Duplicate Account Numbers
 * Returns records that share an account_number with at least one other record.
 * High-performance optimized for multi-million record datasets.
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
    // 1. Get total duplicate account count and total affected records in a single fast grouped pass
    $stats = $pdo->query("
        SELECT COUNT(*) AS distinct_dups, SUM(cnt) AS total_records FROM (
            SELECT COUNT(*) AS cnt
            FROM unclaimed_assets
            WHERE account_number IS NOT NULL AND account_number != ''
            GROUP BY account_number
            HAVING COUNT(*) > 1
        ) t
    ")->fetch(PDO::FETCH_ASSOC);

    $distinctDups = (int)($stats['distinct_dups'] ?? 0);
    $total        = (int)($stats['total_records'] ?? 0);

    // 2. Fetch paginated records using INNER JOIN derived table (leverages index idx_account_no)
    $stmt = $pdo->prepare("
        SELECT a.record_id, a.owner_name, a.id_passport_no, a.account_number,
               a.date_of_birth, a.last_transaction, a.due_amount, a.compilation_date, a.status
        FROM unclaimed_assets a
        INNER JOIN (
            SELECT account_number
            FROM unclaimed_assets
            WHERE account_number IS NOT NULL AND account_number != ''
            GROUP BY account_number
            HAVING COUNT(*) > 1
        ) d ON a.account_number = d.account_number
        ORDER BY a.account_number ASC, a.record_id ASC
        LIMIT :lim OFFSET :off
    ");
    $stmt->bindValue(':lim', $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':off', $offset,  PDO::PARAM_INT);
    $stmt->execute();

    echo json_encode([
        'success'      => true,
        'records'      => $stmt->fetchAll(PDO::FETCH_ASSOC),
        'total'        => $total,
        'distinct_dups'=> $distinctDups,
        'page'         => $page,
        'per_page'     => $perPage,
        'total_pages'  => max(1, (int)ceil($total / $perPage)),
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
