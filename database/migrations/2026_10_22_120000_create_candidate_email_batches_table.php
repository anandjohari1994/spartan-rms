<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('candidate_email_batches', function (Blueprint $table) {
            $table->id();
            $table->uuid('token')->unique();
            $table->foreignId('actor_id')->constrained('users');
            $table->json('candidate_ids');
            $table->unsignedInteger('recipient_count');
            $table->string('subject');
            $table->string('status')->default('sending');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('candidate_email_batches');
    }
};
