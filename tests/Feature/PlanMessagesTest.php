<?php

namespace Tests\Feature;

use App\Models\{Setting, SmsLog};
use App\Services\{OwnerSummaryService, SmsService, WhatsAppService};
use App\Support\Feature;
use Illuminate\Support\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Messages the system sends by itself follow the plan (18 Nov 2026): owner emails (daily on Standard, weekly,
 * monthly and morning alerts on Plus) and patient SMS / WhatsApp (Standard and up). A clinic that moved down to
 * Starter keeps its switches on, so the sending itself must stop, not only the settings pages.
 */
class PlanMessagesTest extends TestCase
{
    use RefreshDatabase, \Tests\Concerns\ClinicOnPlan;

    public static function plans(): array
    {
        return [
            'clinic-starter' => ['clinic-starter', [], false, false],
            'clinic-standard' => ['clinic-standard', ['daily'], false, true],
            'clinic-plus' => ['clinic-plus', ['daily', 'weekly', 'monthly'], true, true],
            'clinic-pro' => ['clinic-pro', ['daily', 'weekly', 'monthly'], true, true],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('plans')]
    public function test_owner_emails_follow_the_plan(string $family, array $summaries, bool $morningAlerts, bool $patientSms): void
    {
        Mail::fake();
        $this->ownerOn($family);
        $this->clinic->update(['billing_email' => 'owner@bright.test', 'default_timezone' => 'Africa/Accra']);
        $service = app(OwnerSummaryService::class);

        // Monday 1 June 2026, after ten: the day all three are due.
        $sent = $service->sendDue($this->clinic->fresh(), Carbon::parse('2026-06-01 10:30:00', 'Africa/Accra'));
        $this->assertSame($summaries, array_keys($sent), "$family owner summaries");
        $this->assertSame($morningAlerts, $service->includes($this->clinic, Feature::MORNING_ALERTS), "$family morning alerts");
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('plans')]
    public function test_patient_sms_and_whatsapp_follow_the_plan_even_with_the_switches_on(string $family, array $summaries, bool $morningAlerts, bool $patientSms): void
    {
        $this->ownerOn($family);
        // Switched on while the clinic was on a bigger plan.
        Setting::getSettings()->forceFill(['sms_enabled' => true, 'whatsapp_enabled' => true])->save();

        $sms = app(SmsService::class)->send('0241119999', 'Your glasses are ready.', null, 'spectacles_ready');
        $whatsApp = app(WhatsAppService::class)->sendText('0241119999', 'Your glasses are ready.');

        $planRefusal = 'not included in your plan';
        $this->assertSame(!$patientSms, str_contains((string) ($sms['error'] ?? ''), $planRefusal), "$family SMS: ".json_encode($sms));
        $this->assertSame(!$patientSms, str_contains((string) ($whatsApp['error'] ?? ''), $planRefusal), "$family WhatsApp: ".json_encode($whatsApp));
        if (!$patientSms) {
            $this->assertSame('skipped', SmsLog::where('channel', 'sms')->latest('id')->value('status'), 'logged so staff can see why');
        }
    }
}
