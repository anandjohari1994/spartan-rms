<?php

namespace App\Services;

use App\Models\Candidate;
use App\Models\InterviewRound;
use App\Models\User;
use App\Support\Recruitment;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class InterviewService
{
    public function update(User $actor, InterviewRound $round, array $data): InterviewRound
    {
        return DB::transaction(function () use ($actor, $round, $data) {
            $c = Candidate::whereKey($round->candidate_id)->lockForUpdate()->firstOrFail();
            Gate::forUser($actor)->authorize('update', $c);
            abort_unless($actor->can('workflow.manage'), 403);
            $r = $c->interviewRounds()->whereKey($round->id)->firstOrFail();
            $v = Validator::make([...$r->only(['scheduled_at', 'completed_at', 'score', 'remarks']), ...$data], [
                'status' => ['required', Rule::in(Recruitment::INTERVIEWS)], 'scheduled_at' => 'nullable|date|required_if:status,Scheduled',
                'completed_at' => 'nullable|date|before_or_equal:now', 'score' => 'nullable|numeric|min:0|max:100', 'remarks' => 'nullable|string|max:10000', 'override_reason' => 'nullable|string|max:2000',
            ])->validate();
            $override = $actor->isAdmin() && filled($v['override_reason'] ?? null);
            $progressing = ! in_array($v['status'], ['Not Started', 'Cancelled']);
            if (! $override && $progressing && $c->interviewRounds()->where('round_number', '<', $r->round_number)->where('status', '!=', 'Passed')->exists()) {
                throw ValidationException::withMessages(['status' => 'Previous rounds must pass before this round can progress.']);
            }
            if (! $override && $v['status'] !== 'Passed' && ($c->interviewRounds()->where('round_number', '>', $r->round_number)->whereNotIn('status', ['Not Started', 'Cancelled'])->exists() || in_array($c->recruitment_status, ['Selected', 'Joined']))) {
                throw ValidationException::withMessages(['status' => 'Downstream progress or final selection prevents this change. An admin override requires a reason.']);
            }
            $transitions = [
                'Not Started' => ['Pending', 'Scheduled', 'Cancelled'], 'Pending' => ['Scheduled', 'Passed', 'Failed', 'On Hold', 'Cancelled'],
                'Scheduled' => ['Pending', 'Passed', 'Failed', 'On Hold', 'Cancelled'], 'On Hold' => ['Pending', 'Scheduled', 'Passed', 'Failed', 'Cancelled'],
                'Passed' => [], 'Failed' => [], 'Cancelled' => ['Pending'],
            ];
            if (! $override && $r->status !== $v['status'] && ! in_array($v['status'], $transitions[$r->status])) {
                throw ValidationException::withMessages(['status' => 'This transition requires an administrator override and reason.']);
            }
            if (in_array($v['status'], ['Passed', 'Failed'])) {
                $v['completed_at'] = $v['completed_at'] ?? now();
            } else {
                $v['completed_at'] = null;
            }
            if (! empty($v['completed_at']) && ! empty($v['scheduled_at']) && Carbon::parse($v['completed_at'])->lt(Carbon::parse($v['scheduled_at']))) {
                throw ValidationException::withMessages(['completed_at' => 'Completion cannot precede the scheduled time.']);
            }
            $old = $r->getAttributes();
            $reason = $v['override_reason'] ?? null;
            unset($v['override_reason']);
            $r->forceFill([...$v, 'updated_by' => $actor->id])->save();
            if ($r->round_number === 1 && in_array($r->status, ['Passed', 'Failed', 'Cancelled'])) {
                foreach ($c->followUps()->where('status', 'Pending')->whereIn('purpose', ['Assessment completion', 'Assessment support', 'Assessment result'])->get() as $task) {
                    app(FollowUpService::class)->save($actor, [...$task->getAttributes(), 'status' => $task->purpose === 'Assessment result' && $r->status !== 'Cancelled' ? 'Completed' : 'Cancelled'], $task);
                }
            }
            app(AuditService::class)->record($actor, $r, 'interview.updated', $old, [...$r->getAttributes(), 'override_reason' => $reason]);

            return $r;
        }, 3);
    }
}
