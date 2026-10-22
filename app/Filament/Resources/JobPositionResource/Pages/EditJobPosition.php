<?php

namespace App\Filament\Resources\JobPositionResource\Pages;

use App\Filament\Resources\JobPositionResource;
use App\Filament\Support\ResourceWriter;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditJobPosition extends EditRecord
{
    protected static string $resource = JobPositionResource::class;

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return ResourceWriter::save(static::getModel(), $data, $record);
    }
}
