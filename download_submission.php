<?php
/**
 * UFAA – Secure Submission File Download Handler
 * Streams generated, password-protected submission files safely with correct MIME headers.
 */

require_once __DIR__ . '/config.php';

$fileName = basename($_GET['file'] ?? '');

if (empty($fileName) || !preg_match('/^UFAA_Submission_[a-zA-Z0-9_\-\.]+\.xlsx$/', $fileName)) {
    http_response_code(400);
    die('Invalid or missing file name.');
}

$filePath = __DIR__ . '/uploads/generated_submissions/' . $fileName;

if (!file_exists($filePath)) {
    http_response_code(404);
    die('The requested submission file does not exist on the server.');
}

$fileSize = filesize($filePath);

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $fileName . '"');
header('Content-Length: ' . $fileSize);
header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
header('Pragma: public');
header('Expires: 0');

// Stream file in 8KB chunks to prevent RAM spikes on large exports
$fp = fopen($filePath, 'rb');
if ($fp) {
    while (!feof($fp)) {
        echo fread($fp, 8192);
        flush();
    }
    fclose($fp);
} else {
    readfile($filePath);
}
exit;
