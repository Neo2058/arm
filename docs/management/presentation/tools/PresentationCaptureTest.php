<?php
// Local-only presentation harness. Never loads project .env or a production database.
$root = dirname(__DIR__, 4);
$work = '/private/tmp/arm-management-demo';
foreach (['APP_ENV'=>'testing','APP_DEBUG'=>'true','APP_URL'=>'http://127.0.0.1:8765','APP_KEY'=>'base64:'.base64_encode(random_bytes(32)),'APP_CONFIG_CACHE'=>$work.'/config.php','APP_ROUTES_CACHE'=>$work.'/routes.php','DB_CONNECTION'=>'sqlite','DB_DATABASE'=>':memory:','DB_URL'=>'','CACHE_STORE'=>'array','SESSION_DRIVER'=>'array','QUEUE_CONNECTION'=>'sync','MAIL_MAILER'=>'array','BROADCAST_CONNECTION'=>'null','LOG_CHANNEL'=>'null'] as $k=>$v) { putenv("$k=$v"); $_ENV[$k]=$v; $_SERVER[$k]=$v; }
require $root.'/vendor/autoload.php';
class PresentationCaptureTest extends Tests\TestCase {
    use Illuminate\Foundation\Testing\RefreshDatabase;
    public function createApplication() {
        $app = require dirname(__DIR__,4).'/bootstrap/app.php';
        $app->useEnvironmentPath('/private/tmp/arm-management-demo');
        $app->loadEnvironmentFrom('absent-demo.env');
        $app->useStoragePath('/private/tmp/arm-management-demo/storage');
        $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        return $app;
    }
    public function testCapture(): void {
        App\Services\ClickHouseService::fake();
        $this->withoutMiddleware([App\Http\Middleware\CheckDeviceBinding::class,App\Http\Middleware\CheckDynamicBarrier::class,App\Http\Middleware\VerifyCsrfToken::class]);
        $person=function($name,$role,$tab) {
            $u=App\Models\User::factory()->create(['name'=>$name,'email'=>'demo'.$tab.'@example.invalid','role'=>$role,'is_active'=>true]);
            App\Models\UserProfile::create(['user_id'=>$u->id,'column'=>'УЧ','tab_number'=>$tab,'normative_class'=>1]);
            return $u;
        };
        $instructor=$person('Учебный инструктор','instructor','D001');
        $drivers=[];
        foreach(['Альфа','Бета','Гамма','Дельта','Эпсилон','Зета'] as $i=>$name) $drivers[]=$person('Машинист '.$name,'driver','D10'.$i);
        $dispatcher=$person('Учебный нарядчик','dispatcher','D002');
        foreach(['Подготовить занятие по электробезопасности','Проверить сроки нормативов колонны','Согласовать учебный план'] as $i=>$title) App\Models\JournalTask::create(['user_id'=>$instructor->id,'column'=>'УЧ','title'=>$title,'description'=>'Учебная запись для презентации','source'=>'manual','status'=>$i===2?'done':'pending','due_date'=>'2026-11-10','version'=>1]);
        $this->actingAs($instructor, 'web');
        $this->postJson('/journal/training-requests',['operation_id'=>(string)Illuminate\Support\Str::uuid(),'planning_month'=>'2026-11','shift_type'=>'А2','participant_count'=>3,'participants'=>array_map(fn($u)=>$u->id,array_slice($drivers,0,3))])->assertOk();
        foreach($drivers as $i=>$driver) foreach(['atz','atz_line'] as $type) App\Models\JournalCrewNormative::create(['user_id'=>$driver->id,'instructor_id'=>$instructor->id,'column'=>'УЧ','type'=>$type,'last_date'=>'2026-10-15','next_date'=>'2027-10-15']);
        $out='/private/tmp/arm-management-demo/web'; @mkdir($out,0700,true);
        $fixtures=[];
        foreach(['/journal','/journal/tasks','/journal/crew','/journal/training-requests?month=2026-11','/journal/training-requests/crew'] as $path) {
            $r=$this->getJson('/api/mobile'.$path); $r->assertOk(); $fixtures[strtok($path,'?')]=$r->json();
        }
        $fixtures['/documents']=['categories'=>[['name'=>'Учебные инструкции','items'=>[['id'=>1,'title'=>'Электробезопасность: учебный материал','updated_at'=>'02.11.2026'],['id'=>2,'title'=>'Организация работы локомотивной бригады','updated_at'=>'02.11.2026']]]]];
        file_put_contents($out.'/api-fixtures.json',json_encode($fixtures,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT));
        foreach(['web-carousel'=>'/mainMenu','web-journal'=>'/journal','web-atz'=>'/journal/training-requests?month=2026-11','web-worktime'=>'/worktime'] as $name=>$path) {
            $this->actingAs($instructor, 'web'); $r=$this->get($path); file_put_contents('/private/tmp/arm-management-demo/last-response.html',$r->getContent()); $r->assertOk(); file_put_contents($out.'/'.$name.'.html',$r->getContent()); echo $name." rendered\n";
        }
        $this->actingAs($dispatcher, 'web');
        foreach($drivers as $i=>$driver) for($d=3;$d<=8;$d+=2) App\Models\NaryadAssignment::create(['user_id'=>$driver->id,'plan_date'=>sprintf('2026-11-%02d',$d),'route_number'=>(string)(100+$i),'assigned_by'=>$dispatcher->id,'position'=>'driver']);
        foreach(['web-naryad'=>'/naryad?month=2026-11','web-setka'=>'/naryad/partial/setka?month=2026-11','web-control'=>'/naryad/partial/control?month=2026-11'] as $name=>$path) { $r=$this->get($path);$r->assertOk();file_put_contents($out.'/'.$name.'.html',$r->getContent());echo $name." rendered\n"; }
        $this->actingAs($drivers[0], 'web');
        foreach (['Ознакомиться с изменениями инструкции','Повторить порядок действий при неисправности','Внести запись о пройденном занятии'] as $i=>$title) {
            $task=App\Models\FormularTask::create(['title'=>$title,'description'=>'Учебное задание для демонстрации личного списка','category'=>'Учебные записи','is_active'=>true,'sort_order'=>$i]);
            if($i===0) App\Models\UserFormularLog::create(['user_id'=>$drivers[0]->id,'formular_task_id'=>$task->id,'completed_at'=>'2026-11-02 10:00:00','entry_text'=>'Ознакомление отмечено. Учебная запись.']);
        }
        App\Models\Podstroika::create(['user_id'=>$drivers[0]->id,'for_month'=>'2026-12-01','details'=>'Прошу учесть учебное занятие при составлении наряда. Демонстрационная заявка.','status'=>'pending']);
        foreach(['web-driver-tasks'=>'/rosisi','web-driver-podstroiki'=>'/podstroiki'] as $name=>$path) { $r=$this->get($path);$r->assertOk();file_put_contents($out.'/'.$name.'.html',$r->getContent());echo $name." rendered\n"; }
        echo "All data synthetic; SQLite in memory; no outbound notifications.\n";
    }
}

