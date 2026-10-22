<?php

namespace App\Filament\Widgets;

use App\Models\FollowUp;
use App\Services\FollowUpService;
use Filament\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

class OverdueFollowUps extends TableWidget
{
    protected static ?int $sort = 14;

    public function table(Table $table): Table
    {
        return $table->heading('Overdue follow-ups')->query(FollowUp::visibleTo(auth()->user())->with('candidate')->where('status', 'Pending')->where('scheduled_at', '<', now()))->defaultSort('scheduled_at')->paginated([5, 10])->columns([TextColumn::make('candidate.full_name'), TextColumn::make('scheduled_at')->since()->color('danger'), TextColumn::make('priority')->badge()])->recordActions([Action::make('complete')->requiresConfirmation()->action(fn (FollowUp $record) => app(FollowUpService::class)->complete(auth()->user(), $record))]);
    }
}
