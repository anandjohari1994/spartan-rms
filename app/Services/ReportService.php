<?php

namespace App\Services;

use App\Models\CallLog;
use App\Models\Candidate;
use App\Models\FollowUp;
use App\Models\InterviewRound;
use App\Models\User;
use App\Support\Recruitment;
use Carbon\Carbon;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class ReportService
{
    public const TYPES = ['daily' => 'Daily candidate registration', 'hr' => 'HR-wise candidates', 'calls' => 'Calling performance', 'interviews' => 'Interview round status', 'selected' => 'Selected candidates', 'followups' => 'Pending follow-ups', 'positions' => 'Job position recruitment', 'range' => 'Date range recruitment'];

    public function validate(User $actor, array $filters, bool $export = false): array
    {
        abort_unless($actor->isAdmin() && $actor->can($export ? 'reports.export' : 'reports.view'), 403);

        return Validator::make($filters, [
            'type' => ['required', Rule::in(array_keys(self::TYPES))], 'from' => 'nullable|date', 'until' => 'nullable|date|after_or_equal:from',
            'assigned_hr_id' => 'nullable|integer|exists:users,id', 'job_position_id' => 'nullable|integer|exists:job_positions,id', 'location' => 'nullable|string|max:120',
            'recruitment_status' => ['nullable', Rule::in(Recruitment::STATUSES)], 'calling_status' => ['nullable', Rule::in(Recruitment::CALLS)], 'round_number' => 'nullable|integer|between:1,3',
        ])->validate();
    }

    private function candidates(User $actor, array $f)
    {
        $q = Candidate::visibleTo($actor);
        foreach (['assigned_hr_id', 'job_position_id', 'recruitment_status', 'calling_status'] as $key) {
            if (filled($f[$key] ?? null)) {
                $q->where($key, $f[$key]);
            }
        }
        if (filled($f['location'] ?? null)) {
            $q->where('location', $f['location']);
        }
        if (filled($f['round_number'] ?? null)) {
            $q->whereHas('interviewRounds', fn ($q) => $q->where('round_number', $f['round_number'])->whereNotIn('status', ['Not Started', 'Cancelled']));
        }

        return $q;
    }

    private function dates($q, string $column, array $f)
    {
        return $q->when($f['from'] ?? null, fn ($q, $v) => $q->where($column, '>=', Carbon::parse($v)->startOfDay()))->when($f['until'] ?? null, fn ($q, $v) => $q->where($column, '<=', Carbon::parse($v)->endOfDay()));
    }

    public function rows(User $actor, array $filters, ?int $limit = null): \Generator
    {
        $f = $this->validate($actor, $filters);
        $type = $f['type'];
        $candidates = $this->candidates($actor, $f);
        if ($type === 'calls') {
            $q = $this->dates(CallLog::whereIn('candidate_id', $candidates->select('id')), 'called_at', $f);
            if (filled($f['calling_status'] ?? null)) {
                $q->where('call_status', $f['calling_status']);
            }
            $q->selectRaw("hr_id, COUNT(*) AS total, COUNT(DISTINCT candidate_id) AS unique_count, SUM(CASE WHEN call_status = 'Connected' THEN 1 ELSE 0 END) AS connected")->groupBy('hr_id')->with('hr')->orderBy('hr_id');
            $map = fn ($r) => ['HR' => $r->hr?->name, 'Calls' => $r->total, 'Unique candidates' => $r->unique_count, 'Connected' => (int) $r->connected, 'Unsuccessful' => $r->total - $r->connected];
        } elseif ($type === 'interviews') {
            $q = $this->dates(InterviewRound::whereIn('candidate_id', $candidates->select('id')), 'scheduled_at', $f)->with('candidate')->orderBy('id');
            if (filled($f['round_number'] ?? null)) {
                $q->where('round_number', $f['round_number']);
            }
            $map = fn ($r) => ['Code' => $r->candidate?->candidate_code, 'Candidate' => $r->candidate?->full_name, 'Round' => $r->round_number, 'Type' => $r->round_type, 'Status' => $r->status, 'Scheduled' => $r->scheduled_at?->format('Y-m-d H:i'), 'Completed' => $r->completed_at?->format('Y-m-d H:i'), 'Score' => $r->score];
        } elseif ($type === 'followups') {
            $q = $this->dates(FollowUp::whereIn('candidate_id', $candidates->select('id'))->where('status', 'Pending'), 'scheduled_at', $f)->with(['candidate', 'assignedHr'])->orderBy('id');
            $map = fn ($r) => ['Code' => $r->candidate?->candidate_code, 'Candidate' => $r->candidate?->full_name, 'HR' => $r->assignedHr?->name, 'Scheduled' => $r->scheduled_at->format('Y-m-d H:i'), 'Priority' => $r->priority, 'Overdue' => $r->is_overdue ? 'Yes' : 'No'];
        } else {
            // entry_date is a DATE column; compare date-only strings on every database engine.
            $q = $candidates->when($f['from'] ?? null, fn ($q, $v) => $q->whereDate('entry_date', '>=', $v))->when($f['until'] ?? null, fn ($q, $v) => $q->whereDate('entry_date', '<=', $v));
            if ($type === 'daily') {
                $q->selectRaw('entry_date, COUNT(*) AS total')->groupBy('entry_date')->orderBy('entry_date');
                $map = fn ($r) => ['Entry date' => $r->entry_date->toDateString(), 'Candidates' => $r->total];
            } elseif ($type === 'hr') {
                $q->selectRaw('assigned_hr_id, COUNT(*) AS total')->groupBy('assigned_hr_id')->with('assignedHr')->orderBy('assigned_hr_id');
                $map = fn ($r) => ['HR' => $r->assignedHr?->name, 'Candidates' => $r->total];
            } elseif ($type === 'positions') {
                $q->selectRaw("job_position_id, COUNT(*) AS total, SUM(CASE WHEN recruitment_status IN ('Selected','Joined') THEN 1 ELSE 0 END) AS selected")->groupBy('job_position_id')->with('jobPosition')->orderBy('job_position_id');
                $map = fn ($r) => ['Position' => $r->jobPosition?->name, 'Candidates' => $r->total, 'Selected / joined' => (int) $r->selected];
            } else {
                if ($type === 'selected') {
                    $q->whereIn('recruitment_status', ['Selected', 'Joined']);
                }
                $q->with(['jobPosition', 'assignedHr'])->orderBy('id');
                $map = fn ($r) => ['Code' => $r->candidate_code, 'Entry date' => $r->entry_date->toDateString(), 'Candidate' => $r->full_name, 'Mobile' => $r->mobile_normalized, 'Email' => $r->email, 'Position' => $r->jobPosition?->name, 'Location' => $r->location, 'HR' => $r->assignedHr?->name, 'Recruitment status' => $r->recruitment_status, 'Calling status' => $r->calling_status];
            }
        }
        $count = 0;
        foreach ($q->lazy(500) as $row) {
            if ($limit !== null && $count >= $limit) {
                break;
            } $count++;
            yield $map($row);
        }
    }

    public static function csvCell(mixed $value): string
    {
        $value = (string) ($value ?? '');

        return preg_match('/^[\s\x00-\x1F]*[=+@-]/u', $value) || preg_match('/^[\t\r\n]/', $value) ? "'".$value : $value;
    }

    public function export(User $actor, array $filters)
    {
        $f = $this->validate($actor, $filters, true);
        app(AuditService::class)->record($actor, $actor, 'report.exported', [], $f);

        return response()->streamDownload(function () use ($actor, $f) {
            $handle = fopen('php://output', 'w');
            fwrite($handle, "\xEF\xBB\xBF");
            $header = false;
            foreach ($this->rows($actor, $f) as $row) {
                if (! $header) {
                    fputcsv($handle, array_keys($row), ',', '"', '');
                    $header = true;
                }
                fputcsv($handle, array_map(self::csvCell(...), array_values($row)), ',', '"', '');
            }
            if (! $header) {
                fputcsv($handle, ['No matching records'], ',', '"', '');
            } fclose($handle);
        }, 'spartan-'.$f['type'].'-'.now()->format('Ymd-His').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
}
