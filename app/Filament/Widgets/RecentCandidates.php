<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\CandidateResource;
use App\Models\Candidate;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

class RecentCandidates extends TableWidget
{
    protected static ?int $sort = 10;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table->heading('Recent candidates')->query(Candidate::visibleTo(auth()->user())->with(['assignedHr', 'jobPosition']))->defaultSort('created_at', 'desc')->paginated([5, 10])
            ->columns([TextColumn::make('candidate_code'), TextColumn::make('full_name'), TextColumn::make('jobPosition.name'), TextColumn::make('recruitment_status')->badge(), TextColumn::make('assignedHr.name')->visible(fn () => auth()->user()->isAdmin()), TextColumn::make('created_at')->since()])
            ->recordUrl(fn ($record) => CandidateResource::getUrl('view', ['record' => $record]));
    }
}
