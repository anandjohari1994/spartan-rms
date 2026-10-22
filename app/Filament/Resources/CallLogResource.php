<?php

namespace App\Filament\Resources;

use App\Filament\Support\Fields;
use App\Models\CallLog;
use App\Support\Recruitment;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class CallLogResource extends Resource
{
    protected static ?string $model = CallLog::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-phone';

    protected static ?int $navigationSort = 2;

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->visibleTo(auth()->user())->with(['candidate', 'hr']);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([Fields::candidate(), DateTimePicker::make('called_at')->default(now())->required()->maxDate(now())->seconds(false), Fields::select('call_status', array_values(array_diff(Recruitment::CALLS, ['Not Called']))), TextInput::make('duration_seconds')->numeric()->minValue(0)->maxValue(86400), Textarea::make('notes')->maxLength(10000)->columnSpanFull()]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([TextColumn::make('candidate.full_name')->searchable(), TextColumn::make('hr.name')->label('Called by'), TextColumn::make('called_at')->dateTime()->sortable(), TextColumn::make('call_status')->badge(), TextColumn::make('duration_seconds')->label('Seconds'), TextColumn::make('notes')->wrap()->limit(150)])->defaultSort('called_at', 'desc')->filters([SelectFilter::make('call_status')->options(Recruitment::options(Recruitment::CALLS))]);
    }

    public static function getPages(): array
    {
        return ['index' => CallLogResource\Pages\ListCallLogs::route('/'), 'create' => CallLogResource\Pages\CreateCallLog::route('/create')];
    }
}
