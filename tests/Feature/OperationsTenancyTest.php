<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Clinic;
use App\Models\Setting;
use App\Models\SmsLog;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\GivesClinicsAccess;
use Tests\TestCase;

class OperationsTenancyTest extends TestCase
{
    use GivesClinicsAccess, RefreshDatabase;

    public function test_operational_tables_have_tenant_context(): void
    {
        foreach (['settings', 'sms_logs', 'report_deliveries', 'app_notifications', 'staff_messages', 'audit_trails', 'login_logs', 'system_health_statuses', 'login_logs_archive', 'audit_trails_archive', 'sms_logs_archive'] as $table) {
            $this->assertTrue(Schema::hasColumns($table, ['clinic_id', 'branch_id']));
            $this->assertSame(0, DB::table($table)->whereNull('clinic_id')->count());
        }
    }

    public function test_settings_and_logs_are_isolated_by_clinic(): void
    {
        config()->set('tenancy.enabled', true);
        $user = User::factory()->create();
        [$clinicA, $branchA] = $this->tenant($user, 'A');
        [$clinicB, $branchB] = $this->tenant($user, 'B');
        $context = app(TenantContext::class);
        $context->set($user, $clinicA, $branchA, [$branchA->id]);
        $settingA = Setting::getSettings();
        SmsLog::create(['recipient' => '1', 'message' => 'A', 'success' => true]);
        $context->set($user, $clinicB, $branchB, [$branchB->id]);
        $settingB = Setting::getSettings();
        SmsLog::create(['recipient' => '2', 'message' => 'B', 'success' => true]);

        $this->assertNotSame($settingA->id, $settingB->id);
        $this->assertSame(['B'], SmsLog::pluck('message')->all());
    }

    public function test_public_booking_key_selects_branch_and_conversion_requires_authentication(): void
    {
        $user = User::factory()->create();
        [$clinic, $branch] = $this->tenant($user, 'Book');
        $branch->update(['public_booking_key' => 'test-booking-key']);
        $response = $this->postJson('/api/v1/appointments', [
            'booking_key' => 'test-booking-key', 'name' => 'Jane', 'phone' => '0240000000',
        ]);
        $response->assertCreated();
        $bookingId = $response->json('data.booking_id');
        $this->assertDatabaseHas('online_bookings', ['id' => $bookingId, 'clinic_id' => $clinic->id, 'branch_id' => $branch->id]);
        $this->postJson('/api/v1/online-bookings/'.$bookingId.'/convert')->assertUnauthorized();
    }

    public function test_guest_login_can_render_with_tenancy_enabled_without_creating_settings(): void
    {
        config()->set('tenancy.enabled', true);
        $before = DB::table('settings')->count();

        $this->get('/login')->assertOk();

        $this->assertSame($before, DB::table('settings')->count());
    }

    private function tenant(User $user, string $suffix): array
    {
        $clinic = $this->activeClinic(['name' => 'Clinic '.$suffix, 'slug' => 'clinic-'.strtolower($suffix)]);
        $branch = $clinic->branches()->create(['code' => 'MAIN', 'name' => 'Main', 'is_default' => true]);
        $clinic->users()->attach($user, ['status' => 'active']);
        $branch->users()->attach($user, ['status' => 'active', 'is_default' => true]);
        return [$clinic, $branch];
    }
}
