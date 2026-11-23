<?php

namespace App\Services;

use App\Mail\CandidateOfferLetter;
use App\Models\Candidate;
use App\Models\OfferLetter;
use App\Models\OfferLetterDelivery;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

class OfferLetterService
{
    public const TEXT_FIELDS = [
        'reference' => 'Reference number', 'address_line_1' => 'Address line 1', 'address_line_2' => 'Address line 2 / City',
        'state' => 'State', 'pin_code' => 'PIN code', 'designation' => 'Designation', 'department' => 'Department',
        'work_location' => 'Work location',
        'signatory_name' => 'Authorized signatory name', 'signatory_designation' => 'Signatory designation',
    ];

    public const DATE_FIELDS = ['letter_date' => 'Letter date', 'joining_date' => 'Proposed joining date', 'commencement_date' => 'Employment commencement date', 'document_deadline' => 'Document submission deadline', 'valid_until' => 'Offer valid until', 'acceptance_deadline' => 'Acceptance deadline'];

    public const TERM_FIELDS = ['notice_probation' => 'Notice period during probation', 'notice_confirmed' => 'Notice period after confirmation', 'working_hours' => 'Regular working hours', 'working_days' => 'Weekly working days', 'leave_policy' => 'Leave policy'];

    public static function previousBusinessDay(?string $date): ?string
    {
        if (! $date || Validator::make(['date' => $date], ['date' => 'date_format:Y-m-d'])->fails()) {
            return null;
        }

        return CarbonImmutable::parse($date)->subWeekday()->toDateString();
    }

    /** Update only blank fields or fields still matching their previous automatic value. */
    public static function dependentDates(string $field, ?string $value, ?string $old, array $current): array
    {
        $changes = [];
        $update = function (string $key, ?string $next, ?string $previous) use ($current, &$changes): bool {
            if (filled($current[$key] ?? null) && ($current[$key] ?? null) !== $previous) {
                return false;
            }
            $changes[$key] = $next;

            return true;
        };
        if ($field === 'joining_date') {
            $next = self::previousBusinessDay($value);
            $previous = self::previousBusinessDay($old);
            if ($value && ! $next) {
                return [];
            }
            if (! $update('commencement_date', $next, $previous)) {
                return [];
            }
            $field = 'commencement_date';
            $value = $next;
            $old = $current['commencement_date'] ?? null;
        }
        if ($field === 'commencement_date') {
            $update('document_deadline', $value, $old);
            if (! $update('valid_until', $value, $old)) {
                return $changes;
            }
            $field = 'valid_until';
            $old = $current['valid_until'] ?? null;
        }
        if ($field === 'valid_until') {
            $update('acceptance_deadline', $value, $old);
        }

        return $changes;
    }

    private function authorize(User $actor, Candidate $candidate): void
    {
        abort_unless($actor->canEmailCandidates(), 403);
        Gate::forUser($actor)->authorize('update', $candidate);
    }

    private function eligible(Candidate $candidate): void
    {
        if (! Candidate::qualifiedForOffer()->whereKey($candidate->id)->exists()) {
            throw ValidationException::withMessages(['offer' => "{$candidate->candidate_code}: Round 1 and Round 2 must both be Passed, with the client interview still pending and the candidate active and interested."]);
        }
    }

    public function defaults(Candidate $candidate): array
    {
        $details = $candidate->offerLetter?->details ?? [
            'reference' => 'SI/OFFER/'.$candidate->candidate_code,
            'designation' => $candidate->jobPosition?->name,
            'letter_date' => today()->toDateString(), 'other_label' => 'Other allowance',
        ];
        // Older drafts retain their saved PDF. Opening one offers its existing CTC as input;
        // the new allocation is applied only when the user explicitly saves a new revision.
        if (! isset($details['annual_package']) && isset($details['basic_monthly'])) {
            $details['annual_package'] = number_format(array_sum(array_map(fn ($key) => (float) ($details[$key.'_monthly'] ?? 0), array_keys(OfferLetterPdf::SALARY_COMPONENTS))) * 12, 2, '.', '');
        }

        return [...config('offer_letters.defaults'), ...$details];
    }

