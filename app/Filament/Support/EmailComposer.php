<?php

namespace App\Filament\Support;

use App\Models\EmailTemplate;
use App\Services\EmailTemplateService;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Validation\ValidationException;

class EmailComposer
{
    public static function picker(): Select
    {
        return Select::make('email_template_id')->label('Saved email template')->placeholder('Write a custom email')
            ->options(fn () => EmailTemplate::where('is_active', true)->orderBy('round_number')->pluck('name', 'id'))
            ->searchable()->live()->helperText('Choosing a template replaces the subject and message below. All recipients receive the same content.')
            ->afterStateUpdated(function ($state, Set $set) {
                $template = EmailTemplate::where('is_active', true)->find($state);
                if ($template) {
                    $set('subject', $template->subject);
                    $set('body', $template->body);
                    $set('body_format', $template->body_format);
                }
            });
    }

    public static function details(): Section
    {
        return Section::make('Shared email details')
            ->visible(fn (Get $get) => (bool) preg_match('/\{\{\s*[a-z_]+\s*\}\}/', (string) $get('subject').' '.(string) $get('body')))
            ->columns(2)->schema([
                TextInput::make('company_name')->default('Spartan Infosoft')->maxLength(255)->live(onBlur: true),
                TextInput::make('session_time')->label('Session date/time and time zone')->maxLength(255)->live(onBlur: true),
                TextInput::make('link')->label('Assessment / meeting link')->url()->maxLength(2000)->live(onBlur: true),
                TextInput::make('deadline')->label('Deadline / confirmation by')->maxLength(255)->live(onBlur: true),
                Textarea::make('instructions')->label('Instructions / next steps')->maxLength(5000)->live(onBlur: true),
                TextInput::make('support_contact')->maxLength(255)->live(onBlur: true),
                TextInput::make('exam_name')->label('Test name')->maxLength(255)->live(onBlur: true),
                TextInput::make('exam_key')->label('Shared exam key')->maxLength(100)->live(onBlur: true)->helperText('Use only a common key intended for all selected candidates.'),
                DatePicker::make('exam_start_date')->label('Exam start date')->live(),
                DatePicker::make('exam_end_date')->label('Exam end date')->afterOrEqual(fn (Get $get) => filled($get('exam_start_date')) ? 'exam_start_date' : null)->live(),
            ]);
    }

    public static function preview(): Section
    {
        return Section::make('Email preview')->schema([
            TextEntry::make('preview_subject')->label('Subject')->state(fn (Get $get) => self::previewText($get, 'subject')),
            TextEntry::make('preview_body')->label('Message')->state(fn (Get $get) => $get('body_format') === 'markdown' ? EmailTemplateService::safeHtml(self::previewText($get, 'body')) : nl2br(e(self::previewText($get, 'body'))))->html()->prose(),
        ]);
    }

    private static function previewText(Get $get, string $field): string
    {
        $service = app(EmailTemplateService::class);
        try {
            $data = [];
            foreach (EmailTemplateService::VARIABLES as $key) {
                $data[$key] = $get($key);
            }
            $template = filled($get('email_template_id')) ? EmailTemplate::find($get('email_template_id')) : null;

            return $service->render((string) $get($field), $service->variables($template, $data));
        } catch (ValidationException $e) {
            return 'Complete the message: '.collect($e->errors())->flatten()->implode(' ');
        }
    }
}
