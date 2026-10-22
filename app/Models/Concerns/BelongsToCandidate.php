<?php

namespace App\Models\Concerns;

use App\Models\Candidate;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

trait BelongsToCandidate
{
    public function candidate()
    {
        return $this->belongsTo(Candidate::class);
    }

    public function scopeVisibleTo(Builder $query, ?User $user): Builder
    {
        return $query->whereHas('candidate', fn (Builder $q) => $q->visibleTo($user));
    }
}
