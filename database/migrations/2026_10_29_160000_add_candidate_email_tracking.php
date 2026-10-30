<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('candidates', function (Blueprint $table) {
            $table->timestamp('last_email_sent_at')->nullable()->index();
            $table->timestamp('round_one_email_sent_at')->nullable()->index();
        });

        // Existing submitted batches establish submission history, not inbox delivery.
        $roundOneTemplates = DB::table('email_templates')->where('round_number', 1)->where('purpose', 'Invitation')->pluck('id')->all();
        DB::table('candidate_email_batches')->where('status', 'submitted')->orderBy('id')->chunkById(200, function ($batches) use ($roundOneTemplates) {
            foreach ($batches as $batch) {
                $ids = json_decode($batch->candidate_ids, true);
                if (! is_array($ids) || ! $ids || ! $batch->updated_at) {
                    continue;
                }
                $fields = ['last_email_sent_at'];
                if (in_array($batch->email_template_id, $roundOneTemplates)) {
                    $fields[] = 'round_one_email_sent_at';
                }
                foreach ($fields as $field) {
                    DB::table('candidates')->whereIn('id', $ids)
                        ->where(fn ($query) => $query->whereNull($field)->orWhere($field, '<', $batch->updated_at))
                        ->update([$field => $batch->updated_at]);
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('candidates', function (Blueprint $table) {
            $table->dropIndex(['last_email_sent_at']);
            $table->dropIndex(['round_one_email_sent_at']);
            $table->dropColumn(['last_email_sent_at', 'round_one_email_sent_at']);
        });
    }
};
