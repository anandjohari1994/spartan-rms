<?php

namespace App\Filament\Resources\FollowUpResource\Pages;

use App\Filament\Resources\FollowUpResource;
use App\Filament\Support\ResourceWriter;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateFollowUp extends CreateRecord
{
    protected static string $resource = FollowUpResource::class;

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
