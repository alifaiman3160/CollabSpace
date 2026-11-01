<?php

namespace App\Support\Sections;

use App\Models\Enrolment;
use App\Models\Section;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Who is enrolled where. The administrator owns enrolment (admin user form); everything else only reads it.
 *
 * A student is enrolled in a subject with exactly one section (unique user+subject). Sections are found or created by
 * name within their subject, never by code, because two lecturers can both teach a "BCS3323".
 */
class Enrolments
{
    public const NAME_MAX = 20;

    /** Active students enrolled in the subject, optionally in one section only. */
    public static function students(Subject $subject, ?Section $section = null): Builder
    {
        return User::query()
            ->where('role', 'student')
            ->where('is_active', true)
            ->whereIn('users.id', Enrolment::query()
                ->where('subject_id', $subject->getKey())
                ->when($section, fn ($q) => $q->where('section_id', $section->getKey()))
                ->select('user_id'));
    }

    /** The section a student is enrolled in for a subject, or null when they are not enrolled in it. */
    public static function sectionOf(User $student, int|Subject $subject): ?Section
    {
        $subjectId = $subject instanceof Subject ? $subject->getKey() : $subject;

        return Section::query()
            ->whereIn('id', Enrolment::query()->where('user_id', $student->getKey())->where('subject_id', $subjectId)->select('section_id'))
            ->first();
    }

    /**
     * Makes the student's enrolments exactly the given rows: adds, changes a section, removes.
     *
     * Refuses (nothing is written) to remove a student from a subject, or to move them to another section of it,
     * while they are in a group of that subject: the group would then hold somebody who is not in its section. They
     * must be taken out of the group first.
     *
     * @param  list<array{subject_id: int|string, section: string}>  $rows
     *
     * @throws EnrolmentException
     */
    public static function sync(User $student, array $rows): void
    {
        DB::transaction(function () use ($student, $rows) {
            $current = $student->enrolments()->with('section')->get()->keyBy('subject_id');
            $wanted = collect($rows)->keyBy(fn ($row) => (int) $row['subject_id']);

            $grouped = $student->groups()->with('subject:id,code')->get()->keyBy('subject_id');

            foreach ($current as $subjectId => $enrolment) {
                $row = $wanted->get($subjectId);
                $group = $grouped->get($subjectId);

                if ($group === null) {
                    continue;
                }

                if ($row === null) {
                    throw new EnrolmentException("{$student->name} is in {$group->name} of {$group->subject->code}. Remove them from that group before removing them from the subject.");
                }

                if (self::norm($row['section']) !== self::norm((string) $enrolment->section?->name)) {
                    throw new EnrolmentException("{$student->name} is in {$group->name} of {$group->subject->code}. Remove them from that group before changing their section.");
                }
            }

            foreach ($wanted as $subjectId => $row) {
                $section = self::section(Subject::findOrFail($subjectId), $row['section']);

                Enrolment::updateOrCreate(
                    ['user_id' => $student->getKey(), 'subject_id' => $subjectId],
                    ['section_id' => $section->getKey()],
                );
            }

            $student->enrolments()->whereNotIn('subject_id', $wanted->keys())->delete();
        });
    }

    /** The subject's section with this name (any capitals), created when it does not exist yet. */
    public static function section(Subject $subject, string $name): Section
    {
        $name = self::clean($name);

        return Section::query()->where('subject_id', $subject->getKey())->whereRaw('lower(name) = ?', [self::norm($name)])->first()
            ?? Section::create(['subject_id' => $subject->getKey(), 'name' => $name]);
    }

    public static function clean(string $name): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $name));
    }

    protected static function norm(string $name): string
    {
        return mb_strtolower(self::clean($name));
    }
}
