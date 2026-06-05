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

if ($ownerName !== '') {
    $whereClauses[] = "`owner_name` IS NOT NULL AND TRIM(`owner_name`) != ''";
    build_multiple_search_clause('owner_name', $ownerName, $whereClauses, $params, 'owner_name');
}
if ($idNo !== '') {
    $whereClauses[] = "`id_passport_no` IS NOT NULL AND TRIM(`id_passport_no`) != ''";
    build_multiple_search_clause('id_passport_no', $idNo, $whereClauses, $params, 'id_no');
}
if ($accountNo !== '') {
    $whereClauses[] = "`account_number` IS NOT NULL AND TRIM(`account_number`) != ''";
    build_multiple_search_clause('account_number', $accountNo, $whereClauses, $params, 'account_no');
}
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

// 1. Handle JSON Count request
if (isset($_GET['get_count']) && $_GET['get_count'] == '1') {
    try {
        $countQuery = $pdo->prepare("SELECT COUNT(*) FROM `unclaimed_assets` $whereSql");
        $countQuery->execute($params);
        $totalCount = (int)$countQuery->fetchColumn();
        
        header('Content-Type: application/json');
        echo json_encode(['status' => 'success', 'count' => $totalCount]);
        exit;
    } catch (PDOException $e) {
        header('Content-Type: application/json');
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        exit;
    }
}

// 2. Parse pagination chunk options
$limit = isset($_GET['limit']) ? intval($_GET['limit']) : 0;
$offset = isset($_GET['offset']) ? intval($_GET['offset']) : 0;

$limitSql = '';
if ($limit > 0) {
    $limitSql = " LIMIT :limit OFFSET :offset";
}

try {
    $stmt = $pdo->prepare("SELECT * FROM `unclaimed_assets` $whereSql ORDER BY `owner_name` IS NULL ASC, `owner_name` ASC" . $limitSql);
    
    // Bind regular query params
    foreach ($params as $key => $val) {
        $stmt->bindValue($key, $val);
    }
    
    // Bind limit and offset as integers if specified
    if ($limit > 0) {
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    }
    
    $stmt->execute();
    $records = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 3. Handle file naming with parts/chunks
    $chunkNum = isset($_GET['chunk_num']) ? intval($_GET['chunk_num']) : 0;
    $totalChunks = isset($_GET['total_chunks']) ? intval($_GET['total_chunks']) : 0;
    
    $suffix = '';
    if ($chunkNum > 0 && $totalChunks > 0) {
        $suffix = "_Part{$chunkNum}_of_{$totalChunks}";
    }
    
    $filename = "UFAA_Compliance_Export_" . date('Ymd_His') . $suffix . ".csv";
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
