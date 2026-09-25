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

    'session_keys' => [
        'clinic' => 'tenancy.active_clinic_id',
        'branch' => 'tenancy.active_branch_id',
    ],
];
