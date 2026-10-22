<?php

namespace App\Filament\Widgets;

use App\Services\DashboardService;
use Filament\Widgets\ChartWidget;

class CandidateDistribution extends ChartWidget
{
    protected static ?int $sort = 3;

    protected ?string $heading = 'Candidate ownership by HR';

    protected ?string $pollingInterval = null;

    protected ?string $maxHeight = '280px';

    public static function canView(): bool
    {
        return auth()->user()?->isAdmin() ?? false;
    }

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getData(): array
    {
        $data = app(DashboardService::class)->chart(auth()->user(), 'hr');

        return ['labels' => array_keys($data), 'datasets' => [['label' => 'Candidates', 'data' => array_values($data), 'backgroundColor' => '#2563eb', 'borderColor' => '#2563eb']]];
    }
}
