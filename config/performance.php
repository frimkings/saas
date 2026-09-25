<?php

return [
    'patient_records_profile' => (bool) env('PATIENT_RECORDS_PROFILE', false),
    'patient_records_log_channel' => env('PATIENT_RECORDS_LOG_CHANNEL', 'daily'),
    'patient_records_slow_ms' => (int) env('PATIENT_RECORDS_SLOW_MS', 250),
];
