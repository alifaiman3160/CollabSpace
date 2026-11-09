<?php

namespace App\Support\GroupLeader;

use App\Models\Group;
use App\Models\User;
use App\Support\ActivityRecorder;
use App\Support\Notifier;
use Illuminate\Support\Collection;

/**
 * The one place a group's leader is chosen or changed. A group has exactly one leader, always a current member; the
 * lecturer who owns the subject is the only person who changes it, and it is never worth points or marks.
 *
 * The default is the member with the highest CGPA, a tie (or a missing CGPA) going to the lowest user id.
 */
class GroupLeader
{
    public function __construct(protected ActivityRecorder $recorder, protected Notifier $notifier) {}

    /** The member who would lead by default, or null for a group with no members. */
    public function defaultFor(Group $group, ?int $except = null): ?User
    {
        return $this->rank($this->members($group)->reject(fn (User $u) => $u->getKey() === $except))->first();
    }

    /** Sets the default leader without telling anyone (a group being formed). Returns who it is. */
    public function assignDefault(Group $group): ?User
    {
        $leader = $this->defaultFor($group);

        $group->forceFill(['leader_id' => $leader?->getKey()])->save();

        return $leader;
    }

    /** Sets a chosen leader at formation (from a CSV), silently. Falls back to the default when not a member. */
    public function assignChosen(Group $group, int $userId): ?User
    {
        $member = $this->members($group)->firstWhere('id', $userId);

        if ($member === null) {
            return $this->assignDefault($group);
        }

        $group->forceFill(['leader_id' => $member->getKey()])->save();

        return $member;
    }

    /**
     * Makes this member the leader. Refused for someone who is not a member. Tells the new leader and the others, and
     * writes a leader_changed line (worth nothing) to the activity log.
     *
     * @throws \InvalidArgumentException when the user is not a member of the group
     */
    public function setLeader(Group $group, User $user, ?User $by = null): Group
    {
        if (! $group->groupMembers()->where('user_id', $user->getKey())->exists()) {
            throw new \InvalidArgumentException('The leader must be a member of the group.');
        }

        if ($group->leader_id === $user->getKey()) {
            return $group;
        }

        $group->forceFill(['leader_id' => $user->getKey()])->save();

        $this->recorder->record($by ?? $user, $group, 'leader_changed', $user->getKey());
        $this->notifier->leaderChanged($group, $user);

        return $group;
    }

    /**
     * A student joined the group: when it has no leader, this first member becomes it. Silent (they are told they were
     * added to the group, which is the news; nobody was displaced), so it is not a leader change.
     */
    public function onMemberAdded(Group $group, User $user): void
    {
        $group->refresh();

        if ($group->leader_id === null) {
            $group->forceFill(['leader_id' => $user->getKey()])->save();
        }
    }

    /**
     * A student left the group (already removed): when they were the leader, the remaining member with the highest CGPA
     * takes over, or nobody when no member remains.
     */
    public function onMemberRemoved(Group $group, User $removed, ?User $by = null): void
    {
        $group->refresh();

        if ($group->leader_id !== null && $group->leader_id !== $removed->getKey()) {
            return;
        }

        $next = $this->defaultFor($group, $removed->getKey());

        $group->forceFill(['leader_id' => null])->save();

        if ($next !== null) {
            $this->setLeader($group, $next, $by);
        }
    }

    /** @return Collection<int, User> */
    protected function members(Group $group): Collection
    {
        return User::query()->whereIn('id', $group->groupMembers()->select('user_id'))->get();
    }

    /** Highest CGPA first; a missing CGPA last; ties by the lowest id. */
    protected function rank(Collection $users): Collection
    {
        return $users->sort(function (User $a, User $b) {
            $ca = $a->cgpa === null ? -1.0 : (float) $a->cgpa;
            $cb = $b->cgpa === null ? -1.0 : (float) $b->cgpa;

            return $cb <=> $ca ?: $a->getKey() <=> $b->getKey();
        })->values();
    }
}
