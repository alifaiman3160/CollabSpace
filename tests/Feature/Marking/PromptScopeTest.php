<?php

namespace Tests\Feature\Marking;

use App\Models\MarkSuggestion;
use App\Support\Ai\AiManager;
use App\Support\Ai\Drivers\FakeDriver;
use App\Support\Marking\EvidenceFacts;
use App\Support\Marking\SuggestionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Sleep;
use Tests\Concerns\BuildsMarkingFixture;
use Tests\TestCase;

/**
 * Each criterion is judged on the facts of its own evidence type and nothing
 * else, the model is told so, and a reason that cites none of those figures is
 * not accepted.
 */
class PromptScopeTest extends TestCase
{
    use BuildsMarkingFixture;
    use RefreshDatabase;

    /** What each evidence type may be sent, exactly. */
    private const EXPECTED = [
        'task_completion' => ['facts' => ['tasks_assigned', 'tasks_completed'], 'average' => null],
        'reliability' => ['facts' => ['tasks_assigned', 'tasks_completed_on_time', 'tasks_completed_late', 'late_days', 'tasks_overdue'], 'average' => null],
        'communication' => ['facts' => ['messages_sent'], 'average' => ['messages_sent']],
        'collaboration' => ['facts' => ['document_edits', 'files_uploaded'], 'average' => ['document_edits', 'files_uploaded']],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildMarkingFixture();
        Sleep::fake();

        config(['ai.driver' => 'fake', 'ai.daily_limit' => 100, 'marking.ai.spacing_ms' => 0]);
        app()->forgetInstance(AiManager::class);
        app()->forgetInstance(\App\Support\Ai\AiGateway::class);
        app()->forgetInstance(SuggestionService::class);
        FakeDriver::reset();
    }

    /** Answers every criterion with the second level and the reason $reason($criterion) gives. */
    protected function answers(callable $reason): void
    {
        FakeDriver::respondWith(fn ($payload) => ['suggestions' => array_map(fn ($c) => [
            'criterion' => $c['id'],
            'level' => collect($c['levels'])->sortBy('marks')->values()[1]['id'],
            'rationale' => $reason($c),
        ], $payload['criteria'])]);
    }

    protected function run_(): \App\Support\Marking\SuggestionReport
    {
        return app(SuggestionService::class)->run($this->lecturer, $this->rubric->fresh(), $this->group->fresh(), true);
    }

    protected function row(string $student, string $criterion): MarkSuggestion
    {
        return MarkSuggestion::where('student_id', $this->students[$student]->id)
            ->where('rubric_criterion_id', $this->criterion($criterion)->id)->firstOrFail();
    }

    /** @return list<array<string, mixed>> every criterion entry sent, with its student's payload */
    protected function sentCriteria(): array
    {
        $all = [];

        foreach (FakeDriver::sent() as $call) {
            foreach (json_decode($call['payload'], true)['criteria'] as $criterion) {
                $all[] = $criterion;
            }
        }

        return $all;
    }

    /*
    | The payload: only the permitted facts
    */

    public function test_each_criterion_is_sent_only_the_facts_of_its_evidence_type(): void
    {
        $this->answers(fn ($c) => $this->cite($c));

        $this->run_();

        $seen = [];

        foreach ($this->sentCriteria() as $criterion) {
            $type = $criterion['evidence'];
            $expected = self::EXPECTED[$type];
            $seen[$type] = true;

            $facts = $criterion['facts'];
            $average = $facts['group_average'] ?? null;
            unset($facts['group_average']);

            $this->assertEqualsCanonicalizing($expected['facts'], array_keys($facts), "{$type}: the facts");
            $this->assertEqualsCanonicalizing($expected['facts'], $criterion['permitted_facts'], "{$type}: the names listed");

            if ($expected['average'] === null) {
                $this->assertNull($average, "{$type} is not compared with the group average");
            } else {
                $this->assertEqualsCanonicalizing($expected['average'], array_keys($average), "{$type}: the averages");
            }
        }

        $this->assertEqualsCanonicalizing(array_keys(self::EXPECTED), array_keys($seen), 'All four types were exercised.');
    }

