<?php

namespace App\Support;

use Illuminate\Support\Collection;

/**
 * The order in which groups need a lecturer: most overdue tasks first, then the lowest progress (a group with no
 * tasks counts as no progress), then by name. Shared by the dashboard and Monitor progress.
 */
class GroupNeed
{
    /**
     * @param  Collection<int, \App\Models\Group>  $groups  with tasks_overdue, tasks_done and tasks_total counts
     * @return Collection<int, \App\Models\Group>
     */
    public static function sort(Collection $groups): Collection
    {
        return $groups
            ->sortBy([
                fn ($a, $b) => $b->tasks_overdue <=> $a->tasks_overdue,
                fn ($a, $b) => self::progress($a) <=> self::progress($b),
                fn ($a, $b) => strcmp($a->name, $b->name),
            ])
            ->values();
    }

    public static function progress($group): float
    {
        return $group->tasks_total > 0 ? $group->tasks_done / $group->tasks_total : 0.0;
    }
}