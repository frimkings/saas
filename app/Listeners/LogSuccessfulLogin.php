<?php

namespace App\Listeners;

use App\Models\LoginLog;
use Illuminate\Auth\Events\Login;

class LogSuccessfulLogin
{
    /**
     * Create the event listener.
     *
     * @return void
     */
    public function __construct()
    {
        //
    }

    /**
     * Handle the event.
     *
     * @param  object  $event
     * @return void
     */
    public function handle(Login $event): void
    {
        if (config('tenancy.enabled') && ! $event->user->clinics()
            ->where('clinics.status', 'active')->wherePivot('status', 'active')->exists()) {
            return;
        }
        LoginLog::recordFor($event->user);
    }
}
