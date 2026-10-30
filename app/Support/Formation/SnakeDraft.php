<?php

namespace App\Support\Formation;

use InvalidArgumentException;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * Snake-draft allocation: sort by ability, deal across the groups, and reverse
 * the direction every round.
 *
 * With four groups the strongest four students go to groups 1, 2, 3, 4, the
 * next four to 4, 3, 2, 1, the next four to 1, 2, 3, 4 again. The reversal is
 * the whole point. Dealing in the same order every round would hand group 1 the
 * best student of every tier and group 4 the worst; running back the other way
 * makes each group's good pick in one round be paid for by a poor one in the
 * next, so the group averages stay close together.
 *
 * Pure on purpose — no database, no session, no clock. It takes a list and
 * returns a list, which is what makes the distribution testable in isolation
 * from everything that surrounds it.
 *
 * Group sizes come out within one of each other whatever the headcount. Thirteen
 * students into four groups is 4, 3, 3, 3.
 *
 * The uneven case is the one place this is not a pure snake, and deliberately.
 * Every full round is dealt strictly back and forth. What is left over — fewer
 * students than groups — are by construction the weakest of the cohort, and a
 * plain snake hands them to whichever groups the next slots happen to fall on.
 * With thirteen students that put the weakest of all into the last group and
 * dragged its average to 2.88 against 3.20 and above for the rest. Instead the
 * leftover students go out weakest first to the groups whose averages are
 * currently highest, so the group that takes an extra, weaker member is the one
 * with the most room to absorb it.
 */
class SnakeDraft
{
    /**
     * CGPAs closer together than this are treated as the same ability.
     *
     * Needed because a snake draft is otherwise deterministic, and a
     * "regenerate" button on something deterministic would return the same
     * answer every time and look broken. Real CGPAs rarely tie exactly, so
     * ties alone cannot supply the variety. Students within this distance of
     * one another are close enough in ability that swapping them barely moves
     * a group average, which makes them the honest place to introduce chance.
     */
    public const TIE_TOLERANCE = 0.10;

    /**
     * @param  list<array{id: int, cgpa: ?float}>  $students
     * @param  int  $seed  0 gives the plain, canonical snake; any other value
     *                     reshuffles students inside each tie band.
     * @return list<list<array{id: int, cgpa: ?float}>>  one list of members per group
     */
    public function deal(array $students, int $groups, int $seed = 0): array
    {
        if ($groups < 1) {
            throw new InvalidArgumentException('At least one group is needed.');
        }

        $ranked = $this->ranked($students, $seed);

        $result = array_fill(0, $groups, []);

        // Everything that fills a complete round is a strict snake.
        $complete = intdiv(count($ranked), $groups) * $groups;

        foreach (array_slice($ranked, 0, $complete) as $position => $student) {
            $round = intdiv($position, $groups);
            $slot = $position % $groups;

            // Even rounds run forwards, odd rounds run back.
            $group = $round % 2 === 0 ? $slot : $groups - 1 - $slot;

            $result[$group][] = $student;
        }

        // What is left is fewer than one round: the weakest students. Weakest
        // first, each to the group whose average is currently highest.
        $leftover = array_reverse(array_slice($ranked, $complete));

        if ($leftover !== []) {
            $averages = array_map(
                fn (array $members) => array_sum(array_column($members, 'rank')) / max(1, count($members)),
                $result,
            );

            // Highest average first; the lower index wins a tie so the answer
            // does not depend on sort stability.
            $targets = array_keys($averages);
            usort($targets, fn (int $a, int $b) => [$averages[$b], $a] <=> [$averages[$a], $b]);

            foreach ($leftover as $i => $student) {
                $result[$targets[$i]][] = $student;
            }
        }

        // The rank was only ever scaffolding for the sort.
        return array_map(
            fn (array $members) => array_map(function (array $s) {
                unset($s['rank']);

                return $s;
            }, $members),
            $result,
        );
    }

    /**
     * The order students are dealt in: strongest first.
     *
     * A student with no CGPA cannot be ranked, and leaving them out would mean
     * silently not placing someone. They are ranked as if they had the cohort's
     * average instead, which puts them in the middle where a wrong guess costs
     * least. That figure is used only to position them in the queue — it is
     * never stored and never counted towards a group's average.
     *
     * @param  list<array{id: int, cgpa: ?float}>  $students
     * @return list<array{id: int, cgpa: ?float}>
     */
    public function order(array $students, int $seed = 0): array
    {
        return array_map(function (array $s) {
            unset($s['rank']);

            return $s;
        }, $this->ranked($students, $seed));
    }

    /**
     * The same list as order(), each student still carrying the figure they
     * were ranked on. deal() needs it to balance the leftover students.
     *
     * @param  list<array{id: int, cgpa: ?float}>  $students
     * @return list<array<string, mixed>>
     */
    protected function ranked(array $students, int $seed): array
    {
        $known = array_filter(
            array_map(fn (array $s) => $s['cgpa'], $students),
            fn ($cgpa) => $cgpa !== null,
        );

        $stand = $known === [] ? 0.0 : array_sum($known) / count($known);

        $ranked = array_map(
            fn (array $s) => $s + ['rank' => $s['cgpa'] ?? $stand],
            array_values($students),
        );

        // Strongest first, and by id inside a tie so the same input always
        // gives the same output.
        usort($ranked, fn (array $a, array $b) => [$b['rank'], $a['id']] <=> [$a['rank'], $b['id']]);

        if ($seed !== 0) {
            $ranked = $this->shuffleWithinBands($ranked, $seed);
        }

        return $ranked;
    }

    /**
     * Reshuffles students who are within TIE_TOLERANCE of the strongest in
     * their band. The order of the bands themselves never changes, so the
     * ranking that makes the draft balanced is preserved.
     *
     * @param  list<array<string, mixed>>  $ranked
     * @return list<array<string, mixed>>
     */
    protected function shuffleWithinBands(array $ranked, int $seed): array
    {
        $randomizer = new Randomizer(new Mt19937($seed));

        $bands = [];
        $current = [];
        $start = null;

        foreach ($ranked as $student) {
            if ($start !== null && round($start - $student['rank'], 4) >= self::TIE_TOLERANCE) {
                $bands[] = $current;
                $current = [];
                $start = null;
            }

            $start ??= $student['rank'];
            $current[] = $student;
        }

        if ($current !== []) {
            $bands[] = $current;
        }

        $out = [];

        foreach ($bands as $band) {
            array_push($out, ...$randomizer->shuffleArray($band));
        }

        return $out;
    }

    /**
     * Average of the CGPAs that are actually recorded.
     *
     * @param  list<array{cgpa: ?float}>  $members
     * @return array{average: ?float, known: int, unknown: int}
     */
    public static function summarise(array $members): array
    {
        $known = array_values(array_filter(
            array_map(fn (array $m) => $m['cgpa'], $members),
            fn ($cgpa) => $cgpa !== null,
        ));

        return [
            'average' => $known === [] ? null : round(array_sum($known) / count($known), 2),
            'known' => count($known),
            'unknown' => count($members) - count($known),
        ];
    }
}
