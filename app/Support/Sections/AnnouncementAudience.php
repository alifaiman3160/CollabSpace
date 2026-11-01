<?php

namespace App\Support\Sections;

use App\Models\Announcement;
use App\Models\Enrolment;
use App\Models\GroupMember;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Who an announcement is for, and which announcements a student sees.
 *
 * Enrolment decides: a student enrolled in the subject gets its announcements, grouped yet or not, and one aimed at a
 * single section reaches that section only. A student who predates enrolments and is only known through a group still
 * gets the announcements meant for every section.
 */
class AnnouncementAudience
{
    /**
     * Active students an announcement of this subject reaches.
     *
     * @return Collection<int, int>  user ids
     */
    public static function studentIds(Subject $subject, ?int $sectionId = null): Collection
    {
        $ids = Enrolment::query()
            ->where('subject_id', $subject->getKey())
            ->when($sectionId, fn ($q) => $q->where('section_id', $sectionId))
            ->pluck('user_id');

        if ($sectionId === null) {
            $ids = $ids->merge(GroupMember::query()->whereIn('group_id', $subject->groups()->select('id'))->pluck('user_id'));
        }

        return User::query()->whereIn('id', $ids->unique()->values())->where('is_active', true)->where('role', 'student')->pluck('id');
    }

    /** The announcements one student may see: their subjects, for every section or for their own. */
    public static function visibleTo(User $student): Builder
    {
        $enrolled = Enrolment::query()->where('user_id', $student->getKey())->get(['subject_id', 'section_id']);
        $groupSubjects = $student->groups()->pluck('groups.subject_id');

        return Announcement::query()->where(function (Builder $q) use ($enrolled, $groupSubjects) {
            // Meant for every section: any subject the student is enrolled in or grouped in.
            $q->where(fn (Builder $all) => $all->whereNull('section_id')
                ->whereIn('subject_id', $enrolled->pluck('subject_id')->merge($groupSubjects)->unique()->values()));

            // Meant for one section: only that section's students.
            foreach ($enrolled as $enrolment) {
                $q->orWhere(fn (Builder $one) => $one->where('subject_id', $enrolment->subject_id)->where('section_id', $enrolment->section_id));
            }
        });
    }
}
