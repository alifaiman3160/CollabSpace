<?php

namespace App\Http\Controllers\Student;

use App\Models\Announcement;
use App\Models\AnnouncementRead;
use App\Support\Sections\AnnouncementAudience;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Records that the signed-in student has opened some announcements. Called once,
 * by the dashboard, when a student opens a subject's strip. Only announcements
 * of subjects the student is grouped in can be marked: any other id is a 404.
 */
class AnnouncementController extends StudentController
{
    public function read(Request $request): JsonResponse
    {
        $ids = collect($request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:200'],
            'ids.*' => ['integer'],
        ])['ids'])->unique()->values();

        // Only announcements this student may see: their subjects, for every section or for their own.
        $found = AnnouncementAudience::visibleTo($this->student())->whereIn('announcements.id', $ids)->pluck('announcements.id');

        abort_unless($found->count() === $ids->count(), 404);

        $now = now();

        // insertOrIgnore, against the unique pair: reading twice never makes a second row.
        AnnouncementRead::insertOrIgnore($found->map(fn ($id) => [
            'announcement_id' => $id,
            'user_id' => $this->student()->id,
            'read_at' => $now,
        ])->all());

        return response()->json(['read' => $found->count()]);
    }
}
