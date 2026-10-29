<?php

namespace App\Services;

use App\Mail\CandidateAssessmentInvitation;
use App\Models\Candidate;
use App\Models\User;
use App\Support\Recruitment;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Throwable;

class CandidateOutreachService
{
    private function selected(User $actor, array $ids): Collection
    {
        abort_unless($actor->isAdmin(), 403);
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
        $records = $this->selected($actor, $ids);
        $values = Validator::make($data, [
            'token' => 'required|uuid',
            'subject' => ['required', 'string', 'max:200', 'not_regex:/[\r\n]/'],
            'body' => 'required|string|max:20000',
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
        ]);
        if (! $claimed) {
            throw ValidationException::withMessages(['body' => 'This submission has already been attempted. Check the activity log and mail provider before composing another invitation.']);
        }
        try {
            // No candidate appears in To or Cc. All recipients receive the same plain-text message.
            $sent = Mail::bcc($recipients)->send(new CandidateAssessmentInvitation($values['subject'], $values['body']));
            if ($sent === null) {
                throw new \RuntimeException('Mail delivery was cancelled.');
            }
        } catch (Throwable $e) {
            DB::table('candidate_email_batches')->where('token', $values['token'])->update(['status' => 'uncertain', 'updated_at' => now()]);
            report($e);
            throw ValidationException::withMessages(['body' => 'Delivery could not be confirmed. Check the mail provider before retrying; some recipients may have been accepted.']);
        }
        DB::table('candidate_email_batches')->where('token', $values['token'])->update(['status' => 'submitted', 'updated_at' => now()]);
        foreach ($records as $record) {
            app(AuditService::class)->record($actor, $record, 'candidate.email_submitted', [], ['batch' => $values['token'], 'subject' => $values['subject']]);
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
