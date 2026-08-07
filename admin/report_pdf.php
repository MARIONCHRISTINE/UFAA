<?php
/**
 * UFAA Admin — Formal Executive PDF Report Generator
 * Renders a standalone, publication-ready executive compliance report for print/PDF export.
 */

require_once __DIR__ . '/includes/admin_auth.php';

$pdo = admin_get_pdo();

// ── Multi-Year Range Bootstrap ────────────────────────────────────────────
$minDate = '2015-01-01';
$maxDate = date('Y-m-d');

if ($pdo) {
    try {
        $minDb = $pdo->query("SELECT MIN(DATE(compilation_date)) FROM unclaimed_assets WHERE compilation_date IS NOT NULL AND compilation_date != '0000-00-00'")->fetchColumn();
        if ($minDb) $minDate = $minDb;
    } catch (Exception $e) {}
}

// ── Extract Filter Parameters ──────────────────────────────────────────────
$selectedYear = $_GET['year']       ?? '';
$dateFrom     = $_GET['date_from']  ?? ($selectedYear ? "{$selectedYear}-01-01" : $minDate);
$dateTo       = $_GET['date_to']    ?? ($selectedYear ? "{$selectedYear}-12-31" : $maxDate);
$statusFilter = $_GET['status']     ?? '';
$letterFilter = $_GET['letter']     ?? '';

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

// ── Fetch Report Aggregations ──────────────────────────────────────────────
$monthlyData          = [];
$statusTotals         = [];
$totalValInPeriod     = 0;
$totalRecordsInPeriod = 0;
$missingOwner         = 0;
$missingId            = 0;
$missingAccount       = 0;
$dupAccounts          = 0;

