<?php

namespace Tests\Unit;

use App\Support\Rubric\RubricScoring;
use PHPUnit\Framework\TestCase;

class RubricScoringTest extends TestCase
{
    /** CLO2: seven weights summing to 6.0, top level 5 (the "Excellent (4–5)" upper bound). */
    private function clo2(): array
    {
        return array_map(
            fn ($w) => ['weight' => $w, 'levels' => [['marks' => 1], ['marks' => 2], ['marks' => 3], ['marks' => 5]]],
            ['0.4', '1', '1', '1.6', '0.8', '0.6', '0.6'],
        );
    }

    /** CLO4: five criteria, weight 2, top level 2.0. */
    private function clo4(): array
    {
        return array_map(
            fn () => ['weight' => '2', 'levels' => [['marks' => 0.5], ['marks' => 1.0], ['marks' => 1.5], ['marks' => 2.0]]],
            range(1, 5),
        );
    }

    public function test_clo2_reaches_30_with_level_times_weight(): void
    {
        $maxima = RubricScoring::maxima($this->clo2());

        $this->assertSame(30.0, $maxima[RubricScoring::LEVEL_X_WEIGHT]);
        $this->assertSame(35.0, $maxima[RubricScoring::LEVEL_ONLY]);
        $this->assertSame(RubricScoring::LEVEL_X_WEIGHT, RubricScoring::preselect(30.0, $maxima));
    }

    public function test_clo4_reaches_10_with_level_only_and_would_say_20_if_multiplied(): void
    {
        $maxima = RubricScoring::maxima($this->clo4());

        $this->assertSame(10.0, $maxima[RubricScoring::LEVEL_ONLY]);
        $this->assertSame(20.0, $maxima[RubricScoring::LEVEL_X_WEIGHT]);
        $this->assertSame(RubricScoring::LEVEL_ONLY, RubricScoring::preselect(10.0, $maxima));
    }

    public function test_nothing_is_preselected_without_a_declared_total(): void
    {
        $this->assertNull(RubricScoring::preselect(null, RubricScoring::maxima($this->clo4())));
    }

    public function test_nothing_is_preselected_when_neither_formula_matches(): void
    {
        $this->assertNull(RubricScoring::preselect(25.0, RubricScoring::maxima($this->clo2())));
    }

    public function test_nothing_is_preselected_when_both_formulas_match(): void
    {
        // Weight 1 and a top level of 2: both give 2 per criterion.
        $criteria = [['weight' => '1', 'levels' => [['marks' => 1], ['marks' => 2]]]];

        $maxima = RubricScoring::maxima($criteria);

        $this->assertSame(2.0, $maxima[RubricScoring::LEVEL_X_WEIGHT]);
        $this->assertSame(2.0, $maxima[RubricScoring::LEVEL_ONLY]);
        $this->assertNull(RubricScoring::preselect(2.0, $maxima));
    }

    public function test_a_missing_weight_makes_level_times_weight_impossible_not_zero(): void
    {
        $criteria = [['weight' => null, 'levels' => [['marks' => 5]]], ['weight' => '2', 'levels' => [['marks' => 5]]]];

        $maxima = RubricScoring::maxima($criteria);

        $this->assertNull($maxima[RubricScoring::LEVEL_X_WEIGHT]);
        $this->assertSame(10.0, $maxima[RubricScoring::LEVEL_ONLY]);
        $this->assertNull(RubricScoring::preselect(0.0, $maxima), 'A null maximum must never equal a total.');
    }

    public function test_floating_point_noise_does_not_break_a_match(): void
    {
        // 0.4 + 1 + 1 + 1.6 + 0.8 + 0.6 + 0.6 is not exactly 6.0 in binary.
        $this->assertSame(RubricScoring::LEVEL_X_WEIGHT, RubricScoring::preselect(30.0, RubricScoring::maxima($this->clo2())));
    }

    public function test_marks_for_a_level_under_each_formula(): void
    {
        $this->assertSame(8.0, RubricScoring::marks(RubricScoring::LEVEL_X_WEIGHT, '1.6', 5));
        $this->assertSame(2.0, RubricScoring::marks(RubricScoring::LEVEL_ONLY, '2', 2.0));
        $this->assertSame(1.5, RubricScoring::marks(RubricScoring::LEVEL_ONLY, '2', 1.5), 'The weight is ignored by level only.');
        $this->assertSame(1.5, RubricScoring::marks(RubricScoring::LEVEL_ONLY, null, 1.5), 'And need not exist.');
    }

    public function test_multiplying_without_a_weight_is_an_error_not_a_zero(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        RubricScoring::marks(RubricScoring::LEVEL_X_WEIGHT, null, 5);
    }

    public function test_an_unknown_formula_is_an_error(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        RubricScoring::marks('average', '1', 5);
    }

    public function test_every_criterion_at_its_top_level_totals_the_section_maximum(): void
    {
        $clo2 = array_sum(array_map(fn ($c) => RubricScoring::marks(RubricScoring::LEVEL_X_WEIGHT, $c['weight'], RubricScoring::topLevel($c)), $this->clo2()));
        $clo4 = array_sum(array_map(fn ($c) => RubricScoring::marks(RubricScoring::LEVEL_ONLY, $c['weight'], RubricScoring::topLevel($c)), $this->clo4()));
        $clo4Wrong = array_sum(array_map(fn ($c) => RubricScoring::marks(RubricScoring::LEVEL_X_WEIGHT, $c['weight'], RubricScoring::topLevel($c)), $this->clo4()));

        $this->assertEqualsWithDelta(30.0, $clo2, 0.001);
        $this->assertEqualsWithDelta(10.0, $clo4, 0.001);
        $this->assertEqualsWithDelta(20.0, $clo4Wrong, 0.001, 'The mistake this amendment exists to prevent.');
    }

    public function test_a_total_is_always_explained(): void
    {
        $clo2 = RubricScoring::maxima($this->clo2());
        $clo4 = RubricScoring::maxima($this->clo4());

        $this->assertSame('marks = level marks × weight; maximum 30; declared total 30', RubricScoring::describe(RubricScoring::LEVEL_X_WEIGHT, 30.0, $clo2));
        $this->assertSame('marks = level marks (the weight is the maximum); maximum 10; declared total 10', RubricScoring::describe(RubricScoring::LEVEL_ONLY, 10.0, $clo4));
        $this->assertSame('scoring formula not chosen; declared total 10', RubricScoring::describe(null, 10.0, $clo4));
        $this->assertSame('marks = level marks (the weight is the maximum); maximum 10', RubricScoring::describe(RubricScoring::LEVEL_ONLY, null, $clo4));
    }

    public function test_problems_are_described_in_plain_words(): void
    {
        $clo2 = RubricScoring::maxima($this->clo2());

        $this->assertNull(RubricScoring::problem(RubricScoring::LEVEL_X_WEIGHT, 30.0, $clo2, 'CLO2'));
        $this->assertStringContainsString('maximum is 35 but the declared total is 30', RubricScoring::problem(RubricScoring::LEVEL_ONLY, 30.0, $clo2, 'CLO2'));
        $this->assertStringContainsString('does not state a total', RubricScoring::problem(null, null, $clo2, 'CLO2'));
        $this->assertStringContainsString('Neither or both formulas give the declared total of 25', RubricScoring::problem(null, 25.0, $clo2, 'CLO2'));
        $this->assertStringContainsString('without a weight', RubricScoring::problem(RubricScoring::LEVEL_X_WEIGHT, null, ['level_x_weight' => null, 'level_only' => 5.0], 'CLO2'));
    }
}
