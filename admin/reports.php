<?php
/**
 * UFAA Admin — Executive Reports & Compliance Generator
 * Multi-year database support (millions of rows across years),
 * Executive Print/PDF output with formal headers & signature blocks.
 */

require_once __DIR__ . '/includes/admin_auth.php';

$pageTitle       = 'Reports';
$adminActivePage = 'reports';

$pdo = admin_get_pdo();

// ── Multi-Year Range Bootstrap ────────────────────────────────────────────
$minDate = '2015-01-01';
$maxDate = date('Y-m-d');
$availableYears = [];

if ($pdo) {
    try {
        $minDb = $pdo->query("SELECT MIN(DATE(compilation_date)) FROM unclaimed_assets WHERE compilation_date IS NOT NULL AND compilation_date != '0000-00-00'")->fetchColumn();
        if ($minDb) $minDate = $minDb;

        $yrs = $pdo->query("SELECT DISTINCT YEAR(compilation_date) as yr FROM unclaimed_assets WHERE compilation_date IS NOT NULL ORDER BY yr DESC")->fetchAll(PDO::FETCH_COLUMN);
        if (!empty($yrs)) $availableYears = array_filter($yrs);
    } catch (Exception $e) {}
}

// ── Extract Filter Parameters ──────────────────────────────────────────────
$selectedYear = $_GET['year']       ?? '';
$dateFrom     = $_GET['date_from']  ?? ($selectedYear ? "{$selectedYear}-01-01" : $minDate);
$dateTo       = $_GET['date_to']    ?? ($selectedYear ? "{$selectedYear}-12-31" : $maxDate);
$statusFilter = $_GET['status']     ?? '';
$letterFilter = $_GET['letter']     ?? '';
$reportType   = $_GET['report_type'] ?? 'summary';

// If year dropdown changed
if ($selectedYear !== '' && !isset($_GET['date_from'])) {
    $dateFrom = "{$selectedYear}-01-01";
    $dateTo   = "{$selectedYear}-12-31";
}

// ── Build Query Filters ───────────────────────────────────────────────────
$where  = ["DATE(compilation_date) BETWEEN :from AND :to"];
$params = [':from' => $dateFrom, ':to' => $dateTo];

if ($statusFilter !== '') {
    $where[]           = "status = :status";
    $params[':status'] = $statusFilter;
}

if ($letterFilter !== '') {
    $where[]           = "letter_generated = :letter";
    $params[':letter'] = $letterFilter;
}

$whereSQL = 'WHERE ' . implode(' AND ', $where);

$exportType = $_GET['export'] ?? '';

