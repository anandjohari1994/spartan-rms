<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FollowUp extends Model
{
    use Concerns\BelongsToCandidate;

    protected $guarded = ['id', 'candidate_id', 'assigned_hr_id', 'created_by', 'completed_at'];

    protected function casts(): array
    {
        return ['scheduled_at' => 'datetime', 'completed_at' => 'datetime'];
    }

    public function getIsOverdueAttribute(): bool
    {
        return $this->status === 'Pending' && $this->scheduled_at->isPast();
    }

    public function assignedHr()
    {
        return $this->belongsTo(User::class, 'assigned_hr_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
