<?php

namespace App\Filament\Resources\CandidateResource\Pages;

use App\Filament\Resources\CandidateResource;
use App\Models\Candidate;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;

class ListCandidates extends ListRecords
{
    protected static string $resource = CandidateResource::class;

    public function getTabs(): array
    {
        $tab = Tab::class;

        return [
            'all' => $tab::make('All candidates'),
            'email_sent' => $tab::make('Email sent')
                ->badge(fn () => Candidate::visibleTo(auth()->user())->whereNotNull('last_email_sent_at')->count())
                ->modifyQueryUsing(fn ($query) => $query->whereNotNull('last_email_sent_at')),
            'round_one_email_sent' => $tab::make('Round 1 email sent')
                ->badge(fn () => Candidate::visibleTo(auth()->user())->whereNotNull('round_one_email_sent_at')->count())
                ->modifyQueryUsing(fn ($query) => $query->whereNotNull('round_one_email_sent_at')),
            'round_one_scheduled' => $tab::make('Round 1 scheduled')
                ->badge(fn () => Candidate::visibleTo(auth()->user())->whereHas('interviewRounds', fn ($q) => $q->where('round_number', 1)->where('status', 'Scheduled'))->count())
                ->modifyQueryUsing(fn ($query) => $query->whereHas('interviewRounds', fn ($q) => $q->where('round_number', 1)->where('status', 'Scheduled'))),
            'not_called' => $tab::make('Not Called')->modifyQueryUsing(fn ($query) => $query->where('calling_status', 'Not Called')),
            'today' => $tab::make('Due Today')->modifyQueryUsing(fn ($query) => $query->whereHas('followUps', fn ($q) => $q->where('status', 'Pending')->whereDate('scheduled_at', today()))),
            'overdue' => $tab::make('Overdue')->modifyQueryUsing(fn ($query) => $query->whereHas('followUps', fn ($q) => $q->where('status', 'Pending')->where('scheduled_at', '<', now()))),
            'interested' => $tab::make('Interested')->modifyQueryUsing(fn ($query) => $query->where('interest_status', 'Interested')),
            'interviews' => $tab::make('Upcoming Interviews')->modifyQueryUsing(fn ($query) => $query->whereHas('interviewRounds', fn ($q) => $q->where('status', 'Scheduled')->where('scheduled_at', '>=', now()))),
        ];
    }

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
