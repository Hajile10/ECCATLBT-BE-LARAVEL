<?php
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;

Artisan::command('library:expire-reservations', function () {
    $count=0;
    DB::table('reservations')->whereIn('status',['Approved','Ready for Pickup'])->where('expires_at','<',now())->orderBy('id')->chunkById(100,function ($rows) use (&$count) {
        foreach ($rows as $res) DB::transaction(function () use ($res,&$count) {
            $live=DB::table('reservations')->where('id',$res->id)->lockForUpdate()->first();
            if(!$live||!in_array($live->status,['Approved','Ready for Pickup'])||!$live->expires_at||$live->expires_at>=now())return;
            if($live->book_copy_id)DB::table('book_copies')->where('id',$live->book_copy_id)->where('status','Reserved')->update(['status'=>'Available','updated_at'=>now()]);
            DB::table('reservations')->where('id',$live->id)->update(['status'=>'Expired','updated_at'=>now()]);
            DB::table('library_notifications')->insert(['user_id'=>$live->user_id,'message'=>"Reservation #{$live->id} expired.",'created_at'=>now(),'updated_at'=>now()]);
            $count++;
        });
    });
    $this->info("Expired {$count} reservations.");
})->purpose('Release library reservation holds after their pickup deadline');

Schedule::command('library:expire-reservations')->everyFiveMinutes();
