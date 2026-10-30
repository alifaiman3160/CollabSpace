<?php

namespace App\Support\Formation;

/**
 * Turns a pool of students into a proposed set of balanced groups.
 *
 * Decides how many groups are possible, runs the snake draft, names the groups
 * and works out the figures the lecturer needs to judge the result by. It does
 * not touch the database: the proposal it returns is plain data, which is what
 * lets it be shown, held, regenerated or thrown away without anything having
 * been written.
 */
class BalancedAllocator
{
    public function __construct(protected SnakeDraft $draft) {}

    /**
     * How many groups a pool of this size can sensibly be split into.
     *
     * At least enough that nobody goes over the size limit, and at most enough
     * that every group still has two members. Where those cross — three
     * students against a limit of two cannot make groups of two — the upper
     * bound gives way, since the lecturer would rather see one short group
     * than be told nothing can be done.
     *
     * @return array{min: int, max: int}
     */
    public static function bounds(int $students, int $limit): array
    {
        if ($students < 2) {
            return ['min' => 0, 'max' => 0];
        }

        $min = max(1, (int) ceil($students / max(1, $limit)));

        return ['min' => $min, 'max' => max($min, intdiv($students, 2))];
    }

    /**
     * @param  list<array{id: int, name: string, matric_no: ?string, cgpa: ?float}>  $students
     * @param  list<string>  $takenNames  names already used in the subject
     * @return array{
     *     seed: int,
     *     groups: list<array{name: string, members: list<array>, average: ?float, known: int, unknown: int}>,
     *     spread: ?float,
     *     sizes: list<int>,
     *     uneven: bool,
     *     warnings: list<string>,
     * }
     */
    public function propose(array $students, int $groups, int $seed, string $prefix, array $takenNames = []): array
    {
        $dealt = $this->draft->deal(
            array_map(fn (array $s) => ['id' => $s['id'], 'cgpa' => $s['cgpa']], $students),
            $groups,
            $seed,
        );

        $byId = [];
        foreach ($students as $student) {
            $byId[$student['id']] = $student;
        }

        $names = $this->names($groups, $prefix, $takenNames);

        $proposal = [];

        foreach ($dealt as $index => $members) {
            $full = array_map(fn (array $m) => $byId[$m['id']], $members);

            // Strongest first inside a group, so a lecturer reading down it
            // sees the spread of ability the group was dealt.
            usort($full, fn (array $a, array $b) => [$b['cgpa'] ?? -1, $a['id']] <=> [$a['cgpa'] ?? -1, $b['id']]);

            $proposal[] = ['name' => $names[$index], 'members' => $full] + SnakeDraft::summarise($full);
        }

        $averages = array_values(array_filter(
            array_column($proposal, 'average'),
            fn ($a) => $a !== null,
        ));

        $sizes = array_map(fn (array $g) => count($g['members']), $proposal);

        return [
            'seed' => $seed,
            'groups' => $proposal,
            'spread' => count($averages) > 1 ? round(max($averages) - min($averages), 2) : null,
            'sizes' => $sizes,
            'uneven' => count(array_unique($sizes)) > 1,
            'warnings' => $this->warnings($students, $sizes),
        ];
    }

    /**
     * "Group 1", "Group 2", skipping any the subject already uses, compared
     * without regard to case — a name that differs only by capitals is a
     * clash as far as a lecturer reading a list is concerned.
     *
     * @param  list<string>  $taken
     * @return list<string>
     */
    protected function names(int $count, string $prefix, array $taken): array
    {
        $prefix = trim($prefix) === '' ? 'Group' : trim($prefix);
        $used = array_map(fn (string $n) => mb_strtolower(trim($n)), $taken);

        $names = [];

        for ($n = 1; count($names) < $count; $n++) {
            $candidate = "{$prefix} {$n}";

            if (! in_array(mb_strtolower($candidate), $used, true)) {
                $names[] = $candidate;
            }
        }

        return $names;
    }

    /**
     * Things the lecturer should know before trusting the proposal.
     *
     * @param  list<array>  $students
     * @param  list<int>  $sizes
     * @return list<string>
     */
    protected function warnings(array $students, array $sizes): array
    {
        $warnings = [];

        $unknown = count(array_filter($students, fn (array $s) => $s['cgpa'] === null));

        if ($unknown === count($students)) {
            $warnings[] = 'None of these students has a CGPA recorded, so there is nothing to balance on. '
                .'The groups below are effectively arbitrary. Ask the administrator to enter CGPAs first.';
        } elseif ($unknown > 0) {
            $warnings[] = $unknown.' '.($unknown === 1 ? 'student has' : 'students have').' no CGPA recorded. '
                .'They are placed as if average and left out of each group’s average, so treat the figures as approximate.';
        }

        if (min($sizes) < 2) {
            $warnings[] = 'With this many groups one of them ends up with a single student. '
                .'Use fewer groups, or raise the subject’s group size.';
        }

        return $warnings;
    }
}
