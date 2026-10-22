<?php

namespace App\Filament\Resources\CandidateResource\Pages;

use App\Filament\Resources\CandidateResource;
use App\Filament\Support\ResourceWriter;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditCandidate extends EditRecord
{
    protected static string $resource = CandidateResource::class;

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return ResourceWriter::save(static::getModel(), $data, $record);
    }
}
