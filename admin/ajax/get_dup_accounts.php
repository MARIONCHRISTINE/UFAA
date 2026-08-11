<?php
/**
 * UFAA Admin AJAX — Duplicate Account Numbers & Recycled Accounts
 * Supports mode:
 *   - 'true_dup' (default): Same account_number AND same id_passport_no (True Duplicate Uploads)
 *   - 'recycled': Same account_number BUT different id_passport_no / owner names (Recycled SIM Accounts)
 * High-performance optimized for multi-million record datasets.
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../includes/admin_auth.php';

$pdo = admin_get_pdo();
if (!$pdo) { echo json_encode(['success' => false, 'error' => 'DB error']); exit; }

$mode    = trim($_GET['mode']    ?? 'true_dup');
$page    = max(1, (int)($_GET['page']     ?? 1));
$perPage = min(500, max(25, (int)($_GET['per_page'] ?? 100)));
$search  = trim($_GET['search']  ?? '');
$filter  = trim($_GET['filter']  ?? 'all');
$offset  = ($page - 1) * $perPage;

try {
    if ($mode === 'recycled') {
        // ── RECYCLED SIM ACCOUNTS (Same account_number, DIFFERENT id_passport_no / owners) ──
        $whereSearch = "";
        $queryParams = [];
        if ($search !== '') {
            $whereSearch = " WHERE (a.account_number LIKE :s1 OR a.id_passport_no LIKE :s2 OR a.owner_name LIKE :s3 OR a.record_id LIKE :s4)";
            $queryParams[':s1'] = "%$search%";
            $queryParams[':s2'] = "%$search%";
            $queryParams[':s3'] = "%$search%";
            $queryParams[':s4'] = "%$search%";
        }

        $statsStmt = $pdo->prepare("
            SELECT COUNT(DISTINCT a.account_number) AS distinct_dups, COUNT(*) AS total_records
            FROM unclaimed_assets a
            INNER JOIN (
                SELECT account_number
                FROM unclaimed_assets
                WHERE account_number IS NOT NULL AND account_number != ''
                GROUP BY account_number
                HAVING COUNT(DISTINCT COALESCE(NULLIF(TRIM(id_passport_no),''), owner_name)) > 1
            ) d ON a.account_number = d.account_number
            {$whereSearch}
        ");
        $statsStmt->execute($queryParams);
        $stats = $statsStmt->fetch(PDO::FETCH_ASSOC);

        $distinctDups = (int)($stats['distinct_dups'] ?? 0);
        $total        = (int)($stats['total_records'] ?? 0);

        $stmt = $pdo->prepare("
            SELECT a.record_id, a.owner_name, a.id_passport_no, a.account_number,
                   a.date_of_birth, a.last_transaction, a.due_amount, a.compilation_date, a.status
            FROM unclaimed_assets a
            INNER JOIN (
                SELECT account_number
                FROM unclaimed_assets
                WHERE account_number IS NOT NULL AND account_number != ''
                GROUP BY account_number
                HAVING COUNT(DISTINCT COALESCE(NULLIF(TRIM(id_passport_no),''), owner_name)) > 1
            ) d ON a.account_number = d.account_number
            {$whereSearch}
            ORDER BY a.account_number ASC, a.record_id ASC
            LIMIT :lim OFFSET :off
        ");

        foreach ($queryParams as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->bindValue(':lim', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':off', $offset,  PDO::PARAM_INT);
        $stmt->execute();

        $records = $stmt->fetchAll(PDO::FETCH_ASSOC);

    } else {
        // ── DUPLICATE CLAIMS ──
        $whereClause = " WHERE 1=1";
        $queryParams = [];

        if ($filter === 'unreviewed') {
            $whereClause .= " AND (dn.reviewed IS NULL OR dn.reviewed = 0)";
        } elseif ($filter === 'confirmed') {
            $whereClause .= " AND (dn.reviewed = 1 AND (dn.note = 'Confirmed True Duplicate' OR dn.note LIKE '%Confirmed%' OR dn.note IS NULL OR dn.note = '' OR dn.note NOT LIKE '%False%'))";
        } elseif ($filter === 'cleared') {
            $whereClause .= " AND (dn.reviewed = 1 AND (dn.note = 'False Duplicate / Cleared' OR dn.note LIKE '%False%' OR dn.note LIKE '%Cleared%'))";
        }

        if ($search !== '') {
            $whereClause .= " AND (a.account_number LIKE :s1 OR a.id_passport_no LIKE :s2 OR a.owner_name LIKE :s3 OR a.record_id LIKE :s4)";
            $queryParams[':s1'] = "%$search%";
            $queryParams[':s2'] = "%$search%";
            $queryParams[':s3'] = "%$search%";
            $queryParams[':s4'] = "%$search%";
        }

        $groupKeyConcat = "CONCAT(COALESCE(TRIM(d.account_number),''),'___',COALESCE(TRIM(d.id_passport_no),''),'___',COALESCE(TRIM(d.last_transaction),''),'___',COALESCE(TRIM(d.compilation_date),''))";

        $statsStmt = $pdo->prepare("
            SELECT COUNT(DISTINCT {$groupKeyConcat}) AS distinct_dups,
                   COUNT(*) AS total_records
            FROM unclaimed_assets a
            INNER JOIN (
                SELECT account_number, id_passport_no, last_transaction, compilation_date
                FROM unclaimed_assets
                WHERE account_number IS NOT NULL AND account_number != ''
                  AND id_passport_no IS NOT NULL AND id_passport_no != ''
                GROUP BY account_number, id_passport_no, last_transaction, compilation_date
                HAVING COUNT(*) > 1
            ) d ON a.account_number = d.account_number
               AND a.id_passport_no = d.id_passport_no
               AND (a.last_transaction = d.last_transaction OR (a.last_transaction IS NULL AND d.last_transaction IS NULL))
               AND (a.compilation_date = d.compilation_date OR (a.compilation_date IS NULL AND d.compilation_date IS NULL))
            LEFT JOIN duplicate_notes dn ON dn.group_key = {$groupKeyConcat}
            {$whereClause}
        ");
        $statsStmt->execute($queryParams);
        $stats = $statsStmt->fetch(PDO::FETCH_ASSOC);

        $distinctDups = (int)($stats['distinct_dups'] ?? 0);
        $total        = (int)($stats['total_records'] ?? 0);

        $stmt = $pdo->prepare("
            SELECT a.record_id, a.owner_name, a.id_passport_no, a.account_number,
                   a.date_of_birth, a.last_transaction, a.due_amount, a.compilation_date, a.status
            FROM unclaimed_assets a
            INNER JOIN (
                SELECT account_number, id_passport_no, last_transaction, compilation_date
                FROM unclaimed_assets
                WHERE account_number IS NOT NULL AND account_number != ''
                  AND id_passport_no IS NOT NULL AND id_passport_no != ''
                GROUP BY account_number, id_passport_no, last_transaction, compilation_date
                HAVING COUNT(*) > 1
            ) d ON a.account_number = d.account_number
               AND a.id_passport_no = d.id_passport_no
               AND (a.last_transaction = d.last_transaction OR (a.last_transaction IS NULL AND d.last_transaction IS NULL))
               AND (a.compilation_date = d.compilation_date OR (a.compilation_date IS NULL AND d.compilation_date IS NULL))
            LEFT JOIN duplicate_notes dn ON dn.group_key = {$groupKeyConcat}
            {$whereClause}
            ORDER BY a.account_number ASC, a.id_passport_no ASC, a.last_transaction ASC, a.compilation_date ASC, a.record_id ASC
            LIMIT :lim OFFSET :off
        ");

        foreach ($queryParams as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->bindValue(':lim', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':off', $offset,  PDO::PARAM_INT);
        $stmt->execute();

        $records = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // Fetch existing notes + reviewed status for true_dup mode
    $notes = [];
    if ($mode !== 'recycled') {
        try {
            $noteStmt = $pdo->query("SELECT group_key, note, noted_by, noted_at, reviewed, reviewed_by, reviewed_at FROM duplicate_notes");
            foreach ($noteStmt->fetchAll(PDO::FETCH_ASSOC) as $n) {
                $notes[trim($n['group_key'])] = $n;
            }
        } catch (Exception $e) { /* table may not exist yet */ }
    }

    echo json_encode([
        'success'      => true,
        'mode'         => $mode,
        'records'      => $records,
        'notes'        => $notes,
        'total'        => $total,
        'distinct_dups'=> $distinctDups,
        'page'         => $page,
        'per_page'     => $perPage,
        'total_pages'  => max(1, (int)ceil($total / $perPage)),
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
