<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class UserService
{
    public function save(User $actor, array $data, ?User $user = null): User
    {
        abort_unless($actor->isAdmin() && $actor->can('users.manage'), 403);

        return DB::transaction(function () use ($actor, $data, $user) {
            // Serialize admin changes to protect the last active administrator.
            User::role('Super Admin')->orderBy('id')->lockForUpdate()->get();
            $u = $user ? User::whereKey($user->id)->lockForUpdate()->firstOrFail() : new User;
            $v = Validator::make($data, ['name' => 'required|string|max:255', 'email' => ['required', 'email', 'max:255', Rule::unique('users')->ignore($u->id)], 'mobile' => 'nullable|string|max:20', 'password' => [$u->exists ? 'nullable' : 'required', Password::min(12)->mixedCase()->numbers()], 'is_active' => 'required|boolean', 'role' => ['required', Rule::in(['Super Admin', 'HR Executive'])]])->validate();
            if ($u->id === $actor->id && (! $v['is_active'] || $v['role'] !== 'Super Admin')) {
                throw ValidationException::withMessages(['role' => 'You cannot deactivate or demote your own administrator account.']);
            }
            if ($u->exists && $u->isAdmin() && (! $v['is_active'] || $v['role'] !== 'Super Admin') && User::role('Super Admin')->where('is_active', true)->count() <= 1) {
                throw ValidationException::withMessages(['role' => 'Keep at least one active administrator.']);
            }
            $old = $u->only(['name', 'email', 'mobile', 'is_active']);
            $oldRoles = $u->exists ? $u->getRoleNames()->all() : [];
            $role = $v['role'];
            unset($v['role']);
            if (empty($v['password'])) {
                unset($v['password']);
            }
            $exists = $u->exists;
            $u->fill($v)->save();
            $u->syncRoles([$role]);
            if (isset($v['password']) || ! $u->is_active) {
                $u->forceFill(['remember_token' => null])->save();
                DB::table('sessions')->where('user_id', $u->id)->delete();
            }
            app(AuditService::class)->record($actor, $u, $exists ? 'user.updated' : 'user.created', $old, $u->only(['name', 'email', 'mobile', 'is_active']));
            if ($oldRoles !== [$role]) {
                app(AuditService::class)->record($actor, $u, 'permission.changed', ['roles' => $oldRoles], ['roles' => [$role]]);
            }

            return $u;
        }, 3);
    }
}
