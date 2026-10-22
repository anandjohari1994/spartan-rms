<?php

namespace App\Filament\Widgets;

use App\Services\DashboardService;
use Filament\Widgets\ChartWidget;

class InterviewOutcomes extends ChartWidget
{
    protected static ?int $sort = 6;

    protected ?string $heading = 'Interview round outcomes';

    protected ?string $pollingInterval = null;

    protected ?string $maxHeight = '280px';

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getData(): array
    {
        $data = app(DashboardService::class)->chart(auth()->user(), 'outcomes');

        return ['labels' => array_keys($data), 'datasets' => [['label' => 'Rounds', 'data' => array_values($data), 'backgroundColor' => '#2563eb', 'borderColor' => '#2563eb']]];
    }
}
