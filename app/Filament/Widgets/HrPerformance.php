<?php

namespace App\Filament\Widgets;

use App\Models\User;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

class HrPerformance extends TableWidget
{
    protected static ?int $sort = 15;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return auth()->user()?->isAdmin() ?? false;
    }

    public function table(Table $table): Table
    {
        abort_unless(auth()->user()->isAdmin(), 403);

        return $table->heading('HR performance · lifetime')->query(User::role('HR Executive')->withCount(['candidates', 'callLogs', 'callLogs as connected_count' => fn ($q) => $q->where('call_status', 'Connected'), 'candidates as selected_count' => fn ($q) => $q->whereIn('recruitment_status', ['Selected', 'Joined'])]))
            ->columns([TextColumn::make('name'), TextColumn::make('candidates_count')->label('Assigned candidates'), TextColumn::make('call_logs_count')->label('Calls made'), TextColumn::make('connected_count')->label('Connected calls'), TextColumn::make('selected_count')->label('Selections')])->paginated([5, 10]);
    }
}
