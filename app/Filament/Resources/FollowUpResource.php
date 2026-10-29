<?php

namespace App\Filament\Resources;

use App\Filament\Support\AssessmentFollowUpAction;
use App\Filament\Support\Fields;
use App\Filament\Support\RecordCallAction;
use App\Models\FollowUp;
use App\Services\AssessmentFollowUpService;
use App\Services\FollowUpService;
use App\Support\Recruitment;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Textarea;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class FollowUpResource extends Resource
{
    protected static ?string $model = FollowUp::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-calendar-days';

    protected static ?int $navigationSort = 3;

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->visibleTo(auth()->user())->with('candidate');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([Fields::candidate()->disabledOn('edit'), Fields::select('purpose', AssessmentFollowUpService::PURPOSES)->default('General'), DateTimePicker::make('scheduled_at')->required()->seconds(false), Fields::select('priority', ['Low', 'Medium', 'High'])->default('Medium'), Fields::select('status', ['Pending', 'Completed', 'Cancelled'])->default('Pending'), Textarea::make('notes')->maxLength(10000)->columnSpanFull()]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([TextColumn::make('candidate.full_name')->searchable(), TextColumn::make('purpose')->badge(), TextColumn::make('scheduled_at')->dateTime()->sortable(), TextColumn::make('priority')->badge(), TextColumn::make('status')->badge(), TextColumn::make('is_overdue')->label('Overdue')->formatStateUsing(fn ($state) => $state ? 'Overdue' : '—')->color('danger'), TextColumn::make('notes')->limit(80)])
            ->defaultSort('scheduled_at')->filters([
                SelectFilter::make('purpose')->options(Recruitment::options(AssessmentFollowUpService::PURPOSES)),
                SelectFilter::make('status')->options(Recruitment::options(['Pending', 'Completed', 'Cancelled'])),
                Filter::make('today')->query(fn (Builder $q) => $q->whereDate('scheduled_at', today())),
                Filter::make('upcoming')->query(fn (Builder $q) => $q->where('status', 'Pending')->where('scheduled_at', '>=', now())),
                Filter::make('overdue')->query(fn (Builder $q) => $q->where('status', 'Pending')->where('scheduled_at', '<', now())),
            ])->recordActions([RecordCallAction::make(), AssessmentFollowUpAction::make(), EditAction::make()->visible(fn (FollowUp $r) => $r->status === 'Pending'), Action::make('complete')->requiresConfirmation()->visible(fn (FollowUp $r) => $r->status === 'Pending')->action(fn (FollowUp $r) => app(FollowUpService::class)->complete(auth()->user(), $r))]);
    }

    public static function getPages(): array
    {
        return ['index' => FollowUpResource\Pages\ListFollowUps::route('/'), 'create' => FollowUpResource\Pages\CreateFollowUp::route('/create'), 'edit' => FollowUpResource\Pages\EditFollowUp::route('/{record}/edit')];
    }
}
