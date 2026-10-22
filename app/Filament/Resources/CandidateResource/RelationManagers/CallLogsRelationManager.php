<?php

namespace App\Filament\Resources\CandidateResource\RelationManagers;

use App\Filament\Resources\CallLogResource;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Table;

class CallLogsRelationManager extends RelationManager
{
    protected static string $relationship = 'callLogs';

    public function table(Table $table): Table
    {
        return CallLogResource::table($table)->recordActions([])->headerActions([Action::make('add')->label('Add call')->url(fn () => CallLogResource::getUrl('create', ['candidate_id' => $this->getOwnerRecord()->id]))]);
    }
}
