<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('candidates', function (Blueprint $table) {
            $table->string('assessment_progress')->default('Not confirmed')->index();
            $table->timestamp('assessment_reported_completed_at')->nullable();
        });
        Schema::table('follow_ups', function (Blueprint $table) {
            $table->string('purpose')->default('General')->index();
        });
        Schema::table('call_logs', function (Blueprint $table) {
            $table->uuid('submission_token')->nullable()->unique();
        });
    }

    public function down(): void
    {
        Schema::table('call_logs', fn (Blueprint $table) => $table->dropColumn('submission_token'));
        Schema::table('follow_ups', fn (Blueprint $table) => $table->dropColumn('purpose'));
        Schema::table('candidates', fn (Blueprint $table) => $table->dropColumn(['assessment_progress', 'assessment_reported_completed_at']));
    }
};
