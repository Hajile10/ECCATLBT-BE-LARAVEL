<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class LibraryController extends Controller {
    private function setting(string $key, int $fallback): int {return (int)(DB::table('library_settings')->where('key',$key)->value('value') ?? $fallback);}
    private function log(Request $r,string $action,string $entity='',?int $id=null,array $details=[]):void {
        DB::table('audit_logs')->insert(['user_id'=>$r->user()?->id,'action'=>$action,'entity'=>$entity,'entity_id'=>$id,'details'=>json_encode($details),'created_at'=>now()]);
    }
    private function notify(int $userId,string $message):void {
        DB::table('library_notifications')->insert(['user_id'=>$userId,'message'=>$message,'created_at'=>now(),'updated_at'=>now()]);
    }
    private function fail(string $message,int $status=422) {return response()->json(['message'=>$message],$status);}
    private function bookQuery() {
        return DB::table('books as b')->select('b.*')
            ->selectSub(fn($q)=>$q->from('book_copies as c')->selectRaw('count(*)')->whereColumn('c.book_id','b.id'),'total_copies')
            ->selectSub(fn($q)=>$q->from('book_copies as c')->selectRaw('count(*)')->whereColumn('c.book_id','b.id')->where('c.status','Available'),'available_copies');
    }
    public function login(Request $r) {
        $d=$r->validate(['identifier'=>'required|string','password'=>'required|string']);
        $u=User::where('email',$d['identifier'])->orWhere('student_id',$d['identifier'])->first();
        if (!$u || !$u->active || !Hash::check($d['password'],$u->password)) return $this->fail('Invalid credentials or inactive account.',401);
        $token=$u->createToken('library-web')->plainTextToken;
        return ['token'=>$token,'user'=>$u];
    }
    public function registerStudent(Request $r) {
        $r->merge(['email'=>mb_strtolower(trim((string)$r->input('email'))),'student_id'=>trim((string)$r->input('student_id'))]);
        $d=$r->validate([
            'name'=>'required|string|max:255',
            'email'=>'required|string|email|max:255|unique:users,email',
            'student_id'=>'required|string|max:50|unique:users,student_id',
            'course'=>'nullable|string|max:255',
            'section'=>'nullable|string|max:255',
            'password'=>'required|string|min:8|confirmed',
        ]);
        $u=User::create([
            'name'=>$d['name'],'email'=>$d['email'],'student_id'=>$d['student_id'],
            'course'=>$d['course']??null,'section'=>$d['section']??null,
            'password'=>Hash::make($d['password']),'role'=>'student','patron_type'=>'Student','active'=>true,
        ]);
        return response()->json(['token'=>$u->createToken('library-web')->plainTextToken,'user'=>$u],201);
    }
    public function logout(Request $r) {$r->user()->currentAccessToken()?->delete();return ['message'=>'Logged out'];}
    public function me(Request $r) {return $r->user();}
    public function catalog(Request $r) {
        $q=$this->bookQuery();
        if ($search=trim((string)$r->query('search',''))) $q->where(fn($w)=>$w->where('b.title','like',"%$search%")->orWhere('b.author','like',"%$search%")->orWhere('b.isbn','like',"%$search%")->orWhere('b.classification','like',"%$search%"));
        if ($r->filled('classification')) $q->where('b.classification',$r->query('classification'));
        return $q->orderBy('b.title')->paginate(12);
    }
    public function book(int $id) {
        $b=$this->bookQuery()->where('b.id',$id)->first();
        if (!$b) return $this->fail('Book not found',404);
        $b->copies=DB::table('book_copies')->where('book_id',$id)->orderBy('accession_number')->get();
        return $b;
    }
    public function saveBook(Request $r,?int $id=null) {
        $d=$r->validate(['title'=>'required|string|max:255','author'=>'required|string|max:255','isbn'=>'nullable|string|max:100','publisher'=>'nullable|string|max:255','publication_year'=>'nullable|integer|min:1000|max:2100','edition'=>'nullable|string|max:100','series'=>'nullable|string|max:255','volume'=>'nullable|string|max:100','classification'=>'nullable|string|max:255','call_number'=>'nullable|string|max:100','collection_type'=>'required|string|max:80','description'=>'nullable|string','table_of_contents'=>'nullable|string','cover_url'=>'nullable|url|max:2048','inside_use_only'=>'boolean']);
        $d['updated_at']=now();
        if ($id) {if(!DB::table('books')->where('id',$id)->exists()) return $this->fail('Book not found',404);DB::table('books')->where('id',$id)->update($d);}
        else {$d['created_at']=now();$id=DB::table('books')->insertGetId($d);}
        $this->log($r,'save_book','book',$id);return $this->book($id);
    }
    public function addCopy(Request $r,int $id) {
        $d=$r->validate(['accession_number'=>'required|string|max:100|unique:book_copies,accession_number','condition'=>'nullable|in:Good,Fair,Poor','inventory_date'=>'nullable|date']);
        if(!DB::table('books')->where('id',$id)->exists()) return $this->fail('Book not found',404);
        $copy=DB::table('book_copies')->insertGetId(['book_id'=>$id,'accession_number'=>$d['accession_number'],'condition'=>$d['condition']??'Good','inventory_date'=>$d['inventory_date']??now()->toDateString(),'status'=>'Available','created_at'=>now(),'updated_at'=>now()]);
        $this->log($r,'add_copy','book_copy',$copy);return response()->json(['id'=>$copy,'message'=>'Copy added'],201);
    }
    public function dashboard(Request $r) {
        $u=$r->user();if($u->role==='student') return ['active_loans'=>DB::table('loans')->where('user_id',$u->id)->whereNull('returned_at')->count(),'reservations'=>DB::table('reservations')->where('user_id',$u->id)->count(),'overdue'=>DB::table('loans')->where('user_id',$u->id)->whereNull('returned_at')->whereDate('due_date','<',today())->count()];
        return ['titles'=>DB::table('books')->count(),'copies'=>DB::table('book_copies')->count(),'available'=>DB::table('book_copies')->where('status','Available')->count(),'borrowed'=>DB::table('book_copies')->where('status','Borrowed')->count(),'overdue'=>DB::table('loans')->whereNull('returned_at')->whereDate('due_date','<',today())->count(),'pending_reservations'=>DB::table('reservations')->where('status','Pending')->count(),'users'=>DB::table('users')->count()];
    }
    private function loanQuery() {return DB::table('loans as l')->join('users as u','u.id','=','l.user_id')->join('book_copies as c','c.id','=','l.book_copy_id')->join('books as b','b.id','=','c.book_id')->select('l.*','u.name as borrower','u.student_id','b.title','c.accession_number');}
    private function addLatePenalty(object $loan): object {
        $due=\Illuminate\Support\Carbon::parse($loan->due_date)->startOfDay();
        $end=$loan->returned_at?\Illuminate\Support\Carbon::parse($loan->returned_at)->startOfDay():today();
        $lateDays=$end->greaterThan($due)?(int)$due->diffInDays($end):0;
        $loan->late_days=$lateDays;$loan->penalty_amount=$lateDays*50;
        return $loan;
    }
    public function myLoans(Request $r) {return $this->loanQuery()->where('l.user_id',$r->user()->id)->orderByDesc('l.id')->limit(150)->get()->map(fn($loan)=>$this->addLatePenalty($loan));}
    public function loans(Request $r) {
        $q=$this->loanQuery();if($r->query('active')==='1')$q->whereNull('l.returned_at');
        $loans=$q->orderByDesc('l.id')->paginate(30);$loans->getCollection()->transform(fn($loan)=>$this->addLatePenalty($loan));return $loans;
    }
    private function loanExtensionQuery() {
        return DB::table('loan_extension_requests as e')->join('loans as l','l.id','=','e.loan_id')
            ->join('users as u','u.id','=','l.user_id')->join('book_copies as c','c.id','=','l.book_copy_id')
            ->join('books as b','b.id','=','c.book_id')->leftJoin('users as d','d.id','=','e.decided_by')
            ->select('e.*','l.due_date','l.returned_at','u.name as borrower','u.student_id','b.title','d.name as decided_by_name');
    }
    public function myLoanExtensions(Request $r) {
        return $this->loanExtensionQuery()->where('l.user_id',$r->user()->id)->orderByDesc('e.id')->limit(100)->get();
    }
    public function requestLoanExtension(Request $r,int $id) {
        if($r->user()->role!=='student')return $this->fail('Only students can request a loan extension.',403);
        $d=$r->validate(['requested_days'=>'required|integer|min:3|max:5']);$user=$r->user();
        return DB::transaction(function()use($r,$id,$d,$user){
            $loan=DB::table('loans')->where('id',$id)->where('user_id',$user->id)->lockForUpdate()->first();
            if(!$loan)return $this->fail('Loan not found.',404);
            if($loan->returned_at)return $this->fail('A returned book cannot be extended.');
            if((string)$loan->due_date<today()->toDateString())return $this->fail('Request an extension before the book becomes overdue.');
            $alreadyGranted=DB::table('loan_extension_requests')->where('loan_id',$id)->where('status','Approved')->exists();
            if($alreadyGranted)return $this->fail('An extension has already been approved for this loan.');
            if(DB::table('loan_extension_requests')->where('loan_id',$id)->where('status','Pending')->exists())return $this->fail('An extension request is already pending.');
            $requestId=DB::table('loan_extension_requests')->insertGetId(['loan_id'=>$id,'requested_days'=>$d['requested_days'],'status'=>'Pending','created_at'=>now(),'updated_at'=>now()]);
            foreach(User::whereIn('role',['admin','librarian'])->where('active',true)->pluck('id') as $staffId)$this->notify($staffId,"{$user->name} requested a {$d['requested_days']}-day extension for {$loan->id}.");
            $this->log($r,'request_loan_extension','loan_extension_request',$requestId,['loan_id'=>$id,'days'=>$d['requested_days']]);
            return response()->json(['id'=>$requestId,'message'=>'Extension request sent'],201);
        });
    }
    public function loanExtensionRequests(Request $r) {
        return $this->loanExtensionQuery()->orderByDesc('e.id')->paginate(30);
    }
    public function decideLoanExtension(Request $r,int $id) {
        $d=$r->validate(['status'=>'required|in:Approved,Rejected','decision_note'=>'nullable|string|max:1000']);
        return DB::transaction(function()use($r,$id,$d){
            $extension=DB::table('loan_extension_requests')->where('id',$id)->lockForUpdate()->first();
            if(!$extension)return $this->fail('Extension request not found.',404);
            if($extension->status!=='Pending')return $this->fail('This extension request has already been decided.');
            $loan=DB::table('loans')->where('id',$extension->loan_id)->lockForUpdate()->first();
            if(!$loan||$loan->returned_at)return $this->fail('The book has already been returned; this request cannot be approved.');
            $newDueDate=null;
            if($d['status']==='Approved'){
                $newDueDate=\Illuminate\Support\Carbon::parse($loan->due_date)->addDays((int)$extension->requested_days)->toDateString();
                DB::table('loans')->where('id',$loan->id)->update(['due_date'=>$newDueDate,'updated_at'=>now()]);
            }
            DB::table('loan_extension_requests')->where('id',$id)->update(['status'=>$d['status'],'decided_by'=>$r->user()->id,'decision_note'=>$d['decision_note']??null,'updated_at'=>now()]);
            $message=$d['status']==='Approved'?"Your {$extension->requested_days}-day extension for loan #{$loan->id} was approved. New due date: {$newDueDate}.":"Your extension request for loan #{$loan->id} was declined.";
            $this->notify($loan->user_id,$message);$this->log($r,'loan_extension_'.$d['status'],'loan_extension_request',$id,['loan_id'=>$loan->id,'days'=>$extension->requested_days]);
            return ['message'=>'Extension request '.$d['status'],'due_date'=>$newDueDate];
        });
    }
    public function checkout(Request $r) {
        $d=$r->validate(['student_id'=>'required|string','accession_number'=>'required|string','due_date'=>'nullable|date|after_or_equal:today']);
        return DB::transaction(function () use ($r,$d) {
            $u=User::where('student_id',$d['student_id'])->where('active',true)->lockForUpdate()->first();
            if(!$u)return $this->fail('No active student with that ID.');
            $copy=DB::table('book_copies as c')->join('books as b','b.id','=','c.book_id')->where('c.accession_number',$d['accession_number'])->select('c.*','b.collection_type','b.inside_use_only','b.title')->lockForUpdate()->first();
            if(!$copy)return $this->fail('Book copy not found.');
            if($copy->inside_use_only)return $this->fail('This material is for inside-library use only.');
            if(DB::table('loans')->where('user_id',$u->id)->whereNull('returned_at')->count()>=$this->setting('max_books',3))return $this->fail('Borrower reached the active loan limit.');
            $reservation=null;
            if($copy->status==='Reserved') {
                $reservation=DB::table('reservations')->where('book_copy_id',$copy->id)->where('user_id',$u->id)->whereIn('status',['Approved','Ready for Pickup'])->lockForUpdate()->first();
                if(!$reservation)return $this->fail('Copy is reserved for someone else.');
                if($reservation->expires_at && now()->greaterThan($reservation->expires_at))return $this->fail('Reservation expired. Ask the librarian to release the hold.');
            } elseif($copy->status!=='Available')return $this->fail('Copy is not available.');
            $days=strtolower($copy->collection_type)==='fiction'?$this->setting('fiction_days',7):$this->setting('general_days',3);
            $due=$d['due_date']??today()->addDays($days)->toDateString();
            $id=DB::table('loans')->insertGetId(['user_id'=>$u->id,'book_copy_id'=>$copy->id,'reservation_id'=>$reservation?->id,'borrowed_at'=>now(),'due_date'=>$due,'created_at'=>now(),'updated_at'=>now()]);
            DB::table('book_copies')->where('id',$copy->id)->update(['status'=>'Borrowed','updated_at'=>now()]);
            if($reservation)DB::table('reservations')->where('id',$reservation->id)->update(['status'=>'Completed','updated_at'=>now()]);
            $this->notify($u->id,"Borrowed {$copy->title}. Due on {$due}.");$this->log($r,'checkout','loan',$id);
            return response()->json(['id'=>$id,'due_date'=>$due,'message'=>'Book checked out'],201);
        });
    }
    public function returnBook(Request $r,int $id) {
        $d=$r->validate(['condition'=>'required|in:Good,Fair,Poor,Damaged','notes'=>'nullable|string|max:2000']);
        return DB::transaction(function()use($r,$id,$d){
            $loan=DB::table('loans')->where('id',$id)->lockForUpdate()->first();
            if(!$loan||$loan->returned_at)return $this->fail('Active loan not found.');
            $copy=DB::table('book_copies')->where('id',$loan->book_copy_id)->lockForUpdate()->first();
            DB::table('loans')->where('id',$id)->update(['returned_at'=>now(),'notes'=>$d['notes']??null,'updated_at'=>now()]);
            DB::table('book_copies')->where('id',$copy->id)->update(['status'=>$d['condition']==='Damaged'?'Damaged':'Available','condition'=>$d['condition'],'updated_at'=>now()]);
            $this->notify($loan->user_id,'Your returned book has been checked in.');$this->log($r,'return','loan',$id);
            return ['message'=>'Book checked in'];
        });
    }
    public function renew(Request $r,int $id) {
        $d=$r->validate(['due_date'=>'required|date|after:today']);
        $loan=DB::table('loans')->where('id',$id)->whereNull('returned_at')->first();if(!$loan)return $this->fail('Active loan not found.');
        if($d['due_date']<=$loan->due_date)return $this->fail('New date must be later than current due date.');
        DB::table('loans')->where('id',$id)->update(['due_date'=>$d['due_date'],'renewals'=>DB::raw('renewals + 1'),'updated_at'=>now()]);
        $this->notify($loan->user_id,"Your due date was extended to {$d['due_date']}.");$this->log($r,'renew','loan',$id);return ['message'=>'Loan renewed'];
    }
    private function reservationQuery() {return DB::table('reservations as r')->join('books as b','b.id','=','r.book_id')->join('users as u','u.id','=','r.user_id')->leftJoin('book_copies as c','c.id','=','r.book_copy_id')->select('r.*','b.title','u.name as borrower','u.student_id','c.accession_number');}
    public function myReservations(Request $r) {return $this->reservationQuery()->where('r.user_id',$r->user()->id)->orderByDesc('r.id')->get();}
    public function reservations(Request $r) {return $this->reservationQuery()->orderByDesc('r.id')->paginate(30);}
    public function requestReservation(Request $r) {
        $d=$r->validate(['book_id'=>'required|integer|exists:books,id']);$u=$r->user();
        return DB::transaction(function()use($r,$d,$u){
            $b=DB::table('books')->where('id',$d['book_id'])->lockForUpdate()->first();
            if($b->inside_use_only)return $this->fail('Inside-library use only.');
            if(DB::table('reservations')->where('user_id',$u->id)->where('book_id',$b->id)->whereIn('status',['Pending','Approved','Ready for Pickup'])->exists())return $this->fail('You already have an active request for this title.');
            if(DB::table('loans')->where('user_id',$u->id)->whereNull('returned_at')->count()>=$this->setting('max_books',3))return $this->fail('You have reached the borrowing limit.');
            if(!DB::table('book_copies')->where('book_id',$b->id)->where('status','Available')->exists())return $this->fail('No copies currently available.');
            $id=DB::table('reservations')->insertGetId(['user_id'=>$u->id,'book_id'=>$b->id,'status'=>'Pending','created_at'=>now(),'updated_at'=>now()]);
            $this->notify($u->id,"Your reservation request for {$b->title} was submitted and is pending staff review.");
            foreach(User::whereIn('role',['admin','librarian'])->where('active',true)->pluck('id') as $sid)$this->notify($sid,"New reservation request for {$b->title}.");
            $this->log($r,'request_reservation','reservation',$id);return response()->json(['id'=>$id,'message'=>'Reservation requested'],201);
        });
    }
    public function reservationStatus(Request $r,int $id) {
        $d=$r->validate(['status'=>'required|in:Approved,Rejected,Ready for Pickup','reason'=>'nullable|string|max:1000']);
        return DB::transaction(function()use($r,$id,$d){
            $reservation=DB::table('reservations')->where('id',$id)->lockForUpdate()->first();if(!$reservation)return $this->fail('Reservation not found',404);
            $status=$d['status'];
            if($reservation->status==='Pending' && !in_array($status,['Approved','Rejected']))return $this->fail('Approve or reject first.');
            if($reservation->status==='Approved' && $status!=='Ready for Pickup')return $this->fail('Only Ready for Pickup is allowed after approval.');
            if(!in_array($reservation->status,['Pending','Approved']))return $this->fail('Reservation cannot be changed.');
            $fields=['status'=>$status,'reason'=>$d['reason']??null,'updated_at'=>now()];
            if($status==='Approved') {
                $copy=DB::table('book_copies')->where('book_id',$reservation->book_id)->where('status','Available')->orderBy('id')->lockForUpdate()->first();
                if(!$copy)return $this->fail('No available copies to hold.');
                DB::table('book_copies')->where('id',$copy->id)->update(['status'=>'Reserved','updated_at'=>now()]);
                $fields['book_copy_id']=$copy->id;
                $fields['expires_at']=now()->addHours($this->setting('reservation_hours',48));
            }
            if($status==='Ready for Pickup' && $reservation->expires_at && now()->greaterThan($reservation->expires_at))return $this->fail('Reservation has expired.');
            DB::table('reservations')->where('id',$id)->update($fields);
            $this->notify($reservation->user_id,"Your reservation #{$id} is {$status}.");$this->log($r,'reservation_'.$status,'reservation',$id);
            return ['message'=>'Reservation updated'];
        });
    }
    public function cancelReservation(Request $r,int $id) {
        return DB::transaction(function()use($r,$id){
            $q=DB::table('reservations')->where('id',$id)->lockForUpdate();$rs=$q->first();
            if(!$rs||($rs->user_id!==$r->user()->id && $r->user()->role==='student'))return $this->fail('Reservation not found',404);
            if(!in_array($rs->status,['Pending','Approved','Ready for Pickup']))return $this->fail('Cannot cancel this request.');
            if($rs->book_copy_id)DB::table('book_copies')->where('id',$rs->book_copy_id)->where('status','Reserved')->update(['status'=>'Available','updated_at'=>now()]);
            $q->update(['status'=>'Cancelled','updated_at'=>now()]);
            $bookTitle=DB::table('books')->where('id',$rs->book_id)->value('title')??'a book';
            if((int)$r->user()->id===(int)$rs->user_id){foreach(User::whereIn('role',['admin','librarian'])->where('active',true)->pluck('id') as $sid)$this->notify($sid,"Reservation #{$id} for {$bookTitle} was cancelled by the borrower.");}
            else $this->notify($rs->user_id,"Your reservation #{$id} for {$bookTitle} was cancelled by library staff.");
            $this->log($r,'cancel_reservation','reservation',$id);return ['message'=>'Reservation cancelled'];
        });
    }
    public function users(Request $r) {return User::query()->when($r->query('search'),function($q,$s){$q->where(fn($p)=>$p->where('name','like',"%$s%")->orWhere('student_id','like',"%$s%"));})->orderBy('name')->paginate(30);}
    public function saveUser(Request $r) {
        $d=$r->validate(['name'=>'required|string|max:255','email'=>'required|email|unique:users,email','student_id'=>'nullable|string|max:50|unique:users,student_id','password'=>'required|string|min:8','role'=>'required|in:student,librarian,admin','course'=>'nullable|string','section'=>'nullable|string','patron_type'=>'nullable|string']);
        if($r->user()->role!=='admin' && $d['role']!=='student')return $this->fail('Only admin can create staff accounts.',403);
        $u=User::create($d);$this->log($r,'create_user','user',$u->id);return response()->json($u,201);
    }
    public function importUsers(Request $r) {
        $r->validate(['file'=>'required|file|mimes:csv,txt|max:2048']);
        $f=fopen($r->file('file')->getRealPath(),'r');$headers=array_map(fn($v)=>strtolower(trim($v)),fgetcsv($f)?:[]);
        if(!in_array('student_id',$headers)||!in_array('name',$headers)||!in_array('email',$headers)){fclose($f);return $this->fail('CSV requires student_id, name and email columns.');}
        $imported=0;$errors=[];$rowNo=1;
        while(($line=fgetcsv($f))!==false){$rowNo++;if(count($line)!==count($headers)){ $errors[]="Row $rowNo: column mismatch";continue;}
            $data=array_combine($headers,$line);
            $v=Validator::make($data,['student_id'=>'required|max:50','name'=>'required|max:255','email'=>'required|email','course'=>'nullable|string','section'=>'nullable|string']);
            if($v->fails()){$errors[]="Row $rowNo: invalid fields";continue;}
            if(User::where('student_id',$data['student_id'])->orWhere('email',$data['email'])->exists()){$errors[]="Row $rowNo: duplicate";continue;}
            User::create(['name'=>$data['name'],'student_id'=>$data['student_id'],'email'=>$data['email'],'password'=>str()->random(32),'role'=>'student','course'=>$data['course']??null,'section'=>$data['section']??null]);$imported++;
        }
        fclose($f);$this->log($r,'import_users','user',null,['count'=>$imported]);return ['imported'=>$imported,'errors'=>$errors,'note'=>'Imported accounts have random passwords. Admin must arrange a secure password setup process before login.'];
    }
    public function incidents(Request $r){return DB::table('incidents as i')->join('loans as l','l.id','=','i.loan_id')->join('users as u','u.id','=','l.user_id')->select('i.*','u.name as borrower')->orderByDesc('i.id')->paginate(30);}
    public function incident(Request $r) {
        $d=$r->validate(['loan_id'=>'required|exists:loans,id','type'=>'required|in:Lost,Damaged','notes'=>'nullable|string','resolution'=>'nullable|string|max:255']);
        return DB::transaction(function()use($r,$d){
            $loan=DB::table('loans')->where('id',$d['loan_id'])->lockForUpdate()->first();
            if(!$loan)return $this->fail('Loan not found.');
            $id=DB::table('incidents')->insertGetId(array_merge($d,['status'=>'Open','created_at'=>now(),'updated_at'=>now()]));
            DB::table('book_copies')->where('id',$loan->book_copy_id)->update(['status'=>$d['type'],'updated_at'=>now()]);
            $title=DB::table('book_copies as c')->join('books as b','b.id','=','c.book_id')->where('c.id',$loan->book_copy_id)->value('b.title')??'a borrowed book';
            $this->notify($loan->user_id,"Library staff recorded a {$d['type']} incident for {$title}. Please contact the library if you have questions.");
            $this->log($r,'incident','incident',$id);return response()->json(['id'=>$id],201);
        });
    }
    public function notifications(Request $r){
        $user=$r->user();$today=today();$tomorrow=$today->copy()->addDay();
        $activeLoans=DB::table('loans as l')->join('book_copies as c','c.id','=','l.book_copy_id')->join('books as b','b.id','=','c.book_id')->where('l.user_id',$user->id)->whereNull('l.returned_at')->whereDate('l.due_date','<=',$tomorrow)->select('l.id','l.due_date','b.title')->get();
        foreach($activeLoans as $loan){$due=\Illuminate\Support\Carbon::parse($loan->due_date)->startOfDay();if($due->lt($today))$message="Overdue notice: {$loan->title} was due on ".$due->toDateString().'. Please return it or contact the library.';elseif($due->isSameDay($today))$message="Reminder: {$loan->title} is due today.";else $message="Reminder: {$loan->title} is due tomorrow.";if(!DB::table('library_notifications')->where('user_id',$user->id)->where('message',$message)->exists())$this->notify($user->id,$message);}
        return DB::table('library_notifications')->where('user_id',$user->id)->orderByDesc('id')->limit(80)->get();
    }
    public function readNotifications(Request $r){DB::table('library_notifications')->where('user_id',$r->user()->id)->whereNull('read_at')->update(['read_at'=>now()]);return ['message'=>'Notifications marked read'];}
    public function reports(Request $r) {
        $start=$r->query('start',today()->startOfMonth()->toDateString());$end=$r->query('end',today()->toDateString());
        Validator::make(['start'=>$start,'end'=>$end],['start'=>'required|date','end'=>'required|date|after_or_equal:start'])->validate();
        return ['period'=>['start'=>$start,'end'=>$end], 'borrowed'=>DB::table('loans')->whereDate('borrowed_at','>=',$start)->whereDate('borrowed_at','<=',$end)->count(), 'returned'=>DB::table('loans')->whereNotNull('returned_at')->whereDate('returned_at','>=',$start)->whereDate('returned_at','<=',$end)->count(), 'overdue'=>DB::table('loans')->whereNull('returned_at')->whereDate('due_date','<',today())->count(), 'top_books'=>DB::table('loans as l')->join('book_copies as c','c.id','=','l.book_copy_id')->join('books as b','b.id','=','c.book_id')->select('b.title')->selectRaw('count(*) as times_borrowed')->whereDate('l.borrowed_at','>=',$start)->whereDate('l.borrowed_at','<=',$end)->groupBy('b.id','b.title')->orderByDesc('times_borrowed')->limit(10)->get()];
    }
    public function research(Request $r){$viewer=$r->bearerToken()?auth('sanctum')->user():null;return DB::table('research_documents')->when(!$viewer||$viewer->role==='student',fn($q)=>$q->where('public',true))->orderByDesc('id')->paginate(20);}
    public function uploadResearch(Request $r) {
        $d=$r->validate(['title'=>'required|string|max:255','authors'=>'required|string|max:255','course'=>'nullable|string|max:255','year'=>'nullable|integer|min:1900|max:2100','abstract'=>'nullable|string','public'=>'boolean','file'=>'required|file|mimes:pdf|max:10240']);
        $path=$r->file('file')->store('research','local');unset($d['file']);$d['file_path']=$path;$d['uploaded_by']=$r->user()->id;$d['public']=$d['public']??false;$d['created_at']=now();$d['updated_at']=now();
        $id=DB::table('research_documents')->insertGetId($d);$this->log($r,'upload_research','research_document',$id);return response()->json(['id'=>$id],201);
    }
    public function downloadResearch(Request $r,int $id) {
        $doc=DB::table('research_documents')->where('id',$id)->first();if(!$doc)return $this->fail('Document not found',404);
        if(!$doc->public && $r->user()->role==='student')return $this->fail('Access denied',403);
        if(!Storage::disk('local')->exists($doc->file_path))return $this->fail('File missing',404);
        return Storage::disk('local')->download($doc->file_path,preg_replace('/[^a-zA-Z0-9_-]/','_',substr($doc->title,0,80)).'.pdf');
    }
    public function settings(Request $r){return DB::table('library_settings')->pluck('value','key');}
    public function updateSettings(Request $r) {
        $d=$r->validate(['max_books'=>'required|integer|min:1|max:20','general_days'=>'required|integer|min:1|max:60','fiction_days'=>'required|integer|min:1|max:60','reservation_hours'=>'required|integer|min:1|max:720']);
        foreach($d as $k=>$v)DB::table('library_settings')->updateOrInsert(['key'=>$k],['value'=>(string)$v]);$this->log($r,'update_settings');return $d;
    }
    public function auditLogs(Request $r){return DB::table('audit_logs as a')->leftJoin('users as u','a.user_id','=','u.id')->select('a.*','u.name')->orderByDesc('a.id')->paginate(40);}
}
