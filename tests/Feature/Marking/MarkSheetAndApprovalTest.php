<?php

namespace Tests\Feature\Marking;

use App\Models\MarkApproval;
use App\Models\MarkChoice;
use App\Models\MarkSuggestion;
use App\Support\Ai\AiManager;
use App\Support\Ai\Drivers\FakeDriver;
use App\Support\Marking\ApprovalService;
use App\Support\Marking\MarkingException;
use App\Support\Marking\MarkSheet;
use App\Support\Marking\SuggestionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Sleep;
use Tests\Concerns\BuildsMarkingFixture;
use Tests\TestCase;

/**
 * The marking sheet: what counts as decided, the totals, scaling, the choices a
 * lecturer records and the approval that freezes them.
 *
 * The fixture rubric is the sample: CLO2 (seven Manual criteria, level × weight,
 * 30 marks) and CLO4 (five System-suggested criteria, level only, 10 marks).
 */
class MarkSheetAndApprovalTest extends TestCase
{
    use BuildsMarkingFixture;
    use RefreshDatabase;

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

    protected function sheet(string $student = 's1', ?\App\Models\Rubric $rubric = null): array
    {
        return app(MarkSheet::class)->build(($rubric ?? $this->rubric)->fresh(), $this->group->fresh(), $this->students[$student]);
    }

    protected function section(array $sheet, string $titleStart): array
    {
        return collect($sheet['sections'])->first(fn ($s) => str_starts_with($s['section']->title, $titleStart));
    }

    protected function suggest(?int $position = null): void
    {
        FakeDriver::respondWith(fn ($payload) => ['suggestions' => array_map(fn ($c) => [
            'criterion' => $c['id'],
            'level' => collect($c['levels'])->sortBy('marks')->values()[min($position ?? 2, count($c['levels']) - 1)]['id'],
            'rationale' => $this->cite($c),
        ], $payload['criteria'])]);

        app(SuggestionService::class)->run($this->lecturer, $this->rubric->fresh(), $this->group->fresh(), true);
    }

    /*
    | Incomplete until every criterion has a level
    */

    public function test_a_fresh_sheet_is_incomplete_with_no_total_and_nothing_so_far(): void
    {
        $sheet = $this->sheet();

        $this->assertFalse($sheet['complete']);
        $this->assertNull($sheet['total']);
        $this->assertSame(0.0, $sheet['so_far']);
        $this->assertSame(0, $sheet['decided']);
        $this->assertSame(12, $sheet['criteria']);
        $this->assertCount(2, $sheet['blockers']);
    }

    public function test_manual_criteria_count_towards_completeness_not_just_suggested_ones(): void
    {
        // Every System-suggested criterion decided; the Manual section left alone.
        $this->choose('s1', array_fill_keys(['Contribution to tasks', 'Reliability and deadlines', 'Communication', 'Collaboration', 'Initiative and problem solving'], 'Excellent'));

        $sheet = $this->sheet();

        $this->assertTrue($this->section($sheet, 'CLO4')['complete']);
        $this->assertSame(10.0, $this->section($sheet, 'CLO4')['total']);

        $this->assertFalse($this->section($sheet, 'CLO2')['complete']);
        $this->assertNull($this->section($sheet, 'CLO2')['total']);
        $this->assertFalse($sheet['complete'], 'One finished section does not finish the sheet.');
        $this->assertNull($sheet['total']);
        $this->assertSame(10.0, $sheet['so_far']);
    }

    public function test_one_manual_criterion_left_undecided_keeps_the_section_incomplete(): void
    {
        $this->chooseEverything('s1');
        $this->choose('s1', ['Testing' => null]);   // clears one of the seven

        $clo2 = $this->section($this->sheet(), 'CLO2');

        $this->assertFalse($clo2['complete']);
        $this->assertSame(1, $clo2['undecided']);
        $this->assertNull($clo2['total'], 'No total, which is not the same as a total of 24.');
        $this->assertEqualsWithDelta(30 - 0.6 * 5, $clo2['so_far'], 0.001, 'Marks so far leave out the undecided criterion.');
        $this->assertStringContainsString('1 criterion still needs a level', implode(' ', $clo2['blockers']));
    }

