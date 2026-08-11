<?php
/**
 * UFAA Admin AJAX — Live Quality Audit Counts
 * Returns updated counts for all quality cards, distinguishing True Duplicates vs Recycled SIMs.
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../includes/admin_auth.php';

$pdo = admin_get_pdo();
if (!$pdo) { echo json_encode(['success' => false, 'error' => 'DB error']); exit; }

try {
    $totalRecords   = (int)$pdo->query("SELECT COUNT(*) FROM unclaimed_assets")->fetchColumn();
    $missingOwner   = (int)$pdo->query("SELECT COUNT(*) FROM unclaimed_assets WHERE owner_name IS NULL OR owner_name = ''")->fetchColumn();
    $missingId      = (int)$pdo->query("SELECT COUNT(*) FROM unclaimed_assets WHERE id_passport_no IS NULL OR id_passport_no = ''")->fetchColumn();
    $missingDOB     = (int)$pdo->query("SELECT COUNT(*) FROM unclaimed_assets WHERE date_of_birth IS NULL")->fetchColumn();
    $missingAccount = (int)$pdo->query("SELECT COUNT(*) FROM unclaimed_assets WHERE account_number IS NULL OR account_number = ''")->fetchColumn();
    
    // True Duplicates (Unreviewed groups sharing account_number + id_passport_no + last_txn + batch)
    $trueDuplicates = (int)$pdo->query("
        SELECT COUNT(*) FROM (
            SELECT account_number, id_passport_no, last_transaction, compilation_date
            FROM unclaimed_assets
            WHERE account_number IS NOT NULL AND account_number != ''
              AND id_passport_no IS NOT NULL AND id_passport_no != ''
            GROUP BY account_number, id_passport_no, last_transaction, compilation_date
            HAVING COUNT(*) > 1
        ) t
        LEFT JOIN duplicate_notes dn ON dn.group_key = CONCAT(
            COALESCE(TRIM(t.account_number), ''), '___',
            COALESCE(TRIM(t.id_passport_no), ''), '___',
            COALESCE(TRIM(t.last_transaction), ''), '___',
            COALESCE(TRIM(t.compilation_date), '')
        )
        WHERE dn.reviewed IS NULL OR dn.reviewed = 0
    ")->fetchColumn();

    // Recycled SIM Accounts (Same account_number BUT Different Owners) -> Legitimate Info
    $recycledAccounts = (int)$pdo->query("
        SELECT COUNT(*) FROM (
            SELECT account_number
            FROM unclaimed_assets
            WHERE account_number IS NOT NULL AND account_number != ''
            GROUP BY account_number
            HAVING COUNT(DISTINCT COALESCE(NULLIF(TRIM(id_passport_no),''), owner_name)) > 1
        ) t
    ")->fetchColumn();

    $totalIssues = $missingOwner + $missingId + $missingDOB + $missingAccount + $trueDuplicates;

    echo json_encode([
        'success'           => true,
        'total'             => $totalRecords,
        'owner_name'        => $missingOwner,
        'id_passport_no'    => $missingId,
        'date_of_birth'     => $missingDOB,
        'account_number'    => $missingAccount,
        'true_duplicates'   => $trueDuplicates,
        'recycled_accounts' => $recycledAccounts,
        'total_issues'      => $totalIssues,
        'all_good'          => $totalIssues === 0,
    ]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