    public function test_communication_is_never_sent_document_edits_and_collaboration_never_messages(): void
    {
        $this->answers(fn ($c) => $this->cite($c));

        $this->run_();

        foreach ($this->sentCriteria() as $criterion) {
            $json = json_encode($criterion['facts']);

            $absent = [
                'communication' => ['document_edits', 'files_uploaded', 'tasks'],
                'collaboration' => ['messages_sent', 'tasks'],
                'task_completion' => ['on_time', 'overdue', 'messages', 'document'],
                'reliability' => ['messages', 'document', 'files'],
            ][$criterion['evidence']];

            foreach ($absent as $name) {
                $this->assertStringNotContainsString($name, $json, "{$criterion['evidence']} was sent {$name}");
            }
        }
    }

    public function test_the_payload_has_no_whole_student_facts_block(): void
    {
        $this->answers(fn ($c) => $this->cite($c));

        $this->run_();

        foreach (FakeDriver::sent() as $call) {
            $payload = json_decode($call['payload'], true);

            $this->assertArrayNotHasKey('facts', $payload, 'Facts belong to a criterion, not to the student as a whole.');
            $this->assertEqualsCanonicalizing(['student', 'insufficient_data', 'criteria'], array_keys($payload));
        }
    }

    public function test_the_values_sent_are_the_students_own(): void
    {
        $this->answers(fn ($c) => $this->cite($c));

        $this->run_();

        $service = app(SuggestionService::class);
        $payload = collect(FakeDriver::sent())
            ->map(fn ($call) => json_decode($call['payload'], true))
            ->first(fn ($p) => $p['student'] === $service->opaque($this->group, $this->students['s1']));

        $byType = collect($payload['criteria'])->keyBy('evidence');

        $this->assertSame(['tasks_assigned' => 4, 'tasks_completed' => 3], $byType['task_completion']['facts']);
        $this->assertSame(
            ['tasks_assigned' => 4, 'tasks_completed_on_time' => 2, 'tasks_completed_late' => 1, 'late_days' => [3], 'tasks_overdue' => 1],
            $byType['reliability']['facts'],
        );
        $this->assertEquals(['messages_sent' => 10, 'group_average' => ['messages_sent' => 5.0]], $byType['communication']['facts']);
        $this->assertEquals(['document_edits' => 3, 'files_uploaded' => 1, 'group_average' => ['document_edits' => 1.0, 'files_uploaded' => 0.25]], $byType['collaboration']['facts']);
    }

    public function test_the_instructions_state_the_scope_of_each_type(): void
    {
        $this->answers(fn ($c) => $this->cite($c));

        $this->run_();

        $system = FakeDriver::sent()[0]['system'];

        $this->assertStringContainsString('use ONLY the facts supplied for that criterion', $system);
        $this->assertStringContainsString('task_completion: use only tasks completed against tasks assigned', $system);
        $this->assertStringContainsString('reliability: use only the on-time, late and overdue figures for the assigned tasks, and name any overdue tasks in the reason', $system);
        $this->assertStringContainsString('communication: use only messages sent, never document edits or uploads', $system);
        $this->assertStringContainsString('collaboration: use only document edits and file uploads, never messages', $system);
        $this->assertStringContainsString('cites one or two of the supplied numbers', $system);
        $this->assertStringContainsString('ONE short, warm sentence of about 15 words or fewer', $system);
        $this->assertStringContainsString('plain everyday English', $system);
        foreach (['indicating', 'showcasing', 'demonstrating'] as $word) {
            $this->assertStringContainsString('"'.$word.'"', $system, 'Named as words not to use.');
        }

        // The same words as the code's own rule for each type.
        foreach (array_keys(self::EXPECTED) as $type) {
            $this->assertStringContainsString(EvidenceFacts::rule($type), $system, $type);
        }
    }

    /*
    | A reason must cite the figures
    */

    public function test_a_reason_with_no_number_is_unusable_and_falls_back_to_the_baseline(): void
    {
        $this->answers(fn ($c) => 'The student has contributed well to the group.');

        $report = $this->run_();

        $row = $this->row('s1', 'Contribution to tasks');
        $this->assertSame('invalid', $row->ai_status);
        $this->assertNull($row->ai_level_id);
        $this->assertNull($row->ai_rationale);
        $this->assertNotNull($row->baseline_level_id);
        $this->assertSame('Rule-based', $row->sourceLabel());
        $this->assertStringContainsString('did not cite any of the figures', $row->ai_message);
        $this->assertSame(0, $report->fromAi);
        $this->assertGreaterThan(0, $report->discarded);
    }

