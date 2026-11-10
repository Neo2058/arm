<?php
// Isolated video fixture app. Never loads repository .env or production credentials.
$root = dirname(__DIR__, 4);
$work = '/private/tmp/arm-video-demo';
if (!is_dir($work)) mkdir($work, 0700, true);
if (!is_file($work.'/key')) {
    file_put_contents($work.'/key', 'base64:'.base64_encode(random_bytes(32)));
    chmod($work.'/key', 0600);
}
foreach (['APP_ENV'=>'testing','APP_DEBUG'=>'true','APP_URL'=>'http://127.0.0.1:8786','APP_KEY'=>trim(file_get_contents($work.'/key')),
    'APP_CONFIG_CACHE'=>$work.'/config.php','APP_ROUTES_CACHE'=>$work.'/routes.php',
    'DB_CONNECTION'=>'sqlite','DB_DATABASE'=>$work.'/demo.sqlite','DB_URL'=>'','CACHE_STORE'=>'array',
    'SESSION_DRIVER'=>'file','SESSION_SECURE_COOKIE'=>'false','SESSION_DOMAIN'=>'','SESSION_COOKIE'=>'arm_video_demo',
    'QUEUE_CONNECTION'=>'sync','MAIL_MAILER'=>'array','BROADCAST_CONNECTION'=>'null','LOG_CHANNEL'=>'null',
    'PULSE_ENABLED'=>'false','TELESCOPE_ENABLED'=>'false','NIGHTWATCH_ENABLED'=>'false',
    'CHAT_ENABLED'=>'true','CHAT_ENCRYPTION_KEY'=>trim(file_get_contents($work.'/key')),'CHAT_PILOT_COLUMN'=>'',
    'TELEGRAM_BOT_TOKEN'=>'','TELEGRAM_NOTIFICATIONS_BOT_TOKEN'=>'','TELEGRAM_KEY_BOT_TOKEN'=>'',
    'AWS_ACCESS_KEY_ID'=>'','AWS_SECRET_ACCESS_KEY'=>'','AWS_ENDPOINT'=>'http://127.0.0.1:9',
] as $k=>$v) {putenv("$k=$v");$_ENV[$k]=$v;$_SERVER[$k]=$v;}
foreach (['framework/sessions','framework/views','framework/cache/data','logs','app/private','app/public'] as $folder) {
    if (!is_dir($work.'/storage/'.$folder)) mkdir($work.'/storage/'.$folder,0700,true);
}
require $root.'/vendor/autoload.php';
$app=require $root.'/bootstrap/app.php';
$app->useEnvironmentPath($work);$app->loadEnvironmentFrom('absent-video.env');$app->useStoragePath($work.'/storage');
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
config(['filesystems.disks.s3' => ['driver'=>'local','root'=>$work.'/storage/s3','throw'=>true]]);
App\Services\ClickHouseService::fake();
Illuminate\Support\Facades\Queue::fake();
Illuminate\Support\Facades\Http::preventStrayRequests();
Illuminate\Support\Facades\Http::fake();
// Device/barrier bypass belongs ONLY to this isolated fixture launcher; role/auth checks stay real.
class VideoFixtureBarrier {
    public function handle($request,$next) {return $next($request);}
}
foreach ([App\Http\Middleware\CheckDeviceBinding::class,App\Http\Middleware\CheckDynamicBarrier::class] as $class) {
    $app->instance($class,new VideoFixtureBarrier());
}
return $app;
