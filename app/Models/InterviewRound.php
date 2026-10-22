<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InterviewRound extends Model
{
    use Concerns\BelongsToCandidate;

    protected $guarded = ['id', 'candidate_id', 'round_number', 'round_type', 'updated_by'];

    protected function casts(): array
    {
        return ['scheduled_at' => 'datetime', 'completed_at' => 'datetime', 'score' => 'decimal:2'];
    }

    public function updater()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
