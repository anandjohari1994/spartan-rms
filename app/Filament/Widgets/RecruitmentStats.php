<?php

namespace App\Filament\Widgets;

use App\Services\DashboardService;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class RecruitmentStats extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    protected ?string $pollingInterval = null;

    protected int|array|null $columns = ['md' => 2, 'xl' => 4];

    protected function getStats(): array
    {
        $stats = app(DashboardService::class)->stats(auth()->user());

        return collect($stats)->map(fn ($value, $label) => Stat::make($label, number_format($value))->color(str_contains($label, 'Overdue') ? 'danger' : 'primary'))->all();
    }
}
