<?php
/**
 * UFAA - Letter File Upload Endpoint
 * Handles uploading letter files (PDF, images, Word) and saving the path to DB.
 */
require_once '../config.php';

header('Content-Type: application/json');

$pdo = get_db_connection();
if (!$pdo) {
    echo json_encode(['status' => 'error', 'message' => 'Database connection failed.']);
    exit;
}

if (!isset($_POST['record_id']) || empty($_POST['record_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Missing record ID.']);
    exit;
}

if (!isset($_FILES['letter_file']) || $_FILES['letter_file']['error'] !== UPLOAD_ERR_OK) {
    $err = $_FILES['letter_file']['error'] ?? 'No file uploaded.';
    echo json_encode(['status' => 'error', 'message' => "Upload failed. Error Code: $err"]);
    exit;
}

$recordId = intval($_POST['record_id']);
$fileTmp = $_FILES['letter_file']['tmp_name'];
$fileName = $_FILES['letter_file']['name'];
$fileSize = $_FILES['letter_file']['size'];

// Validate extension
$allowedExts = ['pdf', 'jpg', 'jpeg', 'png', 'doc', 'docx'];
$ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

if (!in_array($ext, $allowedExts)) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid file type. Only PDF, Images, and Word docs allowed.']);
    exit;
}

// Ensure upload directory exists
$uploadDir = '../uploads/letters/';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
    // Create .htaccess to prevent PHP execution
    file_put_contents($uploadDir . '.htaccess', "RemoveHandler .php .phtml .php3\nRemoveType .php .phtml .php3\nphp_flag engine off\n");
}

// Generate safe unique filename
$newFileName = 'letter_' . $recordId . '_' . time() . '.' . $ext;
$destPath = $uploadDir . $newFileName;

if (move_uploaded_file($fileTmp, $destPath)) {
    // Relative path for database and frontend (from site root)
    $dbPath = 'uploads/letters/' . $newFileName;
    
    try {
        $stmt = $pdo->prepare("UPDATE `unclaimed_assets` SET `letter_file_path` = :path WHERE `record_id` = :id");
        $stmt->execute([':path' => $dbPath, ':id' => $recordId]);
        
        echo json_encode([
            'status' => 'success', 
            'message' => 'Letter uploaded successfully!',
            'file_path' => $dbPath
        ]);
    } catch (PDOException $e) {
        // DB update failed, delete the uploaded file
        unlink($destPath);
        echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $e->getMessage()]);
    }
} else {
    echo json_encode(['status' => 'error', 'message' => 'Failed to move uploaded file.']);
}
