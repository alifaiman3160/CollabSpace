<?php

namespace Tests\Unit;

use App\Support\Formation\BalancedAllocator;
use App\Support\Formation\SnakeDraft;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * The snake draft, in isolation.
 *
 * No database and no framework: the class takes a list and returns a list, and
 * that is what lets the distribution be pinned down exactly. The guarantees
 * that matter are that nobody is lost or duplicated, that group sizes stay
 * within one of each other, that the reversal does what it is for, and that the
 * awkward headcounts, the ones that do not divide evenly, come out balanced
 * rather than lopsided.
 */
class SnakeDraftTest extends TestCase
{
    protected SnakeDraft $draft;

    protected function setUp(): void
    {
        parent::setUp();

        $this->draft = new SnakeDraft();
    }

    /**
     * Students numbered strongest first, so id 1 is the best and a group's
     * contents read as "which ranks did it get".
     *
     * @param  list<float|null>  $cgpas
     * @return list<array{id: int, cgpa: ?float}>
     */
    protected function cohort(array $cgpas): array
    {
        $students = [];

        foreach ($cgpas as $i => $cgpa) {
            $students[] = ['id' => $i + 1, 'cgpa' => $cgpa];
        }

        return $students;
    }

    /** @return list<float> */
    protected function descending(int $n, float $top = 3.95, float $step = 0.13): array
    {
        return array_map(fn (int $i) => round($top - $i * $step, 2), range(0, $n - 1));
    }

    /** @return list<list<int>> */
    protected function ids(array $groups): array
    {
        return array_map(fn (array $members) => array_column($members, 'id'), $groups);
    }

    protected function spread(array $groups): float
    {
        $averages = array_map(fn ($g) => SnakeDraft::summarise($g)['average'], $groups);

        return round(max($averages) - min($averages), 2);
    }

    /*
    | The snake itself
    */

    public function test_a_round_is_dealt_forwards_and_the_next_comes_back(): void
    {
        $groups = $this->draft->deal($this->cohort($this->descending(8)), 4);

        // Round one: 1 2 3 4 into groups 1 to 4. Round two: 5 6 7 8 back the
        // other way, into groups 4 to 1.
        $this->assertSame([[1, 8], [2, 7], [3, 6], [4, 5]], $this->ids($groups));
    }

    public function test_the_direction_reverses_every_round_not_just_once(): void
    {
        $groups = $this->draft->deal($this->cohort($this->descending(12)), 4);

        // Round three runs forwards again.
        $this->assertSame([[1, 8, 9], [2, 7, 10], [3, 6, 11], [4, 5, 12]], $this->ids($groups));
    }

    public function test_the_reversal_is_what_keeps_the_averages_close(): void
    {
        $students = $this->cohort($this->descending(12));

        $snake = $this->draft->deal($students, 4);

        // Dealing the same way every round: the best student of every tier to
        // group one, the worst to group four.
        $naive = array_fill(0, 4, []);
        foreach ($students as $i => $s) {
            $naive[$i % 4][] = $s;
        }

        $this->assertLessThan($this->spread($naive), $this->spread($snake));
        $this->assertLessThanOrEqual(0.15, $this->spread($snake));
    }

    public function test_input_order_does_not_matter(): void
    {
        $students = $this->cohort($this->descending(12));
        $shuffled = $students;
        mt_srand(3);
        shuffle($shuffled);

        $this->assertSame(
            $this->ids($this->draft->deal($students, 4)),
            $this->ids($this->draft->deal($shuffled, 4)),
        );
    }

    /*
    | Headcounts that do not divide evenly
    */

    public function test_nobody_is_lost_or_duplicated_at_any_headcount(): void
    {
        foreach (range(4, 31) as $n) {
            foreach (range(2, intdiv($n, 2)) as $k) {
                $students = $this->cohort($this->descending($n, 3.95, 0.07));
                $dealt = $this->draft->deal($students, $k);

                $placed = array_merge(...$this->ids($dealt));
                sort($placed);

                $this->assertSame(range(1, $n), $placed, "{$n} students into {$k} groups");
            }
        }
    }

    public function test_group_sizes_never_differ_by_more_than_one(): void
    {
        foreach (range(4, 31) as $n) {
            foreach (range(2, intdiv($n, 2)) as $k) {
                $sizes = array_map('count', $this->draft->deal($this->cohort($this->descending($n, 3.95, 0.07)), $k));

                $this->assertLessThanOrEqual(1, max($sizes) - min($sizes), "{$n} students into {$k} groups: ".implode(',', $sizes));
            }
        }
    }