// ── Full CSV / Executive Excel Export Handler ──────────────────────────────
if ($exportType && $pdo) {
    try {
        if ($exportType === 'raw_csv') {
            // Raw CSV Data (without Letter Date column)
            $stmt = $pdo->prepare("
                SELECT
                    record_id, owner_name, id_passport_no, date_of_birth,
                    account_number, last_transaction, due_amount, compilation_date,
                    status, letter_generated, letter_received, uploaded_at
                FROM unclaimed_assets
                $whereSQL
                ORDER BY compilation_date DESC
            ");
            $stmt->execute($params);

            $filename = "UFAA_Raw_Data_Export_" . date('Ymd_His') . ".csv";
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $filename . '"');

            $out = fopen('php://output', 'w');
            fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF)); // UTF-8 BOM for Excel

            fputcsv($out, [
                'Record ID', 'Owner Name', 'ID / Passport No', 'Date of Birth',
                'Account Number', 'Last Transaction Date', 'Due Amount (KSh)', 'Compilation Date',
                'Claim Status', 'Letter Generated', 'Letter Received', 'Uploaded At'
            ]);

            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                fputcsv($out, [
                    $row['record_id'],
                    $row['owner_name'],
                    $row['id_passport_no'],
                    $row['date_of_birth'],
                    $row['account_number'],
                    $row['last_transaction'],
                    $row['due_amount'],
                    $row['compilation_date'],
                    $row['status'],
                    $row['letter_generated'],
                    $row['letter_received'],
                    $row['uploaded_at'],
                ]);
            }
            fclose($out);
            log_activity('report_export', "Exported Raw CSV Data ({$dateFrom} to {$dateTo})");
            exit;
        } else {
            // Executive Excel Summary Report
            $stmtStatus = $pdo->prepare("
                SELECT status, COUNT(*) as cnt, SUM(COALESCE(due_amount, 0)) as sum_amount
                FROM unclaimed_assets $whereSQL GROUP BY status
            ");
            $stmtStatus->execute($params);
            $statusTotals = $stmtStatus->fetchAll();

            $stmtM = $pdo->prepare("
                SELECT DATE_FORMAT(compilation_date, '%b %Y') as month_label, DATE_FORMAT(compilation_date, '%Y-%m') as month_key,
                       status, COUNT(*) as record_count, SUM(COALESCE(due_amount, 0)) as total_amount
                FROM unclaimed_assets $whereSQL GROUP BY month_key, month_label, status ORDER BY month_key ASC, status ASC
            ");
            $stmtM->execute($params);
            $monthlyData = $stmtM->fetchAll();

            $missingOwner   = (int)$pdo->query("SELECT COUNT(*) FROM unclaimed_assets WHERE owner_name IS NULL OR TRIM(owner_name)=''")->fetchColumn();
            $missingId      = (int)$pdo->query("SELECT COUNT(*) FROM unclaimed_assets WHERE id_passport_no IS NULL OR TRIM(id_passport_no)=''")->fetchColumn();
            $missingAccount = (int)$pdo->query("SELECT COUNT(*) FROM unclaimed_assets WHERE account_number IS NULL OR TRIM(account_number)=''")->fetchColumn();
            $dupAccounts    = (int)$pdo->query("SELECT COUNT(*) FROM (SELECT account_number FROM unclaimed_assets WHERE account_number IS NOT NULL AND TRIM(account_number) != '' GROUP BY account_number HAVING COUNT(*) > 1) t")->fetchColumn();

            $filename = "UFAA_Executive_Compliance_Report_" . date('Ymd_His') . ".csv";
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $filename . '"');

            $out = fopen('php://output', 'w');
            fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF)); // UTF-8 BOM

            fputcsv($out, ['UNCLAIMED FINANCIAL ASSETS AUTHORITY (UFAA) — AIRTEL COMPLIANCE REPORT']);
            fputcsv($out, ['Report Date', date('d M Y, H:i') . ' EAT']);
            fputcsv($out, ['Date Period', $dateFrom . ' to ' . $dateTo]);
            fputcsv($out, ['Generated By', $_SESSION['admin_user']['username'] ?? 'Compliance Admin']);
            fputcsv($out, []); // Blank line

            fputcsv($out, ['1. FINANCIAL ASSET SUMMARY BY CLAIM STATUS']);
            fputcsv($out, ['Claim Status', 'Total Accounts', 'Total Money (KSh)']);
            $totCnt = 0; $totVal = 0;
            foreach ($statusTotals as $s) {
                $totCnt += (int)$s['cnt'];
                $totVal += (float)$s['sum_amount'];
                fputcsv($out, [$s['status'], $s['cnt'], number_format($s['sum_amount'], 2)]);
            }
            fputcsv($out, ['TOTAL PORTFOLIO', $totCnt, number_format($totVal, 2)]);
            fputcsv($out, []); // Blank line

            fputcsv($out, ['2. MONTHLY ASSET COMPILATION BREAKDOWN']);
            fputcsv($out, ['Month & Year', 'Claim Status', 'Account Count', 'Total Money (KSh)', 'Avg Account Value (KSh)']);
            foreach ($monthlyData as $m) {
                $avg = $m['record_count'] > 0 ? ($m['total_amount'] / $m['record_count']) : 0;
                fputcsv($out, [$m['month_label'], $m['status'], $m['record_count'], number_format($m['total_amount'], 2), number_format($avg, 2)]);
            }
            fputcsv($out, []); // Blank line

            fputcsv($out, ['3. DATA COMPLETENESS & QUALITY AUDIT']);
            fputcsv($out, ['Parameter', 'Affected Records', 'Compliance Status']);
            fputcsv($out, ['Missing Owner Names', $missingOwner, $missingOwner > 0 ? 'Action Required' : 'Complete']);
            fputcsv($out, ['Missing ID / Passport Numbers', $missingId, $missingId > 0 ? 'Action Required' : 'Complete']);
            fputcsv($out, ['Missing Account Numbers', $missingAccount, $missingAccount > 0 ? 'Action Required' : 'Complete']);
            fputcsv($out, ['Duplicate Account Numbers', $dupAccounts, $dupAccounts > 0 ? 'Action Required' : 'Complete']);
            fputcsv($out, []); // Blank line

            fputcsv($out, ['4. OFFICIAL SIGN-OFF']);
            fputcsv($out, ['Prepared By:', 'Compliance Officer']);
            fputcsv($out, ['Reviewed By:', 'Head of Compliance']);
            fputcsv($out, ['Approval:', 'UFAA Official Seal']);

            fclose($out);
            log_activity('report_export', "Exported Executive Excel Summary Report ({$dateFrom} to {$dateTo})");
            exit;
        }
    } catch (Exception $e) {
        die('Export failed: ' . $e->getMessage());
    }
}

