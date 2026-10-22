<?php

namespace App\Filament\Resources;

use App\Models\User;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $navigationLabel = 'HR & users';

    protected static string|\UnitEnum|null $navigationGroup = 'Administration';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-identification';

    public static function canAccess(): bool
    {
        return auth()->user()?->isAdmin() && auth()->user()->can('users.manage');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([TextInput::make('name')->required()->maxLength(255), TextInput::make('email')->email()->required()->unique(ignoreRecord: true), TextInput::make('mobile')->tel()->maxLength(20), TextInput::make('password')->password()->revealable()->required(fn (string $operation) => $operation === 'create')->dehydrated(fn ($state) => filled($state))->helperText('At least 12 characters, mixed case and a number. Leave blank to retain the password.'), Select::make('role')->options(['HR Executive' => 'HR Executive', 'Super Admin' => 'Super Admin'])->default('HR Executive')->required(), Toggle::make('is_active')->default(true)->required()]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([TextColumn::make('name')->searchable(), TextColumn::make('email')->searchable(), TextColumn::make('roles.name')->badge(), IconColumn::make('is_active')->boolean(), TextColumn::make('candidates_count')->counts('candidates')->label('Assigned candidates'), TextColumn::make('last_login_at')->dateTime()])->recordActions([EditAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => UserResource\Pages\ListUsers::route('/'), 'create' => UserResource\Pages\CreateUser::route('/create'), 'edit' => UserResource\Pages\EditUser::route('/{record}/edit')];
    }
}
