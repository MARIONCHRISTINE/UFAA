<?php
/**
 * UFAA Admin AJAX — Paginated Activity Logs
 * GET params: page, per_page, search, action_filter, user_filter, date_from, date_to
 */

header('Content-Type: application/json');

require_once __DIR__ . '/../includes/admin_auth.php';

$pdo = admin_get_pdo();
if (!$pdo) { echo json_encode(['success' => false, 'error' => 'DB error']); exit; }

$page         = max(1, (int)($_GET['page']          ?? 1));
$perPage      = min(500, max(1, (int)($_GET['per_page'] ?? 100))); // Default 100 per page
$search       = trim($_GET['search']             ?? '');
$actionFilter = trim($_GET['action_filter']     ?? '');
$userFilter   = trim($_GET['user_filter']       ?? '');
$dateFrom     = trim($_GET['date_from']         ?? '');
$dateTo       = trim($_GET['date_to']           ?? '');

$offset       = ($page - 1) * $perPage;

$where  = [];
$params = [];

if ($search !== '') {
    $where[]            = "(username LIKE :search OR description LIKE :search2 OR ip_address LIKE :search3 OR record_id LIKE :search4)";
    $params[':search']  = '%' . $search . '%';
    $params[':search2'] = '%' . $search . '%';
    $params[':search3'] = '%' . $search . '%';
    $params[':search4'] = '%' . $search . '%';
}

if ($actionFilter !== '') {
    $where[]            = "action = :action";
    $params[':action']  = $actionFilter;
}

if ($userFilter !== '') {
    $where[]            = "username = :uname";
    $params[':uname']   = $userFilter;
}

if ($dateFrom !== '') {
    $where[]            = "DATE(created_at) >= :dfrom";
    $params[':dfrom']   = $dateFrom;
}

if ($dateTo !== '') {
    $where[]            = "DATE(created_at) <= :dto";
    $params[':dto']     = $dateTo;
}

$whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

try {
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM activity_logs $whereSQL");
    $countStmt->execute($params);
    $total = (int) $countStmt->fetchColumn();

    $stmt = $pdo->prepare(
        "SELECT id, user_id, username, action, description, record_id, ip_address, created_at
         FROM activity_logs $whereSQL
         ORDER BY created_at DESC
         LIMIT :limit OFFSET :offset"
    );
    foreach ($params as $k => $v) $stmt->bindValue($k, $v);
    $stmt->bindValue(':limit',  $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset,  PDO::PARAM_INT);
    $stmt->execute();

    $rows = $stmt->fetchAll();

    // Distinct actions for filter dropdown
    $actions = $pdo->query("SELECT DISTINCT action FROM activity_logs ORDER BY action")->fetchAll(PDO::FETCH_COLUMN);

    // Distinct users for filter dropdown
    $users = $pdo->query("SELECT DISTINCT username FROM activity_logs WHERE username IS NOT NULL AND username != '' ORDER BY username")->fetchAll(PDO::FETCH_COLUMN);

    echo json_encode([
        'success'      => true,
        'logs'         => $rows,
        'total'        => $total,
        'page'         => $page,
        'per_page'     => $perPage,
        'total_pages'  => max(1, (int)ceil($total / $perPage)),
        'action_list'  => $actions,
        'user_list'    => $users,
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
