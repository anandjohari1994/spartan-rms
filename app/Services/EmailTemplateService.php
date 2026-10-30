<?php

namespace App\Services;

use App\Models\EmailTemplate;
use App\Models\User;
use App\Support\Recruitment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class EmailTemplateService
{
    public const VARIABLES = ['company_name', 'round_name', 'round_number', 'session_time', 'link', 'deadline', 'instructions', 'support_contact', 'exam_name', 'exam_key', 'exam_start_date', 'exam_end_date'];

    public static function safeHtml(string $body): string
    {
        return (string) Str::markdown($body, ['html_input' => 'strip', 'allow_unsafe_links' => false]);
    }

    public function save(User $actor, array $data, ?EmailTemplate $template = null): EmailTemplate
    {
        abort_unless($actor->isAdmin(), 403);
        $v = Validator::make($data, [
            'code' => ['required', 'alpha_dash', 'max:100', Rule::unique('email_templates')->ignore($template?->id)],
            'name' => 'required|string|max:255', 'round_number' => 'required|integer|between:1,3',
            'purpose' => ['required', Rule::in(['Invitation', 'Cleared'])],
            'subject' => ['required', 'string', 'max:200', 'not_regex:/[\r\n]/'],
            'body' => 'required|string|max:20000', 'is_active' => 'required|boolean',
            'body_format' => ['sometimes', Rule::in(['plain', 'markdown'])],
        ])->validate();
        $this->render($v['subject'], [], false);
        $this->render($v['body'], [], false);

        return DB::transaction(function () use ($actor, $v, $template) {
            $record = $template ? EmailTemplate::whereKey($template->id)->lockForUpdate()->firstOrFail() : new EmailTemplate;
            $old = $record->getAttributes();
            $record->fill($v)->save();
            app(AuditService::class)->record($actor, $record, $template ? 'email_template.updated' : 'email_template.created', $old, $record->getAttributes());

            return $record;
        });
    }

    public function render(string $text, array $variables, bool $strict = true): string
    {
        $result = preg_replace_callback('/\{\{\s*([a-z_]+)\s*\}\}/', function ($match) use ($variables, $strict) {
            $key = $match[1];
            if (! in_array($key, self::VARIABLES, true)) {
                throw ValidationException::withMessages(['body' => "Unsupported placeholder: {$key}. Use shared message fields only."]);
            }
            if (! filled($variables[$key] ?? null)) {
                if ($strict) {
                    throw ValidationException::withMessages(['body' => "Fill the shared message field: {$key}."]);
                }

                return $match[0];
            }

            return (string) $variables[$key];
        }, $text);
        if ($strict && (str_contains($result, '{{') || str_contains($result, '}}'))) {
            throw ValidationException::withMessages(['body' => 'Unresolved or malformed placeholders remain in the message.']);
        }

        return $result;
    }

    public function variables(?EmailTemplate $template, array $data): array
    {
        $values = Validator::make($data, [
            'company_name' => 'nullable|string|max:255', 'session_time' => 'nullable|string|max:255',
            'link' => 'nullable|url:http,https|max:2000', 'deadline' => 'nullable|string|max:255',
            'instructions' => 'nullable|string|max:5000', 'support_contact' => 'nullable|string|max:255',
            'exam_name' => 'nullable|string|max:255', 'exam_key' => 'nullable|string|max:100',
            'exam_start_date' => 'nullable|date_format:Y-m-d',
            'exam_end_date' => ['nullable', 'date_format:Y-m-d', Rule::when(filled($data['exam_start_date'] ?? null), 'after_or_equal:exam_start_date')],
        ])->validate();

        return [...$values, 'round_name' => $template ? Recruitment::ROUNDS[$template->round_number] : null, 'round_number' => $template?->round_number];
    }
}
