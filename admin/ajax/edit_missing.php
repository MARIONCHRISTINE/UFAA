<?php
/**
 * UFAA Admin AJAX — Edit a missing field on a record
 * POST params: record_id, field, value
 * Allowed fields: owner_name, id_passport_no, date_of_birth, account_number
 * Logs the change to activity_logs.
 */

header('Content-Type: application/json');

require_once __DIR__ . '/../includes/admin_auth.php';

$pdo = admin_get_pdo();
if (!$pdo) { echo json_encode(['success' => false, 'error' => 'DB error']); exit; }

$recordId = trim($_POST['record_id'] ?? '');
$field    = trim($_POST['field']     ?? '');
$value    = trim($_POST['value']     ?? '');

// Only these fields may be edited via this endpoint
$allowed = ['owner_name', 'id_passport_no', 'date_of_birth', 'account_number'];

if ($recordId === '' || !in_array($field, $allowed)) {
    echo json_encode(['success' => false, 'error' => 'Invalid record or field']);
    exit;
}

if ($value === '') {
    echo json_encode(['success' => false, 'error' => 'Value cannot be empty']);
    exit;
}

// Validate date format
if ($field === 'date_of_birth') {
    $dt = DateTime::createFromFormat('Y-m-d', $value);
    if (!$dt || $dt->format('Y-m-d') !== $value) {
        echo json_encode(['success' => false, 'error' => 'Invalid date format. Use YYYY-MM-DD']);
        exit;
    }
}

try {
    // Fetch existing value for log message — use record_id (the PK)
    $existing = $pdo->prepare(
        "SELECT record_id, $field FROM unclaimed_assets WHERE record_id = ? LIMIT 1"
    );
    $existing->execute([$recordId]);
    $row = $existing->fetch();

    if (!$row) {
        echo json_encode(['success' => false, 'error' => "Record '$recordId' not found"]);
        exit;
    }

    $oldValue = ($row[$field] !== null && $row[$field] !== '') ? $row[$field] : '(empty)';

    // Perform update
    $pdo->prepare("UPDATE unclaimed_assets SET `$field` = ? WHERE record_id = ?")
        ->execute([$value, $recordId]);

    // Log to activity_logs
    $labels = [
        'owner_name'    => 'Owner Name',
        'id_passport_no'=> 'ID/Passport No',
        'date_of_birth' => 'Date of Birth',
        'account_number'=> 'Account Number',
    ];
    log_activity(
        'record_edit',
        "Updated {$labels[$field]} for record #{$recordId}: '{$oldValue}' \u2192 '{$value}'"
    );

    echo json_encode(['success' => true, 'new_value' => $value]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
