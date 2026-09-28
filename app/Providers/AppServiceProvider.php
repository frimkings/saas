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
        // Caches price lists for the request; stock option pricing looks them up per eye.
        $this->app->scoped(\App\Services\OpticalLensPriceList::class);
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
        });

        // Every clinic sends through the platform's Resend account (config/mail.php), so
        // there is no per-clinic mail setup to swap in and out around jobs.
        $clearTenant = fn () => app(TenantContext::class)->clear();
        Queue::after(fn (JobProcessed $event) => $clearTenant());
        Queue::exceptionOccurred(fn (JobExceptionOccurred $event) => $clearTenant());

        // Livewire's own requests from an optical page are checked against that page's role access too.
        \Livewire\Livewire::addPersistentMiddleware([\App\Http\Middleware\EnsureOpticalAccess::class]);

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

        // Super Admin Gate
        Gate::before(function ($user, $ability) {
            return $user->hasRole('Super Admin') ? true : null;
        });
    }
}
