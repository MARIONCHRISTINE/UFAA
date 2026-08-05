<?php
/**
 * UFAA - Holder Confirmation Letter Web Preview & Printable View
 * Renders an official, beautifully styled HTML letterhead document
 * with built-in Download PDF and Print capabilities.
 */

require_once __DIR__ . '/config.php';

$pdo = get_db_connection();
if (!$pdo) {
    die("Database connection error.");
}

$recordId = $_GET['id'] ?? $_GET['record_id'] ?? null;
if (!$recordId || !is_numeric($recordId)) {
    die("Invalid or missing Asset Record ID.");
}

// Fetch record
$stmt = $pdo->prepare("SELECT * FROM `unclaimed_assets` WHERE `record_id` = ?");
$stmt->execute([(int)$recordId]);
$asset = $stmt->fetch();

if (!$asset) {
    die("Asset record not found for ID: " . (int)$recordId);
}

// Consolidate same ID records for genuine, valid ID numbers
$records = [$asset];
$idPassport = trim((string)($asset['id_passport_no'] ?? ''));
$invalidPlaceholders = ['N/A', 'NA', 'NONE', 'NIL', 'UNKNOWN', 'NOT PROVIDED', 'NO ID', 'NOT AVAILABLE', '0', '-', '--', '000000', '123456', 'NULL', 'INVALID'];

