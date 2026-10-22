<?php

namespace App\Filament\Resources\CandidateResource\RelationManagers;

use App\Filament\Resources\FollowUpResource;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Table;

class FollowUpsRelationManager extends RelationManager
{
    protected static string $relationship = 'followUps';

    public function table(Table $table): Table
    {
        return FollowUpResource::table($table)->recordActions([])->headerActions([Action::make('add')->label('Add follow-up')->url(fn () => FollowUpResource::getUrl('create', ['candidate_id' => $this->getOwnerRecord()->id]))]);
    }
}
