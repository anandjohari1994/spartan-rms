<?php

namespace App\Filament\Resources\CallLogResource\Pages;

use App\Filament\Resources\CallLogResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListCallLogs extends ListRecords
{
    protected static string $resource = CallLogResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
