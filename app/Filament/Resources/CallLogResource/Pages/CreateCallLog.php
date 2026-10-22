<?php

namespace App\Filament\Resources\CallLogResource\Pages;

use App\Filament\Resources\CallLogResource;
use App\Filament\Support\ResourceWriter;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateCallLog extends CreateRecord
{
    protected static string $resource = CallLogResource::class;

    protected function afterFill(): void
    {
        $candidate = \App\Models\Candidate::visibleTo(auth()->user())->find(request()->integer('candidate_id'));
        if ($candidate) $this->data['candidate_id'] = $candidate->id;
    }
    protected function handleRecordCreation(array $data): Model
    {
        return ResourceWriter::save(static::getModel(), $data);
    }
}
