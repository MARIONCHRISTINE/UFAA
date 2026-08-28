<?php
/**
 * SimpleXLSXGen PHP library v1.4
 * Fast, lightweight single-sheet / multi-sheet XLSX generator with cell styles and sheet protection.
 * @author Sergey Shuchkin <sergey.shuchkin@gmail.com>
 */

namespace Shuchkin;

class SimpleXLSXGen {

    protected $sheets;
    protected $template;
    protected $styles;
    protected $protectionPassword;

    public function __construct() {
        $this->sheets = [
            ['name' => 'Sheet1', 'rows' => []]
        ];
        $this->protectionPassword = null;
    }

    public static function fromArray(array $rows, $sheetName = 'Sheet1') {
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

    public function saveAs($filename) {
        $content = $this->output();
        return file_put_contents($filename, $content) !== false;
    }

    public function downloadAs($filename) {
        $content = $this->output();
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($content));
        header('Cache-Control: max-age=0');
        echo $content;
        exit;
    }

    public function output() {
        $zip = new \ZipArchive();
        $tempFile = tempnam(sys_get_temp_dir(), 'xlsx_');
        if ($zip->open($tempFile, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
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

        // Build shared strings & sheet XML
        $sharedStrings = [];
        $sharedStringsMap = [];
        $sheetXmlRows = [];

        $rows = $this->sheets[0]['rows'];
        foreach ($rows as $rIdx => $row) {
            $rowNum = $rIdx + 1;
            $cellXmls = [];
            foreach ($row as $cIdx => $val) {
                $colLetter = $this->colName($cIdx);
                $cellRef = $colLetter . $rowNum;
                if ($val === null || $val === '') {
                    continue;
                }
                $strVal = (string)$val;
                if (!isset($sharedStringsMap[$strVal])) {
                    $sharedStringsMap[$strVal] = count($sharedStrings);
                    $sharedStrings[] = $strVal;
                }
                $sIdx = $sharedStringsMap[$strVal];
                // Header row (row 1) bold style index 1, normal cells style 0
                $styleAttr = ($rowNum === 1) ? ' s="1"' : '';
                $cellXmls[] = '<c r="' . $cellRef . '" t="s"' . $styleAttr . '><v>' . $sIdx . '</v></c>';
            }
            $sheetXmlRows[] = '<row r="' . $rowNum . '">' . implode('', $cellXmls) . '</row>';
        }

        // Shared Strings XML
        $ssXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="' . count($sharedStrings) . '" uniqueCount="' . count($sharedStrings) . '">';
        foreach ($sharedStrings as $str) {
            $ssXml .= '<si><t>' . htmlspecialchars($str, ENT_QUOTES | ENT_XML1, 'UTF-8') . '</t></si>';
        }
        $ssXml .= '</sst>';
        $zip->addFromString('xl/sharedStrings.xml', $ssXml);

        // Sheet Protection Tag
        $protTag = '';
        if (!empty($this->protectionPassword)) {
            $pwHash = $this->calcPasswordHash($this->protectionPassword);
            $protTag = '<sheetProtection password="' . $pwHash . '" sheet="1" objects="1" scenarios="1" selectLockedCells="1" selectUnlockedCells="1" formatCells="1" formatColumns="0" formatRows="0" insertColumns="1" insertRows="1" insertHyperlinks="1" deleteColumns="1" deleteRows="1" sort="1" autoFilter="1" pivotTables="1"/>';
        }

        // Worksheet sheet1.xml
        $sheetXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
    <dimension ref="A1:' . $this->colName(max(0, count($rows ? $rows[0] : []) - 1)) . max(1, count($rows)) . '"/>
    <sheetViews>
        <sheetView tabSelected="1" workbookViewId="0"/>
    </sheetViews>
    <sheetFormatPr defaultRowHeight="15"/>
    <sheetData>' . implode('', $sheetXmlRows) . '</sheetData>
    ' . $protTag . '
</worksheet>';
        $zip->addFromString('xl/worksheets/sheet1.xml', $sheetXml);

        // Styles XML
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

        // Workbook XML
        $wbProtTag = !empty($this->protectionPassword) ? '<workbookProtection lockStructure="1" lockWindows="0"/>' : '';
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
    ' . $wbProtTag . '
    <sheets>
        <sheet name="UFAA Submission" sheetId="1" r:id="rId1"/>
    </sheets>
</workbook>');

        // Workbook rels
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
    <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>
    <Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>
    <Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/>
</Relationships>');

        $zip->close();
        $data = file_get_contents($tempFile);
        @unlink($tempFile);
        return $data;
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
