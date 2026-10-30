<?php

namespace App\Filament\Resources;

use App\Filament\Support\ActionFeedback;
use App\Filament\Support\AssessmentFollowUpAction;
use App\Filament\Support\EmailComposer;
use App\Filament\Support\Fields;
use App\Filament\Support\RecordCallAction;
use App\Models\Candidate;
use App\Models\User;
use App\Services\CandidateOutreachService;
use App\Services\CandidateService;
use App\Support\Recruitment;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\Indicator;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class CandidateResource extends Resource
{
    protected static ?string $model = Candidate::class;

    protected static ?string $recordTitleAttribute = 'full_name';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-user-group';

    protected static ?int $navigationSort = 1;

    public static function getEloquentQuery(): Builder
    {
        $q = parent::getEloquentQuery()->visibleTo(auth()->user())->with(['jobPosition', 'assignedHr', 'interviewRounds', 'nextFollowUp', 'latestCall']);

        return auth()->user()?->isAdmin() ? $q->withoutGlobalScopes([SoftDeletingScope::class]) : $q;
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['candidate_code', 'full_name', 'mobile', 'email'];
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Candidate profile')->columns(2)->schema([
                DatePicker::make('entry_date')->default(today())->required()->maxDate(today()),
                TextInput::make('full_name')->required()->maxLength(255),
                TextInput::make('mobile')->label('Indian mobile (+91)')->tel()->required()->maxLength(30),
                Select::make('job_position_id')->relationship('jobPosition', 'name')->searchable()->preload()->required(),
            ]),
            Section::make('Profile details - complete later')->description('Collect these during your conversations. A valid email is required before email invitations.')->collapsible()->collapsed()->columns(2)->schema([
                TextInput::make('email')->email()->maxLength(255),
                TextInput::make('qualification')->maxLength(255),
                TextInput::make('passout_year')->numeric()->minValue(1960)->maxValue(now()->year + 5),
                TextInput::make('location')->maxLength(120),
                Fields::select('experience_type', ['Unknown', 'Fresher', 'Experienced'])->default('Unknown'),
                TextInput::make('current_ctc')->label('Current CTC (INR/year)')->numeric()->minValue(0),
                TextInput::make('expected_ctc')->label('Expected CTC (INR/year)')->numeric()->minValue(0),
                Fields::select('calling_status', Recruitment::CALLS)->default('Not Called')->helperText('After the first call, the latest call determines this status.'),
                Fields::select('interest_status', ['Unknown', 'Interested', 'Not Interested'])->default('Unknown'),
                Select::make('pan_verification_status')->options(Recruitment::options(['Not Verified', 'Verified', 'Failed'])),
                Textarea::make('remarks')->maxLength(10000)->columnSpanFull(), Fields::resume()->columnSpanFull(),
            ]),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        $entry = TextEntry::class;

        return $schema->components([
            Section::make('Candidate profile')->columns(3)->schema([
                $entry::make('candidate_code'), $entry::make('entry_date')->date(), $entry::make('full_name'),
                $entry::make('mobile_normalized')->label('Mobile'), $entry::make('email')->placeholder('Not provided'),
                $entry::make('qualification'), $entry::make('passout_year'), $entry::make('location'), $entry::make('jobPosition.name')->label('Applied position'),
            ])->columnSpanFull(),
            Section::make('Recruitment progress')->columns(3)->schema([
                $entry::make('recruitment_status')->badge(), $entry::make('calling_status')->badge(), $entry::make('interest_status')->badge(),
                $entry::make('assessment_progress')->label('Assessment completion')->badge(),
                $entry::make('assessment_reported_completed_at')->label('Completion reported')->dateTime()->placeholder('Not reported'),
                $entry::make('experience_type'), $entry::make('current_ctc')->label('Current CTC (INR/year)'), $entry::make('expected_ctc')->label('Expected CTC (INR/year)'),
                $entry::make('pan_verification_status')->placeholder('Not verified'), $entry::make('assignedHr.name')->label('Assigned HR'),
                $entry::make('updated_at')->dateTime(), $entry::make('remarks')->columnSpanFull(),
            ])->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        $columns = [
            TextColumn::make('candidate_code')->searchable()->sortable()->toggleable(isToggledHiddenByDefault: true), TextColumn::make('entry_date')->label('Entry date')->date()->sortable()->toggleable(),
            TextColumn::make('full_name')->searchable()->sortable(), TextColumn::make('mobile_normalized')->label('Mobile')->searchable(),
            TextColumn::make('qualification')->toggleable(isToggledHiddenByDefault: true), TextColumn::make('passout_year')->sortable()->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('location')->searchable()->sortable()->toggleable(isToggledHiddenByDefault: true), TextColumn::make('jobPosition.name')->label('Position')->sortable(),
            TextColumn::make('current_ctc')->numeric()->toggleable(isToggledHiddenByDefault: true), TextColumn::make('expected_ctc')->numeric()->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('calling_status')->badge(),
            TextColumn::make('latestCall.called_at')->label('Last call')->dateTime()->placeholder('Not called'),
            TextColumn::make('interest_status')->label('Interest')->badge(),
            TextColumn::make('last_email_sent_at')->label('Last email sent')->dateTime()->sortable()->placeholder('Not sent')->toggleable()
                ->tooltip('Accepted by the mail provider; inbox delivery is not confirmed.'),
            TextColumn::make('round_one_email_sent_at')->label('Round 1 email sent')->dateTime()->sortable()->placeholder('Not sent')->toggleable(),
            TextColumn::make('round_one_scheduled_at')->label('Round 1 scheduled at')->state(fn (Candidate $r) => $r->interviewRounds->firstWhere('round_number', 1)?->scheduled_at)->dateTime()->placeholder('Not scheduled')->toggleable(),
            TextColumn::make('nextFollowUp.notes')->label('Next action')->limit(45)->placeholder('No pending task'),
            TextColumn::make('nextFollowUp.scheduled_at')->label('Due')->dateTime()->placeholder('-'),
            TextColumn::make('assessment_progress')->label('Assessment completion')->badge()->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('assessment_reported_completed_at')->label('Completion reported')->dateTime()->toggleable(isToggledHiddenByDefault: true),
        ];
        foreach ([1, 2, 3] as $round) {
            $columns[] = TextColumn::make('round_'.$round)->label('Round '.$round)->state(fn (Candidate $r) => $r->interviewRounds->firstWhere('round_number', $round)?->status)->badge()->toggleable(isToggledHiddenByDefault: $round !== 1);
        }

        return $table->columns([...$columns, TextColumn::make('recruitment_status')->badge(), TextColumn::make('assignedHr.name')->label('Assigned HR')->visible(fn () => auth()->user()->isAdmin()), TextColumn::make('updated_at')->since()->sortable()])
            ->defaultSort('created_at', 'desc')->deferFilters(false)->filters([
                TernaryFilter::make('round_one_email_sent')->label('Round 1 email')
                    ->placeholder('All candidates')->trueLabel('Sent')->falseLabel('Not sent')
                    ->queries(
                        true: fn (Builder $query) => $query->whereNotNull('round_one_email_sent_at'),
                        false: fn (Builder $query) => $query->whereNull('round_one_email_sent_at'),
                        blank: fn (Builder $query) => $query,
                    ),
                SelectFilter::make('job_position_id')->relationship('jobPosition', 'name')->label('Position'),
                SelectFilter::make('recruitment_status')->options(Recruitment::options(Recruitment::STATUSES)),
                SelectFilter::make('calling_status')->options(Recruitment::options(Recruitment::CALLS)),
                SelectFilter::make('interest_status')->options(Recruitment::options(['Unknown', 'Interested', 'Not Interested'])),
                Filter::make('assessment_pending')->label('Assessment pending')->query(fn (Builder $query) => $query->whereIn('assessment_progress', ['Not confirmed', 'Not completed', 'Needs help'])->where('interest_status', 'Interested')->whereNotIn('recruitment_status', ['Rejected', 'Withdrawn', 'Selected', 'Joined'])->whereHas('interviewRounds', fn (Builder $r) => $r->where('round_number', 1)->whereIn('status', ['Pending', 'Scheduled', 'On Hold']))),
                Filter::make('assessment_awaiting_result')->label('Completed - awaiting result')->query(fn (Builder $query) => $query->where('assessment_progress', 'Reported completed')->whereNotIn('recruitment_status', ['Rejected', 'Withdrawn'])->whereHas('interviewRounds', fn (Builder $r) => $r->where('round_number', 1)->whereIn('status', ['Pending', 'Scheduled', 'On Hold']))),
                Filter::make('assessment_overdue')->label('Overdue assessment follow-ups')->query(fn (Builder $query) => $query->whereHas('followUps', fn (Builder $f) => $f->where('status', 'Pending')->where('scheduled_at', '<', now())->whereIn('purpose', ['Assessment completion', 'Assessment support', 'Assessment result']))),
                SelectFilter::make('assigned_hr_id')->label('HR')->options(fn () => User::role('HR Executive')->pluck('name', 'id'))->visible(fn () => auth()->user()->isAdmin()),
                Filter::make('location')->schema([TextInput::make('location')])->query(fn (Builder $query, array $data) => $query->when($data['location'] ?? null, fn ($q, $v) => $q->where('location', 'like', '%'.$v.'%'))),
                Filter::make('entry_date')->label('Candidate entry date')->schema([
                    DatePicker::make('from')->label('Entry date from'),
                    DatePicker::make('until')->label('Entry date to')->helperText('Both dates are included. Use the same date for a single day.'),
                ])->query(fn (Builder $query, array $data) => $query
                    ->when($data['from'] ?? null, fn ($q, $v) => $q->whereDate('entry_date', '>=', $v))
                    ->when($data['until'] ?? null, fn ($q, $v) => $q->whereDate('entry_date', '<=', $v)))
                    ->indicateUsing(function (array $data): array {
                        $indicators = [];
                        if (filled($data['from'] ?? null)) {
                            $indicators[] = Indicator::make('Entry date from: '.$data['from'])->removeField('from');
                        }
                        if (filled($data['until'] ?? null)) {
                            $indicators[] = Indicator::make('Entry date to: '.$data['until'])->removeField('until');
                        }

                        return $indicators;
                    }),
                TrashedFilter::make()->visible(fn () => auth()->user()->isAdmin()),
            ])->toolbarActions([
                BulkAction::make('emailCandidates')->label('Email selected (BCC)')
                    ->visible(fn () => auth()->user()?->canEmailCandidates())
                    ->databaseTransaction(false)
                    ->modalHeading('Send one email to selected candidates')
                    ->modalDescription('Maximum 100 candidates. Every candidate is in BCC; To and Cc stay empty. Duplicate email addresses receive one copy. Sending a Round 1 invitation template schedules Round 1 at the current date and time once the mail provider accepts the email. Review the shared message before sending.')
                    ->modalSubmitActionLabel('Send email')
                    ->schema([
                        Hidden::make('token')->default(fn () => (string) Str::uuid()),
                        EmailComposer::picker(),
                        EmailComposer::details(),
                        Select::make('body_format')->label('Message format')->options(['plain' => 'Plain text', 'markdown' => 'Formatted (Markdown)'])->default('plain')->required()->live(),
                        TextInput::make('subject')->required()->maxLength(200)->default('Round 1 online assessment invitation')->live(onBlur: true),
                        Textarea::make('body')->label('Message')->required()->maxLength(20000)->rows(12)->live(onBlur: true)
                            ->helperText('Include the assessment URL, date, time zone, deadline, duration and contact details. This exact text goes to everyone; use a shared link, not individual credentials.'),
                        EmailComposer::preview(),
                    ])
                    ->action(fn (Collection $records, array $data, Schema $schema) => ActionFeedback::run($schema, function () use ($records, $data) {
                        $count = app(CandidateOutreachService::class)->email(auth()->user(), $records->modelKeys(), $data);
                        Notification::make()->success()->title("Email submitted to mail provider for {$count} unique recipients")->send();
                    }))->deselectRecordsAfterCompletion(),
                BulkAction::make('bulkStatus')->label('Update selected statuses')
                    ->visible(fn () => auth()->user()?->isAdmin())
                    ->modalDescription('Updates recruitment status for all selected candidates, up to 100. Interview results and call statuses remain separate. All changes are audited; if any candidate fails validation, none are changed.')
                    ->schema([Fields::select('status', Recruitment::STATUSES), Textarea::make('reason')->maxLength(2000)->helperText('Required to override the three passed rounds rule for Selected or Joined.')])
                    ->action(fn (Collection $records, array $data, Schema $schema) => ActionFeedback::run($schema, function () use ($records, $data) {
                        $count = app(CandidateOutreachService::class)->bulkStatus(auth()->user(), $records->modelKeys(), $data['status'], $data['reason'] ?? null);
                        Notification::make()->success()->title("Updated {$count} candidates")->send();
                    }))->deselectRecordsAfterCompletion(),
            ])->recordActions([RecordCallAction::make(), ViewAction::make(), EditAction::make(), AssessmentFollowUpAction::make(),
                Action::make('whatsapp')->label('WhatsApp')->color('success')
                    ->visible(fn (Candidate $record) => auth()->user()->can('update', $record))
                    ->modalDescription('Opens a prefilled WhatsApp chat. Review and press Send in WhatsApp. Opening the chat does not record a message as sent.')
                    ->modalSubmitActionLabel('Open WhatsApp')
                    ->schema([Textarea::make('message')->required()->maxLength(4000)->rows(6)->default(fn (Candidate $record) => "Hello {$record->full_name}, this is the recruitment team at Spartan Infosoft regarding your job application.")])
                    ->action(fn (Candidate $record, array $data, $livewire, Schema $schema) => ActionFeedback::run($schema, fn () => $livewire->redirect(app(CandidateOutreachService::class)->whatsappUrl(auth()->user(), $record, $data['message']), navigate: false))),
                Action::make('status')->label('Change status')->visible(fn (Candidate $r) => auth()->user()->can('update', $r))->schema([Fields::select('status', Recruitment::STATUSES), Textarea::make('reason')->maxLength(2000)])->action(fn (Candidate $r, array $data, Schema $schema) => ActionFeedback::run($schema, fn () => app(CandidateService::class)->status(auth()->user(), $r, $data['status'], $data['reason'] ?? null))),
                Action::make('reassign')->visible(fn (Candidate $r) => auth()->user()->can('reassign', $r))->schema([Fields::hr(), Textarea::make('reason')->required()->maxLength(2000)])->action(fn (Candidate $r, array $data, Schema $schema) => ActionFeedback::run($schema, fn () => app(CandidateService::class)->reassign(auth()->user(), $r, (int) $data['new_hr_id'], $data['reason']))),
                Action::make('delete')->color('danger')->requiresConfirmation()->visible(fn (Candidate $r) => ! $r->trashed() && auth()->user()->can('delete', $r))->action(fn (Candidate $r) => app(CandidateService::class)->delete(auth()->user(), $r)),
                Action::make('restore')->requiresConfirmation()->visible(fn (Candidate $r) => $r->trashed() && auth()->user()->can('restore', $r))->action(fn (Candidate $r) => app(CandidateService::class)->restore(auth()->user(), $r)),
            ]);
    }

    public static function getRelations(): array
    {
        return [CandidateResource\RelationManagers\InterviewRoundsRelationManager::class, CandidateResource\RelationManagers\CallLogsRelationManager::class, CandidateResource\RelationManagers\FollowUpsRelationManager::class, CandidateResource\RelationManagers\DocumentsRelationManager::class, CandidateResource\RelationManagers\AssignmentsRelationManager::class];
    }

    public static function getPages(): array
    {
        return ['index' => CandidateResource\Pages\ListCandidates::route('/'), 'create' => CandidateResource\Pages\CreateCandidate::route('/create'), 'view' => CandidateResource\Pages\ViewCandidate::route('/{record}'), 'edit' => CandidateResource\Pages\EditCandidate::route('/{record}/edit')];
    }
}
