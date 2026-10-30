<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('email_templates', fn (Blueprint $table) => $table->string('body_format')->default('plain'));
        Schema::table('candidate_email_batches', fn (Blueprint $table) => $table->string('body_format')->default('plain'));
    }

    public function down(): void
    {
        Schema::table('email_templates', fn (Blueprint $table) => $table->dropColumn('body_format'));
        Schema::table('candidate_email_batches', fn (Blueprint $table) => $table->dropColumn('body_format'));
    }
};
