<?php

namespace App\Filament\Resources\CandidateResource\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class AssignmentsRelationManager extends RelationManager
{
    protected static string $relationship = 'assignments';

    public function table(Table $table): Table
    {
        return $table->columns([TextColumn::make('previousHr.name'), TextColumn::make('newHr.name'), TextColumn::make('assignedBy.name'), TextColumn::make('reason')->wrap(), TextColumn::make('assigned_at')->dateTime()])->defaultSort('assigned_at', 'desc');
    }
}
