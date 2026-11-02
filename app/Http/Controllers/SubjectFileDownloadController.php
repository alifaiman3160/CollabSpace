<?php

namespace App\Http\Controllers;

use App\Models\SubjectFile;
use App\Support\SubjectFiles;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * The one way to a subject file. Shared by the lecturer and the students, so it sits outside either prefix and decides
 * here: the lecturer who owns the subject, or a student enrolled in it (or in one of its groups). Anyone else is 403.
 * The file is streamed from the private disk; the storage path is never shown.
 */
class SubjectFileDownloadController extends Controller
{
    public function show(SubjectFile $file): Response
    {
        $file->loadMissing('subject');

        abort_unless($file->subject !== null && SubjectFiles::canRead(auth()->user(), $file->subject), 403);

        $disk = Storage::disk(SubjectFiles::DISK);

        abort_unless($disk->exists($file->stored_path), 404);

        return $disk->response($file->stored_path, $file->downloadName(), [
            'Content-Type' => $file->mime,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=0, must-revalidate',
        ], $file->opensInBrowser() ? 'inline' : 'attachment');
    }
}
