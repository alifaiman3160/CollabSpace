<?php

namespace Tests\Feature\Marking;

use App\Models\MarkSuggestion;
use App\Support\Ai\AiManager;
use App\Support\Ai\Drivers\FakeDriver;
use App\Support\Marking\Baseline;
use App\Support\Marking\EvidenceType;
use App\Support\Marking\GroupFacts;
use App\Support\Marking\SectionTotals;
use App\Support\Marking\SuggestionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Sleep;
use Tests\Concerns\BuildsMarkingFixture;
use Tests\TestCase;

/**
 * The minimum-activity guard: a group that has recorded too little cannot give a
 * trustworthy ratio, so no level is suggested, by rule or by AI, and the
 * criterion is undecided until the lecturer chooses.
 *
 * The fixture group has recorded: 8 assigned tasks, 20 counted messages, and 5
 * document edits and uploads (4 edits, 1 file).
 */
class MinimumActivityTest extends TestCase
{
    use BuildsMarkingFixture;
    use RefreshDatabase;

    private const REASON = 'Not enough recorded activity in this group to compare students';

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

    protected function minimums(array $values): void
    {
        config(['marking.min_group_activity' => array_replace(
            ['task_completion' => 0, 'reliability' => 0, 'communication' => 0, 'collaboration' => 0],
            $values,
        )]);
    }

    protected function facts(): array
    {
        return app(GroupFacts::class)->forGroup($this->group->fresh())[$this->students['s1']->id];
    }

