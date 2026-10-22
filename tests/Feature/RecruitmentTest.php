<?php

namespace Tests\Feature;

use App\Filament\Resources\CandidateResource\Pages\CreateCandidate;
use App\Filament\Resources\CandidateResource\Pages\ListCandidates;
use App\Filament\Widgets\CandidateDistribution;
use App\Filament\Widgets\HrPerformance;
use App\Models\ActivityLog;
use App\Models\Candidate;
use App\Models\FollowUp;
use App\Models\JobPosition;
use App\Models\User;
use App\Services\CallService;
use App\Services\CandidateService;
use App\Services\DashboardService;
use App\Services\DocumentService;
use App\Services\FollowUpService;
use App\Services\InterviewService;
use App\Services\ReportService;
use App\Services\UserService;
use Database\Seeders\DatabaseSeeder;
use Filament\Auth\Pages\Login;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class RecruitmentTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected User $hr;

    protected User $other;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole('Super Admin');
        $this->hr = User::factory()->create(['is_active' => true]);
        $this->hr->assignRole('HR Executive');
        $this->other = User::factory()->create(['is_active' => true]);
        $this->other->assignRole('HR Executive');
    }

    protected function data(array $extra = []): array
    {
        return [...['entry_date' => today()->toDateString(), 'full_name' => 'Candidate One', 'mobile' => '9876543210', 'qualification' => 'B.Tech', 'passout_year' => 2024, 'location' => 'Pune', 'job_position_id' => JobPosition::first()->id, 'experience_type' => 'Fresher', 'calling_status' => 'Not Called', 'interest_status' => 'Interested'], ...$extra];
    }

    protected function candidate(?User $actor = null, array $extra = []): Candidate
    {
        return app(CandidateService::class)->create($actor ?? $this->hr, $this->data($extra));
    }

    public function test_candidate_creation_ignores_manipulated_ownership_and_status(): void
    {
        $c = $this->candidate(extra: ['assigned_hr_id' => $this->other->id, 'created_by' => $this->admin->id, 'recruitment_status' => 'Selected', 'candidate_code' => 'forged']);
        $this->assertSame($this->hr->id, $c->assigned_hr_id);
        $this->assertSame($this->hr->id, $c->created_by);
        $this->assertSame('New', $c->recruitment_status);
        $this->assertSame('+919876543210', $c->mobile_normalized);
        $this->assertMatchesRegularExpression('/^SI-\d{4}-000001$/', $c->candidate_code);
        $this->assertSame(['Pending', 'Not Started', 'Not Started'], $c->interviewRounds->pluck('status')->all());
        $this->assertDatabaseHas('activity_logs', ['event' => 'candidate.created']);
    }

    public function test_scope_and_policy_prevent_cross_hr_access(): void
    {
        $c = $this->candidate();
        $this->assertSame(0, Candidate::visibleTo($this->other)->count());
        $this->assertSame(1, Candidate::visibleTo($this->admin)->count());
        foreach (['view', 'update', 'delete', 'reassign'] as $ability) {
            $this->assertFalse($this->other->can($ability, $c));
        }
        $this->assertFalse($this->hr->can('delete', $c));
        $this->expectException(AuthorizationException::class);
        app(CandidateService::class)->update($this->other, $c, $this->data());
    }

    public function test_duplicate_normalized_mobile_is_generic_and_sequence_is_unique(): void
    {
        $first = $this->candidate();
        $second = $this->candidate(extra: ['mobile' => '9876543211']);
        $this->assertNotSame($first->candidate_code, $second->candidate_code);
        $this->expectException(ValidationException::class);
        $this->candidate($this->other, ['mobile' => '+91 98765-43210']);
    }

    public function test_reassignment_moves_pending_followups_and_changes_history_visibility(): void
    {
        $c = $this->candidate();
        $f = app(FollowUpService::class)->save($this->hr, ['candidate_id' => $c->id, 'scheduled_at' => now()->addDay(), 'status' => 'Pending', 'priority' => 'High', 'assigned_hr_id' => $this->other->id]);
        $this->assertSame($this->hr->id, $f->assigned_hr_id);
        app(CandidateService::class)->reassign($this->admin, $c, $this->other->id, 'Workload balancing');
        $this->assertSame($this->other->id, $f->fresh()->assigned_hr_id);
        $this->assertSame(0, FollowUp::visibleTo($this->hr)->count());
        $this->assertSame(1, FollowUp::visibleTo($this->other)->count());
        $this->expectException(AuthorizationException::class);
        app(FollowUpService::class)->complete($this->hr, $f);
    }

    public function test_hr_cannot_reassign_even_through_service(): void
    {
        $this->expectException(AuthorizationException::class);
        app(CandidateService::class)->reassign($this->hr, $this->candidate(), $this->other->id, 'Forged');
    }

    public function test_interview_progression_and_override_reason(): void
    {
        $c = $this->candidate();
        $round2 = $c->interviewRounds[1];
        try {
            app(InterviewService::class)->update($this->admin, $round2, ['status' => 'Scheduled', 'scheduled_at' => now()]);
            $this->fail('Missing override should fail');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('status', $e->errors());
        }
        app(InterviewService::class)->update($this->admin, $round2, ['status' => 'Scheduled', 'scheduled_at' => now(), 'override_reason' => 'Prior assessment verified externally']);
        $this->assertSame('Scheduled', $round2->fresh()->status);
        $this->assertDatabaseHas('activity_logs', ['event' => 'interview.updated']);
    }

    public function test_interview_normal_flow_and_final_selection(): void
    {
        $c = $this->candidate();
        foreach ($c->interviewRounds as $round) {
            if ($round->round_number > 1) {
                app(InterviewService::class)->update($this->hr, $round, ['status' => 'Pending']);
            }
            app(InterviewService::class)->update($this->hr, $round, ['status' => 'Passed', 'score' => 80]);
        }
        app(CandidateService::class)->status($this->hr, $c, 'Selected');
        $this->assertSame('Selected', $c->fresh()->recruitment_status);
        $this->expectException(ValidationException::class);
        app(InterviewService::class)->update($this->hr, $c->interviewRounds[0], ['status' => 'Failed']);
    }

    public function test_call_history_uses_chronologically_latest_status_and_actor(): void
    {
        $c = $this->candidate();
        $service = app(CallService::class);
        $service->create($this->hr, ['candidate_id' => $c->id, 'call_status' => 'Connected', 'called_at' => now(), 'hr_id' => $this->other->id]);
        $service->create($this->hr, ['candidate_id' => $c->id, 'call_status' => 'Busy', 'called_at' => now()->subDay()]);
        $this->assertSame('Connected', $c->fresh()->calling_status);
        $this->assertSame(2, $c->callLogs()->count());
        $this->assertSame($this->hr->id, $c->callLogs()->first()->hr_id);
    }

    public function test_soft_delete_restore_and_auditing(): void
    {
        $c = $this->candidate();
        app(CandidateService::class)->delete($this->admin, $c);
        $this->assertSoftDeleted($c);
        app(CandidateService::class)->restore($this->admin, $c);
        $this->assertFalse($c->fresh()->trashed());
        $this->assertDatabaseHas('activity_logs', ['event' => 'candidate.restored']);
    }

    public function test_private_resume_upload_download_and_denial(): void
    {
        Storage::fake('private');
        $c = $this->candidate();
        $file = UploadedFile::fake()->createWithContent('resume.pdf', "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF");
        $doc = app(DocumentService::class)->upload($this->hr, $c, $file);
        Storage::disk('private')->assertExists($doc->private_path);
        $this->actingAs($this->other)->get(route('documents.download', $doc))->assertForbidden();
        $this->actingAs($this->hr)->get(route('documents.download', $doc))->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        app(CandidateService::class)->delete($this->admin, $c);
        $this->actingAs($this->admin)->get(route('documents.download', $doc))->assertForbidden();
    }

    public function test_fake_resume_rejected(): void
    {
        $c = $this->candidate();
        $this->expectException(ValidationException::class);
        app(DocumentService::class)->upload($this->hr, $c, UploadedFile::fake()->createWithContent('resume.pdf', '<?php echo "bad";'));
    }

    public function test_inactive_authenticated_user_rejected(): void
    {
        $this->hr->update(['is_active' => false]);
        $this->actingAs($this->hr)->get('/admin/candidates')->assertForbidden();
    }

    public function test_filament_lists_are_scoped_and_admin_screens_forbidden(): void
    {
        $c = $this->candidate();
        $other = $this->candidate($this->other, ['mobile' => '9876543211']);
        $this->actingAs($this->hr);
        Livewire::test(ListCandidates::class)->assertCanSeeTableRecords([$c])->assertCanNotSeeTableRecords([$other]);
        $this->get('/admin/candidates/'.$other->id.'/edit')->assertNotFound();
        $this->get('/admin/users')->assertForbidden();
        $this->get('/admin/activity-logs')->assertForbidden();
    }

    public function test_filament_resource_pages_render(): void
    {
        $c = $this->candidate();
        $this->actingAs($this->admin);
        foreach (['/admin/candidates', '/admin/candidates/create', '/admin/candidates/'.$c->id, '/admin/candidates/'.$c->id.'/edit', '/admin/follow-ups', '/admin/follow-ups/create', '/admin/call-logs', '/admin/call-logs/create', '/admin/users', '/admin/users/create', '/admin/users/'.$this->hr->id.'/edit', '/admin/job-positions', '/admin/job-positions/create', '/admin/activity-logs'] as $url) {
            $this->get($url)->assertOk();
        }
    }

    public function test_admin_and_hr_login_and_inactive_login_denied(): void
    {
        foreach ([$this->admin, $this->hr] as $user) {
            Livewire::test(Login::class)->fillForm(['email' => $user->email, 'password' => 'password'])->call('authenticate')->assertHasNoFormErrors();
            $this->assertAuthenticatedAs($user);
            auth()->logout();
        }
        $this->hr->update(['is_active' => false]);
        Livewire::test(Login::class)->fillForm(['email' => $this->hr->email, 'password' => 'password'])->call('authenticate')->assertHasFormErrors();
        $this->assertGuest();
    }

    public function test_dashboards_are_scoped_and_widgets_render(): void
    {
        $this->candidate();
        $this->candidate($this->other, ['mobile' => '9876543211']);
        $this->assertSame(1, app(DashboardService::class)->stats($this->hr)['My candidates']);
        $this->assertSame(1, app(DashboardService::class)->stats($this->hr)['Added today']);
        $this->assertSame(1, app(DashboardService::class)->chart($this->hr, 'growth')[today()->toDateString()]);
        $this->assertSame(2, app(DashboardService::class)->stats($this->admin)['Total candidates']);
        foreach ([$this->admin, $this->hr] as $user) {
            $this->actingAs($user);
            $this->get('/admin')->assertOk();
            foreach (['RecruitmentStats', 'CandidateGrowth', 'RecruitmentFunnel', 'PositionDistribution', 'InterviewOutcomes', 'RecentCandidates', 'PendingCalls', 'InterviewPipeline', 'UpcomingFollowUps', 'OverdueFollowUps'] as $widget) {
                Livewire::test('App\\Filament\\Widgets\\'.$widget)->assertOk();
            }
        }
        $this->actingAs($this->admin);
        Livewire::test(HrPerformance::class)->assertOk();
        Livewire::test(CandidateDistribution::class)->assertOk();
    }

    public function test_reports_filters_export_and_formula_protection(): void
    {
        $c = $this->candidate(extra: ['full_name' => '=HYPERLINK("bad")']);
        $this->candidate($this->other, ['mobile' => '9876543211']);
        $service = app(ReportService::class);
        $filters = ['type' => 'range', 'assigned_hr_id' => $this->hr->id, 'from' => today()->toDateString(), 'until' => today()->toDateString()];
        $this->assertCount(1, iterator_to_array($service->rows($this->admin, $filters)));
        $this->assertSame("'=1+1", $service::csvCell('=1+1'));
        $this->assertSame("'\t=1", $service::csvCell("\t=1"));
        $this->actingAs($this->hr)->get('/admin/reports')->assertForbidden();
        $this->actingAs($this->admin)->get('/admin/reports')->assertOk();
        $this->get('/admin/settings')->assertOk();
        app(CallService::class)->create($this->hr, ['candidate_id' => $c->id, 'call_status' => 'Connected', 'called_at' => now()]);
        app(FollowUpService::class)->save($this->hr, ['candidate_id' => $c->id, 'scheduled_at' => now(), 'status' => 'Pending', 'priority' => 'High']);
        foreach (array_keys($service::TYPES) as $type) {
            $this->assertIsArray(iterator_to_array($service->rows($this->admin, ['type' => $type])));
        }
        $response = $service->export($this->admin, $filters);
        ob_start();
        $response->sendContent();
        $csv = ob_get_clean();
        $this->assertStringContainsString("'=HYPERLINK", $csv);
        $this->assertStringNotContainsString('9876543211', $csv);
        $this->assertDatabaseHas('activity_logs', ['event' => 'report.exported']);
    }

    public function test_hr_cannot_call_report_service_directly(): void
    {
        $this->expectException(HttpException::class);
        app(ReportService::class)->export($this->hr, ['type' => 'range']);
    }

    public function test_livewire_candidate_creation_and_reassignment_actions(): void
    {
        $this->actingAs($this->hr);
        Livewire::test(CreateCandidate::class)->fillForm($this->data())->call('create')->assertHasNoFormErrors();
        $c = Candidate::firstOrFail();
        $this->assertSame($this->hr->id, $c->assigned_hr_id);
        $this->actingAs($this->admin);
        Livewire::test(ListCandidates::class)->callTableAction('reassign', $c, ['new_hr_id' => $this->other->id, 'reason' => 'Coverage'])->assertHasNoActionErrors();
        $this->assertSame($this->other->id, $c->fresh()->assigned_hr_id);
    }

    public function test_livewire_duplicate_mobile_shows_a_field_error(): void
    {
        $this->candidate(); $this->actingAs($this->other);
        Livewire::test(CreateCandidate::class)->fillForm($this->data())->call('create')->assertHasFormErrors(['mobile']);
        $this->assertDatabaseCount('candidates', 1);
    }

    public function test_interview_relation_actions_validate_and_render(): void
    {
        $candidate = $this->candidate(); $this->actingAs($this->hr);
        $component = Livewire::test(\App\Filament\Resources\CandidateResource\RelationManagers\InterviewRoundsRelationManager::class, ['ownerRecord' => $candidate, 'pageClass' => \App\Filament\Resources\CandidateResource\Pages\ViewCandidate::class]);
        $component->assertCanSeeTableRecords($candidate->interviewRounds)
            ->callTableAction('updateRound', $candidate->interviewRounds[1], ['status' => 'Scheduled', 'scheduled_at' => now()->addDay()->toDateTimeString()])->assertHasActionErrors(['status']);
        $this->assertSame('Not Started', $candidate->interviewRounds[1]->fresh()->status);
    }

    public function test_hr_delete_and_cross_owner_workflow_are_rejected(): void
    {
        $candidate = $this->candidate();
        foreach (['delete', 'interview', 'call', 'followup'] as $action) {
            try {
                match ($action) {
                    'delete' => app(CandidateService::class)->delete($this->hr, $candidate),
                    'interview' => app(InterviewService::class)->update($this->other, $candidate->interviewRounds[0], ['status' => 'Passed']),
                    'call' => app(CallService::class)->create($this->other, ['candidate_id' => $candidate->id, 'called_at' => now(), 'call_status' => 'Connected']),
                    'followup' => app(FollowUpService::class)->save($this->other, ['candidate_id' => $candidate->id, 'scheduled_at' => now(), 'priority' => 'High', 'status' => 'Pending']),
                };
                $this->fail('Manipulated '.$action.' must fail');
            } catch (AuthorizationException $exception) { $this->assertTrue(true); }
        }
    }

    public function test_followup_completion_and_history_are_immutable(): void
    {
        $candidate = $this->candidate(); $this->actingAs($this->hr);
        $f = app(FollowUpService::class)->save($this->hr, ['candidate_id' => $candidate->id, 'scheduled_at' => now()->subHour(), 'priority' => 'High', 'status' => 'Pending']);
        $this->assertTrue($f->is_overdue);
        Livewire::test(\App\Filament\Resources\FollowUpResource\Pages\ListFollowUps::class)->callTableAction('complete', $f)->assertHasNoActionErrors();
        $this->assertSame('Completed', $f->fresh()->status); $this->assertNotNull($f->fresh()->completed_at);
        $this->expectException(ValidationException::class); app(FollowUpService::class)->save($this->hr, ['status' => 'Pending'], $f);
    }

    public function test_document_limits_and_path_traversal(): void
    {
        Storage::fake('private'); $candidate = $this->candidate();
        try { app(DocumentService::class)->upload($this->hr, $candidate, UploadedFile::fake()->create('large.pdf', 5121)); $this->fail('Oversized upload accepted'); }
        catch (ValidationException $e) { $this->assertArrayHasKey('resume', $e->errors()); }
        $doc = app(DocumentService::class)->upload($this->hr, $candidate, UploadedFile::fake()->createWithContent('resume.pdf', "%PDF-1.4\n%%EOF"));
        $doc->forceFill(['private_path' => '../../.env'])->save();
        $this->actingAs($this->hr)->get(route('documents.download', $doc))->assertNotFound();
    }

    public function test_real_docx_container_is_accepted(): void
    {
        Storage::fake('private'); $candidate = $this->candidate();
        $path = tempnam(sys_get_temp_dir(), 'rms-docx-'); $zip = new \ZipArchive;
        $zip->open($path, \ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/></Types>');
        $zip->addFromString('word/document.xml', '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body/></w:document>'); $zip->close();
        try { $doc = app(DocumentService::class)->upload($this->hr, $candidate, new UploadedFile($path, 'resume.docx', null, null, true)); $this->assertSame('application/vnd.openxmlformats-officedocument.wordprocessingml.document', $doc->mime_type); }
        finally { if (file_exists($path)) unlink($path); }
    }

    public function test_final_selection_cannot_be_forged_and_scheduled_time_is_preserved(): void
    {
        $candidate = $this->candidate();
        try { app(CandidateService::class)->status($this->hr, $candidate, 'Selected', 'Forged override'); $this->fail('HR override accepted'); }
        catch (ValidationException $e) { $this->assertArrayHasKey('status', $e->errors()); }
        app(InterviewService::class)->update($this->hr, $candidate->interviewRounds[0], ['status' => 'Scheduled', 'scheduled_at' => now()->addDay()]);
        $this->expectException(ValidationException::class);
        app(InterviewService::class)->update($this->hr, $candidate->interviewRounds[0], ['status' => 'Passed']);
    }

    public function test_user_management_deactivation_role_audit_and_self_protection(): void
    {
        $service = app(UserService::class);
        $u = $service->save($this->admin, ['name' => 'New Recruiter', 'email' => 'recruiter@example.test', 'password' => 'StrongPassword123', 'role' => 'HR Executive', 'is_active' => true]);
        $this->assertTrue($u->hasRole('HR Executive'));
        $this->assertTrue(Hash::check('StrongPassword123', $u->password));
        $service->save($this->admin, ['name' => $u->name, 'email' => $u->email, 'is_active' => false, 'role' => 'HR Executive'], $u);
        $this->assertFalse($u->fresh()->is_active);
        $this->assertDatabaseHas('activity_logs', ['event' => 'permission.changed']);
        $this->assertStringNotContainsString('StrongPassword123', ActivityLog::all()->toJson());
        $this->expectException(ValidationException::class);
        $service->save($this->admin, ['name' => $this->admin->name, 'email' => $this->admin->email, 'role' => 'HR Executive', 'is_active' => false], $this->admin);
    }
}
