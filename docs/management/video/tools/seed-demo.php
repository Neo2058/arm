<?php
$work='/private/tmp/arm-video-demo';
if (!is_dir($work)) mkdir($work,0700,true);
if (is_file($work.'/demo.sqlite')) throw new RuntimeException('Existing demo DB; inspect before reset');
touch($work.'/demo.sqlite');chmod($work.'/demo.sqlite',0600);
$app=require __DIR__.'/local-app.php';
Illuminate\Support\Facades\Artisan::call('migrate',['--force'=>true]);
$password=bin2hex(random_bytes(12));$accounts=[];
foreach (['driver'=>'Учебный машинист','instructor'=>'Учебный инструктор','dispatcher'=>'Учебный нарядчик','operator'=>'Учебный оператор','super_admin'=>'Учебный администратор'] as $role=>$name) {
    $u=App\Models\User::factory()->create(['name'=>$name,'email'=>$role.'@example.invalid','password'=>Illuminate\Support\Facades\Hash::make($password),'role'=>$role,'is_active'=>true]);
    App\Models\UserProfile::create(['user_id'=>$u->id,'column'=>'УЧ','tab_number'=>'D00'.$u->id,'normative_class'=>1]);
    $accounts[$role]=['id'=>$u->id,'email'=>$u->email,'password'=>$password];
}
$i=App\Models\User::find($accounts['instructor']['id']);
foreach (['Подготовить занятие по электробезопасности','Проверить сроки нормативов колонны','Согласовать учебный план'] as $n=>$title) {
    App\Models\JournalTask::create(['user_id'=>$i->id,'column'=>'УЧ','title'=>$title,'description'=>'Учебный сценарий видеопрезентации','source'=>'manual','status'=>$n===2?'done':'pending','due_date'=>'2026-11-12','version'=>1]);
}
foreach (['Ознакомиться с новой инструкцией','Повторить действия при неисправности','Внести запись о занятии'] as $n=>$title) {
    App\Models\FormularTask::create(['title'=>$title,'description'=>'Учебный пример','category'=>'Подготовка к работе','is_active'=>true,'sort_order'=>$n]);
}
file_put_contents($work.'/accounts.json',json_encode($accounts,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT));chmod($work.'/accounts.json',0600);
echo "LOCAL_DEMO_SEEDED roles=5 database=isolated_sqlite outbound_notifications=faked\n";
