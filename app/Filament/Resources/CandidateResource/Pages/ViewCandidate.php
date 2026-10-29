<?php

namespace App\Filament\Resources\CandidateResource\Pages;

use App\Filament\Resources\CandidateResource;
use App\Filament\Support\AssessmentFollowUpAction;
use App\Filament\Support\RecordCallAction;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewCandidate extends ViewRecord
{
    protected static string $resource = CandidateResource::class;

    protected function getHeaderActions(): array
    {
        return [RecordCallAction::make(), EditAction::make(), AssessmentFollowUpAction::make()];
    }
}
