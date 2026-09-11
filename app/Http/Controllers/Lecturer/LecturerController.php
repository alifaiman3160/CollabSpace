<?php

namespace App\Http\Controllers\Lecturer;

use App\Http\Controllers\Controller;
use App\Models\Group;
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
