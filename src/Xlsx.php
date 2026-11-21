<?php
declare(strict_types=1);
namespace KienzleSumup;

/**
 * Kleine XLSX-Ausgabe (Office Open XML) ohne Zusatzpakete: mehrere Tabellenblätter,
 * fette Kopfzeile mit Filter, fixierte erste Zeile, Euro-, Datums- und Prozentformat,
 * Summenzeilen mit Formel und zwischengespeichertem Ergebnis.
 */
final class Xlsx {
    // Formatnummern in styles.xml (cellXfs-Reihenfolge)
    public const TEXT = 0, HEAD = 1, MONEY = 2, DATE = 3, PERCENT = 4, SUM_MONEY = 5, SUM_TEXT = 6, INT = 7, SUM_INT = 8;
    private array $sheets = [];

    /**
     * @param array $columns Liste von ['title' => ..., 'type' => text|money|date|percent|int, 'width' => Zeichen]
     * @param array $rows    Zeilen als Listen; money in Cent (int), date als 'Y-m-d', percent in Hundertstelprozent
     * @param bool  $sum     Summenzeile für money- und int-Spalten anhängen
     */
    public function sheet(string $name, array $columns, array $rows, bool $sum = false): void {
        $this->sheets[] = [mb_substr(preg_replace('/[\[\]\*\?\/\\\\:]/u', ' ', $name), 0, 31), $columns, $rows, $sum];
    }

