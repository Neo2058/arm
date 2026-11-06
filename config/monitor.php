<?php

return [
    'metrics_path' => env('MONITOR_METRICS_PATH', '/run/arm-metrics/arm.prom'),
    'installer_path' => env('MONITOR_INSTALLER_PATH', '/run/arm-installers/tch15-monitor.apk'),
];
