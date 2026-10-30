<?php

namespace App\Support\Rubric;

/**
 * How a section turns a chosen level into marks.
 *
 * The weight column of a rubric table does not mean the same thing in every
 * table, so there is no one formula:
 *
 *   level_x_weight  marks = level marks × weight
 *                   The weights scale the levels. CLO2: weights 0.4, 1, 1, 1.6,
 *                   0.8, 0.6, 0.6 sum to 6.0 and the top level is 5, so the most
 *                   a student can earn is 6.0 × 5 = 30.
 *
 *   level_only      marks = level marks
 *                   The weight is the criterion's maximum and the levels already
 *                   run up to it. CLO4: five criteria, weight 2, top level 2.0,
 *                   so the most is 5 × 2.0 = 10. Multiplying would say 20.
 *
 * Which applies is decided per section, by the lecturer, preselected only when
 * exactly one formula's maximum equals the total the section declares. All
 * arithmetic lives here and is done in code; nothing else computes marks.
 *
 * Criteria are passed as plain arrays: ['weight' => ?numeric, 'levels' =>
 * [['marks' => numeric], ...]] so the same code serves a parsed draft, a posted
 * form and saved models alike.
 */
final class RubricScoring
{
    public const LEVEL_X_WEIGHT = 'level_x_weight';

    public const LEVEL_ONLY = 'level_only';

    public const FORMULAS = [self::LEVEL_X_WEIGHT, self::LEVEL_ONLY];

    /** Two decimals is how marks are kept; a difference under half a hundredth is the same total. */
    private const TOLERANCE = 0.005;

    public static function label(?string $formula): string
    {
        return match ($formula) {
            self::LEVEL_X_WEIGHT => 'Level × weight',
            self::LEVEL_ONLY => 'Level only (the weight is the maximum)',
            default => 'Not chosen yet',
        };
    }

    /** The highest marks any level of the criterion carries. */
    public static function topLevel(array $criterion): float
    {
        $marks = array_map(fn ($level) => (float) $level['marks'], $criterion['levels'] ?? []);

        return $marks === [] ? 0.0 : max($marks);
    }

    /**
     * The most a student could earn in a section under each formula.
     *
     * level_x_weight is null when any criterion has no weight, because it
     * cannot be worked out, and a null never equals a declared total.
     *
     * @param  list<array<string, mixed>>  $criteria
     * @return array{level_x_weight: ?float, level_only: float}
     */
    public static function maxima(array $criteria): array
    {
        $weighted = 0.0;
        $weightsKnown = $criteria !== [];
        $only = 0.0;

        foreach ($criteria as $criterion) {
            $top = self::topLevel($criterion);
            $only += $top;

            if (self::number($criterion['weight'] ?? null) === null) {
                $weightsKnown = false;
            } else {
                $weighted += $top * self::number($criterion['weight']);
            }
        }

        return [
            self::LEVEL_X_WEIGHT => $weightsKnown ? round($weighted, 2) : null,
            self::LEVEL_ONLY => round($only, 2),
        ];
    }

    /**
     * The formula to preselect, or null when the lecturer has to choose.
     *
     * Only an exact, unambiguous match counts: the declared total must equal one
     * formula's maximum and not the other's. Both matching, neither matching and
     * no declared total all leave it unchosen, because guessing a formula
     * silently changes every mark in the section.
     *
     * @param  array{level_x_weight: ?float, level_only: float}  $maxima
     */
    public static function preselect(?float $declared, array $maxima): ?string
    {
        if ($declared === null) {
            return null;
        }

        $weighted = self::equal($maxima[self::LEVEL_X_WEIGHT], $declared);
        $only = self::equal($maxima[self::LEVEL_ONLY], $declared);

        return match (true) {
            $weighted && ! $only => self::LEVEL_X_WEIGHT,
            $only && ! $weighted => self::LEVEL_ONLY,
            default => null,
        };
    }

    /** @param  array{level_x_weight: ?float, level_only: float}  $maxima */
    public static function maximum(?string $formula, array $maxima): ?float
    {
        return $formula === null ? null : ($maxima[$formula] ?? null);
    }

    /** Why a section is or is not resolved, in words. Null when it is fine. */
    public static function problem(?string $formula, ?float $declared, array $maxima, string $title): ?string
    {
        if ($formula === null) {
            return $declared === null
                ? "“{$title}” does not state a total, so choose how its marks are calculated."
                : 'Neither or both formulas give the declared total of '.self::trim($declared).". Choose how “{$title}” is calculated.";
        }

        $max = self::maximum($formula, $maxima);

        if ($max === null) {
            return "“{$title}” has a criterion without a weight, so ".mb_strtolower(self::label($formula)).' cannot be worked out.';
        }

        if ($declared !== null && ! self::equal($max, $declared)) {
            return "The maximum is ".self::trim($max)." but the declared total is ".self::trim($declared).'.';
        }

        return null;
    }

    /**
     * Marks for one criterion at one level. Rounded to two decimals, once, here.
     *
     * @throws \InvalidArgumentException  when level_x_weight is asked for without a weight
     */
    public static function marks(string $formula, mixed $weight, mixed $levelMarks): float
    {
        $level = (float) $levelMarks;

        return match ($formula) {
            self::LEVEL_ONLY => round($level, 2),
            self::LEVEL_X_WEIGHT => self::number($weight) === null
                ? throw new \InvalidArgumentException('A weight is needed to multiply by.')
                : round($level * self::number($weight), 2),
            default => throw new \InvalidArgumentException("Unknown scoring formula “{$formula}”."),
        };
    }

    /**
     * The sentence that explains a total, for the screen and the export, so a
     * number is never left without how it was reached.
     *
     * @param  array{level_x_weight: ?float, level_only: float}  $maxima
     */
    public static function describe(?string $formula, ?float $declared, array $maxima): string
    {
        $max = self::maximum($formula, $maxima);

        $how = match ($formula) {
            self::LEVEL_X_WEIGHT => 'marks = level marks × weight',
            self::LEVEL_ONLY => 'marks = level marks (the weight is the maximum)',
            default => 'scoring formula not chosen',
        };

        return $how
            .($max !== null ? '; maximum '.self::trim($max) : '')
            .($declared !== null ? '; declared total '.self::trim($declared) : '');
    }

    public static function number(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        $value = is_string($value) ? str_replace(',', '.', trim($value)) : $value;

        return is_numeric($value) ? (float) $value : null;
    }

    public static function trim(float $n): string
    {
        return rtrim(rtrim(number_format($n, 2, '.', ''), '0'), '.');
    }

    private static function equal(?float $a, float $b): bool
    {
        return $a !== null && abs($a - $b) < self::TOLERANCE;
    }
}
