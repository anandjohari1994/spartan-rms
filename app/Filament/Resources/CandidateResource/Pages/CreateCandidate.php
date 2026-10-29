<?php

namespace App\Filament\Resources\CandidateResource\Pages;

use App\Filament\Resources\CandidateResource;
use App\Filament\Support\ResourceWriter;
use Filament\Actions\Action;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateCandidate extends CreateRecord
{
    public bool $recordCallAfterSave = false;

    protected function getFormActions(): array
    {
        return [
            $this->getCreateFormAction()->label('Save candidate'),
            Action::make('saveAndCall')->label('Save candidate & record call')->action('saveAndCall'),
            $this->getCancelFormAction(),
        ];
    }

    public function saveAndCall(): void
    {
        $this->recordCallAfterSave = true;
        try {
            $this->create();
        } finally {
            $this->recordCallAfterSave = false;
        }
    }

    protected function getRedirectUrl(): string
    {
        return $this->recordCallAfterSave
            ? CandidateResource::getUrl('view', ['record' => $this->getRecord(), 'action' => 'recordCall'])
            : parent::getRedirectUrl();
    }

    protected static string $resource = CandidateResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        return ResourceWriter::save(static::getModel(), $data);
    }
}
