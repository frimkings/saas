<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => env('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
    ],

    // Platform email sender for every clinic (config/mail.php 'resend' mailer).
    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    // Platform-owned SMS gateway used by every hosted clinic; offline installs keep their own credentials.
    'eazisms' => [
        'url' => env('EAZISMS_API_URL'),
        'key' => env('EAZISMS_API_KEY'),
        'default_sender' => env('EAZISMS_DEFAULT_SENDER'),
        // Alert platform admins when the provider balance drops below this (or below clinics' prepaid credits).
        'low_balance' => (int) env('EAZISMS_LOW_BALANCE', 1000),
    ],

];
