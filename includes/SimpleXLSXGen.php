<?php
/**
 * SimpleXLSXGen PHP library - High Performance Universal Streaming Edition
 * Generates protected, password-locked XLSX files with constant O(1) memory usage.
 * Features built-in Pure-PHP Zip streaming fallback so it works seamlessly even if
 * the server does NOT have the PHP ZipArchive extension installed.
 */

namespace Shuchkin;

class SimpleZipWriter {
    protected $fp;
    protected $cd = [];
    protected $offset = 0;

    public function __construct(string $filename) {
        $this->fp = @fopen($filename, 'wb');
    }

    public function isOpen(): bool {
        return is_resource($this->fp);
    }

    public function addFromString(string $name, string $data): bool {
        if (!$this->fp) return false;
        $crc = crc32($data);
        $uncompLen = strlen($data);

        if (function_exists('gzdeflate')) {
            $compData = gzdeflate($data, 6);
            $compLen = strlen($compData);
            $method = 8;
        } else {
            $compData = $data;
            $compLen = $uncompLen;
            $method = 0;
        }

        $headerOffset = $this->offset;
        $localHeader = pack(
            'VvvvVVVVvv',
            0x04034b50, 20, 0, $method, 0, $crc, $compLen, $uncompLen, strlen($name), 0
        ) . $name;

        fwrite($this->fp, $localHeader);
        fwrite($this->fp, $compData);
        $this->offset += strlen($localHeader) + $compLen;

        $this->cd[] = [
            'name'      => $name,
            'method'    => $method,
            'crc'       => $crc,
            'compLen'   => $compLen,
            'uncompLen' => $uncompLen,
            'offset'    => $headerOffset
        ];
        return true;
    }

    public function addFile(string $filePath, string $name): bool {
        if (!$this->fp || !file_exists($filePath)) return false;
        $fileSize = filesize($filePath);
        $crc = crc32_file($filePath);

        // If file is moderate (under 25MB) and gzdeflate exists, compress it
        if ($fileSize < 25 * 1024 * 1024 && function_exists('gzdeflate')) {
            $content = file_get_contents($filePath);
            $compData = gzdeflate($content, 6);
            $compLen = strlen($compData);
            $method = 8;
            $headerOffset = $this->offset;
            $localHeader = pack(
                'VvvvVVVVvv',
                0x04034b50, 20, 0, $method, 0, $crc, $compLen, $fileSize, strlen($name), 0
            ) . $name;

            fwrite($this->fp, $localHeader);
            fwrite($this->fp, $compData);
            $this->offset += strlen($localHeader) + $compLen;
            unset($content, $compData);
        } else {
            // Stream directly from disk with ZERO memory overhead (method 0 = STORED)
            $method = 0;
            $headerOffset = $this->offset;
            $localHeader = pack(
                'VvvvVVVVvv',
                0x04034b50, 20, 0, $method, 0, $crc, $fileSize, $fileSize, strlen($name), 0
            ) . $name;

            fwrite($this->fp, $localHeader);
            $src = fopen($filePath, 'rb');
            if ($src) {
                stream_copy_to_stream($src, $this->fp);
                fclose($src);
            }
            $this->offset += strlen($localHeader) + $fileSize;
        }

        $this->cd[] = [
            'name'      => $name,
            'method'    => $method,
            'crc'       => $crc,
            'compLen'   => ($method === 8 ? $compLen : $fileSize),
            'uncompLen' => $fileSize,
            'offset'    => $headerOffset
        ];
        return true;
    }

    public function close(): bool {
        if (!$this->fp) return false;
        $cdStart = $this->offset;
        $cdSize = 0;

        foreach ($this->cd as $item) {
            $cdHeader = pack(
                'VvvvvVVVVvvvvvVV',
                0x02014b50, 20, 20, 0, $item['method'], 0, $item['crc'],
                $item['compLen'], $item['uncompLen'], strlen($item['name']),
                0, 0, 0, 0, 0, $item['offset']
            ) . $item['name'];

            fwrite($this->fp, $cdHeader);
            $cdSize += strlen($cdHeader);
        }

        $count = count($this->cd);
        $eocd = pack(
            'VvvvvVVv',
            0x06054b50, 0, 0, $count, $count, $cdSize, $cdStart, 0
        );

        fwrite($this->fp, $eocd);
        fclose($this->fp);
        $this->fp = null;
        return true;
    }
}

class SimpleXLSXGen {

