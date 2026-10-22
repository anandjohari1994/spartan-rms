<?php

namespace App\Filament\Resources\FollowUpResource\Pages;

use App\Filament\Resources\FollowUpResource;
use App\Filament\Support\ResourceWriter;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditFollowUp extends EditRecord
{
    protected static string $resource = FollowUpResource::class;

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return ResourceWriter::save(static::getModel(), $data, $record);
    }
}
