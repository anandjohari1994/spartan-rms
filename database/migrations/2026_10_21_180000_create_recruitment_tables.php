<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->string('mobile', 20)->nullable();
            $t->boolean('is_active')->default(true)->index();
            $t->timestamp('last_login_at')->nullable();
        });
        Schema::create('job_positions', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('code', 40)->unique();
            $t->text('description')->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });
        Schema::create('candidate_code_sequences', function (Blueprint $t) {
            $t->unsignedSmallInteger('year')->primary();
            $t->unsignedBigInteger('next_number')->default(1);
        });
        Schema::create('candidates', function (Blueprint $t) {
            $t->id();
            $t->string('candidate_code', 30)->unique();
            $t->date('entry_date')->index();
            $t->string('full_name');
            $t->string('mobile_country_code', 5)->default('+91');
            $t->string('mobile', 15);
            $t->string('mobile_normalized', 20)->unique();
            $t->string('email')->nullable();
            $t->string('qualification');
            $t->unsignedSmallInteger('passout_year');
            $t->string('location', 120)->index();
            $t->foreignId('job_position_id')->constrained()->restrictOnDelete();
            $t->string('experience_type', 20);
            $t->decimal('current_ctc', 12, 2)->nullable();
            $t->decimal('expected_ctc', 12, 2)->nullable();
            $t->string('recruitment_status', 30)->default('New')->index();
            $t->string('calling_status', 30)->default('Not Called')->index();
            $t->string('interest_status', 30)->default('Unknown');
            $t->string('pan_verification_status', 30)->nullable();
            $t->foreignId('assigned_hr_id')->constrained('users')->restrictOnDelete();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->foreignId('updated_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->text('remarks')->nullable();
            $t->timestamps();
            $t->softDeletes();
            $t->index(['assigned_hr_id', 'recruitment_status', 'deleted_at'], 'candidates_owner_status');
        });
        Schema::create('interview_rounds', function (Blueprint $t) {
            $t->id();
            $t->foreignId('candidate_id')->constrained()->restrictOnDelete();
            $t->unsignedTinyInteger('round_number');
            $t->string('round_type');
            $t->string('status', 30)->index();
            $t->dateTime('scheduled_at')->nullable()->index();
            $t->dateTime('completed_at')->nullable();
            $t->decimal('score', 5, 2)->nullable();
            $t->text('remarks')->nullable();
            $t->foreignId('updated_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamps();
            $t->unique(['candidate_id', 'round_number']);
        });
        Schema::create('call_logs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('candidate_id')->constrained()->restrictOnDelete();
            $t->foreignId('hr_id')->constrained('users')->restrictOnDelete();
            $t->string('call_status', 30);
            $t->dateTime('called_at')->index();
            $t->unsignedInteger('duration_seconds')->nullable();
            $t->text('notes')->nullable();
            $t->timestamps();
            $t->index(['hr_id', 'called_at']);
        });
        Schema::create('follow_ups', function (Blueprint $t) {
            $t->id();
            $t->foreignId('candidate_id')->constrained()->restrictOnDelete();
            $t->foreignId('assigned_hr_id')->constrained('users')->restrictOnDelete();
            $t->dateTime('scheduled_at');
            $t->string('status', 20)->default('Pending');
            $t->string('priority', 10)->default('Medium');
            $t->text('notes')->nullable();
            $t->dateTime('completed_at')->nullable();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->timestamps();
            $t->index(['status', 'scheduled_at']);
            $t->index(['assigned_hr_id', 'status', 'scheduled_at'], 'followups_owner_due');
        });
        Schema::create('candidate_documents', function (Blueprint $t) {
            $t->id();
            $t->foreignId('candidate_id')->constrained()->restrictOnDelete();
            $t->string('document_type', 30)->default('Resume');
            $t->string('private_path')->unique();
            $t->string('original_name');
            $t->string('mime_type', 120);
            $t->unsignedBigInteger('file_size');
            $t->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $t->timestamps();
        });
        Schema::create('candidate_assignments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('candidate_id')->constrained()->restrictOnDelete();
            $t->foreignId('previous_hr_id')->nullable()->constrained('users')->restrictOnDelete();
            $t->foreignId('new_hr_id')->constrained('users')->restrictOnDelete();
            $t->foreignId('assigned_by')->constrained('users')->restrictOnDelete();
            $t->text('reason')->nullable();
            $t->dateTime('assigned_at');
            $t->timestamps();
        });
        Schema::create('activity_logs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $t->string('subject_type');
            $t->unsignedBigInteger('subject_id');
            $t->string('event', 80)->index();
            $t->json('old_values')->nullable();
            $t->json('new_values')->nullable();
            $t->string('ip_address', 45)->nullable();
            $t->timestamp('created_at')->useCurrent()->index();
            $t->index(['subject_type', 'subject_id']);
        });
        Schema::create('application_settings', function (Blueprint $t) {
            $t->string('key')->primary();
            $t->text('value')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['application_settings', 'activity_logs', 'candidate_assignments', 'candidate_documents', 'follow_ups', 'call_logs', 'interview_rounds', 'candidates', 'candidate_code_sequences', 'job_positions'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn(['mobile', 'is_active', 'last_login_at']));
    }
};
