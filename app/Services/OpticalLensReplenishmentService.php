<?php
namespace App\Services;

use App\Models\OpticalProduct;
use Illuminate\Validation\ValidationException;
use ZipArchive;

class OpticalLensReplenishmentService
{
    public function rows(array $specs)
    {
        return OpticalProduct::with('stocks')->where('is_active', true)->whereNotNull('lens_specs')->whereHas('stocks')->get()
            ->filter(function ($product) use ($specs) {
                foreach ($specs as $key => $value) if ((string) data_get($product->lens_specs, $key) !== (string) $value) return false;
                return true;
            })->map(function ($product) {
                $stock = $product->stocks->first();
                $threshold = (int) ($stock->lens_reorder_pairs ?? 5);
                $target = (int) ($stock->lens_target_pairs ?? max(10, $threshold + 1));
                $quantity = (int) $stock->quantity;
                return ['id' => $product->id, 'sphere' => $product->lens_specs['sphere'], 'power' => $product->lens_specs['power'],
                    'quantity' => $quantity, 'reorder' => $threshold, 'target' => $target,
                    'low' => $quantity <= $threshold * 2,
                    'pairs' => $quantity <= $threshold * 2 ? max(0, (int) ceil(($target * 2 - $quantity) / 2)) : 0];
            })->sortBy(fn ($r) => [(float) $r['sphere'], (float) $r['power']])->values();
    }

    public function workbook(array $specs): string
    {
        $rows = $this->rows($specs)->where('pairs', '>', 0);
        if ($rows->isEmpty()) throw ValidationException::withMessages(['replenishment' => 'No tracked powers need replenishment for these specifications.']);
        $escape = fn ($value) => htmlspecialchars((string) $value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $cell = fn ($ref, $value) => '<c r="'.$ref.'" t="inlineStr"><is><t>'.$escape($value).'</t></is></c>';
        $data = '';
        $meta = [1 => ['Replenishment order', 'Review quantities before ordering; this download does not change stock.'],
            2 => ['Template version', 'LENS-GRID-1'], 3 => ['Lens range', $specs['range']], 4 => ['Design', $specs['design']],
            5 => ['Treatment', $specs['coating']], 6 => ['Index', $specs['index']], 7 => ['Diameter (mm)', $specs['diameter']],
            8 => ['Quantity unit', 'pairs'], 9 => ['Generated', now()->format('Y-m-d H:i').' UTC'],
            10 => ['Order total (pairs)', $rows->sum('pairs')]];
        foreach ($meta as $r => [$a, $b]) $data .= '<row r="'.$r.'">'.$cell('A'.$r, $a).$cell('B'.$r, $b).'</row>';
        $powers = collect($specs['design'] === 'Single Vision' ? range(0, -2, -.25) : range(1, 3, .25))
            ->merge($rows->pluck('power')->map(fn ($p) => (float) $p))->unique()->sort()->values();
        if ($specs['design'] === 'Single Vision') $powers = $powers->reverse()->values();
        $column = function ($n) { $s = ''; do { $s = chr(65 + $n % 26).$s; $n = intdiv($n, 26) - 1; } while ($n >= 0); return $s; };
        $data .= '<row r="12">'.$cell('A12', 'SPH');
        foreach ($powers as $c => $power) $data .= $cell($column($c + 1).'12', sprintf('%+.2f', $power));
        $data .= '</row>';
        $spheres = collect(range(-14, 14, .25))->merge($rows->pluck('sphere')->map(fn ($p) => (float) $p))->unique()->sortDesc()->values();
        foreach ($spheres as $offset => $sphere) {
            $r = 13 + $offset; $data .= '<row r="'.$r.'">'.$cell('A'.$r, sprintf('%+.2f', $sphere));
            foreach ($powers as $c => $power) {
                $pairs = $rows->filter(fn ($line) => (float) $line['sphere'] === (float) $sphere && (float) $line['power'] === (float) $power)->sum('pairs');
                if ($pairs) $data .= '<c r="'.$column($c + 1).$r.'"><v>'.$pairs.'</v></c>';
            }
            $data .= '</row>';
        }
        $data = str_replace($cell('B10', $rows->sum('pairs')), '<c r="B10"><f>SUM(B13:'.$column($powers->count()).(12 + $spheres->count()).')</f><v>'.$rows->sum('pairs').'</v></c>', $data);
        $ns = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
        $path = tempnam(sys_get_temp_dir(), 'lens_order_'); $zip = new ZipArchive();
        try {
            if ($zip->open($path, ZipArchive::OVERWRITE) !== true) throw new \RuntimeException('Cannot create order workbook.');
            $zip->addFromString('[Content_Types].xml', '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>');
            $zip->addFromString('_rels/.rels', '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
            $zip->addFromString('xl/workbook.xml', '<workbook xmlns="'.$ns.'" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="LENS REPLENISHMENT ORDER" sheetId="1" r:id="rId1"/></sheets></workbook>');
            $zip->addFromString('xl/_rels/workbook.xml.rels', '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>');
            $zip->addFromString('xl/worksheets/sheet1.xml', '<worksheet xmlns="'.$ns.'"><sheetViews><sheetView workbookViewId="0"><pane ySplit="12" topLeftCell="A13" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews><cols><col min="1" max="1" width="28" customWidth="1"/><col min="2" max="2" width="40" customWidth="1"/><col min="3" max="26" width="12" customWidth="1"/></cols><sheetData>'.$data.'</sheetData></worksheet>');
            $zip->close(); return file_get_contents($path);
        } finally { if ($zip->filename !== '') $zip->close(); if (is_file($path)) unlink($path); }
    }
}
