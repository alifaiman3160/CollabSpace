<?php

namespace App\Support\Formation;

use App\Models\Group;
use App\Models\Enrolment;
use App\Models\GroupMember;
use App\Models\Section;
use App\Models\Subject;
use App\Models\User;
use App\Support\Notifier;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The only place groups are created in bulk.
 *
 * Both formation modes end here, so the rules are written once: the same
 * checks, the same record of how the group came about, the same notifications
 * as assigning a student by hand.
 *
 * Everything is re-checked against the database at the moment of writing, not
 * trusted from the preview. A preview can be minutes old, and in that time a
 * student may have been placed by hand, an account deactivated, or a group
 * created with a name the proposal was about to use. If any of that is true,
 * nothing is saved and the lecturer is told what changed. Half a set of
 * groups, with the rest quietly missing, is worse than none.
 */
class GroupFormation
{
    public function __construct(protected Notifier $notifier) {}

    /**
     * @param  list<array{name: string, size: int, member_ids: list<int>, meta: array<string, mixed>}>  $plans
     * @return list<Group>
     *
     * @throws FormationException
     */
    public function create(Subject $subject, User $actor, string $method, array $plans, ?Section $section = null): array
    {
        if ($plans === []) {
            throw new FormationException('There are no groups to create.');
        }

        $batch = (string) Str::uuid();

        $groups = DB::transaction(function () use ($subject, $actor, $method, $plans, $batch, $section) {
            // Serialises two formations running on the same subject at once:
            // the second waits here, then finds the first one's students
            // already placed and refuses cleanly rather than double-booking.
            Subject::query()->whereKey($subject->getKey())->lockForUpdate()->first();

            $sections = $this->guard($subject, $plans, $section);

            $created = [];

            foreach ($plans as $i => $plan) {
                $group = Group::create([
                    'subject_id' => $subject->getKey(),
                    'section_id' => $sections[$i] ?? $section?->getKey(),
                    'name' => trim($plan['name']),
                    'group_size' => $plan['size'],
                    'formation_method' => $method,
                    'formation_meta' => ['batch' => $batch, 'batch_size' => count($plans)] + $plan['meta'],
                    'formed_by' => $actor->getKey(),
                    'formed_at' => now(),
                ]);

                foreach ($plan['member_ids'] as $userId) {
                    GroupMember::create([
                        'group_id' => $group->getKey(),
                        'user_id' => $userId,
                        'joined_at' => now(),
                    ]);
                }

                // The leader: the one the CSV marked, otherwise the member with the highest CGPA.
                isset($plan['leader_id']) && $plan['leader_id']
                    ? app(\App\Support\GroupLeader\GroupLeader::class)->assignChosen($group, (int) $plan['leader_id'])
                    : app(\App\Support\GroupLeader\GroupLeader::class)->assignDefault($group);

                $created[] = $group;
            }

            return $created;
        });

        $this->announce($groups, $plans, $actor);

        return $groups;
    }

