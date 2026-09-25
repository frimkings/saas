<?php

namespace Tests\Feature;

use App\Http\Middleware\EnforceSubscriptionWriteAccess;
use App\Livewire\Admin\LicenseComponent;
use App\Models\{Clinic, ClinicSubscription, Setting, SubscriptionPlan, User};
use App\Services\{ClinicAccessService, LicenseService};
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class DeploymentLicensingTest extends TestCase
{
    use DatabaseTransactions;

    private function clinic(string $mode): Clinic
    {
        app(TenantContext::class)->clear();
        config(['tenancy.enabled' => true]);
        $user = User::factory()->create();
        Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']);
        $user->assignRole('Super Admin');
        $clinic = Clinic::create(['name' => 'Registered Clinic', 'slug' => 'license-'.uniqid(), 'deployment_mode' => $mode]);
        $branch = $clinic->branches()->create(['code' => 'MAIN', 'name' => 'Main', 'is_default' => true, 'is_active' => true]);
        $clinic->users()->attach($user->id, ['status' => 'active']);
        $branch->users()->attach($user->id, ['status' => 'active', 'is_default' => true]);
        app(TenantContext::class)->set($user, $clinic, $branch, [$branch->id]);
        $this->actingAs($user);
        Setting::getSettings();
        LicenseService::clearCache();
        return $clinic;
    }

    private function key(string $expires, ?string $installation = null, array $features = ['appointments']): string
    {
        $pair = sodium_crypto_sign_keypair();
        config(['license.public_key' => base64_encode(sodium_crypto_sign_publickey($pair))]);
        $encode = fn ($s) => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
        $payload = $encode(json_encode(['tier' => 'pro', 'plan' => 'Offline Standard',
            'installation_id' => $installation ?? LicenseService::installationId(),
            'expires' => $expires, 'features' => $features]));
        return 'EYECLINIC-PRO-'.$payload.'.'.$encode(sodium_crypto_sign_detached($payload, sodium_crypto_sign_secretkey($pair)));
    }

    public function test_local_clinic_uses_signed_features_without_a_subscription(): void
    {
        $this->clinic('local');
        $key = $this->key(now()->addMonth()->toDateString());
        $this->assertTrue(LicenseService::activate($key)['ok']);
        $this->assertTrue(LicenseService::has('appointments'));
        $this->assertFalse(LicenseService::has('inventory'));
        $this->assertFalse(app(ClinicAccessService::class)->access()['read_only']);
        Livewire::test(LicenseComponent::class)->assertSee('Offline Standard')->assertSee('Activate or renew offline');
    }

    public function test_hosted_clinic_uses_subscription_and_rejects_keys(): void
    {
        $clinic = $this->clinic('hosted');
        $plan = SubscriptionPlan::create(['name' => 'Hosted Plan', 'code' => 'hosted-'.uniqid(), 'features' => ['inventory'], 'base_price' => 10, 'billing_interval' => 'monthly']);
        ClinicSubscription::create(['clinic_id' => $clinic->id, 'subscription_plan_id' => $plan->id,
            'status' => 'active', 'current_period_ends_at' => now()->addMonth()]);
        $this->assertTrue(LicenseService::has('inventory'));
        $this->assertFalse(LicenseService::has('appointments'));
        $this->assertFalse(LicenseService::activate('any-key')['ok']);
        Livewire::test(LicenseComponent::class)->assertSee('Hosted Plan')->assertDontSee('Developer-issued license key');
        $subscription = $clinic->subscriptions()->latest('id')->first();
        // Expired, and past the one grace day: legacy grace_ends_at is ignored.
        $subscription->update(['current_period_ends_at' => now()->subDays(2), 'grace_ends_at' => now()->addMonth()]);
        $this->assertTrue(app(ClinicAccessService::class)->access()['read_only']);
        $this->assertFalse($subscription->permitsBranches());
    }

    public function test_expiry_blocks_writes_after_one_grace_day_even_with_legacy_grace_configuration(): void
    {
        $this->clinic('local');
        config(['license.grace_days' => 7]);
        $key = $this->key(now()->toDateString());
        $this->assertTrue(LicenseService::activate($key)['ok']);
        $this->travel(1)->days();
        $this->assertFalse(app(ClinicAccessService::class)->access()['read_only'], 'The day after expiry is the grace day.');
        $this->travel(1)->days();
        $this->assertSame('restricted', app(ClinicAccessService::class)->access()['status']);
        $this->assertTrue(app(ClinicAccessService::class)->access()['read_only']);
        $middleware = app(EnforceSubscriptionWriteAccess::class);
        $this->assertSame(200, $middleware->handle(Request::create('/records', 'GET'), fn () => response('ok'))->status());
        try {
            $middleware->handle(Request::create('/records', 'POST'), fn () => response('ok'));
            $this->fail('Expired clinic writes must be blocked.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
        $request = Request::create('/livewire/update', 'POST', ['components' => [[
            'snapshot' => json_encode(['memo' => ['name' => 'admin.license-component']]),
            'calls' => [['method' => 'activate']],
        ]]]);
        $route = new Route('POST', 'livewire/update', fn () => null);
        $route->name('livewire.update');
        $request->setRouteResolver(fn () => $route);
        $this->assertSame(200, $middleware->handle($request, fn () => response('ok'))->status());
        $this->assertTrue(LicenseService::activate($this->key(now()->addMonth()->toDateString()))['ok']);
        $this->assertFalse(app(ClinicAccessService::class)->access()['read_only']);
        $this->travelBack();
    }

    public function test_wrong_installation_and_malformed_keys_are_rejected(): void
    {
        $this->clinic('local');
        $this->assertFalse(LicenseService::activate($this->key(now()->addMonth()->toDateString(), 'other-installation'))['ok']);
        $this->assertFalse(LicenseService::activate('EYECLINIC-PRO-invalid.bad')['ok']);
    }

    public function test_expired_clinic_cannot_insert_bulk_update_or_delete_domain_records(): void
    {
        $this->clinic('local');
        $setting = Setting::getSettings();
        $setting->license_key = 'expired-or-invalid';
        $setting->save();
        foreach ([
            fn () => \Illuminate\Support\Facades\DB::table('patients')->insert(['name' => 'Blocked']),
            fn () => \Illuminate\Support\Facades\DB::table('patients')->where('id', -1)->update(['name' => 'Blocked']),
            fn () => \Illuminate\Support\Facades\DB::table('patients')->where('id', -1)->delete(),
            fn () => \Illuminate\Support\Facades\DB::table('sales')->where('id', -1)->update(['total' => 0]),
            fn () => \Illuminate\Support\Facades\DB::table('stock_movements')->where('id', -1)->delete(),
        ] as $write) {
            try { $write(); $this->fail('Domain write should have been rejected.'); }
            catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) { $this->assertSame(403, $e->getStatusCode()); }
        }
        $this->assertIsInt(\Illuminate\Support\Facades\DB::table('patients')->count());
    }

    public function test_expired_clinic_can_search_and_paginate_but_cannot_submit_changes(): void
    {
        $this->clinic('local');
        $setting = Setting::getSettings(); $setting->license_key = 'invalid'; $setting->save();
        $request = Request::create('/livewire/update', 'POST', ['components' => [[
            'snapshot' => json_encode(['memo' => ['name' => 'secretary.patients-component']]),
            'updates' => ['pxSearch' => 'Example'], 'calls' => [['method' => 'gotoPage', 'params' => [2]]],
        ]]]);
        $route = new Route('POST', 'livewire/update', fn () => null); $route->name('livewire.update');
        $request->setRouteResolver(fn () => $route);
        $this->assertSame(200, app(EnforceSubscriptionWriteAccess::class)->handle($request, fn () => response('ok'))->status());
        $request->merge(['components' => [[
            'snapshot' => json_encode(['memo' => ['name' => 'secretary.patients-component']]),
            'updates' => ['state.name' => 'Changed'], 'calls' => [],
        ]]]);
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        app(EnforceSubscriptionWriteAccess::class)->handle($request, fn () => response('ok'));
    }

    public function test_public_booking_is_blocked_before_any_records_are_created(): void
    {
        $this->clinic('local');
        app(TenantContext::class)->branch()->update(['public_booking_key' => 'license-booking-test']);
        $setting = Setting::getSettings(); $setting->license_key = 'invalid'; $setting->save();
        $request = Request::create('/api/v1/appointments', 'POST', ['booking_key' => 'license-booking-test', 'name' => 'Example', 'phone' => '123456789']);
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        app(\App\Http\Controllers\Api\PublicBookingController::class)->store($request);
    }

    public function test_expired_queued_work_is_rejected_after_restoring_its_clinic(): void
    {
        $this->clinic('local');
        $snapshot = \App\Support\Tenancy\TenantContextSnapshot::capture();
        $setting = Setting::getSettings(); $setting->license_key = 'invalid'; $setting->save();
        $job = \Mockery::mock(\Illuminate\Contracts\Queue\Job::class);
        $job->shouldReceive('payload')->andReturn(['tenant_context' => $snapshot, 'displayName' => 'ClinicImport']);
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        event(new \Illuminate\Queue\Events\JobProcessing('sync', $job));
    }

    public function test_developer_command_issues_a_usable_key(): void
    {
        $this->clinic('local');
        $pair = sodium_crypto_sign_keypair();
        config(['license.public_key' => base64_encode(sodium_crypto_sign_publickey($pair))]);
        $path = tempnam(sys_get_temp_dir(), 'license-test-');
        try {
            file_put_contents($path, base64_encode(sodium_crypto_sign_secretkey($pair)));
            $this->assertSame(0, \Illuminate\Support\Facades\Artisan::call('license:issue', [
                'installation' => LicenseService::installationId(), 'expires' => now()->addYear()->toDateString(),
                '--signing-key' => $path, '--plan' => 'Standard', '--feature' => ['appointments'],
            ]));
            $this->assertTrue(LicenseService::activate(trim(\Illuminate\Support\Facades\Artisan::output()))['ok']);
            $this->assertFalse(LicenseService::has('inventory'));
        } finally {
            unlink($path);
        }
    }

    public function test_activation_history_and_expiry_notice_include_license_details(): void
    {
        $this->clinic('local');
        $key = $this->key(now()->addDays(14)->toDateString());
        Livewire::test(LicenseComponent::class)->set('licenseKey', $key)->call('activate')->assertSee('Activation history');
        $activation = \App\Models\AuditTrail::where('event', 'license.activated')->latest('id')->firstOrFail();
        $this->assertSame(['appointments'], $activation->new_values['features']);
        $this->assertSame(hash('sha256', $key), $activation->new_values['key_fingerprint']);
        $notice = app(ClinicAccessService::class)->notice();
        $this->assertSame(14, $notice['days']);
        $this->assertStringContainsString('23:59:59', $notice['cutoff']);
    }

    public function test_clock_rollback_restricts_writes(): void
    {
        $this->clinic('local');
        LicenseService::activate($this->key(now()->addMonth()->toDateString()));
        $setting = Setting::getSettings();
        $setting->license_last_seen = now()->addDay()->toDateString();
        $setting->save();
        $this->assertTrue(app(ClinicAccessService::class)->access()['read_only']);
    }

    public function test_developer_can_issue_selected_features_from_dashboard(): void
    {
        $clinic = $this->clinic('local');
        auth()->user()->forceFill(['is_platform_admin' => true])->save();
        $pair = sodium_crypto_sign_keypair();
        $path = tempnam(sys_get_temp_dir(), 'license-ui-');
        config(['license.public_key' => base64_encode(sodium_crypto_sign_publickey($pair)), 'license.signing_key_path' => $path]);
        try {
            file_put_contents($path, base64_encode(sodium_crypto_sign_secretkey($pair)));
            $component = Livewire::test(\App\Livewire\Platform\OfflineLicenseComponent::class)
                ->set('clinicId', $clinic->id)->set('installationId', LicenseService::installationId())
                ->set('expires', now()->addYear()->toDateString())
                ->set('product', '')->call('issue')->assertHasErrors('product')
                ->set('product', 'both')->set('features', ['appointments'])->call('issue')->assertHasNoErrors();
            $this->assertTrue(LicenseService::activate($component->get('generatedKey'))['ok']);
            $this->assertTrue(LicenseService::has('appointments'));
            $this->assertFalse(LicenseService::has('inventory'));
            $this->assertDatabaseHas('platform_audit_logs', ['action' => 'OFFLINE_LICENSE_ISSUED', 'clinic_id' => $clinic->id]);
            $issuance = \App\Models\PlatformAuditLog::where('clinic_id', $clinic->id)->where('action', 'OFFLINE_LICENSE_ISSUED')->latest('id')->first();
            $component->call('recordActivationReport', $issuance->id)->assertSee('not remotely verified');
            $this->assertDatabaseHas('platform_audit_logs', ['action' => 'OFFLINE_ACTIVATION_REPORTED', 'clinic_id' => $clinic->id]);
            $component->set('features', ['inventory'])->assertSet('generatedKey', '');
            $component->set('features', ['unknown_feature'])->call('issue')->assertHasErrors('features.0');
        } finally {
            unlink($path);
        }
    }

    public function test_optical_shop_license_locks_clinic_pages_on_a_local_install(): void
    {
        $this->clinic('local');
        $this->assertTrue(LicenseService::activate($this->key(now()->addMonth()->toDateString(), null, ['optical', 'sms_campaigns']))['ok']);

        $this->assertTrue(LicenseService::has('optical'));
        $this->assertFalse(LicenseService::has('clinical'));
        $this->assertTrue(LicenseService::has('sms_campaigns'));
        $this->assertFalse(LicenseService::has('appointments'));
        $this->assertTrue(\App\Support\OpticalMode::opticalOnly());
        Livewire::test(LicenseComponent::class)->assertSee('Optical shop')->assertSee('SMS reminders');
        $this->get(route('admin.diagnoses'))->assertRedirect(route('optical.dashboard'));
    }

    public function test_older_keys_without_a_product_keep_both_modules(): void
    {
        $this->clinic('local');
        $this->assertTrue(LicenseService::activate($this->key(now()->addMonth()->toDateString()))['ok']);

        $this->assertTrue(LicenseService::has('clinical'));
        $this->assertTrue(LicenseService::has('optical'));
        $this->assertFalse(\App\Support\OpticalMode::opticalOnly());
    }

    public function test_developer_issues_an_optical_shop_key_without_clinic_only_features(): void
    {
        $clinic = $this->clinic('local');
        auth()->user()->forceFill(['is_platform_admin' => true])->save();
        $pair = sodium_crypto_sign_keypair();
        $path = tempnam(sys_get_temp_dir(), 'license-optical-');
        config(['license.public_key' => base64_encode(sodium_crypto_sign_publickey($pair)), 'license.signing_key_path' => $path]);
        try {
            file_put_contents($path, base64_encode(sodium_crypto_sign_secretkey($pair)));
            $component = Livewire::test(\App\Livewire\Platform\OfflineLicenseComponent::class)
                ->set('clinicId', $clinic->id)->set('installationId', LicenseService::installationId())
                ->set('expires', now()->addYear()->toDateString())
                ->set('features', ['appointments', 'audit_trail'])->set('product', 'optical')
                ->assertSet('features', ['audit_trail'])->assertDontSee('Appointments')
                ->call('issue')->assertHasNoErrors();
            $this->assertTrue(LicenseService::activate($component->get('generatedKey'))['ok']);
            $this->assertSame(['optical', 'audit_trail'], LicenseService::offlineAccess()['features']);
            $this->assertFalse(LicenseService::has('clinical'));
            $this->assertDatabaseHas('platform_audit_logs', ['action' => 'OFFLINE_LICENSE_ISSUED', 'clinic_id' => $clinic->id]);
        } finally {
            unlink($path);
        }
    }

    public function test_clinic_administrator_cannot_open_developer_issuer(): void
    {
        $this->clinic('local');
        Livewire::test(\App\Livewire\Platform\OfflineLicenseComponent::class)->assertForbidden();
    }

    public function test_renewal_exception_does_not_allow_bundled_clinical_writes(): void
    {
        $this->clinic('local');
        $setting = Setting::getSettings();
        $setting->license_key = 'invalid';
        $setting->save();
        $request = Request::create('/livewire/update', 'POST', ['components' => [
            ['snapshot' => json_encode(['memo' => ['name' => 'admin.license-component']]), 'calls' => [['method' => 'activate']]],
            ['snapshot' => json_encode(['memo' => ['name' => 'secretary.patients-component']]), 'calls' => [['method' => 'save']]],
        ]]);
        $route = new Route('POST', 'livewire/update', fn () => null);
        $route->name('livewire.update');
        $request->setRouteResolver(fn () => $route);
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        app(EnforceSubscriptionWriteAccess::class)->handle($request, fn () => response('ok'));
    }
}