    public function test_thirteen_into_four_is_four_three_three_three(): void
    {
        $sizes = array_map('count', $this->draft->deal($this->cohort($this->descending(13)), 4));

        sort($sizes);

        $this->assertSame([3, 3, 3, 4], $sizes);
    }

    public function test_the_leftover_student_goes_to_the_group_with_the_highest_average(): void
    {
        // Thirteen into four leaves the weakest student over. A plain snake
        // gave them to the last group, whose average collapsed. Here they go to
        // the group that currently averages highest, which can best absorb them.
        $students = $this->cohort([3.95, 3.88, 3.75, 3.60, 3.42, 3.30, 3.15, 3.02, 2.90, 2.74, 2.55, 2.40, 2.10]);

        $groups = $this->draft->deal($students, 4);

        $this->assertContains(13, array_column($groups[0], 'id'), 'The weakest student should join the strongest group.');
    }

    public function test_handling_the_leftover_this_way_beats_a_plain_snake(): void
    {
        $students = $this->cohort([3.95, 3.88, 3.75, 3.60, 3.42, 3.30, 3.15, 3.02, 2.90, 2.74, 2.55, 2.40, 2.10]);

        $plain = array_fill(0, 4, []);
        foreach ($students as $i => $s) {
            $round = intdiv($i, 4);
            $slot = $i % 4;
            $plain[$round % 2 === 0 ? $slot : 3 - $slot][] = $s;
        }

        $this->assertLessThan($this->spread($plain), $this->spread($this->draft->deal($students, 4)));
    }

    public function test_a_single_group_takes_everyone(): void
    {
        $groups = $this->draft->deal($this->cohort($this->descending(5)), 1);

        $this->assertCount(1, $groups);
        $this->assertCount(5, $groups[0]);
    }

    public function test_at_least_one_group_is_required(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->draft->deal($this->cohort($this->descending(4)), 0);
    }

    /*
    | Students with no CGPA
    */

    public function test_a_student_with_no_cgpa_is_still_placed(): void
    {
        $students = $this->cohort([3.9, null, 3.5, 3.2, null, 2.8, 2.5, 2.2]);

        $placed = array_merge(...$this->ids($this->draft->deal($students, 4)));
        sort($placed);

        $this->assertSame(range(1, 8), $placed);
    }

    public function test_an_unknown_cgpa_is_ranked_as_average_not_as_last(): void
    {
        $order = $this->draft->order($this->cohort([3.9, null, 2.1]));

        // Placed between the two, not tipped to either end.
        $this->assertSame([1, 2, 3], array_column($order, 'id'));
    }

    public function test_a_stand_in_cgpa_never_leaks_into_a_groups_average(): void
    {
        $summary = SnakeDraft::summarise([
            ['cgpa' => 3.0],
            ['cgpa' => null],
            ['cgpa' => 4.0],
        ]);

        // Averaged over the two that are recorded, with the gap counted.
        $this->assertSame(3.5, $summary['average']);
        $this->assertSame(2, $summary['known']);
        $this->assertSame(1, $summary['unknown']);
    }

    public function test_a_group_of_unknowns_has_no_average_rather_than_a_zero(): void
    {
        $summary = SnakeDraft::summarise([['cgpa' => null], ['cgpa' => null]]);

        $this->assertNull($summary['average']);
    }

    public function test_an_all_unknown_cohort_is_still_dealt(): void
    {
        $groups = $this->draft->deal($this->cohort([null, null, null, null, null, null]), 3);

        $this->assertSame([2, 2, 2], array_map('count', $groups));
    }

    /*
    | Regeneration
    */

    public function test_the_same_seed_always_gives_the_same_groups(): void
    {
        $students = $this->cohort($this->descending(14, 3.9, 0.05));

        $this->assertSame(
            $this->ids($this->draft->deal($students, 4, 11)),
            $this->ids($this->draft->deal($students, 4, 11)),
        );
    }

    public function test_different_seeds_give_different_groups(): void
    {
        // Regenerate has to do something. A snake draft is otherwise
        // deterministic, and a button that returns the same answer every time
        // looks broken.
        $students = $this->cohort($this->descending(16, 3.9, 0.04));

        $seen = [];
        foreach (range(1, 25) as $seed) {
            $seen[json_encode($this->ids($this->draft->deal($students, 4, $seed)))] = true;
        }

        $this->assertGreaterThan(5, count($seen));
    }

