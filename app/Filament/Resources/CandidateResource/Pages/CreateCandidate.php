<?php

namespace App\Filament\Resources\CandidateResource\Pages;

use App\Filament\Resources\CandidateResource;
use App\Filament\Support\ResourceWriter;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateCandidate extends CreateRecord
{
    protected static string $resource = CandidateResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        return ResourceWriter::save(static::getModel(), $data);
    }
}
