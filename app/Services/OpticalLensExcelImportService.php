<?php

namespace App\Services;

use Illuminate\Validation\ValidationException;
use SimpleXMLElement;
use ZipArchive;

class OpticalLensExcelImportService
{
    public function readUpload($upload): array
    {
        // A short local path also works with remote upload disks and Windows long filenames.
        $path = tempnam(sys_get_temp_dir(), 'lens_');
        try {
            file_put_contents($path, $upload->get());
            return $this->read($path);
        } finally { if (is_file($path)) unlink($path); }
    }

    public function specifications(array $sheet): array
    {
        if (($sheet['rows'][2][2] ?? '') === 'LENS-GRID-1') {
            $specs = ['template' => 'LENS-GRID-1'];
            foreach ([3 => 'range', 4 => 'design', 5 => 'coating', 6 => 'index', 7 => 'diameter', 8 => 'unit'] as $row => $key) {
                $specs[$key] = trim($sheet['rows'][$row][2] ?? '');
            }
            if (is_numeric($specs['index'])) $specs['index'] = number_format((float) $specs['index'], 2, '.', '');
            return $specs + $this->eyeFromTitle($sheet['name']);
        }
        $title = strtoupper(trim($sheet['name']));
        $text = $title.' '.implode(' ', array_merge(...array_values(array_slice($sheet['rows'], 0, 5, true))));
        $specs = [];
        if (str_contains($title, 'PROGRESSIVE')) $specs['design'] = 'Progressive';
        elseif (str_contains($title, 'BIFOCAL')) $specs['design'] = 'Bifocal';
        elseif (str_contains($title, 'BLUE BLOCK')) $specs['design'] = 'Single Vision';
        if (str_contains($title, 'PHOTOGRAY')) $specs['coating'] = 'Photo Gray';
        elseif (str_contains($title, 'BLUE BLOCK')) $specs['coating'] = 'BlueCut';
        if (preg_match('/\b(1\.50|1\.56|1\.60|1\.61|1\.67|1\.74)\b/', $text, $m)) $specs['index'] = $m[1];
        if (preg_match('/\b(\d{2})\s*mm\b/i', $text, $m)) $specs['diameter'] = $m[1];
        return $specs + $this->eyeFromTitle($sheet['name']);
    }

    /** Progressive and bifocal sheets are per eye, e.g. "PROGRESSIVE RIGHT" or "BIFOCAL (L)". */
    private function eyeFromTitle(string $name): array
    {
        $title = strtoupper($name);
        if (preg_match('/\bRIGHT\b|\bOD\b|\(R\)|\bR\b/', $title)) return ['eye' => 'R'];
        if (preg_match('/\bLEFT\b|\bOS\b|\(L\)|\bL\b/', $title)) return ['eye' => 'L'];
        return [];
    }

    private function powerNumber($value): ?float
    {
        $value = trim(str_replace("\u{2212}", '-', (string) $value));
        // Manufacturer notation: +0 25, - 0 75, -1 00.
        if (preg_match('/^([+-]?)\s*(\d+)\s+(\d{2})$/', $value, $m)) $value = $m[1].$m[2].'.'.$m[3];
        return is_numeric($value) ? (float) $value : null;
    }

