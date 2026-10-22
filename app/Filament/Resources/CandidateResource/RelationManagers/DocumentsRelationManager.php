<?php

namespace App\Filament\Resources\CandidateResource\RelationManagers;

use App\Filament\Support\Fields;
use App\Services\DocumentService;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class DocumentsRelationManager extends RelationManager
{
    protected static string $relationship = 'documents';

    public function isReadOnly(): bool
    {
        return false;
    }

    public function table(Table $table): Table
    {
        return $table->columns([TextColumn::make('original_name'), TextColumn::make('file_size')->numeric()->label('Bytes'), TextColumn::make('uploader.name'), TextColumn::make('created_at')->dateTime()])
            ->headerActions([Action::make('uploadResume')->visible(fn () => auth()->user()->can('update', $this->getOwnerRecord()))->schema([Fields::resume()->required()])->action(fn (array $data, \Filament\Schemas\Schema $schema) => \App\Filament\Support\ActionFeedback::run($schema, fn () => app(DocumentService::class)->upload(auth()->user(), $this->getOwnerRecord(), $data['resume'])))])
            ->recordActions([Action::make('download')->url(fn ($record) => route('documents.download', $record))->openUrlInNewTab()]);
    }
}
