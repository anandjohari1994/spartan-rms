<?php

use App\Models\Candidate;
use App\Models\CandidateDocument;
use App\Services\DocumentService;
use App\Services\OfferLetterService;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/admin');
Route::get('/offer-letters/{candidate}/preview', function (Candidate $candidate) {
    return app(OfferLetterService::class)->preview(auth()->user(), $candidate);
})->middleware(['auth', 'throttle:60,1'])->name('offer-letters.preview');
Route::get('/documents/{document}/download', function (CandidateDocument $document) {
    return app(DocumentService::class)->download(auth()->user(), $document);
})->middleware(['auth', 'throttle:60,1'])->name('documents.download');