    public function test_regenerating_never_swaps_students_who_are_meaningfully_different(): void
    {
        $students = $this->cohort($this->descending(16, 3.9, 0.04));

        foreach (range(1, 25) as $seed) {
            $order = $this->draft->order($students, $seed);

            foreach ($order as $i => $a) {
                foreach (array_slice($order, $i + 1) as $b) {
                    // Anyone later in the queue is at most a tolerance better.
                    $this->assertLessThanOrEqual(
                        SnakeDraft::TIE_TOLERANCE + 0.0001,
                        $b['cgpa'] - $a['cgpa'],
                        "seed {$seed}: student {$b['id']} was ranked behind {$a['id']}",
                    );
                }
            }
        }
    }

    public function test_seed_zero_is_the_plain_canonical_snake(): void
    {
        $this->assertSame(
            [[1, 8], [2, 7], [3, 6], [4, 5]],
            $this->ids($this->draft->deal($this->cohort($this->descending(8)), 4, 0)),
        );
    }

    /*
    | How many groups are possible
    */

    public function test_the_range_of_group_counts(): void
    {
        // Thirteen students, limit four: at least four groups, at most six
        // (every group still needs two).
        $this->assertSame(['min' => 4, 'max' => 6], BalancedAllocator::bounds(13, 4));
        $this->assertSame(['min' => 3, 'max' => 4], BalancedAllocator::bounds(9, 4));
    }

    public function test_fewer_than_two_students_cannot_form_a_group(): void
    {
        $this->assertSame(['min' => 0, 'max' => 0], BalancedAllocator::bounds(1, 4));
        $this->assertSame(['min' => 0, 'max' => 0], BalancedAllocator::bounds(0, 4));
    }

    public function test_the_upper_bound_gives_way_where_it_would_contradict_the_lower(): void
    {
        // Three students against a limit of two cannot make groups of two.
        $this->assertSame(['min' => 2, 'max' => 2], BalancedAllocator::bounds(3, 2));
    }

    public function test_the_minimum_always_respects_the_size_limit(): void
    {
        foreach (range(2, 40) as $n) {
            foreach (range(2, 10) as $limit) {
                $min = BalancedAllocator::bounds($n, $limit)['min'];
                $sizes = array_map('count', $this->draft->deal($this->cohort($this->descending($n, 3.9, 0.02)), $min));

                $this->assertLessThanOrEqual($limit, max($sizes), "{$n} students, limit {$limit}, {$min} groups");
            }
        }
    }

    public function test_new_groups_skip_names_already_taken(): void
    {
        $students = array_map(fn ($s) => $s + ['name' => 'S'.$s['id'], 'matric_no' => null], $this->cohort($this->descending(6)));

        $proposal = (new BalancedAllocator($this->draft))->propose($students, 2, 0, 'Group', ['group 1', 'Group 3']);

        // Case does not matter: "group 1" blocks "Group 1".
        $this->assertSame(['Group 2', 'Group 4'], array_column($proposal['groups'], 'name'));
    }

    public function test_the_proposal_reports_the_spread_between_groups(): void
    {
        $students = array_map(fn ($s) => $s + ['name' => 'S'.$s['id'], 'matric_no' => null], $this->cohort($this->descending(8)));

        $proposal = (new BalancedAllocator($this->draft))->propose($students, 2, 0, 'Group');

        $this->assertNotNull($proposal['spread']);
        $this->assertLessThan(0.1, $proposal['spread']);
        $this->assertFalse($proposal['uneven']);
    }

    public function test_a_cohort_with_no_cgpa_anywhere_is_warned_about(): void
    {
        $students = array_map(fn ($s) => $s + ['name' => 'S'.$s['id'], 'matric_no' => null], $this->cohort([null, null, null, null]));

        $proposal = (new BalancedAllocator($this->draft))->propose($students, 2, 0, 'Group');

        $this->assertStringContainsString('nothing to balance on', implode(' ', $proposal['warnings']));
        $this->assertNull($proposal['spread']);
    }

    public function test_a_group_of_one_is_warned_about(): void
    {
        $students = array_map(fn ($s) => $s + ['name' => 'S'.$s['id'], 'matric_no' => null], $this->cohort($this->descending(3)));

        $proposal = (new BalancedAllocator($this->draft))->propose($students, 2, 0, 'Group');

        $this->assertStringContainsString('single student', implode(' ', $proposal['warnings']));
    }
}
