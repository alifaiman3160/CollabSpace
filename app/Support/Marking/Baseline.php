<?php

namespace App\Support\Marking;

/**
 * The deterministic suggestion: a student's facts, a band table from
 * config/marking.php, and the criterion's levels in order.
 *
 * No judgement and no AI. The same facts always give the same level, the answer
 * comes with a sentence that cites the figures, and it is available whether or
 * not an AI provider is configured. It is what the AI's suggestion is checked
 * against, and what stands in when the AI cannot answer.
 *
 * Levels are ordered lowest to highest by marks (then by position), and a band
 * is placed on them by position, so the same bands work for any number of levels.
 */
class Baseline
{
    /** What the lecturer is told when the group has recorded too little to compare students. */
    public const INSUFFICIENT = 'There is too little activity in this group to compare students.';

    /**
     * @param  array<string, mixed>  $facts  one student's entry from GroupFacts
     * @param  list<array{id: int, marks: float|string}>  $levels  a criterion's levels, any order
     * @return array{status: 'ok', index: int, level_id: int, ratio: float, note: string}
     *               |array{status: 'needs_input', reason: string}
     *               |array{status: 'insufficient', reason: string, confidence: 'none'}
     */
    public function suggest(?string $evidence, array $facts, array $levels): array
    {
        $evidence = EvidenceType::normalise($evidence);

        if ($evidence === EvidenceType::NONE) {
            return $this->needsInput('This criterion is not drawn from recorded activity.');
        }

        if ($levels === []) {
            return $this->needsInput('This criterion has no performance levels.');
        }

        // The whole group must have done enough for a comparison to mean anything.
        // This comes before the student's own figures: a quiet group is a fact
        // about the group, whatever one student did.
        if ($this->insufficient($evidence, $facts)) {
            return ['status' => 'insufficient', 'reason' => self::INSUFFICIENT, 'confidence' => 'none'];
        }

        $measured = $this->measure($evidence, $facts);

        if ($measured === null) {
            return $this->needsInput($this->noData($evidence));
        }

        [$ratio, $sentence] = $measured;

        $ordered = $this->ordered($levels);
        $index = $this->index($evidence, $ratio, count($ordered));

        return [
            'status' => 'ok',
            'confidence' => 'normal',
            'index' => $index,
            'level_id' => (int) $ordered[$index]['id'],
            'ratio' => round($ratio, 4),
            // Plain words, figures only. Which level it lands on is shown on its own line by the page.
            'note' => EvidenceFacts::sentence($evidence, $facts) ?? $sentence,
        ];
    }

    /** The least group-wide activity a ratio for this evidence type is trusted on. */
    public function minimum(string $evidence): float
    {
        return max(0.0, (float) config("marking.min_group_activity.{$evidence}", 0));
    }

    /**
     * What the whole group has recorded for this evidence type: counts as
     * recorded, added up over the current members.
     */
    public function groupTotal(string $evidence, array $facts): float
    {
        $size = (int) ($facts['group_size'] ?? 0);

        // Older snapshots carry only averages.
        $sum = fn (string $key): float => isset($facts['group_total'][$key])
            ? (float) $facts['group_total'][$key]
            : round((float) ($facts['group_average'][$key] ?? 0) * $size);

        return match ($evidence) {
            EvidenceType::TASK_COMPLETION, EvidenceType::RELIABILITY => $sum('tasks_assigned'),
            EvidenceType::COMMUNICATION => $sum('messages_sent'),
            EvidenceType::COLLABORATION => $sum('document_edits') + $sum('files_uploaded'),
            default => 0.0,
        };
    }

    /** True when the group has recorded less than the minimum for this type. At the minimum is enough. */
    public function insufficient(string $evidence, array $facts): bool
    {
        $minimum = $this->minimum($evidence);

        return $minimum > 0 && $this->groupTotal($evidence, $facts) < $minimum;
    }

    /**
     * The level's zero-based place among the criterion's levels, lowest first.
     *
     * @param  list<array{id: int, marks: float|string}>  $levels
     */
    public function position(array $levels, int $levelId): ?int
    {
        foreach ($this->ordered($levels) as $i => $level) {
            if ((int) $level['id'] === $levelId) {
                return $i;
            }
        }

        return null;
    }

    /**
     * Which level a ratio lands on.
     *
     * The band is the number of cutoffs the ratio has reached; that is placed
     * evenly along the levels and rounded half up. A ratio exactly on a cutoff
     * has reached it.
     */
    public function index(string $evidence, float $ratio, int $levelCount): int
    {
        $cutoffs = (array) config("marking.bands.{$evidence}.cutoffs", []);
        sort($cutoffs);

        if ($levelCount <= 1 || $cutoffs === []) {
            return 0;
        }

        $reached = count(array_filter($cutoffs, fn ($c) => $ratio + 1e-9 >= (float) $c));

        return min($levelCount - 1, (int) floor(($reached / count($cutoffs)) * ($levelCount - 1) + 0.5));
    }

