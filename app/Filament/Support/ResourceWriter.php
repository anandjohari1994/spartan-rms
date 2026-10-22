<?php

namespace App\Filament\Support;

use App\Models\CallLog;
use App\Models\Candidate;
use App\Models\FollowUp;
use App\Models\JobPosition;
use App\Models\User;
use App\Services\AuditService;
use App\Services\CallService;
use App\Services\CandidateService;
use App\Services\DocumentService;
use App\Services\FollowUpService;
use App\Services\UserService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ResourceWriter
{
    public static function save(string $model, array $data, ?Model $record = null): Model
    {
        try {
            return self::performSave($model, $data, $record);
        } catch (ValidationException $e) {
            throw ValidationException::withMessages(collect($e->errors())->mapWithKeys(fn ($messages, $key) => ['data.'.$key => $messages])->all());
        }
    }

    private static function performSave(string $model, array $data, ?Model $record = null): Model
    {
        $actor = auth()->user();
        if ($model === Candidate::class) {
            return DB::transaction(function () use ($actor, $record, $data) {
                $c = $record ? app(CandidateService::class)->update($actor, $record, $data) : app(CandidateService::class)->create($actor, $data);
                if (($data['resume'] ?? null) instanceof UploadedFile) {
                    app(DocumentService::class)->upload($actor, $c, $data['resume']);
                }

                return $c;
            });
        }
        if ($model === FollowUp::class) {
            return app(FollowUpService::class)->save($actor, $data, $record);
        }
        if ($model === CallLog::class) {
            abort_if($record, 403);

            return app(CallService::class)->create($actor, $data);
        }
        if ($model === User::class) {
            return app(UserService::class)->save($actor, $data, $record);
        }
        abort_unless($model === JobPosition::class, 403);
        Gate::authorize($record ? 'update' : 'create', $record ?? $model);
        abort_unless($actor->can('positions.manage'), 403);
        $v = Validator::make($data, ['name' => 'required|string|max:255', 'code' => ['required', 'alpha_dash', 'max:40', Rule::unique('job_positions')->ignore($record?->id)], 'description' => 'nullable|string|max:10000', 'is_active' => 'required|boolean'])->validate();

        return DB::transaction(function () use ($record, $v, $actor) {
            $r = $record ?? new JobPosition;
            $old = $r->getAttributes();
            $r->fill($v)->save();
            app(AuditService::class)->record($actor, $r, 'position.saved', $old, $r->getAttributes());

            return $r;
        });
    }
}
