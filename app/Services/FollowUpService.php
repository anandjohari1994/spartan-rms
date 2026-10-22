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

class FollowUpService
{
    public function save(User $actor, array $data, ?FollowUp $followUp = null): FollowUp
    {
        return DB::transaction(function () use ($actor, $data, $followUp) {
            if (! $followUp) Validator::make($data, ['candidate_id' => 'required|integer'])->validate();
            $candidateId = $followUp?->candidate_id ?? ($data['candidate_id'] ?? 0);
            $c = Candidate::whereKey($candidateId)->lockForUpdate()->firstOrFail();
            Gate::forUser($actor)->authorize('update', $c);
            abort_unless($actor->can('workflow.manage'), 403);
            $f = $followUp ? FollowUp::whereKey($followUp->id)->firstOrFail() : new FollowUp;
            if ($f->exists && $f->status !== 'Pending') {
                throw ValidationException::withMessages(['status' => 'Completed or cancelled follow-ups are historical records. Create a new follow-up.']);
            }
            $v = Validator::make($data, ['scheduled_at' => 'required|date', 'priority' => ['required', Rule::in(['Low', 'Medium', 'High'])], 'status' => ['required', Rule::in(['Pending', 'Completed', 'Cancelled'])], 'notes' => 'nullable|string|max:10000'])->validate();
            $old = $f->getAttributes();
            $exists = $f->exists;
            $f->forceFill([...$v, 'candidate_id' => $c->id, 'assigned_hr_id' => $c->assigned_hr_id, 'created_by' => $f->created_by ?? $actor->id, 'completed_at' => $v['status'] === 'Completed' ? now() : null])->save();
            app(AuditService::class)->record($actor, $f, $exists ? 'followup.updated' : 'followup.created', $old, $f->getAttributes());

            return $f;
        }, 3);
    }

    public function complete(User $actor, FollowUp $followUp): FollowUp
    {
        return $this->save($actor, [...$followUp->getAttributes(), 'status' => 'Completed'], $followUp);
    }
}
