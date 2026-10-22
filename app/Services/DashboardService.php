<?php

namespace App\Services;

use App\Models\CallLog;
use App\Models\Candidate;
use App\Models\FollowUp;
use App\Models\InterviewRound;
use App\Models\User;

class DashboardService
{
    public function stats(User $user): array
    {
        abort_unless($user->is_active && $user->can('candidates.view'), 403);
        $c = Candidate::visibleTo($user)->selectRaw("COUNT(*) AS total, SUM(CASE WHEN DATE(entry_date) = ? THEN 1 ELSE 0 END) AS today, SUM(CASE WHEN interest_status = 'Interested' THEN 1 ELSE 0 END) AS interested, SUM(CASE WHEN recruitment_status IN ('Selected','Joined') THEN 1 ELSE 0 END) AS selected", [today()->toDateString()])->first();
        $calls = CallLog::visibleTo($user)->whereDate('called_at', today())->selectRaw("COUNT(*) AS total, COUNT(DISTINCT candidate_id) AS unique_candidates, SUM(CASE WHEN call_status = 'Connected' THEN 1 ELSE 0 END) AS connected")->first();
        $follow = FollowUp::visibleTo($user)->where('status', 'Pending')->selectRaw('COUNT(*) AS pending, SUM(CASE WHEN scheduled_at < ? THEN 1 ELSE 0 END) AS overdue, SUM(CASE WHEN DATE(scheduled_at) = ? THEN 1 ELSE 0 END) AS today', [now(), today()->toDateString()])->first();
        $rounds = InterviewRound::visibleTo($user)->where('status', 'Passed')->selectRaw('round_number, COUNT(*) AS total')->groupBy('round_number')->pluck('total', 'round_number');
        $stats = [
            ($user->isAdmin() ? 'Total candidates' : 'My candidates') => (int) $c->total, 'Added today' => (int) $c->today,
            'Calls attempted today' => (int) $calls->total, 'Connected calls today' => (int) $calls->connected,
            'Unique candidates called today' => (int) $calls->unique_candidates, 'Unsuccessful calls today' => (int) $calls->total - (int) $calls->connected,
            'Interested candidates' => (int) $c->interested, 'Round 1 passed' => (int) ($rounds[1] ?? 0), 'Round 2 passed' => (int) ($rounds[2] ?? 0),
            'Round 3 passed' => (int) ($rounds[3] ?? 0), 'Final selections' => (int) $c->selected, 'Pending follow-ups' => (int) $follow->pending,
            "Today's follow-ups" => (int) $follow->today, 'Overdue follow-ups' => (int) $follow->overdue,
        ];
        if ($user->isAdmin()) {
            $stats['HR executives'] = User::role('HR Executive')->count();
        }

        return $stats;
    }

    public function chart(User $user, string $kind): array
    {
        abort_unless($user->is_active && $user->can('candidates.view'), 403);
        $q = Candidate::visibleTo($user);
        if ($kind === 'growth') {
            $counts = $q->where('entry_date', '>=', today()->subDays(13))->selectRaw('DATE(entry_date) AS day, COUNT(*) AS total')->groupByRaw('DATE(entry_date)')->pluck('total', 'day');
            $result = [];
            for ($i = 13; $i >= 0; $i--) {
                $day = today()->subDays($i)->toDateString();
                $result[$day] = (int) ($counts[$day] ?? 0);
            }

return $result;
        }
        if ($kind === 'hr') {
            abort_unless($user->isAdmin(), 403);

            return $q->join('users', 'users.id', '=', 'candidates.assigned_hr_id')->selectRaw('users.id, users.name, COUNT(*) AS total')->groupBy('users.id', 'users.name')->get()->mapWithKeys(fn ($r) => [$r->name.' (#'.$r->id.')' => (int) $r->total])->all();
        }
        if ($kind === 'position') {
            return $q->join('job_positions', 'job_positions.id', '=', 'candidates.job_position_id')->selectRaw('job_positions.name, COUNT(*) AS total')->groupBy('job_positions.name')->pluck('total', 'name')->all();
        }
        if ($kind === 'outcomes') {
            return InterviewRound::visibleTo($user)->selectRaw('status, COUNT(*) AS total')->groupBy('status')->pluck('total', 'status')->all();
        }
        $stats = $this->stats($user);

        return ['Registered' => $q->count(), 'Interested' => $stats['Interested candidates'], 'Round 1 passed' => $stats['Round 1 passed'], 'Round 2 passed' => $stats['Round 2 passed'], 'Round 3 passed' => $stats['Round 3 passed'], 'Selected / joined' => $stats['Final selections']];
    }
}
