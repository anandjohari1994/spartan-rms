<?php

namespace App\Filament\Pages;

use App\Filament\Resources\CallLogResource;
use App\Filament\Resources\CandidateResource;
use App\Filament\Resources\FollowUpResource;
use App\Models\ApplicationSetting;
use Filament\Actions\Action;

class Dashboard extends \Filament\Pages\Dashboard
{
    public function getTitle(): string
    {
        return auth()->user()->isAdmin() ? 'Recruitment overview' : 'My recruitment workspace';
    }

    public function getSubheading(): ?string
    {
        return ApplicationSetting::where('key', 'company_name')->value('value') ?: 'Spartan Infosoft Pvt. Ltd.';
    }

    protected function getHeaderActions(): array
    {
        return [Action::make('addCandidate')->label('Add candidate')->url(CandidateResource::getUrl('create')),
            Action::make('candidates')->label('My candidates')->color('gray')->url(CandidateResource::getUrl()),
            Action::make('addCall')->label('Add call')->url(CallLogResource::getUrl('create')),
            Action::make('followups')->label('Follow-ups')->color('gray')->url(FollowUpResource::getUrl())];
    }
}
