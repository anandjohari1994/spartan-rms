<?php

namespace App\Filament\Resources;

use App\Filament\Resources\OfferLetterResource\Pages\ListOfferLetters;
use App\Filament\Support\ActionFeedback;
use App\Models\Candidate;
use App\Services\OfferLetterPdf;
use App\Services\OfferLetterService;
use App\Services\OfferSalaryCalculator;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class OfferLetterResource extends Resource
{
    protected static ?string $model = Candidate::class;

    protected static ?string $slug = 'offer-letters';

    protected static ?string $navigationLabel = 'Offer Letter';

    protected static ?string $modelLabel = 'Offer Letter';

    protected static ?string $pluralModelLabel = 'Offer Letters';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-document-text';

    protected static ?int $navigationSort = 2;

    public static function canViewAny(): bool
    {
        return auth()->user()?->canEmailCandidates() ?? false;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->visibleTo(auth()->user())->qualifiedForOffer()->with(['jobPosition', 'offerLetter', 'interviewRounds']);
    }

    public static function draftFields(): array
    {
        $text = fn (string $name) => TextInput::make($name)->label(OfferLetterService::TEXT_FIELDS[$name])->required()->maxLength(150);
        $dates = [];
        foreach (OfferLetterService::DATE_FIELDS as $name => $label) {
            $dates[] = DatePicker::make($name)->label($label)->required();
        }

        return [
            Hidden::make('revision'),
            Section::make('Candidate and offer details')->description('The candidate name and email come from the candidate profile. Save a draft, preview it, then send.')->columns(2)->schema([
                $text('reference'), $text('address_line_1'), $text('address_line_2'), $text('state'), $text('pin_code'),
            ]),
            Section::make('Position and reporting')->columns(2)->schema([
                $text('designation'), $text('department'), $text('work_location'), $text('reporting_manager'),
                Select::make('employment_type')->options(['Full-Time' => 'Full-Time', 'Contractual' => 'Contractual', 'Other' => 'Other'])->required(),
                TextInput::make('probation_months')->numeric()->integer()->minValue(0)->maxValue(36)->required(),
            ]),
            Section::make('Dates')->columns(2)->schema($dates),
            Section::make('Salary annexure')->description('Enter the annual package in rupees. The complete salary breakup is calculated automatically, including employer PF within CTC.')->columns(2)->schema([
                TextInput::make('annual_package')->label('Annual package (CTC)')->prefix('INR')->numeric()->step('0.01')->minValue(1)->maxValue(999999999.99)->required()->live(debounce: 500)
                    ->helperText('Example: enter 600000 for a 6 lakh annual package.'),
                TextInput::make('salary_payment_day')->label('Salary payment day of following month')->numeric()->integer()->minValue(1)->maxValue(31)->required(),
                View::make('filament.offer-salary-breakdown')->viewData(fn (Get $get) => ['breakdown' => app(OfferSalaryCalculator::class)->preview($get('annual_package')), 'components' => OfferLetterPdf::SALARY_COMPONENTS])->columnSpanFull(),
            ]),
            Section::make('Signatory and optional clause')->columns(2)->schema([
                $text('signatory_name'), $text('signatory_designation'),
                Select::make('optional_clause')->label('Optional clause 5.6')->options(['include' => 'Include clause 5.6', 'omit' => 'Omit clause 5.6'])->required()->columnSpanFull()
                    ->helperText('The reference marks this clause as optional. If included: if the client does not select the candidate, the Company may consider other opportunities or review/end employment under the Employment Agreement and applicable law. The internal HR instruction is removed from issued letters.'),
            ]),
        ];
    }

    private static function notifyDelivery(array $result): void
    {
        $notification = Notification::make()->title($result['submitted'].' offer letter(s) submitted')
            ->body($result['uncertain'] ? $result['uncertain'].' delivery attempt(s) could not be confirmed. Check the mail provider and server logs before taking further action. Automatic resend is blocked.' : 'Accepted by the mail provider. Inbox delivery is not confirmed.');
        ($result['uncertain'] ? $notification->warning() : $notification->success())->send();
    }

    public static function table(Table $table): Table
    {
        return $table->description('Candidates who passed Round 1 and Round 2 and are awaiting the client interview. Prepare and preview each draft, then select up to 25 candidates to send individual PDF attachments.')
            ->columns([
                TextColumn::make('candidate_code')->label('Candidate ID')->searchable(),
                TextColumn::make('full_name')->label('Candidate')->searchable()->sortable(),
                TextColumn::make('email')->searchable()->placeholder('Add email to prepare offer'),
                TextColumn::make('jobPosition.name')->label('Position'),
                TextColumn::make('round_1')->label('Round 1')->state('Passed')->badge()->color('success'),
                TextColumn::make('round_2')->label('Round 2')->state('Passed')->badge()->color('success'),
                TextColumn::make('offerLetter.status')->label('Offer status')->placeholder('Not prepared')->badge()
                    ->formatStateUsing(fn (string $state) => ['draft' => 'Draft ready', 'sending' => 'Sending / check provider', 'submitted' => 'Submitted to provider', 'uncertain' => 'Delivery unconfirmed'][$state] ?? $state)
                    ->color(fn (?string $state) => match ($state) {
                        'submitted' => 'success', 'draft' => 'info', default => 'warning'
                    }),
                TextColumn::make('offerLetter.submitted_at')->label('Submitted at')->dateTime()->placeholder('Not sent'),
            ])->defaultSort('updated_at', 'desc')->recordUrl(null)
            ->filters([
                SelectFilter::make('offer_status')->label('Offer status')->options(['not_prepared' => 'Not prepared', 'draft' => 'Draft ready', 'submitted' => 'Submitted to provider', 'uncertain' => 'Delivery unconfirmed', 'sending' => 'Sending / check provider'])
                    ->query(fn (Builder $query, array $data) => $query->when($data['value'] ?? null, fn ($query, $status) => $status === 'not_prepared' ? $query->whereDoesntHave('offerLetter') : $query->whereHas('offerLetter', fn ($offer) => $offer->where('status', $status)))),
            ])->recordActions([
                Action::make('prepareOffer')->label(fn (Candidate $record) => $record->offerLetter ? 'Edit draft' : 'Prepare offer')->icon('heroicon-o-pencil-square')
                    ->visible(fn (Candidate $record) => ! $record->offerLetter || $record->offerLetter->status === 'draft')
                    ->modalHeading(fn (Candidate $record) => 'Offer letter - '.$record->full_name)->modalWidth('5xl')->modalSubmitActionLabel('Save draft PDF')
                    ->fillForm(fn (Candidate $record) => [...app(OfferLetterService::class)->defaults($record), 'revision' => $record->offerLetter?->revision])
                    ->schema(static::draftFields())
                    ->action(function (Candidate $record, array $data, Schema $schema) {
                        ActionFeedback::run($schema, fn () => app(OfferLetterService::class)->saveDraft(auth()->user(), $record, $data));
                        Notification::make()->title('Draft PDF saved')->body('Use Preview to review the exact attachment before sending.')->success()->send();
                    }),
                Action::make('previewOffer')->label('Preview')->icon('heroicon-o-eye')->visible(fn (Candidate $record) => (bool) $record->offerLetter)
                    ->url(fn (Candidate $record) => route('offer-letters.preview', $record))->openUrlInNewTab(),
                Action::make('sendOffer')->label('Send offer')->icon('heroicon-o-paper-airplane')->color('success')
                    ->visible(fn (Candidate $record) => $record->offerLetter?->status === 'draft')
                    ->requiresConfirmation()->modalHeading('Send conditional offer letter')
                    ->modalDescription(fn (Candidate $record) => 'Send the saved PDF to '.$record->email.'? Please preview the letter and confirm its terms first.')
                    ->schema([Hidden::make('revision')->default(fn (Candidate $record) => $record->offerLetter?->revision)])
                    ->databaseTransaction(false)
                    ->action(function (Candidate $record, array $data, Schema $schema) {
                        $result = ActionFeedback::run($schema, fn () => app(OfferLetterService::class)->send(auth()->user(), [$record->id], [$record->id => $data['revision']]));
                        static::notifyDelivery($result);
                    }),
            ])->toolbarActions([
                BulkAction::make('sendOffers')->label('Send selected offer letters')->icon('heroicon-o-paper-airplane')->requiresConfirmation()
                    ->modalHeading('Send selected offer letters')->modalDescription('Each candidate receives only their own saved PDF. Preview all drafts first. The entire selection must have current, unsent drafts and valid email addresses. Maximum 25 candidates per send.')
                    ->schema([Hidden::make('revisions')->default(fn (Collection $records) => $records->mapWithKeys(fn (Candidate $record) => [$record->id => $record->offerLetter?->revision])->all())])
                    ->databaseTransaction(false)->deselectRecordsAfterCompletion()
                    ->action(function (Collection $records, array $data, Schema $schema) {
                        $result = ActionFeedback::run($schema, fn () => app(OfferLetterService::class)->send(auth()->user(), $records->modelKeys(), $data['revisions'] ?? []));
                        static::notifyDelivery($result);
                    }),
            ])->emptyStateHeading('No candidates qualified for a conditional offer')
            ->emptyStateDescription('Mark Round 1 and Round 2 as Passed. Active candidates with a pending client interview will appear here.');
    }

    public static function getPages(): array
    {
        return ['index' => ListOfferLetters::route('/')];
    }
}
