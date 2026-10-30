<?php

namespace Tests\Feature;

use App\Filament\Resources\CandidateResource\Pages\ListCandidates;
use App\Models\Candidate;
use App\Models\EmailTemplate;
use App\Models\JobPosition;
use App\Models\User;
use App\Services\CandidateOutreachService;
use App\Services\CandidateService;
use App\Services\InterviewService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class CandidateOutreachTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $hr;

    private ArrayTransport $transport;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole('Super Admin');
        $this->hr = User::factory()->create(['is_active' => true]);
        $this->hr->assignRole('HR Executive');
        $this->transport = new ArrayTransport;
        config(['mail.default' => 'smtp']);
        Mail::extend('smtp', fn () => $this->transport);
    }

    private function candidate(int $number, ?string $email = 'candidate@example.test'): Candidate
    {
        return app(CandidateService::class)->create($this->hr, [
            'entry_date' => today()->toDateString(), 'full_name' => "Candidate {$number}",
            'mobile' => '987654321'.$number, 'email' => $email, 'qualification' => 'B.Tech',
            'passout_year' => 2024, 'location' => 'Pune', 'job_position_id' => JobPosition::first()->id,
            'experience_type' => 'Fresher', 'calling_status' => 'Not Called', 'interest_status' => 'Interested',
        ]);
    }

    private function message(): array
    {
        return ['token' => (string) Str::uuid(), 'subject' => 'Assessment invitation', 'body' => "Hello,\nPlease attend at 10 AM IST.\nhttps://example.test/exam?a=1&b=2"];
    }

    public function test_one_message_contains_only_bcc_recipients_and_deduplicates_addresses(): void
    {
        $a = $this->candidate(0);
        $b = $this->candidate(1, 'second@example.test');
        $c = $this->candidate(2, 'CANDIDATE@example.test');
        $data = $this->message();
        $count = app(CandidateOutreachService::class)->email($this->admin, [$a->id, $b->id, $c->id], $data);
        $this->assertSame(2, $count);
        $this->assertCount(1, $this->transport->messages());
        $mail = $this->transport->messages()->first()->getOriginalMessage();
        $this->assertSame([], $mail->getTo());
        $this->assertSame([], $mail->getCc());
        $this->assertCount(2, $mail->getBcc());
        $this->assertStringContainsString($data['body'], $mail->getTextBody());
        $this->assertDatabaseHas('candidate_email_batches', ['token' => $data['token'], 'status' => 'submitted']);
        $this->assertDatabaseHas('activity_logs', ['event' => 'candidate.email_submitted', 'subject_id' => $a->id]);
        try {
            app(CandidateOutreachService::class)->email($this->admin, [$a->id], $data);
            $this->fail('Duplicate submission was accepted.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('already accepted by the mail provider', $e->getMessage());
            $this->assertCount(1, $this->transport->messages());
        }
    }

    public function test_invalid_email_blocks_the_whole_batch(): void
    {
        $a = $this->candidate(0);
        $b = $this->candidate(1, null);
        try {
            app(CandidateOutreachService::class)->email($this->admin, [$a->id, $b->id], $this->message());
            $this->fail('Missing email was accepted.');
        } catch (ValidationException) {
            $this->assertCount(0, $this->transport->messages());
            $this->assertDatabaseCount('candidate_email_batches', 0);
        }
    }

    public function test_logging_mailer_cannot_claim_delivery(): void
    {
        config(['mail.default' => 'log']);
        $a = $this->candidate(0);
        $this->expectException(ValidationException::class);
        app(CandidateOutreachService::class)->email($this->admin, [$a->id], $this->message());
    }

    public function test_hr_cannot_bulk_update_status_even_directly(): void
    {
        $a = $this->candidate(0);
        $this->expectException(HttpException::class);
        app(CandidateOutreachService::class)->bulkStatus($this->hr, [$a->id], 'Rejected', null);
    }

    public function test_bulk_status_is_atomic_and_keeps_interview_gates(): void
    {
        $a = $this->candidate(0);
        $b = $this->candidate(1);
        $a->interviewRounds()->update(['status' => 'Passed']);
        try {
            app(CandidateOutreachService::class)->bulkStatus($this->admin, [$a->id, $b->id], 'Selected', null);
            $this->fail('Interview gate bypassed.');
        } catch (ValidationException) {
            $this->assertSame('New', $a->fresh()->recruitment_status);
            $this->assertSame('New', $b->fresh()->recruitment_status);
        }
        $this->assertSame(2, app(CandidateOutreachService::class)->bulkStatus($this->admin, [$a->id, $b->id], 'Selected', 'Approved exception'));
        $this->assertSame('Selected', $b->fresh()->recruitment_status);
    }

    public function test_whatsapp_encodes_text_and_checks_ownership(): void
    {
        $a = $this->candidate(0);
        $text = "Hello & welcome\nAssessment: https://example.test/?a=1&b=2";
        $this->assertSame('https://wa.me/919876543210?text='.rawurlencode($text), app(CandidateOutreachService::class)->whatsappUrl($this->hr, $a, $text));
        $other = User::factory()->create(['is_active' => true]);
        $other->assignRole('HR Executive');
        $this->expectException(AuthorizationException::class);
        app(CandidateOutreachService::class)->whatsappUrl($other, $a, $text);
    }

    public function test_admin_bulk_actions_work_and_hr_has_email_but_not_bulk_status(): void
    {
        $a = $this->candidate(0);
        $this->actingAs($this->admin);
        Livewire::test(ListCandidates::class)
            ->callTableBulkAction('bulkStatus', [$a], ['status' => 'In Progress', 'reason' => 'Shortlisted'])
            ->assertHasNoErrors();
        $this->assertSame('In Progress', $a->fresh()->recruitment_status);
        Livewire::test(ListCandidates::class)
            ->callTableBulkAction('emailCandidates', [$a], $this->message())
            ->assertHasNoErrors();
        $this->assertCount(1, $this->transport->messages());
        $this->actingAs($this->hr);
        Livewire::test(ListCandidates::class)->assertTableBulkActionHidden('bulkStatus')->assertTableBulkActionVisible('emailCandidates');
    }

    public function test_hr_can_email_assigned_candidates_using_template_and_schedule_round_one(): void
    {
        $this->freezeSecond();
        $a = $this->candidate(0);
        $b = $this->candidate(1, 'second@example.test');
        $template = EmailTemplate::where('code', 'round_1_invitation')->firstOrFail();
        $template->update(['subject' => 'Assessment invitation', 'body' => 'Please attend the assessment.']);
        $data = [...$this->message(), 'email_template_id' => $template->id, 'subject' => $template->subject, 'body' => $template->body];
        $this->actingAs($this->hr);
        Livewire::test(ListCandidates::class)->callTableBulkAction('emailCandidates', [$a, $b], $data)->assertHasNoErrors();
        $this->assertCount(1, $this->transport->messages());
        $mail = $this->transport->messages()->first()->getOriginalMessage();
        $this->assertCount(2, $mail->getBcc());
        $this->assertSame([], $mail->getTo());
        $this->assertSame([], $mail->getCc());
        foreach ([$a, $b] as $candidate) {
            $round = $candidate->interviewRounds()->where('round_number', 1)->first();
            $this->assertSame('Scheduled', $round->status);
            $this->assertSame($this->hr->id, $round->updated_by);
            $this->assertTrue($round->scheduled_at->eq(now()));
            $this->assertNotNull($candidate->fresh()->round_one_email_sent_at);
            $this->assertDatabaseHas('activity_logs', ['actor_id' => $this->hr->id, 'event' => 'candidate.email_submitted', 'subject_id' => $candidate->id]);
        }
        $this->assertDatabaseHas('candidate_email_batches', ['actor_id' => $this->hr->id, 'status' => 'submitted']);
    }

    public function test_hr_cannot_email_other_hrs_candidates_or_a_mixed_selection(): void
    {
        $own = $this->candidate(0);
        $other = $this->candidate(1);
        $other->forceFill(['assigned_hr_id' => $this->admin->id])->save();
        foreach ([[$other->id], [$own->id, $other->id]] as $ids) {
            try {
                app(CandidateOutreachService::class)->email($this->hr, $ids, $this->message());
                $this->fail('Unauthorized candidate emailed');
            } catch (AuthorizationException) {
                $this->assertCount(0, $this->transport->messages());
                $this->assertDatabaseCount('candidate_email_batches', 0);
            }
        }
    }

    public function test_inactive_hr_and_users_without_hr_role_cannot_send_email(): void
    {
        $candidate = $this->candidate(0);
        $this->hr->forceFill(['is_active' => false])->save();
        $nonHr = User::factory()->create(['is_active' => true]);
        $nonHr->givePermissionTo(['candidates.view', 'candidates.update']);
        foreach ([$this->hr, $nonHr] as $actor) {
            try {
                app(CandidateOutreachService::class)->email($actor, [$candidate->id], $this->message());
                $this->fail('Unauthorized sender accepted');
            } catch (HttpException $e) {
                $this->assertSame(403, $e->getStatusCode());
                $this->assertCount(0, $this->transport->messages());
                $this->assertDatabaseCount('candidate_email_batches', 0);
            }
        }
    }

    public function test_whatsapp_table_action_redirects_to_the_encoded_chat(): void
    {
        $a = $this->candidate(0);
        $this->actingAs($this->hr);
        Livewire::test(ListCandidates::class)
            ->callTableAction('whatsapp', $a, ['message' => 'Hello & welcome'])
            ->assertHasNoErrors()
            ->assertRedirect('https://wa.me/919876543210?text=Hello%20%26%20welcome');
    }

    public function test_deleted_selection_is_rejected_and_inactive_admin_is_denied(): void
    {
        $a = $this->candidate(0);
        $a->delete();
        try {
            app(CandidateOutreachService::class)->email($this->admin, [$a->id], $this->message());
            $this->fail('Deleted candidate was accepted.');
        } catch (ValidationException) {
            $this->assertCount(0, $this->transport->messages());
        }
        $this->admin->forceFill(['is_active' => false])->save();
        $this->expectException(HttpException::class);
        app(CandidateOutreachService::class)->bulkStatus($this->admin, [$a->id], 'Rejected', null);
    }

    public function test_cancelled_delivery_is_marked_uncertain_and_cannot_be_replayed(): void
    {
        $a = $this->candidate(0);
        $data = [...$this->message(), 'email_template_id' => EmailTemplate::where('code', 'round_1_invitation')->value('id')];
        Event::listen(MessageSending::class, fn () => false);
        foreach ([1, 2] as $attempt) {
            try {
                app(CandidateOutreachService::class)->email($this->admin, [$a->id], $data);
                $this->fail('Cancelled mail reported success.');
            } catch (ValidationException $e) {
                $this->assertDatabaseHas('candidate_email_batches', ['token' => $data['token'], 'status' => 'uncertain']);
                $this->assertStringContainsString('Email attempt #', $e->getMessage());
                $this->assertStringContainsString($attempt === 1 ? 'storage/logs/laravel.log' : 'previous email attempt has unconfirmed delivery', $e->getMessage());
            }
        }
        $this->assertCount(0, $this->transport->messages());
        $this->assertDatabaseCount('candidate_email_batches', 1);
        $round = $a->interviewRounds()->where('round_number', 1)->first();
        $this->assertSame('Pending', $round->status);
        $this->assertNull($round->scheduled_at);
        $this->assertNull($a->fresh()->last_email_sent_at);
        $this->assertNull($a->fresh()->round_one_email_sent_at);
    }

    public function test_round_one_invitation_schedules_every_selected_candidate_after_delivery(): void
    {
        $this->freezeSecond();
        $a = $this->candidate(0);
        $b = $this->candidate(1); // Shared addresses still update both candidate records.
        $previousSchedule = now()->subDay();
        $b->interviewRounds()->where('round_number', 1)->update(['status' => 'Scheduled', 'scheduled_at' => $previousSchedule]);
        EmailTemplate::where('code', 'round_1_invitation')->update(['subject' => 'Assessment invitation', 'body' => 'Attend the online assessment.']);
        $data = [...$this->message(), 'email_template_id' => EmailTemplate::where('code', 'round_1_invitation')->value('id')];
        Event::listen(MessageSending::class, function () use ($a, $b, $previousSchedule) {
            $this->assertSame('Pending', $a->interviewRounds()->where('round_number', 1)->first()->status);
            $this->assertTrue($b->interviewRounds()->where('round_number', 1)->first()->scheduled_at->eq($previousSchedule));
        });
        $this->actingAs($this->admin);
        Livewire::test(ListCandidates::class)->callTableBulkAction('emailCandidates', [$a, $b], $data)->assertHasNoErrors();
        $submittedAt = now();
        foreach ([$a, $b] as $candidate) {
            $round = $candidate->interviewRounds()->where('round_number', 1)->first();
            $this->assertSame('Scheduled', $round->status);
            $this->assertTrue($round->scheduled_at->eq($submittedAt));
            $this->assertSame($this->admin->id, $round->updated_by);
            $this->assertTrue($candidate->fresh()->last_email_sent_at->eq($submittedAt));
            $this->assertTrue($candidate->fresh()->round_one_email_sent_at->eq($submittedAt));
            $this->assertDatabaseHas('activity_logs', ['event' => 'interview.updated', 'subject_id' => $round->id]);
            $this->assertSame(2, $candidate->interviewRounds()->where('round_number', '>', 1)->where('status', 'Not Started')->count());
        }
        $this->travel(10)->minutes();
        try {
            app(CandidateOutreachService::class)->email($this->admin, [$a->id, $b->id], $data);
            $this->fail('Duplicate submission accepted');
        } catch (ValidationException) {
            $this->assertCount(1, $this->transport->messages());
            $this->assertTrue($a->interviewRounds()->where('round_number', 1)->first()->scheduled_at->eq($submittedAt));
        }
    }

    public function test_other_email_types_do_not_change_round_one(): void
    {
        $candidate = $this->candidate(0);
        $service = app(CandidateOutreachService::class);
        $service->email($this->admin, [$candidate->id], $this->message());
        $this->assertSame('Pending', $candidate->interviewRounds()->where('round_number', 1)->first()->status);
        $candidate->interviewRounds()->where('round_number', 1)->update(['status' => 'Passed', 'completed_at' => now()]);
        foreach (['round_1_cleared', 'round_2_invitation'] as $code) {
            $service->email($this->admin, [$candidate->id], [...$this->message(), 'email_template_id' => EmailTemplate::where('code', $code)->value('id')]);
            $round = $candidate->interviewRounds()->where('round_number', 1)->first();
            $this->assertSame('Passed', $round->status);
            $this->assertNull($round->scheduled_at);
        }
    }

    public function test_round_completed_during_delivery_is_not_reopened(): void
    {
        $candidate = $this->candidate(0);
        Event::listen(MessageSending::class, function () use ($candidate) {
            $candidate->interviewRounds()->where('round_number', 1)->update(['status' => 'Passed', 'completed_at' => now()]);
        });
        app(CandidateOutreachService::class)->email($this->admin, [$candidate->id], [...$this->message(), 'email_template_id' => EmailTemplate::where('code', 'round_1_invitation')->value('id')]);
        $this->assertSame('Passed', $candidate->interviewRounds()->where('round_number', 1)->first()->status);
        $this->assertDatabaseHas('activity_logs', ['event' => 'candidate.email_scheduling_skipped', 'subject_id' => $candidate->id]);
    }

    public function test_schedule_failure_after_delivery_does_not_allow_resending_the_same_submission(): void
    {
        $candidate = $this->candidate(0);
        $data = [...$this->message(), 'email_template_id' => EmailTemplate::where('code', 'round_1_invitation')->value('id')];
        $this->mock(InterviewService::class)->shouldReceive('update')->once()->andThrow(new \RuntimeException('Schedule update failed'));
        try {
            app(CandidateOutreachService::class)->email($this->admin, [$candidate->id], $data);
            $this->fail('Schedule failure was not reported');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('Do not resend', $e->getMessage());
            $this->assertDatabaseHas('candidate_email_batches', ['token' => $data['token'], 'status' => 'submitted']);
            $this->assertSame('Pending', $candidate->interviewRounds()->where('round_number', 1)->first()->status);
            $this->assertNotNull($candidate->fresh()->last_email_sent_at);
        }
        try {
            app(CandidateOutreachService::class)->email($this->admin, [$candidate->id], $data);
            $this->fail('Duplicate submission accepted');
        } catch (ValidationException) {
            $this->assertCount(1, $this->transport->messages());
        }
    }

    public function test_email_and_scheduled_tabs_count_candidates_and_respect_hr_ownership(): void
    {
        $a = $this->candidate(0);
        $b = $this->candidate(1);
        $unsent = $this->candidate(2);
        $deleted = $this->candidate(3);
        $b->forceFill(['assigned_hr_id' => $this->admin->id])->save();
        $data = [...$this->message(), 'email_template_id' => EmailTemplate::where('code', 'round_1_invitation')->value('id')];
        app(CandidateOutreachService::class)->email($this->admin, [$a->id, $b->id, $deleted->id], $data);
        $deleted->delete();

        $this->actingAs($this->admin);
        $page = Livewire::test(ListCandidates::class);
        foreach (['email_sent', 'round_one_email_sent', 'round_one_scheduled'] as $tab) {
            $this->assertSame('2', $page->instance()->getTabs()[$tab]->getBadge());
            $page->set('activeTab', $tab)->assertCanSeeTableRecords([$a, $b])->assertCanNotSeeTableRecords([$unsent, $deleted]);
        }
        $page->assertTableColumnStateSet('round_1', 'Scheduled', $a)
            ->assertTableColumnVisible('last_email_sent_at')
            ->assertTableColumnVisible('round_one_email_sent_at')
            ->assertTableColumnVisible('round_one_scheduled_at');

        $this->actingAs($this->hr);
        $page = Livewire::test(ListCandidates::class);
        foreach (['email_sent', 'round_one_email_sent', 'round_one_scheduled'] as $tab) {
            $this->assertSame('1', $page->instance()->getTabs()[$tab]->getBadge());
            $page->set('activeTab', $tab)->assertCanSeeTableRecords([$a])->assertCanNotSeeTableRecords([$b, $unsent, $deleted]);
        }
        app(InterviewService::class)->update($this->hr, $a->interviewRounds()->where('round_number', 1)->first(), ['status' => 'Passed']);
        $this->assertSame('0', $page->instance()->getTabs()['round_one_scheduled']->getBadge());
        $this->assertSame('1', $page->instance()->getTabs()['round_one_email_sent']->getBadge());

        // Other email types must not hide candidates still awaiting a Round 1 invitation.
        app(CandidateOutreachService::class)->email($this->admin, [$unsent->id], $this->message());
        foreach ([$this->admin, $this->hr] as $actor) {
            $this->actingAs($actor);
            $filtered = Livewire::test(ListCandidates::class)
                ->filterTable('round_one_email_sent', false)
                ->assertCanSeeTableRecords([$unsent])
                ->assertCanNotSeeTableRecords([$a, $b, $deleted]);
            $filtered->filterTable('round_one_email_sent', true)
                ->assertCanSeeTableRecords([$a])->assertCanNotSeeTableRecords([$unsent, $deleted]);
            $filtered->resetTableFilters()->assertCanSeeTableRecords([$a, $unsent]);
        }
    }

    public function test_existing_submitted_batches_backfill_dates_without_rescheduling(): void
    {
        $candidate = $this->candidate(0);
        $templateId = EmailTemplate::where('code', 'round_1_invitation')->value('id');
        foreach ([['2026-10-20 10:00:00', 'submitted', $templateId], ['2026-10-19 10:00:00', 'submitted', $templateId], ['2026-10-21 10:00:00', 'submitted', null], ['2026-10-22 10:00:00', 'uncertain', $templateId]] as [$date, $status, $id]) {
            DB::table('candidate_email_batches')->insert([
                'token' => (string) Str::uuid(), 'actor_id' => $this->admin->id,
                'candidate_ids' => json_encode([$candidate->id]), 'recipient_count' => 1,
                'subject' => 'Historical invitation', 'status' => $status,
                'email_template_id' => $id, 'created_at' => $date, 'updated_at' => $date,
            ]);
        }
        $migration = require database_path('migrations/2026_10_29_160000_add_candidate_email_tracking.php');
        $migration->down();
        $migration->up();
        $candidate->refresh();
        $this->assertSame('2026-10-21 10:00:00', $candidate->last_email_sent_at->toDateTimeString());
        $this->assertSame('2026-10-20 10:00:00', $candidate->round_one_email_sent_at->toDateTimeString());
        $round = $candidate->interviewRounds()->where('round_number', 1)->first();
        $this->assertSame('Pending', $round->status);
        $this->assertNull($round->scheduled_at);
    }
}
