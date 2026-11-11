<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('offer_letters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('candidate_id')->unique()->constrained();
            $table->foreignId('created_by')->constrained('users');
            $table->uuid('revision');
            $table->json('details');
            $table->string('recipient');
            $table->string('status')->default('draft');
            $table->string('pdf_path');
            $table->string('pdf_sha256', 64);
            $table->timestamp('submitted_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('offer_letters');
    }
};
