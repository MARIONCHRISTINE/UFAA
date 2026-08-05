<?php
// Buffer output immediately to prevent premature output/headers
ob_start();
ini_set('display_errors', '0');
error_reporting(0);

/**
 * UFAA - Clean & Minimal Holder Confirmation Letter PDF Generator
 * Downloads a clean PDF document directly to the user's device.
 */

try {
    require_once __DIR__ . '/config.php';
    require_once __DIR__ . '/includes/holder_letter_pdf.php';

    $pdo = get_db_connection();
    if (!$pdo) {
        throw new Exception("Database connection error. Please verify MySQL service status.");
    }

    $recordId = $_GET['id'] ?? $_GET['record_id'] ?? null;
    // Default to 'download' mode ('D') to prevent Chrome extensions (e.g., Adobe Acrobat extension)
    // from intercepting localhost inline streams and throwing "Failed to load PDF document".
    $action = $_GET['action'] ?? 'download';
    $outputMode = ($action === 'inline') ? 'I' : 'D';

    if (!$recordId || !is_numeric($recordId)) {
        throw new Exception("Invalid or missing Asset Record ID.");
    }

    // Call helper which handles fetching, consolidating, updating DB, and generating PDF
    $result = create_and_save_holder_letter_pdf($pdo, (int)$recordId, $outputMode);

    if (isset($result['status']) && $result['status'] === 'error') {
        throw new Exception($result['message']);
    }

} catch (Throwable $e) {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: text/html; charset=utf-8');
    http_response_code(400);
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <title>UFAA - Document Error</title>
        <style>
            body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: #0f172a; color: #f8fafc; display: flex; align-items: center; justify-content: center; height: 100vh; margin: 0; }
            .error-card { background: #1e293b; padding: 2rem 2.5rem; border-radius: 12px; box-shadow: 0 20px 25px -5px rgba(0,0,0,0.5); text-align: center; max-width: 480px; width: 90%; border: 1px solid #334155; }
            .error-icon { font-size: 3rem; color: #ef4444; margin-bottom: 1rem; }
            h2 { color: #f8fafc; font-size: 1.5rem; margin-bottom: 0.5rem; }
            p { color: #94a3b8; font-size: 0.95rem; line-height: 1.5; margin-bottom: 1.5rem; }
            .btn { background: #dc2626; color: white; border: none; padding: 0.6rem 1.4rem; border-radius: 6px; font-weight: 600; cursor: pointer; text-decoration: none; display: inline-block; }
            .btn:hover { background: #b91c1c; }
        </style>
    </head>
    <body>
        <div class="error-card">
            <div class="error-icon">&#9888;</div>
            <h2>Unable to Generate PDF</h2>
            <p><?= htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') ?></p>
            <a href="javascript:window.close()" class="btn">Close Tab</a>
        </div>
    </body>
    </html>
    <?php
    exit;
}
