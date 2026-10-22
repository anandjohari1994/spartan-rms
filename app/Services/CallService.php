<?php

namespace App\Services;

use App\Models\CallLog;
use App\Models\Candidate;
use App\Models\User;
use App\Support\Recruitment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class CallService
{
    public function create(User $actor, array $data): CallLog
    {
        return DB::transaction(function () use ($actor, $data) {
            Validator::make($data, ['candidate_id' => 'required|integer'])->validate();
            $c = Candidate::whereKey($data['candidate_id'] ?? 0)->lockForUpdate()->firstOrFail();
            Gate::forUser($actor)->authorize('update', $c);
            abort_unless($actor->can('workflow.manage'), 403);
            $v = Validator::make($data, ['call_status' => ['required', Rule::in(array_values(array_diff(Recruitment::CALLS, ['Not Called'])))], 'called_at' => 'required|date|before_or_equal:now', 'duration_seconds' => 'nullable|integer|min:0|max:86400', 'notes' => 'nullable|string|max:10000'])->validate();
            $log = new CallLog;
            $log->forceFill([...$v, 'candidate_id' => $c->id, 'hr_id' => $actor->id])->save();
            $latest = $c->callLogs()->orderByDesc('called_at')->orderByDesc('id')->first();
            $c->forceFill(['calling_status' => $latest->call_status, 'updated_by' => $actor->id])->save();
            app(AuditService::class)->record($actor, $log, 'call.created', [], $log->getAttributes());

            return $log;
        }, 3);
    }
}
