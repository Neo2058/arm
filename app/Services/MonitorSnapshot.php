<?php

namespace App\Services;

class MonitorSnapshot
{
    public function read(): array
    {
        $path = config('monitor.metrics_path');
        abort_unless(is_file($path) && is_readable($path) && filesize($path) <= 131072, 503, 'Метрики временно недоступны.');
        $text = file_get_contents($path);
        $metrics = [];
        // Strict names and bounded labels: never pass through arbitrary exporter text.
        $allowed = ['arm_host_cpu_percent', 'arm_host_memory_bytes', 'arm_host_disk_bytes',
            'arm_container_up', 'arm_container_healthy', 'arm_container_cpu_percent', 'arm_container_memory_percent',
            'arm_http_probe_up', 'arm_http_probe_duration_seconds', 'arm_queue_pending', 'arm_queue_failed',
            'arm_queue_oldest_age', 'arm_db_connections', 'arm_db_max_connections', 'arm_db_slow',
            'arm_backup_created_timestamp_seconds', 'arm_alert_active', 'arm_collection_stage_up',
            'arm_collector_timestamp_seconds', 'arm_monitor_service_up'];
        foreach (explode("\n", $text) as $line) {
            if (! preg_match('/^(arm_[a-z_]+)(?:\{(\w+)="([a-zA-Z0-9_-]{1,64})"\})? ([0-9.eE+\-]+)$/D', $line, $m) || ! in_array($m[1], $allowed, true)) {
                continue;
            }
            $value = (float) $m[4];
            if (! is_finite($value)) {
                continue;
            }
            $metrics[] = ['name' => $m[1], 'label' => $m[3] ?? '', 'value' => $value];
        }
        $stamp = collect($metrics)->firstWhere('name', 'arm_collector_timestamp_seconds')['value'] ?? 0;

        return ['collected_at' => $stamp, 'stale' => $stamp > time() + 60 || time() - $stamp > 120, 'metrics' => $metrics];
    }
}