    public function test_when_everything_is_chosen_the_total_is_the_sum_of_both_sections(): void
    {
        $this->chooseEverything('s1');

        $sheet = $this->sheet();

        $this->assertTrue($sheet['complete']);
        $this->assertSame(30.0, $this->section($sheet, 'CLO2')['total'], 'Excellent on all seven, level × weight: 6.0 × 5.');
        $this->assertSame(10.0, $this->section($sheet, 'CLO4')['total'], 'Excellent on all five, level only: 10, not 20.');
        $this->assertSame(40.0, $sheet['total']);
        $this->assertSame([], $sheet['blockers']);
    }

    public function test_marks_are_computed_by_each_sections_own_formula(): void
    {
        $this->choose('s1', ['Design' => 'Good', 'Testing' => 'Fair', 'Collaboration' => 'Good', 'Communication' => 'Poor']);

        $rows = collect($this->sheet()['sections'])->flatMap(fn ($s) => $s['rows'])->keyBy(fn ($r) => $r['criterion']->name);

        $this->assertEqualsWithDelta(1.6 * 3, $rows['Design']['marks'], 0.001, 'Level × weight.');
        $this->assertEqualsWithDelta(0.6 * 2, $rows['Testing']['marks'], 0.001);
        $this->assertEqualsWithDelta(1.5, $rows['Collaboration']['marks'], 0.001, 'Level only: the weight is not multiplied in.');
        $this->assertEqualsWithDelta(0.5, $rows['Communication']['marks'], 0.001);
    }

    public function test_a_section_with_no_formula_chosen_cannot_be_completed(): void
    {
        $this->rubric->sections[1]->update(['scoring' => null]);
        $this->chooseEverything('s1');

        $clo4 = $this->section($this->sheet(), 'CLO4');

        $this->assertFalse($clo4['complete']);
        $this->assertNull($clo4['total']);
        $this->assertSame(0.0, $clo4['so_far'], 'No formula, so no marks to add up.');
        $this->assertStringContainsString('choose how its marks are calculated', implode(' ', $clo4['blockers']));
    }

    /*
    | Suggestions: side by side, never preselecting the AI
    */

    public function test_the_rule_based_level_is_preselected_and_the_ai_level_never_is(): void
    {
        $this->suggest(position: 3);   // the AI says the top level everywhere

        $row = collect($this->sheet('s1')['sections'])->flatMap(fn ($s) => $s['rows'])->first(fn ($r) => $r['criterion']->name === 'Contribution to tasks');

        $this->assertNotNull($row['baseline_level']);
        $this->assertNotNull($row['ai_level']);
        $this->assertNotSame($row['baseline_level']->id, $row['ai_level']->id);

        $this->assertSame($row['baseline_level']->id, $row['preselect_id'], 'The rule-based level is the one put forward.');
        $this->assertSame($row['baseline_level']->id, $row['selected_id']);
        $this->assertNull($row['level'], 'Preselected is not chosen: nothing counts until it is saved.');
        $this->assertNull($row['marks']);
    }

    public function test_nothing_is_preselected_when_confidence_is_none_or_there_is_no_suggestion(): void
    {
        config(['marking.min_group_activity.communication' => 99]);
        $this->suggest();

        $rows = collect($this->sheet('s1')['sections'])->flatMap(fn ($s) => $s['rows'])->keyBy(fn ($r) => $r['criterion']->name);

        $this->assertNull($rows['Communication']['preselect_id'], 'Insufficient data has confidence none.');
        $this->assertNull($rows['Communication']['selected_id']);
        $this->assertTrue($rows['Communication']['insufficient']);
        $this->assertNull($rows['Initiative and problem solving']['preselect_id'], 'No evidence type: nothing to preselect.');
        $this->assertNull($rows['Design']['preselect_id'], 'A Manual criterion has no suggestion.');
        $this->assertNotNull($rows['Contribution to tasks']['preselect_id']);
    }