    /**
     * The ratio for an evidence type and the sentence that explains it, or null
     * when there is nothing to measure.
     *
     * @return array{0: float, 1: string}|null
     */
    protected function measure(string $evidence, array $facts): ?array
    {
        $average = $facts['group_average'] ?? [];

        switch ($evidence) {
            case EvidenceType::TASK_COMPLETION:
                $assigned = (int) $facts['tasks_assigned'];

                if ($assigned === 0) {
                    return null;
                }

                $done = (int) $facts['tasks_completed'];

                return [$done / $assigned, "Completed {$done} of {$assigned} assigned ".self::plural($assigned, 'task').' ('.self::percent($done / $assigned).').'];

            case EvidenceType::RELIABILITY:
                $assigned = (int) $facts['tasks_assigned'];

                if ($assigned === 0) {
                    return null;
                }

                // On time over assigned, and nothing else. A task that is overdue now is
                // not completed, so it is already missing from the numerator; a task
                // completed late is completed but not on time, so it does not add to
                // it either. Subtracting overdue tasks as well would count the same
                // task against the student twice. Both appear in the sentence so the
                // lecturer sees them, but only on-time work moves the ratio.
                $onTime = (int) $facts['tasks_completed_on_time'];
                $overdue = (int) $facts['tasks_overdue'];
                $late = (int) $facts['tasks_completed_late'];
                $ratio = $onTime / $assigned;

                $sentence = "{$onTime} of {$assigned} assigned ".self::plural($assigned, 'task').' '.($onTime === 1 ? 'was' : 'were').' completed on time';
                $sentence .= $late > 0 ? ", {$late} completed late (".self::days($facts['late_days'] ?? []).')' : '';
                $sentence .= $overdue > 0 ? "; {$overdue} ".($overdue === 1 ? 'is' : 'are').' overdue now' : '';

                return [$ratio, $sentence.' ('.self::percent($ratio).').'];
            case EvidenceType::COMMUNICATION:
                return $this->relative(
                    (float) $facts['messages_sent'],
                    (float) ($average['messages_sent'] ?? 0),
                    fn ($mine, $avg) => 'Sent '.self::number($mine).' '.self::plural((int) $mine, 'message').' against a group average of '.self::number($avg),
                );

            case EvidenceType::COLLABORATION:
                return $this->relative(
                    (float) $facts['document_edits'] + (float) $facts['files_uploaded'],
                    (float) ($average['document_edits'] ?? 0) + (float) ($average['files_uploaded'] ?? 0),
                    fn ($mine, $avg) => 'Document edits and uploads: '.self::number($mine).' against a group average of '.self::number($avg),
                );
        }

        return null;
    }

    /** @return array{0: float, 1: string}|null null when the group has no activity to compare with */
    protected function relative(float $mine, float $average, callable $describe): ?array
    {
        if ($average <= 0) {
            return null;
        }

        $ratio = $mine / $average;

        return [$ratio, $describe($mine, $average).' ('.self::number($ratio).' times the average).'];
    }

    protected function noData(string $evidence): string
    {
        return match ($evidence) {
            EvidenceType::TASK_COMPLETION, EvidenceType::RELIABILITY => 'No tasks are assigned to this student.',
            EvidenceType::COMMUNICATION => 'The group has no recorded messages to compare with.',
            default => 'The group has no recorded document edits or uploads to compare with.',
        };
    }

    /** @return array{status: 'needs_input', reason: string} */
    protected function needsInput(string $reason): array
    {
        return ['status' => 'needs_input', 'reason' => $reason];
    }

    /**
     * @param  list<array{id: int, marks: float|string}>  $levels
     * @return list<array{id: int, marks: float|string}>
     */
    protected function ordered(array $levels): array
    {
        $levels = array_values($levels);

        usort($levels, fn ($a, $b) => [(float) $a['marks'], $a['position'] ?? 0] <=> [(float) $b['marks'], $b['position'] ?? 0]);

        return $levels;
    }

    private static function plural(int $n, string $word): string
    {
        return $n === 1 ? $word : $word.'s';
    }

    private static function percent(float $ratio): string
    {
        return round($ratio * 100).'%';
    }

    private static function number(float $n): string
    {
        return rtrim(rtrim(number_format($n, 2, '.', ''), '0'), '.');
    }

    /** @param  list<int>  $days */
    private static function days(array $days): string
    {
        return implode(', ', array_map(fn ($d) => $d.' '.self::plural($d, 'day').' late', $days));
    }
}