    public function saveDraft(User $actor, Candidate $candidate, array $data): OfferLetter
    {
        return DB::transaction(function () use ($actor, $candidate, $data) {
            $candidate = Candidate::whereKey($candidate->id)->lockForUpdate()->firstOrFail();
            $this->authorize($actor, $candidate);
            $this->eligible($candidate);
            $existing = $candidate->offerLetter()->lockForUpdate()->first();
            if ($existing && ($existing->submitted_at || $existing->status === 'submitted')) {
                abort_unless($actor->isAdmin(), 403);
            }
            if ($existing && (! in_array($existing->status, ['draft', 'submitted']) || ($data['revision'] ?? '') !== $existing->revision)) {
                throw ValidationException::withMessages(['offer' => 'This offer has changed or delivery is unconfirmed. Refresh before continuing. Sending and unconfirmed offers cannot be edited.']);
            }
            $rules = ['employment_type' => ['required', Rule::in(['Full-Time', 'Contractual', 'Other'])], 'probation_months' => 'required|integer|min:0|max:36', 'salary_payment_day' => 'required|integer|min:1|max:31', 'optional_clause' => ['required', Rule::in(['include', 'omit'])]];
            foreach (self::TEXT_FIELDS as $field => $label) {
                $rules[$field] = ['required', 'string', 'max:150', 'not_regex:/[\x00-\x1F\x7F\[\]]/'];
            }
            foreach (self::TERM_FIELDS as $field => $label) {
                $rules[$field] = ['required', 'string', 'max:'.($field === 'leave_policy' ? 600 : 80), 'not_regex:/[\x00-\x1F\x7F\[\]]/'];
            }
            $rules['pin_code'] = 'required|digits:6';
            foreach (self::DATE_FIELDS as $field => $label) {
                $rules[$field] = ['required', 'date_format:Y-m-d'];
                if ($field !== 'letter_date') {
                    $rules[$field][] = 'after_or_equal:letter_date';
                }
            }
            $rules['acceptance_deadline'][] = 'before_or_equal:valid_until';
            $rules['annual_package'] = OfferSalaryCalculator::ANNUAL_CTC_RULES;
            if (is_string($data['leave_policy'] ?? null)) {
                $data['leave_policy'] = preg_replace('/\r\n?|\n/', ' ', $data['leave_policy']);
            }
            $details = Validator::make($data, $rules)->validate();
            // Ignore any client-supplied components; the server owns the complete calculation.
            $details = [...$details, ...app(OfferSalaryCalculator::class)->calculate($details['annual_package']), 'other_label' => 'Other allowance'];
            Validator::make(['email' => $candidate->email], ['email' => 'required|email:rfc|max:255'])->validate();
            $details['candidate_name'] = $candidate->full_name;
            $pdf = app(OfferLetterPdf::class)->generate($details);
            $revision = (string) Str::uuid();
            $path = 'offer-letters/'.$candidate->id.'/'.$revision.'.pdf';
            Storage::disk('private')->put($path, $pdf);
            $offer = $existing ?? new OfferLetter(['candidate_id' => $candidate->id]);
            $offer->fill(['created_by' => $actor->id, 'revision' => $revision, 'details' => $details, 'recipient' => trim($candidate->email), 'status' => 'draft', 'pdf_path' => $path, 'pdf_sha256' => hash('sha256', $pdf)])->save();
            app(AuditService::class)->record($actor, $candidate, 'offer.draft_saved', [], ['offer_id' => $offer->id, 'revision' => $revision]);

            return $offer;
        });
    }

    public function pdf(OfferLetter $offer): string
    {
        abort_unless(preg_match('#^offer-letters/\d+/[a-f0-9-]{36}\.pdf$#', $offer->pdf_path), 404);
        $pdf = Storage::disk('private')->get($offer->pdf_path);
        if (! hash_equals($offer->pdf_sha256, hash('sha256', $pdf))) {
            throw ValidationException::withMessages(['offer' => 'The saved PDF has changed. Re-save the draft before sending.']);
        }

        return $pdf;
    }

