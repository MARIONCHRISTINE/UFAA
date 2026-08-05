<?php
/**
 * UFAA - AJAX Batch Holder Letter PDF Generator
 * Accepts an array of record_ids or a query filter, generates PDFs for each,
 * updates the database, and packs them into a downloadable ZIP archive.
 */

require_once '../config.php';
require_once '../includes/holder_letter_pdf.php';

header('Content-Type: application/json');

$pdo = get_db_connection();
if (!$pdo) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Database connection failed.']);
    exit;
}

$recordId = $_POST['record_id'] ?? $_GET['record_id'] ?? null;

if ($recordId && is_numeric($recordId)) {
    $res = create_and_save_holder_letter_pdf($pdo, (int)$recordId, 'F', true);
    echo json_encode($res);
    exit;
}

http_response_code(400);
echo json_encode(['status' => 'error', 'message' => 'Invalid parameters.']);
