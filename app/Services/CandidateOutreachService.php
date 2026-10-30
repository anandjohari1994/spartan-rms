<?php

namespace App\Services;

use App\Mail\CandidateAssessmentInvitation;
use App\Models\Candidate;
use App\Models\EmailTemplate;
use App\Models\User;
use App\Support\Recruitment;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

class CandidateOutreachService
{
    private function selected(User $actor, array $ids): Collection
    {
        Validator::make(['candidates' => $ids], [
            'candidates' => 'required|array|min:1|max:100',
            'candidates.*' => 'required|integer|distinct',
        ])->validate();
        $records = Candidate::whereIn('id', $ids)->orderBy('id')->get();
        if ($records->count() !== count($ids)) {
            throw ValidationException::withMessages(['candidates' => 'Selection includes deleted or missing candidates. Refresh and select again.']);
        }
        foreach ($records as $record) {
            Gate::forUser($actor)->authorize('update', $record);
        }

        return $records;
    }

    public function bulkStatus(User $actor, array $ids, string $status, ?string $reason): int
    {
        abort_unless($actor->isAdmin(), 403);

        return DB::transaction(function () use ($actor, $ids, $status, $reason) {
            $records = $this->selected($actor, $ids);
            foreach ($records as $record) {
                app(CandidateService::class)->status($actor, $record, $status, $reason);
            }

            return $records->count();
        });
    }

