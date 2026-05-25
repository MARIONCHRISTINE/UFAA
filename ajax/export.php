<?php
/**
 * UFAA - Data Export to Excel-compatible CSV
 * Respects all active filters (owner_name, id_no, account_no, status, letter).
 */

require_once '../config.php';

$pdo = get_db_connection();
if (!$pdo) {
    die("Database connection failed.");
}

// Extract filter parameters
$ownerName = trim($_GET['owner_name'] ?? '');
$idNo      = trim($_GET['id_no'] ?? '');
$accountNo = trim($_GET['account_no'] ?? '');
$status    = trim($_GET['status'] ?? '');
$letter    = trim($_GET['letter'] ?? '');

// Build query
$whereClauses = [];
$params = [];

if ($ownerName !== '') {
    $whereClauses[] = "`owner_name` LIKE :owner_name";
    $params[':owner_name'] = '%' . $ownerName . '%';
}
if ($idNo !== '') {
    $whereClauses[] = "`id_passport_no` LIKE :id_no";
    $params[':id_no'] = '%' . $idNo . '%';
}
if ($accountNo !== '') {
    $whereClauses[] = "`account_number` LIKE :account_no";
    $params[':account_no'] = '%' . $accountNo . '%';
}
if ($status !== '') {
    $whereClauses[] = "`status` = :status";
    $params[':status'] = $status;
}
if ($letter !== '') {
    $whereClauses[] = "`letter_received` = :letter_received";
    $params[':letter_received'] = $letter;
}

$whereSql = '';
if (!empty($whereClauses)) {
    $whereSql = 'WHERE ' . implode(' AND ', $whereClauses);
}

try {
    $stmt = $pdo->prepare("SELECT * FROM `unclaimed_assets` $whereSql ORDER BY `record_id` DESC");
    $stmt->execute($params);
    $records = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Set headers to force download as CSV
    $filename = "UFAA_Compliance_Export_" . date('Ymd_His') . ".csv";
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    
    // Create file pointer writing to output stream
    $output = fopen('php://output', 'w');
    
    // Add UTF-8 BOM for proper rendering of special characters in Excel
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

    // Output column headers
    fputcsv($output, [
        'Record ID', 
        'Owner Name', 
        'ID / Passport No', 
        'Date of Birth', 
        'Account Number', 
        'Last Transaction', 
        'Due Amount', 
        'Status', 
        'Letter Received', 
        'Letter Date', 
        'Uploaded At'
    ]);

    // Output rows
    foreach ($records as $row) {
        fputcsv($output, [
            $row['record_id'],
            $row['owner_name'],
            $row['id_passport_no'],
            $row['date_of_birth'],
            $row['account_number'],
            $row['last_transaction'],
            $row['due_amount'],
            $row['status'],
            $row['letter_received'],
            $row['letter_date'],
            $row['uploaded_at']
        ]);
    }
    
    fclose($output);
    exit;

} catch (PDOException $e) {
    die("Export failed: " . $e->getMessage());
}
