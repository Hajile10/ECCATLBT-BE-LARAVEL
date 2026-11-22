<?php
namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class LibraryContentSeeder extends Seeder {
    public function run(): void {
        $path=database_path('seeders/data/library_content.json');
        if(!is_file($path))throw new \RuntimeException('Library seed data file is missing.');
        $content=json_decode(file_get_contents($path),true,512,JSON_THROW_ON_ERROR);
        foreach($content['books']??[] as $book){
            $copies=$book['copies']??[];unset($book['copies']);
            $book['created_at']=$book['updated_at']=now();
            $id=DB::table('books')->where('title',$book['title'])->where('author',$book['author'])->value('id');
            if(!$id)$id=DB::table('books')->insertGetId($book);
            foreach($copies as $copy){
                if(DB::table('book_copies')->where('accession_number',$copy['accession_number'])->exists())continue;
                DB::table('book_copies')->insert(array_merge($copy,['book_id'=>$id,'inventory_date'=>today(),'created_at'=>now(),'updated_at'=>now()]));
            }
        }
        $adminId=DB::table('users')->where('email','admin@library.test')->value('id')??DB::table('users')->where('role','admin')->value('id');
        if(!$adminId)throw new \RuntimeException('Seed an administrator before the research collection.');
        foreach($content['research']??[] as $document){
            if(DB::table('research_documents')->where('title',$document['title'])->exists())continue;
            if(!Storage::disk('local')->exists($document['file_path']))continue;
            DB::table('research_documents')->insert(array_merge($document,['uploaded_by'=>$adminId,'created_at'=>now(),'updated_at'=>now()]));
        }
    }
}