    /**
     * @param  list<array{name: string, size: int, member_ids: list<int>, meta: array}>  $plans
     *
     * @return array<int, ?int>  the section each plan's group belongs to
     *
     * @throws FormationException
     */
    protected function guard(Subject $subject, array $plans, ?Section $section = null): array
    {
        $allIds = array_merge(...array_map(fn (array $p) => $p['member_ids'], $plans));

        if (count($allIds) !== count(array_unique($allIds))) {
            throw new FormationException('The same student appears in more than one group, so nothing was saved.');
        }

        $users = User::query()->whereIn('id', $allIds)->get()->keyBy('id');

        foreach ($allIds as $id) {
            $user = $users->get($id);

            if ($user === null || $user->role !== 'student') {
                throw new FormationException('One of the accounts is no longer a student, so nothing was saved. Preview again.');
            }

            if (! $user->is_active) {
                throw new FormationException("{$user->name} has been deactivated since the preview, so nothing was saved. Preview again.");
            }
        }

        $placed = GroupMember::query()
            ->whereIn('user_id', $allIds)
            ->whereHas('group', fn ($q) => $q->where('subject_id', $subject->getKey()))
            ->with(['user', 'group'])
            ->first();

        if ($placed !== null) {
            throw new FormationException(
                "{$placed->user->name} was placed in {$placed->group->name} since the preview, so nothing was saved. Preview again."
            );
        }

        // Everyone is enrolled in this subject, and every group holds one section only (the chosen one, when one
        // was chosen). Re-checked here, not trusted from the preview or the form.
        $enrolled = Enrolment::query()->where('subject_id', $subject->getKey())->whereIn('user_id', $allIds)->pluck('section_id', 'user_id');
        $sectionNames = Section::query()->where('subject_id', $subject->getKey())->pluck('name', 'id');
        $planSections = [];

        foreach ($plans as $i => $plan) {
            $seen = [];

            foreach ($plan['member_ids'] as $id) {
                $user = $users->get($id);

                if (! $enrolled->has($id)) {
                    throw new FormationException("{$user->name} is not enrolled in {$subject->code}, so nothing was saved.");
                }

                if ($section !== null && (int) $enrolled[$id] !== $section->getKey()) {
                    throw new FormationException("{$user->name} is in section ".($sectionNames[$enrolled[$id]] ?? '?').", not {$section->name}, so nothing was saved.");
                }

                $seen[(int) $enrolled[$id]] = true;
            }

            if (count($seen) > 1) {
                throw new FormationException('"'.trim($plan['name']).'" would mix students of different sections ('.collect(array_keys($seen))->map(fn ($s) => $sectionNames[$s] ?? '?')->implode(', ').'), so nothing was saved.');
            }

            $planSections[$i] = $seen === [] ? $section?->getKey() : array_key_first($seen);
        }

        $existing = Group::query()->where('subject_id', $subject->getKey())->pluck('name')
            ->map(fn (string $n) => mb_strtolower(trim($n)))->all();

        $seen = [];

        foreach ($plans as $plan) {
            $name = trim($plan['name']);
            $key = mb_strtolower($name);

            if ($name === '' || mb_strlen($name) > 100) {
                throw new FormationException('A group needs a name of up to 100 characters, so nothing was saved.');
            }

            if (in_array($key, $existing, true)) {
                throw new FormationException("{$subject->code} already has a group called \"{$name}\", so nothing was saved.");
            }

            if (isset($seen[$key])) {
                throw new FormationException("Two of the new groups are both called \"{$name}\", so nothing was saved.");
            }

            $seen[$key] = true;

            if ($plan['size'] < 2 || $plan['size'] > 10) {
                throw new FormationException("\"{$name}\" has a size limit outside 2 to 10, so nothing was saved.");
            }

            if (count($plan['member_ids']) > $plan['size']) {
                throw new FormationException("\"{$name}\" has more members than its size limit allows, so nothing was saved.");
            }
        }

        return $planSections;
    }

    /**
     * Tells each student, the way assigning them by hand would.
     *
     * After the commit and individually guarded: a notification is only
     * information, and a failure writing one must never undo or hide groups
     * that were created successfully.
     *
     * @param  list<Group>  $groups
     * @param  list<array{member_ids: list<int>}>  $plans
     */
    protected function announce(array $groups, array $plans, User $actor): void
    {
        $students = User::query()
            ->whereIn('id', array_merge(...array_map(fn (array $p) => $p['member_ids'], $plans)))
            ->get()
            ->keyBy('id');

        foreach ($groups as $i => $group) {
            foreach ($plans[$i]['member_ids'] as $userId) {
                try {
                    $this->notifier->addedToGroup($students[$userId], $group, $actor);
                } catch (\Throwable $e) {
                    report($e);
                }
            }
        }
    }
}
