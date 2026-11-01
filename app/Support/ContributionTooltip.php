<?php

namespace App\Support;

/** The words of a contribution segment's tooltip, in one place, so the screen-reader label and the tooltip agree. */
class ContributionTooltip
{
    /** The share of the group's points, a whole percent; null when the group has none (there is nothing to share). */
    public static function percent(int $points, int $total): ?int
    {
        return $total > 0 ? (int) round($points / $total * 100) : null;
    }

    /** "Farah Aqilah Roslan, 18 points, 11 percent": what a screen reader says on the segment. */
    public static function label(string $name, int $points, ?int $percent): string
    {
        return $name.', '.$points.' '.str('point')->plural($points)->value().($percent !== null ? ', '.$percent.' percent' : '');
    }

    /** "18 points · 11% of the group": the second line of the tooltip. */
    public static function line(int $points, ?int $percent): string
    {
        return $points.' '.str('point')->plural($points)->value().($percent !== null ? ' · '.$percent.'% of the group' : '');
    }

    public static function initials(string $name): string
    {
        return mb_strtoupper(collect(preg_split('/\s+/', trim($name), -1, PREG_SPLIT_NO_EMPTY))->take(2)->map(fn ($part) => mb_substr($part, 0, 1))->implode('')) ?: '?';
    }
}