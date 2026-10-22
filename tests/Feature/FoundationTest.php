<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_roles_positions_and_active_account_access(): void
    {
        $this->seed(DatabaseSeeder::class);
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('HR Executive');
        $this->assertTrue($user->can('candidates.create'));
        $this->assertFalse($user->can('reports.export'));
        $this->assertTrue($user->canAccessPanel(Filament::getPanel('admin')));
        $user->update(['is_active' => false]);
        $this->assertFalse($user->canAccessPanel(Filament::getPanel('admin')));
        $this->assertDatabaseCount('job_positions', 6);
    }
}