    public function previewTemplate(array $sheet, string $design, string $unit): array
    {
        if (! in_array($unit, ['pieces', 'pairs'], true)) $this->fail('Choose whether the workbook quantities are pieces or pairs.');
        $detected = $this->specifications($sheet);
        if (isset($detected['design']) && $detected['design'] !== $design) $this->fail('The selected lens design does not match this worksheet.');
        $columns = []; $quantities = []; $lines = []; $seen = []; $section = 0; $sign = 1;
        $warnings = []; $sectionTotals = []; $rawTotal = 0;
        foreach ($sheet['rows'] as $row => $cells) {
            $label = trim($cells[1] ?? '');
            $marker = preg_replace('/[\s()]/', '', $label);
            $candidate = [];
            if (in_array(strtoupper($marker), ['', '+', '-', 'SPH', 'SPH/CYL', 'SPH/ADD'], true)) {
                foreach ($cells as $col => $value) {
                    if ($col <= 1 || $value === '' || preg_match('/total|final/i', $value)) continue;
                    $power = $this->powerNumber($value);
                    if ($power !== null) $candidate[$col] = $power;
                }
            }
            if (count($candidate) >= 2) {
                $section++; $columns = []; $seen = []; $sign = $marker === '-' ? -1 : 1;
                foreach ($candidate as $col => $power) {
                    if (abs($power * 4 - round($power * 4)) > .00001 || ($design === 'Single Vision' ? ($power > 0 || $power < -6) : ($power < .25 || $power > 4))) $this->fail("Invalid power heading in worksheet row $row.");
                    $key = sprintf('%.2f', $power);
                    if (in_array($power, $columns, true)) $this->fail("Duplicate power heading in row $row.");
                    $columns[$col] = $power;
                }
                $sectionTotals[$section] = 0;
                continue;
            }
            if (! $columns) continue;
            if (preg_match('/^sub\s*t|total|^sph|^s\.?c\.?$/i', $label)) continue;
            if ($label === '' && collect($columns)->keys()->every(fn ($col) => ($cells[$col] ?? '') === '' || preg_match('/total|final/i', $cells[$col] ?? ''))) continue;
            $sphere = $this->powerNumber($label);
            if ($sphere === null) $this->fail("Invalid sphere in row $row. Check the selected worksheet.");
            if ($sign < 0 && ! str_starts_with(ltrim($label), '+')) $sphere = -abs($sphere);
            if ($sphere < -15 || $sphere > 15 || abs($sphere * 4 - round($sphere * 4)) > .00001) $this->fail("Invalid sphere in row $row.");
            $r = (int) round(($sphere + 15) * 4);
            if (isset($seen[$r])) $this->fail("Repeated sphere within the same section at row $row.");
            $seen[$r] = true;
            foreach ($columns as $col => $power) {
                $value = $cells[$col] ?? '';
                if ($value === '') continue;
                $quantity = $this->number($value);
                if ($quantity === null || $quantity < 0 || $quantity > 100000 || floor($quantity) !== $quantity) $this->fail("Invalid quantity at row $row, column $col. Use whole quantities; paste formulas as values in quantity cells.");
                if ($quantity == 0) continue;
                $c = $design === 'Single Vision' ? (int) round(-$power * 4) : (int) round(($power - .25) * 4);
                $pieces = (int) $quantity * ($unit === 'pairs' ? 2 : 1);
                if (isset($quantities[$r][$c])) {
                    if ($r !== 60) $this->fail("Duplicate non-zero sphere/power across sections at row $row.");
                    $warnings['plano'] = 'Plus-zero and minus-zero quantities are combined into one 0.00 stock balance.';
                }
                $quantities[$r][$c] = ($quantities[$r][$c] ?? 0) + $pieces;
                if ($quantities[$r][$c] > 100000) $this->fail('A power exceeds the maximum receipt quantity of 100000 pieces.');
                $rawTotal += (int) $quantity; $sectionTotals[$section] += (int) $quantity;
                $lines[] = ['sphere' => sprintf('%+.2f', $sphere ?: 0), 'power' => sprintf('%+.2f', $power), 'quantity' => $pieces, 'source_quantity' => (int) $quantity, 'row' => $row];
            }
        }
        if (! $lines) $this->fail('No quantities found. Use the ST PAT layout or switch to manual header selection.');
        foreach ($sheet['rows'] as $row => $cells) foreach ($cells as $col => $value) {
            $declared = null;
            if (preg_match('/total\s*=\s*(\d+)/i', $value, $m)) $declared = (int) $m[1];
            if ($col > 11 && preg_match('/^total$/i', $value)) $declared = $sheet['cached'][$row][$col + 1] ?? $cells[$col + 1] ?? null;
            if (is_numeric($declared) && (int) $declared !== $rawTotal) $warnings[] = "Worksheet summary at row $row shows $declared; quantity cells total $rawTotal. The import uses the quantity cells.";
            if (preg_match('/\bpairs\b/i', $value) && $unit === 'pieces') $warnings['units'] = 'The worksheet mentions pairs, but pieces was selected. Confirm the quantity unit before applying.';
        }
        return ['quantities' => $quantities, 'lines' => $lines, 'total' => $rawTotal * ($unit === 'pairs' ? 2 : 1), 'sourceTotal' => $rawTotal, 'unit' => $unit, 'sectionTotals' => array_values($sectionTotals), 'warnings' => array_values($warnings)];
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['excelFile' => $message]);
    }

    private function xml(ZipArchive $zip, string $path): SimpleXMLElement
    {
        $stat = $zip->statName($path);
        if (! $stat || $stat['size'] > 12000000) $this->fail('The workbook is missing a worksheet or is too large.');
        $text = $zip->getFromName($path);
        if (stripos($text, '<!DOCTYPE') !== false || stripos($text, '<!ENTITY') !== false) $this->fail('This workbook contains unsupported XML.');
        $xml = simplexml_load_string($text, SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        if ($xml === false) $this->fail('The Excel workbook could not be read. Save it as .xlsx and try again.');
        $xml->registerXPathNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        return $xml;
    }

    public function read(string $path): array
    {
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) $this->fail('Upload a valid .xlsx workbook.');
        try {
            $size = 0;
            for ($i = 0; $i < $zip->numFiles; $i++) $size += $zip->statIndex($i)['size'];
            if ($zip->numFiles > 2000 || $size > 40000000) $this->fail('The workbook is too large. Upload only the lens order sheets.');
            $strings = [];
            if ($zip->locateName('xl/sharedStrings.xml') !== false) {
                foreach ($this->xml($zip, 'xl/sharedStrings.xml')->xpath('//m:si') as $item) {
                    $item->registerXPathNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
                    $strings[] = implode('', array_map('strval', $item->xpath('.//m:t')));
                }
            }
            $relationships = [];
            foreach ($this->xml($zip, 'xl/_rels/workbook.xml.rels')->children('http://schemas.openxmlformats.org/package/2006/relationships') as $rel) {
                if ((string) $rel->attributes()['TargetMode'] !== 'External') $relationships[(string) $rel->attributes()['Id']] = (string) $rel->attributes()['Target'];
            }
            $result = [];
            foreach ($this->xml($zip, 'xl/workbook.xml')->xpath('//m:sheet') as $sheet) {
                $id = (string) $sheet->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];
                $target = $relationships[$id] ?? '';
                $target = str_starts_with($target, '/') ? ltrim($target, '/') : 'xl/'.$target;
                if (str_contains($target, '..') || ! str_starts_with($target, 'xl/worksheets/')) continue;
                $rows = [];
                $cached = [];
                foreach ($this->xml($zip, $target)->xpath('//m:sheetData/m:row/m:c') as $cell) {
                    if (! preg_match('/^([A-Z]+)([0-9]+)$/', (string) $cell['r'], $match)) continue;
                    $col = 0;
                    foreach (str_split($match[1]) as $letter) $col = $col * 26 + ord($letter) - 64;
                    if ((int) $match[2] > 2000 || $col > 100) $this->fail('Use a worksheet with at most 2,000 rows and 100 columns.');
                    $data = $cell->children('http://schemas.openxmlformats.org/spreadsheetml/2006/main');
                    $value = (string) $data->v;
                    if (isset($data->f)) { $cached[(int) $match[2]][$col] = $value; $value = '=FORMULA'; }
                    elseif ((string) $cell['t'] === 's') $value = $strings[(int) $value] ?? '';
                    elseif ((string) $cell['t'] === 'inlineStr') {
                        $cell->registerXPathNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
                        $value = implode('', array_map('strval', $cell->xpath('.//m:t')));
                    }
                    $rows[(int) $match[2]][$col] = trim($value);
                }
                $result[] = ['name' => (string) $sheet['name'], 'rows' => $rows, 'cached' => $cached];
            }
            if (! $result) $this->fail('No readable worksheets were found.');
            return $result;
        } finally {
            $zip->close();
        }
    }

    private function number($value): ?float
    {
        $value = str_replace(['−', ' '], ['-', ''], trim((string) $value));
        return is_numeric($value) ? (float) $value : null;
    }

    public function preview(array $rows, int $headerRow, int $sphereColumn, string $design): array
    {
        $columns = [];
        $seen = [];
        foreach ($rows[$headerRow] ?? [] as $col => $value) {
            if ($col <= $sphereColumn || $value === '' || preg_match('/total/i', $value)) continue;
            $power = $this->number($value);
            if ($power === null || abs($power * 4 - round($power * 4)) > 0.00001
                || ($design === 'Single Vision' ? ($power < -6 || $power > 0) : ($power < 0.25 || $power > 4))) {
                $this->fail("Invalid power heading at row $headerRow, column $col. Check the header row and lens design.");
            }
            $key = number_format($power, 2, '.', '');
            if (isset($seen[$key])) $this->fail('Duplicate power columns found. Import one grid at a time.');
            $seen[$key] = true;
            $columns[$col] = $power;
        }
        if (! $columns) $this->fail('No power headings found. Select the row containing CYL or ADD numbers.');
        $quantities = [];
        $lines = [];
        $seenRows = [];
        foreach ($rows as $row => $cells) {
            if ($row <= $headerRow) continue;
            $label = $cells[$sphereColumn] ?? '';
            if (preg_match('/total|^sph|^s\\.?c\\.?$/i', $label)) continue;
            $hasData = collect(array_keys($columns))->contains(fn ($col) => ($cells[$col] ?? '') !== '');
            if ($label === '' && ! $hasData) continue;
            $sphere = $this->number($label);
            if ($sphere === null || $sphere < -15 || $sphere > 15 || abs($sphere * 4 - round($sphere * 4)) > 0.00001) $this->fail("Invalid SPH at row $row. Use signed numeric powers and remove unrelated rows below the grid.");
            $r = (int) round(($sphere + 15) * 4);
            if (isset($seenRows[$r])) $this->fail("Duplicate sphere at row $row. Import one grid at a time.");
            $seenRows[$r] = true;
            foreach ($columns as $col => $power) {
                $value = $cells[$col] ?? '';
                if ($value === '') continue;
                $quantity = $this->number($value);
                if ($quantity === null || $quantity < 0 || $quantity > 100000 || floor($quantity) !== $quantity) $this->fail("Invalid quantity at row $row, column $col. Enter whole pieces from 0 to 100000; formulas must be pasted as values.");
                if ($quantity == 0) continue;
                $c = $design === 'Single Vision' ? (int) round(-$power * 4) : (int) round(($power - 0.25) * 4);
                $quantities[$r][$c] = (int) $quantity;
                $lines[] = ['sphere' => sprintf('%+.2f', $sphere), 'power' => sprintf('%+.2f', $power), 'quantity' => (int) $quantity, 'row' => $row];
            }
        }
        if (! $lines) $this->fail('The selected grid contains no positive quantities.');
        return ['quantities' => $quantities, 'lines' => $lines, 'total' => array_sum(array_column($lines, 'quantity'))];
    }

    private function extendTemplateSphereRows(\DOMDocument $doc): void
    {
        $ns = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
        $xpath = new \DOMXPath($doc);
        $xpath->registerNamespace('m', $ns);
        $data = $xpath->query('//m:sheetData')->item(0);
        $prototype = $xpath->query('//m:row[@r="6"]')->item(0)->cloneNode(true);
        $header = $xpath->query('//m:row[@r="4"]')->item(0)->cloneNode(true);
        foreach (iterator_to_array($data->childNodes) as $row) {
            if ($row instanceof \DOMElement && (int) $row->getAttribute('r') > 4) $data->removeChild($row);
        }
        $makeRow = function (int $number, string $label, ?string $formula = null) use ($doc, $ns, $prototype) {
            $row = $prototype->cloneNode(false);
            $row->setAttribute('r', (string) $number);
            foreach (range('A', 'K') as $offset => $column) {
                $cell = $doc->createElementNS($ns, 'c');
                $cell->setAttribute('r', $column.$number);
                $style = $prototype->childNodes->item($offset);
                if ($style instanceof \DOMElement && $style->hasAttribute('s')) $cell->setAttribute('s', $style->getAttribute('s'));
                if ($column === 'A') {
                    $cell->setAttribute('t', 'inlineStr');
                    $inline = $doc->createElementNS($ns, 'is');
                    $text = $doc->createElementNS($ns, 't');
                    $text->appendChild($doc->createTextNode($label));
                    $inline->appendChild($text); $cell->appendChild($inline);
                } elseif ($column === 'K') {
                    $cell->appendChild($doc->createElementNS($ns, 'f', $formula ?? 'SUM(B'.$number.':J'.$number.')'));
                }
                $row->appendChild($cell);
            }
            return $row;
        };
        // 57 non-negative powers, then 56 negative powers; plano is entered once.
        for ($step = 0; $step <= 56; $step++) $data->appendChild($makeRow(5 + $step, sprintf('%+.2f', $step / 4)));
        $data->appendChild($makeRow(62, 'Sub Total (+)', 'SUM(B5:J61)'));
        $header->setAttribute('r', '64');
        foreach ($header->childNodes as $cell) {
            if (! $cell instanceof \DOMElement) continue;
            $ref = preg_replace('/\d+$/', '64', $cell->getAttribute('r'));
            $cell->setAttribute('r', $ref);
            if ($ref === 'A64') {
                while ($cell->firstChild) $cell->removeChild($cell->firstChild);
                $cell->setAttribute('t', 'inlineStr');
                $inline = $doc->createElementNS($ns, 'is');
                $inline->appendChild($doc->createElementNS($ns, 't', '(-)'));
                $cell->appendChild($inline);
            }
        }
        $data->appendChild($header);
        for ($step = 1; $step <= 56; $step++) $data->appendChild($makeRow(64 + $step, sprintf('%+.2f', -$step / 4)));
        $data->appendChild($makeRow(121, 'Sub Total (-)', 'SUM(B65:J120)'));
        $data->appendChild($makeRow(123, 'Total', 'SUM(K62,K121)'));
        $xpath->query('//m:dimension')->item(0)?->setAttribute('ref', 'A1:K123');
        foreach (['mergeCells', 'conditionalFormatting', 'dataValidations', 'rowBreaks'] as $tag) {
            foreach (iterator_to_array($xpath->query('//m:'.$tag)) as $node) $node->parentNode->removeChild($node);
        }
    }

    public function generateTemplate(string $design = 'Single Vision'): string
    {
        $source = resource_path('templates/st-pat-lens-order.xlsx');
        $sheets = $this->read($source);
        $path = tempnam(sys_get_temp_dir(), 'lens_tpl_');
        copy($source, $path);
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) $this->fail('The lens template could not be opened.');
        try {
            foreach ($sheets as $index => $sheet) {
                $name = 'xl/worksheets/sheet'.($index + 1).'.xml';
                $doc = new \DOMDocument();
                $doc->loadXML($zip->getFromName($name), LIBXML_NONET);
                $xpath = new \DOMXPath($doc);
                $ns = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
                $xpath->registerNamespace('m', $ns);
                foreach ($xpath->query('//m:sheetData/m:row/m:c') as $cell) {
                    preg_match('/^([A-Z]+)([0-9]+)$/', $cell->getAttribute('r'), $parts);
                    $row = (int) $parts[2];
                    $quantityCell = in_array($parts[1], range('B','J'), true) && $this->powerNumber($sheet['rows'][$row][1] ?? '') !== null;
                    if ($quantityCell) {
                        while ($cell->firstChild) $cell->removeChild($cell->firstChild);
                        $cell->removeAttribute('t');
                    } elseif ($xpath->query('m:f', $cell)->length) {
                        foreach (iterator_to_array($xpath->query('m:v', $cell)) as $value) $cell->removeChild($value);
                    }
                    if ($cell->getAttribute('r') === 'A2') {
                        while ($cell->firstChild) $cell->removeChild($cell->firstChild);
                        $cell->setAttribute('t', 'inlineStr');
                        $inline = $doc->createElementNS($ns, 'is');
                        $text = $doc->createElementNS($ns, 't');
                        $text->appendChild($doc->createTextNode(trim($sheet['name']).' - quantities in individual pieces'));
                        $inline->appendChild($text); $cell->appendChild($inline);
                    }
                }
                if ($design !== 'Single Vision') {
                    foreach ([4, 32] as $header) {
                        foreach (range('B', 'J') as $offset => $column) {
                            $cell = $xpath->query('//m:c[@r="'.$column.$header.'"]')->item(0);
                            if (! $cell) continue;
                            while ($cell->firstChild) $cell->removeChild($cell->firstChild);
                            $cell->removeAttribute('t');
                            $value = $doc->createElementNS($ns, 'v', number_format(1 + $offset * .25, 2, '.', ''));
                            $cell->appendChild($value);
                        }
                    }
                    $label = $xpath->query('//m:c[@r="C3"]')->item(0);
                    if ($label) {
                        while ($label->firstChild) $label->removeChild($label->firstChild);
                        $label->setAttribute('t', 'inlineStr');
                        $inline = $doc->createElementNS($ns, 'is');
                        $inline->appendChild($doc->createElementNS($ns, 't', 'ADD (+)'));
                        $label->appendChild($inline);
                    }
                }
                // Correct the original BLUE BLOCK subtotal, which counted its last column twice.
                if (str_contains(strtoupper($sheet['name']), 'BLUE BLOCK')) {
                    foreach (['K30' => 'SUM(B5:J29)', 'K58' => 'SUM(B33:J57)', 'S33' => 'SUM(K30,K58)'] as $ref => $formula) {
                        $cell = $xpath->query('//m:c[@r="'.$ref.'"]')->item(0);
                        if ($cell) {
                            while ($cell->firstChild) $cell->removeChild($cell->firstChild);
                            $cell->removeAttribute('t');
                            $f = $doc->createElementNS($ns, 'f'); $f->appendChild($doc->createTextNode($formula)); $cell->appendChild($f);
                        }
                    }
                }
                $this->extendTemplateSphereRows($doc);
                $zip->addFromString($name, $doc->saveXML());
            }
            $workbook = $zip->getFromName('xl/workbook.xml');
            if ($design !== 'Single Vision') $workbook = str_replace('name="BLUE BLOCK"', 'name="'.strtoupper($design).' STOCK"', $workbook);
            $workbook = preg_replace('/<calcPr[^>]*\/>/', '<calcPr calcMode="auto" fullCalcOnLoad="1" forceFullCalc="1"/>', $workbook);
            $zip->addFromString('xl/workbook.xml', $workbook);
            // Excel rebuilds dependencies for the extended formula ranges on opening.
            $zip->deleteName('xl/calcChain.xml');
            foreach (['xl/_rels/workbook.xml.rels', '[Content_Types].xml'] as $part) {
                $xml = $zip->getFromName($part);
                $xml = preg_replace('/<(?:Relationship|Override)\b[^>]*(?:calcChain)[^>]*\/>/', '', $xml);
                $zip->addFromString($part, $xml);
            }
            $zip->close();
            return file_get_contents($path);
        } finally {
            if ($zip->filename !== '') $zip->close();
            if (is_file($path)) unlink($path);
        }
    }
}
