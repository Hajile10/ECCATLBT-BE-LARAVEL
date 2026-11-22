<?php
namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder {
    public function run(): void {
        foreach (['max_books'=>'3', 'general_days'=>'3', 'fiction_days'=>'7', 'reservation_hours'=>'48'] as $k=>$v)
            DB::table('library_settings')->updateOrInsert(['key'=>$k],['value'=>$v]);
        foreach ([
            ['name'=>'Library Administrator','email'=>'admin@library.test','student_id'=>null,'role'=>'admin','patron_type'=>'Staff'],
            ['name'=>'Library Staff','email'=>'librarian@library.test','student_id'=>null,'role'=>'librarian','patron_type'=>'Staff'],
            ['name'=>'Demo Student','email'=>'student@library.test','student_id'=>'2026-0001','role'=>'student','patron_type'=>'Student']
        ] as $u) DB::table('users')->updateOrInsert(['email'=>$u['email']], array_merge($u,['password'=>Hash::make('Password123!'),'active'=>true,'updated_at'=>now(),'created_at'=>now()]));
        $this->call(LibraryContentSeeder::class);
    }
}
