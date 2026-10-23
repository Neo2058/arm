<?php

return [
    'scanner' => env('DISCIPLINE_CLAMDSCAN', 'clamdscan'),
    'scan_timeout' => 60,
    'clamd_config' => env('DISCIPLINE_CLAMD_CONFIG', base_path('docker/clamav/clamd-client.conf')),
    'timezone' => env('DISCIPLINE_TIMEZONE', 'Europe/Moscow'),
];