if ($idPassport !== '' && strlen($idPassport) >= 3 && !in_array(strtoupper($idPassport), $invalidPlaceholders, true)) {
    $stmtSame = $pdo->prepare("
        SELECT * FROM `unclaimed_assets`
        WHERE TRIM(`id_passport_no`) = ?
          AND TRIM(`id_passport_no`) != ''
          AND `record_id` != ?
        LIMIT 50
    ");
    $stmtSame->execute([$idPassport, (int)$recordId]);
    $sameRecords = $stmtSame->fetchAll();
    if (!empty($sameRecords)) {
        $records = array_merge($records, $sameRecords);
    }
}

// Mark letter as generated in DB
try {
    $recordIds = array_column($records, 'record_id');
    if (!empty($recordIds)) {
        $inClause = implode(',', array_map('intval', $recordIds));
        $pdo->exec("
            UPDATE `unclaimed_assets`
            SET `letter_generated` = 'Yes',
                `letter_generated_date` = COALESCE(`letter_generated_date`, NOW()),
                `status` = CASE WHEN `status` = 'Unclaimed' THEN 'Letter Generated' ELSE `status` END
            WHERE `record_id` IN ($inClause)
        ");
    }
} catch (Exception $e) {
    // Ignore DB update errors
}

$refNo = 'UFAA/HL/' . date('Y') . '/' . str_pad($asset['record_id'], 5, '0', STR_PAD_LEFT);
$dateStr = date('d F Y');
$ownerName = !empty($asset['owner_name']) ? strtoupper($asset['owner_name']) : 'NOT PROVIDED';
$idNo = !empty($asset['id_passport_no']) ? $asset['id_passport_no'] : 'NOT PROVIDED';

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Holder Confirmation Letter - <?= htmlspecialchars($asset['record_id']) ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Segoe UI', Arial, sans-serif; background-color: #0f172a; color: #1e293b; padding-bottom: 40px; }

        /* Top Action Bar */
        .action-bar {
            background-color: #1e293b;
            color: #ffffff;
            padding: 14px 24px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: sticky;
            top: 0;
            z-index: 100;
            box-shadow: 0 4px 12px rgba(0,0,0,0.3);
            border-bottom: 1px solid #334155;
        }
        .action-title { font-size: 1.1rem; font-weight: 600; color: #f8fafc; display: flex; align-items: center; gap: 10px; }
        .action-buttons { display: flex; gap: 12px; }
        .btn-act {
            padding: 8px 16px;
            border-radius: 6px;
            font-size: 0.9rem;
            font-weight: 600;
            text-decoration: none;
            cursor: pointer;
            border: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.2s ease;
        }
        .btn-download { background-color: #dc2626; color: #ffffff; }
        .btn-download:hover { background-color: #b91c1c; }
        .btn-print { background-color: #0284c7; color: #ffffff; }
        .btn-print:hover { background-color: #0369a1; }
        .btn-close { background-color: #475569; color: #ffffff; }
        .btn-close:hover { background-color: #334155; }

        /* Letter Wrapper */
        .page-container {
            display: flex;
            justify-content: center;
            padding: 30px 15px;
        }
        .paper {
            background-color: #ffffff;
            width: 210mm;
            min-height: 297mm;
            padding: 20mm 20mm 20mm 20mm;
            box-shadow: 0 10px 30px rgba(0,0,0,0.5);
            border-radius: 4px;
            position: relative;
            font-size: 13px;
            line-height: 1.5;
        }

        /* Corporate Letterhead Header */
        .letterhead {
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 2.5px solid #dc2626;
            padding-bottom: 15px;
            margin-bottom: 25px;
        }
        .company-logo img {
            height: 55px;
            width: auto;
            object-fit: contain;
        }
        .company-details {
            text-align: right;
            font-size: 11px;
            color: #475569;
            line-height: 1.4;
        }
        .company-name {
            font-size: 16px;
            font-weight: 800;
            color: #dc2626;
            letter-spacing: 0.5px;
            margin-bottom: 2px;
        }

        .meta-row { display: flex; justify-content: space-between; font-weight: bold; font-size: 13px; margin-bottom: 20px; }

        .addressee { margin-bottom: 20px; line-height: 1.4; font-size: 13px; }
        .addressee .bold { font-weight: bold; }

        .subject { font-size: 14px; font-weight: bold; color: #dc2626; border-bottom: 1.5px solid #dc2626; padding-bottom: 4px; margin-bottom: 20px; text-transform: uppercase; }

        .salutation { font-size: 13px; font-weight: 600; margin-bottom: 12px; }
        .letter-body { font-size: 13px; text-align: justify; margin-bottom: 20px; line-height: 1.6; }

        /* Claimant Box */
        .box { border: 1px solid #e2e8f0; border-radius: 4px; overflow: hidden; margin-bottom: 20px; }
        .box-title { background-color: #f8fafc; font-weight: bold; padding: 8px 12px; border-bottom: 1px solid #e2e8f0; font-size: 13px; color: #1e293b; }
        .box-row { display: flex; border-bottom: 1px solid #f1f5f9; padding: 8px 12px; }
        .box-row:last-child { border-bottom: none; }
        .box-label { width: 150px; color: #475569; }
        .box-val { font-weight: bold; color: #0f172a; }

        /* Assets Table */
        .asset-table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
        .asset-table th { background-color: #dc2626; color: #ffffff; text-align: left; padding: 8px 10px; font-size: 12px; font-weight: 600; border: 1px solid #dc2626; }
        .asset-table th.text-right { text-align: right; }
        .asset-table th.text-center { text-align: center; }
        .asset-table td { padding: 8px 10px; border: 1px solid #e2e8f0; font-size: 12px; }
        .asset-table td.text-right { text-align: right; }
        .asset-table td.text-center { text-align: center; }
        .asset-table tr:nth-child(even) { background-color: #f8fafc; }

        .closing-note { font-size: 13px; text-align: justify; margin-bottom: 25px; line-height: 1.6; }

        /* Signatures Section */
        .sig-section { display: flex; justify-content: space-between; align-items: flex-end; margin-top: 30px; page-break-inside: avoid; }
        .sig-box { line-height: 1.5; font-size: 13px; }
        .stamp-box {
            width: 240px;
            height: 100px;
            border: 1px dashed #cbd5e1;
            background-color: #f8fafc;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #94a3b8;
            font-weight: bold;
            font-size: 11px;
            border-radius: 4px;
        }

        /* Print Media Styles */
        @media print {
            @page {
                size: A4 portrait;
                margin: 10mm 15mm 10mm 15mm;
            }
            body { background: white; padding: 0; }
            .action-bar { display: none !important; }
            .page-container { padding: 0; }
            .paper { box-shadow: none; width: 100%; min-height: auto; padding: 0; border-radius: 0; }
        }
    </style>
</head>
<body>

    <!-- Action Bar -->
    <div class="action-bar">
        <div class="action-title">
            <i class="fa-solid fa-file-contract" style="color:#ef4444;"></i>
            Airtel Money Holder Confirmation Letter #<?= htmlspecialchars($asset['record_id']) ?>
        </div>
        <div class="action-buttons">
            <button onclick="window.print()" class="btn-act btn-print" title="Print Letter or Save as PDF via Print Dialog">
                <i class="fa-solid fa-print"></i> Print Letter
            </button>
            <button onclick="window.close()" class="btn-act btn-close">
                <i class="fa-solid fa-xmark"></i> Close
            </button>
        </div>
    </div>

    <!-- Letter Container -->
    <div class="page-container">
        <div class="paper">
            
            <!-- Corporate Header -->
            <div class="letterhead">
                <div class="company-logo">
                    <img src="logo.png" alt="Airtel Logo">
                </div>
                <div class="company-details">
                    <div class="company-name">AIRTEL MONEY KENYA LIMITED</div>
                    <div>Airtel Park, Parkside Towers, Mombasa Road</div>
                    <div>P.O. Box 73335 - 00200, Nairobi, Kenya</div>
                    <div>Tel: +254 733 100 100 | www.airtelkenya.com</div>
                </div>
            </div>

            <div class="meta-row">
                <div>REF NO: <?= htmlspecialchars($refNo) ?></div>
                <div>DATE: <?= htmlspecialchars($dateStr) ?></div>
            </div>

            <div class="addressee">
                <div class="bold">TO:</div>
                <div>The Chief Executive Officer / Managing Director</div>
                <div class="bold">Unclaimed Financial Assets Authority (UFAA)</div>
                <div>P.O. Box 28235 - 00200, Nairobi, Kenya</div>
            </div>

            <div class="subject">
                RE: HOLDER CONFIRMATION LETTER — CLAIMANT ASSET DETAILS
            </div>

            <div class="salutation">
                Dear Sir / Madam,
            </div>

            <div class="letter-body">
                We write to confirm that the unclaimed financial asset(s) listed below are recorded under <strong>Airtel Money Kenya Limited</strong> and have been processed in compliance with the Unclaimed Financial Assets Authority guidelines.
            </div>

            <div class="box">
                <div class="box-title">CLAIMANT INFORMATION</div>
                <div class="box-row">
                    <div class="box-label">Owner Name:</div>
                    <div class="box-val"><?= htmlspecialchars($ownerName) ?></div>
                </div>
                <div class="box-row">
                    <div class="box-label">ID / Passport No:</div>
                    <div class="box-val"><?= htmlspecialchars($idNo) ?></div>
                </div>
            </div>

            <!-- Asset Details Table -->
            <table class="asset-table">
                <thead>
                    <tr>
                        <th class="text-center" style="width: 30px;">#</th>
                        <th>Account Number</th>
                        <th>Last Transaction</th>
                        <th class="text-right">Due Amount</th>
                        <th class="text-center">Compilation Date</th>
                    </tr>
                </thead>
                <tbody>
                    <?php $i = 1; foreach ($records as $rec): ?>
                        <tr>
                            <td class="text-center"><?= $i++ ?></td>
                            <td><?= htmlspecialchars($rec['account_number'] ?: '-') ?></td>
                            <td><?= htmlspecialchars(substr($rec['last_transaction'] ?: '-', 0, 30)) ?></td>
                            <td class="text-right"><?= htmlspecialchars($rec['due_amount'] ?: '-') ?></td>
                            <td class="text-center"><?= htmlspecialchars($rec['compilation_date'] ?: '-') ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <div class="closing-note">
                This letter confirms that the above unclaimed asset records belong to the specified owner as per our records submitted to the Unclaimed Financial Assets Authority.
            </div>

            <div style="font-size: 13px; font-weight: 600; margin-bottom: 20px;">
                Yours faithfully,
            </div>

            <div class="sig-section">
                <div class="sig-box">
                    <div style="font-weight: bold; color: #dc2626; margin-bottom: 6px;">AIRTEL MONEY KENYA LIMITED</div>
                    <div style="margin-bottom: 8px;">Signature: ___________________________</div>
                    <div style="margin-bottom: 2px;">Name: <strong>Peter Mwangi</strong></div>
                    <div style="margin-bottom: 2px;">Title: <strong>Risk and Compliance Lead</strong></div>
                    <div>Date: <?= htmlspecialchars($dateStr) ?></div>
                </div>
                <div class="stamp-box">
                    [ OFFICIAL COMPANY STAMP ]
                </div>
            </div>
        </div>
    </div>

</body>
</html>
