<?php

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Models\Group;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Base for the student area.
 *
 * A student reaches exactly the groups they are a member of, and nothing else.
 * Every controller here resolves its models through these helpers rather than
 * through route model binding alone, so another group's board cannot be opened
 * by changing an id in the URL.
 *
 * Aborts 404 rather than 403, matching the lecturer area: a student should not
 * be able to learn that a group exists by probing ids.
 */
abstract class StudentController extends Controller
{
    protected function student(): User
    {
        return auth()->user();
    }

    /**
     * Groups the signed-in student belongs to. Columns are qualified because
     * callers join subjects onto this, and both tables carry an `id`.
     */
    protected function groups(): Builder
    {
        return Group::query()->whereIn(
            'groups.id',
            $this->student()->groupMemberships()->select('group_id'),
        );
    }

    /** @return Collection<int, Group> */
    protected function myGroups(): Collection
    {
        return $this->groups()
            ->with('subject')
            ->select('groups.*')
            ->join('subjects', 'subjects.id', '=', 'groups.subject_id')
            ->orderBy('subjects.code')
            ->orderBy('groups.name')
            ->get();
    }

    /** @throws \Symfony\Component\HttpKernel\Exception\NotFoundHttpException */
    protected function ownedGroup(Group $group): Group
    {
        abort_unless(
            $this->student()->groupMemberships()->where('group_id', $group->getKey())->exists(),
            404,
        );

        return $group;
    }
}
