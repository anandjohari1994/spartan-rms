<?php

namespace App\Filament\Pages;

use App\Models\JobPosition;
use App\Models\User;
use App\Services\ReportService;
use App\Support\Recruitment;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Pages\Page;
use Filament\Schemas\Schema;

class Reports extends Page
{
    protected string $view = 'filament.pages.reports';

    protected static string|\UnitEnum|null $navigationGroup = 'Administration';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-document-chart-bar';

    public ?array $data = [];

    public static function canAccess(): bool
    {
        return auth()->user()?->isAdmin() && auth()->user()->can('reports.view');
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        $this->form->fill(['type' => 'range', 'from' => today()->startOfMonth()->toDateString(), 'until' => today()->toDateString()]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->statePath('data')->columns(3)->components([
            Select::make('type')->label('Report')->options(ReportService::TYPES)->required(), DatePicker::make('from'), DatePicker::make('until')->afterOrEqual('from'),
            Select::make('assigned_hr_id')->label('Assigned HR')->options(fn () => User::role('HR Executive')->pluck('name', 'id'))->searchable(),
            Select::make('job_position_id')->label('Position')->options(fn () => JobPosition::pluck('name', 'id'))->searchable(), TextInput::make('location')->maxLength(120),
            Select::make('recruitment_status')->options(Recruitment::options(Recruitment::STATUSES)), Select::make('calling_status')->options(Recruitment::options(Recruitment::CALLS)),
            Select::make('round_number')->label('Progressed interview round')->options(Recruitment::ROUNDS),
        ]);
    }

    public function preview(): void
    {
        app(ReportService::class)->validate(auth()->user(), $this->form->getState());
    }

    public function export()
    {
        return app(ReportService::class)->export(auth()->user(), $this->form->getState());
    }

    protected function getViewData(): array
    {
        return ['rows' => iterator_to_array(app(ReportService::class)->rows(auth()->user(), $this->data, 50))];
    }
}
