<?php

class DocumentParser {

    public static function parseUploadedFile(string $tmpPath, string $originalName): array {
        if (!file_exists($tmpPath) || !is_readable($tmpPath)) {
            return ['success' => false, 'message' => 'Uploaded file not found or is not readable.'];
        }
        $fsize = @filesize($tmpPath);
        if ($fsize === false || $fsize <= 0 || $fsize > 10 * 1024 * 1024) {
            return ['success' => false, 'message' => 'File size must be between 1 byte and 10 MB maximum.'];
        }

        $rawExt = strtoupper(pathinfo($originalName, PATHINFO_EXTENSION));
        if (!in_array($rawExt, ['TXT', 'DOCX', 'DOC', 'PDF', 'XLSX'], true)) {
            return ['success' => false, 'message' => 'Supported file formats are PDF, DOCX, DOC, XLSX, or TXT.'];
        }

        $header = (string)file_get_contents($tmpPath, false, null, 0, 16);
        if ($rawExt === 'PDF' && strpos($header, '%PDF-') !== 0) {
            Security::log_incident('UPLOAD_INVALID_PDF_MAGIC', 'Rejected forged PDF upload: ' . substr($originalName, 0, 60), 'Blocked');
            return ['success' => false, 'message' => 'PDF file does not have a valid %PDF- file signature.'];
        }
        if (($rawExt === 'DOCX' || $rawExt === 'XLSX') && substr($header, 0, 2) !== 'PK') {
            Security::log_incident('UPLOAD_INVALID_OOXML_MAGIC', 'Rejected forged OOXML upload: ' . substr($originalName, 0, 60), 'Blocked');
            return ['success' => false, 'message' => "{$rawExt} file does not contain a valid ZIP (OOXML) archive."];
        }

        $dbFileType = ($rawExt === 'DOC') ? 'DOCX' : $rawExt;
        $rawText = '';

        if ($rawExt === 'TXT') {
            $raw = (string)file_get_contents($tmpPath, false, null, 0, 1048576);
            if (strpos($raw, "\0") !== false && substr($raw, 0, 2) !== "\xFF\xFE" && substr($raw, 0, 2) !== "\xFE\xFF") {
                if (preg_match('/<\?(php|=)/i', $raw)) {
                    Security::log_incident('UPLOAD_EXECUTABLE_PAYLOAD', 'Blocked binary/script payload in TXT upload', 'Blocked');
                    return ['success' => false, 'message' => 'TXT file rejected because it contains binary bytes or executable code.'];
                }
            }
            if (preg_match('/<\?(php|=)/i', $raw)) {
                Security::log_incident('UPLOAD_EXECUTABLE_PAYLOAD', 'Blocked script tag in TXT upload', 'Blocked');
                return ['success' => false, 'message' => 'TXT file rejected by security scanner because it contains executable script code.'];
            }
            $rawText = self::decodeTxtEncoding($raw);
        } elseif ($rawExt === 'DOCX') {
            $rawText = self::extractDocxText($tmpPath);
            if (trim($rawText) === '') {
                return ['success' => false, 'message' => 'word/document.xml inside the DOCX archive is empty, corrupted, or password-protected.'];
            }
        } elseif ($rawExt === 'XLSX') {
            $rawText = self::extractXlsxText($tmpPath);
            if (trim($rawText) === '') {
                return ['success' => false, 'message' => 'Workbook inside the XLSX archive is empty, corrupted, or password-protected.'];
            }
        } elseif ($rawExt === 'DOC') {
            if (substr($header, 0, 2) === 'PK') {
                $rawText = self::extractDocxText($tmpPath);
            } else {
                $rawText = self::extractLegacyDocText($tmpPath);
            }
            if (trim($rawText) === '') {
                return ['success' => false, 'message' => 'Legacy DOC file contains no readable text or is encrypted. Please re-save as .docx or paste the text manually.'];
            }
        } elseif ($rawExt === 'PDF') {
            $rawText = self::extractPdfText((string)file_get_contents($tmpPath));
        }

        $clean = self::normalizeExtractedText($rawText);
        if (mb_strlen($clean, 'UTF-8') < 10) {
            return [
                'success' => false,
                'message' => $rawExt === 'PDF'
                    ? 'PDF text could not be extracted automatically (likely an image-scanned PDF). Please paste the text manually or upload a DOCX/XLSX/TXT file.'
                    : 'Document text is empty or too short (minimum 10 characters).'
            ];
        }

        if (!self::isReadableDocumentText($clean)) {
            return [
                'success' => false,
                'message' => "The {$rawExt} file content reads as binary/garbled bytes without a valid UTF-8 text layer."
            ];
        }

        return [
            'success' => true,
            'file_type' => $dbFileType,
            'raw_ext' => $rawExt,
            'text' => $clean
        ];
    }

