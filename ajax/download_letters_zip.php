<?php
/**
 * UFAA - Download Filtered Letters as ZIP
 * Respects all active filters (owner_name, id_no, account_no, status).
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

// Build query
$whereClauses = ["`letter_received` = 'Yes'", "`letter_file_path` IS NOT NULL", "`letter_file_path` != ''"];
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

$whereSql = 'WHERE ' . implode(' AND ', $whereClauses);

try {
    $stmt = $pdo->prepare("SELECT `owner_name`, `letter_file_path` FROM `unclaimed_assets` $whereSql ORDER BY `record_id` DESC");
    $stmt->execute($params);
    $records = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($records)) {
        die("No letter files found matching the search/filter parameters.");
    }

    // Ensure ZipArchive class exists
    if (!class_exists('ZipArchive')) {
        die("ZipArchive PHP extension is not installed or enabled on this server.");
    }

    // Create temporary zip file
    $zipFilename = "UFAA_Letters_Export_" . date('Ymd_His') . ".zip";
    $zipDir = '../uploads/';
    if (!is_dir($zipDir)) {
        mkdir($zipDir, 0755, true);
    }
    $zipPath = $zipDir . $zipFilename;

    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== TRUE) {
        die("Cannot create ZIP file.");
    }

    $addedFiles = 0;
    foreach ($records as $row) {
        $dbPath = $row['letter_file_path'];
        $fullPath = '../' . $dbPath; // Path relative to this script in ajax/

        if (file_exists($fullPath) && is_file($fullPath)) {
            // Clean up owner name for a safe file name in ZIP
            $safeOwnerName = preg_replace('/[^a-zA-Z0-9_\-]/', '_', trim($row['owner_name']));
            if (empty($safeOwnerName)) {
                $safeOwnerName = 'Record';
            }
            
            $ext = pathinfo($fullPath, PATHINFO_EXTENSION);
            // Prefix filename with owner name for clear identification in ZIP
            $inZipName = $safeOwnerName . '_' . basename($fullPath);
            
            $zip->addFile($fullPath, $inZipName);
            $addedFiles++;
        }
    }

    $zip->close();

    if ($addedFiles === 0) {
        if (file_exists($zipPath)) {
            unlink($zipPath);
        }
        die("None of the matching files exist on the server filesystem.");
    }

    // Deliver ZIP file to user
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $zipFilename . '"');
    header('Content-Length: ' . filesize($zipPath));
    header('Pragma: no-cache');
    header('Expires: 0');
    
    // Clear output buffer to avoid corruption
    ob_clean();
    flush();
    
    readfile($zipPath);

    // Delete temp zip file from server
    unlink($zipPath);
    exit;

} catch (Exception $e) {
    die("Error exporting ZIP: " . $e->getMessage());
}
