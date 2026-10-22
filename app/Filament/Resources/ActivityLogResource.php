<?php

namespace App\Filament\Resources;

use App\Models\ActivityLog;
use Filament\Actions\Action;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ActivityLogResource extends Resource
{
    protected static ?string $model = ActivityLog::class;

    protected static string|\UnitEnum|null $navigationGroup = 'Administration';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-shield-check';

    public static function table(Table $table): Table
    {
        return $table->columns([TextColumn::make('created_at')->dateTime()->sortable(), TextColumn::make('actor.name')->label('Actor')->searchable(), TextColumn::make('event')->searchable()->badge(), TextColumn::make('subject_type')->formatStateUsing(fn ($state) => class_basename($state)), TextColumn::make('subject_id'), TextColumn::make('ip_address')])->defaultSort('id', 'desc')->recordActions([Action::make('details')->modalSubmitAction(false)->modalContent(fn (ActivityLog $r) => view('filament.audit-details', ['record' => $r]))]);
    }

    public static function getPages(): array
    {
        return ['index' => ActivityLogResource\Pages\ListActivityLogs::route('/')];
    }
}
