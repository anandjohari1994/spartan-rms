<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\CandidateResource;
use App\Models\Candidate;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

class PendingCalls extends TableWidget
{
    protected static ?int $sort = 11;

    public function table(Table $table): Table
    {
        return $table->heading('Pending calls')->query(Candidate::visibleTo(auth()->user())->whereIn('calling_status', ['Not Called', 'Callback Requested']))->paginated([5, 10])->columns([TextColumn::make('full_name'), TextColumn::make('mobile_normalized')->label('Mobile'), TextColumn::make('calling_status')->badge()])
            ->recordUrl(fn ($record) => CandidateResource::getUrl('view', ['record' => $record]));
    }
}
