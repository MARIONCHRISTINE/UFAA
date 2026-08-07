<?php
/**
 * UFAA Admin AJAX — Upload Audit Trail
 * Returns paginated upload sessions and per-session stats.
 */

header('Content-Type: application/json');

require_once __DIR__ . '/../includes/admin_auth.php';

$pdo = admin_get_pdo();
if (!$pdo) { echo json_encode(['success' => false, 'error' => 'DB error']); exit; }

$page    = max(1, (int)($_GET['page']     ?? 1));
$perPage = min(100, max(10, (int)($_GET['per_page'] ?? 25)));
$search  = trim($_GET['search'] ?? '');
$offset  = ($page - 1) * $perPage;

$where  = [];
$params = [];

if ($search !== '') {
    $where[]              = "(uploaded_by LIKE :s OR file_name LIKE :s2)";
    $params[':s']         = '%' . $search . '%';
    $params[':s2']        = '%' . $search . '%';
}

$whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

try {
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM upload_sessions $whereSQL");
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();

    $stmt = $pdo->prepare(
        "SELECT id, uploaded_by, file_name, record_count, status, notes, uploaded_at
         FROM upload_sessions $whereSQL
         ORDER BY uploaded_at DESC
         LIMIT :limit OFFSET :offset"
    );
    foreach ($params as $k => $v) $stmt->bindValue($k, $v);
    $stmt->bindValue(':limit',  $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset,  PDO::PARAM_INT);
    $stmt->execute();

    $rows = $stmt->fetchAll();

    // Summary totals
    $summaryStmt = $pdo->query("SELECT COUNT(*) as sessions, COALESCE(SUM(record_count),0) as total_records FROM upload_sessions");
    $summary = $summaryStmt->fetch();

    echo json_encode([
        'success'     => true,
        'sessions'    => $rows,
        'total'       => $total,
        'page'        => $page,
        'per_page'    => $perPage,
        'total_pages' => max(1, (int)ceil($total / $perPage)),
        'summary'     => $summary,
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