    public function email(User $actor, array $ids, array $data): int
    {
        abort_unless($actor->canEmailCandidates(), 403);
        $records = $this->selected($actor, $ids);
        $templateId = Validator::make($data, ['email_template_id' => 'nullable|integer|exists:email_templates,id'])->validate()['email_template_id'] ?? null;
        $template = $templateId ? EmailTemplate::findOrFail($templateId) : null;
        if ($template?->round_number === 1 && $template->purpose === 'Invitation') {
            abort_unless($actor->can('workflow.manage'), 403);
        }
        if ($template) {
            if (! $template->is_active) {
                throw ValidationException::withMessages(['email_template_id' => 'This template is inactive. Choose an active template.']);
            }
            $invalidStage = $records->filter(function ($candidate) use ($template) {
                $rounds = $candidate->interviewRounds()->get()->keyBy('round_number');
                if ($template->purpose === 'Cleared') {
                    return $rounds->get($template->round_number)?->status !== 'Passed';
                }
                if (in_array($candidate->recruitment_status, ['Rejected', 'Withdrawn', 'Joined']) || $candidate->interest_status === 'Not Interested') {
                    return true;
                }
                for ($i = 1; $i < $template->round_number; $i++) {
                    if ($rounds->get($i)?->status !== 'Passed') {
                        return true;
                    }
                }

                return ! $rounds->has($template->round_number) || in_array($rounds->get($template->round_number)->status, ['Passed', 'Failed', 'Cancelled']);
            });
            if ($invalidStage->isNotEmpty()) {
                throw ValidationException::withMessages(['email_template_id' => 'Nothing sent. Candidates do not meet this template stage: '.$invalidStage->pluck('candidate_code')->implode(', ')]);
            }
        }
        $renderer = app(EmailTemplateService::class);
        $variables = $renderer->variables($template, $data);
        foreach (['subject', 'body'] as $field) {
            if (isset($data[$field]) && is_string($data[$field])) {
                $data[$field] = $renderer->render($data[$field], $variables);
            }
        }
        $values = Validator::make($data, [
            'token' => 'required|uuid',
            'subject' => ['required', 'string', 'max:200', 'not_regex:/[\r\n]/'],
            'body' => 'required|string|max:20000',
            'body_format' => ['sometimes', Rule::in(['plain', 'markdown'])],
        ])->validate();
        $invalid = $records->filter(fn ($c) => ! filter_var($c->email, FILTER_VALIDATE_EMAIL));
        if ($invalid->isNotEmpty()) {
            throw ValidationException::withMessages(['body' => 'Nothing sent. Add valid email addresses for: '.$invalid->pluck('candidate_code')->implode(', ')]);
        }
        $recipients = $records->pluck('email')->map(fn ($email) => strtolower(trim($email)))->unique()->values()->all();
        $mailer = config('mail.default');
        if (! in_array(config("mail.mailers.{$mailer}.transport"), ['smtp', 'sendmail', 'ses', 'ses-v2', 'mailgun', 'postmark', 'resend'], true)) {
            throw ValidationException::withMessages(['body' => 'Configure a delivery mailer such as SMTP first. The current mailer does not send candidate invitations.']);
        }
        // Claim once before delivery; never automatically retry an ambiguous SMTP result.
        $claimed = DB::table('candidate_email_batches')->insertOrIgnore([
            'token' => $values['token'], 'actor_id' => $actor->id,
            'candidate_ids' => json_encode($records->modelKeys()), 'recipient_count' => count($recipients),
            'subject' => $values['subject'], 'status' => 'sending', 'created_at' => now(), 'updated_at' => now(),
            'email_template_id' => $template?->id, 'body' => $values['body'], 'body_format' => $values['body_format'] ?? 'plain',
        ]);
        if (! $claimed) {
            $attempt = DB::table('candidate_email_batches')->where('token', $values['token'])->first();
            if (! $attempt) {
                throw ValidationException::withMessages(['body' => 'The email attempt could not be recorded. No email was sent by this request. Check the database migrations and server logs.']);
            }
            $message = match ($attempt->status) {
                'submitted' => 'This email was already accepted by the mail provider. No second email was sent. Check the candidate email history; if the schedule is missing, correct it without resending.',
                'uncertain' => 'The previous email attempt has unconfirmed delivery. This form cannot resend it. Check the original Laravel log error and the mail provider before retrying. After fixing the problem and confirming the email was not accepted, close this window and open Email selected (BCC) again.',
                default => 'This email attempt is still marked as sending; it may be in progress or have been interrupted. No second email was sent. Check the server logs and mail provider before starting another attempt.',
            };
            throw ValidationException::withMessages(['body' => "Email attempt #{$attempt->id} ({$attempt->updated_at}): {$message}"]);
        }
        try {
            // No candidate appears in To or Cc. All recipients receive the same message.
            $sent = Mail::bcc($recipients)->send(new CandidateAssessmentInvitation($values['subject'], $values['body'], $values['body_format'] ?? 'plain'));
            if ($sent === null) {
                throw new \RuntimeException('Mail delivery was cancelled.');
            }
        } catch (Throwable $e) {
            DB::table('candidate_email_batches')->where('token', $values['token'])->update(['status' => 'uncertain', 'updated_at' => now()]);
            $attemptId = DB::table('candidate_email_batches')->where('token', $values['token'])->value('id');
            Log::error('Candidate email delivery failed.', ['email_attempt_id' => $attemptId, 'exception' => $e]);
            throw ValidationException::withMessages(['body' => "Email attempt #{$attemptId}: Delivery could not be confirmed. The original error is in storage/logs/laravel.log. Check the mail provider before retrying; some recipients may have been accepted."]);
        }
        $submittedAt = now();
        DB::transaction(function () use ($records, $template, $values, $submittedAt) {
            DB::table('candidate_email_batches')->where('token', $values['token'])->update(['status' => 'submitted', 'updated_at' => $submittedAt]);
            $fields = ['last_email_sent_at'];
            if ($template?->round_number === 1 && $template->purpose === 'Invitation') {
                $fields[] = 'round_one_email_sent_at';
            }
            foreach ($fields as $field) {
                Candidate::whereKey($records->modelKeys())
                    ->where(fn ($query) => $query->whereNull($field)->orWhere($field, '<', $submittedAt))
                    ->update([$field => $submittedAt]);
            }
        }, 3);
        try {
            DB::transaction(function () use ($actor, $records, $template, $values, $submittedAt) {
                foreach ($records as $record) {
                    app(AuditService::class)->record($actor, $record, 'candidate.email_submitted', [], ['batch' => $values['token'], 'subject' => $values['subject']]);
                    if ($template?->round_number !== 1 || $template->purpose !== 'Invitation') {
                        continue;
                    }

                    // Recheck under the same candidate lock used by interview updates:
                    // an assessment result recorded during delivery must never be reset.
                    $candidate = Candidate::whereKey($record->id)->lockForUpdate()->first();
                    $round = $candidate?->interviewRounds()->where('round_number', 1)->first();
                    if (! $round || ! in_array($round->status, ['Not Started', 'Pending', 'Scheduled', 'On Hold'])
                        || in_array($candidate->recruitment_status, ['Selected', 'Joined', 'Rejected', 'Withdrawn'])
                        || $candidate->interest_status === 'Not Interested'
                        || $candidate->interviewRounds()->where('round_number', '>', 1)->whereNotIn('status', ['Not Started', 'Cancelled'])->exists()) {
                        app(AuditService::class)->record($actor, $record, 'candidate.email_scheduling_skipped', [], ['batch' => $values['token'], 'reason' => 'Candidate or interview stage no longer permits Round 1 scheduling.']);

                        continue;
                    }
                    app(InterviewService::class)->update($actor, $round, ['status' => 'Scheduled', 'scheduled_at' => $submittedAt]);
                }
            }, 3);
        } catch (Throwable $e) {
            report($e);
            throw ValidationException::withMessages(['body' => 'Email was submitted to the mail provider, but the candidate updates could not be saved. Check Round 1 and the activity log, and update the schedule manually. Do not resend this email to fix the schedule.']);
        }

        return count($recipients);
    }

    public function whatsappUrl(User $actor, Candidate $candidate, string $message): string
    {
        $record = Candidate::findOrFail($candidate->id);
        Gate::forUser($actor)->authorize('update', $record);
        Validator::make(['message' => $message], ['message' => 'required|string|max:4000'])->validate();
        $phone = ltrim(Recruitment::mobile($record->mobile_normalized), '+');

        return 'https://wa.me/'.$phone.'?text='.rawurlencode($message);
    }
}
