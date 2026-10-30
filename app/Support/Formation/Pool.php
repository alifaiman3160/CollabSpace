<?php

namespace App\Support\Formation;

use App\Models\Subject;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Who can still be placed in a subject.
 *
 * Active student accounts with no group in it. CollabSpace has no enrolment
 * table, so there is no list of who is actually taking a subject: every active
 * student is a candidate for every subject, exactly as when a lecturer assigns
 * people by hand. Both formation modes therefore let the lecturer choose who is
 * in the pool rather than assuming everyone is.
 */
class Pool
{
    /** @return Collection<int, User> */
    public static function for(Subject $subject): Collection
    {
        return User::query()
            ->where('role', 'student')
            ->where('is_active', true)
            ->whereDoesntHave('groups', fn ($q) => $q->where('subject_id', $subject->getKey()))
            ->orderBy('name')
            ->get();
    }

    /**
     * A student as the allocator and the preview see them: a snapshot, so the
     * figures on the screen are the figures that get recorded even if an
     * administrator edits a CGPA in between.
     *
     * @return array{id: int, name: string, matric_no: ?string, cgpa: ?float}
     */
    public static function snapshot(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'matric_no' => $user->matric_no,
            'cgpa' => $user->cgpa === null ? null : (float) $user->cgpa,
        ];
    }
}