if ($pdo) {
    try {
        // Status Totals
        $stmtStatus = $pdo->prepare("
            SELECT status, COUNT(*) as cnt, SUM(COALESCE(due_amount, 0)) as sum_amount
            FROM unclaimed_assets $whereSQL GROUP BY status
        ");
        $stmtStatus->execute($params);
        $statusTotals = $stmtStatus->fetchAll();

        foreach ($statusTotals as $st) {
            $totalRecordsInPeriod += (int)$st['cnt'];
            $totalValInPeriod     += (float)$st['sum_amount'];
        }

        // Monthly breakdown
        $stmtM = $pdo->prepare("
            SELECT DATE_FORMAT(compilation_date, '%b %Y') as month_label, DATE_FORMAT(compilation_date, '%Y-%m') as month_key,
                   status, COUNT(*) as record_count, SUM(COALESCE(due_amount, 0)) as total_amount
            FROM unclaimed_assets $whereSQL GROUP BY month_key, month_label, status ORDER BY month_key ASC, status ASC
        ");
        $stmtM->execute($params);
        $monthlyData = $stmtM->fetchAll();

        // Data Quality
        $missingOwner   = (int)$pdo->query("SELECT COUNT(*) FROM unclaimed_assets WHERE owner_name IS NULL OR TRIM(owner_name)=''")->fetchColumn();
        $missingId      = (int)$pdo->query("SELECT COUNT(*) FROM unclaimed_assets WHERE id_passport_no IS NULL OR TRIM(id_passport_no)=''")->fetchColumn();
        $missingAccount = (int)$pdo->query("SELECT COUNT(*) FROM unclaimed_assets WHERE account_number IS NULL OR TRIM(account_number)=''")->fetchColumn();
        $dupAccounts    = (int)$pdo->query("SELECT COUNT(*) FROM (SELECT account_number FROM unclaimed_assets WHERE account_number IS NOT NULL AND TRIM(account_number) != '' GROUP BY account_number HAVING COUNT(*) > 1) t")->fetchColumn();

    } catch (Exception $e) {}
}

$reportId = 'RPT-' . date('Ymd-His');
$adminName = htmlspecialchars($_SESSION['admin_user']['fullname'] ?? $_SESSION['admin_user']['username'] ?? 'Compliance Admin');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Executive Compliance Report — UFAA & Airtel Kenya</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Outfit', sans-serif;
            background: #f4f6f9;
            color: #1e293b;
            padding: 2rem;
            line-height: 1.5;
        }
        .pdf-page {
            max-width: 900px;
            margin: 0 auto;
            background: #ffffff;
            padding: 2.5rem 3rem;
            border-radius: 12px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
            border: 1px solid #e2e8f0;
        }
        .pdf-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 3px solid #CC0000;
            padding-bottom: 1.25rem;
            margin-bottom: 1.5rem;
        }
        .pdf-brand h1 {
            font-size: 1.35rem;
            color: #CC0000;
            font-weight: 800;
            letter-spacing: -0.3px;
        }
        .pdf-brand h2 {
            font-size: 1.05rem;
            color: #1e3a5f;
            font-weight: 700;
            margin-top: 2px;
        }
        .pdf-meta {
            text-align: right;
            font-size: 0.8rem;
            color: #64748b;
        }
        .pdf-meta strong { color: #1e293b; }

        .pdf-summary-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 1rem;
            margin-bottom: 2rem;
        }
        .pdf-stat-box {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 1rem;
            border-left: 4px solid #CC0000;
        }
        .pdf-stat-label {
            font-size: 0.7rem;
            text-transform: uppercase;
            letter-spacing: 0.8px;
            color: #64748b;
            font-weight: 600;
        }
        .pdf-stat-val {
            font-size: 1.35rem;
            font-weight: 800;
            color: #0f172a;
            margin-top: 2px;
        }

        .pdf-section-title {
            font-size: 1rem;
            font-weight: 700;
            color: #1e3a5f;
            margin-bottom: 0.75rem;
            display: flex;
            align-items: center;
            gap: 0.4rem;
            border-bottom: 1px solid #e2e8f0;
            padding-bottom: 0.4rem;
        }
        .pdf-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.83rem;
            margin-bottom: 2rem;
        }
        .pdf-table th {
            background: #1e3a5f;
            color: #ffffff;
            text-align: left;
            padding: 0.6rem 0.8rem;
            font-weight: 600;
            font-size: 0.74rem;
            text-transform: uppercase;
            letter-spacing: 0.6px;
        }
        .pdf-table td {
            padding: 0.55rem 0.8rem;
            border-bottom: 1px solid #e2e8f0;
        }
        .pdf-table tfoot td {
            font-weight: 700;
            background: #f8fafc;
            border-top: 2px solid #cbd5e1;
        }

        .pdf-signatures {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 1.5rem;
            margin-top: 3rem;
            padding-top: 1.5rem;
            border-top: 1px solid #cbd5e1;
        }
        .sig-box {
            text-align: center;
        }
        .sig-line {
            border-bottom: 1px dashed #64748b;
            height: 40px;
            margin-bottom: 0.5rem;
        }
        .sig-title {
            font-size: 0.82rem;
            font-weight: 700;
            color: #0f172a;
        }
        .sig-sub {
            font-size: 0.74rem;
            color: #64748b;
        }

        .no-print-toolbar {
            max-width: 900px;
            margin: 0 auto 1.25rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .btn-print {
            background: #CC0000;
            color: #fff;
            border: none;
            padding: 0.6rem 1.4rem;
            border-radius: 8px;
            font-weight: 700;
            font-size: 0.88rem;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            box-shadow: 0 3px 10px rgba(204,0,0,0.25);
        }
        .btn-print:hover { background: #990000; }

        @media print {
            body { background: #fff; padding: 0; }
            .pdf-page { box-shadow: none; border: none; padding: 0; max-width: 100%; }
            .no-print-toolbar { display: none !important; }
        }
    </style>
</head>
<body>

<div class="no-print-toolbar">
    <a href="reports.php" style="color:#64748b;text-decoration:none;font-size:0.85rem;font-weight:600;">
        <i class="fa-solid fa-arrow-left"></i> Back to Reports
    </a>
    <button onclick="window.print()" class="btn-print">
        <i class="fa-solid fa-file-pdf"></i> Save / Print PDF Report
    </button>
</div>

<div class="pdf-page">

    <!-- Header -->
    <div class="pdf-header">
        <div class="pdf-brand">
            <h1>UNCLAIMED FINANCIAL ASSETS AUTHORITY (UFAA)</h1>
            <h2>Executive Compliance & Audit Report</h2>
        </div>
        <div class="pdf-meta">
            <div>Report Ref: <strong><?= $reportId ?></strong></div>
            <div>Period: <strong><?= date('d M Y', strtotime($dateFrom)) ?> &ndash; <?= date('d M Y', strtotime($dateTo)) ?></strong></div>
            <div>Generated By: <strong><?= $adminName ?></strong></div>
            <div>Timestamp: <strong><?= date('d M Y, H:i') ?> EAT</strong></div>
        </div>
    </div>

    <!-- Summary KPI Boxes -->
    <div class="pdf-summary-grid">
        <div class="pdf-stat-box" style="border-left-color:#1e3a5f;">
            <div class="pdf-stat-label">Total Accounts</div>
            <div class="pdf-stat-val"><?= number_format($totalRecordsInPeriod) ?></div>
        </div>
        <div class="pdf-stat-box" style="border-left-color:#CC0000;">
            <div class="pdf-stat-label">Total Asset Value</div>
            <div class="pdf-stat-val">KSh <?= number_format($totalValInPeriod, 2) ?></div>
        </div>
        <div class="pdf-stat-box" style="border-left-color:#16a34a;">
            <div class="pdf-stat-label">Claimed Value</div>
            <?php
            $claimedVal = 0;
            foreach ($statusTotals as $s) if ($s['status'] === 'Claimed') $claimedVal = $s['sum_amount'];
            ?>
            <div class="pdf-stat-val" style="color:#16a34a;">KSh <?= number_format($claimedVal, 2) ?></div>
        </div>
        <div class="pdf-stat-box" style="border-left-color:#ea580c;">
            <div class="pdf-stat-label">Unclaimed Value</div>
            <?php
            $unclaimedVal = 0;
            foreach ($statusTotals as $s) if ($s['status'] === 'Unclaimed') $unclaimedVal = $s['sum_amount'];
            ?>
            <div class="pdf-stat-val" style="color:#ea580c;">KSh <?= number_format($unclaimedVal, 2) ?></div>
        </div>
    </div>

    <!-- Section 1: Financial Totals Table -->
    <div class="pdf-section-title">
        <i class="fa-solid fa-vault" style="color:#CC0000;"></i> 1. Financial Asset Summary by Status
    </div>
    <table class="pdf-table">
        <thead>
            <tr>
                <th>Claim Status</th>
                <th style="text-align:right;">Total Accounts</th>
                <th style="text-align:right;">Total Value (KSh)</th>
                <th style="text-align:right;">% Share</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($statusTotals)): ?>
            <tr><td colspan="4" style="text-align:center;color:#64748b;">No records found in selected range.</td></tr>
            <?php else: ?>
            <?php foreach ($statusTotals as $st):
                $pct = $totalValInPeriod > 0 ? round(($st['sum_amount'] / $totalValInPeriod) * 100, 1) : 0;
            ?>
            <tr>
                <td><strong><?= htmlspecialchars($st['status']) ?></strong></td>
                <td style="text-align:right;"><?= number_format($st['cnt']) ?></td>
                <td style="text-align:right;font-weight:700;">KSh <?= number_format($st['sum_amount'], 2) ?></td>
                <td style="text-align:right;color:#64748b;"><?= $pct ?>%</td>
            </tr>
            <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
        <tfoot>
            <tr>
                <td>Total Period Portfolio</td>
                <td style="text-align:right;"><?= number_format($totalRecordsInPeriod) ?></td>
                <td style="text-align:right;color:#CC0000;">KSh <?= number_format($totalValInPeriod, 2) ?></td>
                <td style="text-align:right;">100%</td>
            </tr>
        </tfoot>
    </table>

    <!-- Section 2: Monthly Breakdown -->
    <div class="pdf-section-title">
        <i class="fa-solid fa-calendar-days" style="color:#1e3a5f;"></i> 2. Monthly Asset Compilation Breakdown
    </div>
    <table class="pdf-table">
        <thead>
            <tr>
                <th>Month &amp; Year</th>
                <th>Claim Status</th>
                <th style="text-align:right;">Account Count</th>
                <th style="text-align:right;">Total Money (KSh)</th>
                <th style="text-align:right;">Avg Account Value</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($monthlyData)): ?>
            <tr><td colspan="5" style="text-align:center;color:#64748b;">No monthly trend data available.</td></tr>
            <?php else: ?>
            <?php foreach ($monthlyData as $row):
                $avg = $row['record_count'] > 0 ? ($row['total_amount'] / $row['record_count']) : 0;
            ?>
            <tr>
                <td><?= htmlspecialchars($row['month_label']) ?></td>
                <td><?= htmlspecialchars($row['status']) ?></td>
                <td style="text-align:right;"><?= number_format($row['record_count']) ?></td>
                <td style="text-align:right;font-weight:700;">KSh <?= number_format($row['total_amount'], 2) ?></td>
                <td style="text-align:right;color:#64748b;">KSh <?= number_format($avg, 2) ?></td>
            </tr>
            <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>

    <!-- Section 3: Data Quality Audit -->
    <div class="pdf-section-title">
        <i class="fa-solid fa-triangle-exclamation" style="color:#ea580c;"></i> 3. Data Completeness &amp; Quality Audit
    </div>
    <table class="pdf-table">
        <thead>
            <tr>
                <th>Quality Audit Parameter</th>
                <th style="text-align:right;">Affected Records</th>
                <th style="text-align:right;">Compliance Status</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>Missing Owner Names</td>
                <td style="text-align:right;"><?= number_format($missingOwner) ?></td>
                <td style="text-align:right;color:<?= $missingOwner > 0 ? '#dc2626':'#16a34a' ?>;font-weight:700;">
                    <?= $missingOwner > 0 ? 'Action Required' : 'Complete' ?>
                </td>
            </tr>
            <tr>
                <td>Missing ID / Passport Numbers</td>
                <td style="text-align:right;"><?= number_format($missingId) ?></td>
                <td style="text-align:right;color:<?= $missingId > 0 ? '#dc2626':'#16a34a' ?>;font-weight:700;">
                    <?= $missingId > 0 ? 'Action Required' : 'Complete' ?>
                </td>
            </tr>
            <tr>
                <td>Missing Account Numbers</td>
                <td style="text-align:right;"><?= number_format($missingAccount) ?></td>
                <td style="text-align:right;color:<?= $missingAccount > 0 ? '#dc2626':'#16a34a' ?>;font-weight:700;">
                    <?= $missingAccount > 0 ? 'Action Required' : 'Complete' ?>
                </td>
            </tr>
            <tr>
                <td>Duplicate Account Numbers</td>
                <td style="text-align:right;"><?= number_format($dupAccounts) ?></td>
                <td style="text-align:right;color:<?= $dupAccounts > 0 ? '#dc2626':'#16a34a' ?>;font-weight:700;">
                    <?= $dupAccounts > 0 ? 'Action Required' : 'Complete' ?>
                </td>
            </tr>
        </tbody>
    </table>

    <!-- Signatures -->
    <div class="pdf-signatures">
        <div class="sig-box">
            <div class="sig-line"></div>
            <div class="sig-title">Prepared By</div>
            <div class="sig-sub">Compliance Officer</div>
        </div>
        <div class="sig-box">
            <div class="sig-line"></div>
            <div class="sig-title">Reviewed By</div>
            <div class="sig-sub">Head of Compliance</div>
        </div>
        <div class="sig-box">
            <div class="sig-line"></div>
            <div class="sig-title">Official Seal</div>
            <div class="sig-sub">UFAA Authority Stamp</div>
        </div>
    </div>

</div>

</body>
</html>
