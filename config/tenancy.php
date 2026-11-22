<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Tenancy context
    |--------------------------------------------------------------------------
    |
    | Keep disabled while deploying the additive migrations and running the
    | reconciliation command. Enable after the cutover checklist passes; model
    | scopes then fail closed without a resolved clinic/branch membership.
    |
    */
    'enabled' => env('TENANCY_CONTEXT_ENABLED', false),

    /*
    | Hosted only: the hours (app timezone, first-last) when routine scheduled tasks run.
    | Outside them nothing is scheduled, so Laravel Cloud lets the app and database sleep.
    | On the quiet days (0 = Sunday, comma-separated; empty for none) they run once, at the
    | last day hour: clinics hardly work on Sundays, so the app can sleep most of the day.
    */
    'schedule_day_hours' => env('SCHEDULE_DAY_HOURS', '7-18'),
    'schedule_quiet_days' => env('SCHEDULE_QUIET_DAYS', '0'),

    /*
    | Count each clinic's requests, data and server time (Platform → Usage). Unset means on
    | for hosted installs (tenancy enabled) and off for offline ones.
    */
    'usage_metering' => env('USAGE_METERING'),

    'session_keys' => [
        'clinic' => 'tenancy.active_clinic_id',
        'branch' => 'tenancy.active_branch_id',
    ],
];
