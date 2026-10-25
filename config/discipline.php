<?php

return [
    'scanner' => env('DISCIPLINE_CLAMDSCAN', env('ANTIVIRUS_CLAMDSCAN', 'clamdscan')),
    'scan_timeout' => (int) env('DISCIPLINE_SCAN_TIMEOUT', env('ANTIVIRUS_SCAN_TIMEOUT', 60)),
    'clamd_config' => env('DISCIPLINE_CLAMD_CONFIG', env('ANTIVIRUS_CLAMD_CONFIG', base_path('docker/clamav/clamd-client.conf'))),
    'timezone' => env('DISCIPLINE_TIMEZONE', 'Europe/Moscow'),
];
