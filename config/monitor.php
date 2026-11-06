<?php

return [
    'metrics_path' => env('MONITOR_METRICS_PATH', '/run/arm-metrics/arm.prom'),
    'installer_path' => storage_path('app/private/installers/tch15-monitor.apk'),
];
