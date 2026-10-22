<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\FollowUpResource;
use App\Models\FollowUp;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

class UpcomingFollowUps extends TableWidget
{
    protected static ?int $sort = 13;

    public function table(Table $table): Table
    {
        return $table->heading('Upcoming follow-ups')->query(FollowUp::visibleTo(auth()->user())->with('candidate')->where('status', 'Pending')->where('scheduled_at', '>=', now()))->defaultSort('scheduled_at')->paginated([5, 10])->columns([TextColumn::make('candidate.full_name'), TextColumn::make('scheduled_at')->dateTime(), TextColumn::make('priority')->badge()])->recordUrl(fn ($record) => FollowUpResource::getUrl('edit', ['record' => $record]));
    }
}
