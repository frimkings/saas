<?php

namespace App\Services\ExternalImport;

use RuntimeException;
use SimpleXMLElement;
use ZipArchive;

/**
 * Reads the first worksheet of an .xlsx file as a table: row 1 is the header, every later row
 * becomes [header => value]. Values come back as Excel stores them (numbers and dates as numbers),
 * so the caller decides what a column means. Built on ZipArchive like the lens import, no library.
 */
class XlsxTableReader
{
    private const MAIN = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
    private const MAX_ROWS = 50000;
    private const MAX_COLUMNS = 200;

    /** @return array{headers: list<string>, rows: list<array<string, string>>} */
    public function read(string $path): array
    {
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) throw new RuntimeException('This is not a readable .xlsx workbook.');
        try {
            $size = 0;
            for ($i = 0; $i < $zip->numFiles; $i++) $size += $zip->statIndex($i)['size'];
            if ($zip->numFiles > 500 || $size > 150_000_000) throw new RuntimeException('This workbook is too large to import.');

            $strings = [];
            if ($zip->locateName('xl/sharedStrings.xml') !== false) {
                foreach ($this->xml($zip, 'xl/sharedStrings.xml')->xpath('//m:si') as $item) {
                    $item->registerXPathNamespace('m', self::MAIN);
                    $strings[] = implode('', array_map('strval', $item->xpath('.//m:t')));
                }
            }

            $cells = [];
            foreach ($this->xml($zip, $this->firstSheet($zip))->xpath('//m:sheetData/m:row/m:c') as $cell) {
                if (! preg_match('/^([A-Z]+)([0-9]+)$/', (string) $cell['r'], $match)) continue;
                $col = 0;
                foreach (str_split($match[1]) as $letter) $col = $col * 26 + ord($letter) - 64;
                $row = (int) $match[2];
                if ($row > self::MAX_ROWS || $col > self::MAX_COLUMNS) throw new RuntimeException('A sheet may have at most '.number_format(self::MAX_ROWS).' rows and '.self::MAX_COLUMNS.' columns.');
                $data = $cell->children(self::MAIN);
                $type = (string) $cell['t'];
                if ($type === 's') $value = $strings[(int) (string) $data->v] ?? '';
                elseif ($type === 'inlineStr') {
                    $cell->registerXPathNamespace('m', self::MAIN);
                    $value = implode('', array_map('strval', $cell->xpath('.//m:t')));
                } else $value = (string) $data->v;
                $value = $this->unescape($value);
                if (trim($value) !== '') $cells[$row][$col] = $value;
            }
        } finally {
            $zip->close();
        }

        ksort($cells);
        $headerRow = array_shift($cells) ?? [];
        $headers = [];
        foreach ($headerRow as $col => $name) $headers[$col] = strtoupper(trim($name));
        $rows = [];
        foreach ($cells as $values) {
            $row = [];
            foreach ($headers as $col => $name) if (isset($values[$col])) $row[$name] = $values[$col];
            if ($row) $rows[] = $row;
        }

        return ['headers' => array_values($headers), 'rows' => $rows];
    }

    private function firstSheet(ZipArchive $zip): string
    {
        $targets = [];
        foreach ($this->xml($zip, 'xl/_rels/workbook.xml.rels')->children('http://schemas.openxmlformats.org/package/2006/relationships') as $rel) {
            if ((string) $rel->attributes()['TargetMode'] !== 'External') $targets[(string) $rel->attributes()['Id']] = (string) $rel->attributes()['Target'];
        }
        foreach ($this->xml($zip, 'xl/workbook.xml')->xpath('//m:sheet') as $sheet) {
            $target = $targets[(string) $sheet->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id']] ?? '';
            $target = str_starts_with($target, '/') ? ltrim($target, '/') : 'xl/'.$target;
            if (! str_contains($target, '..') && str_starts_with($target, 'xl/worksheets/')) return $target;
        }
        throw new RuntimeException('The workbook has no worksheet.');
    }

    private function xml(ZipArchive $zip, string $path): SimpleXMLElement
    {
        $text = $zip->getFromName($path);
        if ($text === false) throw new RuntimeException('The workbook is missing '.$path.'.');
        if (stripos($text, '<!DOCTYPE') !== false || stripos($text, '<!ENTITY') !== false) throw new RuntimeException('The workbook contains unsupported XML.');
        $xml = simplexml_load_string($text, SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_PARSEHUGE);
        if ($xml === false) throw new RuntimeException('The workbook could not be read. Save it as .xlsx and try again.');
        $xml->registerXPathNamespace('m', self::MAIN);

        return $xml;
    }

    /** Excel writes characters XML can't hold as _xHHHH_ (e.g. _x000D_ for a carriage return). */
    private function unescape(string $value): string
    {
        $value = preg_replace_callback('/_x([0-9A-Fa-f]{4})_/', fn ($m) => mb_chr(hexdec($m[1]), 'UTF-8'), $value);

        return str_replace(["\r\n", "\r"], "\n", $value);
    }
}
