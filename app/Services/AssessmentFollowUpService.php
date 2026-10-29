<?php

namespace App\Services;

use App\Models\Candidate;
use App\Models\FollowUp;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AssessmentFollowUpService
{
    public const RESPONSES = ['Completed', 'Not completed yet', 'Facing a problem', 'Not reachable', 'Not interested'];

    public const PURPOSES = ['General', 'Assessment completion', 'Assessment support', 'Assessment result'];

    public function record(User $actor, Candidate $candidate, array $data, ?FollowUp $source = null): void
    {
        DB::transaction(function () use ($actor, $candidate, $data, $source) {
            $c = Candidate::whereKey($candidate->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($actor)->authorize('update', $c);
            abort_unless($actor->can('workflow.manage'), 403);
            $v = Validator::make($data, [
                'token' => 'required|uuid', 'response' => ['required', Rule::in(self::RESPONSES)],
                'called_at' => 'required|date|before_or_equal:now',
                'next_at' => 'nullable|required_unless:response,Not interested|date|after:now',
                'notes' => 'nullable|required_if:response,Facing a problem|string|max:8000',
                'cancel_reminders' => 'sometimes|boolean',
            ])->validate();
            if ($c->callLogs()->where('submission_token', $v['token'])->where('hr_id', $actor->id)->exists()) {
                return;
            }
            $task = $source ? $c->followUps()->whereKey($source->id)->where('status', 'Pending')->first() : null;
            if ($source && ! $task) {
                throw ValidationException::withMessages(['response' => 'This follow-up is no longer pending. Refresh the list.']);
            }
            $round = $c->interviewRounds()->where('round_number', 1)->firstOrFail();
            if (in_array($round->status, ['Passed', 'Failed', 'Cancelled'])) {
                throw ValidationException::withMessages(['response' => 'Round 1 already has a final result or is cancelled. Use its interview record instead.']);
            }
            if (in_array($c->recruitment_status, ['Selected', 'Joined', 'Rejected', 'Withdrawn'])) {
                throw ValidationException::withMessages(['response' => 'This candidate is no longer in the assessment follow-up stage.']);
            }
            if ($c->assessment_progress === 'Reported completed' && in_array($v['response'], ['Not completed yet', 'Facing a problem'])) {
                throw ValidationException::withMessages(['response' => 'Completion was already reported. Verify the result instead; ask an administrator to investigate conflicting information.']);
            }
            $notes = 'Assessment follow-up: '.$v['response'].'. '.($v['notes'] ?? '');
            $call = app(CallService::class)->create($actor, [
                'candidate_id' => $c->id, 'called_at' => $v['called_at'],
                'call_status' => $v['response'] === 'Not reachable' ? 'Not Reachable' : 'Connected', 'notes' => $notes,
            ]);
            $call->forceFill(['submission_token' => $v['token']])->save();
            $old = $c->only(['assessment_progress', 'assessment_reported_completed_at', 'interest_status']);
            $purpose = 'Assessment completion';
            switch ($v['response']) {
                case 'Completed':
                    $c->assessment_progress = 'Reported completed';
                    $c->assessment_reported_completed_at ??= $v['called_at'];
                    $purpose = 'Assessment result';
                    break;
                case 'Not completed yet': $c->assessment_progress = 'Not completed';
                    break;
                case 'Facing a problem': $c->assessment_progress = 'Needs help';
                    $purpose = 'Assessment support';
                    break;
                case 'Not interested': $c->interest_status = 'Not Interested';
                    break;
                case 'Not reachable':
                    $purpose = $task && $task->purpose !== 'General' ? $task->purpose : ($c->assessment_progress === 'Reported completed' ? 'Assessment result' : 'Assessment completion');
                    break;
            }
            $c->forceFill(['updated_by' => $actor->id])->save();
            app(AuditService::class)->record($actor, $c, 'assessment.followed_up', $old, [...$c->only(array_keys($old)), 'response' => $v['response']]);
            if ($task) {
                app(FollowUpService::class)->complete($actor, $task);
            }
            // Retire only assessment reminders, preserving unrelated pending tasks.
            if ($v['response'] === 'Completed' || ($v['response'] === 'Not interested' && ($v['cancel_reminders'] ?? false))) {
                $purposes = $v['response'] === 'Completed' ? ['Assessment completion', 'Assessment support'] : array_slice(self::PURPOSES, 1);
                foreach ($c->followUps()->where('status', 'Pending')->whereIn('purpose', $purposes)->get() as $reminder) {
                    app(FollowUpService::class)->save($actor, [...$reminder->getAttributes(), 'status' => 'Cancelled'], $reminder);
                }
            }
            if ($v['response'] !== 'Not interested') {
                // Reuse an existing pending task of this purpose instead of duplicating it.
                $next = $c->followUps()->where('status', 'Pending')->where('purpose', $purpose)->orderBy('id')->first();
                app(FollowUpService::class)->save($actor, [
                    'candidate_id' => $c->id, 'scheduled_at' => $v['next_at'], 'priority' => 'Medium',
                    'status' => 'Pending', 'purpose' => $purpose, 'notes' => $notes,
                ], $next);
            }
        });
    }
}
