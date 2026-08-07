<?php
/**
 * UFAA Admin — Export Missing / Duplicate Records as CSV
 * Supports: mode=missing_owner | missing_id | missing_dob | missing_account | dup_accounts
 * All data columns included (mirrors main portal export.php).
 * Chunked download support for millions of rows.
 */

require_once __DIR__ . '/../includes/admin_auth.php';

$pdo = admin_get_pdo();
if (!$pdo) { die('Database unavailable'); }

$mode   = trim($_GET['mode'] ?? '');
$limit  = isset($_GET['limit'])  ? max(1, (int)$_GET['limit'])  : 0;
$offset = isset($_GET['offset']) ? max(0, (int)$_GET['offset']) : 0;

$allowedModes = ['missing_owner', 'missing_id', 'missing_dob', 'missing_account', 'dup_accounts'];
if (!in_array($mode, $allowedModes)) {
    header('Content-Type: application/json');
    echo json_encode(['status' => 'error', 'error' => 'Invalid export mode']);
    exit;
}

// Return count only (JSON) for pre-download size estimation
if (isset($_GET['get_count']) && $_GET['get_count'] == '1') {
    try {
        $where = _build_where($mode);
        $count = (int)$pdo->query("SELECT COUNT(*) FROM unclaimed_assets WHERE $where")->fetchColumn();
        header('Content-Type: application/json');
        echo json_encode(['status' => 'success', 'count' => $count]);
        exit;
    } catch (Exception $e) {
        header('Content-Type: application/json');
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        exit;
    }
}

try {
    $where    = _build_where($mode);
    $limitSQL = $limit > 0 ? " LIMIT :lim OFFSET :off" : '';

    $stmt = $pdo->prepare(
        "SELECT record_id, owner_name, id_passport_no, date_of_birth,
                account_number, last_transaction, due_amount, compilation_date,
                status, letter_generated, letter_received, letter_date,
                letter_generated_date, uploaded_at
         FROM unclaimed_assets
         WHERE $where
         ORDER BY account_number ASC, record_id ASC" . $limitSQL
    );

    if ($limit > 0) {
        $stmt->bindValue(':lim', $limit,  PDO::PARAM_INT);
        $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
    }
    $stmt->execute();

    // File naming
    $chunkNum    = isset($_GET['chunk_num'])    ? (int)$_GET['chunk_num']    : 0;
    $totalChunks = isset($_GET['total_chunks']) ? (int)$_GET['total_chunks'] : 0;
    $suffix      = ($chunkNum > 0 && $totalChunks > 0) ? "_Part{$chunkNum}_of_{$totalChunks}" : '';

    $modeLabel = [
        'missing_owner'  => 'Missing_Owner_Name',
        'missing_id'     => 'Missing_ID_Passport',
        'missing_dob'    => 'Missing_Date_of_Birth',
        'missing_account'=> 'Missing_Account_Number',
        'dup_accounts'   => 'Duplicate_Account_Numbers',
    ][$mode];

    $filename = "UFAA_Admin_{$modeLabel}_" . date('Ymd_His') . $suffix . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF)); // UTF-8 BOM for Excel

    // Full column headers — matches main portal export
    fputcsv($out, [
        'Record ID', 'Owner Name', 'ID / Passport No', 'Date of Birth',
        'Account Number', 'Last Transaction', 'Due Amount', 'Compilation Date',
        'Status', 'Letter Generated', 'Letter Received', 'Letter Date',
        'Letter Generated Date', 'Uploaded At'
    ]);

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        fputcsv($out, [
            $row['record_id'],
            $row['owner_name'],
            $row['id_passport_no'],
            $row['date_of_birth'],
            $row['account_number'],
            $row['last_transaction'],
            $row['due_amount'],
            $row['compilation_date'],
            $row['status'],
            $row['letter_generated'],
            $row['letter_received'],
            $row['letter_date'],
            $row['letter_generated_date'],
            $row['uploaded_at'],
        ]);
    }

    fclose($out);

    // Log the export action
    log_activity('export', "Exported '{$modeLabel}' records" . ($chunkNum > 0 ? " (chunk {$chunkNum}/{$totalChunks})" : ''));

} catch (Exception $e) {
    die('Export failed: ' . $e->getMessage());
}

function _build_where(string $mode): string
{
    switch ($mode) {
        case 'missing_owner':   return "owner_name IS NULL OR TRIM(owner_name) = ''";
        case 'missing_id':      return "id_passport_no IS NULL OR TRIM(id_passport_no) = ''";
        case 'missing_dob':     return "date_of_birth IS NULL";
        case 'missing_account': return "account_number IS NULL OR TRIM(account_number) = ''";
        case 'dup_accounts':
            return "account_number IS NOT NULL AND TRIM(account_number) != ''
                    AND account_number IN (
                        SELECT account_number FROM unclaimed_assets
                        WHERE account_number IS NOT NULL AND TRIM(account_number) != ''
                        GROUP BY account_number HAVING COUNT(*) > 1
                    )";
        default: return '1=0';
    }
}
