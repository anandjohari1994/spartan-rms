<?php

namespace App\Filament\Widgets;

use App\Services\DashboardService;
use Filament\Widgets\ChartWidget;

class CandidateGrowth extends ChartWidget
{
    protected static ?int $sort = 2;

    protected ?string $heading = 'Candidates added · last 14 days';

    protected ?string $pollingInterval = null;

    protected ?string $maxHeight = '280px';

    protected function getType(): string
    {
        return 'line';
    }

    protected function getData(): array
    {
        $data = app(DashboardService::class)->chart(auth()->user(), 'growth');

        return ['labels' => array_keys($data), 'datasets' => [['label' => 'Candidates', 'data' => array_values($data), 'backgroundColor' => '#2563eb', 'borderColor' => '#2563eb']]];
    }
}
