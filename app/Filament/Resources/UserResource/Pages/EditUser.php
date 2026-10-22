<?php

namespace App\Filament\Resources\UserResource\Pages;

use App\Filament\Resources\UserResource;
use App\Filament\Support\ResourceWriter;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return ResourceWriter::save(static::getModel(), $data, $record);
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['role'] = $this->record->getRoleNames()->first();
        unset($data['password']);

        return $data;
    }
}
