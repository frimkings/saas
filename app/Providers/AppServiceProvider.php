<?php

namespace App\Providers;

use App\Services\LicenseService;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantContextSnapshot;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    private array $baseMailConfig = [];
    /**
     * Register any application services.
     */
    public function register()
    {
        $this->app->bind(\App\Services\Messaging\SmsDriver::class, \App\Services\Messaging\Drivers\EazismsDriver::class);
        $this->app->scoped(
            \App\Support\Tenancy\TenantContext::class,
            fn () => new \App\Support\Tenancy\TenantContext()
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot()
    {
        $attachLicenseGuard = function ($connection): void {
            $connection->beforeExecuting(function ($sql): void {
                app(\App\Support\Licensing\ClinicWriteGuard::class)->check($sql);
            });
        };
        \Illuminate\Support\Facades\Event::listen(\Illuminate\Database\Events\ConnectionEstablished::class,
            fn ($event) => $attachLicenseGuard($event->connection));
        foreach (\Illuminate\Support\Facades\DB::getConnections() as $connection) $attachLicenseGuard($connection);
        $this->baseMailConfig = [
            'mail.default' => config('mail.default'),
            'mail.mailers.smtp' => config('mail.mailers.smtp'),
            'mail.from' => config('mail.from'),
        ];
        // Every queued job carries the authenticated clinic/branch in its payload.
        // Workers re-authorize it before processing and always clear it afterward.
        Queue::createPayloadUsing(function (): array {
            $snapshot = TenantContextSnapshot::capture();

            return $snapshot ? ['tenant_context' => $snapshot] : [];
        });

        Queue::before(function (JobProcessing $event): void {
            try { \Illuminate\Support\Facades\Cache::forever('platform.queue_worker_last_seen', now()->toIso8601String()); } catch (\Throwable) {}
            $snapshot = $event->job->payload()['tenant_context'] ?? null;
            app(TenantContext::class)->clear();

            if (is_array($snapshot)) {
                TenantContextSnapshot::restore($snapshot);
                if (($event->job->payload()['displayName'] ?? '') !== \App\Mail\SubscriptionBillingMail::class) {
                    app(\App\Services\ClinicAccessService::class)->assertWritable();
                }
            }
            $this->configureTenantMail();
        });

        $clearTenant = function (): void {
            app(TenantContext::class)->clear();
            $this->restoreBaseMailConfig();
        };
        Queue::after(fn (JobProcessed $event) => $clearTenant());
        Queue::exceptionOccurred(fn (JobExceptionOccurred $event) => $clearTenant());

        // @feature('feature_name') ... @else ... @endfeature
        Blade::if('feature', fn(string $f) => LicenseService::has($f));

        // Global Date Directive
        Blade::directive('formatDate', function ($expression) {
            return "<?php echo {$expression} 
                ? htmlspecialchars_decode(\\Carbon\\Carbon::parse({$expression})->format('jS F, Y')) 
                : ''; 
            ?>";
        });

        // Share settings safely
        if (!app()->runningInConsole() && Schema::hasTable('settings')) {
            $settings = \App\Models\Setting::first() ?? new \App\Models\Setting([
                'clinic_name' => 'Eye Clinic System',
                'clinic_address' => '',
                'clinic_logo' => null
            ]);

            view()->share('appSettings', $settings);
        }

        // Web requests may already have a resolved tenant; queue workers call
        // this again after restoring each job's captured clinic context.
        $this->configureTenantMail();

        // Super Admin Gate
        Gate::before(function ($user, $ability) {
            return $user->hasRole('Super Admin') ? true : null;
        });
    }

    private function configureTenantMail(): void
    {
        try {
            if (! Schema::hasTable('settings')) return;
            $s = \App\Models\Setting::first();
            if (! $s || ! $s->smtp_host || ! $s->smtp_username) {
                $this->restoreBaseMailConfig();
                return;
            }

            $password = '';
            if ($s->smtp_password) {
                try {
                    $password = \Illuminate\Support\Facades\Crypt::decrypt($s->smtp_password);
                } catch (\Throwable) {}
            }
            config([
                'mail.default'                 => 'smtp',
                'mail.mailers.smtp.host'       => $s->smtp_host,
                'mail.mailers.smtp.port'       => (int) ($s->smtp_port ?? 587),
                'mail.mailers.smtp.username'   => $s->smtp_username,
                'mail.mailers.smtp.password'   => $password,
                'mail.mailers.smtp.encryption' => $s->smtp_encryption,
                'mail.from.address'            => $s->smtp_from_address ?? $s->clinic_email,
                'mail.from.name'               => $s->smtp_from_name    ?? $s->clinic_name,
            ]);
        } catch (\Throwable) {}
    }

    private function restoreBaseMailConfig(): void
    {
        if ($this->baseMailConfig !== []) config($this->baseMailConfig);
    }
}
