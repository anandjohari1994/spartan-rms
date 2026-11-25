<?php

namespace Tests\Feature;

use App\Filament\Resources\OfferLetterResource\Pages\ListOfferLetters;
use App\Filament\Resources\ActivityLogResource\Pages\ListActivityLogs;
use App\Models\ActivityLog;
use App\Models\Candidate;
use App\Models\JobPosition;
use App\Models\OfferLetterDelivery;
use App\Models\User;
use App\Services\CandidateService;
use App\Services\EmailActivityDetails;
use App\Services\DashboardService;
use App\Services\ReportService;
use App\Filament\Pages\Reports;
use App\Services\OfferLetterPdf;
use App\Services\OfferLetterService;
use App\Services\OfferSalaryCalculator;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class OfferLetterTest extends TestCase
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
        $this->actingAs($this->admin);
        Storage::fake('private');
        $this->transport = new ArrayTransport;
        config(['mail.default' => 'careers', 'mail.from.address' => 'careers@spartaninfosoft.com', 'mail.mailers.careers.from.address' => 'careers@spartaninfosoft.com', 'mail.mailers.invitation.from.address' => 'invitation@spartaninfosoft.com', 'mail.mailers.hr.from.address' => 'hr@spartaninfosoft.com', 'mail.mailers.careers.transport' => 'smtp', 'mail.mailers.invitation.transport' => 'smtp', 'mail.mailers.hr.transport' => 'smtp']);
        Mail::extend('smtp', fn () => $this->transport);
    }

    private function candidate(int $number = 0): Candidate
    {
        $candidate = app(CandidateService::class)->create($this->hr, [
            'entry_date' => today()->toDateString(), 'full_name' => 'Meera Patel '.$number,
            'mobile' => '987654321'.$number, 'email' => 'candidate'.$number.'@example.test',
            'job_position_id' => JobPosition::first()->id, 'interest_status' => 'Interested',
        ]);
        $candidate->interviewRounds()->whereIn('round_number', [1, 2])->update(['status' => 'Passed']);

        return $candidate;
    }

    public static function details(): array
    {
        return [
            ...config('offer_letters.defaults'),
            'reference' => 'SI/OFFER/2026/001', 'address_line_1' => '12, Lotus Apartments', 'address_line_2' => 'Kalyani Nagar, Pune', 'state' => 'Maharashtra', 'pin_code' => '411014',
            'designation' => 'Software Engineer', 'department' => 'Engineering', 'work_location' => 'Pune', 'employment_type' => 'Full-Time',
            'letter_date' => today()->toDateString(), 'joining_date' => today()->addDays(20)->toDateString(), 'probation_months' => 6,
            'annual_package' => '636000.00',
            'other_label' => 'Other allowance', 'salary_payment_day' => 7, 'signatory_name' => 'Ananya Sharma', 'signatory_designation' => 'HR Manager', 'optional_clause' => 'include',
        ];
    }

    private function draft(Candidate $candidate)
    {
        return app(OfferLetterService::class)->saveDraft($this->admin, $candidate, self::details());
    }

    public function test_offer_email_activity_snapshots_actual_message_for_send_and_resend(): void
    {
        $candidate = $this->candidate();
        $offer = $this->draft($candidate);
        $service = app(OfferLetterService::class);
        $service->send($this->admin, [$candidate->id], [$candidate->id => $offer->revision]);
        $service->resend($this->admin, $candidate->id, $offer->revision, (string) Str::uuid());
        $logs = ActivityLog::where('event', 'offer.submitted')->orderBy('id')->get();
        $this->assertCount(2, $logs);
        $this->assertNotSame($logs[0]->new_values['delivery_id'], $logs[1]->new_values['delivery_id']);
        foreach ($logs as $index => $log) {
            $mail = $this->transport->messages()->values()[$index]->getOriginalMessage();
            $values = $log->new_values;
            $this->assertSame($mail->getTextBody(), $values['body']);
            $this->assertSame($mail->getSubject(), $values['subject']);
            $this->assertSame($mail->getFrom()[0]->getAddress(), $values['from']);
            $this->assertSame($mail->getTo()[0]->getAddress(), $values['to']);
            $this->assertSame($candidate->email, $values['candidate_email']);
            $this->assertSame('To', $values['recipient_type']);
            $this->assertSame($offer->revision, $values['revision']);
            $this->assertSame($offer->pdf_sha256, $values['attachment_sha256']);
            $this->assertNotEmpty($values['message_id']);
            $this->assertNotEmpty($values['submitted_at']);
        }
        $candidate->forceFill(['email' => 'changed@example.test', 'full_name' => 'Changed name'])->save();
        $service->saveDraft($this->admin, $candidate, [...self::details(), 'revision' => $offer->revision, 'designation' => 'New role']);
        $this->assertSame('candidate0@example.test', app(EmailActivityDetails::class)->forRecord($logs[0])['candidate_email']);
        $page = Livewire::test(ListActivityLogs::class)
            ->filterTable('event', 'offer.submitted')
            ->searchTable('candidate0@example.test')
            ->assertCanSeeTableRecords($logs)
            ->mountTableAction('details', $logs[0]);
        $html = $page->instance()->getMountedAction()->getModalContent()->render();
        $this->assertStringContainsString('Email content', $html);
        $this->assertStringContainsString('candidate0@example.test', $html);
        $this->assertStringContainsString('hr@spartaninfosoft.com', $html);
        $this->assertStringContainsString('PDF attachment', $html);
        $this->assertStringNotContainsString('reconstructed', $html);
        $this->assertStringNotContainsString('New role', $html);
    }

    public function test_legacy_offer_activity_uses_exact_delivery_with_a_labeled_content_preview(): void
    {
        $candidate = $this->candidate();
        $offer = $this->draft($candidate);
        $service = app(OfferLetterService::class);
        $service->send($this->admin, [$candidate->id], [$candidate->id => $offer->revision]);
        $log = ActivityLog::where('event', 'offer.submitted')->firstOrFail();
        $legacy = array_intersect_key($log->new_values, array_flip(['offer_id', 'revision', 'delivery_id']));
        $log->forceFill(['new_values' => $legacy])->save();
        $candidate->forceFill(['email' => 'changed@example.test'])->save();
        $service->saveDraft($this->admin, $candidate, [...self::details(), 'revision' => $offer->revision, 'designation' => 'New role']);
        $email = app(EmailActivityDetails::class)->forRecord($log);
        $this->assertSame('candidate0@example.test', $email['candidate_email']);
        $this->assertTrue($email['reconstructed']);
        $this->assertStringNotContainsString('New role', $email['body']);
        $html = view('filament.audit-details', ['record' => $log])->render();
        $this->assertStringContainsString('Email content preview (reconstructed)', $html);
        $this->assertStringContainsString('Not recorded in this activity entry', $html);
        $this->assertStringContainsString('candidate0@example.test', $html);
        $log->forceFill(['event' => 'offer.sending']);
        $this->assertStringNotContainsString('Submitted to provider.', view('filament.audit-details', ['record' => $log])->render());
        $log->forceFill(['new_values' => [...$legacy, 'delivery_id' => 999999]]);
        $this->assertNull(app(EmailActivityDetails::class)->forRecord($log));
        $this->assertStringContainsString('New values', view('filament.audit-details', ['record' => $log])->render());
    }

    public function test_admin_can_edit_and_send_a_submitted_offer_without_losing_history(): void
    {
        $candidate = $this->candidate();
        $offer = $this->draft($candidate);
        $service = app(OfferLetterService::class);
        $oldRevision = $offer->revision;
        $oldPath = $offer->pdf_path;
        $oldHash = $offer->pdf_sha256;
        $service->send($this->admin, [$candidate->id], [$candidate->id => $oldRevision]);
        Livewire::test(ListOfferLetters::class)->callTableAction('prepareOffer', $candidate, [...self::details(), 'revision' => $oldRevision, 'annual_package' => '720000'])->assertHasNoActionErrors();
        $offer->refresh();
        $this->assertSame('draft', $offer->status);
        $this->assertNotSame($oldRevision, $offer->revision);
        $this->assertNotNull($offer->submitted_at);
        $history = $offer->deliveries()->firstOrFail();
        $this->assertSame($oldRevision, $history->revision);
        $this->assertSame('636000.00', $history->details['annual_package']);
        $this->assertSame($oldHash, hash('sha256', Storage::disk('private')->get($oldPath)));
        $this->assertSame(1, app(DashboardService::class)->stats($this->admin)['Offer letters sent']);
        try {
            $service->resend($this->admin, $candidate->id, $oldRevision, (string) Str::uuid());
            $this->fail('An old revision was resent.');
        } catch (ValidationException) {
            $this->assertCount(1, $this->transport->messages());
        }
        Livewire::test(ListOfferLetters::class)->callTableAction('sendOffer', $candidate, ['revision' => $offer->revision])->assertHasNoActionErrors();
        $this->assertCount(2, $this->transport->messages());
        $this->assertSame(2, $offer->deliveries()->where('status', 'submitted')->count());
        $this->assertSame('720000.00', $offer->deliveries()->latest('id')->first()->details['annual_package']);
        $this->assertSame(1, app(DashboardService::class)->stats($this->admin)['Offer letters sent']);
    }

    public function test_resend_action_reuses_saved_pdf_and_rejects_replayed_confirmation(): void
    {
        $candidate = $this->candidate();
        $offer = $this->draft($candidate);
        $service = app(OfferLetterService::class);
        $service->send($this->admin, [$candidate->id], [$candidate->id => $offer->revision]);
        $token = (string) Str::uuid();
        Livewire::test(ListOfferLetters::class)->callTableAction('resendOffer', $candidate, ['revision' => $offer->revision, 'token' => $token])->assertHasNoActionErrors();
        $this->assertCount(2, $this->transport->messages());
        foreach ($this->transport->messages() as $message) {
            $this->assertSame($offer->pdf_sha256, hash('sha256', $message->getOriginalMessage()->getAttachments()[0]->getBody()));
        }
        try {
            $service->resend($this->admin, $candidate->id, $offer->revision, $token);
            $this->fail('Duplicate resend accepted.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('already been processed', $exception->getMessage());
            $this->assertCount(2, $this->transport->messages());
            $this->assertDatabaseCount('offer_letter_deliveries', 2);
        }
    }

    public function test_hr_cannot_edit_or_resend_submitted_offers_or_send_their_revisions(): void
    {
        $candidate = $this->candidate();
        $offer = $this->draft($candidate);
        $service = app(OfferLetterService::class);
        $service->send($this->admin, [$candidate->id], [$candidate->id => $offer->revision]);
        $this->actingAs($this->hr);
        Livewire::test(ListOfferLetters::class)->assertTableActionHidden('prepareOffer', $candidate)->assertTableActionHidden('resendOffer', $candidate);
        foreach ([fn () => $service->saveDraft($this->hr, $candidate, [...self::details(), 'revision' => $offer->revision]), fn () => $service->resend($this->hr, $candidate->id, $offer->revision, (string) Str::uuid())] as $attempt) {
            try {
                $attempt();
                $this->fail('HR accessed an admin-only operation.');
            } catch (HttpException $exception) {
                $this->assertSame(403, $exception->getStatusCode());
            }
        }
        $offer = $service->saveDraft($this->admin, $candidate, [...self::details(), 'revision' => $offer->revision]);
        Livewire::test(ListOfferLetters::class)->assertTableActionHidden('prepareOffer', $candidate)->assertTableActionHidden('sendOffer', $candidate);
        try {
            $service->send($this->hr, [$candidate->id], [$candidate->id => $offer->revision]);
            $this->fail('HR sent a revised submitted offer.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
            $this->assertCount(1, $this->transport->messages());
        }
    }

    public function test_unconfirmed_resend_stays_locked_and_preserves_previous_submission_count(): void
    {
        $candidate = $this->candidate();
        $offer = $this->draft($candidate);
        $service = app(OfferLetterService::class);
        $service->send($this->admin, [$candidate->id], [$candidate->id => $offer->revision]);
        Event::listen(MessageSending::class, fn () => false);
        $this->assertSame(['submitted' => 0, 'uncertain' => 1], $service->resend($this->admin, $candidate->id, $offer->revision, (string) Str::uuid()));
        $this->assertSame('uncertain', $offer->fresh()->status);
        $this->assertSame(1, $offer->deliveries()->where('status', 'submitted')->count());
        $this->assertSame(1, $offer->deliveries()->where('status', 'uncertain')->count());
        $this->assertSame(1, app(DashboardService::class)->stats($this->admin)['Offer letters sent']);
        foreach ([fn () => $service->saveDraft($this->admin, $candidate, [...self::details(), 'revision' => $offer->revision]), fn () => $service->resend($this->admin, $candidate->id, $offer->revision, (string) Str::uuid())] as $attempt) {
            try {
                $attempt();
                $this->fail('Unconfirmed offer was unlocked.');
            } catch (ValidationException) {
                $this->assertCount(1, $this->transport->messages());
            }
        }
    }

    public function test_offer_reports_filter_by_send_date_and_dashboard_counts_candidates_once(): void
    {
        $a = $this->candidate();
        $b = $this->candidate(1);
        $b->forceFill(['assigned_hr_id' => $this->admin->id])->save();
        $c = $this->candidate(2);
        $a->update(['entry_date' => today()->subMonths(2)->toDateString()]);
        $service = app(OfferLetterService::class);
        $first = $this->draft($a);
        $second = $this->draft($b);
        $this->draft($c);
        $service->send($this->admin, [$a->id], [$a->id => $first->revision]);
        $first->deliveries()->update(['submitted_at' => now()->subDays(2)]);
        $service->resend($this->admin, $a->id, $first->revision, (string) Str::uuid());
        $service->send($this->admin, [$b->id], [$b->id => $second->revision]);
        $reports = app(ReportService::class);
        $today = ['type' => 'offers', 'from' => today()->toDateString(), 'until' => today()->toDateString()];
        $this->assertCount(3, iterator_to_array($reports->rows($this->admin, ['type' => 'offers'])));
        $this->assertCount(2, iterator_to_array($reports->rows($this->admin, $today)));
        $rows = iterator_to_array($reports->rows($this->admin, [...$today, 'assigned_hr_id' => $this->hr->id]));
        $this->assertCount(1, $rows);
        $this->assertSame($a->email, $rows[0]['Recipient']);
        $this->assertCount(2, iterator_to_array($reports->rows($this->admin, ['type' => 'range', 'offer_sent' => 'sent'])));
        $this->assertCount(1, iterator_to_array($reports->rows($this->admin, ['type' => 'range', 'offer_sent' => 'not_sent'])));
        $this->assertSame(2, app(DashboardService::class)->stats($this->admin)['Offer letters sent']);
        $this->assertSame(1, app(DashboardService::class)->stats($this->hr)['Offer letters sent']);
        Livewire::test(Reports::class)->fillForm([...$today, 'offer_sent' => 'sent', 'assigned_hr_id' => $this->hr->id])->call('preview')->assertHasNoFormErrors()->assertSee($a->email)->assertDontSee($b->email);
        $response = $reports->export($this->admin, [...$today, 'assigned_hr_id' => $this->hr->id]);
        ob_start();
        $response->sendContent();
        $csv = ob_get_clean();
        $this->assertStringContainsString($a->email, $csv);
        $this->assertStringNotContainsString($b->email, $csv);
        $b->delete();
        $this->assertSame(1, app(DashboardService::class)->stats($this->admin)['Offer letters sent']);
    }

    public function test_delivery_history_migration_backfills_existing_offers(): void
    {
        $submitted = $this->draft($this->candidate());
        $submitted->update(['status' => 'submitted', 'submitted_at' => now()->subDay()]);
        $uncertain = $this->draft($this->candidate(1));
        $uncertain->update(['status' => 'uncertain']);
        $this->draft($this->candidate(2));
        $migration = require database_path('migrations/2026_11_22_100000_create_offer_letter_deliveries_table.php');
        $migration->down();
        $migration->up();
        $this->assertDatabaseCount('offer_letter_deliveries', 2);
        $history = OfferLetterDelivery::where('offer_letter_id', $submitted->id)->firstOrFail();
        $this->assertSame($submitted->pdf_path, $history->pdf_path);
        $this->assertSame($submitted->details, $history->details);
        $this->assertTrue($history->submitted_at->equalTo($submitted->submitted_at));
        $this->assertNull($history->sent_by);
        $this->assertSame(1, app(DashboardService::class)->stats($this->admin)['Offer letters sent']);
    }

    public function test_prepare_offer_prefills_editable_defaults_without_removed_dates(): void
    {
        $candidate = $this->candidate();
        $page = Livewire::test(ListOfferLetters::class)->mountTableAction('prepareOffer', $candidate);
        foreach (config('offer_letters.defaults') as $key => $value) {
            $page->assertSet('mountedActions.0.data.'.$key, $value);
        }
        $page->assertSet('mountedActions.0.data.letter_date', today()->toDateString());
        $this->assertSame(['letter_date', 'joining_date'], array_keys(OfferLetterService::DATE_FIELDS));
        foreach (['commencement_date', 'document_deadline', 'valid_until', 'acceptance_deadline'] as $key) {
            $this->assertArrayNotHasKey($key, $page->get('mountedActions')[0]['data']);
        }
        $page->set('mountedActions.0.data.annual_package', '500000')
            ->set('mountedActions.0.data.department', 'Quality Assurance')
            ->assertSet('mountedActions.0.data.annual_package', '500000')
            ->assertSet('mountedActions.0.data.department', 'Quality Assurance');
    }

    public function test_new_offers_ignore_obsolete_deadlines_and_use_receipt_based_acceptance(): void
    {
        $candidate = $this->candidate();
        $oldDates = array_fill_keys(['commencement_date', 'document_deadline', 'valid_until', 'acceptance_deadline'], '2020-01-01');
        $offer = app(OfferLetterService::class)->saveDraft($this->admin, $candidate, [...self::details(), ...$oldDates]);
        foreach (array_keys($oldDates) as $key) {
            $this->assertArrayNotHasKey($key, $offer->details);
        }
        $this->assertSame(2, $offer->details['offer_terms_version']);
        $this->assertSame(1, app(OfferLetterService::class)->send($this->admin, [$candidate->id], [$candidate->id => $offer->revision])['submitted']);
        $body = $this->transport->messages()->first()->getOriginalMessage()->getTextBody();
        $this->assertStringContainsString('within 24 hours of receiving it', $body);
        $this->assertStringNotContainsString('on or before', $body);
        $this->assertSame(config('offer_letters.defaults.leave_policy'), $offer->details['leave_policy']);
    }

    public function test_saved_custom_values_survive_reopening_and_terms_are_stored(): void
    {
        $candidate = $this->candidate();
        $offer = app(OfferLetterService::class)->saveDraft($this->admin, $candidate, [...self::details(), 'notice_probation' => '15 days', 'notice_confirmed' => '2 months', 'working_hours' => '8 hours per day', 'leave_policy' => "Planned leave requires approval.\nEmergency leave must be reported promptly."]);
        $defaults = app(OfferLetterService::class)->defaults($candidate->fresh());
        $this->assertSame('Engineering', $defaults['department']);
        $this->assertSame(6, $defaults['probation_months']);
        $this->assertSame('636000.00', $defaults['annual_package']);
        $this->assertSame('15 days', $defaults['notice_probation']);
        $this->assertSame('Planned leave requires approval. Emergency leave must be reported promptly.', $offer->details['leave_policy']);
        $this->assertStringContainsString('15 days during probation and 2 months after confirmation', app(OfferLetterPdf::class)->values($offer->details)['notice_terms']);
    }

    public function test_terms_that_overflow_the_pdf_are_rejected_before_saving(): void
    {
        $candidate = $this->candidate();
        try {
            app(OfferLetterService::class)->saveDraft($this->admin, $candidate, [...self::details(), 'leave_policy' => str_repeat('WIDEWORD ', 65)]);
            $this->fail('Overflowing leave policy was saved.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('leave_policy', $exception->errors());
            $this->assertDatabaseCount('offer_letters', 0);
        }
    }

    public function test_only_two_passed_rounds_with_pending_client_interview_are_listed(): void
    {
        $qualified = $this->candidate();
        $pending = $this->candidate(1);
        $pending->interviewRounds()->where('round_number', 2)->update(['status' => 'Pending']);
        $failed = $this->candidate(2);
        $failed->interviewRounds()->where('round_number', 1)->update(['status' => 'Failed']);
        $rejected = $this->candidate(3);
        $rejected->update(['recruitment_status' => 'Rejected']);
        $clientPassed = $this->candidate(4);
        $clientPassed->interviewRounds()->where('round_number', 3)->update(['status' => 'Passed']);
        $deleted = $this->candidate(5);
        $deleted->delete();
        Livewire::test(ListOfferLetters::class)->assertOk()->assertCanSeeTableRecords([$qualified])->assertCanNotSeeTableRecords([$pending, $failed, $rejected, $clientPassed, $deleted]);
        $this->get('/admin/offer-letters')->assertOk();
    }

    public function test_draft_action_generates_preview_with_private_pdf_and_correct_totals(): void
    {
        $candidate = $this->candidate();
        Livewire::test(ListOfferLetters::class)->callTableAction('prepareOffer', $candidate, self::details())->assertHasNoActionErrors();
        $offer = $candidate->offerLetter()->firstOrFail();
        $response = $this->get(route('offer-letters.preview', $candidate))->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-', $response->getContent());
        $this->assertSame($offer->pdf_sha256, hash('sha256', $response->getContent()));
        $values = app(OfferLetterPdf::class)->values($offer->details);
        $this->assertSame('50,000.00', $values['monthly_gross']);
        $this->assertSame('636,000.00', $values['annual_ctc']);
        $this->assertSame('300,000.00', $values['basic_annual']);
    }

    public function test_bulk_action_sends_private_personalized_attachments_and_blocks_repeats(): void
    {
        $a = $this->candidate();
        $b = $this->candidate(1);
        $first = $this->draft($a);
        $second = $this->draft($b);
        Livewire::test(ListOfferLetters::class)->callTableBulkAction('sendOffers', [$a, $b], ['revisions' => [$a->id => $first->revision, $b->id => $second->revision]])->assertHasNoActionErrors();
        $this->assertCount(2, $this->transport->messages());
        foreach ($this->transport->messages()->values() as $i => $message) {
            $mail = $message->getOriginalMessage();
            $this->assertSame('candidate'.$i.'@example.test', $mail->getTo()[0]->getAddress());
            $this->assertSame('hr@spartaninfosoft.com', $mail->getFrom()[0]->getAddress());
            $this->assertCount(1, $mail->getTo());
            $this->assertSame([], $mail->getCc());
            $this->assertSame([], $mail->getBcc());
            $this->assertCount(1, $mail->getAttachments());
            $offer = $i === 0 ? $first : $second;
            $this->assertSame($offer->pdf_sha256, hash('sha256', $mail->getAttachments()[0]->getBody()));
        }
        $this->assertSame('submitted', $first->fresh()->status);
        $this->assertNotNull($first->fresh()->submitted_at);
        $this->expectException(ValidationException::class);
        app(OfferLetterService::class)->send($this->admin, [$a->id], [$a->id => $first->revision]);
    }

    public function test_single_send_action_uses_current_draft(): void
    {
        $candidate = $this->candidate();
        $offer = $this->draft($candidate);
        Livewire::test(ListOfferLetters::class)->callTableAction('sendOffer', $candidate, ['revision' => $offer->revision])->assertHasNoActionErrors();
        $this->assertCount(1, $this->transport->messages());
        $this->assertSame('submitted', $offer->fresh()->status);
    }

    public function test_whole_selection_is_checked_before_any_delivery(): void
    {
        $a = $this->candidate();
        $b = $this->candidate(1);
        $first = $this->draft($a);
        $second = $this->draft($b);
        $b->interviewRounds()->where('round_number', 2)->update(['status' => 'Failed']);
        try {
            app(OfferLetterService::class)->send($this->admin, [$a->id, $b->id], [$a->id => $first->revision, $b->id => $second->revision]);
            $this->fail('Ineligible candidate accepted');
        } catch (ValidationException) {
            $this->assertCount(0, $this->transport->messages());
            $this->assertSame('draft', $first->fresh()->status);
        }
    }

    public function test_changed_recipient_and_stale_revision_cannot_send(): void
    {
        $candidate = $this->candidate();
        $offer = $this->draft($candidate);
        foreach (['email', 'revision', 'expired', 'tampered'] as $scenario) {
            $candidate->update(['email' => $scenario === 'email' ? 'changed@example.test' : 'candidate0@example.test']);
            if ($scenario === 'expired') {
                $offer->update(['details' => [...$offer->details, 'offer_terms_version' => 1, 'valid_until' => today()->subDay()->toDateString()]]);
            }
            if ($scenario === 'tampered') {
                $offer->update(['details' => self::details() + ['candidate_name' => $candidate->full_name, 'offer_terms_version' => 2]]);
                Storage::disk('private')->put($offer->pdf_path, 'broken');
            }
            try {
                app(OfferLetterService::class)->send($this->admin, [$candidate->id], [$candidate->id => $scenario === 'revision' ? 'stale' : $offer->revision]);
                $this->fail('Unsafe send accepted: '.$scenario);
            } catch (ValidationException) {
                $this->assertCount(0, $this->transport->messages());
            }
        }
    }

    public function test_delivery_failure_is_recorded_and_cannot_be_retried(): void
    {
        $candidate = $this->candidate();
        $offer = $this->draft($candidate);
        Event::listen(MessageSending::class, fn () => false);
        $result = app(OfferLetterService::class)->send($this->admin, [$candidate->id], [$candidate->id => $offer->revision]);
        $this->assertSame(['submitted' => 0, 'uncertain' => 1], $result);
        $this->assertSame('uncertain', $offer->fresh()->status);
        $this->expectException(ValidationException::class);
        app(OfferLetterService::class)->send($this->admin, [$candidate->id], [$candidate->id => $offer->revision]);
    }

    public function test_access_is_scoped_and_guests_and_inactive_users_are_denied(): void
    {
        $candidate = $this->candidate();
        $this->draft($candidate);
        $other = User::factory()->create(['is_active' => true]);
        $other->assignRole('HR Executive');
        $this->actingAs($other);
        Livewire::test(ListOfferLetters::class)->assertCanNotSeeTableRecords([$candidate]);
        $this->get(route('offer-letters.preview', $candidate))->assertForbidden();
        $this->actingAs($this->hr)->get(route('offer-letters.preview', $candidate))->assertOk();
        $this->hr->update(['is_active' => false]);
        $this->get(route('offer-letters.preview', $candidate))->assertForbidden();
        auth()->logout();
        $this->get(route('offer-letters.preview', $candidate))->assertRedirect();
    }

    public function test_invalid_salary_and_missing_clause_choice_are_not_saved(): void
    {
        $candidate = $this->candidate();
        Livewire::test(ListOfferLetters::class)->callTableAction('prepareOffer', $candidate, [...self::details(), 'annual_package' => -1, 'optional_clause' => null])->assertHasActionErrors();
        $this->assertDatabaseCount('offer_letters', 0);
    }

    public function test_log_mailer_cannot_be_reported_as_delivery(): void
    {
        $candidate = $this->candidate();
        $offer = $this->draft($candidate);
        config(['mail.mailers.hr.transport' => 'log']);
        $this->expectException(ValidationException::class);
        app(OfferLetterService::class)->send($this->admin, [$candidate->id], [$candidate->id => $offer->revision]);
    }

    public function test_salary_table_updates_from_annual_input_and_cannot_be_forged(): void
    {
        $candidate = $this->candidate();
        $page = Livewire::test(ListOfferLetters::class)->mountTableAction('prepareOffer', $candidate)
            ->set('mountedActions.0.data.annual_package', '1200000');
        // Filament returns action modals as partial renders, outside Livewire's root HTML.
        $html = $page->instance()->getSchema('mountedActionSchema0')->toHtml();
        foreach (['100,000.00', '48,500.00', '19,400.00', '3,000.00', '4,850.00', '2,425.00', '14,550.00'] as $amount) {
            $this->assertStringContainsString($amount, $html);
        }
        $page->set('mountedActions.0.data.annual_package', '');
        $this->assertStringContainsString('Enter a valid annual package', $page->instance()->getSchema('mountedActionSchema0')->toHtml());
        $offer = app(OfferLetterService::class)->saveDraft($this->admin, $candidate, [...self::details(), 'basic_monthly' => 1, 'benefits_monthly' => 999999, 'ctc_annual' => 1]);
        $this->assertSame('25000.00', $offer->details['basic_monthly']);
        $this->assertSame('3000.00', $offer->details['benefits_monthly']);
        $this->assertSame('2500.00', $offer->details['lta_monthly']);
        $this->assertSame('1250.00', $offer->details['medical_monthly']);
        $this->assertSame('1250.00', $offer->details['conveyance_monthly']);
        $this->assertSame('2500.00', $offer->details['other_monthly']);
        $this->assertSame('636000.00', $offer->details['ctc_annual']);
        $this->assertSame(app(OfferSalaryCalculator::class)->calculate('636000.00')['salary_policy'], $offer->details['salary_policy']);
    }

    public function test_legacy_monthly_draft_keeps_its_pdf_until_explicit_save(): void
    {
        $candidate = $this->candidate();
        $offer = $this->draft($candidate);
        $originalPdfHash = $offer->pdf_sha256;
        $legacyDetails = $offer->details;
        unset($legacyDetails['annual_package'], $legacyDetails['salary_calculation_version'], $legacyDetails['salary_policy']);
        $offer->update(['details' => $legacyDetails]);
        $defaults = app(OfferLetterService::class)->defaults($candidate->fresh());
        $this->assertSame('636000.00', $defaults['annual_package']);
        $this->assertSame($originalPdfHash, $offer->fresh()->pdf_sha256);
    }
}
