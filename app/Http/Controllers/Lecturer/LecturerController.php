<?php

namespace App\Http\Controllers\Lecturer;

use App\Http\Controllers\Controller;
use App\Models\Group;
use App\Models\Section;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Base for the lecturer area.
 *
 * Ownership runs through the subject: a lecturer owns a subject, and through it
 * every group, member and task underneath. Nothing in this area may be reached
 * by putting another lecturer's id in the URL, so every controller resolves its
 * models through the helpers here rather than through route model binding
 * alone.
 *
 * These abort with 404 rather than 403 — a lecturer should not be able to learn
 * that another lecturer's subject exists by probing ids.
 */
abstract class LecturerController extends Controller
{
    protected function lecturer(): User
    {
        return auth()->user();
    }

    /** Subjects belonging to the signed-in lecturer. */
    protected function subjects(): Builder
    {
        return Subject::query()->where('lecturer_id', $this->lecturer()->id);
    }

    /** Groups sitting under the signed-in lecturer's subjects. */
    protected function groups(): Builder
    {
        return Group::query()->whereIn('subject_id', $this->subjects()->select('id'));
    }

    /**
     * The section of this subject named by an id (404 when it belongs to another subject, so another lecturer's
     * sections cannot be probed). With no id, the subject's only section; with several, none: the caller asks.
     *
     * @throws \Symfony\Component\HttpKernel\Exception\NotFoundHttpException
     */
    protected function sectionFor(Subject $subject, mixed $id): ?Section
    {
        if (filled($id)) {
            return Section::query()->where('subject_id', $subject->getKey())->findOrFail((int) $id);
        }

        $all = $subject->sections()->limit(2)->get();

        return $all->count() === 1 ? $all->first() : null;
    }

    /**
     * The contribution reports of several groups, each counted for its own subject's assessment: the one chosen on the
     * page when the subject is the chosen one, otherwise the subject's active assessment. One read of the log per subject.
     *
     * @param  \Illuminate\Support\Collection<int, Group>  $groups  with their subject
     * @return \Illuminate\Support\Collection<int, array>  keyed by group id
     */
    protected function contributionFor(\App\Support\ContributionReport $reports, \Illuminate\Support\Collection $groups, ?int $chosenSubject, mixed $asked): \Illuminate\Support\Collection
    {
        $all = [];
        $plain = new \Illuminate\Database\Eloquent\Collection;

        foreach ($groups->groupBy('subject_id') as $subjectId => $list) {
            $subject = $list->first()->subject;
            $assessment = \App\Support\Sections\SubjectSectionFilter::assessmentFor($subject, $chosenSubject === $subject->getKey() ? $asked : null);

            // Subjects whose activity is not split by dates share one read of the log; only a subject with several
            // dated assessments needs its own.
            if ($assessment?->activityWindow() === null) {
                $plain = $plain->merge($list);

                continue;
            }

            $all += $reports->forMany($list, $assessment)->all();
        }

        if ($plain->isNotEmpty()) {
            $all += $reports->forMany($plain)->all();
        }

        return collect($all);
    }

    /** @throws \Symfony\Component\HttpKernel\Exception\NotFoundHttpException */
    protected function ownedSubject(Subject $subject): Subject
    {
        abort_unless($subject->lecturer_id === $this->lecturer()->id, 404);

        return $subject;
    }

    /** @throws \Symfony\Component\HttpKernel\Exception\NotFoundHttpException */
    protected function ownedGroup(Group $group): Group
    {
        $group->loadMissing('subject');

        abort_unless(
            $group->subject !== null && $group->subject->lecturer_id === $this->lecturer()->id,
            404,
        );

        return $group;
    }
}
