<?php

namespace App\Filament\Support;

use App\Models\Candidate;
use App\Models\FollowUp;
use App\Services\AssessmentFollowUpService;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class AssessmentFollowUpAction
{
    public static function make(): Action
    {
        return Action::make('assessmentFollowUp')->label('Assessment follow-up')->icon('heroicon-o-phone')
            ->visible(fn ($record) => (! $record instanceof FollowUp || $record->status === 'Pending') && auth()->user()->can('workflow.manage') && auth()->user()->can('update', $record instanceof Candidate ? $record : $record->candidate))
            ->modalHeading('Record call and assessment follow-up')
            ->modalDescription('Save the call, candidate response and next task together. Completed means reported by the candidate; it does not pass Round 1. For a non-call update, use the existing follow-up or interview editor.')
            ->schema([
                Hidden::make('token')->default(fn () => (string) Str::uuid()),
                DateTimePicker::make('called_at')->label('Call time')->default(now())->required()->maxDate(now())->seconds(false),
                Select::make('response')->label('Assessment response')->options(array_combine(AssessmentFollowUpService::RESPONSES, AssessmentFollowUpService::RESPONSES))->required()->live(),
                DateTimePicker::make('next_at')->label(fn (Get $get) => $get('response') === 'Completed' ? 'Verify result on' : 'Next follow-up on')->seconds(false)->required(fn (Get $get) => $get('response') !== 'Not interested')->visible(fn (Get $get) => $get('response') !== 'Not interested'),
                Textarea::make('notes')->label('Conversation / issue notes')->maxLength(8000)->required(fn (Get $get) => $get('response') === 'Facing a problem'),
                Toggle::make('cancel_reminders')->label('Cancel remaining assessment reminders')->default(false)->visible(fn (Get $get) => $get('response') === 'Not interested'),
            ])->modalSubmitActionLabel('Save call and next step')
            ->action(fn ($record, array $data, Schema $schema) => ActionFeedback::run($schema, function () use ($record, $data) {
                app(AssessmentFollowUpService::class)->record(auth()->user(), $record instanceof Candidate ? $record : $record->candidate, $data, $record instanceof FollowUp ? $record : null);
                Notification::make()->success()->title('Call and assessment follow-up saved')->send();
            }));
    }
}
