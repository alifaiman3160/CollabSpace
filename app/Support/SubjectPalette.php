<?php

namespace App\Support;

/**
 * The six soft colours a subject card can wear. A subject always gets the same
 * one (its id modulo six), so every student sees a subject in the same colour.
 */
final class SubjectPalette
{
    /** @var list<array{name: string, bg: string, accent: string, border: string}> */
    public const COLOURS = [
        ['name' => 'periwinkle', 'bg' => '#eef1fd', 'accent' => '#8fa4f0', 'border' => '#d5ddfa'],
        ['name' => 'mint', 'bg' => '#e8f5ee', 'accent' => '#6bbf98', 'border' => '#cfe9db'],
        ['name' => 'peach', 'bg' => '#fdf0e6', 'accent' => '#f0a56b', 'border' => '#f7dcc4'],
        ['name' => 'lavender', 'bg' => '#f1ecfb', 'accent' => '#a58be0', 'border' => '#e0d6f5'],
        ['name' => 'sky', 'bg' => '#e8f3fa', 'accent' => '#6bb5dc', 'border' => '#cfe6f3'],
        ['name' => 'butter', 'bg' => '#fbf5dc', 'accent' => '#e0c04f', 'border' => '#f1e6b4'],
    ];

    /** @return array{name: string, bg: string, accent: string, border: string} */
    public static function for(int $subjectId): array
    {
        return self::COLOURS[$subjectId % count(self::COLOURS)];
    }
}
