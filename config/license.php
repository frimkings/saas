<?php

return [
    'signing_key_path' => env('OFFLINE_LICENSE_SIGNING_KEY'),
    'grace_days' => 0,

    /*
    |--------------------------------------------------------------------------
    | Ed25519 Public Key
    |--------------------------------------------------------------------------
    | Run `php keygen.php --generate-keys` on your development machine to
    | generate a keypair. Paste the PUBLIC KEY value here. Keep the private
    | key only inside keygen.php — never commit it or ship it with the app.
    |
    | Leave empty to run in FREE tier mode (no PRO features available).
    */
    'public_key' => '38qjdzFdT/EBK1oipeh3/akfrMp6uE5hytb1WoVFxUo=',
    

    /*
    |--------------------------------------------------------------------------
    | PRO Feature List
    |--------------------------------------------------------------------------
    | All features that require an active PRO license. Any feature not listed
    | here is considered FREE and always available.
    */
    'pro_features' => [
        \App\Support\Feature::APPOINTMENTS,
        \App\Support\Feature::REFERRALS,
        \App\Support\Feature::OUTSTANDING_BALANCES,
        \App\Support\Feature::MANUAL_BACKUP,
        \App\Support\Feature::SMS_CAMPAIGNS,
        \App\Support\Feature::ADVANCED_REPORTS,
        \App\Support\Feature::INVENTORY,
        \App\Support\Feature::APPROVALS,
        \App\Support\Feature::AUDIT_TRAIL,
        \App\Support\Feature::SCHEDULED_BACKUPS,
        \App\Support\Feature::EXPENSE_TRACKING,
        \App\Support\Feature::REPORT_DELIVERY,
        \App\Support\Feature::SPECTACLES_PRO,
        \App\Support\Feature::UNLIMITED_USERS,
    ],

];
