<?php

namespace App\Policies;

use App\Models\CallLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class CandidateRecordPolicy
{
    public function viewAny(User $u): bool
    {
        return $u->is_active && $u->can('candidates.view');
    }

    public function view(User $u, Model $r): bool
    {
        return $r->candidate && ! $r->candidate->trashed() && $u->can('view', $r->candidate);
    }

    public function create(User $u): bool
    {
        return $u->is_active && $u->can('workflow.manage');
    }

    public function update(User $u, Model $r): bool
    {
        return $this->view($u, $r) && $u->can('workflow.manage') && ! ($r instanceof CallLog);
    }

    public function delete(User $u, Model $r): bool
    {
        return false;
    }
}
