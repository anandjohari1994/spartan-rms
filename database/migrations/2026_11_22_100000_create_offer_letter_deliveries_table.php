<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('offer_letter_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('offer_letter_id')->constrained();
            $table->uuid('token')->unique();
            $table->uuid('revision');
            $table->foreignId('sent_by')->nullable()->constrained('users');
            $table->string('recipient');
            $table->json('details');
            $table->string('pdf_path');
            $table->string('pdf_sha256', 64);
            $table->string('status');
            $table->timestamp('submitted_at')->nullable()->index();
            $table->timestamps();
            $table->index(['offer_letter_id', 'status']);
        });

        // Preserve known past submissions before admins can revise their current draft.
        DB::table('offer_letters')->whereIn('status', ['submitted', 'sending', 'uncertain'])->orderBy('id')->chunkById(200, function ($offers) {
            foreach ($offers as $offer) {
                if ($offer->status === 'submitted' && ! $offer->submitted_at) {
                    $offer->submitted_at = $offer->updated_at;
                    DB::table('offer_letters')->where('id', $offer->id)->update(['submitted_at' => $offer->submitted_at]);
                }
                DB::table('offer_letter_deliveries')->insert([
                    'offer_letter_id' => $offer->id, 'token' => (string) Str::uuid(), 'revision' => $offer->revision,
                    'sent_by' => null, 'recipient' => $offer->recipient, 'details' => $offer->details,
                    'pdf_path' => $offer->pdf_path, 'pdf_sha256' => $offer->pdf_sha256, 'status' => $offer->status,
                    'submitted_at' => $offer->status === 'submitted' ? ($offer->submitted_at ?? $offer->updated_at) : null,
                    'created_at' => $offer->created_at, 'updated_at' => $offer->updated_at,
                ]);
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('offer_letter_deliveries');
    }
};
