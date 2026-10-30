<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_templates', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->unsignedTinyInteger('round_number');
            $table->string('purpose');
            $table->string('subject', 200);
            $table->text('body');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
        Schema::table('candidate_email_batches', function (Blueprint $table) {
            $table->foreignId('email_template_id')->nullable()->constrained('email_templates');
            $table->text('body')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('candidate_email_batches', function (Blueprint $table) {
            $table->dropConstrainedForeignId('email_template_id');
            $table->dropColumn('body');
        });
        Schema::dropIfExists('email_templates');
    }
};
