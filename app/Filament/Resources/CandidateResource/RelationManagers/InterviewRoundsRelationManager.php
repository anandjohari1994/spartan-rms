<?php

namespace App\Filament\Resources\CandidateResource\RelationManagers;

use App\Filament\Support\Fields;
use App\Models\InterviewRound;
use App\Services\InterviewService;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class InterviewRoundsRelationManager extends RelationManager
{
    protected static string $relationship = 'interviewRounds';

    public function isReadOnly(): bool
    {
        return false;
    }

    public function table(Table $table): Table
    {
        return $table->columns([TextColumn::make('round_number'), TextColumn::make('round_type'), TextColumn::make('status')->badge(), TextColumn::make('scheduled_at')->dateTime(), TextColumn::make('completed_at')->dateTime(), TextColumn::make('score'), TextColumn::make('remarks')->limit(100), TextColumn::make('updater.name')->label('Updated by'), TextColumn::make('updated_at')->since()])
            ->recordActions([Action::make('updateRound')->label('Update round')->visible(fn (InterviewRound $record) => auth()->user()->can('update', $record))->fillForm(fn (InterviewRound $record) => $record->toArray())->schema(Fields::interview())->action(fn (InterviewRound $record, array $data, \Filament\Schemas\Schema $schema) => \App\Filament\Support\ActionFeedback::run($schema, fn () => app(InterviewService::class)->update(auth()->user(), $record, $data)))]);
    }
}
