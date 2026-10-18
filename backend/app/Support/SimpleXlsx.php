<?php

namespace App\Support;

use DOMDocument;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use ZipArchive;

class SimpleXlsx
{
    /**
     * @param  list<string>  $headers
     * @param  list<list<string|int|float|null>>  $rows
     */
    public static function download(string $filename, array $headers, array $rows): StreamedResponse
    {
        $path = tempnam(sys_get_temp_dir(), 'xlsx');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', self::contentTypes());
        $zip->addFromString('_rels/.rels', self::rootRels());
        $zip->addFromString('xl/workbook.xml', self::workbook());
        $zip->addFromString('xl/_rels/workbook.xml.rels', self::workbookRels());
        $zip->addFromString('xl/worksheets/sheet1.xml', self::sheet(array_merge([$headers], $rows)));
        $zip->close();

        return response()->streamDownload(function () use ($path): void {
            readfile($path);
            @unlink($path);
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    /** @param  list<list<string|int|float|null>>  $rows */
    private static function sheet(array $rows): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';
        foreach ($rows as $index => $row) {
            $xml .= '<row r="'.($index + 1).'">';
            foreach (array_values($row) as $column => $value) {
                $ref = self::column($column).($index + 1);
                $text = htmlspecialchars((string) $value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
                $xml .= '<c r="'.$ref.'" t="inlineStr"><is><t>'.$text.'</t></is></c>';
            }
            $xml .= '</row>';
        }

        return $xml.'</sheetData></worksheet>';
    }

    /**
     * Read the first worksheet, or a CSV file, into rows of plain text.
     *
     * @return list<list<string>>
     */
    public static function read(string $path): array
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if (in_array($extension, ['csv', 'txt'], true)) {
            return self::readCsv($path);
        }

        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            throw new RuntimeException('That file is not a readable Excel workbook. Save it as .xlsx or .csv.');
        }

        $shared = self::sharedStrings($zip->getFromName('xl/sharedStrings.xml') ?: '');
        $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
        if ($sheet === false) {
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $name = (string) $zip->getNameIndex($index);
                if (preg_match('#^xl/worksheets/sheet\d+\.xml$#', $name) === 1) {
                    $sheet = $zip->getFromName($name);
                    break;
                }
            }
        }
        $zip->close();
        if (! is_string($sheet) || $sheet === '') {
            throw new RuntimeException('The workbook has no sheet to read.');
        }

        return self::sheetRows($sheet, $shared);
    }

    /** @return list<string> */
    private static function sharedStrings(string $xml): array
    {
        if ($xml === '') {
            return [];
        }

        $document = new DOMDocument;
        $document->loadXML($xml);
        $strings = [];
        foreach ($document->getElementsByTagName('si') as $item) {
            $text = '';
            foreach ($item->getElementsByTagName('t') as $node) {
                $text .= $node->textContent;
            }
            $strings[] = $text;
        }

        return $strings;
    }

    /**
     * @param  list<string>  $shared
     * @return list<list<string>>
     */
    private static function sheetRows(string $xml, array $shared): array
    {
        $document = new DOMDocument;
        $document->loadXML($xml);
        $rows = [];
        foreach ($document->getElementsByTagName('row') as $row) {
            $cells = [];
            $last = -1;
            foreach ($row->getElementsByTagName('c') as $cell) {
                if (! $cell instanceof \DOMElement) {
                    continue;
                }
                $column = self::columnIndex((string) preg_replace('/\d+/', '', $cell->getAttribute('r')));
                $type = $cell->getAttribute('t');
                if ($type === 'inlineStr') {
                    $value = '';
                    foreach ($cell->getElementsByTagName('t') as $node) {
                        $value .= $node->textContent;
                    }
                } elseif ($type === 's') {
                    $value = $shared[(int) self::directValue($cell)] ?? '';
                } else {
                    $value = self::directValue($cell);
                }
                $cells[$column] = trim($value);
                $last = max($last, $column);
            }
            $line = [];
            for ($index = 0; $index <= $last; $index++) {
                $line[] = $cells[$index] ?? '';
            }
            $rows[] = $line;
        }

        return $rows;
    }

    private static function directValue(\DOMElement $cell): string
    {
        foreach ($cell->childNodes as $child) {
            if ($child instanceof \DOMElement && $child->localName === 'v') {
                return $child->textContent;
            }
        }

        return '';
    }

    private static function columnIndex(string $letters): int
    {
        $index = 0;
        foreach (str_split(strtoupper($letters)) as $letter) {
            $index = ($index * 26) + (ord($letter) - 64);
        }

        return max(0, $index - 1);
    }

    /** @return list<list<string>> */
    private static function readCsv(string $path): array
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException('The spreadsheet could not be opened.');
        }

        $first = fgets($handle);
        if ($first === false) {
            fclose($handle);

            return [];
        }
        $first = preg_replace('/^\xEF\xBB\xBF/', '', $first) ?? $first;
        $delimiter = substr_count($first, ';') > substr_count($first, ',') ? ';' : ',';
        $rows = [str_getcsv($first, $delimiter)];
        while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
            $rows[] = array_map(fn ($value) => trim((string) $value), $row);
        }
        fclose($handle);

        return $rows;
    }

    private static function column(int $index): string
    {
        $name = '';
        $index++;
        while ($index > 0) {
            $index--;
            $name = chr(65 + ($index % 26)).$name;
            $index = intdiv($index, 26);
        }

        return $name;
    }

    private static function contentTypes(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            .'<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            .'</Types>';
    }

    private static function rootRels(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            .'</Relationships>';
    }

    private static function workbook(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<sheets><sheet name="Class grades" sheetId="1" r:id="rId1"/></sheets></workbook>';
    }

    private static function workbookRels(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            .'</Relationships>';
    }
}
