<?php

namespace App\Providers;

use Database\Seeders\NativeBootstrapSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Native\Desktop\Contracts\ProvidesPhpIni;
use Native\Desktop\Facades\Window;

class NativeAppServiceProvider implements ProvidesPhpIni
{
    /**
     * Executed once the native application has been booted.
     * Use this method to open windows, register global shortcuts, etc.
     */
    public function boot(): void
    {
        try {
            Artisan::call('db:seed', [
                '--class' => NativeBootstrapSeeder::class,
                '--force' => true,
            ]);
        } catch (\Throwable $exception) {
            Log::error('Unable to seed NativePHP first-run data.', [
                'exception' => $exception,
            ]);
        }

        Window::open();
    }

    /**
     * Return an array of php.ini directives to be set.
     */
    public function phpIni(): array
    {
        return [
        ];
    }
}
