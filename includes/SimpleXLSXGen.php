<?php
/**
 * SimpleXLSXGen PHP library - High Performance Streaming Edition
 * Generates protected, password-locked XLSX files with constant O(1) memory usage.
 * Uses disk-buffered streaming & inline strings to support up to 500,000+ rows without PHP memory exhaustion.
 */

namespace Shuchkin;

class SimpleXLSXGen {

    protected $sheets;
    protected $protectionPassword;

    public function __construct() {
        $this->sheets = [
            ['name' => 'UFAA Submission', 'rows' => []]
        ];
        $this->protectionPassword = null;
    }

    public static function fromArray(array $rows, $sheetName = 'UFAA Submission') {
        $xlsx = new static();
        $xlsx->sheets[0] = ['name' => $sheetName, 'rows' => $rows];
        return $xlsx;
    }

    public function setProtectionPassword($password) {
        $this->protectionPassword = $password;
        return $this;
    }

    /**
     * Excel password hash algorithm (ISO 29500 / OpenXML standard)
     */
    protected function calcPasswordHash($password) {
        $password = (string) $password;
        $hash = 0;
        $len = strlen($password);
        if ($len > 0) {
            while ($len--) {
                $hash = (($hash >> 14) & 0x01) | (($hash << 1) & 0x7FFF);
                $hash ^= ord($password[$len]);
            }
            $hash = (($hash >> 14) & 0x01) | (($hash << 1) & 0x7FFF);
            $hash ^= $len;
            $hash ^= 0xBAAD;
        }
        return strtoupper(dechex($hash & 0xFFFF));
    }

    /**
     * Streams worksheet XML directly to a disk file to prevent PHP memory exhaustion.
     */
    protected function writeSheetXmlToDisk(array &$rows, string $tempSheetPath): bool {
        $fp = @fopen($tempSheetPath, 'wb');
        if (!$fp) return false;

        fwrite($fp, "<?xml version=\"1.0\" encoding=\"UTF-8\" standalone=\"yes\"?>\n");
        fwrite($fp, "<worksheet xmlns=\"http://schemas.openxmlformats.org/spreadsheetml/2006/main\" xmlns:r=\"http://schemas.openxmlformats.org/officeDocument/2006/relationships\">\n");
        fwrite($fp, "    <sheetViews><sheetView tabSelected=\"1\" workbookViewId=\"0\"/></sheetViews>\n");
        fwrite($fp, "    <sheetFormatPr defaultRowHeight=\"15\"/>\n");
        fwrite($fp, "    <sheetData>\n");

        $rowCount = count($rows);
        for ($rIdx = 0; $rIdx < $rowCount; $rIdx++) {
            $row = $rows[$rIdx];
            $rowNum = $rIdx + 1;
            $rowBuf = '        <row r="' . $rowNum . '">';

            // Support both numeric indexed rows and associative row objects
            $colValues = [];
            if (isset($row['owner_name']) || isset($row['account_number'])) {
                $colValues = [
                    $row['owner_name'] ?? '',
                    $row['id_passport_no'] ?? '',
                    $row['date_of_birth'] ?? '',
                    $row['account_number'] ?? '',
                    $row['last_transaction'] ?? '',
                    $row['due_amount'] ?? '',
                    $row['compilation_date'] ?? ''
                ];
            } elseif (is_array($row)) {
                $colValues = array_values($row);
            }

            foreach ($colValues as $cIdx => $val) {
                if ($val === null || $val === '') continue;
                $colLetter = $this->colName($cIdx);
                $cellRef = $colLetter . $rowNum;
                $strVal = (string)$val;
                $styleAttr = ($rowNum === 1) ? ' s="1"' : '';

                // If numeric, write directly as numeric cell; otherwise write as inline string
                if ($rowNum > 1 && is_numeric($strVal) && !preg_match('/^0\d+/', $strVal) && strlen($strVal) < 18) {
                    $rowBuf .= '<c r="' . $cellRef . '"' . $styleAttr . '><v>' . $strVal . '</v></c>';
                } else {
                    $safeVal = htmlspecialchars($strVal, ENT_QUOTES | ENT_XML1, 'UTF-8');
                    $rowBuf .= '<c r="' . $cellRef . '" t="inlineStr"' . $styleAttr . '><is><t>' . $safeVal . '</t></is></c>';
                }
            }

            $rowBuf .= "</row>\n";
            fwrite($fp, $rowBuf);
        }

        fwrite($fp, "    </sheetData>\n");

        if (!empty($this->protectionPassword)) {
            $pwHash = $this->calcPasswordHash($this->protectionPassword);
            fwrite($fp, "    <sheetProtection password=\"{$pwHash}\" sheet=\"1\" objects=\"1\" scenarios=\"1\" selectLockedCells=\"1\" selectUnlockedCells=\"1\" formatCells=\"1\" formatColumns=\"0\" formatRows=\"0\" insertColumns=\"1\" insertRows=\"1\" insertHyperlinks=\"1\" deleteColumns=\"1\" deleteRows=\"1\" sort=\"1\" autoFilter=\"1\" pivotTables=\"1\"/>\n");
        }

        fwrite($fp, "</worksheet>");
        fclose($fp);
        return true;
    }

