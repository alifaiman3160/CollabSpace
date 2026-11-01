<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * What the sidebar remembers in the browser, read on the server so the first paint is already right: whether it is
 * collapsed, and which of its sections are open. The script (resources/js/sidebar.js) writes the same two cookies.
 */
final class SidebarState
{
    public const COLLAPSE_COOKIE = 'cs_sidebar';

    public const OPEN_COOKIE = 'cs_nav_open';

    /** True or false when the person has chosen, null when they never have (then the screen's width decides). */
    public static function collapsed(Request $request): ?bool
    {
        return match ($request->cookie(self::COLLAPSE_COOKIE)) {
            'collapsed' => true,
            'expanded' => false,
            default => null,
        };
    }

    /** @return list<string> the keys of the sections left open */
    public static function open(Request $request): array
    {
        $value = $request->cookie(self::OPEN_COOKIE);

        if (! is_string($value) || $value === '') {
            return [];
        }

        return array_values(array_unique(array_filter(explode(',', $value), fn ($key) => preg_match('/^[a-z]{1,20}$/', $key) === 1)));
    }

    /**
     * The sub-item whose page the visitor is on (the longest address that is the path or a parent of it), or null.
     * The same rule is in resources/js/sidebar-logic.js, which keeps the highlight right across wire:navigate.
     *
     * @param  list<array{match?: ?string, exact?: bool}>  $children
     */
    public static function activeChild(string $path, array $children): ?int
    {
        $path = '/'.trim($path, '/');
        $best = null;
        $length = -1;

        foreach ($children as $index => $child) {
            $match = $child['match'] ?? null;

            if ($match === null) {
                continue;
            }

            $match = '/'.trim($match, '/');
            $hit = $path === $match || (empty($child['exact']) && str_starts_with($path, $match.'/'));

            if ($hit && strlen($match) > $length) {
                $best = $index;
                $length = strlen($match);
            }
        }

        return $best;
    }
}
