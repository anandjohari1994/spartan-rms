<?php

use App\Models\CandidateDocument;
use App\Services\DocumentService;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/admin');
Route::get('/documents/{document}/download', function (CandidateDocument $document) {
    return app(DocumentService::class)->download(auth()->user(), $document);
})->middleware(['auth', 'throttle:60,1'])->name('documents.download');
