<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CandidateAssignment extends Model
{
    use Concerns\BelongsToCandidate;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['assigned_at' => 'datetime'];
    }

    public function previousHr()
    {
        return $this->belongsTo(User::class, 'previous_hr_id');
    }

    public function newHr()
    {
        return $this->belongsTo(User::class, 'new_hr_id');
    }

    public function assignedBy()
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }
}
