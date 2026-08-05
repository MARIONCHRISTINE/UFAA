<?php
/**
 * UFAA - Clean & Minimal Holder Confirmation Letter PDF Generator
 * Core class and reusable function for generating, streaming, and saving PDF holder letters.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/fpdf.php';

if (!class_exists('UFAA_Clean_Letter_PDF')) {

    class UFAA_Clean_Letter_PDF extends FPDF
    {
        private $refNo;
        private $dateStr;

        public function __construct($refNo = '', $dateStr = '')
        {
            parent::__construct('P', 'mm', 'A4');
            $this->refNo = $refNo ?: ('UFAA/HL/' . date('Y') . '/' . rand(10000, 99999));
            $this->dateStr = $dateStr ?: date('d F Y');
            $this->SetAutoPageBreak(true, 20);
            $this->SetMargins(20, 20, 20);
        }

        /**
         * Safely converts UTF-8 strings into ISO-8859-1 for FPDF standard fonts
         */
        public function toPdfText($str)
        {
            if ($str === null || $str === '') {
                return '';
            }
            $str = (string)$str;
            $str = str_replace(
                ['—', '–', '“', '”', '‘', '’', '…'],
                ['-', '-', '"', '"', "'", "'", '...'],
                $str
            );
            if (function_exists('mb_convert_encoding')) {
                return mb_convert_encoding($str, 'ISO-8859-1', 'UTF-8');
            }
            if (function_exists('iconv')) {
                $conv = @iconv('UTF-8', 'ISO-8859-1//TRANSLIT//IGNORE', $str);
                if ($conv !== false) {
                    return $conv;
                }
            }
            return (string)$str;
        }

        function Header()
        {
            $this->SetDrawColor(200, 0, 0);
            $this->SetLineWidth(1);
            $this->Line(20, 15, 190, 15);
            $this->Ln(2);
        }

        function Footer()
        {
            $this->SetY(-18);
            $this->SetFont('Helvetica', 'I', 8);
            $this->SetTextColor(120, 120, 120);
            $this->Cell(0, 4, $this->toPdfText('Unclaimed Financial Assets Authority (UFAA) - Official Holder Confirmation Letter'), 0, 1, 'C');
            $this->Cell(0, 4, 'Page ' . $this->PageNo() . ' of {nb}', 0, 0, 'C');
        }

        public function buildLetter($customerData, $records)
        {
            $this->AliasNbPages('{nb}');
            $this->AddPage();

            // Title
            $this->SetFont('Helvetica', 'B', 15);
            $this->SetTextColor(200, 0, 0);
            $this->Cell(0, 8, $this->toPdfText('HOLDER CONFIRMATION LETTER'), 0, 1, 'C');
            
            $this->SetFont('Helvetica', '', 9);
            $this->SetTextColor(100, 100, 100);
            $this->Cell(0, 4, $this->toPdfText('Unclaimed Financial Assets Compliance'), 0, 1, 'C');
            $this->Ln(4);

            // Ref No & Date
            $this->SetFont('Helvetica', 'B', 9.5);
            $this->SetTextColor(40, 40, 40);
            $this->Cell(90, 5, $this->toPdfText('REF NO: ' . $this->refNo), 0, 0, 'L');
            $this->Cell(80, 5, $this->toPdfText('DATE: ' . $this->dateStr), 0, 1, 'R');
            $this->Ln(4);

            // Addressee
            $this->SetFont('Helvetica', 'B', 9.5);
            $this->Cell(0, 4.5, $this->toPdfText('TO:'), 0, 1, 'L');
            $this->SetFont('Helvetica', '', 9.5);
            $this->Cell(0, 4.5, $this->toPdfText('The Chief Executive Officer / Managing Director'), 0, 1, 'L');
            $this->SetFont('Helvetica', 'B', 9.5);
            $this->Cell(0, 4.5, $this->toPdfText('Unclaimed Financial Assets Authority (UFAA)'), 0, 1, 'L');
            $this->SetFont('Helvetica', '', 9.5);
            $this->Cell(0, 4.5, $this->toPdfText('P.O. Box 28235 - 00200, Nairobi, Kenya'), 0, 1, 'L');
            $this->Ln(5);

            // Subject Line
            $this->SetFont('Helvetica', 'B', 10.5);
            $this->SetTextColor(200, 0, 0);
            $this->Cell(0, 5.5, $this->toPdfText('RE: CLAIMANT ASSET DETAILS CONFIRMATION'), 0, 1, 'L');
            $this->SetDrawColor(200, 0, 0);
            $this->SetLineWidth(0.4);
            $this->Line(20, $this->GetY(), 190, $this->GetY());
            $this->Ln(5);

            // Claimant Details Box
            $ownerName = !empty($customerData['owner_name']) ? strtoupper((string)$customerData['owner_name']) : 'NOT PROVIDED';
            $idNo = !empty($customerData['id_passport_no']) ? (string)$customerData['id_passport_no'] : 'NOT PROVIDED';

            $this->SetFillColor(245, 245, 245);
            $this->SetDrawColor(220, 220, 220);
            $this->SetFont('Helvetica', 'B', 9.5);
            $this->SetTextColor(40, 40, 40);
            $this->Cell(170, 6.5, $this->toPdfText('  CLAIMANT INFORMATION'), 1, 1, 'L', true);
            
            $this->SetFont('Helvetica', '', 9);
            $this->Cell(45, 5.5, $this->toPdfText(' Owner Name:'), 'L', 0);
            $this->SetFont('Helvetica', 'B', 9);
            $this->Cell(125, 5.5, $this->toPdfText($ownerName), 'R', 1);

            $this->SetFont('Helvetica', '', 9);
            $this->Cell(45, 5.5, $this->toPdfText(' ID / Passport No:'), 'LB', 0);
            $this->SetFont('Helvetica', 'B', 9);
            $this->Cell(125, 5.5, $this->toPdfText($idNo), 'RB', 1);
            $this->Ln(5);

            // Unclaimed Asset Details Table Header
            $this->SetFont('Helvetica', 'B', 8.5);
            $this->SetFillColor(200, 0, 0);
            $this->SetTextColor(255, 255, 255);
            $this->Cell(10, 6.5, '#', 1, 0, 'C', true);
            $this->Cell(50, 6.5, 'Account Number', 1, 0, 'L', true);
            $this->Cell(45, 6.5, 'Last Transaction', 1, 0, 'L', true);
            $this->Cell(35, 6.5, 'Due Amount', 1, 0, 'R', true);
            $this->Cell(30, 6.5, 'Compilation Date', 1, 1, 'C', true);

            $this->SetFont('Helvetica', '', 8.5);
            $this->SetTextColor(30, 30, 30);
            $i = 1;
            foreach ($records as $rec) {
                // Multi-page page break check: redraw table header on new page
                if ($this->GetY() + 15 > $this->PageBreakTrigger) {
                    $this->AddPage();
                    $this->SetFont('Helvetica', 'B', 8.5);
                    $this->SetFillColor(200, 0, 0);
                    $this->SetTextColor(255, 255, 255);
                    $this->Cell(10, 6.5, '#', 1, 0, 'C', true);
                    $this->Cell(50, 6.5, 'Account Number', 1, 0, 'L', true);
                    $this->Cell(45, 6.5, 'Last Transaction', 1, 0, 'L', true);
                    $this->Cell(35, 6.5, 'Due Amount', 1, 0, 'R', true);
                    $this->Cell(30, 6.5, 'Compilation Date', 1, 1, 'C', true);
                    $this->SetFont('Helvetica', '', 8.5);
                    $this->SetTextColor(30, 30, 30);
                }

                $fill = ($i % 2 == 0);
                $this->SetFillColor($fill ? 248 : 255, $fill ? 248 : 255, $fill ? 248 : 255);
                
                $acct = $this->toPdfText((string)($rec['account_number'] ?? '-'));
                $lastTx = $this->toPdfText(substr((string)($rec['last_transaction'] ?? '-'), 0, 22));
                $dueAmt = $this->toPdfText((string)($rec['due_amount'] ?? '-'));
                $compDate = $this->toPdfText((string)($rec['compilation_date'] ?? '-'));

                $this->Cell(10, 6, $i++, 1, 0, 'C', $fill);
                $this->Cell(50, 6, $acct, 1, 0, 'L', $fill);
                $this->Cell(45, 6, $lastTx, 1, 0, 'L', $fill);
                $this->Cell(35, 6, $dueAmt, 1, 0, 'R', $fill);
                $this->Cell(30, 6, $compDate, 1, 1, 'C', $fill);
            }
            $this->Ln(5);

            // Statement
            if ($this->GetY() + 20 > $this->PageBreakTrigger) {
                $this->AddPage();
            }
            $this->SetFont('Helvetica', '', 9);
            $statement = "This letter confirms that the above unclaimed financial asset(s) are recorded under our organization's submission files to the Unclaimed Financial Assets Authority.";
            $this->MultiCell(170, 4.5, $this->toPdfText($statement), 0, 'J');
            $this->Ln(6);

            // Signatures & Stamping Box Overflow Protection
            if ($this->GetY() + 35 > $this->PageBreakTrigger) {
                $this->AddPage();
            }

            $currentY = $this->GetY();
            
            // Left: Signature Line
            $this->SetFont('Helvetica', 'B', 8.5);
            $this->Cell(85, 4.5, $this->toPdfText('AUTHORIZED SIGNATORY:'), 0, 1, 'L');
            $this->Ln(1);
            $this->SetFont('Helvetica', '', 8.5);
            $this->Cell(85, 4, $this->toPdfText('Signature: ___________________________'), 0, 1, 'L');
            $this->Cell(85, 4, $this->toPdfText('Name: Peter Mwangi'), 0, 1, 'L');
            $this->Cell(85, 4, $this->toPdfText('Title: Risk and Compliance Lead'), 0, 1, 'L');
            $this->Cell(85, 4, $this->toPdfText('Organization: Airtel Money Kenya Limited'), 0, 1, 'L');
            $this->Cell(85, 4, $this->toPdfText('Date: ' . $this->dateStr), 0, 1, 'L');

            // Right: Stamp Area Box
            $this->SetDrawColor(180, 180, 180);
            $this->SetFillColor(252, 252, 252);
            $this->Rect(110, $currentY, 80, 28, 'DF');
            $this->SetXY(110, $currentY + 11);
            $this->SetFont('Helvetica', 'B', 8);
            $this->SetTextColor(150, 150, 150);
            $this->Cell(80, 4, $this->toPdfText('[ STAMP AREA ]'), 0, 1, 'C');
        }
    }
}