    /**
     * Builds and saves the XLSX zip archive directly to the destination path.
     * Uses constant memory regardless of whether there are 1,000 or 500,000 rows.
     */
    public function saveAs($filename): bool {
        $zip = new \ZipArchive();
        if ($zip->open($filename, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            return false;
        }

        // 1. [Content_Types].xml
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
    <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
    <Default Extension="xml" ContentType="application/xml"/>
    <Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>
    <Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>
    <Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>
    <Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/>
</Types>');

        // 2. _rels/.rels
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
    <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>
</Relationships>');

        // 3. xl/sharedStrings.xml (minimal empty catalog since inlineStr is used for O(1) memory)
        $zip->addFromString('xl/sharedStrings.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="0" uniqueCount="0"/>');

        // 4. xl/styles.xml
        $zip->addFromString('xl/styles.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
    <fonts count="2">
        <font><sz val="11"/><color theme="1"/><name val="Calibri"/></font>
        <font><b/><sz val="11"/><color theme="1"/><name val="Calibri"/></font>
    </fonts>
    <fills count="2">
        <fill><patternFill patternType="none"/></fill>
        <fill><patternFill patternType="gray125"/></fill>
    </fills>
    <borders count="1">
        <border><left/><right/><top/><bottom/><diagonal/></border>
    </borders>
    <cellStyleXfs count="1">
        <xf numFmtId="0" fontId="0" fillId="0" borderId="0"/>
    </cellStyleXfs>
    <cellXfs count="2">
        <xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>
        <xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/>
    </cellXfs>
</styleSheet>');

        // 5. xl/workbook.xml
        $wbProtTag = !empty($this->protectionPassword) ? '<workbookProtection lockStructure="1" lockWindows="0"/>' : '';
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
    ' . $wbProtTag . '
    <sheets>
        <sheet name="UFAA Submission" sheetId="1" r:id="rId1"/>
    </sheets>
</workbook>');

        // 6. xl/_rels/workbook.xml.rels
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
    <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>
    <Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>
    <Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/>
</Relationships>');

        // 7. xl/worksheets/sheet1.xml (Streamed from disk file)
        $tempSheetPath = tempnam(sys_get_temp_dir(), 'sxml_');
        $this->writeSheetXmlToDisk($this->sheets[0]['rows'], $tempSheetPath);
        $zip->addFile($tempSheetPath, 'xl/worksheets/sheet1.xml');

        $closed = $zip->close();
        @unlink($tempSheetPath);
        return $closed;
    }

    public function output() {
        $tempZip = tempnam(sys_get_temp_dir(), 'xlsx_');
        $this->saveAs($tempZip);
        $data = @file_get_contents($tempZip);
        @unlink($tempZip);
        return $data;
    }

    public function downloadAs($filename) {
        $tempZip = tempnam(sys_get_temp_dir(), 'xlsx_');
        $this->saveAs($tempZip);
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . filesize($tempZip));
        header('Cache-Control: max-age=0');
        readfile($tempZip);
        @unlink($tempZip);
        exit;
    }

    protected function colName($index) {
        $letter = '';
        while ($index >= 0) {
            $letter = chr($index % 26 + 65) . $letter;
            $index = intval($index / 26) - 1;
        }
        return $letter;
    }
}

