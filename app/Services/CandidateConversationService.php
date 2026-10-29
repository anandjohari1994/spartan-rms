<?php

namespace App\Services;

use App\Models\Candidate;
use App\Models\FollowUp;
use App\Models\User;
use App\Support\Recruitment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CandidateConversationService
{
    public const NEXT = ['None', 'Call again', 'Schedule Round 1', 'Schedule Round 2', 'Schedule Round 3'];

    public function record(User $actor, Candidate $candidate, array $data, ?FollowUp $source = null): void
    {
        DB::transaction(function () use ($actor, $candidate, $data, $source) {
            $c = Candidate::whereKey($candidate->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($actor)->authorize('update', $c);
            abort_unless($actor->can('workflow.manage'), 403);
            $v = Validator::make($data, [
                'token' => 'required|uuid', 'called_at' => 'required|date|before_or_equal:now',
                'call_status' => ['required', Rule::in(array_diff(Recruitment::CALLS, ['Not Called']))],
                'interest_status' => ['nullable', Rule::in(['Unknown', 'Interested', 'Not Interested'])],
                'notes' => 'nullable|string|max:8000', 'next_action' => ['required', Rule::in(self::NEXT)],
                'next_at' => 'nullable|required_unless:next_action,None|date|after:now',
                'scheduled_at' => 'nullable|required_if:next_action,Schedule Round 1,Schedule Round 2,Schedule Round 3|date|after:now',
            ])->validate();
            if ($c->callLogs()->where('submission_token', $v['token'])->where('hr_id', $actor->id)->exists()) {
                return;
            }
            $task = $source ? $c->followUps()->whereKey($source->id)->where('status', 'Pending')->first() : null;
            if ($source && ! $task) {
                throw ValidationException::withMessages(['next_action' => 'This follow-up is no longer pending. Refresh the list.']);
            }
            if ($v['call_status'] !== 'Connected' && filled($v['interest_status'] ?? null)) {
                throw ValidationException::withMessages(['interest_status' => 'Update interest only after a connected conversation.']);
            }
            if (str_starts_with($v['next_action'], 'Schedule Round')) {
                $number = (int) substr($v['next_action'], -1);
                app(InterviewService::class)->update($actor, $c->interviewRounds()->where('round_number', $number)->firstOrFail(), ['status' => 'Scheduled', 'scheduled_at' => $v['scheduled_at']]);
            }
            $call = app(CallService::class)->create($actor, [
                'candidate_id' => $c->id, 'called_at' => $v['called_at'], 'call_status' => $v['call_status'], 'notes' => $v['notes'] ?? null,
            ]);
            $call->forceFill(['submission_token' => $v['token']])->save();
            if (filled($v['interest_status'] ?? null)) {
                $old = $c->interest_status;
                $c->forceFill(['interest_status' => $v['interest_status'], 'updated_by' => $actor->id])->save();
                app(AuditService::class)->record($actor, $c, 'candidate.interest_updated', ['interest_status' => $old], ['interest_status' => $v['interest_status']]);
            }
            if ($task) {
                app(FollowUpService::class)->complete($actor, $task);
            }
            if ($v['next_action'] !== 'None') {
                $purpose = $v['next_action'] === 'Schedule Round 1' ? 'Assessment completion' : ($task?->purpose ?? 'General');
                app(FollowUpService::class)->save($actor, [
                    'candidate_id' => $c->id, 'scheduled_at' => $v['next_at'], 'purpose' => $purpose,
                    'status' => 'Pending', 'priority' => 'Medium', 'notes' => $v['next_action'].'. '.($v['notes'] ?? ''),
                ]);
            }
        });
    }
}
