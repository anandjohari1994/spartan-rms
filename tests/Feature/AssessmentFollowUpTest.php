<?php

namespace Tests\Feature;

use App\Filament\Resources\CandidateResource\Pages\ListCandidates;
use App\Filament\Resources\FollowUpResource\Pages\ListFollowUps;
use App\Models\Candidate;
use App\Models\JobPosition;
use App\Models\User;
use App\Services\AssessmentFollowUpService;
use App\Services\CandidateService;
use App\Services\FollowUpService;
use App\Services\InterviewService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class AssessmentFollowUpTest extends TestCase
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
        $this->candidate = app(CandidateService::class)->create($this->hr, [
            'entry_date' => today()->toDateString(), 'full_name' => 'Assessment Candidate', 'mobile' => '9876543210',
            'qualification' => 'B.Tech', 'passout_year' => 2024, 'location' => 'Pune', 'job_position_id' => JobPosition::first()->id,
            'experience_type' => 'Fresher', 'calling_status' => 'Not Called', 'interest_status' => 'Interested',
        ]);
    }

    private function data(string $response = 'Completed'): array
    {
        return ['token' => (string) Str::uuid(), 'response' => $response, 'called_at' => now()->toDateTimeString(), 'next_at' => now()->addDay()->toDateTimeString(), 'notes' => 'Candidate contacted'];
    }

    private function task(string $purpose = 'Assessment completion')
    {
        return app(FollowUpService::class)->save($this->hr, ['candidate_id' => $this->candidate->id, 'purpose' => $purpose, 'scheduled_at' => now()->subHour(), 'priority' => 'Medium', 'status' => 'Pending']);
    }

    public function test_completion_records_call_and_next_task_without_passing_round_and_is_idempotent(): void
    {
        $source = $this->task();
        $other = $this->task('General');
        $data = $this->data();
        $service = app(AssessmentFollowUpService::class);
        $service->record($this->hr, $this->candidate, $data, $source);
        $service->record($this->hr, $this->candidate, $data, $source);
        $this->assertSame('Completed', $source->fresh()->status);
        $this->assertSame('Pending', $other->fresh()->status);
        $this->assertSame('Reported completed', $this->candidate->fresh()->assessment_progress);
        $this->assertNotNull($this->candidate->fresh()->assessment_reported_completed_at);
        $this->assertDatabaseCount('call_logs', 1);
        $this->assertSame(1, $this->candidate->followUps()->where('purpose', 'Assessment result')->where('status', 'Pending')->count());
        $this->assertSame('Pending', $this->candidate->interviewRounds()->first()->status);
        $this->expectException(ValidationException::class);
        app(InterviewService::class)->update($this->hr, $this->candidate->interviewRounds()->where('round_number', 2)->first(), ['status' => 'Scheduled', 'scheduled_at' => now()->addDays(2)]);
    }

    public function test_follow_up_table_action_saves_one_call_and_reuses_next_reminder(): void
    {
        $source = $this->task();
        $next = $this->task();
        $this->actingAs($this->hr);
        Livewire::test(ListFollowUps::class)->callTableAction('assessmentFollowUp', $source, $this->data('Not completed yet'))->assertHasNoErrors();
        $this->assertSame('Completed', $source->fresh()->status);
        $this->assertTrue($next->fresh()->scheduled_at->isFuture());
        $this->assertSame('Not completed', $this->candidate->fresh()->assessment_progress);
        $this->assertDatabaseCount('call_logs', 1);
    }

    public function test_candidate_action_and_awaiting_result_filter(): void
    {
        $this->actingAs($this->hr);
        Livewire::test(ListCandidates::class)->callTableAction('assessmentFollowUp', $this->candidate, $this->data())->assertHasNoErrors();
        Livewire::test(ListCandidates::class)->filterTable('assessment_awaiting_result')->assertCanSeeTableRecords([$this->candidate]);
        Livewire::test(ListCandidates::class)->filterTable('assessment_pending')->assertCanNotSeeTableRecords([$this->candidate]);
        $round = $this->candidate->interviewRounds()->first();
        app(InterviewService::class)->update($this->hr, $round, ['status' => 'Passed', 'score' => 80]);
        $this->assertSame(0, $this->candidate->followUps()->where('status', 'Pending')->count());
        Livewire::test(ListCandidates::class)->filterTable('assessment_awaiting_result')->assertCanNotSeeTableRecords([$this->candidate]);
    }

    public function test_not_reachable_preserves_progress_and_not_interested_requires_explicit_cancellation(): void
    {
        $task = $this->task();
        $service = app(AssessmentFollowUpService::class);
        $service->record($this->hr, $this->candidate, $this->data('Not reachable'));
        $this->assertSame('Not confirmed', $this->candidate->fresh()->assessment_progress);
        $this->assertSame('Not Reachable', $this->candidate->fresh()->calling_status);
        $service->record($this->hr, $this->candidate, $this->data('Not interested'));
        $this->assertSame('Pending', $task->fresh()->status);
        $service->record($this->hr, $this->candidate, [...$this->data('Not interested'), 'cancel_reminders' => true]);
        $this->assertSame('Cancelled', $task->fresh()->status);
        $this->assertSame('Not Interested', $this->candidate->fresh()->interest_status);
    }

    public function test_missing_next_date_or_problem_notes_does_not_partially_save(): void
    {
        foreach ([['next_at' => null], ['response' => 'Facing a problem', 'notes' => null]] as $invalid) {
            try {
                app(AssessmentFollowUpService::class)->record($this->hr, $this->candidate, [...$this->data(), ...$invalid]);
                $this->fail('Invalid outcome accepted.');
            } catch (ValidationException) {
                $this->assertDatabaseCount('call_logs', 0);
                $this->assertSame('Not confirmed', $this->candidate->fresh()->assessment_progress);
            }
        }
    }

    public function test_other_hr_cannot_record_outcome(): void
    {
        $other = User::factory()->create(['is_active' => true]);
        $other->assignRole('HR Executive');
        $this->expectException(AuthorizationException::class);
        app(AssessmentFollowUpService::class)->record($other, $this->candidate, $this->data());
    }

    public function test_closed_source_task_cannot_be_completed_twice_with_new_submission(): void
    {
        $task = $this->task();
        app(FollowUpService::class)->complete($this->hr, $task);
        try {
            app(AssessmentFollowUpService::class)->record($this->hr, $this->candidate, $this->data(), $task);
            $this->fail('Closed task accepted.');
        } catch (ValidationException) {
            $this->assertDatabaseCount('call_logs', 0);
        }
    }
}