    protected function levels(): array
    {
        return array_map(fn ($i) => ['id' => 100 + $i, 'marks' => $i + 1, 'position' => $i + 1], range(0, 3));
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

    /** A provider that picks the second level of each criterion it is asked about. */
    protected function aiPicks(?callable $tweak = null): void
    {
        FakeDriver::respondWith(function ($payload) use ($tweak) {
            $items = array_map(fn ($c) => [
                'criterion' => $c['id'],
                'level' => collect($c['levels'])->sortBy('marks')->values()[1]['id'],
                'rationale' => $this->cite($c),
            ], $payload['criteria']);

            return ['suggestions' => $tweak ? $tweak($items) : $items];
        });
    }

    /*
    | The numbers
    */

    public function test_the_group_totals_are_what_was_recorded(): void
    {
        $total = $this->facts()['group_total'];

        $this->assertSame(8, $total['tasks_assigned']);
        $this->assertSame(20, $total['messages_sent']);
        $this->assertSame(4, $total['document_edits']);
        $this->assertSame(1, $total['files_uploaded']);
    }

    public function test_the_defaults_are_conservative_and_explained(): void
    {
        $config = require config_path('marking.php');

        $this->assertSame(
            ['task_completion' => 4, 'reliability' => 4, 'communication' => 6, 'collaboration' => 6],
            $config['min_group_activity'],
        );
        $this->assertStringContainsString('one extra upload moves a student', file_get_contents(config_path('marking.php')));
        $this->assertStringContainsString('set 0 to switch the guard off', file_get_contents(config_path('marking.php')));
    }

    /*
    | Baseline
    */

    public function test_a_group_below_the_minimum_gives_no_level_the_reason_and_no_confidence(): void
    {
        $this->minimums(['communication' => 21]);   // the group has 20

        $result = app(Baseline::class)->suggest(EvidenceType::COMMUNICATION, $this->facts(), $this->levels());

        $this->assertSame('insufficient', $result['status']);
        $this->assertSame(self::REASON, $result['reason']);
        $this->assertSame('none', $result['confidence']);
        $this->assertArrayNotHasKey('level_id', $result);
        $this->assertArrayNotHasKey('ratio', $result);
    }

    public function test_a_group_exactly_at_the_minimum_is_scored_normally(): void
    {
        $this->minimums(['communication' => 20, 'task_completion' => 8, 'reliability' => 8, 'collaboration' => 5]);

        foreach ([EvidenceType::COMMUNICATION, EvidenceType::TASK_COMPLETION, EvidenceType::RELIABILITY, EvidenceType::COLLABORATION] as $type) {
            $result = app(Baseline::class)->suggest($type, $this->facts(), $this->levels());

            $this->assertSame('ok', $result['status'], $type);
            $this->assertSame('normal', $result['confidence'], $type);
            $this->assertArrayHasKey('level_id', $result, $type);
        }
    }

    public function test_one_below_the_minimum_is_insufficient_for_each_type(): void
    {
        $this->minimums(['communication' => 21, 'task_completion' => 9, 'reliability' => 9, 'collaboration' => 6]);

        foreach ([EvidenceType::COMMUNICATION, EvidenceType::TASK_COMPLETION, EvidenceType::RELIABILITY, EvidenceType::COLLABORATION] as $type) {
            $this->assertSame('insufficient', app(Baseline::class)->suggest($type, $this->facts(), $this->levels())['status'], $type);
        }
    }

    public function test_each_type_is_measured_against_its_own_activity(): void
    {
        // Collaboration is edits plus uploads (5), not messages (20) or tasks (8).
        $this->minimums(['collaboration' => 6, 'communication' => 6, 'task_completion' => 6]);

        $baseline = app(Baseline::class);

        $this->assertSame('insufficient', $baseline->suggest(EvidenceType::COLLABORATION, $this->facts(), $this->levels())['status']);
        $this->assertSame('ok', $baseline->suggest(EvidenceType::COMMUNICATION, $this->facts(), $this->levels())['status']);
        $this->assertSame('ok', $baseline->suggest(EvidenceType::TASK_COMPLETION, $this->facts(), $this->levels())['status']);
    }

    public function test_zero_switches_the_guard_off_for_that_type(): void
    {
        $this->minimums(['communication' => 0]);
        $facts = $this->facts();
        $facts['group_total']['messages_sent'] = 0;
        $facts['group_average']['messages_sent'] = 3;   // some activity, none counted

        $this->assertSame('ok', app(Baseline::class)->suggest(EvidenceType::COMMUNICATION, $facts, $this->levels())['status']);
    }

    public function test_the_guard_applies_to_a_quiet_group_before_the_students_own_figures(): void
    {
        $this->minimums(['communication' => 6]);
        $facts = $this->facts();
        $facts['group_total']['messages_sent'] = 0;
        $facts['group_average']['messages_sent'] = 0;

        $this->assertSame('insufficient', app(Baseline::class)->suggest(EvidenceType::COMMUNICATION, $facts, $this->levels())['status']);
    }

    public function test_no_evidence_type_is_still_needs_input_not_insufficient(): void
    {
        $this->minimums(['communication' => 99]);

        $this->assertSame('needs_input', app(Baseline::class)->suggest(EvidenceType::NONE, $this->facts(), $this->levels())['status']);
        $this->assertSame('needs_input', app(Baseline::class)->suggest(null, $this->facts(), $this->levels())['status']);
    }

    public function test_older_facts_with_only_averages_still_work(): void
    {
        $facts = ['group_average' => ['messages_sent' => 1.5], 'group_size' => 4, 'messages_sent' => 2];
        $this->minimums(['communication' => 6]);

        $this->assertSame(6.0, app(Baseline::class)->groupTotal(EvidenceType::COMMUNICATION, $facts));
        $this->assertSame('ok', app(Baseline::class)->suggest(EvidenceType::COMMUNICATION, $facts, $this->levels())['status']);

        $this->minimums(['communication' => 7]);
        $this->assertSame('insufficient', app(Baseline::class)->suggest(EvidenceType::COMMUNICATION, $facts, $this->levels())['status']);
    }

    /*
    | The service: stored, and never put to the model
    */

    public function test_an_insufficient_criterion_is_stored_with_no_level_and_the_reason(): void
    {
        $this->minimums(['communication' => 21]);
        $this->aiPicks();

        $report = $this->run_();

        foreach ($this->students as $key => $student) {
            $row = $this->row($key, 'Communication');

            $this->assertTrue($row->insufficient_data, $key);
            $this->assertTrue($row->needs_input, $key);
            $this->assertNull($row->baseline_level_id, $key);
            $this->assertNull($row->ai_level_id, $key);
            $this->assertNull($row->suggestedLevelId(), $key);
            $this->assertNull($row->sourceLabel(), $key);
            $this->assertSame(self::REASON, $row->needs_input_reason, $key);
            $this->assertSame('none', $row->confidence, $key);
        }

        $this->assertSame(4, $report->insufficient);
    }

    public function test_the_model_is_never_asked_about_an_insufficient_criterion(): void
    {
        $this->minimums(['communication' => 99, 'collaboration' => 99]);
        $this->aiPicks();

        $this->run_();

        $comm = $this->criterion('Communication')->id;
        $collab = $this->criterion('Collaboration')->id;

        $sent = FakeDriver::sent();
        $this->assertCount(3, $sent, 's3 has nothing left to ask about, so three requests.');

        foreach ($sent as $call) {
            $payload = json_decode($call['payload'], true);
            $asked = array_column($payload['criteria'], 'id');

            $this->assertNotContains($comm, $asked);
            $this->assertNotContains($collab, $asked);
            $this->assertStringNotContainsString('Communication', json_encode($payload['criteria']));
        }
    }

    public function test_when_every_criterion_is_insufficient_the_provider_is_not_called_at_all(): void
    {
        $this->minimums(['task_completion' => 99, 'reliability' => 99, 'communication' => 99, 'collaboration' => 99]);
        $this->aiPicks();

        $report = $this->run_();

        $this->assertSame([], FakeDriver::sent());
        $this->assertSame(0, $report->requests);
        $this->assertSame(16, $report->insufficient, 'Four evidence criteria for four students.');
    }

    public function test_the_model_is_told_in_plain_flags_which_types_have_too_little(): void
    {
        $this->minimums(['communication' => 99]);
        $this->aiPicks();

        $this->run_();

        $payload = json_decode(FakeDriver::sent()[0]['payload'], true);

        $this->assertSame(
            ['task_completion' => false, 'reliability' => false, 'communication' => true, 'collaboration' => false],
            $payload['insufficient_data'],
        );
        $this->assertContains(false, array_column($payload['criteria'], 'insufficient_data'));
        $this->assertNotContains(true, array_column($payload['criteria'], 'insufficient_data'), 'Those that would be true are not sent.');
        $this->assertStringContainsString('insufficient_data is true', FakeDriver::sent()[0]['system']);
        $this->assertStringContainsString('Never rate from it', FakeDriver::sent()[0]['system']);
    }

    public function test_the_other_criteria_in_the_same_request_are_unaffected(): void
    {
        $this->minimums(['communication' => 99]);
        $this->aiPicks();

        $this->run_();

        $contribution = $this->row('s1', 'Contribution to tasks');
        $this->assertFalse($contribution->insufficient_data);
        $this->assertNotNull($contribution->baseline_level_id);
        $this->assertNotNull($contribution->ai_level_id);
        $this->assertSame('normal', $contribution->confidence);
        $this->assertSame('ok', $contribution->ai_status);

        $this->assertNotNull($this->row('s1', 'Reliability and deadlines')->ai_level_id);
        $this->assertNotNull($this->row('s1', 'Collaboration')->ai_level_id);
    }

    public function test_an_answer_for_an_insufficient_criterion_is_ignored_and_other_ids_are_still_checked(): void
    {
        $this->minimums(['communication' => 99]);

        $comm = $this->criterion('Communication');
        $foreign = $this->criterion('Collaboration')->levels->first()->id;
        $task = $this->criterion('Contribution to tasks')->id;

        // The model volunteers a rating for the criterion it was not asked about, and
        // gives another criterion a level that belongs elsewhere.
        $this->aiPicks(function (array $items) use ($comm, $foreign, $task) {
            foreach ($items as &$item) {
                if ($item['criterion'] === $task) {
                    $item['level'] = $foreign;
                }
            }
            $items[] = ['criterion' => $comm->id, 'level' => $comm->levels->last()->id, 'rationale' => 'Volunteered.'];

            return $items;
        });

        $this->run_();

        $volunteered = $this->row('s1', 'Communication');
        $this->assertNull($volunteered->ai_level_id, 'Never filled from the model.');
        $this->assertTrue($volunteered->insufficient_data);

        $rejected = $this->row('s1', 'Contribution to tasks');
        $this->assertNull($rejected->ai_level_id);
        $this->assertSame('invalid', $rejected->ai_status);
        $this->assertNotNull($rejected->baseline_level_id, 'Falls back to its own baseline.');
    }

    public function test_a_mixed_rubric_still_scores_the_sufficient_criteria(): void
    {
        // Collaboration (5 recorded) is below 6; everything else is fine.
        $this->minimums(['collaboration' => 6, 'communication' => 6, 'task_completion' => 4, 'reliability' => 4]);
        $this->aiPicks();

        $report = $this->run_();

        $this->assertTrue($this->row('s1', 'Collaboration')->insufficient_data);
        $this->assertNull($this->row('s1', 'Collaboration')->baseline_level_id);

        foreach (['Contribution to tasks', 'Reliability and deadlines', 'Communication'] as $name) {
            $row = $this->row('s1', $name);
            $this->assertFalse($row->insufficient_data, $name);
            $this->assertNotNull($row->baseline_level_id, $name);
            $this->assertNotNull($row->ai_level_id, $name);
        }

        $this->assertTrue($this->row('s1', 'Initiative and problem solving')->needs_input);
        $this->assertFalse($this->row('s1', 'Initiative and problem solving')->insufficient_data, 'No evidence type is a different reason.');
        $this->assertSame(4, $report->insufficient);
    }

    public function test_manual_criteria_are_unaffected_by_the_guard(): void
    {
        $this->minimums(['task_completion' => 99, 'reliability' => 99, 'communication' => 99, 'collaboration' => 99]);
        $this->aiPicks();

        $this->run_();

        $manual = $this->rubric->sections[0]->criteria->pluck('id');   // the report-quality section

        $this->assertSame(0, MarkSuggestion::whereIn('rubric_criterion_id', $manual)->count());
        $this->assertSame(0, MarkSuggestion::where('insufficient_data', true)->whereIn('rubric_criterion_id', $manual)->count());
    }

    public function test_without_the_guard_nothing_changes(): void
    {
        $this->minimums([]);
        $this->aiPicks();

        $report = $this->run_();

        $this->assertSame(0, $report->insufficient);
        $this->assertSame(0, MarkSuggestion::where('insufficient_data', true)->count());
        $this->assertSame(4, $report->requests);
    }

    /*
    | Totals: undecided is not zero
    */

    protected function suggestionsFor(string $student): \Illuminate\Support\Collection
    {
        return MarkSuggestion::where('student_id', $this->students[$student]->id)->get();
    }

    public function test_a_section_with_an_insufficient_criterion_has_no_total_not_zero(): void
    {
        $this->minimums(['collaboration' => 6]);
        $this->aiPicks();
        $this->run_();

        $clo4 = $this->rubric->sections[1]->fresh();
        $totals = app(SectionTotals::class)->for($clo4, $this->suggestionsFor('s1'));

        $this->assertFalse($totals['complete']);
        $this->assertNull($totals['total'], 'There is no total yet, which is not the same as 0.');
        $this->assertSame(5, $totals['criteria']);
        $this->assertSame(3, $totals['decided']);
        $this->assertSame(2, $totals['undecided'], 'Collaboration (too little data) and Initiative (no evidence).');
        $this->assertGreaterThan(0, $totals['so_far']);
        $this->assertSame(10.0, $totals['maximum']);
    }

    public function test_marks_so_far_come_from_the_formula_and_ignore_the_undecided(): void
    {
        $this->minimums(['collaboration' => 6]);
        $this->run_();   // no AI: baseline levels

        $clo4 = $this->rubric->sections[1]->fresh();
        $rows = $this->suggestionsFor('s1');
        $expected = 0.0;

        foreach ($clo4->criteria as $criterion) {
            $row = $rows->firstWhere('rubric_criterion_id', $criterion->id);

            if ($row?->suggestedLevelId()) {
                $expected += $clo4->marksFor($criterion, $criterion->levels->firstWhere('id', $row->suggestedLevelId()));
            }
        }

        $this->assertEqualsWithDelta($expected, app(SectionTotals::class)->for($clo4, $rows)['so_far'], 0.001);
    }

    public function test_the_total_appears_only_when_every_criterion_has_a_level(): void
    {
        $clo4 = $this->rubric->sections[1]->fresh();

        foreach ($clo4->criteria as $criterion) {
            MarkSuggestion::create([
                'rubric_criterion_id' => $criterion->id, 'group_id' => $this->group->id, 'student_id' => $this->students['s1']->id,
                'evidence_type' => 'none', 'baseline_level_id' => $criterion->levels->sortBy('marks')->last()->id, 'facts' => [],
            ]);
        }

        $totals = app(SectionTotals::class)->for($clo4->fresh(), $this->suggestionsFor('s1'));

        $this->assertTrue($totals['complete']);
        $this->assertSame(10.0, $totals['total'], 'Excellent on all five criteria, level only: 10, not 20.');
        $this->assertSame(0, $totals['undecided']);

        // Take one level away: it is undecided again, and the total goes with it.
        MarkSuggestion::where('rubric_criterion_id', $clo4->criteria->first()->id)->update(['baseline_level_id' => null]);

        $again = app(SectionTotals::class)->for($clo4->fresh(), $this->suggestionsFor('s1'));

        $this->assertFalse($again['complete']);
        $this->assertNull($again['total']);
        $this->assertSame(8.0, $again['so_far']);
    }

    public function test_a_manual_section_is_wholly_undecided(): void
    {
        $totals = app(SectionTotals::class)->for($this->rubric->sections[0]->fresh(), collect());

        $this->assertSame(0, $totals['decided']);
        $this->assertSame(7, $totals['undecided']);
        $this->assertNull($totals['total']);
        $this->assertFalse($totals['complete']);
    }

    public function test_a_section_without_a_chosen_formula_gives_no_marks_at_all(): void
    {
        $clo4 = $this->rubric->sections[1];
        $clo4->update(['scoring' => null]);

        $this->run_();
        $totals = app(SectionTotals::class)->for($clo4->fresh(), $this->suggestionsFor('s1'));

        $this->assertFalse($totals['formula_chosen']);
        $this->assertSame(0, $totals['decided']);
        $this->assertNull($totals['total']);
    }

    /*
    | marking:preview
    */

    public function test_the_preview_says_insufficient_data_and_shows_the_section_as_incomplete(): void
    {
        $this->minimums(['communication' => 21, 'collaboration' => 6]);

        Artisan::call('marking:preview', ['rubric' => $this->rubric->id, 'group' => $this->group->id, '--no-ai' => true]);
        $out = Artisan::output();

        $this->assertStringContainsString('insufficient data: '.self::REASON, $out);
        $this->assertStringNotContainsString('Rule-based : —', $out);

        // For Communication and Collaboration there is no rule-based line at all, only the reason.
        preg_match('/Communication  \[Communication\]\R(.*?)\R  \S/s', $out, $block);
        $this->assertStringNotContainsString('Rule-based', $block[1] ?? '');
        $this->assertStringContainsString('insufficient data', $block[1] ?? '');

        $this->assertStringContainsString('Total, CLO4 – Teamwork (10 marks): incomplete', $out);
        $this->assertStringContainsString('undecided until you choose a level', $out);
        $this->assertStringNotContainsString('marks if these suggestions are taken', $out, 'No student has a finished total here.');
        $this->assertStringContainsString('insufficient data 8', $out, 'Two criteria for four students in the summary.');
    }

    public function test_the_preview_does_not_show_a_level_for_an_insufficient_criterion_even_with_the_ai_on(): void
    {
        $this->minimums(['communication' => 99]);
        $this->aiPicks();

        Artisan::call('marking:preview', ['rubric' => $this->rubric->id, 'group' => $this->group->id]);
        $out = Artisan::output();

        preg_match_all('/Communication  \[Communication\]\R(.*?)\R  \S/s', $out, $blocks);

        $this->assertCount(4, $blocks[1]);

        foreach ($blocks[1] as $block) {
            $this->assertStringContainsString('insufficient data: '.self::REASON, $block);
            $this->assertStringNotContainsString('AI-suggested', $block);
            $this->assertStringNotContainsString('level ', $block);
        }
    }

    public function test_a_normal_group_previews_exactly_as_before(): void
    {
        $this->minimums([]);

        Artisan::call('marking:preview', ['rubric' => $this->rubric->id, 'group' => $this->group->id, '--no-ai' => true]);
        $out = Artisan::output();

        $this->assertStringNotContainsString('insufficient data:', $out);
        $this->assertStringContainsString('Rule-based :', $out);
        $this->assertStringContainsString('insufficient data 0', $out);
    }
}
