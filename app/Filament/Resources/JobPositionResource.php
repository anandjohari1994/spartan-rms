<?php

namespace App\Filament\Resources;

use App\Models\JobPosition;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class JobPositionResource extends Resource
{
    protected static ?string $model = JobPosition::class;

    protected static string|\UnitEnum|null $navigationGroup = 'Administration';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-briefcase';

    public static function canAccess(): bool
    {
        return auth()->user()?->isAdmin() && auth()->user()->can('positions.manage');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([TextInput::make('name')->required()->maxLength(255), TextInput::make('code')->required()->maxLength(40)->unique(ignoreRecord: true), Textarea::make('description')->maxLength(10000), Toggle::make('is_active')->default(true)]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([TextColumn::make('name')->searchable(), TextColumn::make('code')->searchable(), IconColumn::make('is_active')->boolean(), TextColumn::make('candidates_count')->counts('candidates')])->recordActions([EditAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => JobPositionResource\Pages\ListJobPositions::route('/'), 'create' => JobPositionResource\Pages\CreateJobPosition::route('/create'), 'edit' => JobPositionResource\Pages\EditJobPosition::route('/{record}/edit')];
    }
}
