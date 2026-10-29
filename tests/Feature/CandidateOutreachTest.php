<?php

namespace Tests\Feature;

use App\Filament\Resources\CandidateResource\Pages\ListCandidates;
use App\Models\Candidate;
use App\Models\JobPosition;
use App\Models\User;
use App\Services\CandidateOutreachService;
use App\Services\CandidateService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Mail\Transport\ArrayTransport;
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
        } catch (ValidationException) {
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

    public function test_hr_cannot_invoke_admin_services_even_directly(): void
    {
        $a = $this->candidate(0);
        foreach (['email', 'bulkStatus'] as $method) {
            try {
                $method === 'email'
                    ? app(CandidateOutreachService::class)->email($this->hr, [$a->id], $this->message())
                    : app(CandidateOutreachService::class)->bulkStatus($this->hr, [$a->id], 'Rejected', null);
                $this->fail('HR was authorized.');
            } catch (HttpException $e) {
                $this->assertSame(403, $e->getStatusCode());
            }
        }
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

    public function test_admin_bulk_actions_work_in_the_table_and_are_hidden_for_hr(): void
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
        Livewire::test(ListCandidates::class)->assertTableBulkActionHidden('bulkStatus')->assertTableBulkActionHidden('emailCandidates');
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
        $data = $this->message();
        Event::listen(MessageSending::class, fn () => false);
        foreach ([1, 2] as $attempt) {
            try {
                app(CandidateOutreachService::class)->email($this->admin, [$a->id], $data);
                $this->fail('Cancelled mail reported success.');
            } catch (ValidationException) {
                $this->assertDatabaseHas('candidate_email_batches', ['token' => $data['token'], 'status' => 'uncertain']);
            }
        }
        $this->assertCount(0, $this->transport->messages());
        $this->assertDatabaseCount('candidate_email_batches', 1);
    }
}
