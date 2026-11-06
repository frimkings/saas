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

    public function test_a_supplier_sheet_with_minus_and_plus_grids_side_by_side_is_read_one_grid_at_a_time(): void
    {
        $service = new OpticalLensExcelImportService();
        // Shaped like a supplier order form: title in B1, diameters above each grid, unsigned CYL,
        // a legend far to the right, subtotal rows and notes under the plus grid.
        $sheet = ['name' => '1.56 PHOTO GREY BLUE BLOCK SV H', 'rows' => [
            1 => [2 => '1.56 PHOTO GREY BLUE BLOCK SV HMC'],
            8 => [2 => 'SPH', 3 => '70MM        CYL(-)', 12 => 'SPH', 13 => '65MM        CYL(-)', 53 => '*'],
            9 => [2 => '(-)', 3 => '0.00', 4 => '0.25', 5 => '0.5', 12 => '(+)', 13 => '0.00', 14 => '0.25', 15 => '0.5', 53 => '1.499', 54 => 'White/Clear'],
            10 => [2 => '0.00', 3 => '200', 4 => '20', 12 => '0.00', 53 => '1.56'],
            11 => [2 => '0.25', 3 => '30', 5 => '4', 12 => '0.25', 13 => '40', 14 => '30', 15 => '35'],
            12 => [2 => '0.5', 3 => '10', 12 => 'S.T', 13 => '=FORMULA'],
            13 => [2 => '0.75', 13 => '注：以下是小计，不是订单数量'],
            14 => [2 => 'S.T', 3 => '=FORMULA', 13 => 'TOTAL:', 15 => '=FORMULA'],
        ], 'cached' => []];

        // The first grid's diameter is the starting point; choosing a grid sets its own.
        $this->assertSame(['design' => 'Single Vision', 'index' => '1.56', 'diameter' => '70'], $service->specifications($sheet));
        $this->assertTrue($service->needsGridChoice($sheet));
        $this->assertSame([['key' => '9:2', 'name' => 'Minus SPH (C9:E9), 70 mm', 'diameter' => '70'], ['key' => '9:12', 'name' => 'Plus SPH (M9:O9), 65 mm', 'diameter' => '65']], $service->templateGrids($sheet));
        try {
            $service->previewTemplate($sheet, 'Single Vision', 'pairs');
            $this->fail('Grids of different diameters must be imported one at a time.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('grids for different lens diameters', $e->errors()['excelFile'][0]);
        }

        // Minus grid: unsigned SPH under "(-)" and unsigned CYL are both minus.
        $minus = $service->previewTemplate($sheet, 'Single Vision', 'pairs', '9:2');
        $this->assertSame(264, $minus['sourceTotal']);
        $this->assertSame(['-0.25', '-0.50'], [$minus['lines'][2]['sphere'], $minus['lines'][3]['power']]);
        $this->assertContains('CYL headings written without a minus sign were read as minus CYL.', $minus['warnings']);
        // Plus grid: ends at its S.T row, so the note and TOTAL below it are not read.
        $plus = $service->previewTemplate($sheet, 'Single Vision', 'pairs', '9:12');
        $this->assertSame(105, $plus['sourceTotal']);
        $this->assertSame('+0.25', $plus['lines'][0]['sphere']);
    }

    public function test_a_supplier_multifocal_sheet_reads_its_plus_and_minus_grids_and_full_title(): void
    {
        $service = new OpticalLensExcelImportService();
        $heading = [2 => "SPH    \n       ADD", 3 => '+1.00', 4 => '+1.25', 5 => '+1.50'];
        $sheet = ['name' => 'F3 1.56 PHOTO GREY BLUE BLOCK F', 'rows' => [
            1 => [2 => 'F3 1.56 PHOTO GREY BLUE BLOCK FLAT TOP HMC UV 415'],
            7 => $heading, 8 => [2 => '0.00', 3 => '15', 4 => '20'], 9 => [2 => '+0.25', 5 => '5'],
            10 => [3 => '=FORMULA', 4 => '=FORMULA', 5 => '=FORMULA'],
            12 => $heading, 13 => [2 => '0.00'], 14 => [2 => '-0.25', 4 => '3'],
        ], 'cached' => []];
        // The sheet name is cut at 31 characters; the title in B1 says it is a flat-top bifocal.
        $this->assertSame(['design' => 'Bifocal', 'form' => 'Flat top', 'index' => '1.56'], $service->specifications($sheet));
        $this->assertFalse($service->needsGridChoice($sheet));
        $preview = $service->previewTemplate($sheet, 'Bifocal', 'pairs');
        $this->assertSame(43, $preview['sourceTotal']);
        $this->assertSame([40, 3], $preview['sectionTotals']);
        // "PROGRESSIVE 14mm" is the corridor, not the diameter.
        $this->assertArrayNotHasKey('diameter', $service->specifications(['name' => 'X', 'rows' => [1 => [2 => '1.56 PHOTO GREY PROGRESSIVE 14mm HMC']]]));
    }

    public function test_formatting_far_outside_the_grid_does_not_make_a_sheet_too_large(): void
    {
        $service = new OpticalLensExcelImportService();
        $path = tempnam(sys_get_temp_dir(), 'wide_');
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::OVERWRITE);
        $zip->addFromString('xl/workbook.xml', '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Wide" sheetId="1" r:id="rId1"/></sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="worksheet" Target="worksheets/sheet1.xml"/></Relationships>');
        // HT1 has only a style (borders): Excel keeps such cells far to the right.
        $cells = fn (string $extra) => '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData><row r="1"><c r="A1" t="inlineStr"><is><t>SPH</t></is></c><c r="B1"><v>0</v></c>'.$extra.'</row></sheetData></worksheet>';
        $zip->addFromString('xl/worksheets/sheet1.xml', $cells('<c r="HT1" s="3"/>'));
        $zip->close();
        try {
            $this->assertSame('SPH', $service->read($path)[0]['rows'][1][1]);
            // A value out there still counts, and the message says where.
            $zip->open($path);
            $zip->addFromString('xl/worksheets/sheet1.xml', $cells('<c r="HT1"><v>5</v></c>'));
            $zip->close();
            try {
                $service->read($path);
                $this->fail('A value beyond column 100 should be refused.');
            } catch (ValidationException $e) {
                $this->assertStringContainsString('Cell HT1 in "Wide" holds something', $e->errors()['excelFile'][0]);
            }
        } finally { unlink($path); }
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
        // A bifocal pair is one right and one left lens: the cell keeps the pair count per eye.
        $this->assertTrue($result['perEye']);
        $this->assertSame(4, $result['quantities'][59][3]);
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

    public function test_template_lists_every_invalid_quantity_cell_by_excel_reference(): void
    {
        $sheet = ['name' => 'BLUE BLOCK', 'rows' => [
            4 => [1 => '(+)', 2 => '0', 3 => '-0.25', 11 => 'FINAL'],
            5 => [1 => '+0.00', 2 => '45', 3 => '1'],
            7 => [1 => '+0.50', 2 => '45', 3 => 's'],
            10 => [1 => '+1.25', 2 => '2.5', 3 => 's'],
        ]];

        try {
            (new OpticalLensExcelImportService())->previewTemplate($sheet, 'Single Vision', 'pieces');
            $this->fail('Invalid quantities should block the preview.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('Invalid quantity in 3 cells: C7 ("s"), B10 ("2.5"), C10 ("s")', $e->errors()['excelFile'][0]);
        }
    }

    public function test_quantities_typed_over_power_headings_name_the_cells_and_expected_powers(): void
    {
        $headings = [1 => '(+)', 2 => '0.00', 3 => '-0.25', 4 => '-0.50', 5 => '-0.75', 6 => '-1.00'];
        $sheet = ['name' => 'BLUE BLOCK', 'rows' => [
            4 => $headings + [7 => '5', 8 => '6', 9 => '79', 11 => 'FINAL'],
            5 => [1 => '+0.00', 2 => '7', 10 => '6'],
        ]];
        try {
            (new OpticalLensExcelImportService())->previewTemplate($sheet, 'Single Vision', 'pairs');
            $this->fail('Overwritten headings should block the preview.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('G4 shows "5", expected -1.25; H4 shows "6", expected -1.50; I4 shows "79", expected -1.75', $e->errors()['excelFile'][0]);
        }

        // Once the headings are restored, a quantity under an empty heading is reported, not skipped.
        $sheet['rows'][4] = $headings + [7 => '-1.25', 8 => '-1.50', 9 => '-1.75', 11 => 'FINAL'];
        try {
            (new OpticalLensExcelImportService())->previewTemplate($sheet, 'Single Vision', 'pairs');
            $this->fail('Quantities under an empty heading should block the preview.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('J4 is empty (expected -2.00) but has quantities in J5', $e->errors()['excelFile'][0]);
        }
    }

    public function test_downloaded_template_is_labelled_in_pairs_and_warns_if_read_as_pieces(): void
    {
        $service = new OpticalLensExcelImportService();
        $path = tempnam(sys_get_temp_dir(), 'template_test_');
        try {
            file_put_contents($path, $service->generateTemplate());
            $sheet = $service->read($path)[0];
        } finally { unlink($path); }
        $this->assertStringContainsString('quantities in pairs', $sheet['rows'][2][1]);

        $sheet['rows'][5][2] = '4';
        $pairs = $service->previewTemplate($sheet, 'Single Vision', 'pairs');
        $this->assertSame(8, $pairs['total']);
        $this->assertSame([], $pairs['warnings']);
        $this->assertStringContainsString('mentions pairs', implode(' ', $service->previewTemplate($sheet, 'Single Vision', 'pieces')['warnings']));
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

    public function test_a_half_pair_is_one_loose_lens_and_only_pairs_may_be_halved(): void
    {
        $service = new OpticalLensExcelImportService();
        $sheet = ['name' => 'BLUE BLOCK', 'rows' => [
            4 => [1 => '(-)', 2 => '0.00', 3 => '-0.25', 4 => '-0.50', 11 => 'FINAL'],
            5 => [1 => '-0.25', 2 => '3.5', 3 => '4'],
            6 => [1 => '-0.50', 4 => '0.5'],
        ]];

        // Single vision in pairs: 3.5 pairs is 7 lenses, 0.5 is 1.
        $result = $service->previewTemplate($sheet, 'Single Vision', 'pairs');
        $this->assertSame([7, 8], [$result['quantities'][59][0], $result['quantities'][59][1]]);
        $this->assertSame(1, $result['quantities'][58][2]);
        $this->assertSame([8, 16, 2, []], [$result['sourceTotal'], $result['total'], $result['halves'], $result['odd']]);
        $manual = $service->preview([1 => [1 => 'SPH', 2 => '0', 3 => '-0.25'], 2 => [1 => '-0.25', 2 => '3.5', 3 => '4']], 1, 1, 'Single Vision', 'pairs');
        $this->assertSame([7, 8, 7.5, 15], [$manual['quantities'][59][0], $manual['quantities'][59][1], $manual['sourceTotal'], $manual['total']]);

        // Half a lens means nothing, and neither does a third of a pair.
        foreach ([['pieces', $sheet], ['pairs', ['name' => 'X', 'rows' => [4 => $sheet['rows'][4], 5 => [1 => '-0.25', 2 => '0.3', 3 => '1']]]]] as [$unit, $bad]) {
            try {
                $service->previewTemplate($bad, 'Single Vision', $unit);
                $this->fail('A fraction that is not a half pair should block the preview.');
            } catch (ValidationException $e) {
                $this->assertStringContainsString($unit === 'pairs' ? 'B5 ("0.3"). Use whole or half pairs' : 'Invalid quantity in 2 cells: B5 ("3.5"), D6 ("0.5")', $e->errors()['excelFile'][0]);
            }
        }

        // A design made per eye keeps the whole pairs, and each half is a loose lens waiting for its eye.
        $bifocal = ['name' => 'BIFOCAL', 'rows' => [5 => [1 => '+', 2 => '1.00', 3 => '1.25'], 6 => [1 => '0', 2 => '3.5', 3 => '0.5']]];
        $result = $service->previewTemplate($bifocal, 'Bifocal', 'pairs');
        $this->assertSame([3, 0], [$result['quantities'][60][3], $result['quantities'][60][4]]);
        $this->assertSame(['60:3', '60:4'], array_keys($result['odd']));
        $this->assertSame(['+0.00', '+1.25'], [$result['odd']['60:4']['sphere'], $result['odd']['60:4']['power']]);
        $this->assertSame([4, 8], [$result['sourceTotal'], $result['total']]);
    }
}
