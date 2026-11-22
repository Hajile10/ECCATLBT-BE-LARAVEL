<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::table('users', function (Blueprint $t) {
            $t->string('student_id', 50)->nullable()->unique();
            $t->string('role', 20)->default('student')->index();
            $t->string('course')->nullable(); $t->string('section')->nullable();
            $t->string('patron_type', 40)->default('Student');
            $t->boolean('active')->default(true);
        });
        Schema::create('books', function (Blueprint $t) {
            $t->id(); $t->string('title'); $t->string('author'); $t->string('isbn')->nullable()->index();
            $t->string('publisher')->nullable(); $t->unsignedSmallInteger('publication_year')->nullable();
            $t->string('edition')->nullable(); $t->string('series')->nullable(); $t->string('volume')->nullable();
            $t->string('classification')->nullable()->index(); $t->string('call_number')->nullable();
            $t->string('collection_type')->default('General'); $t->text('description')->nullable();
            $t->longText('table_of_contents')->nullable(); $t->string('cover_url')->nullable();
            $t->boolean('inside_use_only')->default(false); $t->timestamps();
        });
        Schema::create('book_copies', function (Blueprint $t) {
            $t->id(); $t->foreignId('book_id')->constrained()->cascadeOnDelete();
            $t->string('accession_number')->unique();
            $t->string('status', 25)->default('Available')->index();
            $t->string('condition', 30)->default('Good'); $t->date('inventory_date')->nullable();
            $t->timestamps();
        });
        Schema::create('reservations', function (Blueprint $t) {
            $t->id(); $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->foreignId('book_id')->constrained()->cascadeOnDelete();
            $t->foreignId('book_copy_id')->nullable()->constrained('book_copies')->nullOnDelete();
            $t->string('status', 25)->default('Pending')->index(); $t->text('reason')->nullable();
            $t->timestamp('expires_at')->nullable(); $t->timestamps();
        });
        Schema::create('loans', function (Blueprint $t) {
            $t->id(); $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->foreignId('book_copy_id')->constrained('book_copies')->restrictOnDelete();
            $t->foreignId('reservation_id')->nullable()->constrained('reservations')->nullOnDelete();
            $t->timestamp('borrowed_at'); $t->date('due_date'); $t->timestamp('returned_at')->nullable();
            $t->unsignedInteger('renewals')->default(0); $t->text('notes')->nullable(); $t->timestamps();
            $t->index(['user_id', 'returned_at']); $t->index(['book_copy_id', 'returned_at']);
        });
        Schema::create('incidents', function (Blueprint $t) {
            $t->id(); $t->foreignId('loan_id')->constrained()->cascadeOnDelete();
            $t->string('type', 20); $t->text('notes')->nullable();
            $t->string('resolution')->nullable(); $t->string('status', 20)->default('Open');
            $t->timestamps();
        });
        Schema::create('research_documents', function (Blueprint $t) {
            $t->id(); $t->string('title'); $t->string('authors'); $t->string('course')->nullable();
            $t->unsignedSmallInteger('year')->nullable(); $t->text('abstract')->nullable();
            $t->string('file_path'); $t->boolean('public')->default(false);
            $t->foreignId('uploaded_by')->constrained('users')->restrictOnDelete(); $t->timestamps();
        });
        Schema::create('library_notifications', function (Blueprint $t) {
            $t->id(); $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('message'); $t->timestamp('read_at')->nullable(); $t->timestamps();
        });
        Schema::create('audit_logs', function (Blueprint $t) {
            $t->id(); $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->string('action'); $t->string('entity')->nullable(); $t->unsignedBigInteger('entity_id')->nullable();
            $t->json('details')->nullable(); $t->timestamp('created_at')->useCurrent();
        });
        Schema::create('library_settings', function (Blueprint $t) { $t->string('key')->primary(); $t->string('value'); });
    }
    public function down(): void {
        foreach (['library_settings','audit_logs','library_notifications','research_documents','incidents','loans','reservations','book_copies','books'] as $name) Schema::dropIfExists($name);
        Schema::table('users', fn(Blueprint $t) => $t->dropColumn(['student_id','role','course','section','patron_type','active']));
    }
};
