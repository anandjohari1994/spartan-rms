<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $hr = ['candidates.view', 'candidates.create', 'candidates.update', 'workflow.manage', 'documents.access'];
        $all = [...$hr, 'candidates.delete', 'candidates.restore', 'candidates.assign', 'users.manage', 'positions.manage', 'reports.view', 'reports.export', 'audit.view', 'settings.manage'];
        foreach ($all as $name) {
            Permission::findOrCreate($name, 'web');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Role::findOrCreate('Super Admin', 'web')->syncPermissions($all);
        Role::findOrCreate('HR Executive', 'web')->syncPermissions($hr);
    }
}
