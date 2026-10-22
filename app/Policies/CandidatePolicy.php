<?php

namespace App\Policies;

use App\Models\Candidate;
use App\Models\User;

class CandidatePolicy
{
    public function viewAny(User $u): bool
    {
        return $u->is_active && $u->can('candidates.view');
    }

    public function view(User $u, Candidate $c): bool
    {
        return $this->viewAny($u) && ($u->isAdmin() || (! $c->trashed() && $c->assigned_hr_id === $u->id));
    }

    public function create(User $u): bool
    {
        return $u->is_active && $u->can('candidates.create');
    }

    public function update(User $u, Candidate $c): bool
    {
        return ! $c->trashed() && $this->view($u, $c) && $u->can('candidates.update');
    }

    public function delete(User $u, Candidate $c): bool
    {
        return $u->isAdmin() && $u->can('candidates.delete');
    }

    public function restore(User $u, Candidate $c): bool
    {
        return $u->isAdmin() && $u->can('candidates.restore');
    }

    public function reassign(User $u, Candidate $c): bool
    {
        return ! $c->trashed() && $u->isAdmin() && $u->can('candidates.assign');
    }

    public function forceDelete(User $u, Candidate $c): bool
    {
        return false;
    }
}
