<?php

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\Task;
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
            ->with(['subject', 'section'])
            ->select('groups.*')
            ->join('subjects', 'subjects.id', '=', 'groups.subject_id')
            ->orderBy('subjects.code')
            ->orderBy('groups.name')
            ->get();
    }

    /**
     * The quiet line under each group's name in the group picker ("4 members"): one grouped count for all of the
     * student's groups, never one query per card.
     *
     * @param  Collection<int, Group>  $groups
     * @param  'members'|'tasks'|'documents'  $kind
     * @return array<int, string>  group id => text
     */
    protected function groupSecondary(Collection $groups, string $kind): array
    {
        $ids = $groups->modelKeys();

        $counts = match ($kind) {
            'members' => GroupMember::query()->whereIn('group_id', $ids)->groupBy('group_id')
                ->selectRaw('group_id, count(*) as total')->pluck('total', 'group_id'),
            'tasks' => Task::query()->whereIn('group_id', $ids)->where('status', '!=', Task::STATUS_DONE)->groupBy('group_id')
                ->selectRaw('group_id, count(*) as total')->pluck('total', 'group_id'),
            'documents' => Document::query()->whereIn('group_id', $ids)->groupBy('group_id')
                ->selectRaw('group_id, count(*) as total')->pluck('total', 'group_id'),
        };

        $noun = ['members' => 'member', 'tasks' => 'open task', 'documents' => 'document'][$kind];

        return $groups->mapWithKeys(fn (Group $group) => [
            $group->getKey() => ($n = (int) ($counts[$group->getKey()] ?? 0)).' '.str($noun)->plural($n),
        ])->all();
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
