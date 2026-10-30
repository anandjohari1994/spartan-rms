<?php

namespace App\Filament\Resources\EmailTemplateResource\Pages;

use App\Filament\Resources\EmailTemplateResource;
use App\Filament\Support\ActionFeedback;
use App\Services\EmailTemplateService;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditEmailTemplate extends EditRecord
{
    protected static string $resource = EmailTemplateResource::class;

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return ActionFeedback::run($this->form, fn () => app(EmailTemplateService::class)->save(auth()->user(), $data, $record));
    }
}