    public static function parseSpreadsheetRows(string $tmpPath, string $originalName): array {
        if (!file_exists($tmpPath) || !is_readable($tmpPath)) {
            return ['success' => false, 'message' => 'Uploaded spreadsheet not found.'];
        }
        $fsize = @filesize($tmpPath);
        if ($fsize === false || $fsize <= 0 || $fsize > 10 * 1024 * 1024) {
            return ['success' => false, 'message' => 'Spreadsheet file size must be <= 10 MB.'];
        }
        $ext = strtoupper(pathinfo($originalName, PATHINFO_EXTENSION));
        if (!in_array($ext, ['XLSX', 'CSV'], true)) {
            return ['success' => false, 'message' => 'Only XLSX or CSV files are accepted for tabular import.'];
        }

        if ($ext === 'CSV') {
            $rows = [];
            if (($fh = fopen($tmpPath, 'r')) !== false) {
                while (($row = fgetcsv($fh, 4096, ',')) !== false) {
                    if (count($row) === 1 && strpos((string)$row[0], ';') !== false) {
                        $row = str_getcsv((string)$row[0], ';');
                    }
                    $cleanRow = array_map(static fn($v) => trim((string)$v), $row);
                    if (implode('', $cleanRow) !== '') {
                        $rows[] = $cleanRow;
                    }
                    if (count($rows) >= 2000) break;
                }
                fclose($fh);
            }
            if (empty($rows)) {
                return ['success' => false, 'message' => 'CSV file contains no rows.'];
            }
            return ['success' => true, 'rows' => $rows];
        }

        $header = (string)file_get_contents($tmpPath, false, null, 0, 4);
        if (substr($header, 0, 2) !== 'PK') {
            Security::log_incident('UPLOAD_INVALID_XLSX_MAGIC', 'Rejected forged XLSX upload: ' . substr($originalName, 0, 60), 'Blocked');
            return ['success' => false, 'message' => 'Invalid XLSX archive signature.'];
        }

        $sheets = self::extractXlsxRows($tmpPath);
        if (empty($sheets)) {
            return ['success' => false, 'message' => 'No readable worksheet rows found in XLSX file.'];
        }
        return ['success' => true, 'rows' => $sheets[0]['rows']];
    }

    public static function extractXlsxText(string $tmpPath): string {
        $sheets = self::extractXlsxRows($tmpPath);
        if (empty($sheets)) return '';
        $blocks = [];
        foreach ($sheets as $sheet) {
            $lines = [];
            foreach ($sheet['rows'] as $row) {
                $nonEmpty = array_values(array_filter($row, static fn($c) => trim((string)$c) !== ''));
                if (!empty($nonEmpty)) {
                    $lines[] = implode(' | ', $nonEmpty);
                }
            }
            if (!empty($lines)) {
                $blocks[] = '[' . $sheet['name'] . "]\n" . implode("\n", $lines);
            }
        }
        return implode("\n\n", $blocks);
    }

