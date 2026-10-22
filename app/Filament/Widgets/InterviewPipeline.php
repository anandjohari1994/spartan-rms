<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\CandidateResource;
use App\Models\InterviewRound;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

class InterviewPipeline extends TableWidget
{
    protected static ?int $sort = 12;

    public function table(Table $table): Table
    {
        return $table->heading('Pending interviews')->query(InterviewRound::visibleTo(auth()->user())->with('candidate')->whereIn('status', ['Pending', 'Scheduled', 'On Hold']))->defaultSort('scheduled_at')->paginated([5, 10])->columns([TextColumn::make('candidate.full_name'), TextColumn::make('round_number'), TextColumn::make('status')->badge(), TextColumn::make('scheduled_at')->dateTime()])->recordUrl(fn ($record) => CandidateResource::getUrl('view', ['record' => $record->candidate_id]));
    }
}
