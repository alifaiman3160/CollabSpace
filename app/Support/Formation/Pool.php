<?php

namespace App\Support\Formation;

use App\Models\Section;
use App\Models\Subject;
use App\Models\User;
use App\Support\Sections\Enrolments;
use Illuminate\Support\Collection;

/**
 * Who can still be placed in a subject.
 *
 * Active students enrolled in this subject AND in this section who have no
 * group in the subject yet. Formation runs one section at a time, so a student
 * of another section is never offered. The lecturer can still untick people
 * from the pool before balancing.
 */
class Pool
{
    /** @return Collection<int, User> */
    public static function for(Subject $subject, ?Section $section): Collection
    {
        // No section chosen, or one that belongs to another subject: nobody.
        if ($section === null || $section->subject_id !== $subject->getKey()) {
            return collect();
        }

        return Enrolments::students($subject, $section)
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
