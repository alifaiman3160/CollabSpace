<?php

namespace App\Http\Controllers;

use App\Models\Submission;
use App\Support\Submissions\Submissions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * The one way to a submission's PDF: the members of that group and the lecturer who owns the subject. Anyone else is 403.
 * Streamed from the private disk; the storage path is never shown. ?inline=1 opens it in the browser, otherwise it downloads.
 */
class SubmissionDownloadController extends Controller
{
    public function show(Request $request, Submission $submission): Response
    {
        $user = $request->user();
        $submission->loadMissing('group.subject');
        $group = $submission->group;

        $allowed = $group !== null && (
            ($user->isStudent() && $group->groupMembers()->where('user_id', $user->getKey())->exists())
            || ($user->isLecturer() && $group->subject?->lecturer_id === $user->getKey())
        );

        abort_unless($allowed, 403);

        $disk = Storage::disk(Submissions::DISK);

        abort_unless($disk->exists($submission->pdf_path), 404);

        return $disk->response($submission->pdf_path, $submission->downloadName(), [
            'Content-Type' => 'application/pdf',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=0, must-revalidate',
        ], $request->boolean('inline') ? 'inline' : 'attachment');
    }
}
