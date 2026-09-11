<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Accounts are created by an administrator, who hands the person a temporary
 * password out of band. It is shown to the admin exactly once, at creation or
 * reset, and never stored in plaintext.
 */
class TemporaryPassword
{
    /**
     * Characters that survive being read off a screen and typed back in:
     * no O/0, I/l/1, or symbols that move around on other keyboard layouts.
     */
    protected const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';

    /** Produces something like "Kx7m-Qp4v-3Rna". */
    public static function generate(int $groups = 3, int $perGroup = 4): string
    {
        return collect(range(1, $groups))
            ->map(fn () => Str::of('')->pipe(
                fn () => collect(range(1, $perGroup))
                    ->map(fn () => self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)])
                    ->implode('')
            ))
            ->implode('-');
    }
}