    public function test_an_insufficient_data_criterion_is_undecided_with_its_reason_until_the_lecturer_chooses(): void
    {
        config(['marking.min_group_activity.communication' => 99]);
        $this->suggest();

        $row = fn () => collect($this->sheet('s1')['sections'])->flatMap(fn ($s) => $s['rows'])->first(fn ($r) => $r['criterion']->name === 'Communication');

        $this->assertNull($row()['level']);
        $this->assertSame('There is too little activity in this group to compare students.', $row()['undecided_reason']);

        // It blocks completeness like any other undecided criterion.
        $this->chooseEverything('s1');
        $this->choose('s1', ['Communication' => null]);
        $this->assertFalse($this->section($this->sheet('s1'), 'CLO4')['complete']);

        $this->choose('s1', ['Communication' => 'Good']);
        $this->assertTrue($this->section($this->sheet('s1'), 'CLO4')['complete']);
        $this->assertSame('lecturer', MarkChoice::where('rubric_criterion_id', $this->criterion('Communication')->id)->first()->source);
    }

    /*
    | Mixed sections: add, or scale
    */

    /** CLO4 with its last criterion made Manual: four suggested (max 8) and one manual (max 2). */
    protected function mixedRubric(): \App\Models\Rubric
    {
        return $this->saveSampleRubric(function ($data) {
            $data['sections'][1]['criteria'][4]['mode'] = 'manual';

            return $data;
        });
    }

    public function test_a_mixed_level_only_section_defaults_to_add_and_says_so(): void
    {
        $rubric = $this->mixedRubric();
        $this->chooseEverything('s1', 'Excellent', $rubric);

        $clo4 = $this->section($this->sheet('s1', $rubric), 'CLO4');

        $this->assertTrue($clo4['mixed']);
        $this->assertSame('add', $clo4['method'], 'Level marks alone put both kinds on one scale.');
        $this->assertTrue($clo4['method_defaulted']);
        $this->assertTrue($clo4['complete']);
        $this->assertSame(5, $clo4['criteria_count'], 'Add counts the Manual criterion too.');
    }

    public function test_a_choice_of_scale_replaces_the_default_and_is_not_marked_as_defaulted(): void
    {
        $rubric = $this->mixedRubric();
        $sectionId = $rubric->sections[1]->id;
        $this->chooseEverything('s1', 'Excellent', $rubric, [$sectionId => 'scale']);

        $clo4 = $this->section($this->sheet('s1', $rubric), 'CLO4');

        $this->assertSame('scale', $clo4['method']);
        $this->assertFalse($clo4['method_defaulted']);
    }

    public function test_a_mixed_section_that_multiplies_by_weight_still_needs_a_method_chosen(): void
    {
        $rubric = $this->saveSampleRubric(function ($data) {
            $data['sections'][0]['criteria'][0]['mode'] = 'suggested';
            $data['sections'][0]['criteria'][0]['evidence_type'] = 'task_completion';

            return $data;
        });
        $this->chooseEverything('s1', 'Excellent', $rubric);

        $clo2 = $this->section($this->sheet('s1', $rubric), 'CLO2');

        $this->assertTrue($clo2['mixed']);
        $this->assertNull($clo2['method']);
        $this->assertFalse($clo2['method_defaulted']);
        $this->assertFalse($clo2['complete']);
        $this->assertStringContainsString('choose how this section is totalled', implode(' ', $clo2['blockers']));
    }

    public function test_the_sheet_carries_the_rubrics_full_marks(): void
    {
        $sheet = $this->sheet('s1', $this->mixedRubric());

        $this->assertSame(40.0, (float) $sheet['maximum']);
    }
    public function test_add_counts_every_criterion_including_the_manual_one(): void
    {
        $rubric = $this->mixedRubric();
        $sectionId = $rubric->sections[1]->id;

        $this->chooseEverything('s1', 'Excellent', $rubric, [$sectionId => 'add']);
        $this->choose('s1', ['Initiative and problem solving' => 'Poor'], [], [], $rubric);   // 0.5

        $clo4 = $this->section($this->sheet('s1', $rubric), 'CLO4');

        $this->assertTrue($clo4['complete']);
        $this->assertSame('add', $clo4['method']);
        $this->assertNull($clo4['scaling']);
        $this->assertSame(8.5, $clo4['total'], 'Four at 2.0 and the manual one at 0.5.');
    }

