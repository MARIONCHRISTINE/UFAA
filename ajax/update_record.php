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
$allowedFields = ['status', 'letter_generated', 'letter_received', 'letter_date'];
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
    $validStatuses = ['Unclaimed', 'Letter Generated', 'Submitted', 'Claimed'];
    if (!in_array($newValue, $validStatuses)) {
        http_response_code(400);
        echo json_encode([
            'status' => 'error',
            'message' => 'Invalid claim status value.'
        ]);
        exit;
    }
} elseif ($fieldName === 'letter_generated' || $fieldName === 'letter_received') {
    if ($newValue !== 'Yes' && $newValue !== 'No') {
        http_response_code(400);
        echo json_encode([
            'status' => 'error',
            'message' => 'Invalid status value.'
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
    // Fetch owner name & old status for descriptive activity logging
    $ownerStmt = $pdo->prepare("SELECT `owner_name`, `status` FROM `unclaimed_assets` WHERE `record_id` = ?");
    $ownerStmt->execute([(int)$recordId]);
    $existingRecord = $ownerStmt->fetch();

    if (!$existingRecord) {
        http_response_code(404);
        echo json_encode([
            'status' => 'error',
            'message' => 'Asset record not found.'
        ]);
        exit;
    }

    $ownerName = $existingRecord['owner_name'] ?? '';
    $oldStatus = $existingRecord['status'] ?? '';

    // Perform parameterized update securely using whitelisted field name
    $stmt = $pdo->prepare("UPDATE `unclaimed_assets` SET `$fieldName` = :val WHERE `record_id` = :id");
    $stmt->execute([
        ':val' => $newValue,
        ':id'  => (int)$recordId
    ]);

    // Automatically set letter_generated_date if setting letter_generated to Yes
    if ($fieldName === 'letter_generated' && $newValue === 'Yes') {
        $stmtDate = $pdo->prepare("UPDATE `unclaimed_assets` SET `letter_generated_date` = NOW() WHERE `record_id` = :id AND `letter_generated_date` IS NULL");
        $stmtDate->execute([':id' => (int)$recordId]);
    }

    $friendlyFieldNames = [
        'status'           => 'Claiming status',
        'letter_generated' => 'Letter Generated status',
        'letter_received'  => 'Letter Received status',
        'letter_date'      => 'Letter date detail'
    ];
    
    $displayValue = $newValue === null ? 'Cleared' : $newValue;

    // Log officer activity
    $actionKey = ($fieldName === 'status') ? 'status_change' : 'record_edit';
    $desc = "Changed {$friendlyFieldNames[$fieldName]} to '{$displayValue}'" . ($oldStatus && $fieldName === 'status' ? " (was '{$oldStatus}')" : "") . " for Record #{$recordId}" . ($ownerName ? " ({$ownerName})" : "");
    log_activity($actionKey, $desc, (int)$recordId);

    echo json_encode([
        'status' => 'success',
        'message' => $friendlyFieldNames[$fieldName] . ' updated successfully to: ' . $displayValue
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Database error during update: ' . $e->getMessage()
    ]);
}