    public function build(): string {
        $files = [
            '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
                . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
                . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
                . '<Default Extension="xml" ContentType="application/xml"/>'
                . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
                . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
                . '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>'
                . implode('', array_map(fn($i) => '<Override PartName="/xl/worksheets/sheet' . $i . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>', range(1, count($this->sheets))))
                . '</Types>',
            '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
                . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
                . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>'
                . '</Relationships>',
            'docProps/core.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
                . '<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
                . '<dc:title>Rechnungen Kienzle-SumUp</dc:title><dc:creator>Kienzle-SumUp</dc:creator>'
                . '<dcterms:created xsi:type="dcterms:W3CDTF">' . gmdate('Y-m-d\TH:i:s\Z') . '</dcterms:created></cp:coreProperties>',
            'xl/workbook.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
                . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>'
                . implode('', array_map(fn($i, $s) => '<sheet name="' . self::xml($s[0]) . '" sheetId="' . ($i + 1) . '" r:id="rId' . ($i + 1) . '"/>', array_keys($this->sheets), $this->sheets))
                . '</sheets>' . $this->definedNames() . '</workbook>',
            'xl/_rels/workbook.xml.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
                . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                . implode('', array_map(fn($i) => '<Relationship Id="rId' . $i . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $i . '.xml"/>', range(1, count($this->sheets))))
                . '<Relationship Id="rId' . (count($this->sheets) + 1) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
                . '</Relationships>',
            'xl/styles.xml' => self::styles(),
        ];
        foreach ($this->sheets as $i => $sheet) $files['xl/worksheets/sheet' . ($i + 1) . '.xml'] = $this->worksheet(...$sheet);
        $zip = '';
        ClientPackage::zip(array_map(fn($data) => ['data' => $data, 'mode' => 0644], $files), function (string $bytes) use (&$zip): void { $zip .= $bytes; });
        return $zip;
    }

    private function definedNames(): string {
        $names = '';
        foreach ($this->sheets as $i => [$name, $columns, $rows]) {
            $names .= '<definedName name="_xlnm._FilterDatabase" localSheetId="' . $i . '" hidden="1">\'' . self::xml(str_replace("'", "''", $name)) . '\'!$A$1:$'
                . self::col(count($columns) - 1) . '$' . (count($rows) + 1) . '</definedName>';
        }
        return '<definedNames>' . $names . '</definedNames>';
    }

    private function worksheet(string $name, array $columns, array $rows, bool $sum): string {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheetPr><pageSetUpPr fitToPage="1"/></sheetPr><sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews><cols>';
        foreach ($columns as $i => $column) $xml .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="' . ($column['width'] ?? 14) . '" customWidth="1"/>';
        $xml .= '</cols><sheetData><row r="1">';
        foreach ($columns as $i => $column) $xml .= self::cell($i, 1, $column['title'], self::HEAD);
        $xml .= '</row>';
        $totals = [];
        foreach ($rows as $r => $row) {
            $n = $r + 2;
            $xml .= '<row r="' . $n . '">';
            foreach ($columns as $i => $column) {
                $value = $row[$i] ?? null;
                if ($value === null || $value === '') continue;
                $type = $column['type'] ?? 'text';
                if ($type === 'money' || $type === 'int') $totals[$i] = ($totals[$i] ?? 0) + (int)$value;
                $xml .= match ($type) {
                    'money' => self::number($i, $n, (int)$value / 100, self::MONEY),
                    'int' => self::number($i, $n, (int)$value, self::INT),
                    'percent' => self::number($i, $n, (int)$value / 10000, self::PERCENT),
                    'date' => self::number($i, $n, self::serial((string)$value), self::DATE),
                    default => self::cell($i, $n, (string)$value, self::TEXT),
                };
            }
            $xml .= '</row>';
        }
        $last = count($rows) + 1;
        if ($sum && $rows) {
            $n = $last + 1;
            $xml .= '<row r="' . $n . '">' . self::cell(0, $n, 'Summe', self::SUM_TEXT);
            foreach ($columns as $i => $column) {
                if (!in_array($column['type'] ?? 'text', ['money', 'int'], true) || $i === 0) continue;
                $money = $column['type'] === 'money';
                $value = $money ? ($totals[$i] ?? 0) / 100 : ($totals[$i] ?? 0);
                $ref = self::col($i);
                $xml .= '<c r="' . $ref . $n . '" s="' . ($money ? self::SUM_MONEY : self::SUM_INT) . '"><f>SUM(' . $ref . '2:' . $ref . $last . ')</f><v>' . self::num($value) . '</v></c>';
            }
            $xml .= '</row>';
        }
        $xml .= '</sheetData><autoFilter ref="A1:' . self::col(count($columns) - 1) . $last . '"/>'
            . '<pageMargins left="0.5" right="0.5" top="0.6" bottom="0.6" header="0.3" footer="0.3"/><pageSetup paperSize="9" orientation="landscape" fitToWidth="1" fitToHeight="0"/></worksheet>';
        return $xml;
    }

    private static function styles(): string {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<numFmts count="3"><numFmt numFmtId="164" formatCode="#,##0.00\ &quot;€&quot;"/><numFmt numFmtId="165" formatCode="dd\.mm\.yyyy"/><numFmt numFmtId="166" formatCode="0.0#%"/></numFmts>'
            . '<fonts count="2"><font><sz val="11"/><name val="Calibri"/><family val="2"/></font><font><b/><sz val="11"/><name val="Calibri"/><family val="2"/></font></fonts>'
            . '<fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FFE5F3ED"/><bgColor indexed="64"/></patternFill></fill></fills>'
            . '<borders count="2"><border><left/><right/><top/><bottom/><diagonal/></border><border><left/><right/><top style="thin"><color auto="1"/></top><bottom/><diagonal/></border></borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="9">'
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            . '<xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/>'
            . '<xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
            . '<xf numFmtId="165" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
            . '<xf numFmtId="166" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
            . '<xf numFmtId="164" fontId="1" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyFont="1" applyBorder="1"/>'
            . '<xf numFmtId="0" fontId="1" fillId="0" borderId="1" xfId="0" applyFont="1" applyBorder="1"/>'
            . '<xf numFmtId="1" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
            . '<xf numFmtId="1" fontId="1" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyFont="1" applyBorder="1"/>'
            . '</cellXfs><cellStyles count="1"><cellStyle name="Standard" xfId="0" builtinId="0"/></cellStyles></styleSheet>';
    }

    public static function col(int $index): string {
        $name = '';
        for ($index++; $index > 0; $index = intdiv($index - 1, 26)) $name = chr(65 + ($index - 1) % 26) . $name;
        return $name;
    }
    /** Excel-Seriennummer (Tage seit 30.12.1899). */
    public static function serial(string $date): int {
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $date, new \DateTimeZone('UTC'));
        if (!$d) throw new \InvalidArgumentException('Datum erwartet: ' . $date);
        return intdiv($d->getTimestamp(), 86400) + 25569;
    }
    private static function cell(int $i, int $row, string $text, int $style): string {
        // Formeln aus Fremdtexten verhindern: Text bleibt Text (Inline-String), auch mit = am Anfang.
        return '<c r="' . self::col($i) . $row . '" t="inlineStr" s="' . $style . '"><is><t xml:space="preserve">' . self::xml($text) . '</t></is></c>';
    }
    private static function number(int $i, int $row, int|float $value, int $style): string {
        return '<c r="' . self::col($i) . $row . '" s="' . $style . '"><v>' . self::num($value) . '</v></c>';
    }
    private static function num(int|float $value): string {
        return is_int($value) ? (string)$value : rtrim(rtrim(sprintf('%.4F', $value), '0'), '.');
    }
    private static function xml(string $text): string {
        $text = preg_replace('/[\x00-\x08\x0b\x0c\x0e-\x1f]/u', ' ', $text) ?? '';
        return htmlspecialchars($text, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
