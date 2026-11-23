<?php

namespace App\Filament\Resources;

use App\Models\ActivityLog;
use Filament\Actions\Action;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ActivityLogResource extends Resource
{
    protected static ?string $model = ActivityLog::class;

    protected static string|\UnitEnum|null $navigationGroup = 'Administration';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-shield-check';

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('created_at')->dateTime()->sortable(),
            TextColumn::make('actor.name')->label('Actor')->searchable(),
            TextColumn::make('event')->searchable()->badge(),
            TextColumn::make('new_values.candidate_name')->label('Candidate')->placeholder('—')
                ->searchable(query: fn (Builder $query, string $search) => $query->where('new_values->candidate_name', 'like', "%{$search}%")),
            TextColumn::make('new_values.candidate_email')->label('Candidate email')->placeholder('—')->copyable()
                ->searchable(query: fn (Builder $query, string $search) => $query->where('new_values->candidate_email', 'like', "%{$search}%")),
            TextColumn::make('new_values.subject')->label('Email subject')->limit(50)->placeholder('—')
                ->searchable(query: fn (Builder $query, string $search) => $query->where('new_values->subject', 'like', "%{$search}%")),
            TextColumn::make('subject_type')->formatStateUsing(fn ($state) => class_basename($state))->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('subject_id')->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('ip_address')->toggleable(isToggledHiddenByDefault: true),
        ])->filters([
            SelectFilter::make('event')->label('Activity type')->options([
                'candidate.email_submitted' => 'Candidate email submitted',
                'offer.submitted' => 'Offer letter email submitted',
                'offer.sending' => 'Offer letter sending',
                'offer.resending' => 'Offer letter resending',
                'offer.delivery_unconfirmed' => 'Offer letter delivery unconfirmed',
            ]),
        ])->defaultSort('id', 'desc')->recordActions([
            Action::make('details')->modalHeading('Activity details')->modalSubmitAction(false)
                ->modalContent(fn (ActivityLog $r) => view('filament.audit-details', ['record' => $r])),
        ]);
    }

    public static function getPages(): array
    {
        return ['index' => ActivityLogResource\Pages\ListActivityLogs::route('/')];
    }
}
