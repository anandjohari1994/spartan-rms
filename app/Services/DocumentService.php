<?php

namespace App\Services;

use App\Models\Candidate;
use App\Models\CandidateDocument;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class DocumentService
{
    public function upload(User $actor, Candidate $candidate, UploadedFile $file): CandidateDocument
    {
        Gate::forUser($actor)->authorize('update', $candidate);
        abort_unless($actor->can('documents.access'), 403);
        Validator::make(['resume' => $file], ['resume' => 'required|file|max:5120'])->validate();
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($file->getRealPath());
        $extension = strtolower($file->getClientOriginalExtension());
        $valid = $extension === 'pdf' && $mime === 'application/pdf';
        if ($extension === 'docx' && in_array($mime, ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip'])) {
            $zip = new \ZipArchive;
            if ($zip->open($file->getRealPath()) === true) {
                $valid = $zip->locateName('[Content_Types].xml') !== false && $zip->locateName('word/document.xml') !== false && $zip->locateName('word/vbaProject.bin') === false;
                $zip->close();
            }
        }
        if (! $valid) {
            throw ValidationException::withMessages(['resume' => 'Upload a genuine PDF or DOCX document (maximum 5 MB).']);
        }
        $path = null;
        $safeOriginalName = preg_replace('/[\x00-\x1F\x7F]/', '', basename(str_replace('\\', '/', $file->getClientOriginalName())));
        try {
            return DB::transaction(function () use ($actor, $candidate, $file, $extension, $mime, $safeOriginalName, &$path) {
                $c = Candidate::whereKey($candidate->id)->lockForUpdate()->firstOrFail();
                Gate::forUser($actor)->authorize('update', $c);
                $path = $file->storeAs('resumes/'.$c->id, Str::uuid().'.'.$extension, 'private');
                $document = new CandidateDocument;
                $document->forceFill(['candidate_id' => $c->id, 'document_type' => 'Resume', 'private_path' => $path, 'original_name' => mb_substr($safeOriginalName, 0, 240), 'mime_type' => $extension === 'docx' ? 'application/vnd.openxmlformats-officedocument.wordprocessingml.document' : $mime, 'file_size' => $file->getSize(), 'uploaded_by' => $actor->id])->save();
                app(AuditService::class)->record($actor, $document, 'resume.uploaded', [], ['candidate_id' => $c->id]);

                return $document;
            });
        } catch (\Throwable $e) {
            if ($path) {
                Storage::disk('private')->delete($path);
            } throw $e;
        }
    }

    public function download(User $actor, CandidateDocument $document)
    {
        Gate::forUser($actor)->authorize('view', $document);
        abort_unless($actor->can('documents.access'), 403);
        abort_unless(preg_match('#^resumes/[0-9]+/[a-f0-9-]{36}\.(pdf|docx)$#D', $document->private_path), 404);
        abort_unless(Storage::disk('private')->exists($document->private_path), 404);
        app(AuditService::class)->record($actor, $document, 'resume.downloaded');

        return Storage::disk('private')->download($document->private_path, $document->original_name, ['Content-Type' => $document->mime_type, 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store']);
    }
}
