<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CallLog extends Model
{
    use Concerns\BelongsToCandidate;

    protected $guarded = ['id', 'candidate_id', 'hr_id'];

    protected function casts(): array
    {
        return ['called_at' => 'datetime'];
    }

    public function hr()
    {
        return $this->belongsTo(User::class, 'hr_id');
    }
}
