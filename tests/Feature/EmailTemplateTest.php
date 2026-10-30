<?php

namespace Tests\Feature;

use App\Filament\Resources\CandidateResource\Pages\ListCandidates;
use App\Filament\Resources\EmailTemplateResource;
use App\Filament\Resources\EmailTemplateResource\Pages\CreateEmailTemplate;
use App\Filament\Resources\EmailTemplateResource\Pages\EditEmailTemplate;
use App\Models\EmailTemplate;
use App\Models\JobPosition;
use App\Models\User;
use App\Services\CandidateOutreachService;
use App\Services\CandidateService;
use App\Services\EmailTemplateService;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\EmailTemplateSeeder;
use Database\Seeders\UiDeveloperEmailTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class EmailTemplateTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private ArrayTransport $transport;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole('Super Admin');
        $this->actingAs($this->admin);
        $this->transport = new ArrayTransport;
        config(['mail.default' => 'smtp']);
        Mail::extend('smtp', fn () => $this->transport);
    }

    private function candidate()
    {
        return app(CandidateService::class)->create($this->admin, ['full_name' => 'Template Candidate', 'mobile' => '9876543210', 'email' => 'candidate@example.test', 'job_position_id' => JobPosition::first()->id]);
    }

    private function message(EmailTemplate $template): array
    {
        return ['token' => (string) Str::uuid(), 'email_template_id' => $template->id, 'subject' => $template->subject, 'body' => $template->body, 'company_name' => 'Spartan Infosoft', 'session_time' => '30 October 2026, 11 AM IST', 'deadline' => '29 October, 6 PM IST', 'link' => 'https://example.test/exam', 'instructions' => 'Please confirm attendance.', 'support_contact' => 'recruitment@example.test'];
    }

    public function test_six_starters_render_and_seeding_preserves_edits(): void
    {
        $this->assertDatabaseCount('email_templates', 6);
        foreach (EmailTemplate::all() as $template) {
            $service = app(EmailTemplateService::class);
            $body = $service->render($template->body, $service->variables($template, $this->message($template)));
            $this->assertStringNotContainsString('{{', $body);
            $this->assertStringContainsString('Spartan Infosoft', $body);
        }
        $template = EmailTemplate::first();
        $template->update(['body' => 'Edited by admin']);
        $this->seed(EmailTemplateSeeder::class);
        $this->assertSame('Edited by admin', $template->fresh()->body);
    }

    public function test_admin_can_create_edit_and_deactivate_while_hr_cannot_access(): void
    {
        Livewire::test(CreateEmailTemplate::class)->fillForm(['code' => 'custom_round_1', 'name' => 'Custom invitation', 'round_number' => 1, 'purpose' => 'Invitation', 'subject' => 'Hello {{company_name}}', 'body' => 'Join {{link}}', 'is_active' => true])->call('create')->assertHasNoErrors();
        $template = EmailTemplate::where('code', 'custom_round_1')->firstOrFail();
        Livewire::test(EditEmailTemplate::class, ['record' => $template->id])->fillForm(['is_active' => false])->call('save')->assertHasNoErrors();
        $this->assertFalse($template->fresh()->is_active);
        $hr = User::factory()->create(['is_active' => true]);
        $hr->assignRole('HR Executive');
        $this->actingAs($hr)->get(EmailTemplateResource::getUrl())->assertForbidden();
        $this->expectException(HttpException::class);
        app(EmailTemplateService::class)->save($hr, $template->toArray(), $template);
    }

    public function test_template_email_action_renders_shared_fields_and_stores_snapshot(): void
    {
        $candidate = $this->candidate();
        $template = EmailTemplate::where('code', 'round_1_invitation')->firstOrFail();
        Livewire::test(ListCandidates::class)->callTableBulkAction('emailCandidates', [$candidate], $this->message($template))->assertHasNoErrors();
        $this->assertCount(1, $this->transport->messages());
        $mail = $this->transport->messages()->first()->getOriginalMessage();
        $this->assertSame([], $mail->getTo());
        $this->assertSame([], $mail->getCc());
        $this->assertCount(1, $mail->getBcc());
        $this->assertStringContainsString('https://example.test/exam', $mail->getTextBody());
        $this->assertStringNotContainsString('{{', $mail->getTextBody());
        $this->assertDatabaseHas('candidate_email_batches', ['email_template_id' => $template->id, 'status' => 'submitted']);
    }

    public function test_clearance_and_invitation_stage_checks_block_invalid_candidates(): void
    {
        $candidate = $this->candidate();
        foreach (['round_1_cleared', 'round_2_invitation', 'round_3_invitation'] as $code) {
            $template = EmailTemplate::where('code', $code)->firstOrFail();
            try {
                app(CandidateOutreachService::class)->email($this->admin, [$candidate->id], $this->message($template));
                $this->fail('Stage check bypassed');
            } catch (ValidationException) {
                $this->assertCount(0, $this->transport->messages());
            }
        }
        foreach ([1, 2, 3] as $number) {
            $candidate->interviewRounds()->where('round_number', $number)->update(['status' => 'Passed']);
            $template = EmailTemplate::where('code', "round_{$number}_cleared")->firstOrFail();
            app(CandidateOutreachService::class)->email($this->admin, [$candidate->id], $this->message($template));
        }
        $this->assertCount(3, $this->transport->messages());
    }

    public function test_missing_values_unknown_placeholders_and_inactive_templates_block_sending(): void
    {
        $candidate = $this->candidate();
        $template = EmailTemplate::first();
        foreach ([['link' => null], ['body' => 'Hello {{candidate_name}}'], ['subject' => "Injected\r\nBcc: other@example.test"]] as $change) {
            try {
                app(CandidateOutreachService::class)->email($this->admin, [$candidate->id], [...$this->message($template), ...$change]);
                $this->fail('Invalid message accepted');
            } catch (ValidationException) {
                $this->assertCount(0, $this->transport->messages());
            }
        }
        $template->update(['is_active' => false]);
        $this->expectException(ValidationException::class);
        app(CandidateOutreachService::class)->email($this->admin, [$candidate->id], $this->message($template));
    }

    public function test_long_formatted_sample_can_be_edited_and_sent_as_html_with_bcc_only(): void
    {
        $this->seed(UiDeveloperEmailTemplateSeeder::class);
        $template = EmailTemplate::where('code', 'ui_developer_round_1_overview')->firstOrFail();
        $this->assertTrue($template->is_active);
        $this->assertStringContainsString('16 October 2026', $template->body);
        $this->assertStringNotContainsString('{{', $template->subject.$template->body);
        Livewire::test(EditEmailTemplate::class, ['record' => $template->id])
            ->fillForm(['is_active' => true, 'body' => $template->body."\n\n**Please confirm receipt.**"])
            ->call('save')->assertHasNoErrors();
        $template->refresh();
        $this->seed(UiDeveloperEmailTemplateSeeder::class);
        $this->assertSame($template->body, $template->fresh()->body);
        // Complete saved content sends without any separate shared fields.
        $data = ['token' => (string) Str::uuid(), 'email_template_id' => $template->id,
            'subject' => $template->subject, 'body' => $template->body, 'body_format' => $template->body_format];
        Livewire::test(ListCandidates::class)->callTableBulkAction('emailCandidates', [$this->candidate()], $data)->assertHasNoErrors();
        $this->assertCount(1, $this->transport->messages());
        $mail = $this->transport->messages()->first()->getOriginalMessage();
        $this->assertSame([], $mail->getTo());
        $this->assertSame([], $mail->getCc());
        $this->assertCount(1, $mail->getBcc());
        $this->assertStringContainsString('<h2>Job Details</h2>', $mail->getHtmlBody());
        $this->assertStringContainsString('<strong>Exam Key:</strong> 1009', $mail->getHtmlBody());
        $this->assertStringContainsString('https://play.google.com/store/apps/details?id=com.apjsoftwares.itquiz', $mail->getHtmlBody());
        $this->assertStringContainsString('<ol>', $mail->getHtmlBody());
        $this->assertStringContainsString('20 October 2026', $mail->getTextBody());
        $this->assertStringContainsString('Employee Benefits', $mail->getTextBody());
        $this->assertStringNotContainsString('{{', $mail->getHtmlBody());
        $batch = DB::table('candidate_email_batches')->where('token', $data['token'])->first();
        $this->assertSame('markdown', $batch->body_format);
        $this->assertSame($template->subject, $mail->getSubject());
        $this->assertSame($template->body, $batch->body);
        $this->assertSame(trim($template->body), trim($mail->getTextBody()));
        $this->assertStringContainsString('**Please confirm receipt.**', $batch->body);
    }

    public function test_formatted_output_strips_raw_html_and_unsafe_links(): void
    {
        $html = EmailTemplateService::safeHtml("## Assessment\n\n<script>alert(1)</script>\n\n[unsafe](javascript:alert%281%29)\n\n[safe](https://example.test)");
        $this->assertStringContainsString('<h2>Assessment</h2>', $html);
        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('href="javascript:', $html);
        $this->assertStringContainsString('href="https://example.test"', $html);
    }

    public function test_editor_and_composer_previews_render_styled_document_content(): void
    {
        $template = EmailTemplate::first();
        $template->update(['subject' => 'Assessment invitation', 'body_format' => 'markdown',
            'body' => "## Assessment details\n\nFirst paragraph.\n\nSecond paragraph.\n\n- **Test:** UI Developer\n\n1. [Download app](https://example.test/app)"]);
        $editor = Livewire::test(EditEmailTemplate::class, ['record' => $template->id]);
        $composer = Livewire::test(ListCandidates::class)
            ->mountTableBulkAction('emailCandidates', [$this->candidate()])
            ->setTableBulkActionData(['email_template_id' => $template->id, 'subject' => $template->subject, 'body' => $template->body, 'body_format' => 'markdown']);

        $previews = [$editor->instance()->form->toHtml(), $composer->instance()->getSchema($composer->instance()->getMountedActionSchemaName())->toHtml()];
        foreach ($previews as $html) {
            $dom = new \DOMDocument;
            @$dom->loadHTML($html);
            $xpath = new \DOMXPath($dom);
            $preview = '//*[contains(concat(" ", normalize-space(@class), " "), " fi-prose ")]';
            $this->assertSame(1, $xpath->query($preview.'//h2[text()="Assessment details"]')->length);
            $this->assertSame(1, $xpath->query($preview.'//p[text()="Second paragraph."]')->length);
            $this->assertSame(1, $xpath->query($preview.'//ul/li/strong[text()="Test:"]')->length);
            $this->assertSame(1, $xpath->query($preview.'//ol/li/a[@href="https://example.test/app"]')->length);
        }
        $editor->fillForm(['body_format' => 'plain', 'body' => "<script>alert(1)</script>\nLiteral message"])
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', escape: false);
    }

    public function test_shared_exam_dates_allow_end_only_but_reject_reversed_range(): void
    {
        $service = app(EmailTemplateService::class);
        $this->assertSame('2026-11-05', $service->variables(null, ['exam_end_date' => '2026-11-05'])['exam_end_date']);
        $this->expectException(ValidationException::class);
        $service->variables(null, ['exam_start_date' => '2026-11-05', 'exam_end_date' => '2026-11-01']);
    }
}
