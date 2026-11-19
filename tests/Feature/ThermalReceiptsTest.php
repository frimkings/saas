<?php

namespace Tests\Feature;

use App\Models\{CashierPatientClearance, Patient, Setting, User};
use Tests\TestCase;

/**
 * Thermal printers print black dots only: grey, colour and logos came out faded and dotted (19 Nov 2026).
 * Receipts are solid black and bold, with the clinic name as the header and no logo.
 */
class ThermalReceiptsTest extends TestCase
{
    private function clearanceReceipt(string $status): string
    {
        $settings = (new Setting)->forceFill(['clinic_name' => 'Icare Vision Centre', 'clinic_contact' => '0544514500', 'clinic_logo' => 'logos/logo.png']);
        $clearance = (new CashierPatientClearance)->forceFill(['id' => 2545, 'payment_status' => $status, 'clearance_date' => '2026-11-19', 'created_at' => now()]);
        $clearance->setRelation('patient', (new Patient)->forceFill(['name' => 'Kakra Amoah', 'pxnumber' => 'IVC-2611-003', 'contact' => '0240000000']))
            ->setRelation('service', null)->setRelation('sale', null)->setRelation('user', (new User)->forceFill(['name' => 'Nana Ama']));

        return view('cashier.clearance-receipt', ['clearance' => $clearance, 'clinicSettings' => $settings])->render();
    }

    public function test_the_clearance_receipt_has_no_logo_grey_or_colour(): void
    {
        $html = $this->clearanceReceipt('Unpaid');

        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringContainsString('Payment: UNPAID', $html);
        $this->assertStringNotContainsString('No specific service recorded', $html, 'the grey filler line is gone');
        preg_match_all('/(?<![\w-])color:\s*(#[0-9a-fA-F]{3,6}|rgba?\([^)]*\))/', $html, $colours);
        $this->assertSame([], array_values(array_diff(array_map('strtolower', $colours[1]), ['#000', '#000000'])), 'only black text');
    }

    public function test_every_thermal_receipt_leaves_the_logo_off(): void
    {
        foreach (['thermal-receipt', 'visit-receipt', 'clearance-receipt', 'refund-receipt'] as $view) {
            $source = file_get_contents(resource_path("views/cashier/$view.blade.php"));
            $this->assertStringNotContainsString('logoDataUri', $source, $view);
            $this->assertStringContainsString("@include('cashier.partials.thermal-styles')", $source, "$view uses the shared black styles");
        }
    }
}
