<?php

namespace App\Support\Sections;

use App\Models\Assessment;
use App\Models\Section;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * The dependent filter of the lecturer's list pages: a subject first, then (only for a subject with two or more
 * sections) one of its sections, then (only for a subject with two or more assessments) one of its assessments. The choice lives in the query string (?subject=ID&section=ID) and nowhere else.
 *
 * Only the lecturer's own subjects are offered. A subject id that is not theirs is ignored, and so is a section id
 * that does not belong to the chosen subject (a stale link, or someone else's), so it can neither error nor reveal
 * another lecturer's sections. With no subject chosen there are no sections to choose from at all.
 */
class SubjectSectionFilter
{
    /** More subjects than this are offered as a dropdown instead of chips. */
    public const CHIPS_UP_TO = 6;

    /**
     * @return array{subjects: Collection<int, Subject>, subjectId: ?int, sections: Collection<int, Section>, sectionId: ?int, assessments: Collection<int, Assessment>, assessmentId: ?int}
     */
    public static function for(Request $request, User $lecturer): array
    {
        $subjects = $lecturer->subjects()->orderBy('code')->orderBy('id')->get(['id', 'code', 'name']);

        $asked = $request->integer('subject') ?: null;
        $subjectId = $asked !== null && $subjects->contains('id', $asked) ? $asked : null;

        $sections = collect();

        if ($subjectId !== null) {
            $subject = $subjects->firstWhere('id', $subjectId);

            $sections = Section::query()->where('subject_id', $subjectId)->get()
                ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
                ->values()
                ->each(fn (Section $s) => $s->setRelation('subject', $subject));

            // A subject with one section has nothing to filter on.
            if ($sections->count() < 2) {
                $sections = collect();
            }
        }

        $wanted = $request->integer('section') ?: null;

        // The assessments of the chosen subject, offered only when there are several. A foreign or stale id is ignored.
        $assessments = collect();

        if ($subjectId !== null) {
            $subject = $subjects->firstWhere('id', $subjectId);
            $all = Assessment::query()->where('subject_id', $subjectId)->orderBy('position')->orderBy('id')->get();
            $subject->setRelation('assessments', $all);
            $all->each(fn (Assessment $a) => $a->setRelation('subject', $subject));

            $assessments = $all->count() > 1 ? $all : collect();
        }

        $askedAssessment = $request->integer('assessment') ?: null;

        return [
            'subjects' => $subjects,
            'subjectId' => $subjectId,
            'sections' => $sections,
            'sectionId' => $wanted !== null && $sections->contains('id', $wanted) ? $wanted : null,
            'assessments' => $assessments,
            'assessmentId' => $askedAssessment !== null && $assessments->contains('id', $askedAssessment) ? $askedAssessment : null,
        ];
    }

    /**
     * The assessment a page counts for, for one subject: the one asked for when it is one of this subject's (anything else
     * is ignored), otherwise the subject's active one. Null for a subject with none.
     */
    public static function assessmentFor(Subject $subject, mixed $asked = null): ?Assessment
    {
        $subject->loadMissing('assessments');
        $subject->assessments->each(fn (Assessment $a) => $a->setRelation('subject', $subject));

        return (filled($asked) ? $subject->assessments->firstWhere('id', (int) $asked) : null) ?? $subject->activeAssessment();
    }
}