    public function preview(User $actor, Candidate $candidate)
    {
        $this->authorize($actor, $candidate);
        $offer = $candidate->offerLetter()->firstOrFail();
        if ($offer->status === 'draft') {
            $this->eligible($candidate);
        }

        return response($this->pdf($offer), 200, [
            'Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="conditional-offer-'.$candidate->id.'.pdf"',
            'Cache-Control' => 'private, no-store, max-age=0', 'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** Preflight the entire selection, then reserve each immutable revision before any mail is sent. */
    public function send(User $actor, array $ids, array $revisions): array
    {
        return $this->deliver($actor, $ids, $revisions);
    }

    public function resend(User $actor, int $candidateId, string $revision, string $token): array
    {
        abort_unless($actor->isAdmin(), 403);
        Validator::make(['token' => $token], ['token' => 'required|uuid'])->validate();

        return $this->deliver($actor, [$candidateId], [$candidateId => $revision], $token);
    }

    private function deliver(User $actor, array $ids, array $revisions, ?string $resendToken = null): array
    {
        abort_unless($actor->canEmailCandidates(), 403);
        Validator::make(['candidates' => $ids], ['candidates' => 'required|array|min:1|max:25', 'candidates.*' => 'required|integer|distinct'])->validate();
        $mailer = 'hr';
        if (! in_array(config("mail.mailers.{$mailer}.transport"), ['smtp', 'sendmail', 'ses', 'ses-v2', 'mailgun', 'postmark', 'resend'], true)) {
            throw ValidationException::withMessages(['offer' => 'Configure SMTP delivery for the HR mail account before sending offer letters.']);
        }
        $attempts = DB::transaction(function () use ($actor, $ids, $revisions, $resendToken) {
            $candidates = Candidate::whereKey($ids)->orderBy('id')->lockForUpdate()->get();
            if ($candidates->count() !== count($ids)) {
                throw ValidationException::withMessages(['offer' => 'Nothing sent. A candidate was deleted or is missing. Refresh your selection.']);
            }
            $offers = [];
            foreach ($candidates as $candidate) {
                $this->authorize($actor, $candidate);
                $this->eligible($candidate);
                $offer = $candidate->offerLetter()->lockForUpdate()->first();
                if (! $offer || $offer->status !== ($resendToken ? 'submitted' : 'draft') || ($revisions[$candidate->id] ?? '') !== $offer->revision) {
                    throw ValidationException::withMessages(['offer' => "Nothing sent. {$candidate->candidate_code}: refresh and review the current offer. Use Resend offer for a submitted letter. Changed or unconfirmed offers cannot be sent."]);
                }
                if ($offer->submitted_at || $resendToken) {
                    abort_unless($actor->isAdmin(), 403);
                }
                if ($candidate->full_name !== $offer->details['candidate_name'] || trim((string) $candidate->email) !== $offer->recipient) {
                    throw ValidationException::withMessages(['offer' => "Nothing sent. {$candidate->candidate_code}: name or email changed. Save and preview a new draft."]);
                }
                foreach (['valid_until', 'acceptance_deadline'] as $date) {
                    if ($offer->details[$date] < today()->toDateString()) {
                        throw ValidationException::withMessages(['offer' => "Nothing sent. {$candidate->candidate_code}: the offer has expired. Update the dates and preview again."]);
                    }
                }
                $this->pdf($offer);
                $offers[] = $offer;
            }
            if ($resendToken && OfferLetterDelivery::where('token', $resendToken)->exists()) {
                throw ValidationException::withMessages(['offer' => 'This resend request has already been processed. No second email was sent. Refresh to see its status.']);
            }
            $attempts = [];
            foreach ($offers as $offer) {
                $attempt = $offer->deliveries()->create([
                    'token' => $resendToken ?? (string) Str::uuid(), 'revision' => $offer->revision,
                    'sent_by' => $actor->id, 'recipient' => $offer->recipient, 'details' => $offer->details,
                    'pdf_path' => $offer->pdf_path, 'pdf_sha256' => $offer->pdf_sha256, 'status' => 'sending',
                ]);
                $attempts[] = [$offer, $attempt];
                $offer->update(['status' => 'sending']);
                app(AuditService::class)->record($actor, $offer->candidate, $resendToken ? 'offer.resending' : 'offer.sending', [], ['offer_id' => $offer->id, 'revision' => $offer->revision, 'delivery_id' => $attempt->id]);
            }

            return $attempts;
        });
        $result = ['submitted' => 0, 'uncertain' => 0];
        foreach ($attempts as [$offer, $attempt]) {
            try {
                $sent = Mail::mailer($mailer)->to($offer->recipient)->send(new CandidateOfferLetter($offer->details, $this->pdf($offer), 'conditional-offer-'.$offer->candidate_id.'.pdf'));
                if ($sent === null) {
                    throw new \RuntimeException('Offer letter mail was cancelled.');
                }
            } catch (Throwable $exception) {
                report($exception);
                DB::transaction(function () use ($offer, $attempt) {
                    $offer->update(['status' => 'uncertain']);
                    $attempt->update(['status' => 'uncertain']);
                });
                app(AuditService::class)->record($actor, $offer->candidate, 'offer.delivery_unconfirmed', [], ['offer_id' => $offer->id, 'delivery_id' => $attempt->id]);
                $result['uncertain']++;

                continue;
            }
            // Never roll an external delivery back to a sendable draft.
            DB::transaction(function () use ($offer, $attempt) {
                $submittedAt = now();
                $offer->update(['status' => 'submitted', 'submitted_at' => $submittedAt]);
                $attempt->update(['status' => 'submitted', 'submitted_at' => $submittedAt]);
            });
            $message = $sent->getSymfonySentMessage()->getOriginalMessage();
            app(AuditService::class)->record($actor, $offer->candidate, 'offer.submitted', [], [
                'offer_id' => $offer->id, 'revision' => $attempt->revision, 'delivery_id' => $attempt->id,
                'candidate_name' => $attempt->details['candidate_name'],
                'candidate_email' => $attempt->recipient,
                'from' => implode(', ', array_map(fn ($address) => $address->getAddress(), $message->getFrom())),
                'to' => implode(', ', array_map(fn ($address) => $address->getAddress(), $message->getTo())),
                'recipient_type' => 'To', 'mailer' => $mailer,
                'subject' => $message->getSubject(), 'body' => $message->getTextBody(), 'body_format' => 'plain',
                'status' => 'submitted', 'submitted_at' => $attempt->submitted_at->toIso8601String(),
                'message_id' => $sent->getMessageId(),
                'attachment' => 'conditional-offer-'.$offer->candidate_id.'.pdf',
                'attachment_sha256' => $attempt->pdf_sha256,
            ]);
            $result['submitted']++;
        }

        return $result;
    }
}