/**
 * Reusable Helper: Creates, updates DB, and streams/saves holder letter PDF.
 */
function create_and_save_holder_letter_pdf($pdo, $recordId, $outputMode = 'I', $saveToDisk = false)
{
    if (!$recordId || !is_numeric($recordId)) {
        return ['status' => 'error', 'message' => 'Invalid or missing Asset Record ID.'];
    }

    $stmt = $pdo->prepare("SELECT * FROM `unclaimed_assets` WHERE `record_id` = ?");
    $stmt->execute([(int)$recordId]);
    $asset = $stmt->fetch();

    if (!$asset) {
        return ['status' => 'error', 'message' => 'Asset record not found for ID: ' . (int)$recordId];
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

    $customerData = [
        'owner_name' => $asset['owner_name'],
        'id_passport_no' => $asset['id_passport_no']
    ];

    $refNo = 'UFAA/HL/' . date('Y') . '/' . str_pad($asset['record_id'], 5, '0', STR_PAD_LEFT);
    $dateStr = date('d F Y');

    // Generate Clean PDF
    $pdf = new UFAA_Clean_Letter_PDF($refNo, $dateStr);
    $pdf->buildLetter($customerData, $records);

    // Update DB Record
    try {
        $recordIds = array_column($records, 'record_id');
        if (!empty($recordIds)) {
            $inClause = implode(',', array_map('intval', $recordIds));
            $updateStmt = $pdo->prepare("
                UPDATE `unclaimed_assets`
                SET `letter_generated` = 'Yes',
                    `letter_generated_date` = COALESCE(`letter_generated_date`, NOW()),
                    `status` = CASE WHEN `status` = 'Unclaimed' THEN 'Letter Generated' ELSE `status` END
                WHERE `record_id` IN ($inClause)
            ");
            $updateStmt->execute();
        }
    } catch (Exception $e) {
        // Log or bypass DB update error so PDF generation succeeds
    }

    $cleanName = preg_replace('/[^a-zA-Z0-9_]/', '_', (string)($asset['owner_name'] ?: 'Asset'));
    $cleanName = trim(preg_replace('/_+/', '_', $cleanName), '_');
    $fileName = 'Holder_Letter_' . $asset['record_id'] . '_' . ($cleanName ?: 'Record') . '.pdf';

    $savedFilePath = null;
    if ($saveToDisk) {
        $uploadDir = __DIR__ . '/../uploads/generated_letters/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }
        $savedFilePath = $uploadDir . $fileName;
        $pdf->Output('F', $savedFilePath);
    }

    if (in_array($outputMode, ['I', 'D'])) {
        @ini_set('zlib.output_compression', 'Off');
        if (function_exists('apache_setenv')) {
            @apache_setenv('no-gzip', 1);
        }
        while (ob_get_level() > 0) {
            @ob_end_clean();
        }
        $pdf->Output($outputMode, $fileName);
        exit;
    }

    return [
        'status' => 'success',
        'file_name' => $fileName,
        'saved_path' => $savedFilePath,
        'records_count' => count($records)
    ];
}