    private static function extractXlsxRows(string $tmpPath): array {
        if (!class_exists('ZipArchive')) {
            return [];
        }
        $zip = new ZipArchive();
        if ($zip->open($tmpPath) !== true) {
            return [];
        }

        $sharedStrings = [];
        $ssXml = $zip->getFromName('xl/sharedStrings.xml', 4194304);
        if (is_string($ssXml) && $ssXml !== '') {
            $sx = @simplexml_load_string($ssXml, 'SimpleXMLElement', LIBXML_NOCDATA | LIBXML_NONET);
            if ($sx) {
                foreach ($sx->si as $si) {
                    if (isset($si->t)) {
                        $sharedStrings[] = (string)$si->t;
                    } elseif (isset($si->r)) {
                        $t = '';
                        foreach ($si->r as $run) {
                            $t .= (string)($run->t ?? '');
                        }
                        $sharedStrings[] = $t;
                    } else {
                        $sharedStrings[] = '';
                    }
                }
            }
        }

        $sheetTargets = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string)$zip->getNameIndex($i);
            if (preg_match('#^xl/worksheets/sheet(\d+)\.xml$#i', $name, $m)) {
                $sheetTargets[(int)$m[1]] = $name;
            }
        }
        ksort($sheetTargets);

        $sheets = [];
        foreach ($sheetTargets as $idx => $target) {
            $xmlStr = $zip->getFromName($target, 4194304);
            if (!is_string($xmlStr) || $xmlStr === '') continue;
            $sx = @simplexml_load_string($xmlStr, 'SimpleXMLElement', LIBXML_NOCDATA | LIBXML_NONET);
            if (!$sx || !isset($sx->sheetData)) continue;

            $rows = [];
            foreach ($sx->sheetData->row as $rNode) {
                $rowMap = [];
                $maxCol = 0;
                foreach ($rNode->c as $cell) {
                    $ref = (string)($cell['r'] ?? '');
                    $colIdx = self::excelColRefToIndex($ref);
                    if ($colIdx > $maxCol) $maxCol = $colIdx;
                    $type = (string)($cell['t'] ?? '');
                    $val = '';
                    if ($type === 's') {
                        $sIdx = (int)($cell->v ?? 0);
                        $val = $sharedStrings[$sIdx] ?? '';
                    } elseif ($type === 'inlineStr') {
                        $val = (string)($cell->is->t ?? '');
                    } else {
                        $val = (string)($cell->v ?? '');
                    }
                    $rowMap[$colIdx] = trim($val);
                }
                if (!empty($rowMap)) {
                    $dense = [];
                    for ($c = 0; $c <= min($maxCol, 64); $c++) {
                        $dense[] = $rowMap[$c] ?? '';
                    }
                    if (implode('', $dense) !== '') {
                        $rows[] = $dense;
                    }
                }
                if (count($rows) >= 2000) break;
            }
            if (!empty($rows)) {
                $sheets[] = ['name' => 'Sheet ' . $idx, 'rows' => $rows];
            }
        }

        $zip->close();
        return $sheets;
    }

    private static function excelColRefToIndex(string $ref): int {
        if (!preg_match('/^([A-Z]+)/i', $ref, $m)) {
            return 0;
        }
        $letters = strtoupper($m[1]);
        $idx = 0;
        $len = strlen($letters);
        for ($i = 0; $i < $len; $i++) {
            $idx = $idx * 26 + (ord($letters[$i]) - ord('A') + 1);
        }
        return max(0, $idx - 1);
    }

    public static function isReadableDocumentText(string $text): bool {
        $trimmed = trim($text);
        $len = mb_strlen($trimmed, 'UTF-8');
        if ($len < 10 || !mb_check_encoding($trimmed, 'UTF-8')) {
            return false;
        }
        $badGlyphs = preg_match_all('/[\x{FFFD}\x{0400}-\x{04FF}\x{0600}-\x{06FF}\x{0590}-\x{05FF}\x{2500}-\x{259F}\x{00C0}-\x{024F}]/u', $trimmed);
        $glitchQMarks = preg_match_all('/(?:\?{2,}|(?<=[^\w\s\.\)])\?|\?(?=[A-Za-z0-9]))/u', $trimmed);
        $asciiProseChars = preg_match_all('/[A-Za-z0-9\s\.,;:\!\?\-\–\—\(\)\[\]\/\%\'\"\+\=\&\*@#°\|]/u', $trimmed);
        $ratio = ($asciiProseChars !== false) ? ($asciiProseChars / max(1, $len)) : 0.0;

        if ($ratio < 0.85) {
            return false;
        }
        if ($badGlyphs !== false && ($badGlyphs / max(1, $len)) > 0.02) {
            return false;
        }
        if ($glitchQMarks !== false && $glitchQMarks >= 2 && ($glitchQMarks / max(1, $len)) > 0.03) {
            return false;
        }
        $vowelWords = preg_match_all('/\b(?=[A-Za-z]*[aeiouAEIOU])[A-Za-z]{2,}\b/u', $trimmed);
        return ($vowelWords !== false && $vowelWords >= 3);
    }

    public static function normalizeExtractedText(string $text): string {
        if (!mb_check_encoding($text, 'UTF-8')) {
            $ctrlOrHi = preg_match_all('/[\x00-\x08\x0E-\x1F\x80-\xFF]/', $text);
            if ($ctrlOrHi !== false && ($ctrlOrHi / max(1, strlen($text))) > 0.18) {
                return '';
            }
            $converted = @mb_convert_encoding($text, 'UTF-8', 'Windows-1252');
            if (is_string($converted)) {
                $text = $converted;
            }
        }
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $text);
        $text = preg_replace("/[ \t]+/", ' ', (string)$text);
        $text = preg_replace("/\n{3,}/", "\n\n", (string)$text);
        return trim((string)$text);
    }

    private static function decodeTxtEncoding(string $raw): string {
        if (substr($raw, 0, 3) === "\xEF\xBB\xBF") {
            return substr($raw, 3);
        }
        if (substr($raw, 0, 2) === "\xFF\xFE") {
            return (string)@mb_convert_encoding(substr($raw, 2), 'UTF-8', 'UTF-16LE');
        }
        if (substr($raw, 0, 2) === "\xFE\xFF") {
            return (string)@mb_convert_encoding(substr($raw, 2), 'UTF-8', 'UTF-16BE');
        }
        if (mb_check_encoding($raw, 'UTF-8')) {
            return $raw;
        }
        return (string)@mb_convert_encoding($raw, 'UTF-8', 'Windows-1252,ISO-8859-1');
    }

    private static function extractDocxText(string $tmpPath): string {
        if (!class_exists('ZipArchive')) {
            return '';
        }
        $zip = new ZipArchive();
        if ($zip->open($tmpPath) !== true) {
            return '';
        }
        $parts = [];
        $xmlTargets = [];
        if ($zip->locateName('word/document.xml') !== false) {
            $xmlTargets[] = 'word/document.xml';
        }
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string)$zip->getNameIndex($i);
            if (preg_match('#^word/(header\d*|footer\d*|footnotes|endnotes)\.xml$#i', $name)) {
                $xmlTargets[] = $name;
            }
        }
        foreach ($xmlTargets as $entry) {
            $xml = $zip->getFromName($entry, 4194304);
            if (!is_string($xml) || $xml === '') {
                continue;
            }
            $xml = str_replace(
                ['</w:p>', '</w:tr>', '</w:tc>', '<w:br/>', '<w:br />', '<w:cr/>', '<w:tab/>'],
                ["\n", "\n", ' | ', "\n", "\n", "\n", ' '],
                $xml
            );
            $plain = html_entity_decode(strip_tags($xml), ENT_QUOTES | ENT_XML1, 'UTF-8');
            if (trim($plain) !== '') {
                $parts[] = trim($plain);
            }
        }
        $zip->close();
        return implode("\n\n", $parts);
    }

    private static function extractLegacyDocText(string $tmpPath): string {
        $raw = (string)file_get_contents($tmpPath, false, null, 0, 4194304);
        if (strpos($raw, '{\\rtf') === 0) {
            $txt = preg_replace('/\{\\\\(?:fonttbl|colortbl|stylesheet|info|pict|object)[^{}]*(?:\{[^{}]*\}[^{}]*)*\}/s', ' ', $raw);
            $txt = preg_replace('/\\\\(?:par|line)(?![a-zA-Z])\s?/', "\n", (string)$txt);
            $txt = preg_replace('/\\\\tab(?![a-zA-Z])\s?/', ' ', (string)$txt);
            $txt = preg_replace('/\\\\[a-z]+\-?\d*\s?/i', '', (string)$txt);
            $txt = str_replace(['{', '}'], '', (string)$txt);
            return trim((string)$txt);
        }

        $chunks = [];
        if (preg_match_all('/(?:[\x20-\x7E\x0A\x0D]\x00){10,}/', $raw, $u16)) {
            foreach ($u16[0] as $seg) {
                $decoded = trim((string)@mb_convert_encoding($seg, 'UTF-8', 'UTF-16LE'));
                if ($decoded !== '' && !preg_match('/^(Root Entry|WordDocument|1Table|0Table|SummaryInformation|Microsoft|Normal\.dotm|Times New Roman|Calibri|Cambria)/i', $decoded)) {
                    $chunks[] = $decoded;
                }
            }
        }
        if (!empty($chunks)) {
            return implode("\n", $chunks);
        }

        if (preg_match_all('/[A-Za-z0-9,\.\;\:\!\?\-\(\)\/\s]{24,}/', $raw, $ascii)) {
            $clean = [];
            foreach ($ascii[0] as $seg) {
                $t = trim($seg);
                if (preg_match_all('/[A-Za-z]{3,}/', $t) >= 3 && !preg_match('/(Root Entry|WordDocument|CompObj|ObjInfo|OlePres)/i', $t)) {
                    $clean[] = $t;
                }
            }
            return implode("\n", $clean);
        }
        return '';
    }

    private static function extractPdfText(string $raw): string {
        $objects = self::parsePdfObjects($raw);
        $cmap = [];
        $textParts = [];

        foreach ($objects as $obj) {
            $streamData = self::decodePdfObjectStream($obj['dict'], $obj['stream']);
            if ($streamData === '') {
                continue;
            }
            if (strpos($streamData, 'begincmap') !== false || strpos($streamData, 'beginbfchar') !== false || strpos($streamData, 'beginbfrange') !== false) {
                $parsedMap = self::parseToUnicodeCMap($streamData);
                if (!empty($parsedMap)) {
                    $cmap = $parsedMap + $cmap;
                }
            }
        }

        foreach ($objects as $obj) {
            $dict = $obj['dict'];
            if (self::isNonTextBinaryPdfDict($dict)) {
                continue;
            }
            $streamData = self::decodePdfObjectStream($dict, $obj['stream']);
            if ($streamData === '' || strpos($streamData, 'BT') === false || strpos($streamData, 'ET') === false) {
                continue;
            }
            $extracted = self::extractTextFromPdfContentStream($streamData, $cmap);
            if (trim($extracted) !== '') {
                $textParts[] = trim($extracted);
            }
        }

        if (empty($textParts) && preg_match_all('/<<(.*?)>>\s*stream\r?\n(.*?)\r?\n?endstream/s', $raw, $m, PREG_SET_ORDER)) {
            foreach ($m as $entry) {
                $dict = $entry[1];
                if (self::isNonTextBinaryPdfDict($dict)) {
                    continue;
                }
                $streamData = self::decodePdfObjectStream($dict, $entry[2]);
                if ($streamData !== '' && strpos($streamData, 'BT') !== false && strpos($streamData, 'ET') !== false) {
                    $extracted = self::extractTextFromPdfContentStream($streamData, $cmap);
                    if (trim($extracted) !== '') {
                        $textParts[] = trim($extracted);
                    }
                }
            }
        }

        return implode("\n\n", $textParts);
    }

    private static function isNonTextBinaryPdfDict(string $dict): bool {
        return (bool)preg_match('/\/(?:Subtype\s*\/(?:Image|Type1C|CIDFontType0C|OpenType|XML)|Type\s*\/XRef|FontFile\d?|Length1)\b/', $dict);
    }

    private static function parsePdfObjects(string $raw): array {
        $objects = [];
        if (!preg_match_all('/(\d+)\s+\d+\s+obj\b(.*?)endobj/s', $raw, $matches, PREG_SET_ORDER)) {
            return $objects;
        }
        foreach ($matches as $m) {
            $objId = (int)$m[1];
            $body = $m[2];
            $dict = '';
            $stream = '';
            if (preg_match('/^(.*?)stream(?:\r\n|\r|\n)(.*?)\r?\n?endstream/s', $body, $sm)) {
                $dict = trim($sm[1]);
                $stream = $sm[2];
            } else {
                $dict = trim($body);
            }
            $objects[$objId] = ['dict' => $dict, 'stream' => $stream];

            if ($stream !== '' && preg_match('/\/Type\s*\/ObjStm\b/', $dict)) {
                $objStmData = self::decodePdfObjectStream($dict, $stream);
                if ($objStmData !== '' && preg_match('/\/First\s+(\d+)/', $dict, $fm)) {
                    $firstOffset = (int)$fm[1];
                    $headerStr = trim(substr($objStmData, 0, $firstOffset));
                    $bodyStr = substr($objStmData, $firstOffset);
                    if (preg_match_all('/(\d+)\s+(\d+)/', $headerStr, $pairs, PREG_SET_ORDER)) {
                        $cnt = count($pairs);
                        for ($i = 0; $i < $cnt; $i++) {
                            $subId = (int)$pairs[$i][1];
                            $start = (int)$pairs[$i][2];
                            $end = ($i + 1 < $cnt) ? (int)$pairs[$i + 1][2] : strlen($bodyStr);
                            if (!isset($objects[$subId])) {
                                $objects[$subId] = ['dict' => substr($bodyStr, $start, max(0, $end - $start)), 'stream' => ''];
                            }
                        }
                    }
                }
            }
        }
        return $objects;
    }

    private static function decodePdfObjectStream(string $dict, string $stream): string {
        if ($stream === '') {
            return '';
        }
        $data = $stream;
        $hasFlate = strpos($dict, '/FlateDecode') !== false || strpos($dict, '/Fl') !== false;
        $hasAsciiHex = strpos($dict, '/ASCIIHexDecode') !== false;
        $hasAscii85 = strpos($dict, '/ASCII85Decode') !== false;
        $hasOtherBinaryFilter = (bool)preg_match('/\/(?:DCTDecode|JPXDecode|JBIG2Decode|CCITTFaxDecode|LZWDecode|RunLengthDecode)\b/', $dict);

        if ($hasOtherBinaryFilter) {
            return '';
        }
        if ($hasAsciiHex) {
            $hex = preg_replace('/[^0-9A-Fa-f]/', '', explode('>', $data)[0]);
            if (strlen($hex) % 2 === 1) $hex .= '0';
            $bin = @hex2bin($hex);
            if (is_string($bin)) $data = $bin;
        }
        if ($hasAscii85) {
            $data = self::decodeAscii85($data);
        }
        if ($hasFlate) {
            $un = @gzuncompress($data);
            if ($un === false) {
                $un = @gzinflate($data);
            }
            if ($un === false && strlen($data) > 2) {
                $un = @gzinflate(substr($data, 2));
            }
            return is_string($un) ? $un : '';
        }
        if (!$hasFlate && preg_match('/[\x00-\x08\x0E-\x1F]{4,}/', substr($data, 0, 200))) {
            return '';
        }
        return $data;
    }

    private static function decodeAscii85(string $str): string {
        $str = preg_replace('/\s+/', '', $str);
        if (strpos($str, '<~') === 0) $str = substr($str, 2);
        $endPos = strpos($str, '~>');
        if ($endPos !== false) $str = substr($str, 0, $endPos);
        $out = '';
        $chunk = [];
        $len = strlen($str);
        for ($i = 0; $i < $len; $i++) {
            $c = $str[$i];
            if ($c === 'z' && empty($chunk)) {
                $out .= "\0\0\0\0";
                continue;
            }
            $val = ord($c) - 33;
            if ($val < 0 || $val > 84) continue;
            $chunk[] = $val;
            if (count($chunk) === 5) {
                $num = 0;
                foreach ($chunk as $v) $num = $num * 85 + $v;
                $out .= pack('N', $num);
                $chunk = [];
            }
        }
        if (!empty($chunk)) {
            $pad = 5 - count($chunk);
            while (count($chunk) < 5) $chunk[] = 84;
            $num = 0;
            foreach ($chunk as $v) $num = $num * 85 + $v;
            $packed = pack('N', $num);
            $out .= substr($packed, 0, 4 - $pad);
        }
        return $out;
    }

    private static function parseToUnicodeCMap(string $cmapData): array {
        $map = [];
        if (preg_match_all('/beginbfchar\s*(.*?)\s*endbfchar/s', $cmapData, $secM)) {
            foreach ($secM[1] as $sec) {
                if (preg_match_all('/<([0-9A-Fa-f]+)>\s*<([0-9A-Fa-f]+)>/', $sec, $pairs, PREG_SET_ORDER)) {
                    foreach ($pairs as $p) {
                        $src = strtoupper($p[1]);
                        $dst = self::hexUtf16BeToUtf8($p[2]);
                        if ($dst !== '') {
                            $map[$src] = $dst;
                        }
                    }
                }
            }
        }
        if (preg_match_all('/beginbfrange\s*(.*?)\s*endbfrange/s', $cmapData, $rngM)) {
            foreach ($rngM[1] as $sec) {
                if (preg_match_all('/<([0-9A-Fa-f]+)>\s*<([0-9A-Fa-f]+)>\s*(?:<([0-9A-Fa-f]+)>|\[([^\]]+)\])/', $sec, $ranges, PREG_SET_ORDER)) {
                    foreach ($ranges as $r) {
                        $startHex = $r[1];
                        $start = hexdec($startHex);
                        $end = hexdec($r[2]);
                        $hexLen = strlen($startHex);
                        if ($end - $start > 2048 || $end < $start) continue;
                        if (!empty($r[3])) {
                            $dstStart = hexdec($r[3]);
                            $dstLen = strlen($r[3]);
                            for ($code = $start; $code <= $end; $code++) {
                                $k = strtoupper(str_pad(dechex((int)$code), $hexLen, '0', STR_PAD_LEFT));
                                $dHex = str_pad(dechex((int)($dstStart + ($code - $start))), $dstLen, '0', STR_PAD_LEFT);
                                $u = self::hexUtf16BeToUtf8($dHex);
                                if ($u !== '') $map[$k] = $u;
                            }
                        } elseif (!empty($r[4])) {
                            preg_match_all('/<([0-9A-Fa-f]+)>/', $r[4], $arrHex);
                            $idx = 0;
                            for ($code = $start; $code <= $end; $code++, $idx++) {
                                if (!isset($arrHex[1][$idx])) break;
                                $k = strtoupper(str_pad(dechex((int)$code), $hexLen, '0', STR_PAD_LEFT));
                                $u = self::hexUtf16BeToUtf8($arrHex[1][$idx]);
                                if ($u !== '') $map[$k] = $u;
                            }
                        }
                    }
                }
            }
        }
        return $map;
    }

    private static function hexUtf16BeToUtf8(string $hex): string {
        $clean = preg_replace('/[^0-9A-Fa-f]/', '', $hex);
        if ($clean === '') return '';
        if (strlen($clean) % 2 === 1) $clean = '0' . $clean;
        $bin = @hex2bin($clean);
        if (!is_string($bin) || $bin === '') return '';
        if (strlen($bin) >= 2 && (strlen($bin) % 2 === 0)) {
            $u8 = @mb_convert_encoding($bin, 'UTF-8', 'UTF-16BE');
            if (is_string($u8) && $u8 !== '') return $u8;
        }
        return (string)@mb_convert_encoding($bin, 'UTF-8', 'Windows-1252');
    }

    private static function extractTextFromPdfContentStream(string $streamData, array $cmap): string {
        if (!preg_match_all('/\bBT\b(.*?)\bET\b/s', $streamData, $btBlocks)) {
            return '';
        }
        $lines = [];
        foreach ($btBlocks[1] as $block) {
            $buf = '';
            $len = strlen($block);
            $i = 0;
            $lastOperandText = '';
            $inArray = false;
            $arrayParts = [];

            while ($i < $len) {
                $ch = $block[$i];
                if (ctype_space($ch)) {
                    $i++;
                    continue;
                }
                if ($ch === '%') {
                    while ($i < $len && $block[$i] !== "\n" && $block[$i] !== "\r") $i++;
                    continue;
                }
                if ($ch === '(') {
                    [$lit, $nextPos] = self::readPdfLiteralString($block, $i);
                    $i = $nextPos;
                    $decoded = self::decodePdfLiteralToUtf8($lit);
                    if ($inArray) {
                        $arrayParts[] = $decoded;
                    } else {
                        $lastOperandText = $decoded;
                    }
                    continue;
                }
                if ($ch === '<' && ($i + 1 >= $len || $block[$i + 1] !== '<')) {
                    $endPos = strpos($block, '>', $i + 1);
                    if ($endPos === false) break;
                    $hex = substr($block, $i + 1, $endPos - $i - 1);
                    $i = $endPos + 1;
                    $decoded = self::decodePdfHexToUtf8($hex, $cmap);
                    if ($inArray) {
                        $arrayParts[] = $decoded;
                    } else {
                        $lastOperandText = $decoded;
                    }
                    continue;
                }
                if ($ch === '[') {
                    $inArray = true;
                    $arrayParts = [];
                    $i++;
                    continue;
                }
                if ($ch === ']') {
                    $inArray = false;
                    $lastOperandText = implode('', $arrayParts);
                    $arrayParts = [];
                    $i++;
                    continue;
                }
                if ($ch === '-' || $ch === '+' || $ch === '.' || ctype_digit($ch)) {
                    $numStart = $i;
                    $i++;
                    while ($i < $len && (ctype_digit($block[$i]) || $block[$i] === '.')) $i++;
                    if ($inArray) {
                        $kern = (float)substr($block, $numStart, $i - $numStart);
                        if ($kern <= -120.0 && !empty($arrayParts)) {
                            $last = end($arrayParts);
                            if ($last !== '' && substr($last, -1) !== ' ') {
                                $arrayParts[] = ' ';
                            }
                        }
                    }
                    continue;
                }
                if (ctype_alpha($ch) || $ch === "'" || $ch === '"') {
                    $opStart = $i;
                    if ($ch === "'" || $ch === '"') {
                        $op = $ch;
                        $i++;
                    } else {
                        while ($i < $len && (ctype_alnum($block[$i]) || $block[$i] === '*')) $i++;
                        $op = substr($block, $opStart, $i - $opStart);
                    }
                    if ($op === 'Tj' || $op === 'TJ' || $op === "'" || $op === '"') {
                        if ($op === "'" || $op === '"') {
                            $buf .= "\n";
                        }
                        if ($lastOperandText !== '') {
                            if ($buf !== '' && substr($buf, -1) !== ' ' && substr($buf, -1) !== "\n" && $lastOperandText[0] !== ' ') {
                                if (preg_match('/[A-Za-z0-9\.,\?\!\)%]$/', $buf) && preg_match('/^[A-Za-z0-9\(]/', $lastOperandText)) {
                                    $buf .= ' ';
                                }
                            }
                            $buf .= $lastOperandText;
                        }
                        $lastOperandText = '';
                    } elseif ($op === 'Td' || $op === 'TD' || $op === 'T*' || $op === 'Tm') {
                        if ($buf !== '' && substr($buf, -1) !== "\n" && substr($buf, -1) !== ' ') {
                            $buf .= "\n";
                        }
                    }
                    continue;
                }
                $i++;
            }
            $trimmed = trim($buf);
            if ($trimmed !== '') {
                $lines[] = $trimmed;
            }
        }
        return implode("\n", $lines);
    }

    private static function readPdfLiteralString(string $s, int $startPos): array {
        $len = strlen($s);
        $i = $startPos + 1;
        $depth = 1;
        $out = '';
        while ($i < $len && $depth > 0) {
            $c = $s[$i];
            if ($c === '\\' && $i + 1 < $len) {
                $n = $s[$i + 1];
                if ($n === 'n') { $out .= "\n"; $i += 2; continue; }
                if ($n === 'r') { $out .= "\r"; $i += 2; continue; }
                if ($n === 't') { $out .= "\t"; $i += 2; continue; }
                if ($n === 'b') { $out .= "\x08"; $i += 2; continue; }
                if ($n === 'f') { $out .= "\x0C"; $i += 2; continue; }
                if ($n === '(' || $n === ')' || $n === '\\') { $out .= $n; $i += 2; continue; }
                if ($n === "\r" || $n === "\n") {
                    $i += ($n === "\r" && $i + 2 < $len && $s[$i + 2] === "\n") ? 3 : 2;
                    continue;
                }
                if (ctype_digit($n) && $n <= '7') {
                    $oct = $n;
                    $k = 1;
                    while ($k < 3 && $i + 1 + $k < $len && ctype_digit($s[$i + 1 + $k]) && $s[$i + 1 + $k] <= '7') {
                        $oct .= $s[$i + 1 + $k];
                        $k++;
                    }
                    $out .= chr((int)octdec($oct));
                    $i += 1 + $k;
                    continue;
                }
                $out .= $n;
                $i += 2;
                continue;
            }
            if ($c === '(') {
                $depth++;
                $out .= $c;
            } elseif ($c === ')') {
                $depth--;
                if ($depth > 0) $out .= $c;
            } else {
                $out .= $c;
            }
            $i++;
        }
        return [$out, $i];
    }

    private static function decodePdfLiteralToUtf8(string $raw): string {
        if ($raw === '') return '';
        if (substr($raw, 0, 2) === "\xFE\xFF") {
            return (string)@mb_convert_encoding(substr($raw, 2), 'UTF-8', 'UTF-16BE');
        }
        if (mb_check_encoding($raw, 'UTF-8') && preg_match('/[\x80-\xFF]/', $raw)) {
            return $raw;
        }
        return (string)@mb_convert_encoding($raw, 'UTF-8', 'Windows-1252');
    }

    private static function decodePdfHexToUtf8(string $hex, array $cmap): string {
        $clean = strtoupper(preg_replace('/[^0-9A-Fa-f]/', '', $hex));
        if ($clean === '') return '';
        if (strlen($clean) % 2 === 1) $clean .= '0';

        if (!empty($cmap)) {
            $out = '';
            $matchedAny = false;
            $len = strlen($clean);
            if ($len % 4 === 0) {
                for ($p = 0; $p < $len; $p += 4) {
                    $code4 = substr($clean, $p, 4);
                    if (isset($cmap[$code4])) {
                        $out .= $cmap[$code4];
                        $matchedAny = true;
                    } elseif (isset($cmap[ltrim($code4, '0')])) {
                        $out .= $cmap[ltrim($code4, '0')];
                        $matchedAny = true;
                    }
                }
            }
            if (!$matchedAny) {
                $out = '';
                for ($p = 0; $p < $len; $p += 2) {
                    $code2 = substr($clean, $p, 2);
                    if (isset($cmap[$code2])) {
                        $out .= $cmap[$code2];
                        $matchedAny = true;
                    }
                }
            }
            if ($matchedAny && trim($out) !== '') {
                return $out;
            }
        }

        if (strpos($clean, 'FEFF') === 0) {
            return self::hexUtf16BeToUtf8(substr($clean, 4));
        }
        $bin = @hex2bin($clean);
        if (!is_string($bin) || $bin === '') return '';
        if (preg_match('/[\x00-\x08\x0E-\x1F]/', $bin)) {
            $try16 = @mb_convert_encoding($bin, 'UTF-8', 'UTF-16BE');
            if (is_string($try16) && preg_match('/^[A-Za-z0-9\s\.,;:\!\?\-\(\)\/]+$/', $try16)) {
                return $try16;
            }
            return '';
        }
        return (string)@mb_convert_encoding($bin, 'UTF-8', 'Windows-1252');
    }
}
