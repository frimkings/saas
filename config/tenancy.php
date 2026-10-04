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
    */
    'schedule_day_hours' => env('SCHEDULE_DAY_HOURS', '6-20'),

    'session_keys' => [
        'clinic' => 'tenancy.active_clinic_id',
        'branch' => 'tenancy.active_branch_id',
    ],
];
