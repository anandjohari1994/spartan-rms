<?php

namespace Tests\Feature;

use App\Filament\Resources\OfferLetterResource\Pages\ListOfferLetters;
use App\Models\Candidate;
use App\Models\JobPosition;
use App\Models\User;
use App\Services\CandidateService;
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
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
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
            'letter_date' => today()->toDateString(), 'joining_date' => today()->addDays(20)->toDateString(), 'commencement_date' => today()->addDays(20)->toDateString(), 'probation_months' => 6,
            'document_deadline' => today()->addDays(10)->toDateString(), 'valid_until' => today()->addDays(7)->toDateString(), 'acceptance_deadline' => today()->addDays(7)->toDateString(),
            'annual_package' => '636000.00',
            'other_label' => 'Other allowance', 'salary_payment_day' => 7, 'signatory_name' => 'Ananya Sharma', 'signatory_designation' => 'HR Manager', 'optional_clause' => 'include',
        ];
    }

    private function draft(Candidate $candidate)
    {
        return app(OfferLetterService::class)->saveDraft($this->admin, $candidate, self::details());
    }

    public function test_prepare_offer_prefills_editable_defaults_and_linked_dates(): void
    {
        $candidate = $this->candidate();
        $page = Livewire::test(ListOfferLetters::class)->mountTableAction('prepareOffer', $candidate);
        foreach (config('offer_letters.defaults') as $key => $value) {
            $page->assertSet('mountedActions.0.data.'.$key, $value);
        }
        $page->assertSet('mountedActions.0.data.letter_date', today()->toDateString());
        $page->set('mountedActions.0.data.joining_date', '2026-12-07'); // Monday -> Friday
        foreach (['commencement_date', 'document_deadline', 'valid_until', 'acceptance_deadline'] as $key) {
            $page->assertSet('mountedActions.0.data.'.$key, '2026-12-04');
        }
        $page->set('mountedActions.0.data.joining_date', '2026-12-08');
        foreach (['commencement_date', 'document_deadline', 'valid_until', 'acceptance_deadline'] as $key) {
            $page->assertSet('mountedActions.0.data.'.$key, '2026-12-07');
        }
        $page->set('mountedActions.0.data.document_deadline', '2026-12-03');
        $page->set('mountedActions.0.data.commencement_date', '2026-12-04')
            ->assertSet('mountedActions.0.data.document_deadline', '2026-12-03')
            ->assertSet('mountedActions.0.data.valid_until', '2026-12-04')
            ->assertSet('mountedActions.0.data.acceptance_deadline', '2026-12-04');
        $page->set('mountedActions.0.data.valid_until', '2026-12-03')
            ->assertSet('mountedActions.0.data.acceptance_deadline', '2026-12-03');
        $page->set('mountedActions.0.data.acceptance_deadline', '2026-12-02');
        $page->set('mountedActions.0.data.valid_until', '2026-12-04')
            ->assertSet('mountedActions.0.data.acceptance_deadline', '2026-12-02');
        $page->set('mountedActions.0.data.joining_date', '2026-12-09')
            ->assertSet('mountedActions.0.data.commencement_date', '2026-12-04');
        $page->set('mountedActions.0.data.annual_package', '500000')
            ->set('mountedActions.0.data.department', 'Quality Assurance')
            ->assertSet('mountedActions.0.data.annual_package', '500000')
            ->assertSet('mountedActions.0.data.department', 'Quality Assurance');
    }

    public function test_previous_business_day_skips_weekends_and_month_year_boundaries(): void
    {
        foreach (['2026-12-07' => '2026-12-04', '2026-12-06' => '2026-12-04', '2026-12-05' => '2026-12-04', '2026-12-08' => '2026-12-07', '2027-01-01' => '2026-12-31', '2027-03-01' => '2027-02-26'] as $joining => $expected) {
            $this->assertSame($expected, OfferLetterService::previousBusinessDay($joining));
        }
        $this->assertNull(OfferLetterService::previousBusinessDay(null));
        $this->assertNull(OfferLetterService::previousBusinessDay('not-a-date'));
        $this->assertNull(OfferLetterService::previousBusinessDay('2026-02-30'));
        $linked = array_fill_keys(['commencement_date', 'document_deadline', 'valid_until', 'acceptance_deadline'], '2026-12-07');
        $this->assertSame(array_fill_keys(array_keys($linked), null), OfferLetterService::dependentDates('joining_date', null, '2026-12-08', $linked));
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
                $offer->update(['details' => [...$offer->details, 'valid_until' => today()->subDay()->toDateString()]]);
            }
            if ($scenario === 'tampered') {
                $offer->update(['details' => self::details() + ['candidate_name' => $candidate->full_name]]);
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
