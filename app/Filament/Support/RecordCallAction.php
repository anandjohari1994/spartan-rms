<?php

namespace App\Filament\Support;

use App\Models\Candidate;
use App\Models\FollowUp;
use App\Services\CandidateConversationService;
use App\Support\Recruitment;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class RecordCallAction
{
    public static function make(): Action
    {
        return Action::make('recordCall')->label('Record call')->icon('heroicon-o-phone')
            ->visible(fn ($record) => (! $record instanceof FollowUp || $record->status === 'Pending') && auth()->user()->can('workflow.manage') && auth()->user()->can('update', $record instanceof Candidate ? $record : $record->candidate))
            ->modalHeading(fn ($record) => 'Record call - '.($record instanceof Candidate ? $record->full_name : $record->candidate->full_name))
            ->modalDescription('Save the call, interest and next step together. When opened from a follow-up, this completes that task. No message is sent automatically.')
            ->schema([
                Hidden::make('token')->default(fn () => (string) Str::uuid()),
                DateTimePicker::make('called_at')->default(now())->required()->maxDate(now())->seconds(false),
                Select::make('call_status')->options(Recruitment::options(array_values(array_diff(Recruitment::CALLS, ['Not Called']))))->required()->live(),
                Select::make('interest_status')->label('Update interest')->placeholder('Keep current interest')->options(Recruitment::options(['Unknown', 'Interested', 'Not Interested']))->visible(fn (Get $get) => $get('call_status') === 'Connected')->dehydrated(fn (Get $get) => $get('call_status') === 'Connected'),
                Textarea::make('notes')->label('Conversation notes')->maxLength(8000),
                Select::make('next_action')->options(Recruitment::options(CandidateConversationService::NEXT))->default('None')->required()->live(),
                DateTimePicker::make('scheduled_at')->label('Assessment / interview appointment')->seconds(false)->visible(fn (Get $get) => str_starts_with($get('next_action') ?? '', 'Schedule Round'))->required(fn (Get $get) => str_starts_with($get('next_action') ?? '', 'Schedule Round')),
                DateTimePicker::make('next_at')->label('Next follow-up date and time')->seconds(false)->visible(fn (Get $get) => $get('next_action') !== 'None')->required(fn (Get $get) => $get('next_action') !== 'None'),
            ])->modalSubmitActionLabel('Save call and next step')
            ->action(fn ($record, array $data, Schema $schema) => ActionFeedback::run($schema, function () use ($record, $data) {
                app(CandidateConversationService::class)->record(auth()->user(), $record instanceof Candidate ? $record : $record->candidate, $data, $record instanceof FollowUp ? $record : null);
                Notification::make()->success()->title('Call and next step saved')->send();
            }));
    }
}
