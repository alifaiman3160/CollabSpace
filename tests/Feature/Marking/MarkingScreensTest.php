<?php

namespace Tests\Feature\Marking;

use App\Models\Group;
use App\Models\MarkApproval;
use App\Models\MarkChoice;
use App\Models\MarkSuggestion;
use App\Models\Subject;
use App\Models\User;
use App\Support\Ai\AiManager;
use App\Support\Ai\Drivers\FakeDriver;
use App\Support\Marking\SuggestionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Sleep;
use Tests\Concerns\BuildsMarkingFixture;
use Tests\TestCase;

/**
 * The marking screens and the routes behind them, as a lecturer uses them.
 */
class MarkingScreensTest extends TestCase
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

    protected function groupUrl(): string
    {
        return route('lecturer.marking.group', [$this->rubric, $this->group]);
    }

    protected function studentUrl(string $student = 's1'): string
    {
        return route('lecturer.marking.student', [$this->rubric, $this->group, $this->students[$student]]);
    }

    protected function page(string $student = 's1'): string
    {
        return $this->actingAs($this->lecturer)->get($this->studentUrl($student))->assertOk()->getContent();
    }

    /** The AI picks the level at $position (0 = lowest) of every criterion it is asked about. */
    protected function aiPicks(int $position = 2): void
    {
        FakeDriver::respondWith(fn ($payload) => ['suggestions' => array_map(fn ($c) => [
            'criterion' => $c['id'],
            'level' => collect($c['levels'])->sortBy('marks')->values()[min($position, count($c['levels']) - 1)]['id'],
            'rationale' => $this->cite($c),
        ], $payload['criteria'])]);
    }

    /** What the group page does: one request for each student in turn. */
    protected function refresh(): void
    {
        foreach ($this->students as $student) {
            $this->actingAs($this->lecturer)->postJson(route('lecturer.marking.suggest', [$this->rubric, $this->group, $student]))->assertOk();
        }
    }

    /** The form fields that choose $label on every criterion, as the screen posts them. */
    protected function everything(string $label = 'Excellent'): array
    {
        $levels = [];
        $reasons = [];

        foreach ($this->rubric->fresh()->sections->flatMap->criteria as $criterion) {
            $levels[$criterion->id] = $criterion->levels->firstWhere('label', $label)->id;
            $reasons[$criterion->id] = 'Chosen by the lecturer.';
        }

        return ['levels' => $levels, 'reasons' => $reasons];
    }

    protected function saveEverything(string $student = 's1', string $label = 'Excellent')
    {
        return $this->actingAs($this->lecturer)->post($this->studentUrl($student), $this->everything($label));
    }

    protected function tag(string $html, string $needle): string
    {
        preg_match('/<button[^>]*'.preg_quote($needle, '/').'[^>]*>/', $html, $m);

        return $m[0] ?? '';
    }

    /** One criterion's card on the student page. */
    protected function card(string $html, string $criterion): string
    {
        preg_match('/<article[^>]*data-criterion-id="'.$this->criterion($criterion)->id.'".*?<\/article>/s', $html, $m);

        return $m[0] ?? '';
    }

    /** The level id ticked on a card, or '' when none is. */
    protected function ticked(string $card): string
    {
        preg_match('/<input type="radio"[^>]*value="(\d+)"[^>]*\schecked/s', $card, $m);

        return $m[1] ?? '';
    }
    /*
    | The hub and the group
    */

    public function test_the_hub_lists_rubrics_and_groups_to_mark(): void
    {
        $html = $this->actingAs($this->lecturer)->get(route('lecturer.marking'))->assertOk()->getContent();

        $this->assertStringContainsString('Marking Rubric', $html);
        $this->assertStringContainsString('Version 1', $html);
        $this->assertStringContainsString('Group Falcon', $html);
        $this->assertStringContainsString('0 of 4', $html);
        $this->assertStringContainsString($this->groupUrl(), $html);
        $this->assertStringContainsString(route('lecturer.rubrics', $this->subject), $html);
    }

    public function test_the_hub_says_so_for_a_subject_with_no_rubric(): void
    {
        $second = Subject::create(['lecturer_id' => $this->lecturer->id, 'code' => 'SECV2', 'name' => 'Second Subject', 'group_size' => 4]);
        Group::create(['subject_id' => $second->id, 'name' => 'Group Heron', 'group_size' => 4]);

        $this->actingAs($this->lecturer)->get(route('lecturer.marking'))
            ->assertOk()->assertSee('Upload a rubric')->assertSee('Needs a rubric')->assertSee('Group Heron');
    }

    public function test_marking_is_in_the_lecturers_navigation_and_stays_highlighted_below_it(): void
    {
        foreach ([route('lecturer.marking'), $this->groupUrl(), $this->studentUrl()] as $url) {
            $html = $this->actingAs($this->lecturer)->get($url)->assertOk()->getContent();

            preg_match('/<nav[^>]*aria-label="Main".*?<\/nav>/s', $html, $nav);
            preg_match_all('/<a[^>]*aria-current="page"[^>]*>(.*?)<\/a>/s', $nav[0], $current);

            $this->assertSame(['Marking'], array_map(fn ($c) => trim(preg_replace('/\s+/', ' ', strip_tags($c))), $current[1]), $url);
        }
    }

    public function test_the_group_page_lists_every_student_with_where_they_stand(): void
    {
        $this->saveEverything('s2');
        app(\App\Support\Marking\ApprovalService::class)->approve($this->lecturer, $this->rubric, $this->group, $this->students['s2']);
        $this->choose('s3', ['Design' => 'Good']);

        $html = $this->actingAs($this->lecturer)->get($this->groupUrl())->assertOk()->getContent();

        foreach ($this->students as $student) {
            $this->assertStringContainsString($student->name, $html);
        }

        $this->assertStringContainsString('Approved', $html);
        $this->assertStringContainsString('1 of 12 decided', $html);
        $this->assertStringContainsString('Not started', $html);
        $this->assertStringContainsString(route('lecturer.marking.export', [$this->rubric, $this->group]), $html);
        $this->assertStringContainsString('Refresh suggestions', $html);
    }

    public function test_the_group_page_says_when_ai_is_off(): void
    {
        config(['ai.driver' => 'none']);
        app()->forgetInstance(AiManager::class);
        app()->forgetInstance(\App\Support\Ai\AiGateway::class);

        $this->actingAs($this->lecturer)->get($this->groupUrl())
            ->assertSee('Rule-based suggestions only. AI suggestions are switched off');
    }

    /*
    | A student's sheet: incomplete, and what blocks approval
    */

    public function test_an_incomplete_sheet_says_so_shows_marks_so_far_and_blocks_approval(): void
    {
        $this->choose('s1', ['Design' => 'Good', 'Testing' => 'Fair']);

        $html = $this->page();

        $this->assertStringContainsString('data-status="incomplete"', $html);
        $this->assertStringContainsString('Incomplete: 2 of 12 criteria decided.', $html);
        $this->assertMatchesRegularExpression('/Marks so far: 6( of [\d.]+)?\./', $html);   // 1.6 × 3 + 0.6 × 2
        $this->assertStringContainsString('There is no total, and it cannot be approved', $html);
        $this->assertStringContainsString('Approval is blocked until every criterion has a level', $html);
        $this->assertStringContainsString('disabled="disabled"', $this->tag($html, 'data-approve'), 'The server renders it disabled.');
        $this->assertStringContainsString('dirty || true', $this->tag($html, 'data-approve'));

        // Each section shows incomplete rather than a total.
        $this->assertSame(3, substr_count($html, '>Incomplete<'), 'The overall tile and the two sections.');
        $this->assertStringContainsString('so far', $html);
    }

    public function test_approving_an_incomplete_sheet_is_refused_by_the_server_as_well(): void
    {
        $this->choose('s1', ['Design' => 'Good']);

        $this->actingAs($this->lecturer)->post(route('lecturer.marking.approve', [$this->rubric, $this->group, $this->students['s1']]))
            ->assertSessionHasErrors('status');

        $this->assertSame(0, MarkApproval::count());
    }

    public function test_a_manual_criterion_left_undecided_blocks_approval_even_if_every_suggested_one_is_chosen(): void
    {
        $this->choose('s1', array_fill_keys(['Contribution to tasks', 'Reliability and deadlines', 'Communication', 'Collaboration', 'Initiative and problem solving'], 'Good'));

        $this->actingAs($this->lecturer)->post(route('lecturer.marking.approve', [$this->rubric, $this->group, $this->students['s1']]))
            ->assertSessionHasErrors('status');

        $this->assertSame(0, MarkApproval::count());
        $this->assertStringContainsString('7 criteria still need a level', $this->page());
    }

    public function test_a_complete_sheet_enables_approval_and_shows_the_total(): void
    {
        $this->saveEverything()->assertSessionHasNoErrors();

        $html = $this->page();

        $this->assertStringContainsString('data-status="complete"', $html);
        $this->assertStringContainsString('Every criterion has a level. Total: 40.', $html);
        $this->assertStringNotContainsString('Approval is blocked', $html);
        $this->assertStringNotContainsString('disabled="disabled"', $this->tag($html, 'data-approve'));
        $this->assertStringContainsString('dirty || false', $this->tag($html, 'data-approve'), 'Only unsaved changes hold it back.');
        $this->assertSame(2, substr_count($html, 'data-section-total'));
    }

    /*
    | Suggestions beside the choice
    */

    public function test_both_suggestions_are_shown_side_by_side_with_their_reasons(): void
    {
        $this->aiPicks(position: 2);
        $this->refresh();

        $html = $this->page();

        $this->assertSame(5, substr_count($html, 'data-rule-based'), 'One for each System-suggested criterion.');
        $this->assertSame(5, substr_count($html, 'data-ai>'));
        $this->assertStringContainsString('Completed 3 of 4 assigned tasks (75%).', $html, 'The full rule-based reason is kept, under Why?.');
        $this->assertStringContainsString('Based on the supplied counts', $html, 'And so does the AI reason.');
        $this->assertStringContainsString('Facts:', $html);
        $this->assertStringContainsString('Messages sent 10 (group average 5)', $html);
    }

    public function test_each_criterion_is_one_row_of_level_chips_with_the_marks_in_small_text(): void
    {
        $this->aiPicks(position: 2);
        $this->refresh();

        $html = $this->page();
        $card = $this->card($html, 'Contribution to tasks');

        $this->assertSame(5, substr_count($card, 'data-chip'), 'One chip for each of the rubric\'s five levels.');
        $this->assertMatchesRegularExpression('/<span class="font-bold leading-tight">([A-Za-z ]+)<\/span>\s*<span[^>]*>[\d.]+ marks?<\/span>/', $card);
        $this->assertDoesNotMatchRegularExpression('/(Poor|Good|Excellent) \(\d/', $html, 'No bare number in brackets.');
        $this->assertStringContainsString('role="radiogroup"', $card);
        $this->assertStringNotContainsString('<select', $card, 'Chips, not a select.');
    }

    public function test_the_rule_based_level_is_preselected_and_marked_suggested_and_the_ai_level_is_not(): void
    {
        $this->aiPicks(position: 3);
        $this->refresh();

        $s = MarkSuggestion::where('student_id', $this->students['s1']->id)->where('rubric_criterion_id', $this->criterion('Contribution to tasks')->id)->first();
        $this->assertNotSame($s->baseline_level_id, $s->ai_level_id);

        $card = $this->card($this->page(), 'Contribution to tasks');

        $this->assertSame((string) $s->baseline_level_id, $this->ticked($card), 'The rule-based level is selected.');
        $this->assertNotSame((string) $s->ai_level_id, $this->ticked($card));
        $this->assertSame(1, substr_count($card, 'data-suggested'), 'Exactly one chip says Suggested.');
        $this->assertMatchesRegularExpression('/value="'.$s->baseline_level_id.'"[^>]*checked.*?data-suggested/s', $card, 'And it is the selected one.');
        $this->assertSame(0, MarkChoice::count(), 'Preselecting saves nothing.');
    }

    public function test_one_short_sentence_sits_under_the_name_and_the_long_reason_is_under_why(): void
    {
        $this->aiPicks(position: 2);
        $this->refresh();

        $card = $this->card($this->page(), 'Contribution to tasks');

        $this->assertMatchesRegularExpression('/data-sentence>Finished 3 of 4 assigned tasks\.<\/p>/', $card);
        $this->assertStringContainsString('data-why-toggle', $card);
        $this->assertMatchesRegularExpression('/data-why>.*Completed 3 of 4 assigned tasks \(75%\)\./s', $card, 'The longer wording is in the folded part.');
        $this->assertDoesNotMatchRegularExpression('/data-sentence>[^<]*This places it at level/', $card);
        $this->assertStringContainsString('x-show="why"', $card);
    }

    public function test_when_the_ai_differs_a_small_note_offers_its_level_and_when_they_agree_nothing_extra_shows(): void
    {
        $this->aiPicks(position: 3);
        $this->refresh();

        $differs = $this->card($this->page(), 'Contribution to tasks');
        $ai = MarkSuggestion::where('student_id', $this->students['s1']->id)->where('rubric_criterion_id', $this->criterion('Contribution to tasks')->id)->first();

        $this->assertMatchesRegularExpression('/data-ai-note[^>]*>\s*AI suggests (Poor|Fair|Good|Excellent)\s*</', $differs);
        $this->assertStringContainsString("sel = '{$ai->ai_level_id}'", $differs, 'Clicking it selects the AI level.');
        $this->assertStringNotContainsString('Rule and AI agree', $differs);

        \App\Models\MarkSuggestion::query()->whereNotNull('baseline_level_id')->update(['ai_level_id' => \Illuminate\Support\Facades\DB::raw('baseline_level_id')]);

        $agree = $this->card($this->page(), 'Contribution to tasks');

        $this->assertStringNotContainsString('data-ai-note', $agree);
        $this->assertStringNotContainsString('data-flag', $agree);
        $this->assertStringNotContainsString('Show both', $agree);
    }

    public function test_a_flag_shows_where_the_two_suggestions_differ_by_more_than_one_level(): void
    {
        $this->aiPicks(position: 3);   // the AI always says the top level
        $this->refresh();

        // s4: 0 of 1 tasks completed is the lowest level, against the AI's highest.
        $html = $this->page('s4');

        $this->assertStringContainsString('data-flag', $html);
        $this->assertStringContainsString('More than one level apart', $html);

        $this->aiPicks(position: 2);
        $this->refresh();

        $this->assertStringNotContainsString('data-flag', $this->page('s1'), 'Adjacent levels are not flagged.');
    }

    public function test_insufficient_data_and_manual_criteria_show_the_chips_with_nothing_selected_and_a_calm_line(): void
    {
        config(['marking.min_group_activity.communication' => 99]);
        $this->aiPicks();
        $this->refresh();

        $html = $this->page();

        foreach (['Communication', 'Design'] as $name) {
            $card = $this->card($html, $name);

            $this->assertSame('', $this->ticked($card), "{$name}: nothing preselected.");
            $this->assertStringContainsString('data-you-choose>You choose this one.', $card);
            $this->assertSame(5, substr_count($card, 'data-chip'));
            $this->assertStringNotContainsString('data-suggested', $card);
            $this->assertStringNotContainsString('text-danger', $card, 'Not styled as an error.');
            $this->assertStringNotContainsString('text-warning', $card);
        }

        $this->assertStringContainsString('Not enough recorded activity in this group to compare students', $this->card($html, 'Communication'), 'The reason is under Why?.');
        $this->assertStringContainsString('Not asked: too little group activity to compare students.', $html);
    }

    public function test_a_manual_criterion_has_chips_and_no_suggestions(): void
    {
        $html = $this->page();

        $this->assertSame('', $this->ticked($this->card($html, 'Design')));
        // The five System-suggested criteria have a box under Why?, waiting for suggestions; the seven Manual ones have none.
        $this->assertSame(5, substr_count($html, 'data-rule-based'));
        $this->assertSame(5, substr_count($html, 'No suggestion yet'));
    }

    public function test_the_top_of_the_page_gives_the_totals_the_two_buttons_and_the_neighbours(): void
    {
        $this->saveEverything();

        $html = $this->page('s2');

        $this->assertStringContainsString('data-top', $html);
        $this->assertSame(1, substr_count($html, 'data-overall'));
        $this->assertSame(2, substr_count($html, 'data-tile'), 'One per section.');
        $this->assertMatchesRegularExpression('/data-overall.*?Total.*?of 40/s', $html);
        $this->assertStringContainsString('data-accept', $html);
        $this->assertStringContainsString('data-approve-top', $html);
        $this->assertStringContainsString('data-approve', $html, 'The sticky bar still has its own.');
        $this->assertStringContainsString('Student 2 of 4', $html);
        $this->assertStringContainsString('data-previous', $html);
        $this->assertStringContainsString('data-next', $html);
        $this->assertStringContainsString('data-all-students', $html);
    }

    public function test_the_first_and_last_students_have_only_one_neighbour(): void
    {
        $first = $this->page('s1');
        $last = $this->page('s4');

        $this->assertStringNotContainsString('data-previous', $first);
        $this->assertStringContainsString('data-next', $first);
        $this->assertStringContainsString('data-previous', $last);
        $this->assertStringNotContainsString('data-next', $last);
    }

    /*
    | Accept suggestions
    */

    public function test_accept_suggestions_saves_the_rule_based_level_as_accepted_and_does_not_approve(): void
    {
        $this->aiPicks(position: 2);
        $this->refresh();

        $this->actingAs($this->lecturer)->post(route('lecturer.marking.accept', [$this->rubric, $this->group, $this->students['s1']]))
            ->assertRedirect($this->studentUrl())
            ->assertSessionHas('status');

        $choices = MarkChoice::where('student_id', $this->students['s1']->id)->get();
        $suggested = MarkSuggestion::where('student_id', $this->students['s1']->id)->whereNotNull('baseline_level_id')->where('insufficient_data', false)->count();

        $this->assertSame($suggested, $choices->count(), 'One saved choice for each criterion that had a suggestion.');
        $this->assertTrue($choices->every(fn ($c) => in_array($c->source, [MarkChoice::RULE_BASED, MarkChoice::AI], true)), 'Recorded as accepted, never as the lecturer\'s own.');
        $this->assertSame(0, MarkApproval::count(), 'Never approves.');
    }

    public function test_accept_suggestions_never_overwrites_a_level_the_lecturer_chose(): void
    {
        $this->aiPicks(position: 2);
        $this->refresh();

        $criterion = $this->criterion('Contribution to tasks');
        $this->actingAs($this->lecturer)->post($this->studentUrl(), [
            'levels' => [$criterion->id => $this->levelId('Contribution to tasks', 'Poor')],
            'reasons' => [$criterion->id => 'Their tasks were reassigned.'],
        ])->assertSessionHasNoErrors();

        $this->actingAs($this->lecturer)->post(route('lecturer.marking.accept', [$this->rubric, $this->group, $this->students['s1']]))->assertRedirect();

        $kept = MarkChoice::where('student_id', $this->students['s1']->id)->where('rubric_criterion_id', $criterion->id)->first();

        $this->assertSame($this->levelId('Contribution to tasks', 'Poor'), $kept->rubric_level_id);
        $this->assertSame(MarkChoice::LECTURER, $kept->source);
        $this->assertSame('Their tasks were reassigned.', $kept->override_reason);
    }

    public function test_accept_suggestions_leaves_manual_and_insufficient_criteria_for_the_lecturer(): void
    {
        config(['marking.min_group_activity.communication' => 99]);
        $this->aiPicks();
        $this->refresh();

        $this->actingAs($this->lecturer)->post(route('lecturer.marking.accept', [$this->rubric, $this->group, $this->students['s1']]))->assertRedirect();

        $saved = MarkChoice::where('student_id', $this->students['s1']->id)->pluck('rubric_criterion_id');

        $this->assertFalse($saved->contains($this->criterion('Communication')->id), 'Too little data: left alone.');
        $this->assertFalse($saved->contains($this->criterion('Design')->id), 'Manual: left alone.');
    }

    public function test_accept_suggestions_is_refused_for_an_approved_student(): void
    {
        $this->saveEverything();
        $this->actingAs($this->lecturer)->post(route('lecturer.marking.approve', [$this->rubric, $this->group, $this->students['s1']]))->assertRedirect();
        $before = MarkChoice::count();

        $this->actingAs($this->lecturer)->post(route('lecturer.marking.accept', [$this->rubric, $this->group, $this->students['s1']]))->assertSessionHasErrors('status');

        $this->assertSame($before, MarkChoice::count());
    }

    public function test_accept_suggestions_for_all_students_skips_approved_ones_and_approves_nobody(): void
    {
        $this->aiPicks(position: 2);
        $this->refresh();

        $this->saveEverything('s1');
        $this->actingAs($this->lecturer)->post(route('lecturer.marking.approve', [$this->rubric, $this->group, $this->students['s1']]))->assertRedirect();
        $s1 = MarkChoice::where('student_id', $this->students['s1']->id)->count();

        $this->actingAs($this->lecturer)->post(route('lecturer.marking.accept_all', [$this->rubric, $this->group]))
            ->assertRedirect(route('lecturer.marking.group', [$this->rubric, $this->group]))
            ->assertSessionHas('status');

        $this->assertSame($s1, MarkChoice::where('student_id', $this->students['s1']->id)->count(), 'The approved student is untouched.');
        $this->assertGreaterThan(0, MarkChoice::where('student_id', $this->students['s2']->id)->count());
        $this->assertSame(1, MarkApproval::count(), 'Only the approval that was already there.');
    }

    public function test_the_group_list_has_the_accept_for_all_button(): void
    {
        $html = $this->actingAs($this->lecturer)->get(route('lecturer.marking.group', [$this->rubric, $this->group]))->getContent();

        $this->assertStringContainsString('Accept suggestions for all students', $html);
        $this->assertStringContainsString('data-accept-all', $html);
    }

    public function test_accept_is_only_for_the_lecturer_who_owns_the_subject(): void
    {
        $other = \App\Models\User::factory()->lecturer()->create(['must_change_password' => false]);

        $this->actingAs($other)->post(route('lecturer.marking.accept', [$this->rubric, $this->group, $this->students['s1']]))->assertNotFound();
        $this->actingAs($other)->post(route('lecturer.marking.accept_all', [$this->rubric, $this->group]))->assertNotFound();
        $this->actingAs($this->students['s1'])->post(route('lecturer.marking.accept', [$this->rubric, $this->group, $this->students['s1']]))->assertForbidden();
    }
    public function test_the_reason_field_is_there_for_an_override_and_the_server_insists_on_it(): void
    {
        $this->aiPicks();
        $this->refresh();

        $this->assertStringContainsString('data-reason-field', $this->page());

        $criterion = $this->criterion('Contribution to tasks');

        $this->actingAs($this->lecturer)->post($this->studentUrl(), ['levels' => [$criterion->id => $this->levelId('Contribution to tasks', 'Poor')]])
            ->assertSessionHasErrors('reasons.'.$criterion->id);

        $this->assertSame(0, MarkChoice::count());

        $this->actingAs($this->lecturer)->post($this->studentUrl(), [
            'levels' => [$criterion->id => $this->levelId('Contribution to tasks', 'Poor')],
            'reasons' => [$criterion->id => 'Their tasks were reassigned.'],
        ])->assertSessionHasNoErrors();

        $this->assertSame('Their tasks were reassigned.', MarkChoice::first()->override_reason);
    }

    /*
    | Saving, approving, revoking
    */

    public function test_saving_records_the_choices_and_says_they_are_not_final(): void
    {
        $this->actingAs($this->lecturer)->post($this->studentUrl(), $this->everything())
            ->assertRedirect($this->studentUrl())
            ->assertSessionHas('status', 'Choices saved. These are not final marks until you approve them.');

        $this->assertSame(12, MarkChoice::where('student_id', $this->students['s1']->id)->count());
        $this->assertSame(0, MarkApproval::count());
    }

    public function test_mixed_sections_get_a_method_choice_and_the_scaling_is_shown(): void
    {
        $rubric = $this->saveSampleRubric(function ($data) {
            $data['sections'][1]['criteria'][4]['mode'] = 'manual';

            return $data;
        });
        $clo4 = $rubric->sections[1];

        $html = $this->actingAs($this->lecturer)->get(route('lecturer.marking.student', [$rubric, $this->group, $this->students['s1']]))->getContent();

        $this->assertStringContainsString('How is this section totalled?', $html);
        $this->assertSame(1, substr_count($html, 'data-method>'), 'Only the mixed section asks.');
        $this->assertStringContainsString('data-method-default', $html, 'Add is chosen for a level-only section, and the page says so.');
        $this->assertMatchesRegularExpression('/<option value="add"[^>]*selected/', $html);

        $levels = [];
        foreach ($rubric->sections->flatMap->criteria as $c) {
            $levels[$c->id] = $c->levels->firstWhere('label', 'Good')->id;
        }

        $this->actingAs($this->lecturer)->post(route('lecturer.marking.save', [$rubric, $this->group, $this->students['s1']]), [
            'levels' => $levels, 'methods' => [$clo4->id => 'scale'],
        ])->assertSessionHasNoErrors();

        $html = $this->actingAs($this->lecturer)->get(route('lecturer.marking.student', [$rubric, $this->group, $this->students['s1']]))->getContent();

        $this->assertStringContainsString('Scaled: 6 of 8', $html);
        $this->assertStringContainsString('× 10 ÷ 8', $html);
        $this->assertStringContainsString('= 7.5.', $html);
        $this->assertStringContainsString('data-scale-note', $html);
        $this->assertStringContainsString('It is not counted in the total.', $html, 'The manual criterion says it is not counted.');
        $this->assertStringContainsString('the manual criteria below are not counted', $html);
        $this->assertDoesNotMatchRegularExpression('/data-undecided/', $html, 'Nothing counted is left undecided.');
    }

    public function test_the_method_select_is_full_width_and_explains_add_and_scale(): void
    {
        $rubric = $this->saveSampleRubric(function ($data) {
            $data['sections'][1]['criteria'][4]['mode'] = 'manual';

            return $data;
        });

        $html = $this->actingAs($this->lecturer)->get(route('lecturer.marking.student', [$rubric, $this->group, $this->students['s1']]))->getContent();

        preg_match('/<select id="method-\d+"[^>]*>/s', $html, $m);
        $this->assertStringContainsString('w-full', $m[0]);
        $this->assertStringNotContainsString('max-w', $m[0]);
        $this->assertStringContainsString('data-method-help', $html);
        $this->assertStringContainsString('only the System-suggested criteria count', $html);
        $this->assertMatchesRegularExpression('/Add:.*every criterion counts/s', $html);
    }

    public function test_an_approval_time_is_shown_in_malaysia_time(): void
    {
        $this->saveEverything();

        \Illuminate\Support\Carbon::setTestNow(\Illuminate\Support\Carbon::parse('2026-10-30 18:57:00', 'UTC'));   // 02:57 on 31 Oct in Kuala Lumpur

        $this->actingAs($this->lecturer)->post(route('lecturer.marking.approve', [$this->rubric, $this->group, $this->students['s1']]))->assertRedirect();

        $html = $this->page();
        \Illuminate\Support\Carbon::setTestNow();

        $this->assertStringContainsString('31 Oct 2026, 2:57am', $html);
        $this->assertStringNotContainsString('30 Oct 2026, 6:57pm', $html);
    }

    protected function scaledRubric(): \App\Models\Rubric
    {
        return $this->saveSampleRubric(function ($data) {
            $data['sections'][1]['criteria'][4]['mode'] = 'manual';

            return $data;
        });
    }

    /** Saves the levels of the named criteria at $label, in the scaled section CLO4. */
    protected function saveScaled(\App\Models\Rubric $rubric, array $names, string $label = 'Good', string $method = 'scale'): void
    {
        $levels = [];

        foreach ($rubric->sections->flatMap->criteria as $c) {
            if (in_array($c->name, $names, true)) {
                $levels[$c->id] = $c->levels->firstWhere('label', $label)->id;
            }
        }

        $this->actingAs($this->lecturer)->post(route('lecturer.marking.save', [$rubric, $this->group, $this->students['s1']]), [
            'levels' => $levels, 'methods' => [$rubric->sections[1]->id => $method],
        ])->assertSessionHasNoErrors();
    }

    public function test_an_approved_scaled_section_marks_the_manual_row_not_counted_and_explains_the_total_on_its_line(): void
    {
        $rubric = $this->scaledRubric();
        $everything = $rubric->sections->flatMap->criteria->pluck('name')->all();

        $this->saveScaled($rubric, $everything);
        $this->actingAs($this->lecturer)->post(route('lecturer.marking.approve', [$rubric, $this->group, $this->students['s1']]))->assertRedirect();

        $html = $this->actingAs($this->lecturer)->get(route('lecturer.marking.student', [$rubric, $this->group, $this->students['s1']]))->getContent();

        $this->assertSame(1, substr_count($html, 'Not counted (scaled)'), 'The one Manual row, where its marks would be.');
        $this->assertStringContainsString('data-not-counted-marks', $html);

        preg_match_all('/data-section-total.*?<\/span>\s*<\/span>/s', $html, $m);
        $this->assertNotEmpty($m[0]);
        $clo4 = collect($m[0])->first(fn ($s) => str_contains($s, 'scaled:'));
        $this->assertNotNull($clo4, 'The scaled total carries its working on the same line.');
        $this->assertStringContainsString('7.5', $clo4);
        $this->assertStringContainsString('Manual criteria are not counted', $clo4);
    }

    public function test_an_unfinished_scaled_section_shows_incomplete_with_the_count_and_no_marks_so_far(): void
    {
        $rubric = $this->scaledRubric();

        $this->saveScaled($rubric, ['Contribution to tasks', 'Reliability and deadlines']);

        $student = $this->actingAs($this->lecturer)->get(route('lecturer.marking.student', [$rubric, $this->group, $this->students['s1']]))->getContent();

        $this->assertStringContainsString('data-scaled-pending', $student);
        $this->assertStringNotContainsString('Marks so far', $student, 'No part-way figure while a scaled section is unfinished.');
        $this->assertStringContainsString('2 of 4 System-suggested criteria chosen', $student);

        $group = $this->actingAs($this->lecturer)->get(route('lecturer.marking.group', [$rubric, $this->group]))->getContent();

        $this->assertStringContainsString('data-incomplete', $group);
        $this->assertStringNotContainsString('data-so-far', $group, 'The group list shows no so-far figure either.');
    }

    public function test_the_group_list_shows_the_denominator_next_to_the_total_and_the_so_far(): void
    {
        $this->saveEverything();
        $this->actingAs($this->lecturer)->post($this->studentUrl('s2'), $this->everything('Good'));
        $this->actingAs($this->lecturer)->post($this->studentUrl('s3'), ['levels' => [], 'reasons' => []]);
        $this->choose('s3', ['Design' => 'Good']);

        $html = $this->actingAs($this->lecturer)->get(route('lecturer.marking.group', [$this->rubric, $this->group]))->getContent();

        $this->assertMatchesRegularExpression('/data-total>\s*[\d.]+\s*<span[^>]*>of 40<\/span>/', $html);
        $this->assertMatchesRegularExpression('/data-so-far>[\d.]+ of 40 so far</', $html);
    }

    public function test_the_banner_on_a_student_page_gives_the_full_marks_too(): void
    {
        $this->choose('s1', ['Design' => 'Good']);

        $this->assertMatchesRegularExpression('/Marks so far: [\d.]+ of 40\./', $this->page());
    }

    public function test_approving_freezes_the_sheet_and_the_page_becomes_read_only(): void
    {
        $this->saveEverything();

        $this->actingAs($this->lecturer)->post(route('lecturer.marking.approve', [$this->rubric, $this->group, $this->students['s1']]))
            ->assertRedirect($this->studentUrl())
            ->assertSessionHas('status');

        $html = $this->page();

        $this->assertStringContainsString('data-status="approved"', $html);
        $this->assertStringContainsString('Approved: 40', $html);
        $this->assertStringContainsString('Dr Zainab Lecturer', $html);
        $this->assertStringNotContainsString('id="marking-form"', $html, 'No form to edit.');
        $this->assertSame(2, substr_count($html, 'data-approved-section'));
        $this->assertStringContainsString('Revoke approval', $html);

        $this->actingAs($this->lecturer)->post($this->studentUrl(), $this->everything('Poor'))->assertSessionHasErrors('approved');
        $this->assertSame($this->levelId('Design', 'Excellent'), MarkChoice::where('rubric_criterion_id', $this->criterion('Design')->id)->first()->rubric_level_id);
    }

    public function test_an_approval_can_be_revoked_and_then_the_choices_reopen(): void
    {
        $this->saveEverything();
        $this->actingAs($this->lecturer)->post(route('lecturer.marking.approve', [$this->rubric, $this->group, $this->students['s1']]));

        $this->actingAs($this->lecturer)->delete(route('lecturer.marking.revoke', [$this->rubric, $this->group, $this->students['s1']]))
            ->assertRedirect($this->studentUrl())
            ->assertSessionHas('status');

        $this->assertStringContainsString('id="marking-form"', $this->page());
        $this->actingAs($this->lecturer)->post($this->studentUrl(), $this->everything('Good'))->assertSessionHasNoErrors();
    }

    public function test_refreshing_skips_a_student_whose_marks_are_approved_and_does_the_rest(): void
    {
        $this->aiPicks();
        $this->refresh();
        $this->saveEverything('s1');
        $this->actingAs($this->lecturer)->post(route('lecturer.marking.approve', [$this->rubric, $this->group, $this->students['s1']]));

        $before = MarkSuggestion::where('student_id', $this->students['s1']->id)->orderBy('id')->get()->map->only(['id', 'baseline_level_id', 'ai_level_id', 'updated_at'])->all();

        $this->aiPicks(position: 0);

        $this->actingAs($this->lecturer)->postJson(route('lecturer.marking.suggest', [$this->rubric, $this->group, $this->students['s1']]))
            ->assertOk()->assertJsonPath('status', 'skipped')->assertJsonPath('message', 'Approved, so left as it is.');

        $this->actingAs($this->lecturer)->postJson(route('lecturer.marking.suggest', [$this->rubric, $this->group, $this->students['s2']]))
            ->assertOk()->assertJsonPath('status', 'done');

        $this->assertEquals($before, MarkSuggestion::where('student_id', $this->students['s1']->id)->orderBy('id')->get()->map->only(['id', 'baseline_level_id', 'ai_level_id', 'updated_at'])->all());
    }

    public function test_with_no_provider_a_student_still_gets_rule_based_suggestions_and_is_told_why(): void
    {
        config(['ai.driver' => 'none']);
        app()->forgetInstance(AiManager::class);
        app()->forgetInstance(\App\Support\Ai\AiGateway::class);
        app()->forgetInstance(SuggestionService::class);

        $this->actingAs($this->lecturer)->postJson(route('lecturer.marking.suggest', [$this->rubric, $this->group, $this->students['s1']]))
            ->assertOk()
            ->assertJsonPath('status', 'disabled')
            ->assertJson(fn ($json) => $json->where('message', fn ($m) => str_contains($m, 'switched off'))->etc());

        $row = MarkSuggestion::where('student_id', $this->students['s1']->id)->where('rubric_criterion_id', $this->criterion('Contribution to tasks')->id)->first();

        $this->assertNotNull($row->baseline_level_id);
        $this->assertNull($row->ai_level_id);
    }
    /*
    | The evidence report
    */

    protected function csv(?\App\Models\Rubric $rubric = null): string
    {
        return $this->actingAs($this->lecturer)->get(route('lecturer.marking.export', [$rubric ?? $this->rubric, $this->group]))
            ->assertOk()->streamedContent();
    }

    public function test_the_export_is_a_utf8_csv_with_a_byte_order_mark(): void
    {
        $response = $this->actingAs($this->lecturer)->get(route('lecturer.marking.export', [$this->rubric, $this->group]))->assertOk();

        $this->assertStringContainsString('text/csv; charset=UTF-8', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('marking-evidence.csv', $response->headers->get('Content-Disposition'));
        $this->assertStringStartsWith("\xEF\xBB\xBF", $response->streamedContent());
    }

    public function test_the_export_carries_the_rubric_provider_facts_levels_reason_and_marks(): void
    {
        $this->aiPicks(position: 3);
        $this->refresh();
        $this->choose('s1', ['Contribution to tasks' => 'Poor'], ['Contribution to tasks' => 'Cancelled by the client.']);

        $csv = $this->csv();

        foreach ([
            'CollabSpace marking evidence report', 'Group Falcon', 'Marking Rubric', '"Rubric version",1', 'fake / fake-model',
            'Tasks assigned 4; Completed 3', 'Rule-based level', 'AI-suggested level', 'Override reason', 'Cancelled by the client.',
            "Lecturer's own choice", 'Draft (not approved)', 'Level × weight', 'Aminah Binti Rashid',
        ] as $needle) {
            $this->assertStringContainsString($needle, $csv, $needle);
        }

        // Header, then one row per student and criterion: 4 students x 12 criteria.
        $rows = array_filter(explode("\n", trim($csv)));
        $this->assertGreaterThanOrEqual(48 + 5, count($rows));
    }

    public function test_the_export_explains_the_scaling_and_shows_the_approved_marks(): void
    {
        $rubric = $this->saveSampleRubric(function ($data) {
            $data['sections'][1]['criteria'][4]['mode'] = 'manual';

            return $data;
        });

        $levels = [];
        foreach ($rubric->sections->flatMap->criteria as $c) {
            $levels[$c->id] = $c->levels->firstWhere('label', 'Good')->id;
        }

        $this->actingAs($this->lecturer)->post(route('lecturer.marking.save', [$rubric, $this->group, $this->students['s1']]), [
            'levels' => $levels, 'methods' => [$rubric->sections[1]->id => 'scale'],
        ])->assertSessionHasNoErrors();

        // CLO2 is Manual and Good everywhere (3 × 6.0 = 18); CLO4 scales 6 of 8 to 7.5: total 25.5.
        $this->actingAs($this->lecturer)->post(route('lecturer.marking.approve', [$rubric, $this->group, $this->students['s1']]))->assertSessionHasNoErrors();

        $csv = $this->csv($rubric);

        $this->assertStringContainsString('scale (System-suggested criteria', $csv);
        $this->assertStringContainsString('6 of 8 from System-suggested criteria, x 10 / 8 = 7.5', $csv);
        $this->assertStringContainsString('Approved', $csv);
        $this->assertStringContainsString('25.5', $csv);
        $this->assertStringContainsString('Dr Zainab Lecturer', $csv);
    }

    public function test_an_approved_students_export_does_not_change_when_activity_does(): void
    {
        $this->saveEverything();
        $this->actingAs($this->lecturer)->post(route('lecturer.marking.approve', [$this->rubric, $this->group, $this->students['s1']]));

        $before = collect(explode("\n", $this->csv()))->filter(fn ($l) => str_contains($l, 'Aminah'))->values()->all();

        $recorder = app(\App\Support\ActivityRecorder::class);
        for ($i = 0; $i < 20; $i++) {
            $recorder->record($this->students['s1'], $this->group, 'message_sent');
        }

        $after = collect(explode("\n", $this->csv()))->filter(fn ($l) => str_contains($l, 'Aminah'))->values()->all();

        $this->assertSame($before, $after);
        $this->assertNotEmpty($before);
    }

    public function test_text_in_the_export_cannot_run_as_a_spreadsheet_formula(): void
    {
        $this->students['s1']->update(['name' => '=HYPERLINK("http://evil.test","x")']);
        $this->rubric->sections[0]->criteria[0]->update(['name' => '+cmd|calc']);

        $csv = $this->csv();

        $this->assertStringContainsString("'=HYPERLINK", $csv);
        $this->assertStringContainsString("'+cmd|calc", $csv);
        $this->assertStringNotContainsString(',=HYPERLINK', $csv);
        $this->assertStringNotContainsString(',"=HYPERLINK', $csv);
    }

    /*
    | Who can reach it
    */

    /** @return array<string, array{string, string}> every marking route, as [verb, url] */
    protected function routes(): array
    {
        $s = $this->students['s1'];
        $base = [$this->rubric, $this->group];

        return [
            'group' => ['get', route('lecturer.marking.group', $base)],
            'student' => ['get', route('lecturer.marking.student', [...$base, $s])],
            'save' => ['post', route('lecturer.marking.save', [...$base, $s])],
            'approve' => ['post', route('lecturer.marking.approve', [...$base, $s])],
            'revoke' => ['delete', route('lecturer.marking.revoke', [...$base, $s])],
            'suggest' => ['post', route('lecturer.marking.suggest', [...$base, $s])],
            'export' => ['get', route('lecturer.marking.export', $base)],
        ];
    }

    public function test_another_lecturer_gets_a_404_for_every_marking_route(): void
    {
        $this->saveEverything();
        $other = User::factory()->lecturer()->create(['must_change_password' => false]);

        foreach ($this->routes() as $name => [$verb, $url]) {
            $this->actingAs($other)->{$verb}($url)->assertNotFound("{$name} was reachable by another lecturer");
        }

        $this->assertSame(12, MarkChoice::count());
        $this->assertSame(0, MarkApproval::count());
    }

    public function test_the_hub_shows_another_lecturer_nothing_of_this_ones(): void
    {
        $other = User::factory()->lecturer()->create(['must_change_password' => false]);

        $this->actingAs($other)->get(route('lecturer.marking'))->assertOk()
            ->assertDontSee('Group Falcon')->assertDontSee('Marking Rubric')->assertDontSee('SECJ3104');
    }

    public function test_a_group_from_another_subject_and_a_student_outside_the_group_are_404(): void
    {
        $second = Subject::create(['lecturer_id' => $this->lecturer->id, 'code' => 'SECV2', 'name' => 'Second Subject', 'group_size' => 4]);
        $elsewhere = Group::create(['subject_id' => $second->id, 'name' => 'Elsewhere', 'group_size' => 4]);
        $stranger = User::factory()->student()->create(['must_change_password' => false]);

        $this->actingAs($this->lecturer)->get(route('lecturer.marking.group', [$this->rubric, $elsewhere]))->assertNotFound();
        $this->actingAs($this->lecturer)->get(route('lecturer.marking.student', [$this->rubric, $this->group, $stranger]))->assertNotFound();
        $this->actingAs($this->lecturer)->post(route('lecturer.marking.save', [$this->rubric, $this->group, $stranger]), $this->everything())->assertNotFound();
        $this->actingAs($this->lecturer)->post(route('lecturer.marking.suggest', [$this->rubric, $elsewhere, $this->students['s1']]))->assertNotFound();
        $this->actingAs($this->lecturer)->get(route('lecturer.marking.export', [$this->rubric, $elsewhere]))->assertNotFound();

        $this->assertSame(0, MarkChoice::count());
    }

    public function test_students_and_admins_cannot_reach_any_marking_route(): void
    {
        $this->saveEverything();
        $admin = User::factory()->admin()->create(['must_change_password' => false]);

        foreach ([$this->students['s1'], $this->students['s2'], $admin] as $user) {
            $this->actingAs($user)->get(route('lecturer.marking'))->assertForbidden();

            foreach ($this->routes() as $name => [$verb, $url]) {
                $this->actingAs($user)->{$verb}($url)->assertForbidden("{$name} was reachable by {$user->role}");
            }
        }
    }

    public function test_a_student_never_sees_marks_suggestions_or_the_marking_menu(): void
    {
        $this->aiPicks();
        $this->refresh();
        $this->saveEverything();
        $this->actingAs($this->lecturer)->post(route('lecturer.marking.approve', [$this->rubric, $this->group, $this->students['s1']]));
        $this->flushSession();

        $student = $this->students['s1'];

        foreach ([route('student.dashboard'), route('student.workspace.show', $this->group), route('student.board.show', $this->group), route('student.chat.show', $this->group), route('student.documents.group', $this->group), route('student.notifications'), route('account')] as $url) {
            $html = $this->actingAs($student)->get($url)->assertOk()->getContent();

            foreach (['lecturer/marking', 'Approved:', 'Rule-based', 'AI-suggested', 'Marks so far', 'Evidence report', 'rubric'] as $needle) {
                $this->assertStringNotContainsStringIgnoringCase($needle, $html, "{$needle} on {$url}");
            }
        }
    }
}
