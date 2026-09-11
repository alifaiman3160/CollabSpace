<?php

namespace App\Support;

/**
 * Assigns each person a chat colour, WhatsApp-style.
 *
 * Derived from the user id, so the same person is the same colour in every
 * session, on every screen and for every viewer — a colour that shuffled
 * between page loads would be worse than no colour at all.
 *
 * The class strings below are written out in full on purpose. Tailwind scans
 * source files for literal class names, so a built string like
 * "text-member-{$i}" would be purged from the stylesheet and silently render
 * unstyled. tailwind.config.js lists app/Support so this file is scanned.
 *
 * Contrast: every `name` value clears WCAG AA against the thread surface, the
 * bubble surface and its own avatar tint. See the palette comment in
 * tailwind.config.js.
 */
class MemberColour
{
    /**
     * @var list<array{name: string, avatar: string}>
     */
    protected const PALETTE = [
        ['name' => 'text-member-1', 'avatar' => 'bg-member-1bg text-member-1'],
        ['name' => 'text-member-2', 'avatar' => 'bg-member-2bg text-member-2'],
        ['name' => 'text-member-3', 'avatar' => 'bg-member-3bg text-member-3'],
        ['name' => 'text-member-4', 'avatar' => 'bg-member-4bg text-member-4'],
        ['name' => 'text-member-5', 'avatar' => 'bg-member-5bg text-member-5'],
        ['name' => 'text-member-6', 'avatar' => 'bg-member-6bg text-member-6'],
        ['name' => 'text-member-7', 'avatar' => 'bg-member-7bg text-member-7'],
        ['name' => 'text-member-8', 'avatar' => 'bg-member-8bg text-member-8'],
    ];

    /** @return array{name: string, avatar: string} */
    public static function for(int $userId): array
    {
        return self::PALETTE[$userId % count(self::PALETTE)];
    }

    /** The colour for a sender's display name. */
    public static function name(int $userId): string
    {
        return self::for($userId)['name'];
    }

    /** Background + foreground for a sender's avatar. */
    public static function avatar(int $userId): string
    {
        return self::for($userId)['avatar'];
    }

    public static function count(): int
    {
        return count(self::PALETTE);
    }
}
