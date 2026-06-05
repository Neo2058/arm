<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

use ClickHouseDB\Client;


class InitClickHouse extends Command
{

    protected $signature = 'clickhouse:init';

    protected $description = 'Инициализация таблиц статистики в ClickHouse';

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        $config = [
            'host' => config('clickhouse.host'),
            'port' => config('clickhouse.port'),
            'username' => config('clickhouse.username'),
            'password' => config('clickhouse.password'),
        ];

        $this->info('Connecting to ClickHouse...');

        $client = new Client($config);

        $client->database(config('clickhouse.database'));

        $client->setTimeout(10);
        $client->setConnectTimeOut(5);

        $client->write("CREATE TABLE IF NOT EXISTS default.user_actions (

                            event_date Date,

                            event_time DateTime,

                            user_id UInt64,

                            user_role String,

                            user_column String,

                            action_type String,

                            resource_id UInt64,

                            details String

                        )
                        ENGINE = MergeTree()
                        PARTITION BY toYYYYMM(event_date)
                        ORDER BY (action_type, event_time, user_id)");

        $this->info('Таблицы ClickHouse успешно созданы!');
    }
}
