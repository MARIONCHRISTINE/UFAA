<?php
/**
 * UFAA - Consolidated Row Update Endpoint (AJAX)
 * Safely updates specific fields (status, letter_received, or letter_date) 
 * for a record identified by its unique record_id.
 */

require_once '../config.php';

header('Content-Type: application/json');

$pdo = get_db_connection();
if (!$pdo) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Database connection failed.'
    ]);
    exit;
}

// Extract inputs
$recordId  = $_POST['record_id'] ?? null;
$fieldName = $_POST['field'] ?? null;
$newValue  = $_POST['value'] ?? null;

// Validate basic parameters
if (!$recordId || !is_numeric($recordId)) {
    http_response_code(400);
    echo json_encode([
        'status' => 'error',
        'message' => 'Invalid or missing Record ID.'
    ]);
    exit;
}

// Strict field whitelist to completely prevent SQL injection
$allowedFields = ['status', 'letter_received', 'letter_date'];
if (!in_array($fieldName, $allowedFields)) {
    http_response_code(400);
    echo json_encode([
        'status' => 'error',
        'message' => 'Unauthorized field modification request.'
    ]);
    exit;
}

// Field-specific validation
if ($fieldName === 'status') {
    if ($newValue !== 'Claimed' && $newValue !== 'Unclaimed') {
        http_response_code(400);
        echo json_encode([
            'status' => 'error',
            'message' => 'Invalid claim status value.'
        ]);
        exit;
    }
} elseif ($fieldName === 'letter_received') {
    if ($newValue !== 'Yes' && $newValue !== 'No') {
        http_response_code(400);
        echo json_encode([
            'status' => 'error',
            'message' => 'Invalid letter received value.'
        ]);
        exit;
    }
} elseif ($fieldName === 'letter_date') {
    // If date string is entirely empty, store it as NULL in the database
    $newValue = trim($newValue);
    if ($newValue === '') {
        $newValue = null;
    }
}

try {
    // Perform parameterized update securely using whitelisted field name
    $stmt = $pdo->prepare("UPDATE `unclaimed_assets` SET `$fieldName` = :val WHERE `record_id` = :id");
    $stmt->execute([
        ':val' => $newValue,
        ':id'  => (int)$recordId
    ]);

    // Check if record exists to determine exact success message
    $chk = $pdo->prepare("SELECT `record_id` FROM `unclaimed_assets` WHERE `record_id` = ?");
    $chk->execute([(int)$recordId]);
    if ($chk->fetch()) {
        
        $friendlyFieldNames = [
            'status' => 'Claiming status',
            'letter_received' => 'Letter Received status',
            'letter_date' => 'Letter date detail'
        ];
        
        $displayValue = $newValue === null ? 'Cleared' : $newValue;

        echo json_encode([
            'status' => 'success',
            'message' => $friendlyFieldNames[$fieldName] . ' updated successfully to: ' . $displayValue
        ]);
    } else {
        http_response_code(404);
        echo json_encode([
            'status' => 'error',
            'message' => 'Asset record not found.'
        ]);
    }

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Database error during update: ' . $e->getMessage()
    ]);
}
