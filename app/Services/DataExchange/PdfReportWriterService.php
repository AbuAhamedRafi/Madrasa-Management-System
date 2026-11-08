<?php

namespace App\Services\DataExchange;

class PdfReportWriterService
{
    /**
     * Generate a printable PDF report (conforming to %PDF-1.4).
     *
     * @param string $title
     * @param array<string, mixed> $metadata (e.g. cutoff_at, filters, exported_by)
     * @param array<string> $headers
     * @param array<array<mixed>> $rows
     * @return string Raw PDF bytes
     */
    public function generateReport(string $title, array $metadata, array $headers, array $rows): string
    {
        $pageWidth = 595;
        $pageHeight = 842;
        $margin = 40;
        $printableWidth = $pageWidth - ($margin * 2);

        // Build stream content
        $stream = "";
        
        // Header Banner
        $stream .= "BT\n";
        $stream .= "/F2 16 Tf\n";
        $stream .= sprintf("%d %d Td\n", $margin, $pageHeight - $margin - 16);
        $stream .= sprintf("(%s) Tj\n", $this->escapePdfString($title));
        $stream .= "ET\n";

        // Subtitle / Metadata
        $y = $pageHeight - $margin - 36;
        $stream .= "BT\n";
        $stream .= "/F1 9 Tf\n";
        $stream .= sprintf("%d %d Td\n", $margin, $y);
        
        $metaParts = [];
        if (!empty($metadata['exported_by'])) {
            $metaParts[] = "Exported by: " . $metadata['exported_by'];
        }
        if (!empty($metadata['cutoff_at'])) {
            $metaParts[] = "Cutoff: " . $metadata['cutoff_at'];
        }
        if (!empty($metadata['total_rows'])) {
            $metaParts[] = "Total Records: " . $metadata['total_rows'];
        }
        $metaText = implode("  |  ", $metaParts);
        $stream .= sprintf("(%s) Tj\n", $this->escapePdfString($metaText));
        $stream .= "ET\n";

        // Horizontal line under title
        $y -= 10;
        $stream .= sprintf("%d %d m %d %d l S\n", $margin, $y, $pageWidth - $margin, $y);

        // Table Columns
        $colCount = max(1, count($headers));
        $colWidth = floor($printableWidth / $colCount);
        
        $y -= 18;
        // Header Row Background / Text
        $stream .= "BT\n";
        $stream .= "/F2 9 Tf\n";
        $colX = $margin;
        foreach ($headers as $h) {
            $truncated = $this->truncateText((string)$h, 18);
            $stream .= sprintf("%d %d Td (%s) Tj %d 0 Td\n", $colX, $y, $this->escapePdfString($truncated), -$colX);
            $colX += $colWidth;
        }
        $stream .= "ET\n";

        // Header underline
        $y -= 6;
        $stream .= sprintf("%d %d m %d %d l S\n", $margin, $y, $pageWidth - $margin, $y);

        // Rows
        $stream .= "BT\n";
        $stream .= "/F1 8 Tf\n";
        $rowLimit = min(count($rows), 100); // cap per page in minimal generator
        for ($i = 0; $i < $rowLimit; $i++) {
            $y -= 14;
            if ($y < $margin + 20) {
                break; // bottom margin reached
            }
            $row = $rows[$i];
            $colX = $margin;
            $cIdx = 0;
            foreach ($headers as $h) {
                $val = $row[$cIdx] ?? ($row[$h] ?? '');
                $truncated = $this->truncateText((string)$val, 20);
                $stream .= sprintf("%d %d Td (%s) Tj %d 0 Td\n", $colX, $y, $this->escapePdfString($truncated), -$colX);
                $colX += $colWidth;
                $cIdx++;
            }
        }
        $stream .= "ET\n";

        // Footer
        $stream .= "BT\n";
        $stream .= "/F1 8 Tf\n";
        $stream .= sprintf("%d %d Td (MMS Secure Data Exchange Report - Confidential - Page 1 of 1) Tj\n", $margin, $margin);
        $stream .= "ET\n";

        // Construct Objects
        $objects = [];
        // Obj 1: Catalog
        $objects[1] = "<< /Type /Catalog /Pages 2 0 R >>";
        // Obj 2: Pages
        $objects[2] = "<< /Type /Pages /Kids [3 0 R] /Count 1 >>";
        // Obj 3: Page
        $objects[3] = sprintf(
            "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %d %d] /Contents 4 0 R /Resources << /Font << /F1 5 0 R /F2 6 0 R >> >> >>",
            $pageWidth,
            $pageHeight
        );
        // Obj 4: Stream
        $streamLen = strlen($stream);
        $objects[4] = sprintf("<< /Length %d >>\nstream\n%sendstream", $streamLen, $stream);
        // Obj 5: Font F1 (Helvetica)
        $objects[5] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>";
        // Obj 6: Font F2 (Helvetica-Bold)
        $objects[6] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>";

        // Compile PDF
        $pdf = "%PDF-1.4\n%\xe2\xe3\xcf\xd3\n";
        $offsets = [];

        foreach ($objects as $num => $body) {
            $offsets[$num] = strlen($pdf);
            $pdf .= sprintf("%d 0 obj\n%s\nendobj\n", $num, $body);
        }

        $xrefOffset = strlen($pdf);
        $pdf .= sprintf("xref\n0 %d\n", count($objects) + 1);
        $pdf .= "0000000000 65535 f \n";
        for ($i = 1; $i <= count($objects); $i++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
        }

        $pdf .= sprintf(
            "trailer\n<< /Size %d /Root 1 0 R >>\nstartxref\n%d\n%%%%EOF\n",
            count($objects) + 1,
            $xrefOffset
        );

        return $pdf;
    }

    private function escapePdfString(string $str): string
    {
        // Replace non-ascii with safe representation or keep ascii
        $safe = mb_encode_numericentity($str, [0x80, 0xffff, 0, 0xffff], 'UTF-8');
        $safe = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $safe);
        return $safe;
    }

    private function truncateText(string $text, int $maxChars): string
    {
        if (mb_strlen($text) > $maxChars) {
            return mb_substr($text, 0, $maxChars - 2) . '..';
        }
        return $text;
    }
}
