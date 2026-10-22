<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class ActivityLogPolicy extends AdminPolicy
{
    public function viewAny(User $u): bool
    {
        return $u->isAdmin() && $u->can('audit.view');
    }

    public function view(User $u, Model $r): bool
    {
        return $this->viewAny($u);
    }

    public function create(User $u): bool
    {
        return false;
    }

    public function update(User $u, Model $r): bool
    {
        return false;
    }
}
