<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Candidate extends Model
{
    use SoftDeletes;

    protected $guarded = ['id', 'candidate_code', 'mobile_normalized', 'assigned_hr_id', 'created_by', 'updated_by'];

    protected function casts(): array
    {
        return ['entry_date' => 'date', 'current_ctc' => 'decimal:2', 'expected_ctc' => 'decimal:2'];
    }

    public function scopeVisibleTo(Builder $query, ?User $user): Builder
    {
        if (! $user?->is_active || ! $user->hasAnyRole(['Super Admin', 'HR Executive']) || ! $user->can('candidates.view')) {
            return $query->whereRaw('1 = 0');
        }

        return $user->isAdmin() ? $query : $query->where('assigned_hr_id', $user->id);
    }

    public function jobPosition()
    {
        return $this->belongsTo(JobPosition::class);
    }

    public function assignedHr()
    {
        return $this->belongsTo(User::class, 'assigned_hr_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function interviewRounds()
    {
        return $this->hasMany(InterviewRound::class)->orderBy('round_number');
    }

    public function callLogs()
    {
        return $this->hasMany(CallLog::class);
    }

    public function followUps()
    {
        return $this->hasMany(FollowUp::class);
    }

    public function documents()
    {
        return $this->hasMany(CandidateDocument::class);
    }

    public function assignments()
    {
        return $this->hasMany(CandidateAssignment::class);
    }
}
