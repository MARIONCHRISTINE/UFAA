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
$ownerName          = trim($_GET['owner_name'] ?? '');
$idNo               = trim($_GET['id_no'] ?? '');
$accountNo          = trim($_GET['account_no'] ?? '');
$status             = trim($_GET['status'] ?? '');
$letter             = trim($_GET['letter'] ?? '');
$compilationStart   = trim($_GET['compilation_start'] ?? '');
$compilationEnd     = trim($_GET['compilation_end'] ?? '');

// Build query
$whereClauses = [];
$params = [];

build_multiple_search_clause('owner_name', $ownerName, $whereClauses, $params, 'owner_name');
build_multiple_search_clause('id_passport_no', $idNo, $whereClauses, $params, 'id_no');
build_multiple_search_clause('account_number', $accountNo, $whereClauses, $params, 'account_no');
if ($status !== '') {
    $whereClauses[] = "`status` = :status";
    $params[':status'] = $status;
}
if ($letter !== '') {
    $whereClauses[] = "`letter_received` = :letter_received";
    $params[':letter_received'] = $letter;
}
if ($compilationStart !== '') {
    $whereClauses[] = "`compilation_date` >= :compilation_start";
    $params[':compilation_start'] = $compilationStart;
}
if ($compilationEnd !== '') {
    $whereClauses[] = "`compilation_date` <= :compilation_end";
    $params[':compilation_end'] = $compilationEnd;
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
        'Compilation Date',
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
            $row['compilation_date'],
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
