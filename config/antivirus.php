<?php

return [
    'scanner' => env('ANTIVIRUS_CLAMDSCAN', env('DISCIPLINE_CLAMDSCAN', 'clamdscan')),
    'scan_timeout' => (int) env('ANTIVIRUS_SCAN_TIMEOUT', env('DISCIPLINE_SCAN_TIMEOUT', 60)),
    'clamd_config' => env('ANTIVIRUS_CLAMD_CONFIG', env('DISCIPLINE_CLAMD_CONFIG', base_path('docker/clamav/clamd-client.conf'))),
];
