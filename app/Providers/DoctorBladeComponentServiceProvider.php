<?php

namespace App\Providers;
use Illuminate\Support\Facades\Blade;


use Illuminate\Support\ServiceProvider;

class DoctorBladeComponentServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     *
     * @return void
     */
    public function register()
    {
        //
        Blade::component('layouts.doctor.doctor-layout', 'doctor-layout');

    }

    /**
     * Bootstrap services.
     *
     * @return void
     */
    public function boot()
    {
        //
    }
}