    public function test_scale_scores_only_the_suggested_criteria_and_scales_to_the_sections_full_marks(): void
    {
        $rubric = $this->mixedRubric();
        $sectionId = $rubric->sections[1]->id;

        // Four suggested at Good (1.5 each = 6 of a possible 8), the manual one at Poor.
        $this->chooseEverything('s1', 'Good', $rubric, [$sectionId => 'scale']);
        $this->choose('s1', ['Initiative and problem solving' => 'Poor'], [], [], $rubric);

        $clo4 = $this->section($this->sheet('s1', $rubric), 'CLO4');

        $this->assertTrue($clo4['complete']);
        $this->assertSame('scale', $clo4['method']);
        $this->assertSame(
            ['suggested_marks' => 6.0, 'suggested_maximum' => 8.0, 'section_maximum' => 10.0, 'factor' => 1.25],
            $clo4['scaling'],
        );
        $this->assertSame(7.5, $clo4['total'], '6 of 8, scaled to 10.');
    }

    public function test_scaling_ignores_what_the_manual_criterion_was_given(): void
    {
        $rubric = $this->mixedRubric();
        $sectionId = $rubric->sections[1]->id;

        $this->chooseEverything('s1', 'Excellent', $rubric, [$sectionId => 'scale']);

        $withPoor = $this->choose('s1', ['Initiative and problem solving' => 'Poor'], [], [], $rubric);
        $this->assertSame([], $withPoor);
        $poor = $this->section($this->sheet('s1', $rubric), 'CLO4')['total'];

        $this->choose('s1', ['Initiative and problem solving' => 'Excellent'], [], [], $rubric);
        $excellent = $this->section($this->sheet('s1', $rubric), 'CLO4')['total'];

        $this->assertSame(10.0, $poor);
        $this->assertSame(10.0, $excellent);
    }

    public function test_scale_does_not_need_the_manual_criterion_and_it_never_blocks(): void
    {
        $rubric = $this->mixedRubric();
        $sectionId = $rubric->sections[1]->id;

        $this->choose('s1', array_fill_keys(['Contribution to tasks', 'Reliability and deadlines', 'Communication', 'Collaboration'], 'Excellent'), [], [$sectionId => 'scale'], $rubric);

        $clo4 = $this->section($this->sheet('s1', $rubric), 'CLO4');

        $this->assertTrue($clo4['complete'], 'The manual criterion is not counted, so it does not hold the section up.');
        $this->assertSame(10.0, $clo4['total']);
        $this->assertSame(4, $clo4['criteria_count'], 'Only the System-suggested criteria are counted.');
        $this->assertFalse(collect($clo4['rows'])->firstWhere(fn ($r) => $r['criterion']->name === 'Initiative and problem solving')['counts']);
    }

    public function test_an_unfinished_scaled_section_has_no_so_far_figure(): void
    {
        $rubric = $this->mixedRubric();
        $sectionId = $rubric->sections[1]->id;

        $this->choose('s1', ['Contribution to tasks' => 'Excellent', 'Reliability and deadlines' => 'Excellent'], [], [$sectionId => 'scale'], $rubric);

        $sheet = $this->sheet('s1', $rubric);
        $clo4 = $this->section($sheet, 'CLO4');

        $this->assertFalse($clo4['complete']);
        $this->assertNull($clo4['so_far'], 'A part-way scaled figure would look nearly final.');
        $this->assertSame(2, $clo4['undecided']);
        $this->assertGreaterThanOrEqual(1, $sheet['scaled_pending']);
    }

