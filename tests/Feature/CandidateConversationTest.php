<?php

namespace Tests\Feature;

use App\Filament\Resources\CandidateResource;
use App\Filament\Resources\CandidateResource\Pages\CreateCandidate;
use App\Filament\Resources\CandidateResource\Pages\ListCandidates;
use App\Filament\Resources\CandidateResource\Pages\ViewCandidate;
use App\Filament\Resources\FollowUpResource\Pages\ListFollowUps;
use App\Models\Candidate;
use App\Models\JobPosition;
use App\Models\User;
use App\Services\CandidateConversationService;
use App\Services\CandidateService;
use App\Services\FollowUpService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class CandidateConversationTest extends TestCase
{
    use RefreshDatabase;

    private User $hr;

    private Candidate $candidate;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->hr = User::factory()->create(['is_active' => true]);
        $this->hr->assignRole('HR Executive');
        $this->candidate = app(CandidateService::class)->create($this->hr, $this->profile());
        $this->actingAs($this->hr);
    }

    private function profile(): array
    {
        return ['full_name' => 'Quick candidate', 'mobile' => '9876543210', 'job_position_id' => JobPosition::first()->id];
    }

    private function conversation(array $extra = []): array
    {
        return [...['token' => (string) Str::uuid(), 'called_at' => now()->toDateTimeString(), 'call_status' => 'Connected', 'interest_status' => 'Interested', 'notes' => 'Discussed role', 'next_action' => 'Call again', 'next_at' => now()->addDay()->toDateTimeString()], ...$extra];
    }

    public function test_candidate_entry_date_filter_supports_inclusive_and_open_ranges_and_reset(): void
    {
        $early = app(CandidateService::class)->create($this->hr, [...$this->profile(), 'mobile' => '9876543211', 'entry_date' => today()->subDays(2)->toDateString()]);
        $middle = app(CandidateService::class)->create($this->hr, [...$this->profile(), 'mobile' => '9876543212', 'entry_date' => today()->subDay()->toDateString()]);
        $page = Livewire::test(ListCandidates::class);
        $page->filterTable('entry_date', ['from' => $early->entry_date->toDateString(), 'until' => $middle->entry_date->toDateString()])
            ->assertCanSeeTableRecords([$early, $middle])->assertCanNotSeeTableRecords([$this->candidate]);
        $page->filterTable('entry_date', ['from' => today()->toDateString(), 'until' => today()->toDateString()])
            ->assertCanSeeTableRecords([$this->candidate])->assertCanNotSeeTableRecords([$early, $middle]);
        $page->filterTable('entry_date', ['from' => $middle->entry_date->toDateString(), 'until' => null])
            ->assertCanSeeTableRecords([$middle, $this->candidate])->assertCanNotSeeTableRecords([$early]);
        $page->filterTable('entry_date', ['from' => null, 'until' => $early->entry_date->toDateString()])
            ->assertCanSeeTableRecords([$early])->assertCanNotSeeTableRecords([$middle, $this->candidate]);
        $page->resetTableFilters()->assertCanSeeTableRecords([$early, $middle, $this->candidate]);
    }

    public function test_date_and_location_filters_combine_with_daily_tab_and_ownership(): void
    {
        $this->candidate->update(['location' => 'Pune', 'interest_status' => 'Interested']);
        $other = User::factory()->create(['is_active' => true]);
        $other->assignRole('HR Executive');
        $hidden = app(CandidateService::class)->create($other, [...$this->profile(), 'mobile' => '9876543211', 'location' => 'Pune', 'interest_status' => 'Interested']);
        Livewire::test(ListCandidates::class)->set('activeTab', 'interested')
            ->filterTable('entry_date', ['from' => today()->toDateString(), 'until' => today()->toDateString()])
            ->filterTable('location', ['location' => 'Pune'])->assertCanSeeTableRecords([$this->candidate])->assertCanNotSeeTableRecords([$hidden])
            ->filterTable('location', ['location' => 'Mumbai'])->assertCanNotSeeTableRecords([$this->candidate, $hidden]);
    }

    public function test_minimal_profile_defaults_and_later_completion(): void
    {
        $this->assertNull($this->candidate->qualification);
        $this->assertNull($this->candidate->passout_year);
        $this->assertSame('Unknown', $this->candidate->experience_type);
        $this->assertDatabaseCount('call_logs', 0);
        $updated = app(CandidateService::class)->update($this->hr, $this->candidate, [...$this->candidate->getAttributes(), 'qualification' => 'B.Tech', 'passout_year' => 2024, 'location' => 'Pune']);
        $this->assertSame('B.Tech', $updated->qualification);
    }

    public function test_save_and_record_call_redirects_to_candidate_action_without_inventing_a_call(): void
    {
        Livewire::test(CreateCandidate::class)->fillForm([...$this->profile(), 'mobile' => '9876543211'])->call('saveAndCall')->assertHasNoErrors()
            ->assertRedirect(CandidateResource::getUrl('view', ['record' => Candidate::latest('id')->first(), 'action' => 'recordCall']));
        $created = Candidate::latest('id')->first();
        $this->assertDatabaseCount('call_logs', 0);
        Livewire::withQueryParams(['action' => 'recordCall'])->test(ViewCandidate::class, ['record' => $created->id])->assertActionExists('recordCall');
    }

    public function test_combined_call_is_atomic_and_idempotent(): void
    {
        $data = $this->conversation();
        $service = app(CandidateConversationService::class);
        $service->record($this->hr, $this->candidate, $data);
        $service->record($this->hr, $this->candidate, $data);
        $this->assertDatabaseCount('call_logs', 1);
        $this->assertDatabaseCount('follow_ups', 1);
        $this->assertSame('Interested', $this->candidate->fresh()->interest_status);
        $this->assertSame('Connected', $this->candidate->fresh()->calling_status);
        $this->assertSame('General', $this->candidate->nextFollowUp->purpose);
    }

    public function test_follow_up_action_closes_only_the_source_and_creates_next_task(): void
    {
        $taskData = ['candidate_id' => $this->candidate->id, 'scheduled_at' => now()->subHour(), 'priority' => 'High', 'status' => 'Pending', 'notes' => 'Previous discussion'];
        $source = app(FollowUpService::class)->save($this->hr, $taskData);
        $unrelated = app(FollowUpService::class)->save($this->hr, $taskData);
        Livewire::test(ListFollowUps::class)->callTableAction('recordCall', $source, $this->conversation())->assertHasNoErrors();
        $this->assertSame('Completed', $source->fresh()->status);
        $this->assertSame('Pending', $unrelated->fresh()->status);
        $this->assertSame('Previous discussion', $unrelated->fresh()->notes);
        $this->assertDatabaseCount('follow_ups', 3);
    }

    public function test_interview_gates_rollback_call_and_scheduling_succeeds_for_round_one(): void
    {
        $service = app(CandidateConversationService::class);
        try {
            $service->record($this->hr, $this->candidate, $this->conversation(['next_action' => 'Schedule Round 2', 'scheduled_at' => now()->addDay()]));
            $this->fail('Skipped round accepted');
        } catch (ValidationException) {
            $this->assertDatabaseCount('call_logs', 0);
            $this->assertDatabaseCount('follow_ups', 0);
        }
        Livewire::test(ListCandidates::class)->callTableAction('recordCall', $this->candidate, $this->conversation(['next_action' => 'Schedule Round 1', 'scheduled_at' => now()->addDay()->toDateTimeString()]))->assertHasNoErrors();
        $this->assertSame('Scheduled', $this->candidate->interviewRounds()->first()->status);
        $this->assertSame('Assessment completion', $this->candidate->followUps()->first()->purpose);
    }

    public function test_daily_tabs_preserve_ownership_and_show_tasks_and_interviews(): void
    {
        $other = User::factory()->create(['is_active' => true]);
        $other->assignRole('HR Executive');
        $hidden = app(CandidateService::class)->create($other, [...$this->profile(), 'mobile' => '9876543211']);
        Livewire::test(ListCandidates::class)->set('activeTab', 'not_called')->assertCanSeeTableRecords([$this->candidate])->assertCanNotSeeTableRecords([$hidden]);
        app(FollowUpService::class)->save($this->hr, ['candidate_id' => $this->candidate->id, 'scheduled_at' => now()->subMinute(), 'priority' => 'High', 'status' => 'Pending']);
        foreach (['today', 'overdue'] as $tab) {
            Livewire::test(ListCandidates::class)->set('activeTab', $tab)->assertCanSeeTableRecords([$this->candidate])->assertCanNotSeeTableRecords([$hidden]);
        }
        app(CandidateConversationService::class)->record($this->hr, $this->candidate, $this->conversation(['next_action' => 'Schedule Round 1', 'scheduled_at' => now()->addDay()]));
        foreach (['interested', 'interviews'] as $tab) {
            Livewire::test(ListCandidates::class)->set('activeTab', $tab)->assertCanSeeTableRecords([$this->candidate])->assertCanNotSeeTableRecords([$hidden]);
        }
        Livewire::test(ListCandidates::class)->set('activeTab', 'not_called')->assertCanNotSeeTableRecords([$this->candidate]);
    }

    public function test_unconnected_call_cannot_change_interest_and_other_hr_is_denied(): void
    {
        try {
            app(CandidateConversationService::class)->record($this->hr, $this->candidate, $this->conversation(['call_status' => 'Busy']));
            $this->fail('Interest changed without conversation');
        } catch (ValidationException) {
            $this->assertDatabaseCount('call_logs', 0);
        }
        $other = User::factory()->create(['is_active' => true]);
        $other->assignRole('HR Executive');
        $this->expectException(AuthorizationException::class);
        app(CandidateConversationService::class)->record($other, $this->candidate, $this->conversation());
    }
}
