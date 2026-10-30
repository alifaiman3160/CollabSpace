<?php

namespace Tests\Feature\Marking;

use App\Models\Task;
use App\Support\ActivityRecorder;
use App\Support\Marking\Baseline;
use App\Support\Marking\EvidenceType;
use App\Support\Marking\GroupFacts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsMarkingFixture;
use Tests\TestCase;

class FactsAndBaselineTest extends TestCase
{
    use BuildsMarkingFixture;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildMarkingFixture(withRubric: false);
    }

    protected function facts(): array
    {
        return app(GroupFacts::class)->forGroup($this->group->fresh());
    }

    /*
    | Facts
    */

    public function test_facts_match_the_task_board_and_activity_for_the_group(): void
    {
        $facts = $this->facts();
        $s1 = $facts[$this->students['s1']->id];

        $this->assertSame(4, $s1['tasks_assigned']);
        $this->assertSame(3, $s1['tasks_completed']);
        $this->assertSame(2, $s1['tasks_completed_on_time']);
        $this->assertSame(1, $s1['tasks_completed_late']);
        $this->assertSame([3], $s1['late_days']);
        $this->assertSame(1, $s1['tasks_overdue']);
        $this->assertSame(10, $s1['messages_sent']);
        $this->assertSame(3, $s1['document_edits']);
        $this->assertSame(1, $s1['files_uploaded']);
    }

    public function test_a_task_done_without_recorded_credit_is_not_evidence(): void
    {
        $s2 = $this->facts()[$this->students['s2']->id];

        $this->assertSame(3, $s2['tasks_assigned']);
        $this->assertSame(2, $s2['tasks_completed'], 'The third is marked done but has no completion on record.');
        $this->assertSame(2, $s2['tasks_completed_on_time']);
        $this->assertSame(0, $s2['tasks_overdue'], 'A done task is never overdue.');
        $this->assertSame(1, $s2['document_edits'], 'A document creation counts with edits, as in the contribution report.');
    }

    public function test_a_student_with_nothing_recorded_has_zeroes_not_gaps(): void
    {
        $s3 = $this->facts()[$this->students['s3']->id];

        foreach (GroupFacts::KEYS as $key) {
            $this->assertSame(0, $s3[$key], $key);
        }
        $this->assertSame([], $s3['late_days']);
    }

    public function test_the_group_average_is_over_current_members(): void
    {
        $avg = $this->facts()[$this->students['s1']->id]['group_average'];

        $this->assertEqualsWithDelta(2.0, $avg['tasks_assigned'], 0.001);
        $this->assertEqualsWithDelta(5.0, $avg['messages_sent'], 0.001);
        $this->assertEqualsWithDelta(1.0, $avg['document_edits'], 0.001);
        $this->assertEqualsWithDelta(0.25, $avg['files_uploaded'], 0.001);
        $this->assertSame(4, $this->facts()[$this->students['s1']->id]['group_size']);
    }

    public function test_the_daily_message_cap_is_respected_not_bypassed(): void
    {
        config(['contribution.message_cap_per_day' => 20]);

        $this->sendMessages($this->students['s3'], 25);

        $this->assertSame(20, $this->facts()[$this->students['s3']->id]['messages_sent']);
        $this->assertSame(25, \App\Models\Message::where('user_id', $this->students['s3']->id)->count(), 'All 25 were sent; only 20 count.');
    }

    public function test_credit_withdrawn_when_a_task_leaves_done_removes_it_from_the_facts(): void
    {
        $task = Task::where('assigned_to', $this->students['s2']->id)->where('deadline', '!=', null)->firstOrFail();

        $task->update(['status' => Task::STATUS_TODO, 'completed_at' => null]);
        app(ActivityRecorder::class)->taskCompletionCleared($task);

        $this->assertSame(1, $this->facts()[$this->students['s2']->id]['tasks_completed']);
    }

    public function test_a_removed_member_no_longer_counts_towards_the_average(): void
    {
        $this->group->members()->detach($this->students['s3']->id);

        $facts = $this->facts();

        $this->assertArrayNotHasKey($this->students['s3']->id, $facts);
        $this->assertSame(3, $facts[$this->students['s1']->id]['group_size']);
        $this->assertEqualsWithDelta(6.67, $facts[$this->students['s1']->id]['group_average']['messages_sent'], 0.01);
    }

    /*
    | Baseline
    */

    protected function levels(int $n): array
    {
        return array_map(fn ($i) => ['id' => 100 + $i, 'marks' => $i + 1, 'position' => $i + 1], range(0, $n - 1));
    }

    /** @return array<string, mixed> */
    protected function facts4(array $over = []): array
    {
        return array_replace([
            'tasks_assigned' => 4, 'tasks_completed' => 4, 'tasks_completed_on_time' => 4, 'tasks_completed_late' => 0,
            'late_days' => [], 'tasks_overdue' => 0, 'messages_sent' => 5, 'document_edits' => 1, 'files_uploaded' => 0,
            'group_average' => ['tasks_assigned' => 4, 'messages_sent' => 5, 'document_edits' => 1, 'files_uploaded' => 0],
            'group_size' => 4,
        ], $over);
    }

    public static function boundaries(): array
    {
        // Four levels, cutoffs 0.25, 0.5, 0.75, 0.9: a ratio has reached a cutoff when it equals it.
        return [
            'just under the first cutoff' => [0.2499, 0],
            'on the first cutoff' => [0.25, 1],
            'just under the second' => [0.4999, 1],
            'on the second' => [0.5, 2],
            'between second and fourth' => [0.8, 2],
            'on the last' => [0.9, 3],
            'above the last' => [1.4, 3],
            'zero' => [0.0, 0],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('boundaries')]
    public function test_bands_give_the_expected_level_at_the_boundaries_for_four_levels(float $ratio, int $expected): void
    {
        $this->assertSame($expected, app(Baseline::class)->index(EvidenceType::TASK_COMPLETION, $ratio, 4));
    }

    public function test_the_same_bands_work_for_any_number_of_levels(): void
    {
        $baseline = app(Baseline::class);
        $type = EvidenceType::TASK_COMPLETION;

        // The lowest ratio is always the lowest level and the highest ratio the highest, however many levels.
        foreach ([1, 2, 3, 4, 5, 6, 7, 10] as $n) {
            $this->assertSame(0, $baseline->index($type, 0.0, $n), "{$n} levels, lowest");
            $this->assertSame($n - 1, $baseline->index($type, 1.0, $n), "{$n} levels, highest");

            $previous = -1;
            foreach ([0, 0.1, 0.25, 0.4, 0.5, 0.6, 0.75, 0.85, 0.9, 1.0] as $ratio) {
                $index = $baseline->index($type, $ratio, $n);
                $this->assertGreaterThanOrEqual($previous, $index, "{$n} levels: a higher ratio never lands lower ({$ratio})");
                $this->assertLessThanOrEqual($n - 1, $index);
                $previous = $index;
            }
        }

        // Three levels: low, middle, high.
        $this->assertSame(0, $baseline->index($type, 0.1, 3));
        $this->assertSame(1, $baseline->index($type, 0.5, 3));
        $this->assertSame(2, $baseline->index($type, 0.9, 3));

        // Two levels.
        $this->assertSame(0, $baseline->index($type, 0.2, 2));
        $this->assertSame(1, $baseline->index($type, 0.5, 2));
    }

    public function test_cutoffs_are_configurable(): void
    {
        config(['marking.bands.task_completion.cutoffs' => [0.5]]);

        $this->assertSame(0, app(Baseline::class)->index('task_completion', 0.49, 4));
        $this->assertSame(3, app(Baseline::class)->index('task_completion', 0.5, 4));
    }

    public function test_task_completion_is_the_share_of_assigned_tasks_completed(): void
    {
        $result = app(Baseline::class)->suggest(EvidenceType::TASK_COMPLETION, $this->facts4(['tasks_completed' => 2]), $this->levels(4));

        $this->assertSame('ok', $result['status']);
        $this->assertSame(0.5, $result['ratio']);
        $this->assertSame(102, $result['level_id']);   // third of four
        $this->assertStringContainsString('Completed 2 of 4 assigned tasks (50%)', $result['note']);
        $this->assertStringContainsString('level 3 of 4', $result['note']);
    }

    public function test_reliability_is_on_time_over_assigned_with_nothing_subtracted_twice(): void
    {
        // 2 assigned, 1 completed on time, 1 overdue now. The overdue task is already
        // not on time, so the share is 1 of 2, not (1 - 1) of 2.
        $facts = $this->facts4(['tasks_assigned' => 2, 'tasks_completed' => 1, 'tasks_completed_on_time' => 1, 'tasks_overdue' => 1]);

        $result = app(Baseline::class)->suggest(EvidenceType::RELIABILITY, $facts, $this->levels(4));

        $this->assertSame(0.5, $result['ratio']);
        $this->assertStringContainsString('1 of 2 assigned tasks was completed on time; 1 is overdue now (50%).', $result['note']);
        $this->assertStringNotContainsString('after counting', $result['note']);
    }

    public function test_a_task_completed_late_is_not_on_time_so_it_does_not_add_to_the_share(): void
    {
        // 4 assigned: 2 on time, 1 completed 3 days late, 1 still open and not yet due.
        $facts = $this->facts4(['tasks_completed' => 3, 'tasks_completed_on_time' => 2, 'tasks_completed_late' => 1, 'late_days' => [3]]);

        $result = app(Baseline::class)->suggest(EvidenceType::RELIABILITY, $facts, $this->levels(4));

        $this->assertSame(0.5, $result['ratio'], 'Only on-time work is in the numerator.');
        $this->assertStringContainsString('2 of 4 assigned tasks were completed on time, 1 completed late (3 days late) (50%).', $result['note']);
    }

    public function test_overdue_tasks_never_move_the_ratio_only_the_sentence(): void
    {
        $none = app(Baseline::class)->suggest(EvidenceType::RELIABILITY, $this->facts4(['tasks_completed_on_time' => 3, 'tasks_overdue' => 0]), $this->levels(4));
        $some = app(Baseline::class)->suggest(EvidenceType::RELIABILITY, $this->facts4(['tasks_completed_on_time' => 3, 'tasks_overdue' => 1]), $this->levels(4));

        $this->assertSame($none['ratio'], $some['ratio']);
        $this->assertStringContainsString('1 is overdue now', $some['note']);
        $this->assertStringNotContainsString('overdue', $none['note']);
    }

    public function test_the_old_overdue_penalty_setting_no_longer_exists(): void
    {
        $this->assertNull(config('marking.overdue_penalty'));
    }

    public static function groupA(): array
    {
        // On-time share of assigned tasks, on a four-level rubric (Poor, Fair, Good, Excellent),
        // with the reliability cutoffs 0.25, 0.5, 0.75, 0.9.
        return [
            'Alif 3 of 5' => [5, 3, 0.6, 3, 'Good'],
            'Farah 1 of 3' => [3, 1, 0.3333, 2, 'Fair'],
            'Lee 1 of 2' => [2, 1, 0.5, 3, 'Good'],
            'Nurul 2 of 3' => [3, 2, 0.6667, 3, 'Good'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('groupA')]
    public function test_group_a_reliability_levels(int $assigned, int $onTime, float $ratio, int $level, string $label): void
    {
        $facts = $this->facts4(['tasks_assigned' => $assigned, 'tasks_completed' => $onTime, 'tasks_completed_on_time' => $onTime]);

        $result = app(Baseline::class)->suggest(EvidenceType::RELIABILITY, $facts, $this->levels(4));

        $this->assertEqualsWithDelta($ratio, $result['ratio'], 0.0001);
        $this->assertSame(100 + $level - 1, $result['level_id'], "{$label}");
        $this->assertStringContainsString("level {$level} of 4", $result['note']);
    }

    public function test_communication_and_collaboration_are_relative_to_the_group_average(): void
    {
        $comm = app(Baseline::class)->suggest(EvidenceType::COMMUNICATION, $this->facts4(['messages_sent' => 10]), $this->levels(4));
        $this->assertSame(2.0, $comm['ratio']);
        $this->assertSame(103, $comm['level_id']);
        $this->assertStringContainsString('Sent 10 messages against a group average of 5', $comm['note']);

        $collab = app(Baseline::class)->suggest(EvidenceType::COLLABORATION, $this->facts4(['document_edits' => 1, 'files_uploaded' => 1, 'group_average' => ['document_edits' => 1, 'files_uploaded' => 1]]), $this->levels(4));
        $this->assertSame(1.0, $collab['ratio']);
        $this->assertSame(102, $collab['level_id'], 'Meeting the average is mid-high on four levels, not the top.');
    }

    public function test_levels_are_ordered_by_marks_not_by_the_order_given(): void
    {
        $shuffled = [['id' => 3, 'marks' => 3, 'position' => 3], ['id' => 1, 'marks' => 1, 'position' => 1], ['id' => 4, 'marks' => 5, 'position' => 4], ['id' => 2, 'marks' => 2, 'position' => 2]];

        $this->assertSame(4, app(Baseline::class)->suggest(EvidenceType::TASK_COMPLETION, $this->facts4(), $shuffled)['level_id']);
        $this->assertSame(1, app(Baseline::class)->suggest(EvidenceType::TASK_COMPLETION, $this->facts4(['tasks_completed' => 0]), $shuffled)['level_id']);
    }

    public function test_no_evidence_or_no_data_is_needs_lecturer_input_never_a_level(): void
    {
        $baseline = app(Baseline::class);

        foreach ([EvidenceType::NONE, null, '', 'unrecognised'] as $type) {
            $this->assertSame('needs_input', $baseline->suggest($type, $this->facts4(), $this->levels(4))['status'], (string) $type);
        }

        $noTasks = $this->facts4(['tasks_assigned' => 0, 'tasks_completed' => 0, 'tasks_completed_on_time' => 0]);
        $this->assertSame('needs_input', $baseline->suggest(EvidenceType::TASK_COMPLETION, $noTasks, $this->levels(4))['status']);
        $this->assertSame('needs_input', $baseline->suggest(EvidenceType::RELIABILITY, $noTasks, $this->levels(4))['status']);

        $silent = $this->facts4(['messages_sent' => 0, 'group_average' => ['messages_sent' => 0, 'document_edits' => 0, 'files_uploaded' => 0]]);
        $this->assertSame('needs_input', $baseline->suggest(EvidenceType::COMMUNICATION, $silent, $this->levels(4))['status']);
        $this->assertSame('needs_input', $baseline->suggest(EvidenceType::COLLABORATION, $silent, $this->levels(4))['status']);

        $this->assertSame('needs_input', $baseline->suggest(EvidenceType::TASK_COMPLETION, $this->facts4(), [])['status']);
    }

    public function test_a_real_zero_is_evidence_when_others_have_activity(): void
    {
        // The student sent nothing while the group averaged 5: that is a finding, not a gap.
        $result = app(Baseline::class)->suggest(EvidenceType::COMMUNICATION, $this->facts4(['messages_sent' => 0]), $this->levels(4));

        $this->assertSame('ok', $result['status']);
        $this->assertSame(100, $result['level_id']);
    }

    public function test_the_baseline_is_deterministic(): void
    {
        $a = app(Baseline::class)->suggest(EvidenceType::RELIABILITY, $this->facts4(['tasks_overdue' => 1]), $this->levels(5));
        $b = app(Baseline::class)->suggest(EvidenceType::RELIABILITY, $this->facts4(['tasks_overdue' => 1]), $this->levels(5));

        $this->assertSame($a, $b);
    }

    public function test_the_baseline_over_the_fixture_gives_the_expected_levels(): void
    {
        $facts = $this->facts();
        $levels = $this->levels(4);
        $baseline = app(Baseline::class);

        // s1: 3 of 4 completed (0.75): third band of four.
        $this->assertSame(102, $baseline->suggest(EvidenceType::TASK_COMPLETION, $facts[$this->students['s1']->id], $levels)['level_id']);
        // s1 reliability: 2 of 4 assigned on time = 0.5: third level.
        $this->assertSame(102, $baseline->suggest(EvidenceType::RELIABILITY, $facts[$this->students['s1']->id], $levels)['level_id']);
        // s3: nothing assigned.
        $this->assertSame('needs_input', $baseline->suggest(EvidenceType::TASK_COMPLETION, $facts[$this->students['s3']->id], $levels)['status']);
        // s3 communication: 0 against an average of 5.
        $this->assertSame(100, $baseline->suggest(EvidenceType::COMMUNICATION, $facts[$this->students['s3']->id], $levels)['level_id']);
    }

    /*
    | Evidence type from wording
    */

    public static function wording(): array
    {
        return [
            ['Contribution to tasks', 'task_completion'],
            ['Completes assigned work', 'task_completion'],
            ['Reliability and deadlines', 'reliability'],
            ['Meets deadlines on tasks', 'reliability'],
            ['Submits on time', 'reliability'],
            ['Communication', 'communication'],
            ['Takes part in discussion', 'communication'],
            ['Collaboration', 'collaboration'],
            ['Helps others with their work', 'collaboration'],
            ['Helps and supports others', null],
            ['Respect for teammates', null],
            ['Initiative and problem solving', null],
            ['Creativity', null],
            ['', null],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('wording')]
    public function test_a_default_evidence_type_is_guessed_from_the_wording(string $name, ?string $expected): void
    {
        $this->assertSame($expected, EvidenceType::guess($name));
    }

    public function test_unset_and_unknown_evidence_is_none(): void
    {
        $this->assertSame('none', EvidenceType::normalise(null));
        $this->assertSame('none', EvidenceType::normalise(''));
        $this->assertSame('none', EvidenceType::normalise('astrology'));
        $this->assertSame('reliability', EvidenceType::normalise('reliability'));
    }
}
