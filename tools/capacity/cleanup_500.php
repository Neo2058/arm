<?php
// Only the exact synthetic fixture, never general users or historical logs.
require '/var/www/vendor/autoload.php';$a=require '/var/www/bootstrap/app.php';$a->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;
use App\Models\User;
$tag='capacity-20261109-500';
$result=DB::transaction(function()use($tag){
 $users=User::where('email','like',$tag.'-%@example.invalid')->lockForUpdate()->get();
 if($users->count()!==500)throw new RuntimeException('Unexpected fixture account count');
 foreach($users as $u)if(!$u->isDriver()||!str_starts_with($u->name,'Capacity 500 '))throw new RuntimeException('Unexpected account attributes');
 $ids=$users->modelKeys();$rooms=DB::table('chat_rooms')->where('title',$tag)->get();
 if($rooms->count()!==1||!in_array($rooms[0]->created_by,$ids,true))throw new RuntimeException('Room mismatch');
 $room=$rooms[0]->id;
 if(DB::table('chat_members')->where('room_id',$room)->whereNotIn('user_id',$ids)->exists())throw new RuntimeException('Unexpected room member');
 if(DB::table('chat_messages')->whereIn('sender_id',$ids)->where('room_id','!=',$room)->exists())throw new RuntimeException('Messages outside fixture');
 if(DB::table('chat_attachments')->whereIn('message_id',DB::table('chat_messages')->where('room_id',$room)->select('id'))->exists())throw new RuntimeException('Unexpected attachments');
 $q=DB::table('work_shifts')->whereIn('user_id',$ids);
 if((clone $q)->count()!==7500||(clone $q)->where(function($q)use($tag){$q->whereNull('start_location')->orWhere('start_location','!=',$tag);})->exists())throw new RuntimeException('Shift fixture mismatch');
 $messages=DB::table('chat_messages')->where('room_id',$room)->count();
 if($messages!==80)throw new RuntimeException('Message fixture mismatch');
 $tokens=DB::table('personal_access_tokens')->where('tokenable_type',User::class)->whereIn('tokenable_id',$ids)->delete();
 DB::table('chat_rooms')->where('id',$room)->delete();
 $shifts=$q->delete();
 DB::table('work_time_month_settings')->whereIn('user_id',$ids)->delete();
 DB::table('user_profiles')->whereIn('user_id',$ids)->delete();
 $deleted=User::whereIn('id',$ids)->where('email','like',$tag.'-%@example.invalid')->delete();
 return ['deleted_users'=>$deleted,'deleted_tokens'=>$tokens,'deleted_shifts'=>$shifts,'deleted_rooms'=>1,'deleted_messages'=>$messages,
         'remaining_users'=>User::where('email','like',$tag.'-%@example.invalid')->count()];
});
echo json_encode($result);
