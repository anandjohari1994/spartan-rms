<?php

namespace App\Filament\Widgets;

use App\Services\DashboardService;
use Filament\Widgets\ChartWidget;

class PositionDistribution extends ChartWidget
{
    protected static ?int $sort = 5;

    protected ?string $heading = 'Candidates by position';

    protected ?string $pollingInterval = null;

    protected ?string $maxHeight = '280px';

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getData(): array
    {
        $data = app(DashboardService::class)->chart(auth()->user(), 'position');

        return ['labels' => array_keys($data), 'datasets' => [['label' => 'Candidates', 'data' => array_values($data), 'backgroundColor' => '#2563eb', 'borderColor' => '#2563eb']]];
    }
}
