<?php

namespace App\Http\Controllers;

use App\Models\DocumentImage;
use App\Support\GroupFiles;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serves a picture that sits inside a document.
 *
 * The files are on the private disk, so this route is the only way to them. It is shared by the
 * editor, the read-only version view and the lecturer's view, which is why it is not under either
 * role's prefix: the document body points at one address, and who may see it is decided here.
 *
 *   a student    must be a member of the document's group
 *   a lecturer   must own the subject the group sits under
 *   anyone else  gets a 404, so ids cannot be probed
 */
class DocumentImageController extends Controller
{
    public function show(DocumentImage $image): Response
    {
        // A deleted document still owns its pictures (the lecturer can read it); students no longer reach them.
        $document = $image->document()->withTrashed()->with('group.subject')->first();
        $group = $document?->group;
        $user = auth()->user();

        abort_if($group === null, 404);

        $allowed = match ($user->role) {
            'student' => ! $document->trashed() && $user->groupMemberships()->where('group_id', $group->getKey())->exists(),
            'lecturer' => $group->subject?->lecturer_id === $user->id,
            default => false,
        };

        abort_unless($allowed, 404);

        $disk = Storage::disk(GroupFiles::DISK);

        abort_unless($disk->exists($image->path), 404);

        return $disk->response($image->path, null, [
            'Content-Type' => $image->mime,
            'Content-Disposition' => 'inline',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }
}