// ── Fetch Report Data ─────────────────────────────────────────────────────
$monthlyData = [];
$statusTotals = [];
$totalValInPeriod = 0;
$totalRecordsInPeriod = 0;

if ($pdo) {
    try {
        // Multi-Year Monthly Aggregation (Year + Month label)
        $stmt = $pdo->prepare("
            SELECT
                DATE_FORMAT(compilation_date, '%b %Y') as month_label,
                DATE_FORMAT(compilation_date, '%Y-%m') as month_key,
                status,
                COUNT(*) as record_count,
                SUM(COALESCE(due_amount, 0)) as total_amount
            FROM unclaimed_assets
            $whereSQL
            GROUP BY month_key, month_label, status
            ORDER BY month_key ASC, status ASC
        ");
        $stmt->execute($params);
        $monthlyData = $stmt->fetchAll();

        // Status Totals
        $stmtStatus = $pdo->prepare("
            SELECT
                status,
                COUNT(*) as cnt,
                SUM(COALESCE(due_amount, 0)) as sum_amount
            FROM unclaimed_assets
            $whereSQL
            GROUP BY status
        ");
        $stmtStatus->execute($params);
        $statusTotals = $stmtStatus->fetchAll();

        foreach ($statusTotals as $st) {
            $totalRecordsInPeriod += (int)$st['cnt'];
            $totalValInPeriod     += (float)$st['sum_amount'];
        }

    } catch (Exception $e) {}
}

require_once __DIR__ . '/includes/admin_layout.php';
?>

<!-- ── Page Header (Web View) ── -->
<div class="admin-page-header">
    <div class="admin-page-header-left">
        <div class="admin-breadcrumb">
            <i class="fa-solid fa-house"></i>
            <a href="index.php">Dashboard</a>
            <i class="fa-solid fa-chevron-right"></i>
            <span>Reports</span>
        </div>
        <h2><i class="fa-solid fa-chart-bar" style="color:var(--airtel-red);margin-right:.45rem;"></i>Reports &amp; Data Export</h2>
        <p>Generate executive audit reports, view multi-year financial trends, and download compliance documents.</p>
    </div>
    <div style="display:flex;gap:.5rem;flex-wrap:wrap;">
        <a href="report_pdf.php?<?= http_build_query($_GET) ?>" target="_blank" class="btn-admin-outline" style="font-size:.82rem;color:#CC0000;border-color:rgba(204,0,0,0.3);">
            <i class="fa-solid fa-file-pdf"></i> Download Executive PDF Report
        </a>
        <a href="?<?= http_build_query(array_merge($_GET, ['export' => 'summary_excel'])) ?>" class="btn-admin-primary" style="font-size:.82rem;">
            <i class="fa-solid fa-file-excel"></i> Download Executive Excel Report
        </a>
        <a href="?<?= http_build_query(array_merge($_GET, ['export' => 'raw_csv'])) ?>" class="btn-admin-outline" style="font-size:.82rem;color:var(--text-secondary);">
            <i class="fa-solid fa-table"></i> Export Raw CSV Data
        </a>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════
     SECTION 1 — Report Filters (Supports Multi-Year & Date Windows)
════════════════════════════════════════════════════════════ -->
<div class="admin-card admin-section">
    <div class="admin-card-title"><i class="fa-solid fa-sliders"></i> Select Report Filter &amp; Multi-Year Range</div>
    <form method="GET" id="report-form">
        <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(160px, 1fr));gap:1rem;margin-bottom:1.25rem;">
            
            <!-- Quick Year Filter -->
            <div class="admin-form-group" style="margin-bottom:0;">
                <label for="year"><i class="fa-solid fa-calendar-days"></i> Quick Year Filter</label>
                <select name="year" id="year" class="admin-select" onchange="document.getElementById('report-form').submit()">
                    <option value="">All Time (2015 &ndash; Now)</option>
                    <?php foreach ($availableYears as $y): ?>
                    <option value="<?= $y ?>" <?= (string)$selectedYear===(string)$y ? 'selected':'' ?>><?= $y ?> Year</option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Custom Date From -->
            <div class="admin-form-group" style="margin-bottom:0;">
                <label for="date_from"><i class="fa-regular fa-calendar"></i> Date From</label>
                <input type="date" name="date_from" id="date_from" class="admin-input" value="<?= htmlspecialchars($dateFrom) ?>">
            </div>

            <!-- Custom Date To -->
            <div class="admin-form-group" style="margin-bottom:0;">
                <label for="date_to"><i class="fa-regular fa-calendar"></i> Date To</label>
                <input type="date" name="date_to" id="date_to" class="admin-input" value="<?= htmlspecialchars($dateTo) ?>">
            </div>

            <!-- Claim Status Filter -->
            <div class="admin-form-group" style="margin-bottom:0;">
                <label for="status"><i class="fa-solid fa-tags"></i> Claim Status</label>
                <select name="status" id="status" class="admin-select">
                    <option value="">All Statuses</option>
                    <option value="Unclaimed"        <?= $statusFilter==='Unclaimed'        ? 'selected':'' ?>>Unclaimed</option>
                    <option value="Letter Generated" <?= $statusFilter==='Letter Generated' ? 'selected':'' ?>>Letter Generated</option>
                    <option value="Submitted"        <?= $statusFilter==='Submitted'        ? 'selected':'' ?>>Submitted</option>
                    <option value="Claimed"          <?= $statusFilter==='Claimed'          ? 'selected':'' ?>>Claimed</option>
                </select>
            </div>

            <!-- Letter Status Filter -->
            <div class="admin-form-group" style="margin-bottom:0;">
                <label for="letter"><i class="fa-solid fa-envelope"></i> Letter Status</label>
                <select name="letter" id="letter" class="admin-select">
                    <option value="">All Letters</option>
                    <option value="Yes" <?= $letterFilter==='Yes' ? 'selected':'' ?>>Letter Issued (Yes)</option>
                    <option value="No"  <?= $letterFilter==='No'  ? 'selected':'' ?>>No Letter Issued (No)</option>
                </select>
            </div>

        </div>

        <div style="display:flex;justify-content:space-between;align-items:center;border-top:1px solid #e5e7eb;padding-top:1rem;flex-wrap:wrap;gap:.75rem;">
            <div style="font-size:.84rem;color:var(--text-secondary);font-weight:500;">
                <i class="fa-solid fa-filter"></i> Filtering records from <strong><?= date('d M Y', strtotime($dateFrom)) ?></strong> to <strong><?= date('d M Y', strtotime($dateTo)) ?></strong>
            </div>
            <div style="display:flex;gap:.5rem;">
                <a href="reports.php" class="btn-admin-outline" style="font-size:.82rem;">
                    <i class="fa-solid fa-rotate-right"></i> Reset Filters
                </a>
                <button type="submit" class="btn-admin-primary" style="font-size:.82rem;">
                    <i class="fa-solid fa-chart-line"></i> Generate Report
                </button>
            </div>
        </div>
    </form>
</div>

<!-- ══════════════════════════════════════════════════════════
     SECTION 2 — Money Totals & Multi-Year Trends
════════════════════════════════════════════════════════════ -->
<div class="admin-grid-2 admin-section">

    <!-- Money Totals by Status -->
    <div class="admin-card">
        <div class="admin-card-title">
            <i class="fa-solid fa-vault"></i> Money Totals by Claim Status
        </div>
        <p style="font-size:.82rem;color:var(--text-secondary);margin-bottom:1rem;">
            Total money in Kenya Shillings (KSh) categorized by status for the selected range.
        </p>

        <?php if (empty($statusTotals)): ?>
        <div class="admin-empty-state"><i class="fa-solid fa-inbox"></i><p>No records found in selected range.</p></div>
        <?php else: ?>
        <div class="admin-table-wrap">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>Status</th>
                        <th style="text-align:right;">Total Accounts</th>
                        <th style="text-align:right;">Total Money (KSh)</th>
                        <th style="text-align:right;">% Share</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($statusTotals as $st):
                        $pct = $totalValInPeriod > 0 ? round(($st['sum_amount'] / $totalValInPeriod) * 100, 1) : 0;
                    ?>
                    <tr>
                        <td>
                            <span class="admin-badge <?= $st['status']==='Claimed' ? 'badge-active' : ($st['status']==='Unclaimed' ? 'badge-inactive' : 'badge-status') ?>">
                                <?= htmlspecialchars($st['status']) ?>
                            </span>
                        </td>
                        <td style="text-align:right;font-weight:600;"><?= number_format($st['cnt']) ?></td>
                        <td style="text-align:right;font-weight:700;color:var(--text-primary);">
                            KSh <?= number_format($st['sum_amount'], 2) ?>
                        </td>
                        <td style="text-align:right;color:var(--text-muted);font-weight:600;"><?= $pct ?>%</td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr style="border-top:2px solid #e5e7eb;font-weight:700;background:#fafafa;">
                        <td>Total in Selected Range</td>
                        <td style="text-align:right;"><?= number_format($totalRecordsInPeriod) ?></td>
                        <td style="text-align:right;color:var(--airtel-red);">KSh <?= number_format($totalValInPeriod, 2) ?></td>
                        <td style="text-align:right;">100%</td>
                    </tr>
                </tfoot>
            </table>
        </div>
        <?php endif; ?>
    </div>

    <!-- Monthly Multi-Year Trend Chart -->
    <div class="admin-card">
        <div class="admin-card-title">
            <i class="fa-solid fa-chart-column"></i> Monthly Asset Trends
        </div>
        <p style="font-size:.82rem;color:var(--text-secondary);margin-bottom:1rem;">
            Monthly account compilation timeline across selected years.
        </p>
        <div class="chart-wrap">
            <canvas id="report-monthly-chart"></canvas>
        </div>
    </div>

</div>

<!-- ══════════════════════════════════════════════════════════
     SECTION 3 — Detailed Multi-Year Monthly Table
════════════════════════════════════════════════════════════ -->
<div class="admin-card admin-section">
    <div class="admin-card-title" style="justify-content:space-between;flex-wrap:wrap;gap:.5rem;">
        <span><i class="fa-solid fa-list-check"></i> Monthly Asset Breakdown Table</span>
        <span style="font-size:.8rem;color:var(--text-muted);font-weight:400;">
            Showing breakdown for <?= count($monthlyData) ?> month-status categories
        </span>
    </div>

    <?php if (empty($monthlyData)): ?>
    <div class="admin-empty-state"><i class="fa-solid fa-chart-simple"></i><p>No data available for selected filters.</p></div>
    <?php else: ?>
    <div class="admin-table-wrap" style="max-height:420px;overflow-y:auto;">
        <table class="admin-table">
            <thead>
                <tr>
                    <th>Month &amp; Year</th>
                    <th>Claim Status</th>
                    <th style="text-align:right;">Total Accounts</th>
                    <th style="text-align:right;">Total Money (KSh)</th>
                    <th style="text-align:right;">Average Amount per Account</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($monthlyData as $row):
                    $avg = $row['record_count'] > 0 ? ($row['total_amount'] / $row['record_count']) : 0;
                ?>
                <tr>
                    <td><strong><i class="fa-regular fa-calendar" style="color:var(--text-muted);margin-right:.4rem;"></i><?= htmlspecialchars($row['month_label']) ?></strong></td>
                    <td>
                        <span class="admin-badge <?= $row['status']==='Claimed' ? 'badge-active' : ($row['status']==='Unclaimed' ? 'badge-inactive' : 'badge-status') ?>">
                            <?= htmlspecialchars($row['status']) ?>
                        </span>
                    </td>
                    <td style="text-align:right;font-weight:600;"><?= number_format($row['record_count']) ?></td>
                    <td style="text-align:right;font-weight:700;color:var(--text-primary);">KSh <?= number_format($row['total_amount'], 2) ?></td>
                    <td style="text-align:right;color:var(--text-muted);">KSh <?= number_format($avg, 2) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const rawData = <?= json_encode($monthlyData) ?>;
    if (!rawData || !rawData.length) return;

    const monthMap = {};
    rawData.forEach(r => {
        const label = r.month_label;
        if (!monthMap[label]) monthMap[label] = 0;
        monthMap[label] += parseInt(r.record_count, 10);
    });

    const labels = Object.keys(monthMap);
    const counts = Object.values(monthMap);

    const ctx = document.getElementById('report-monthly-chart');
    if (ctx && typeof Chart !== 'undefined') {
        new Chart(ctx, {
            type: 'bar',
            data: {
                labels: labels,
                datasets: [{
                    label: 'Total Accounts',
                    data: counts,
                    backgroundColor: '#CC0000',
                    borderRadius: 6,
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: {
                    y: { beginAtZero: true, grid: { color: '#f3f4f6' } },
                    x: { grid: { display: false } }
                }
            }
        });
    }
});
</script>

<?php require_once __DIR__ . '/includes/admin_layout_footer.php'; ?>
