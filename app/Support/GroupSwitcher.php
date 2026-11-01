<?php

namespace App\Support;

use App\Models\Group;
use Illuminate\Support\Collection;

/**
 * What the lecturer's group switcher shows: every group of theirs in one order (subject code, then group name, the
 * way a person reads a list), the current one, and the neighbours the previous / next buttons go to. No queries of
 * its own: it works on the groups (with their subject) the page already loaded.
 */
class GroupSwitcher
{
    /** More groups than this and the card gets a search box. */
    public const SEARCH_ABOVE = 6;

    /**
     * @param  Collection<int, Group>  $groups  with ->subject loaded
     * @param  string  $route  route name taking the group, e.g. lecturer.progress.show
     * @return array{sections: Collection, count: int, searchable: bool, current: ?Group, previous: ?string, next: ?string}
     */
    public static function for(Collection $groups, Group $current, string $route): array
    {
        $ordered = $groups
            ->sort(fn (Group $a, Group $b) => strcasecmp($a->subject->code, $b->subject->code)
                ?: $a->subject_id <=> $b->subject_id
                ?: strnatcasecmp($a->name, $b->name)
                ?: $a->id <=> $b->id)
            ->values();

        $at = $ordered->search(fn (Group $group) => $group->is($current));

        $href = fn (?Group $group) => $group ? route($route, $group) : null;

        return [
            'sections' => $ordered->groupBy('subject_id')->map(fn (Collection $rows) => [
                'subject' => $rows->first()->subject,
                'palette' => SubjectPalette::for($rows->first()->subject_id),
                'groups' => $rows,
            ])->values(),
            'count' => $ordered->count(),
            'searchable' => $ordered->count() > self::SEARCH_ABOVE,
            'current' => $current,
            'previous' => $at !== false && $at > 0 ? $href($ordered[$at - 1]) : null,
            'next' => $at !== false && $at < $ordered->count() - 1 ? $href($ordered[$at + 1]) : null,
        ];
    }
}
