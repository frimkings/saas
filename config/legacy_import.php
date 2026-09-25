<?php

return [
    // The staging account must be allowed to CREATE and DROP databases.
    // Keep these unset when the normal application DB user already has access.
    'database_username' => env('LEGACY_IMPORT_DB_USERNAME'),
    'database_password' => env('LEGACY_IMPORT_DB_PASSWORD'),
];
