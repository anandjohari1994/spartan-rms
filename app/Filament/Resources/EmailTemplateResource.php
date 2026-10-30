<?php

namespace App\Filament\Resources;

use App\Models\EmailTemplate;
use App\Services\EmailTemplateService;
use App\Support\Recruitment;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class EmailTemplateResource extends Resource
{
    protected static ?string $model = EmailTemplate::class;

    protected static string|\UnitEnum|null $navigationGroup = 'Administration';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-envelope';

    public static function canAccess(): bool
    {
        return auth()->user()?->isAdmin() ?? false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->required()->maxLength(255),
            TextInput::make('code')->required()->maxLength(100)->unique(ignoreRecord: true),
            Select::make('round_number')->options(Recruitment::ROUNDS)->required(),
            Select::make('purpose')->options(['Invitation' => 'Invitation', 'Cleared' => 'Cleared'])->required(),
            Toggle::make('is_active')->default(true)->helperText('Deactivate a template to remove it from the email composer.'),
            TextInput::make('subject')->required()->maxLength(200)->columnSpanFull(),
            Select::make('body_format')->label('Message format')->options(['plain' => 'Plain text', 'markdown' => 'Formatted (Markdown)'])->default('plain')->required()->live(),
            Textarea::make('body')->required()->rows(24)->maxLength(20000)->columnSpanFull()->live(onBlur: true)->helperText('Formatted messages support ## Heading, **bold**, - bullet lists, numbered lists and [link text](https://...). HTML is not supported. Shared placeholders: '.implode(', ', array_map(fn ($v) => '{{'.$v.'}}', EmailTemplateService::VARIABLES)).'. Candidate names and individual credentials cannot be used in a shared BCC email.'),
            Section::make('Template preview')->columnSpanFull()
                ->description('Updates after leaving the body field. Any placeholders remain visible here and are filled when composing an email.')
                ->schema([
                    TextEntry::make('template_preview')->hiddenLabel()
                        ->state(fn (Get $get) => $get('body_format') === 'markdown'
                            ? EmailTemplateService::safeHtml((string) $get('body'))
                            : nl2br(e((string) $get('body'))))
                        ->html()->prose(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([TextColumn::make('name')->searchable(), TextColumn::make('round_number')->label('Round'), TextColumn::make('purpose')->badge(), IconColumn::make('is_active')->boolean(), TextColumn::make('updated_at')->since()])->recordActions([EditAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => EmailTemplateResource\Pages\ListEmailTemplates::route('/'), 'create' => EmailTemplateResource\Pages\CreateEmailTemplate::route('/create'), 'edit' => EmailTemplateResource\Pages\EditEmailTemplate::route('/{record}/edit')];
    }
}
