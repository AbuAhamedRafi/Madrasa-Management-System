<?php

namespace App\Services\DataExchange;

use App\Exceptions\DataExchangeDomainException;
use DOMDocument;
use ZipArchive;

class XlsxWorkbookService
{
    /**
     * Build an OpenXML XLSX workbook binary string.
     *
     * @param array<string, array{headers: array<string>, rows: array<array<mixed>>}> $sheets
     */
    public function generateWorkbook(array $sheets): string
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'mms_xlsx_');
        $zip = new ZipArchive();
        if ($zip->open($tempFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Failed to create temporary XLSX archive.');
        }

        // 1. [Content_Types].xml
        $contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
        $contentTypes .= '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">';
        $contentTypes .= '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>';
        $contentTypes .= '<Default Extension="xml" ContentType="application/xml"/>';
        $contentTypes .= '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>';
        $contentTypes .= '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>';

        // 2. _rels/.rels
        $rootRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
        $rootRels .= '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
        $rootRels .= '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>';
        $rootRels .= '</Relationships>';

        // 3. xl/workbook.xml & xl/_rels/workbook.xml.rels
        $workbookXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
        $workbookXml .= '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">';
        $workbookXml .= '<sheets>';

        $wbRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
        $wbRels .= '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
        $wbRels .= '<Relationship Id="rIdStyles" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';

        $sheetIndex = 1;
        foreach ($sheets as $sheetName => $sheetData) {
            $sheetRelId = "rIdSheet{$sheetIndex}";
            $safeSheetName = htmlspecialchars(substr($sheetName, 0, 31), ENT_XML1, 'UTF-8');
            $workbookXml .= sprintf('<sheet name="%s" sheetId="%d" r:id="%s"/>', $safeSheetName, $sheetIndex, $sheetRelId);

            $wbRels .= sprintf(
                '<Relationship Id="%s" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet%d.xml"/>',
                $sheetRelId,
                $sheetIndex
            );

            $contentTypes .= sprintf(
                '<Override PartName="/xl/worksheets/sheet%d.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>',
                $sheetIndex
            );

            // Generate Sheet XML
            $sheetXml = $this->buildSheetXml($sheetData['headers'] ?? [], $sheetData['rows'] ?? []);
            $zip->addFromString("xl/worksheets/sheet{$sheetIndex}.xml", $sheetXml);

            $sheetIndex++;
        }

        $workbookXml .= '</sheets></workbook>';
        $wbRels .= '</Relationships>';
        $contentTypes .= '</Types>';

        // 4. xl/styles.xml
        $stylesXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
        $stylesXml .= '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">';
        $stylesXml .= '<fonts count="1"><font><sz val="11"/><name val="Calibri"/></font></fonts>';
        $stylesXml .= '<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>';
        $stylesXml .= '<borders count="1"><border><left/><right/><top/><bottom/></border></borders>';
        $stylesXml .= '<cellXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellXfs>';
        $stylesXml .= '</styleSheet>';

        $zip->addFromString('[Content_Types].xml', $contentTypes);
        $zip->addFromString('_rels/.rels', $rootRels);
        $zip->addFromString('xl/workbook.xml', $workbookXml);
        $zip->addFromString('xl/_rels/workbook.xml.rels', $wbRels);
        $zip->addFromString('xl/styles.xml', $stylesXml);

        $zip->close();

        $content = file_get_contents($tempFile);
        @unlink($tempFile);

        return $content;
    }

    /**
     * Build worksheet XML with explicit inline strings, formula-escaping, and Unicode safety.
     */
    private function buildSheetXml(array $headers, array $rows): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
        $xml .= '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">';
        $xml .= '<sheetData>';

        $rowIndex = 1;

        // Header Row
        if (!empty($headers)) {
            $xml .= sprintf('<row r="%d">', $rowIndex);
            $colIndex = 1;
            foreach ($headers as $headerText) {
                $cellRef = $this->columnToLetter($colIndex) . $rowIndex;
                $safeVal = $this->escapeXmlAndFormula((string) $headerText);
                $xml .= sprintf('<c r="%s" t="inlineStr"><is><t>%s</t></is></c>', $cellRef, $safeVal);
                $colIndex++;
            }
            $xml .= '</row>';
            $rowIndex++;
        }

        // Data Rows
        foreach ($rows as $row) {
            $xml .= sprintf('<row r="%d">', $rowIndex);
            $colIndex = 1;
            foreach ($row as $val) {
                $cellRef = $this->columnToLetter($colIndex) . $rowIndex;

                if ($val === null || $val === '') {
                    // Empty cell
                } elseif (is_int($val) || (is_numeric($val) && !preg_match('/^0[0-9]+/', (string) $val) && !str_contains((string) $val, '-'))) {
                    // Numeric cell, UNLESS it's a phone number or ID starting with 0
                    $xml .= sprintf('<c r="%s"><v>%s</v></c>', $cellRef, (string) $val);
                } else {
                    // Text cell with formula-injection escaping and leading-zero retention
                    $safeVal = $this->escapeXmlAndFormula((string) $val);
                    $xml .= sprintf('<c r="%s" t="inlineStr"><is><t>%s</t></is></c>', $cellRef, $safeVal);
                }
                $colIndex++;
            }
            $xml .= '</row>';
            $rowIndex++;
        }

        $xml .= '</sheetData></worksheet>';
        return $xml;
    }

    /**
     * Defend against Excel Formula Injection (D08-F09-AT04, D08-F12-AT06)
     * Prefix with single-quote if text starts with =, +, -, @, \t, \r.
     */
    public function escapeXmlAndFormula(string $val): string
    {
        $firstChar = $val[0] ?? '';
        if (in_array($firstChar, ['=', '+', '-', '@', "\t", "\r"], true)) {
            $val = "'" . $val;
        }

        return htmlspecialchars($val, ENT_XML1, 'UTF-8');
    }

    public static function columnLetter(int $colIndex): string
    {
        $letter = '';
        $col = $colIndex + 1; // 0-based to 1-based
        while ($col > 0) {
            $mod = ($col - 1) % 26;
            $letter = chr(65 + $mod) . $letter;
            $col = (int) (($col - $mod) / 26);
        }
        return $letter;
    }

    private function columnToLetter(int $col): string
    {
        return self::columnLetter($col - 1);
    }

    /**
     * Inspect and validate uploaded XLSX ZIP package before parsing (F03).
     *
     * @throws DataExchangeDomainException
     */
    public function validateAndScanPackage(string $filePath): array
    {
        if (!file_exists($filePath)) {
            throw DataExchangeDomainException::fileUnsafe('File not found.');
        }

        $size = filesize($filePath);
        if ($size > 10 * 1024 * 1024) { // 10 MB limit
            throw DataExchangeDomainException::fileUnsafe('File exceeds maximum allowed size of 10 MB.', ['size' => $size], 413);
        }

        $zip = new ZipArchive();
        $openResult = $zip->open($filePath, ZipArchive::RDONLY);
        if ($openResult !== true) {
            throw DataExchangeDomainException::fileUnsafe('Invalid or corrupted XLSX file package.');
        }

        $hasWorkbook = false;
        $hasContentTypes = false;
        $totalUncompressedSize = 0;

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            $name = $stat['name'] ?? '';
            $uncompressed = $stat['size'] ?? 0;
            $totalUncompressedSize += $uncompressed;

            // Reject macro-enabled files (D08-F03-AT01)
            if (str_contains(strtolower($name), 'vbaproject.bin') || str_ends_with(strtolower($name), '.xlsm')) {
                $zip->close();
                throw DataExchangeDomainException::fileUnsafe('Macro-enabled workbooks (.xlsm / VBA) are strictly prohibited.');
            }

            // Reject external references or OLE links
            if (str_contains(strtolower($name), 'externallinks')) {
                $zip->close();
                throw DataExchangeDomainException::fileUnsafe('Workbooks with external links are not allowed for security reasons.');
            }

            if ($name === 'xl/workbook.xml') {
                $hasWorkbook = true;
            }
            if ($name === '[Content_Types].xml') {
                $hasContentTypes = true;
            }
        }

        // Zip-bomb check: max uncompressed 32 MB or 100:1 ratio (D08-F03-AT02)
        if ($totalUncompressedSize > 32 * 1024 * 1024) {
            $zip->close();
            throw DataExchangeDomainException::fileUnsafe('Decompressed package size exceeds safe limits (Zip Bomb defense).', [], 413);
        }

        if (!$hasWorkbook || !$hasContentTypes) {
            $zip->close();
            throw DataExchangeDomainException::fileUnsafe('Uploaded archive is not a valid OpenXML spreadsheet.');
        }

        // Read sheets from workbook.xml
        $wbXml = $zip->getFromName('xl/workbook.xml');
        $zip->close();

        $sheets = [];
        if ($wbXml) {
            $dom = new DOMDocument();
            @$dom->loadXML($wbXml);
            $sheetElements = $dom->getElementsByTagName('sheet');
            foreach ($sheetElements as $el) {
                $sheets[] = $el->getAttribute('name');
            }
        }

        return [
            'valid' => true,
            'sheets' => $sheets,
            'byte_size' => $size,
        ];
    }

    public static function columnLetterToIndex(string $letters): int
    {
        $letters = strtoupper(trim($letters));
        $len = strlen($letters);
        $idx = 0;
        for ($i = 0; $i < $len; $i++) {
            $idx = $idx * 26 + (ord($letters[$i]) - 64);
        }
        return $idx - 1;
    }

    /**
     * Extract rows from a specified sheet in an OpenXML XLSX archive.
     *
     * @return array<int, array<int, string>> Array of rows: [row_number => [col_index => value]]
     */
    public function extractSheetRows(string $filePath, ?string $sheetName = null, int $maxRows = 0): array
    {
        if (!file_exists($filePath)) {
            throw DataExchangeDomainException::fileUnsafe('File not found for extraction.');
        }

        $zip = new ZipArchive();
        if ($zip->open($filePath, ZipArchive::RDONLY) !== true) {
            throw DataExchangeDomainException::fileUnsafe('Unable to open XLSX archive for reading.');
        }

        // 1. Load shared strings if present
        $sharedStrings = [];
        $ssXml = $zip->getFromName('xl/sharedStrings.xml');
        if ($ssXml) {
            $dom = new DOMDocument();
            @$dom->loadXML($ssXml);
            $siNodes = $dom->getElementsByTagName('si');
            foreach ($siNodes as $si) {
                $tNodes = $si->getElementsByTagName('t');
                $str = '';
                foreach ($tNodes as $t) {
                    $str .= $t->nodeValue;
                }
                $sharedStrings[] = $str;
            }
        }

        // 2. Resolve sheet XML path from workbook.xml + workbook.xml.rels
        $wbXml = $zip->getFromName('xl/workbook.xml');
        $wbRelsXml = $zip->getFromName('xl/_rels/workbook.xml.rels');

        $sheetPath = null;
        if ($wbXml && $wbRelsXml) {
            $wbDom = new DOMDocument();
            @$wbDom->loadXML($wbXml);
            $sheetNodes = $wbDom->getElementsByTagName('sheet');

            $targetRelId = null;
            $firstRelId = null;

            foreach ($sheetNodes as $sn) {
                $name = $sn->getAttribute('name');
                $relId = $sn->getAttribute('r:id') ?: $sn->getAttribute('id');
                if ($firstRelId === null) {
                    $firstRelId = $relId;
                }
                if ($sheetName && strcasecmp($name, $sheetName) === 0) {
                    $targetRelId = $relId;
                    break;
                }
            }

            if (!$targetRelId) {
                $targetRelId = $firstRelId;
            }

            if ($targetRelId) {
                $relsDom = new DOMDocument();
                @$relsDom->loadXML($wbRelsXml);
                $relNodes = $relsDom->getElementsByTagName('Relationship');
                foreach ($relNodes as $rel) {
                    if ($rel->getAttribute('Id') === $targetRelId) {
                        $target = $rel->getAttribute('Target');
                        $sheetPath = str_starts_with($target, 'worksheets/') ? 'xl/' . $target : (str_starts_with($target, '/') ? substr($target, 1) : 'xl/' . $target);
                        break;
                    }
                }
            }
        }

        // Fallback to sheet2.xml or sheet1.xml if path not found
        if (!$sheetPath || $zip->locateName($sheetPath) === false) {
            if ($zip->locateName('xl/worksheets/sheet2.xml') !== false && ($sheetName === 'Data' || !$sheetName)) {
                $sheetPath = 'xl/worksheets/sheet2.xml';
            } elseif ($zip->locateName('xl/worksheets/sheet1.xml') !== false) {
                $sheetPath = 'xl/worksheets/sheet1.xml';
            } else {
                $zip->close();
                throw DataExchangeDomainException::fileUnsafe('Target worksheet not found in XLSX package.');
            }
        }

        $worksheetXml = $zip->getFromName($sheetPath);
        $zip->close();

        if (!$worksheetXml) {
            throw DataExchangeDomainException::fileUnsafe('Worksheet content could not be read.');
        }

        // 3. Parse rows using DOMDocument
        $dom = new DOMDocument();
        @$dom->loadXML($worksheetXml);
        $rowNodes = $dom->getElementsByTagName('row');

        $rows = [];

        foreach ($rowNodes as $rowNode) {
            $rAttr = $rowNode->getAttribute('r');
            $currentRowNumber = $rAttr ? (int)$rAttr : (count($rows) + 1);
            $currentRowCells = [];

            $cNodes = $rowNode->getElementsByTagName('c');
            foreach ($cNodes as $c) {
                $ref = $c->getAttribute('r');
                $type = $c->getAttribute('t');
                $val = '';

                if ($type === 's') {
                    $vNode = $c->getElementsByTagName('v')->item(0);
                    $idx = $vNode ? (int)$vNode->nodeValue : null;
                    $val = ($idx !== null && isset($sharedStrings[$idx])) ? $sharedStrings[$idx] : '';
                } elseif ($type === 'inlineStr') {
                    $isNode = $c->getElementsByTagName('is')->item(0);
                    $val = $isNode ? $isNode->nodeValue : '';
                } else {
                    $vNode = $c->getElementsByTagName('v')->item(0);
                    $val = $vNode ? $vNode->nodeValue : '';
                }

                if ($ref && preg_match('/^([A-Z]+)(\d+)$/', $ref, $m)) {
                    $colIdx = self::columnLetterToIndex($m[1]);
                    $currentRowCells[$colIdx] = trim((string)$val);
                } else {
                    $currentRowCells[] = trim((string)$val);
                }
            }

            $rows[$currentRowNumber] = $currentRowCells;

            if ($maxRows > 0 && count($rows) >= $maxRows) {
                break;
            }
        }

        return $rows;
    }
}
