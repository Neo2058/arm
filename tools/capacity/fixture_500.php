<?php
// CLI-only synthetic fixture. No production tariff or schema changes.
require '/var/www/vendor/autoload.php';
$a=require '/var/www/bootstrap/app.php';$a->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use App\Models\User;
use App\Services\Payroll\PersonalWorkShiftService;
use App\Services\Chat\ChatCrypto;
$tag='capacity-20261109-500';
$result=DB::transaction(function()use($tag){
 if(User::where('email','like',$tag.'-%@example.invalid')->exists())throw new RuntimeException('Fixture already exists');
 if(!filter_var(config('chat.enabled'),FILTER_VALIDATE_BOOLEAN))throw new RuntimeException('Chat disabled');
 $now=now()->format('Y-m-d H:i:s');$hash=Hash::make(bin2hex(random_bytes(32)));$rows=[];
 for($i=0;$i<500;$i++)$rows[]=['name'=>'Capacity 500 '.$i,'email'=>$tag.'-'.$i.'@example.invalid','password'=>$hash,'role'=>'driver','is_active'=>true,'created_at'=>$now,'updated_at'=>$now];
 foreach(array_chunk($rows,100) as $chunk)DB::table('users')->insert($chunk);
 $users=User::where('email','like',$tag.'-%@example.invalid')->orderBy('id')->get();
 if($users->count()!==500)throw new RuntimeException('Account count mismatch');
 $profiles=[];
 foreach($users as $i=>$u)$profiles[]=['user_id'=>$u->id,'column'=>'CAPACITY-500','tab_number'=>'CP500-'.$i,'created_at'=>$now,'updated_at'=>$now];
 foreach(array_chunk($profiles,100) as $chunk)DB::table('user_profiles')->insert($chunk);
 // Shared default settings, calculated through the existing preview service. No duplicated formulas.
 $templates=[];
 for($i=0;$i<15;$i++){
  $day=1+$i*2;$date=sprintf('2026-11-%02d',$day);$night=$i%3===0;
  $start=$date.($night?' 20:00':' 09:00');$end=$night?sprintf('2026-11-%02d 04:00',$day+1):$date.' 17:00';
  $kind=['line','reserve','surface'][$i%3];
  $s=app(PersonalWorkShiftService::class)->preview($users[0],['shift_date'=>$date,'started_at'=>$start,'ended_at'=>$end,'break_duration'=>30,
      'start_location'=>$tag,'end_location'=>$tag,'segments'=>[['kind'=>$kind,'started_at'=>$start,'ended_at'=>$end]]]);
  $attrs=$s->getAttributes();$attrs['created_at']=$now;$attrs['updated_at']=$now;
  if($attrs['total_minutes']!==480)throw new RuntimeException('Fixture duration mismatch');
  $templates[]=$attrs;
 }
 $shifts=[];
 foreach($users as $u)foreach($templates as $t){$t['user_id']=$u->id;$shifts[]=$t;}
 foreach(array_chunk($shifts,100) as $chunk)DB::table('work_shifts')->insert($chunk);
 $room=DB::table('chat_rooms')->insertGetId(['type'=>'work','title'=>$tag,'created_by'=>$users[0]->id,'created_at'=>$now,'updated_at'=>$now]);
 $members=[];
 foreach($users as $u)$members[]=['room_id'=>$room,'user_id'=>$u->id,'role'=>'member','source'=>'regular','created_at'=>$now,'updated_at'=>$now];
 foreach(array_chunk($members,100) as $chunk)DB::table('chat_members')->insert($chunk);
 $messages=[];
 for($i=0;$i<80;$i++)$messages[]=['room_id'=>$room,'sender_id'=>$users[$i%500]->id,'type'=>'text','status'=>'available',
  'body_ciphertext'=>app(ChatCrypto::class)->encrypt('Capacity fixture message '.$i),'created_at'=>$now,'updated_at'=>$now];
 DB::table('chat_messages')->insert($messages);
 $accounts=[];
 foreach($users as $u)$accounts[]=['user_id'=>$u->id,'token'=>$u->createToken($tag,['*'],now()->addHours(2))->plainTextToken];
 return ['tag'=>$tag,'room_id'=>$room,'accounts'=>$accounts,'shifts_per_user'=>15,'expected_paid_minutes'=>7200,'messages'=>80];
});
echo json_encode($result);
