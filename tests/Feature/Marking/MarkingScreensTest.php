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
            preg_match_all('/<a(?=[^>]*data-nav-link)(?=[^>]*(?:\saria-current="page"|\sdata-parent-current[\s>]))[^>]*>(.*?)<\/a>/s', $nav[0], $current);

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
            ->assertSee('We suggest a level for each criterion from what every student did in the group. You make the final decision.')
            ->assertDontSee('switched off')
            ->assertDontSee('Groq')
            ->assertDontSee('Rule-based');
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
        // The banner counts and lists what is missing; it carries no figure that could pass for the total.
        preg_match('/<section[^>]*data-status="incomplete".*?<\/section>/s', $html, $banner);
        $this->assertStringNotContainsString('Marks so far', $banner[0]);
        $this->assertStringContainsString('Still needed:', $banner[0]);
        $this->assertMatchesRegularExpression('/data-overall.*?Not yet.*?Incomplete.*?2 of 12 decided/s', $html, 'The Total tile agrees with the banner.');
        $this->assertStringContainsString('There is no total, and it cannot be approved', $html);
        $this->assertStringContainsString('Approval is blocked until every criterion has a level', $html);
        $this->assertStringContainsString('disabled="disabled"', $this->tag($html, 'data-approve'), 'The server renders it disabled.');
        $this->assertStringContainsString('dirty || true', $this->tag($html, 'data-approve'));

        // Each section shows incomplete rather than a total.
        $this->assertSame(5, substr_count($html, '>Incomplete<'), 'Three tile badges and two section headings.');
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
        $this->assertStringContainsString('Finished 3 of 4 tasks (75%).', $html, 'The reason from the numbers is in the Why? panel.');
        $this->assertStringContainsString('Based on the supplied counts', $html, 'And so does the AI reason.');
        $this->assertStringContainsString('Records:', $html);
        $this->assertStringContainsString('Messages: 10 sent (group average 5)', $html);
        $this->assertStringContainsString('From the numbers', $html);
        $this->assertStringContainsString("AI's view", $html);
        $this->assertStringNotContainsString('Rule-based', $html);
        $this->assertStringNotContainsString('AI-suggested', $html);
        $this->assertStringNotContainsString('Facts:', $html);
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

        $this->assertMatchesRegularExpression('/data-sentence>Finished 3 of 4 tasks \(75%\)\.<\/p>/', $card);
        $this->assertStringContainsString('data-why-toggle', $card);
        $this->assertMatchesRegularExpression('/data-why[^>]*>.*From the numbers.*Finished 3 of 4 tasks \(75%\)\..*data-level-position>Level \d of 4</s', $card, 'The same sentence and the level\'s place are in the folded part.');
        $this->assertDoesNotMatchRegularExpression('/data-sentence>[^<]*This places it at level/', $card);
        $this->assertStringContainsString("'is-open': why", $card, 'The panel opens with the shared collapse.');
    }

    public function test_when_the_ai_differs_a_small_note_offers_its_level_and_when_they_agree_nothing_extra_shows(): void
    {
        $this->aiPicks(position: 3);
        $this->refresh();

        $differs = $this->card($this->page(), 'Contribution to tasks');
        $ai = MarkSuggestion::where('student_id', $this->students['s1']->id)->where('rubric_criterion_id', $this->criterion('Contribution to tasks')->id)->first();

        $this->assertMatchesRegularExpression('/data-ai-note[^>]*>\s*AI\'s view: ([A-Za-z ]+)\s*</', $differs);
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
            $this->assertMatchesRegularExpression('/data-you-choose>(\QThere is too little activity in this group to compare students.\E )?You choose this one\.</', $card);
            $this->assertSame(5, substr_count($card, 'data-chip'));
            $this->assertStringNotContainsString('data-suggested', $card);
            $this->assertStringNotContainsString('text-danger', $card, 'Not styled as an error.');
            $this->assertStringNotContainsString('text-warning', $card);
        }

        $this->assertStringContainsString('data-you-choose>There is too little activity in this group to compare students. You choose this one.', $this->card($html, 'Communication'), 'The calm line gives the reason in full.');
        $this->assertStringContainsString('Not asked: there is too little activity in this group to compare students.', $html);
    }

    public function test_a_manual_criterion_has_chips_and_no_suggestions(): void
    {
        $html = $this->page();

        $this->assertSame('', $this->ticked($this->card($html, 'Design')));
        // Nothing has been suggested yet, so there is nothing to explain: no Why? link, and no empty boxes.
        $this->assertSame(0, substr_count($html, 'data-rule-based'));
        $this->assertStringNotContainsString('No suggestion yet', $html);
        $this->assertStringNotContainsString('Why?', $this->card($html, 'Contribution to tasks'));
    }

    public function test_the_top_of_the_page_gives_the_totals_the_two_buttons_and_the_neighbours(): void
    {
        $this->saveEverything();

        $html = $this->page('s2');

        $this->assertStringContainsString('data-top', $html);
        $this->assertSame(1, substr_count($html, 'data-overall'));
        $this->assertSame(2, substr_count($html, 'data-tile data-tile-box'), 'One per section.');
        $this->assertSame(3, substr_count($html, 'data-tile-box'), 'Three equal tiles in one grid.');
        $this->assertStringContainsString('sm:grid-flow-col sm:auto-cols-fr', $html);
        $this->assertMatchesRegularExpression('/data-overall.*?Total/s', $html);
        $this->assertMatchesRegularExpression('/data-overall.*?Total.*?of 40/s', $this->page('s1'));
        $this->assertStringContainsString('data-accept', $html);
        $this->assertStringContainsString('data-approve-top', $html);
        $this->assertStringContainsString('data-approve', $html, 'The sticky bar still has its own.');
        $this->assertStringContainsString('Student 2 of 4', $html);
        $this->assertStringContainsString('data-previous', $html);
        $this->assertStringContainsString('data-next', $html);
        $this->assertStringContainsString('data-all-students', $html);
    }

    public function test_an_incomplete_total_reads_not_yet_with_an_incomplete_badge_and_the_tiles_line_up(): void
    {
        $html = $this->page();

        $this->assertStringNotContainsString('>—<', $html, 'No heavy dash in place of a number.');
        $this->assertSame(3, substr_count($html, 'data-not-yet'));
        $this->assertSame(3, substr_count($html, 'data-incomplete-badge'));
        // The same three lines (label, figure, caption) in every tile, with fixed heights.
        $this->assertSame(3, substr_count($html, 'flex h-[30px] items-baseline'));
        $this->assertSame(3, substr_count($html, 'flex min-h-[22px] items-center'));
    }

    public function test_accept_suggestions_has_a_short_hint_and_a_tooltip_instead_of_a_paragraph(): void
    {
        $html = $this->page();

        $this->assertStringNotContainsString('data-accept-note', $html);
        $this->assertStringNotContainsString('Accept suggestions saves the suggested level for each', $html, 'The long sentence is gone from the page body.');
        $this->assertMatchesRegularExpression('/data-tooltip="Saves the suggested level[^>]*data-accept/s', $html, 'The explanation is the button\'s tooltip.');
        $this->assertStringContainsString('disabled="disabled"', $this->tag($html, 'data-accept'));
        $this->assertStringContainsString('data-accept-hint>Nothing to accept', $html);

        $this->aiPicks(position: 2);
        $this->refresh();
        $this->actingAs($this->lecturer)->post(route('lecturer.marking.accept', [$this->rubric, $this->group, $this->students['s1']]));

        $this->assertStringContainsString('data-accept-hint>All suggestions accepted', $this->page());
    }

    public function test_why_is_only_offered_where_there_is_something_to_say_and_manual_rows_get_a_level_guide(): void
    {
        $this->aiPicks(position: 2);
        $this->refresh();

        $html = $this->page();

        $suggested = $this->card($html, 'Contribution to tasks');
        $this->assertStringContainsString('Why?', $suggested);
        $this->assertStringNotContainsString('data-guide', $suggested);

        $manual = $this->card($html, 'Design');
        $this->assertStringNotContainsString('Why?', $manual);
        $this->assertStringContainsString('Level guide', $manual);
        $this->assertStringContainsString('data-guide', $manual);
    }

    public function test_the_level_guide_lists_only_levels_that_have_a_descriptor_and_is_absent_when_none_do(): void
    {
        $criterion = $this->criterion('Design');
        $levels = $criterion->levels()->orderBy('marks')->get();
        $levels->each(fn ($l, $i) => $l->update(['descriptor' => $i < 2 ? 'Meaning of '.$l->label : null]));

        $card = $this->card($this->page(), 'Design');

        preg_match('/data-guide.*?<\/div>/s', $card, $guide);
        $this->assertSame(2, substr_count($guide[0], 'Meaning of '), 'The levels without a descriptor are left out.');

        $criterion->levels()->update(['descriptor' => null]);

        $bare = $this->card($this->page(), 'Design');
        $this->assertStringNotContainsString('data-why-toggle', $bare);
        $this->assertStringNotContainsString('Level guide', $bare);
    }

    public function test_a_suggested_row_with_no_suggestion_yet_shows_no_why(): void
    {
        $card = $this->card($this->page(), 'Contribution to tasks');

        $this->assertStringNotContainsString('Why?', $card);
        $this->assertStringNotContainsString('data-rule-based', $card);
    }

    public function test_chip_styles_never_let_hover_override_a_selected_chip(): void
    {
        $css = file_get_contents(resource_path('css/app.css'));

        // Selected: dark with white text, and hover only lightens the dark slightly.
        $this->assertMatchesRegularExpression('/\.cs-chip-input:checked \+ \.cs-chip:hover\s*\{\s*background:\s*#2a2a33;/', $css);
        $this->assertMatchesRegularExpression('/\.cs-chip-input:checked \+ \.cs-chip,\s*\.cs-chip-input:checked \+ \.cs-chip:hover\s*\{[^}]*color:\s*#ffffff/s', $css);
        // Unselected: hover changes the background but keeps dark text, and only applies when not checked.
        $this->assertMatchesRegularExpression('/\.cs-chip-input:not\(:checked\) \+ \.cs-chip:hover\s*\{[^}]*color:\s*#111114/s', $css);
        $this->assertStringContainsString('transition: background-color 150ms', $css);
        $this->assertStringContainsString('.cs-chip-input:focus-visible + .cs-chip', $css);

        // And the markup no longer carries a hover utility that could compete.
        $card = $this->card($this->page(), 'Design');
        $this->assertStringNotContainsString('hover:bg-', preg_replace('/<button.*?<\/button>/s', '', $card));
        $this->assertStringNotContainsString('peer-checked', $card);
    }

    public function test_the_suggested_label_sits_inside_the_chip_not_over_its_border(): void
    {
        $this->aiPicks(position: 2);
        $this->refresh();

        $card = $this->card($this->page(), 'Contribution to tasks');

        $this->assertStringNotContainsString('absolute', $card);
        $this->assertMatchesRegularExpression('/<span class="cs-chip [^"]*">.*?data-suggested>Suggested<\/span>\s*<\/span>/s', $card);
    }
    public function test_the_total_tile_is_tinted_and_the_section_tiles_are_white_boxes(): void
    {
        $html = $this->page();

        preg_match('/<div class="[^"]*"\s+data-overall data-tile-box>/', $html, $total);
        $this->assertStringContainsString('bg-periwinkle-soft', $total[0]);
        $this->assertStringContainsString('border border-periwinkle', $total[0]);
        $this->assertStringContainsString('text-ink', $total[0]);
        $this->assertStringNotContainsString('bg-ink', $total[0], 'No black tile.');

        preg_match_all('/<div class="[^"]*"\s+data-tile data-tile-box>/', $html, $tiles);
        $this->assertCount(2, $tiles[0]);

        foreach ($tiles[0] as $tile) {
            foreach (['bg-surface', 'border border-surface-line', 'rounded-card', 'shadow-subtle'] as $class) {
                $this->assertStringContainsString($class, $tile);
            }
        }

        // The badge stays amber on both kinds of tile.
        $this->assertSame(3, substr_count($html, 'bg-warning-bg px-2 py-0.5 text-micro font-bold uppercase tracking-eyebrow text-warning-text" data-incomplete-badge'));
    }

    public function test_labels_on_the_page_use_plain_words(): void
    {
        $this->aiPicks(position: 3);
        $this->refresh();

        $html = $this->page('s4');

        foreach (['Rule-based', 'AI-suggested', 'Facts:', 'AI suggests'] as $old) {
            $this->assertStringNotContainsString($old, $html, $old);
        }

        foreach (['From the numbers', "AI's view", 'Records:'] as $new) {
            $this->assertStringContainsString($new, $html, $new);
        }
    }

    public function test_an_approval_keeps_the_records_line_it_was_approved_with_and_older_ones_read_in_the_old_wording(): void
    {
        $this->aiPicks(position: 2);
        $this->refresh();
        $this->saveEverything();
        $this->actingAs($this->lecturer)->post(route('lecturer.marking.approve', [$this->rubric, $this->group, $this->students['s1']]))->assertRedirect();

        $this->assertStringContainsString('Tasks: 4 given, 3 done', $this->csv(), 'New approvals carry the simple wording.');

        // An approval made before the wording changed has no stored line and is read back as it was.
        $approval = MarkApproval::first();
        $snapshot = $approval->snapshot;
        foreach ($snapshot['sections'] as &$section) {
            foreach ($section['criteria'] as &$criterion) {
                unset($criterion['facts_text']);
            }
        }
        unset($section, $criterion);
        $approval->update(['snapshot' => $snapshot]);

        $csv = $this->csv();
        $this->assertStringContainsString('Tasks assigned 4; Completed 3', $csv);
        $this->assertStringContainsString('Messages sent 10 (group average 5)', $csv);
    }
    public function test_a_row_is_two_columns_when_wide_and_stacks_when_narrow(): void
    {
        $this->aiPicks(position: 2);
        $this->refresh();

        $card = $this->card($this->page(), 'Contribution to tasks');

        // Measured on the row's own width, not the screen's, because the sidebar takes room.
        $this->assertStringContainsString('<article class="@container', $card);
        $this->assertStringContainsString('@[700px]:grid-cols-[38%_minmax(0,1fr)]', $card);
        $this->assertStringContainsString('data-info', $card);
        $this->assertStringContainsString('data-controls', $card);

        // Left: name, kind and weight, the sentence and the Why? link. Right: the chips.
        $info = substr($card, strpos($card, 'data-info'), strpos($card, 'data-controls') - strpos($card, 'data-info'));
        foreach (['Contribution to tasks', 'System-suggested', 'data-sentence', 'data-why-toggle'] as $needle) {
            $this->assertStringContainsString($needle, $info, $needle);
        }
        $this->assertStringNotContainsString('data-chip', $info);

        $controls = substr($card, strpos($card, 'data-controls'));
        $this->assertStringContainsString('data-chips', $controls);
        $this->assertStringContainsString('@[700px]:justify-end', $controls, 'Right-aligned, not centred.');
        $this->assertStringNotContainsString('justify-center', substr($controls, 0, strpos($controls, 'data-chip>') ?: 800));
    }

    public function test_chips_are_a_grid_of_three_or_two_when_stacked_and_a_fixed_width_range_when_wide(): void
    {
        $card = $this->card($this->page(), 'Design');

        $this->assertStringContainsString('grid grid-cols-3 gap-2 max-[430px]:grid-cols-2', $card);
        $this->assertStringContainsString('@[700px]:flex @[700px]:flex-nowrap', $card);
        $this->assertStringContainsString('@[700px]:min-w-[110px] @[700px]:max-w-[150px] @[700px]:flex-[0_1_130px]', $card, 'Between 110 and 150px, never stretched.');
        $this->assertStringContainsString('min-h-[44px]', $card, 'A comfortable touch target.');
        $this->assertStringNotContainsString('flex-1', $card, 'No chip grows to fill the row.');
        $this->assertStringNotContainsString('flex-grow', $card);
    }

    public function test_the_opened_why_panel_spans_the_row_below_both_columns(): void
    {
        $this->aiPicks(position: 2);
        $this->refresh();

        $card = $this->card($this->page(), 'Contribution to tasks');

        $this->assertGreaterThan(strpos($card, 'data-controls'), strpos($card, 'id="why-'), 'After the columns.');
        // The two columns are closed before the panel starts.
        $before = substr($card, 0, strpos($card, 'id="why-'));
        $this->assertStringContainsString('data-row', $before);
        $this->assertGreaterThan(strrpos($before, 'data-controls'), strrpos($before, '</div>'));
    }

    public function test_rows_are_separated_by_light_lines_not_boxes(): void
    {
        $html = $this->page();

        $this->assertStringContainsString('data-rows', $html);
        $this->assertStringContainsString('divide-y divide-surface-strong', $html);
        $this->assertStringNotContainsString('rounded-card border border-surface-line bg-surface-soft p-4', $this->card($html, 'Design'));
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

        $this->assertStringContainsString('Use all suggestions', $html);
        $this->assertStringContainsString('Fills in the suggested level for every student who is not approved yet. It does not approve anything.', $html);
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
        $this->assertStringContainsString('data-method-default', $html, 'Add is picked for a level-only section, and the page says so.');
        $this->assertMatchesRegularExpression('/<option value="add"[^>]*selected/', $html);

        $levels = [];
        foreach ($rubric->sections->flatMap->criteria as $c) {
            $levels[$c->id] = $c->levels->firstWhere('label', 'Good')->id;
        }

        $this->actingAs($this->lecturer)->post(route('lecturer.marking.save', [$rubric, $this->group, $this->students['s1']]), [
            'levels' => $levels, 'methods' => [$clo4->id => 'scale'],
        ])->assertSessionHasNoErrors();

        $html = $this->actingAs($this->lecturer)->get(route('lecturer.marking.student', [$rubric, $this->group, $this->students['s1']]))->getContent();

        $this->assertStringContainsString('System-suggested marks: 6 out of 8.', $html);
        $this->assertStringContainsString('Scaled to 10: 7.5.', $html);
        $this->assertStringContainsString('It is not counted in the total.', $html, 'The manual criterion says it is not counted.');
        $this->assertStringContainsString('Manual ones are left out.', $html);
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
        $this->assertStringContainsString('Only System-suggested criteria count. Manual ones are left out.', $html);
        $this->assertStringContainsString('Every criterion counts. All the marks are added up.', $html);
        $this->assertStringContainsString('Add up every criterion', $html);
        $this->assertStringContainsString('Scale the System-suggested criteria', $html);
        $this->assertStringNotContainsString('<ul', substr($html, strpos($html, 'data-method>'), 2500), 'One short line, not a list.');
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
        $clo4 = collect($m[0])->first(fn ($s) => str_contains($s, 'scaled up from'));
        $this->assertNotNull($clo4, 'The scaled total carries its working on the same line.');
        $this->assertStringContainsString('7.5', $clo4);
        $this->assertStringContainsString('Manual criteria are not counted', $clo4);
    }

    public function test_an_unfinished_scaled_section_shows_incomplete_with_the_count_and_no_marks_so_far(): void
    {
        $rubric = $this->scaledRubric();

        $this->saveScaled($rubric, ['Contribution to tasks', 'Reliability and deadlines']);

        $student = $this->actingAs($this->lecturer)->get(route('lecturer.marking.student', [$rubric, $this->group, $this->students['s1']]))->getContent();

        $this->assertStringNotContainsString('Marks so far', $student, 'No part-way figure while a scaled section is unfinished.');
        $this->assertStringContainsString('data-not-yet', $student);
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

    public function test_the_banner_gives_counts_not_a_figure_and_the_total_tile_agrees(): void
    {
        $this->choose('s1', ['Design' => 'Good']);

        $html = $this->page();

        $this->assertStringContainsString('Incomplete: 1 of 12 criteria decided.', $html);
        $this->assertDoesNotMatchRegularExpression('/Marks so far/', $html);
        $this->assertMatchesRegularExpression('/data-overall.*?Not yet/s', $html);
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
            ->assertJson(fn ($json) => $json->where('message', fn ($m) => str_contains($m, 'not turned on'))->etc());

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
            'Tasks: 4 given, 3 done', 'Rule-based level', 'AI-suggested level', 'Override reason', 'Cancelled by the client.',
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
    /*
    | The class section: a label only, never an input to the marks
    */

    public function test_the_class_section_is_a_label_on_the_marking_headers_the_export_and_the_frozen_snapshot(): void
    {
        $section = \App\Models\Section::create(['subject_id' => $this->subject->id, 'name' => '1B']);
        $this->group->update(['section_id' => $section->id]);

        $this->aiPicks(position: 2);
        $this->refresh();
        $this->saveEverything();

        $this->assertStringContainsString('Section 1B', $this->actingAs($this->lecturer)->get($this->groupUrl())->assertOk()->getContent());
        $this->assertStringContainsString('Section 1B', $this->page());

        $this->actingAs($this->lecturer)->post(route('lecturer.marking.approve', [$this->rubric, $this->group, $this->students['s1']]))->assertRedirect();
        $this->assertSame('1B', MarkApproval::first()->snapshot['class_section']);

        // The export has the class section on its own line right after the subject, and the rubric's "Section"
        // column is untouched.
        $csv = $this->csv();
        $this->assertMatchesRegularExpression('/Subject"?,[^
]*?
"?Class section"?,"?1B"??
/', $csv);
        $this->assertMatchesRegularExpression('/"?Student"?,"?Matric no\."?,"?Status"?,"?Section"?,"?Scoring formula"?/', $csv);
    }

    public function test_marks_do_not_depend_on_the_class_section(): void
    {
        $this->aiPicks(position: 2);
        $this->refresh();
        $this->saveEverything();
        $before = $this->sheets()->build($this->rubric, $this->group->fresh(), $this->students['s1'])['total'];

        $this->group->update(['section_id' => \App\Models\Section::create(['subject_id' => $this->subject->id, 'name' => '9Z'])->id]);

        $this->assertSame($before, $this->sheets()->build($this->rubric, $this->group->fresh(), $this->students['s1'])['total']);
    }

    protected function sheets(): \App\Support\Marking\MarkSheet
    {
        return app(\App\Support\Marking\MarkSheet::class);
    }
}