    public function test_add_still_needs_the_manual_criterion(): void
    {
        $rubric = $this->mixedRubric();
        $sectionId = $rubric->sections[1]->id;

        $this->choose('s1', array_fill_keys(['Contribution to tasks', 'Reliability and deadlines', 'Communication', 'Collaboration'], 'Excellent'), [], [$sectionId => 'add'], $rubric);

        $clo4 = $this->section($this->sheet('s1', $rubric), 'CLO4');

        $this->assertFalse($clo4['complete']);
        $this->assertSame(5, $clo4['criteria_count']);
    }

    public function test_a_method_is_ignored_for_a_section_that_is_not_mixed(): void
    {
        $sectionId = $this->rubric->sections[1]->id;   // all System-suggested

        $this->choose('s1', ['Collaboration' => 'Good'], [], [$sectionId => 'scale']);

        $this->assertSame(0, \App\Models\MarkSectionMethod::count());
        $this->assertNull($this->section($this->sheet(), 'CLO4')['method']);
    }

    /*
    | Recording choices
    */

    public function test_accepting_a_suggestion_is_recorded_as_such_and_needs_no_reason(): void
    {
        $this->suggest(position: 3);

        $suggestion = MarkSuggestion::where('student_id', $this->students['s1']->id)->where('rubric_criterion_id', $this->criterion('Contribution to tasks')->id)->first();

        $levels = [
            $this->criterion('Contribution to tasks')->id => $suggestion->baseline_level_id,
            $this->criterion('Communication')->id => MarkSuggestion::where('student_id', $this->students['s1']->id)->where('rubric_criterion_id', $this->criterion('Communication')->id)->first()->ai_level_id,
        ];

        $this->assertSame([], app(\App\Support\Marking\ChoiceRecorder::class)->save($this->lecturer, $this->rubric, $this->group, $this->students['s1'], $levels));

        $this->assertSame('rule_based', MarkChoice::where('rubric_criterion_id', $this->criterion('Contribution to tasks')->id)->first()->source);
        $this->assertSame('ai', MarkChoice::where('rubric_criterion_id', $this->criterion('Communication')->id)->first()->source);
        $this->assertNull(MarkChoice::first()->override_reason);
    }

    public function test_overriding_a_suggestion_requires_and_stores_a_reason(): void
    {
        $this->suggest(position: 2);

        // Neither suggestion is level "Poor" for s1's contribution.
        $errors = $this->choose('s1', ['Contribution to tasks' => 'Poor']);

        $this->assertArrayHasKey('reasons.'.$this->criterion('Contribution to tasks')->id, $errors);
        $this->assertSame(0, MarkChoice::count(), 'A refused save writes nothing.');

        $this->assertSame([], $this->choose('s1', ['Contribution to tasks' => 'Poor'], ['Contribution to tasks' => 'Their tasks were cancelled by the client.']));

        $choice = MarkChoice::first();
        $this->assertSame('lecturer', $choice->source);
        $this->assertSame('Their tasks were cancelled by the client.', $choice->override_reason);
        $this->assertNotNull($choice->baseline_level_id, 'The suggestions at that moment are kept with the choice.');
        $this->assertNotNull($choice->ai_level_id);
        $this->assertSame($this->lecturer->id, $choice->chosen_by);
    }

    public function test_a_criterion_with_nothing_suggested_needs_no_reason(): void
    {
        $this->assertSame([], $this->choose('s1', ['Design' => 'Fair', 'Initiative and problem solving' => 'Good']));

        $this->assertSame(2, MarkChoice::where('source', 'lecturer')->count());
    }

    public function test_a_level_from_another_criterion_is_refused_and_nothing_at_all_is_saved(): void
    {
        $foreign = $this->criterion('Design')->levels->first()->id;

        $errors = app(\App\Support\Marking\ChoiceRecorder::class)->save($this->lecturer, $this->rubric, $this->group, $this->students['s1'], [
            $this->criterion('Testing')->id => $this->levelId('Testing', 'Good'),
            $this->criterion('Implementation')->id => $foreign,
        ]);

        $this->assertArrayHasKey('levels.'.$this->criterion('Implementation')->id, $errors);
        $this->assertSame(0, MarkChoice::count(), 'The valid choice is not saved on its own.');
    }

