<?php

namespace App\Filament\Resources;

use App\Filament\Support\Fields;
use App\Models\Candidate;
use App\Models\User;
use App\Services\CandidateService;
use App\Support\Recruitment;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class CandidateResource extends Resource
{
    protected static ?string $model = Candidate::class;

    protected static ?string $recordTitleAttribute = 'full_name';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-user-group';

    protected static ?int $navigationSort = 1;

    public static function getEloquentQuery(): Builder
    {
        $q = parent::getEloquentQuery()->visibleTo(auth()->user())->with(['jobPosition', 'assignedHr', 'interviewRounds']);

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
                TextInput::make('email')->email()->maxLength(255),
                TextInput::make('qualification')->required()->maxLength(255),
                TextInput::make('passout_year')->numeric()->required()->minValue(1960)->maxValue(now()->year + 5),
                TextInput::make('location')->required()->maxLength(120),
                Select::make('job_position_id')->relationship('jobPosition', 'name')->searchable()->preload()->required(),
            ]),
            Section::make('Recruitment details')->columns(2)->schema([
                Fields::select('experience_type', ['Fresher', 'Experienced'])->default('Fresher'),
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
        $entry = \Filament\Infolists\Components\TextEntry::class;
        return $schema->components([
            Section::make('Candidate profile')->columns(3)->schema([
                $entry::make('candidate_code'), $entry::make('entry_date')->date(), $entry::make('full_name'),
                $entry::make('mobile_normalized')->label('Mobile'), $entry::make('email')->placeholder('Not provided'),
                $entry::make('qualification'), $entry::make('passout_year'), $entry::make('location'), $entry::make('jobPosition.name')->label('Applied position'),
            ])->columnSpanFull(),
            Section::make('Recruitment progress')->columns(3)->schema([
                $entry::make('recruitment_status')->badge(), $entry::make('calling_status')->badge(), $entry::make('interest_status')->badge(),
                $entry::make('experience_type'), $entry::make('current_ctc')->label('Current CTC (INR/year)'), $entry::make('expected_ctc')->label('Expected CTC (INR/year)'),
                $entry::make('pan_verification_status')->placeholder('Not verified'), $entry::make('assignedHr.name')->label('Assigned HR'),
                $entry::make('updated_at')->dateTime(), $entry::make('remarks')->columnSpanFull(),
            ])->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        $columns = [
            TextColumn::make('candidate_code')->searchable()->sortable(), TextColumn::make('entry_date')->date()->sortable(),
            TextColumn::make('full_name')->searchable()->sortable(), TextColumn::make('mobile_normalized')->label('Mobile')->searchable(),
            TextColumn::make('qualification')->toggleable(), TextColumn::make('passout_year')->sortable()->toggleable(),
            TextColumn::make('location')->searchable()->sortable(), TextColumn::make('jobPosition.name')->label('Position')->sortable(),
            TextColumn::make('current_ctc')->numeric()->toggleable(isToggledHiddenByDefault: true), TextColumn::make('expected_ctc')->numeric()->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('calling_status')->badge(),
        ];
        foreach ([1, 2, 3] as $round) {
            $columns[] = TextColumn::make('round_'.$round)->label('Round '.$round)->state(fn (Candidate $r) => $r->interviewRounds->firstWhere('round_number', $round)?->status)->badge();
        }

        return $table->columns([...$columns, TextColumn::make('recruitment_status')->badge(), TextColumn::make('assignedHr.name')->label('Assigned HR')->visible(fn () => auth()->user()->isAdmin()), TextColumn::make('updated_at')->since()->sortable()])
            ->defaultSort('created_at', 'desc')->filters([
                SelectFilter::make('job_position_id')->relationship('jobPosition', 'name')->label('Position'),
                SelectFilter::make('recruitment_status')->options(Recruitment::options(Recruitment::STATUSES)),
                SelectFilter::make('calling_status')->options(Recruitment::options(Recruitment::CALLS)),
                SelectFilter::make('assigned_hr_id')->label('HR')->options(fn () => User::role('HR Executive')->pluck('name', 'id'))->visible(fn () => auth()->user()->isAdmin()),
                Filter::make('location')->schema([TextInput::make('location')])->query(fn (Builder $q, array $data) => $q->when($data['location'] ?? null, fn ($q, $v) => $q->where('location', 'like', '%'.$v.'%'))),
                Filter::make('entry_date')->schema([DatePicker::make('from'), DatePicker::make('until')])->query(fn (Builder $q, array $data) => $q->when($data['from'] ?? null, fn ($q, $v) => $q->whereDate('entry_date', '>=', $v))->when($data['until'] ?? null, fn ($q, $v) => $q->whereDate('entry_date', '<=', $v))),
                TrashedFilter::make()->visible(fn () => auth()->user()->isAdmin()),
            ])->recordActions([ViewAction::make(), EditAction::make(),
                Action::make('status')->label('Change status')->visible(fn (Candidate $r) => auth()->user()->can('update', $r))->schema([Fields::select('status', Recruitment::STATUSES), Textarea::make('reason')->maxLength(2000)])->action(fn (Candidate $r, array $data, Schema $schema) => \App\Filament\Support\ActionFeedback::run($schema, fn () => app(CandidateService::class)->status(auth()->user(), $r, $data['status'], $data['reason'] ?? null))),
                Action::make('reassign')->visible(fn (Candidate $r) => auth()->user()->can('reassign', $r))->schema([Fields::hr(), Textarea::make('reason')->required()->maxLength(2000)])->action(fn (Candidate $r, array $data, Schema $schema) => \App\Filament\Support\ActionFeedback::run($schema, fn () => app(CandidateService::class)->reassign(auth()->user(), $r, (int) $data['new_hr_id'], $data['reason']))),
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
