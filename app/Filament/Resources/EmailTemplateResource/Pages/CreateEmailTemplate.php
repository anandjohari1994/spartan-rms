<?php

namespace App\Filament\Resources\EmailTemplateResource\Pages;

use App\Filament\Resources\EmailTemplateResource;
use App\Filament\Support\ActionFeedback;
use App\Services\EmailTemplateService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateEmailTemplate extends CreateRecord
{
    protected static string $resource = EmailTemplateResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        return ActionFeedback::run($this->form, fn () => app(EmailTemplateService::class)->save(auth()->user(), $data));
    }
}