    public function test_a_criterion_from_another_rubric_is_ignored(): void
    {
        $other = $this->saveSampleRubric();

        $this->assertSame([], app(\App\Support\Marking\ChoiceRecorder::class)->save($this->lecturer, $this->rubric, $this->group, $this->students['s1'], [
            $this->criterion('Testing', $other)->id => $this->levelId('Testing', 'Good', $other),
        ]));

        $this->assertSame(0, MarkChoice::count());
    }

    public function test_choices_can_be_changed_and_cleared_before_approval(): void
    {
        $this->choose('s1', ['Design' => 'Poor']);
        $this->choose('s1', ['Design' => 'Excellent']);

        $this->assertSame(1, MarkChoice::count());
        $this->assertSame($this->levelId('Design', 'Excellent'), MarkChoice::first()->rubric_level_id);

        $this->choose('s1', ['Design' => null]);
        $this->assertSame(0, MarkChoice::count());
    }

    /*
    | Approval
    */

    public function test_approval_is_refused_until_the_sheet_is_complete_and_says_what_is_missing(): void
    {
        $this->choose('s1', ['Design' => 'Good']);

        try {
            app(ApprovalService::class)->approve($this->lecturer, $this->rubric, $this->group, $this->students['s1']);
            $this->fail('An incomplete sheet was approved.');
        } catch (MarkingException $e) {
            $this->assertCount(2, $e->blockers);
            $this->assertStringContainsString('still need a level', implode(' ', $e->blockers));
        }

        $this->assertSame(0, MarkApproval::count());
        $this->assertFalse($this->rubric->fresh()->isLocked());
    }

    public function test_an_insufficient_data_criterion_must_be_chosen_before_approval(): void
    {
        config(['marking.min_group_activity.communication' => 99]);
        $this->suggest();

        $this->chooseEverything('s1');
        $this->choose('s1', ['Communication' => null]);

        $this->expectException(MarkingException::class);

        app(ApprovalService::class)->approve($this->lecturer, $this->rubric, $this->group, $this->students['s1']);
    }

    public function test_approving_a_complete_sheet_freezes_it(): void
    {
        $this->chooseEverything('s1');

        $approval = app(ApprovalService::class)->approve($this->lecturer, $this->rubric, $this->group, $this->students['s1']);

        $this->assertSame('40.00', $approval->total);
        $this->assertSame($this->lecturer->id, $approval->approved_by);
        $this->assertTrue($approval->isActive());
        $this->assertTrue($this->rubric->fresh()->isLocked());

        $snapshot = $approval->snapshot;
        $this->assertEquals(40.0, $snapshot['total']);
        $this->assertCount(2, $snapshot['sections']);
        $this->assertSame('Excellent', $snapshot['sections'][1]['criteria'][0]['level']['label']);
        $this->assertEquals(2.0, $snapshot['sections'][1]['criteria'][0]['marks']);
        $this->assertStringContainsString('the weight is the maximum', $snapshot['sections'][1]['formula_text']);
    }

    public function test_the_approval_keeps_the_suggestions_facts_and_provider_record(): void
    {
        $this->suggest(position: 3);
        $this->chooseEverything('s1');

        $approval = app(ApprovalService::class)->approve($this->lecturer, $this->rubric, $this->group, $this->students['s1']);

        $criterion = collect($approval->snapshot['sections'][1]['criteria'])->firstWhere('name', 'Communication');

        $this->assertNotNull($criterion['baseline']);
        $this->assertNotNull($criterion['ai']);
        $this->assertSame('fake', $criterion['provider']);
        $this->assertSame(10, $criterion['facts']['messages_sent']);
        $this->assertNotEmpty($criterion['ai_rationale']);

        $run = $approval->audit['runs'][0];
        $this->assertSame('fake', $run['provider']);
        $this->assertStringContainsString('SYSTEM', $run['prompt']);
        $this->assertArrayHasKey('response', $run);
    }

