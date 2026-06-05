<?php

return [

    'host' => env('CLICKHOUSE_HOST', 'clickhouse'),

    'port' => env('CLICKHOUSE_PORT', 8123),

    'username' => env('CLICKHOUSE_USER', 'default'),

    'password' => env('CLICKHOUSE_PASSWORD', ''),

    'database' => env('CLICKHOUSE_DATABASE', 'default'),

];
