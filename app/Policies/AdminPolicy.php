<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class AdminPolicy
{
    public function viewAny(User $u): bool
    {
        return $u->isAdmin();
    }

    public function view(User $u, Model $r): bool
    {
        return $u->isAdmin();
    }

    public function create(User $u): bool
    {
        return $u->isAdmin();
    }

    public function update(User $u, Model $r): bool
    {
        return $u->isAdmin();
    }

    public function delete(User $u, Model $r): bool
    {
        return false;
    }
}
