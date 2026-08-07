<?php
/**
 * UFAA Admin AJAX — Dashboard KPI Stats
 * Returns JSON with counts for the dashboard stat cards.
 */

header('Content-Type: application/json');

require_once __DIR__ . '/../includes/admin_auth.php';

$pdo = admin_get_pdo();

if (!$pdo) {
    echo json_encode(['success' => false, 'error' => 'Database unavailable']);
    exit;
}

try {
    // Total records
    $total = (int) $pdo->query("SELECT COUNT(*) FROM unclaimed_assets")->fetchColumn();

    // Claimed vs Unclaimed
    $claimed   = (int) $pdo->query("SELECT COUNT(*) FROM unclaimed_assets WHERE status = 'Claimed'")->fetchColumn();
    $unclaimed = (int) $pdo->query("SELECT COUNT(*) FROM unclaimed_assets WHERE status = 'Unclaimed'")->fetchColumn();

    // Letters generated
    $letters = (int) $pdo->query("SELECT COUNT(*) FROM unclaimed_assets WHERE letter_generated = 'Yes'")->fetchColumn();

    // Total admin users
    $users = (int) $pdo->query("SELECT COUNT(*) FROM admin_users WHERE is_active = 1")->fetchColumn();

    // Activity log count
    $logCount = (int) $pdo->query("SELECT COUNT(*) FROM activity_logs")->fetchColumn();

    // Today's activity count
    $todayActivity = (int) $pdo->query(
        "SELECT COUNT(*) FROM activity_logs WHERE DATE(created_at) = CURDATE()"
    )->fetchColumn();

    // Upload sessions count
    $uploads = (int) $pdo->query("SELECT COUNT(*) FROM upload_sessions")->fetchColumn();

    // Monthly uploads chart data (last 6 months)
    $uploadMonths = $pdo->query("
        SELECT
            DATE_FORMAT(uploaded_at, '%b %Y') AS month_label,
            SUM(record_count) AS total_records
        FROM upload_sessions
        WHERE uploaded_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
        GROUP BY YEAR(uploaded_at), MONTH(uploaded_at)
        ORDER BY YEAR(uploaded_at) ASC, MONTH(uploaded_at) ASC
    ")->fetchAll();

    $uploadLabels = array_column($uploadMonths, 'month_label');
    $uploadData   = array_map('intval', array_column($uploadMonths, 'total_records'));

    // Activity trend — last 14 days
    $activityTrend = $pdo->query("
        SELECT
            DATE_FORMAT(created_at, '%d %b') AS day_label,
            COUNT(*) AS cnt
        FROM activity_logs
        WHERE created_at >= DATE_SUB(NOW(), INTERVAL 14 DAY)
        GROUP BY DATE(created_at)
        ORDER BY DATE(created_at) ASC
    ")->fetchAll();

    $trendLabels = array_column($activityTrend, 'day_label');
    $trendData   = array_map('intval', array_column($activityTrend, 'cnt'));

    echo json_encode([
        'success'       => true,
        'total'         => $total,
        'claimed'       => $claimed,
        'unclaimed'     => $unclaimed,
        'letters'       => $letters,
        'users'         => $users,
        'log_count'     => $logCount,
        'today_activity'=> $todayActivity,
        'uploads'       => $uploads,
        'chart_uploads' => ['labels' => $uploadLabels, 'data' => $uploadData],
        'chart_trend'   => ['labels' => $trendLabels,  'data' => $trendData],
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
