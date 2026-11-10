<?php
$app=require __DIR__.'/local-app.php';
if(config('database.connections.sqlite.database')!=='/private/tmp/arm-video-demo/demo.sqlite')exit(2);
$admin=App\Models\User::where('role','super_admin')->firstOrFail();
$driver=App\Models\User::where('role','driver')->firstOrFail();
$instructor=App\Models\User::where('role','instructor')->firstOrFail();
foreach([$driver,$instructor] as $user){$user->forceFill(['mobile_enrolled_at'=>now()])->save();}
$chat=app(App\Services\Chat\ChatService::class);
$room=$chat->createRoom($admin,['type'=>'column','column'=>'УЧ','title'=>'Рабочий чат · учебная колонна']);
foreach([$driver,$instructor] as $user){App\Models\ChatMember::firstOrCreate(['room_id'=>$room->id,'user_id'=>$user->id],['role'=>$user->isInstructor()?'moderator':'member','source'=>'regular','added_by'=>$admin->id]);}
$chat->sendText($instructor,$room,(string)Illuminate\Support\Str::uuid(),'Коллеги, учебный материал размещён в разделе обучения.');
$chat->sendText($driver,$room,(string)Illuminate\Support\Str::uuid(),'Принято. Открою материал перед занятием.');
$doc=App\Models\Document::create(['title'=>'Учебная инструкция · пример PDF','category'=>'Учебные документы','file_path'=>'demo/instruction.pdf','size'=>filesize('/private/tmp/arm-video-demo/storage/s3/demo/instruction.pdf'),'allowed_roles'=>['driver','instructor','dispatcher','operator','admin','super_admin']]);
$quiz=App\Models\Quiz::create(['title'=>'Учебный пример проверки знаний','description'=>'Демонстрация интерфейса на вымышленных материалах','document_id'=>$doc->id,'time_limit'=>300,'is_active'=>true]);
$q=App\Models\Question::create(['quiz_id'=>$quiz->id,'question_text'=>'Для чего используется учебный контур в этой презентации?','sort_order'=>1]);
foreach(['Для демонстрации без рабочих данных'=>true,'Для изменения действующего наряда'=>false,'Для отправки реальных уведомлений'=>false] as $text=>$correct){App\Models\Answer::create(['question_id'=>$q->id,'answer_text'=>$text,'is_correct'=>$correct]);}
$topic=App\Models\TrainingTopic::create(['title'=>'Подготовка к рабочему дню · учебный пример','slug'=>'video-demo','description'=>'Показ возможностей интерфейса','is_active'=>true,'sort_order'=>1]);
App\Models\TrainingMaterial::create(['training_topic_id'=>$topic->id,'title'=>'Как пользоваться учебным контуром','description'=>'Вымышленный материал для демонстрации','type'=>'text','content'=>'В учебном контуре можно безопасно показать действия пользователей. Здесь нет действующих инструкций и рабочих персональных данных.','is_active'=>true,'sort_order'=>1]);
echo "LOCAL_CONTENT_SEEDED chat=1 document=1 quiz=1 topic=1\n";