    public function test_approved_marks_do_not_change_when_later_activity_is_added(): void
    {
        $this->suggest();
        $this->chooseEverything('s1', 'Good');

        $approval = app(ApprovalService::class)->approve($this->lecturer, $this->rubric, $this->group, $this->students['s1']);
        $before = $approval->fresh()->only(['snapshot', 'total', 'audit']);

        // Much more recorded activity, and the suggestions run again.
        $recorder = app(\App\Support\ActivityRecorder::class);
        for ($i = 0; $i < 30; $i++) {
            $recorder->record($this->students['s3'], $this->group, 'message_sent');
            $recorder->record($this->students['s3'], $this->group, 'document_edited');
        }
        \App\Models\Task::create(['group_id' => $this->group->id, 'created_by' => $this->lecturer->id, 'assigned_to' => $this->students['s1']->id, 'title' => 'Late addition', 'status' => 'todo', 'deadline' => now()->subDay()->toDateString()]);

        $this->suggest(position: 3);

        $this->assertSame($before, $approval->fresh()->only(['snapshot', 'total', 'audit']), 'The approval is exactly as it was.');
        $this->assertSame(10, collect($approval->fresh()->snapshot['sections'][1]['criteria'])->firstWhere('name', 'Communication')['facts']['messages_sent']);
        $this->assertSame(1, collect($approval->fresh()->snapshot['sections'][1]['criteria'])->firstWhere('name', 'Reliability and deadlines')['facts']['tasks_overdue']);
    }

    public function test_an_approved_student_cannot_have_their_choices_changed(): void
    {
        $this->chooseEverything('s1');
        app(ApprovalService::class)->approve($this->lecturer, $this->rubric, $this->group, $this->students['s1']);

        $errors = $this->choose('s1', ['Design' => 'Poor']);

        $this->assertArrayHasKey('approved', $errors);
        $this->assertSame($this->levelId('Design', 'Excellent'), MarkChoice::where('rubric_criterion_id', $this->criterion('Design')->id)->first()->rubric_level_id);
    }

    public function test_an_approval_cannot_be_made_twice(): void
    {
        $this->chooseEverything('s1');
        $service = app(ApprovalService::class);
        $service->approve($this->lecturer, $this->rubric, $this->group, $this->students['s1']);

        $this->expectException(MarkingException::class);
        $service->approve($this->lecturer, $this->rubric, $this->group, $this->students['s1']);
    }

    public function test_revoking_keeps_the_approval_on_record_and_reopens_the_choices(): void
    {
        $this->chooseEverything('s1');
        $service = app(ApprovalService::class);
        $approval = $service->approve($this->lecturer, $this->rubric, $this->group, $this->students['s1']);

        $this->assertTrue($this->rubric->fresh()->isLocked());

        $service->revoke($this->lecturer, $this->rubric, $this->group, $this->students['s1']);

        $this->assertFalse($approval->fresh()->isActive());
        $this->assertSame($this->lecturer->id, $approval->fresh()->revoked_by);
        $this->assertNotNull($approval->fresh()->revoked_at);
        $this->assertSame(1, MarkApproval::count(), 'The revoked approval is kept.');
        $this->assertFalse($this->rubric->fresh()->isLocked());

        $this->assertSame([], $this->choose('s1', ['Design' => 'Poor']));
        $this->assertNotNull($service->approve($this->lecturer, $this->rubric, $this->group, $this->students['s1']));
        $this->assertSame(2, MarkApproval::count());
    }

    public function test_revoking_with_nothing_approved_is_an_error(): void
    {
        $this->expectException(MarkingException::class);

        app(ApprovalService::class)->revoke($this->lecturer, $this->rubric, $this->group, $this->students['s1']);
    }

    public function test_one_students_approval_does_not_affect_another(): void
    {
        $this->chooseEverything('s1');
        $this->chooseEverything('s2');
        app(ApprovalService::class)->approve($this->lecturer, $this->rubric, $this->group, $this->students['s1']);

        $this->assertSame([], $this->choose('s2', ['Design' => 'Fair']));
        $this->assertSame(1, MarkApproval::active()->count());
    }
}
