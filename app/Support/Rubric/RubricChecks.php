<?php

namespace App\Support\Rubric;

/**
 * Things worth a second look in a rubric, found without stopping anyone saving it.
 */
final class RubricChecks
{
    /**
     * In a "level only" section the weight is the criterion's maximum, so a top
     * level that is worth a different amount means one of the two is probably a
     * typing slip: a student at the top level would be given more or less than
     * the criterion's stated maximum.
     *
     * @param  array<string, mixed>  $criterion  ['weight' => ?numeric, 'levels' => [['marks' => numeric]]]
     */
    public static function topLevelDiffersFromWeight(array $criterion): bool
    {
        $weight = RubricScoring::number($criterion['weight'] ?? null);

        return $weight !== null
            && ($criterion['levels'] ?? []) !== []
            && abs(RubricScoring::topLevel($criterion) - $weight) >= 0.005;
    }

    public static function topLevelMessage(array $criterion): string
    {
        return 'The top level is worth '.RubricScoring::trim(RubricScoring::topLevel($criterion))
            .' but the weight, which is this criterion\'s maximum, is '
            .RubricScoring::trim((float) RubricScoring::number($criterion['weight'])).'. Check which is right.';
    }
}