    public function test_a_number_that_belongs_to_another_evidence_type_does_not_count(): void
    {
        // s1 sent 10 messages (the group averaged 5), made 3 document edits and 1 upload.
        // A communication reason that cites only the edits cites nothing it was given.
        $this->answers(fn ($c) => $c['evidence'] === 'communication'
            ? 'They made 3 document edits and 1 upload.'
            : $this->cite($c));

        $this->run_();

        $this->assertSame('invalid', $this->row('s1', 'Communication')->ai_status);
        $this->assertSame('ok', $this->row('s1', 'Contribution to tasks')->ai_status);
    }

    public function test_a_reason_citing_any_permitted_figure_is_accepted(): void
    {
        // s1 sent 10 messages and the group averaged 5.
        foreach ([
            'They sent 10 messages.' => true,
            'Above the group average of 5.' => true,
            'Sent 10 against an average of 5.0, so well above.' => true,
            'About ten messages.' => false,       // a number in words is not a figure
            'Clearly above the average.' => false,
        ] as $reason => $accepted) {
            $this->answers(fn ($c) => $c['evidence'] === 'communication' ? $reason : $this->cite($c));

            $this->run_();

            $this->assertSame($accepted ? 'ok' : 'invalid', $this->row('s1', 'Communication')->ai_status, $reason);
        }
    }
    public function test_figures_are_compared_as_numbers_not_as_text(): void
    {
        $this->assertSame(['2.5', '3', '75'], EvidenceFacts::numbersIn('2.50 of 3 and 75%'));
        $this->assertSame(['1.5'], EvidenceFacts::numbersIn('about 1,5 times'));
        $this->assertSame([], EvidenceFacts::numbersIn('no figures here'));

        $figures = EvidenceFacts::figures('communication', ['messages_sent' => 10, 'document_edits' => 3, 'group_average' => ['messages_sent' => 5.0, 'document_edits' => 1.0]]);
        $this->assertEqualsCanonicalizing(['10', '5'], $figures, 'Messages and their average, not the edits.');

        $reliability = EvidenceFacts::figures('reliability', ['tasks_assigned' => 4, 'tasks_completed_on_time' => 2, 'tasks_completed_late' => 1, 'late_days' => [3, 7], 'tasks_overdue' => 1, 'messages_sent' => 99]);
        $this->assertEqualsCanonicalizing(['4', '2', '1', '3', '7'], $reliability);
    }

    public function test_a_reliability_reason_must_name_overdue_tasks_when_there_are_any(): void
    {
        // s1 has one task overdue now; s2 has none.
        $this->answers(fn ($c) => $c['evidence'] === 'reliability'
            ? '2 of the 4 assigned tasks were completed on time.'
            : $this->cite($c));

        $this->run_();

        $withOverdue = $this->row('s1', 'Reliability and deadlines');
        $this->assertSame('invalid', $withOverdue->ai_status);
        $this->assertStringContainsString('did not mention the overdue tasks', $withOverdue->ai_message);
        $this->assertNotNull($withOverdue->baseline_level_id);

        $this->assertSame('ok', $this->row('s2', 'Reliability and deadlines')->ai_status, 'Nothing overdue, so nothing to name.');
    }

    public function test_naming_the_overdue_task_with_a_figure_is_accepted(): void
    {
        $this->answers(fn ($c) => $c['evidence'] === 'reliability'
            ? '2 of 4 assigned tasks were on time, 1 was late by 3 days and 1 is overdue now.'
            : $this->cite($c));

        $this->run_();

        $this->assertSame('ok', $this->row('s1', 'Reliability and deadlines')->ai_status);
        $this->assertStringContainsString('overdue', $this->row('s1', 'Reliability and deadlines')->ai_rationale);
    }

    public function test_the_other_checks_still_apply_after_the_figure_check(): void
    {
        $other = $this->criterion('Collaboration')->levels->first()->id;
        $target = $this->criterion('Contribution to tasks')->id;

        FakeDriver::respondWith(fn ($payload) => ['suggestions' => array_map(fn ($c) => [
            'criterion' => $c['id'],
            'level' => $c['id'] === $target ? $other : collect($c['levels'])->sortBy('marks')->values()[1]['id'],
            'rationale' => $this->cite($c),
        ], $payload['criteria'])]);

        $this->run_();

        $this->assertStringContainsString('not one of this criterion', $this->row('s1', 'Contribution to tasks')->ai_message);
        $this->assertSame('ok', $this->row('s1', 'Communication')->ai_status);
    }
}
