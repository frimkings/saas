<?php

namespace Tests\Unit;

use App\Services\OpticalLensExcelImportService;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class OpticalLensExcelImportTest extends TestCase
{
    public function test_versioned_workbook_specs_preserve_missing_values_and_pairs(): void
    {
        $service = new OpticalLensExcelImportService();
        $sheet = ['name' => 'PHOTOCH 65', 'rows' => [
            2 => [2 => 'LENS-GRID-1'], 3 => [2 => 'Supplier Photochromic'],
            4 => [2 => 'Single Vision'], 5 => [2 => 'Photochromic'],
            6 => [2 => ''], 7 => [2 => '65'], 8 => [2 => 'pairs'],
            12 => [1 => 'SPH', 2 => '0', 3 => '-0.25', 4 => 'Total'],
            13 => [1 => '14', 2 => '3'], 125 => [1 => '-14', 3 => '2'],
            127 => [1 => 'Total input units', 2 => '=FORMULA'],
        ]];
        $spec = $service->specifications($sheet);
        $this->assertSame('', $spec['index']);
        $this->assertSame('65', $spec['diameter']);
        $this->assertSame('Supplier Photochromic', $spec['range']);
        $this->assertSame('pairs', $spec['unit']);
        $this->assertSame(10, $service->previewTemplate($sheet, $spec['design'], $spec['unit'])['total']);
    }

    public function test_st_pat_workbook_sections_and_actual_totals(): void
    {
        $service = new OpticalLensExcelImportService();
        $sheets = $service->read(resource_path('templates/st-pat-lens-order.xlsx'));
        $this->assertCount(1, $sheets);
        $blue = $service->previewTemplate($sheets[0], 'Single Vision', 'pieces');
        $this->assertSame(186, $blue['total']);
        $this->assertSame(10, $blue['quantities'][60][0]);
        $this->assertSame(10, $blue['quantities'][61][0]);
        $this->assertArrayNotHasKey(59, $blue['quantities']);
        $sheets[0]['rows'][33][2] = '10';
        $sheets[0]['rows'][34][2] = '5';
        $sheets[0]['rows'][33][18] = 'TOTAL';
        $sheets[0]['cached'][33][19] = '999';
        $blue = $service->previewTemplate($sheets[0], 'Single Vision', 'pieces');
        $this->assertSame(201, $blue['total']);
        $this->assertSame(20, $blue['quantities'][60][0]);
        $this->assertSame(5, $blue['quantities'][59][0]);
        $this->assertStringContainsString('999', implode(' ', $blue['warnings']));
    }

    public function test_template_negative_add_section_and_pair_conversion(): void
    {
        $sheet = ['name' => 'BIFOCAL PHOTOGRAY', 'rows' => [
            4 => [1 => 'SPH', 2 => 'ADD (+)'], 5 => [1 => '+', 2 => '1.00', 3 => '1.25'],
            6 => [1 => '0', 2 => '3', 3 => '2'], 7 => [1 => 'Sub T', 2 => '=FORMULA'],
            8 => [1 => 'SPH', 2 => 'ADD'], 9 => [1 => '-', 2 => '1.00', 3 => '1.25'],
            10 => [1 => '0.25', 2 => '4'],
        ]];
        $result = (new OpticalLensExcelImportService())->previewTemplate($sheet, 'Bifocal', 'pairs');
        $this->assertSame(18, $result['total']);
        $this->assertSame(8, $result['quantities'][59][3]);
    }

    public function test_download_template_preserves_source_layout_and_clears_order_quantities(): void
    {
        $service = new OpticalLensExcelImportService();
        $path = tempnam(sys_get_temp_dir(), 'template_test_');
        try {
            file_put_contents($path, $service->generateTemplate());
            $sheets = $service->read($path);
            $this->assertCount(1, $sheets);
            $this->assertSame('', $sheets[0]['rows'][6][2]);
            $this->assertSame('', $sheets[0]['rows'][34][2]);
            $this->assertSame('-0.25', $sheets[0]['rows'][4][3]);
            $this->assertSame('BLUE BLOCK', $sheets[0]['name']);
        } finally { unlink($path); }
    }

    public function test_extended_template_has_every_quarter_power_and_imports_endpoints(): void
    {
        $service = new OpticalLensExcelImportService();
        foreach (['Single Vision', 'Bifocal', 'Progressive'] as $design) {
            $path = tempnam(sys_get_temp_dir(), 'range_template_');
            try {
                file_put_contents($path, $service->generateTemplate($design));
                $sheet = $service->read($path)[0];
                $powers = [];
                foreach ($sheet['rows'] as $row => $cells) if (is_numeric($cells[1] ?? null)) $powers[] = (float) $cells[1];
                sort($powers);
                $this->assertSame(array_map('floatval', range(-14, 14, .25)), $powers);
                $sheet['rows'][61][2] = '3';
                $sheet['rows'][120][2] = '4';
                $preview = $service->previewTemplate($sheet, $design, 'pieces');
                $this->assertSame(7, $preview['total']);
                $column = $design === 'Single Vision' ? 0 : 3;
                $this->assertSame(3, $preview['quantities'][116][$column]);
                $this->assertSame(4, $preview['quantities'][4][$column]);
            } finally { unlink($path); }
        }
    }

    public function test_sphere_cylinder_grid_maps_signed_powers_and_skips_totals(): void
    {
        $result = (new OpticalLensExcelImportService())->preview([
            1 => [1 => 'SPH', 2 => 'CYL'],
            2 => [1 => '(+)', 2 => '0.00', 3 => '-0.25', 4 => 'Total'],
            3 => [1 => '0.00', 2 => '3', 3 => '', 4 => '3'],
            4 => [1 => '+0.25', 2 => '0', 3 => '5', 4 => '5'],
            5 => [1 => 'Total', 2 => '=FORMULA', 3 => '=FORMULA'],
        ], 2, 1, 'Single Vision');
        $this->assertSame(8, $result['total']);
        $this->assertSame(3, $result['quantities'][60][0]);
        $this->assertSame(5, $result['quantities'][61][1]);
    }

    public function test_bifocal_template_uses_add_headings(): void
    {
        $service = new OpticalLensExcelImportService();
        $path = tempnam(sys_get_temp_dir(), 'add_template_');
        try {
            file_put_contents($path, $service->generateTemplate('Bifocal'));
            $sheet = $service->read($path)[0];
            $this->assertSame('BIFOCAL STOCK', $sheet['name']);
            $this->assertSame('1.00', $sheet['rows'][4][2]);
            $this->assertSame('3.00', $sheet['rows'][64][10]);
        } finally { unlink($path); }
    }

    public function test_add_grid_and_offset_sphere_column(): void
    {
        $result = (new OpticalLensExcelImportService())->preview([
            4 => [2 => '+', 3 => '1.00', 4 => '2.00'],
            5 => [2 => '-1.25', 3 => '2', 4 => '4'],
        ], 4, 2, 'Progressive');
        $this->assertSame(6, $result['total']);
        $this->assertSame(4, $result['quantities'][55][7]);
    }

    public function test_invalid_quantity_blocks_entire_preview(): void
    {
        $this->expectException(ValidationException::class);
        (new OpticalLensExcelImportService())->preview([1 => [1 => 'SPH', 2 => '0'], 2 => [1 => '0', 2 => '-1']], 1, 1, 'Single Vision');
    }

    public function test_duplicate_power_columns_are_rejected(): void
    {
        $this->expectException(ValidationException::class);
        (new OpticalLensExcelImportService())->preview([1 => [1 => 'SPH', 2 => '0', 3 => '0.00'], 2 => [1 => '0', 2 => '1']], 1, 1, 'Single Vision');
    }

    public function test_generated_template_is_valid_openxml_excel_workbook(): void
    {
        $service = new OpticalLensExcelImportService();
        $binary = $service->generateTemplate('Single Vision');
        $path = tempnam(sys_get_temp_dir(), 'test_tpl_');
        file_put_contents($path, $binary);
        try {
            $sheets = $service->read($path);
            $this->assertCount(1, $sheets);
            $this->assertSame('BLUE BLOCK', $sheets[0]['name']);
            $this->assertArrayHasKey(4, $sheets[0]['rows']);
            $this->assertSame('(+)', $sheets[0]['rows'][4][1]);
        } finally {
            if (file_exists($path)) unlink($path);
        }
    }

    public function test_user_uploaded_grid_from_image_is_parsed_correctly(): void
    {
        $rows = [
            2 => [1 => 'SPH / CYL', 2 => '0.00', 3 => '-0.25', 4 => '-0.50', 5 => '-0.75', 6 => '-1.00', 7 => '-1.25'],
            3 => [1 => '-6.00', 3 => '3', 4 => '5'],
            4 => [1 => '-5.75', 3 => '3', 4 => '5'],
            27 => [1 => '+0.00', 4 => '5'],
            28 => [1 => '+0.25', 4 => '5'],
            51 => [1 => '+6.00', 4 => '5'],
        ];
        $result = (new OpticalLensExcelImportService())->preview($rows, 2, 1, 'Single Vision');
        $this->assertGreaterThan(0, $result['total']);
        $this->assertSame(3, $result['quantities'][36][1]); // SPH -6.00, CYL -0.25
        $this->assertSame(5, $result['quantities'][36][2]); // SPH -6.00, CYL -0.50
        $this->assertSame(5, $result['quantities'][60][2]); // SPH +0.00, CYL -0.50
        $this->assertSame(5, $result['quantities'][61][2]); // SPH +0.25, CYL -0.50
        $this->assertSame(5, $result['quantities'][84][2]); // SPH +6.00, CYL -0.50
    }
}
