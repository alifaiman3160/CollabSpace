<?php

namespace App\Support\Marking;

use App\Models\MarkSuggestion;
use App\Models\RubricSection;
use Illuminate\Support\Collection;

/**
 * What a student's section total would be if the suggestions were taken as they
 * stand, and whether that total is finished.
 *
 * A criterion with no suggested level (the lecturer's own, one with no evidence,
 * or one the group has recorded too little for) is undecided, which is not the
 * same as scoring nothing. So a section with any undecided criterion has no
 * total yet: marks-so-far are reported, but `total` stays null until every
 * criterion has a level, and nobody can mistake a gap for a zero.
 *
 * Marks come only from RubricSection::marksFor, which goes through RubricScoring.
 */
class SectionTotals
{
    /**
     * @param  Collection<int, MarkSuggestion>  $suggestions  one student's, in any order
     * @return array{
     *     criteria: int,
     *     decided: int,
     *     undecided: int,
     *     so_far: float,
     *     total: ?float,
     *     maximum: ?float,
     *     complete: bool,
     *     formula_chosen: bool,
     * }
     */
    public function for(RubricSection $section, Collection $suggestions): array
    {
        $section->loadMissing('criteria.levels');

        $byCriterion = $suggestions->keyBy('rubric_criterion_id');
        $formulaChosen = $section->scoring !== null;

        $decided = 0;
        $soFar = 0.0;

        foreach ($section->criteria as $criterion) {
            $levelId = $byCriterion->get($criterion->id)?->suggestedLevelId();
            $level = $levelId === null ? null : $criterion->levels->firstWhere('id', $levelId);

            if ($level === null || ! $formulaChosen) {
                continue;
            }

            $decided++;
            $soFar += $section->marksFor($criterion, $level);
        }

        $count = $section->criteria->count();
        $complete = $count > 0 && $decided === $count;

        return [
            'criteria' => $count,
            'decided' => $decided,
            'undecided' => $count - $decided,
            'so_far' => round($soFar, 2),
            'total' => $complete ? round($soFar, 2) : null,
            'maximum' => $section->maximumMarks(),
            'complete' => $complete,
            'formula_chosen' => $formulaChosen,
        ];
    }
}