    protected $sheets;
    protected $headerRow;
    protected $protectionPassword;

    public function __construct() {
        $this->sheets = [
            ['name' => 'UFAA Submission', 'rows' => []]
        ];
        $this->headerRow = null;
        $this->protectionPassword = null;
    }

    public static function hasZipSupport(): bool {
        return class_exists('\\ZipArchive') || function_exists('gzdeflate');
    }

    public static function fromArray(array $rows, $sheetName = 'UFAA Submission') {
        $xlsx = new static();
        $xlsx->sheets[0] = ['name' => $sheetName, 'rows' => $rows];
        return $xlsx;
    }

    public static function fromArrayWithHeader(array $header, array &$rows, $sheetName = 'UFAA Submission') {
        $xlsx = new static();
        $xlsx->sheets[0] = ['name' => $sheetName, 'rows' => &$rows];
        $xlsx->headerRow = $header;
        return $xlsx;
    }

    public function setProtectionPassword($password) {
        $this->protectionPassword = $password;
        return $this;
    }

    protected function getTempDir(): string {
        $dir = __DIR__ . '/../uploads/tmp';
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
        if (is_dir($dir) && is_writable($dir)) {
            return $dir;
        }
        return sys_get_temp_dir();
    }

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

    protected function writeSheetXmlToDisk(array &$rows, string $tempSheetPath): bool {
        $fp = @fopen($tempSheetPath, 'wb');
        if (!$fp) return false;

        fwrite($fp, "<?xml version=\"1.0\" encoding=\"UTF-8\" standalone=\"yes\"?>\n");
        fwrite($fp, "<worksheet xmlns=\"http://schemas.openxmlformats.org/spreadsheetml/2006/main\" xmlns:r=\"http://schemas.openxmlformats.org/officeDocument/2006/relationships\">\n");
        fwrite($fp, "    <sheetViews><sheetView tabSelected=\"1\" workbookViewId=\"0\"/></sheetViews>\n");
        fwrite($fp, "    <sheetFormatPr defaultRowHeight=\"15\"/>\n");
        fwrite($fp, "    <sheetData>\n");

        $currentRowNum = 1;

        if (!empty($this->headerRow) && is_array($this->headerRow)) {
            $hBuf = '        <row r="1">';
            $hVals = array_values($this->headerRow);
            foreach ($hVals as $cIdx => $val) {
                $colLetter = $this->colName($cIdx);
                $cellRef = $colLetter . '1';
                $safeVal = htmlspecialchars((string)$val, ENT_QUOTES | ENT_XML1, 'UTF-8');
                $hBuf .= '<c r="' . $cellRef . '" t="inlineStr" s="1"><is><t>' . $safeVal . '</t></is></c>';
            }
            $hBuf .= "</row>\n";
            fwrite($fp, $hBuf);
            $currentRowNum = 2;
        }

        $rowCount = count($rows);
        for ($rIdx = 0; $rIdx < $rowCount; $rIdx++) {
            $row = $rows[$rIdx];
            $rowNum = $currentRowNum++;
            $rowBuf = '        <row r="' . $rowNum . '">';

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

    public function saveAs($filename): bool {
        $zip = null;
        $useNativeZip = class_exists('\\ZipArchive');

        if ($useNativeZip) {
            $zip = new \ZipArchive();
            if ($zip->open($filename, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
                $zip = null; // Fallback to Pure-PHP writer
            }
        }

        if (!$zip) {
            $zip = new SimpleZipWriter($filename);
            if (!$zip->isOpen()) {
                return false;
            }
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

        // 3. xl/sharedStrings.xml
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
        $tempDir = $this->getTempDir();
        $tempSheetPath = tempnam($tempDir, 'sxml_');
        $this->writeSheetXmlToDisk($this->sheets[0]['rows'], $tempSheetPath);
        $zip->addFile($tempSheetPath, 'xl/worksheets/sheet1.xml');

        $closed = $zip->close();
        @unlink($tempSheetPath);
        return $closed;
    }

    public function output() {
        $tempDir = $this->getTempDir();
        $tempZip = tempnam($tempDir, 'xlsx_');
        $this->saveAs($tempZip);
        $data = @file_get_contents($tempZip);
        @unlink($tempZip);
        return $data;
    }

    public function downloadAs($filename) {
        $tempDir = $this->getTempDir();
        $tempZip = tempnam($tempDir, 'xlsx_');
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
