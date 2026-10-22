<?php

namespace App\Services;

use App\Models\Candidate;
use App\Models\CandidateAssignment;
use App\Models\InterviewRound;
use App\Models\User;
use App\Support\Recruitment;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CandidateService
{
    public function __construct(private AuditService $audit) {}

    private function validated(array $data, ?Candidate $candidate = null): array
    {
        $v = Validator::make($data, [
            'entry_date' => 'required|date|before_or_equal:today', 'full_name' => 'required|string|max:255', 'mobile' => 'required|string|max:30',
            'email' => 'nullable|email|max:255', 'qualification' => 'required|string|max:255', 'passout_year' => 'required|integer|min:1960|max:'.(now()->year + 5),
            'location' => 'required|string|max:120', 'job_position_id' => ['required', Rule::exists('job_positions', 'id')->where(fn ($q) => $q->where('is_active', true)->orWhere('id', $candidate?->job_position_id))],
            'experience_type' => ['required', Rule::in(['Fresher', 'Experienced'])], 'current_ctc' => 'nullable|numeric|min:0|max:9999999999.99', 'expected_ctc' => 'nullable|numeric|min:0|max:9999999999.99',
            'calling_status' => ['required', Rule::in(Recruitment::CALLS)], 'interest_status' => ['required', Rule::in(['Unknown', 'Interested', 'Not Interested'])],
            'pan_verification_status' => ['nullable', Rule::in(['Not Verified', 'Verified', 'Failed'])], 'remarks' => 'nullable|string|max:10000',
        ])->validate();
        $normalized = Recruitment::mobile($v['mobile']);
        if (Candidate::withTrashed()->where('mobile_normalized', $normalized)->when($candidate, fn ($q) => $q->whereKeyNot($candidate->id))->exists()) {
            $this->duplicate();
        }
        $v['mobile'] = substr($normalized, 3);
        $v['mobile_normalized'] = $normalized;
        $v['mobile_country_code'] = '+91';

        return $v;
    }

    private function duplicate(): never
    {
        throw ValidationException::withMessages(['mobile' => 'This mobile number is already registered. Contact your administrator.']);
    }

    public function create(User $actor, array $data): Candidate
    {
        Gate::forUser($actor)->authorize('create', Candidate::class);
        try {
            return DB::transaction(function () use ($actor, $data) {
                $values = $this->validated($data);
                $year = now()->year;
                DB::table('candidate_code_sequences')->insertOrIgnore(['year' => $year, 'next_number' => 1]);
                $sequence = DB::table('candidate_code_sequences')->where('year', $year)->lockForUpdate()->first();
                DB::table('candidate_code_sequences')->where('year', $year)->increment('next_number');
                $c = new Candidate;
                $c->forceFill([...$values, 'candidate_code' => sprintf('SI-%d-%06d', $year, $sequence->next_number), 'assigned_hr_id' => $actor->id, 'created_by' => $actor->id, 'recruitment_status' => 'New'])->save();
                foreach (Recruitment::ROUNDS as $number => $type) {
                    (new InterviewRound)->forceFill(['candidate_id' => $c->id, 'round_number' => $number, 'round_type' => $type, 'status' => $number === 1 ? 'Pending' : 'Not Started'])->save();
                }
                $this->audit->record($actor, $c, 'candidate.created', [], $c->getAttributes());

                return $c;
            }, 3);
        } catch (UniqueConstraintViolationException $e) {
            if (str_contains($e->getMessage(), 'mobile_normalized')) {
                $this->duplicate();
            }
            throw $e;
        }
    }

    public function update(User $actor, Candidate $candidate, array $data): Candidate
    {
        try {
            return DB::transaction(function () use ($actor, $candidate, $data) {
                $c = Candidate::whereKey($candidate->id)->lockForUpdate()->firstOrFail();
                Gate::forUser($actor)->authorize('update', $c);
                $old = $c->getAttributes();
                $values = $this->validated($data, $c);
                // Calling status is derived from history once calls exist.
                if ($c->callLogs()->exists()) {
                    unset($values['calling_status']);
                }
                $c->forceFill([...$values, 'updated_by' => $actor->id])->save();
                $this->audit->record($actor, $c, 'candidate.updated', $old, $c->getAttributes());

                return $c;
            }, 3);
        } catch (UniqueConstraintViolationException $e) {
            if (str_contains($e->getMessage(), 'mobile_normalized')) {
                $this->duplicate();
            }
            throw $e;
        }
    }

    public function reassign(User $actor, Candidate $candidate, int $hrId, string $reason): void
    {
        DB::transaction(function () use ($actor, $candidate, $hrId, $reason) {
            $c = Candidate::whereKey($candidate->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($actor)->authorize('reassign', $c);
            Validator::make(['reason' => $reason], ['reason' => 'required|string|max:2000'])->validate();
            $hr = User::whereKey($hrId)->where('is_active', true)->role('HR Executive')->first();
            if (! $hr) {
                throw ValidationException::withMessages(['new_hr_id' => 'Choose an active HR executive.']);
            }
            $old = $c->assigned_hr_id;
            $c->forceFill(['assigned_hr_id' => $hr->id, 'updated_by' => $actor->id])->save();
            $c->followUps()->where('status', 'Pending')->update(['assigned_hr_id' => $hr->id]);
            (new CandidateAssignment)->forceFill(['candidate_id' => $c->id, 'previous_hr_id' => $old, 'new_hr_id' => $hr->id, 'assigned_by' => $actor->id, 'reason' => $reason, 'assigned_at' => now()])->save();
            $this->audit->record($actor, $c, 'candidate.reassigned', ['assigned_hr_id' => $old], ['assigned_hr_id' => $hr->id, 'reason' => $reason]);
        }, 3);
    }

    public function delete(User $actor, Candidate $candidate): void
    {
        $this->changeDeletion($actor, $candidate, false);
    }

    public function restore(User $actor, Candidate $candidate): void
    {
        $this->changeDeletion($actor, $candidate, true);
    }

    private function changeDeletion(User $actor, Candidate $candidate, bool $restore): void
    {
        DB::transaction(function () use ($actor, $candidate, $restore) {
            $c = Candidate::withTrashed()->whereKey($candidate->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($actor)->authorize($restore ? 'restore' : 'delete', $c);
            $restore ? $c->restore() : $c->delete();
            $this->audit->record($actor, $c, $restore ? 'candidate.restored' : 'candidate.deleted');
        });
    }

    public function status(User $actor, Candidate $candidate, string $status, ?string $reason = null): void
    {
        DB::transaction(function () use ($actor, $candidate, $status, $reason) {
            $c = Candidate::whereKey($candidate->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($actor)->authorize('update', $c);
            Validator::make(compact('status', 'reason'), ['status' => ['required', Rule::in(Recruitment::STATUSES)], 'reason' => 'nullable|string|max:2000'])->validate();
            if (in_array($status, ['Selected', 'Joined']) && $c->interviewRounds()->where('status', 'Passed')->count() !== 3 && (! $actor->isAdmin() || ! filled($reason))) {
                throw ValidationException::withMessages(['status' => 'All three rounds must pass. An administrator may override with a reason.']);
            }
            $old = $c->recruitment_status;
            $c->forceFill(['recruitment_status' => $status, 'updated_by' => $actor->id])->save();
            $this->audit->record($actor, $c, 'candidate.status_changed', ['status' => $old], compact('status', 'reason'));
        }, 3);
    }
}
