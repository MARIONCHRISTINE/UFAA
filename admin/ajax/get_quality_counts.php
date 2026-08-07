<?php
/**
 * UFAA Admin AJAX — Live Quality Audit Counts
 * Returns updated counts for all 5 quality cards.
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../includes/admin_auth.php';

$pdo = admin_get_pdo();
if (!$pdo) { echo json_encode(['success' => false, 'error' => 'DB error']); exit; }

try {
    $totalRecords   = (int)$pdo->query("SELECT COUNT(*) FROM unclaimed_assets")->fetchColumn();
    $missingOwner   = (int)$pdo->query("SELECT COUNT(*) FROM unclaimed_assets WHERE owner_name IS NULL OR TRIM(owner_name)=''")->fetchColumn();
    $missingId      = (int)$pdo->query("SELECT COUNT(*) FROM unclaimed_assets WHERE id_passport_no IS NULL OR TRIM(id_passport_no)=''")->fetchColumn();
    $missingDOB     = (int)$pdo->query("SELECT COUNT(*) FROM unclaimed_assets WHERE date_of_birth IS NULL")->fetchColumn();
    $missingAccount = (int)$pdo->query("SELECT COUNT(*) FROM unclaimed_assets WHERE account_number IS NULL OR TRIM(account_number)=''")->fetchColumn();
    $dupAccounts    = (int)$pdo->query("SELECT COUNT(*) FROM (SELECT account_number FROM unclaimed_assets WHERE account_number IS NOT NULL AND TRIM(account_number) != '' GROUP BY account_number HAVING COUNT(*) > 1) t")->fetchColumn();

    echo json_encode([
        'success'         => true,
        'total'           => $totalRecords,
        'owner_name'      => $missingOwner,
        'id_passport_no'  => $missingId,
        'date_of_birth'   => $missingDOB,
        'account_number'  => $missingAccount,
        'dup_accounts'    => $dupAccounts,
        'all_good'        => ($missingOwner + $missingId + $missingDOB + $missingAccount + $dupAccounts) === 0,
    ]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
