<?php

namespace Tests\Feature\Marking;

use App\Support\Marking\Baseline;
use App\Support\Marking\EvidenceType;
use Tests\TestCase;

/**
 * The bands for the evidence types measured against the group average
 * (communication, collaboration). Meeting the average is mid-high, not the top:
 *
 *     ratio to the group average      level of five
 *     1.25 or more                    5
 *     0.9 up to, not including 1.25   4
 *     0.6 up to, not including 0.9    3
 *     0.3 up to, not including 0.6    2
 *     below 0.3                       1
 */
class RelativeBandsTest extends TestCase
{
    private const RELATIVE = [EvidenceType::COMMUNICATION, EvidenceType::COLLABORATION];

    /** @return list<array{id: int, marks: int, position: int}> levels 1..$n, ids 101..10n */
    protected function levels(int $n = 5): array
    {
        return array_map(fn ($i) => ['id' => 100 + $i, 'marks' => $i, 'position' => $i], range(1, $n));
    }

    protected function level(string $type, float $ratio, int $n = 5): int
    {
        return app(Baseline::class)->index($type, $ratio, $n) + 1;
    }

    public function test_the_chosen_table_is_in_the_config_and_the_same_for_both_relative_types(): void
    {
        foreach (self::RELATIVE as $type) {
            $this->assertSame([0.3, 0.6, 0.9, 1.25], config("marking.bands.{$type}.cutoffs"), $type);
        }
    }

    public function test_the_absolute_shares_are_unchanged(): void
    {
        $this->assertSame([0.25, 0.5, 0.75, 0.9], config('marking.bands.task_completion.cutoffs'));
        $this->assertSame([0.25, 0.5, 0.75, 0.9], config('marking.bands.reliability.cutoffs'));
    }

    public static function boundaries(): array
    {
        return [
            'zero' => [0.0, 1],
            'well below' => [0.1, 1],
            'just under 0.3' => [0.2999, 1],
            'on 0.3' => [0.3, 2],
            'middle of level 2' => [0.45, 2],
            'just under 0.6' => [0.5999, 2],
            'on 0.6' => [0.6, 3],
            'middle of level 3' => [0.75, 3],
            'just under 0.9' => [0.8999, 3],
            'on 0.9' => [0.9, 4],
            'just under the average' => [0.99, 4],
            'exactly the average' => [1.0, 4],
            'above the average' => [1.1, 4],
            'just under 1.25' => [1.2499, 4],
            'on 1.25' => [1.25, 5],
            'just over 1.25' => [1.2501, 5],
            'double' => [2.0, 5],
            'far above' => [10.0, 5],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('boundaries')]
    public function test_each_boundary_on_a_five_level_criterion(float $ratio, int $expectedLevel): void
    {
        foreach (self::RELATIVE as $type) {
            $this->assertSame($expectedLevel, $this->level($type, $ratio), "{$type} at {$ratio}");
        }
    }

    public function test_meeting_the_average_is_level_four_of_five_not_the_top(): void
    {
        $this->assertSame(4, $this->level(EvidenceType::COMMUNICATION, 1.0));
        $this->assertSame(4, $this->level(EvidenceType::COMMUNICATION, 0.9));
        $this->assertSame(5, $this->level(EvidenceType::COMMUNICATION, 1.25));
    }

    /**
     * [student's count, group average, expected level of five]. Averages of 20 keep
     * the ratios exact: 6/20 = 0.3, 12/20 = 0.6, 18/20 = 0.9, 25/20 = 1.25.
     */
    public static function counts(): array
    {
        return [
            [5, 20, 1],
            [6, 20, 2],
            [11, 20, 2],
            [12, 20, 3],
            [17, 20, 3],
            [18, 20, 4],
            [20, 20, 4],
            [24, 20, 4],
            [25, 20, 5],
            [40, 20, 5],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('counts')]
    public function test_communication_from_counts_through_the_whole_baseline(int $messages, int $average, int $expectedLevel): void
    {
        config(['marking.min_group_activity.communication' => 0]);

        $facts = ['messages_sent' => $messages, 'group_average' => ['messages_sent' => $average], 'group_size' => 4];

        $result = app(Baseline::class)->suggest(EvidenceType::COMMUNICATION, $facts, $this->levels());

        $this->assertSame('ok', $result['status']);
        $this->assertSame(100 + $expectedLevel, $result['level_id'], "{$messages} against an average of {$average}");
        $this->assertStringNotContainsString('This places it at level', $result['note'], 'The level has its own line on the page.');
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('counts')]
    public function test_collaboration_from_counts_through_the_whole_baseline(int $activity, int $average, int $expectedLevel): void
    {
        config(['marking.min_group_activity.collaboration' => 0]);

        // Edits and uploads together, against the group's edits and uploads together.
        $facts = [
            'document_edits' => $activity - 1, 'files_uploaded' => 1,
            'group_average' => ['document_edits' => $average - 2, 'files_uploaded' => 2],
            'group_size' => 4,
        ];

        $result = app(Baseline::class)->suggest(EvidenceType::COLLABORATION, $facts, $this->levels());

        $this->assertSame(100 + $expectedLevel, $result['level_id'], "{$activity} against an average of {$average}");
    }

    public function test_the_ends_hold_for_any_number_of_levels_and_the_average_is_never_the_top_on_four_or_more(): void
    {
        foreach (self::RELATIVE as $type) {
            foreach ([2, 3, 4, 5, 6, 7, 10] as $n) {
                $this->assertSame(1, $this->level($type, 0.0, $n), "{$n} levels, lowest");
                $this->assertSame($n, $this->level($type, 1.25, $n), "{$n} levels, highest");

                if ($n >= 4) {
                    $this->assertLessThan($n, $this->level($type, 1.0, $n), "{$n} levels: meeting the average is not the top");
                }

                $previous = 0;
                foreach ([0, 0.2, 0.3, 0.5, 0.6, 0.8, 0.9, 1.0, 1.2, 1.25, 2] as $ratio) {
                    $level = $this->level($type, (float) $ratio, $n);

                    $this->assertGreaterThanOrEqual($previous, $level, "{$n} levels: a higher ratio never lands lower ({$ratio})");
                    $previous = $level;
                }
            }
        }
    }

    public function test_other_level_counts_spread_the_same_bands_evenly(): void
    {
        $type = EvidenceType::COMMUNICATION;

        // Four levels: the average lands on the third.
        $this->assertSame([1, 2, 3, 3, 4], array_map(fn ($r) => $this->level($type, $r, 4), [0.1, 0.3, 0.6, 1.0, 1.25]));

        // Three levels. The five bands cannot all be told apart on three levels, and the average
        // lands on the top one; see the note in config/marking.php.
        $this->assertSame([1, 2, 2, 3, 3], array_map(fn ($r) => $this->level($type, $r, 3), [0.1, 0.3, 0.6, 1.0, 1.25]));
    }

    public function test_the_bands_stay_adjustable_in_config(): void
    {
        config(['marking.bands.communication.cutoffs' => [0.5, 1.0, 1.5, 2.0]]);

        $this->assertSame(1, $this->level(EvidenceType::COMMUNICATION, 0.49));
        $this->assertSame(3, $this->level(EvidenceType::COMMUNICATION, 1.0));
        $this->assertSame(5, $this->level(EvidenceType::COMMUNICATION, 2.0));
        $this->assertSame(4, $this->level(EvidenceType::COLLABORATION, 1.0), 'The other type keeps its own table.');
    }
}
