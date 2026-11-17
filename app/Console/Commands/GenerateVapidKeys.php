<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class GenerateVapidKeys extends Command
{
    protected $signature = 'arm:vapid-generate';

    protected $description = 'Сгенерировать VAPID-ключи для веб-оповещений (не печатает в лог, только stdout)';

    public function handle(): int
    {
        $key = openssl_pkey_new([
            'curve_name' => 'prime256v1',
            'private_key_type' => OPENSSL_KEYTYPE_EC,
        ]);
        if ($key === false) {
            $this->error('openssl не смог создать ключ prime256v1.');

            return self::FAILURE;
        }

        $details = openssl_pkey_get_details($key);
        $x = $details['ec']['x'] ?? '';
        $y = $details['ec']['y'] ?? '';
        if (strlen($x) !== 32 || strlen($y) !== 32) {
            $this->error('Некорректная точка P-256.');

            return self::FAILURE;
        }

        $public = rtrim(strtr(base64_encode("\x04".$x.$y), '+/', '-_'), '=');
        $pem = '';
        if (! openssl_pkey_export($key, $pem)) {
            $this->error('Не удалось экспортировать закрытый ключ.');

            return self::FAILURE;
        }
        $pemOneLine = str_replace("\n", '\\n', trim($pem));
        $host = parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'localhost';

        $this->line('VAPID_PUBLIC_KEY='.$public);
        $this->line('VAPID_PRIVATE_KEY="'.$pemOneLine.'"');
        $this->line('VAPID_SUBJECT=mailto:noreply@'.$host);
        $this->newLine();
        $this->comment('Добавьте строки в .env. Закрытый ключ не коммитить. После смены ключей пересоздайте app.');

        return self::SUCCESS;
    }
}
