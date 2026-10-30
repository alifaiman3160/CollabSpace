<?php

namespace Tests\Feature\Marking;

use App\Models\RubricCriterion;
use App\Models\User;
use App\Support\Ai\AiManager;
use App\Support\Rubric\RubricChecks;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\Concerns\BuildsMarkingFixture;
use Tests\TestCase;

class EvidenceScreensAndPreviewTest extends TestCase
{
    use BuildsMarkingFixture;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildMarkingFixture();
        Sleep::fake();
    }

    protected function useProvider(string $driver, array $extra = []): void
    {
        config(array_merge(['ai.driver' => $driver, 'ai.daily_limit' => 100], $extra));
        app()->forgetInstance(AiManager::class);
        app()->forgetInstance(\App\Support\Ai\AiGateway::class);
        app()->forgetInstance(\App\Support\Marking\SuggestionService::class);
    }

    /*
    | Evidence type on the review screen
    */

    protected function reviewUrl(): string
    {
        return $this->actingAs($this->lecturer)->post(
            route('lecturer.rubrics.store', $this->subject),
            ['file' => new UploadedFile(__DIR__.'/../../../docs/samples/MarkingRubric.xlsx', 'MarkingRubric.xlsx', null, null, true)],
        )->assertRedirect()->headers->get('Location');
    }

    public function test_the_review_offers_an_evidence_type_for_each_criterion_with_defaults_marked_as_such(): void
    {
        $html = $this->actingAs($this->lecturer)->get($this->reviewUrl())->assertOk()->getContent();

        // One compact select per criterion (12), shown only while the criterion is system-suggested.
        $this->assertSame(12, preg_match_all('/<select id="evidence-\d+-\d+" name="sections\[\d+\]\[criteria\]\[\d+\]\[evidence_type\]"/', $html));
        $this->assertStringContainsString('Task completion', $html);
        $this->assertStringContainsString('Reliability (deadlines)', $html);
        $this->assertStringContainsString('None (lecturer input only)', $html);
        $this->assertStringContainsString('Not set (treated as none)', $html);
        $this->assertStringContainsString('A default chosen from the wording. Change it if it is not right.', $html);

        // The wording picks the obvious ones in the teamwork section.
        foreach ([
            'Contribution to tasks' => 'task_completion',
            'Reliability and deadlines' => 'reliability',
            'Communication' => 'communication',
            'Collaboration' => 'collaboration',
        ] as $name => $type) {
            $this->assertMatchesRegularExpression('/value="'.preg_quote($name, '/').'".*?<option value="'.$type.'"\s+selected/s', $html, $name);
        }

        // And leaves the unclear one for the lecturer.
        $this->assertMatchesRegularExpression('/value="Initiative and problem solving".*?<option value=""\s+selected/s', $html);
    }

    public function test_saving_stores_evidence_only_for_system_suggested_criteria(): void
    {
        $url = $this->reviewUrl();
        $draft = (new \App\Support\Rubric\RubricParser)->parse(__DIR__.'/../../../docs/samples/MarkingRubric.xlsx');
        $payload = ['name' => 'R', 'sections' => $draft['sections']];

        foreach ($payload['sections'] as &$section) {
            foreach ($section['criteria'] as &$criterion) {
                foreach ($criterion['levels'] as &$level) {
                    $level['range'] = $level['range'] ?? '';
                }
            }
        }
        unset($section, $criterion, $level);

        // A manual criterion that carries an evidence type, and a suggested one set to none.
        $payload['sections'][0]['criteria'][0]['evidence_type'] = 'communication';
        $payload['sections'][1]['criteria'][1]['evidence_type'] = 'none';

        $this->actingAs($this->lecturer)->post($url, $payload)->assertSessionHasNoErrors();

        $rubric = \App\Models\Rubric::where('version', 2)->firstOrFail();

        $this->assertNull($this->criterion('Abstract & introduction', $rubric)->evidence_type, 'A manual criterion keeps none.');
        $this->assertSame('none', $this->criterion('Reliability and deadlines', $rubric)->evidence_type);
        $this->assertSame('task_completion', $this->criterion('Contribution to tasks', $rubric)->evidence_type);
        $this->assertNull($this->criterion('Initiative and problem solving', $rubric)->evidence_type);
        $this->assertSame('none', $this->criterion('Initiative and problem solving', $rubric)->evidence(), 'Unset reads as none.');
    }

    public function test_an_unknown_evidence_type_is_refused(): void
    {
        $url = $this->reviewUrl();
        $draft = (new \App\Support\Rubric\RubricParser)->parse(__DIR__.'/../../../docs/samples/MarkingRubric.xlsx');
        $payload = ['name' => 'R', 'sections' => $draft['sections']];
        $payload['sections'][1]['criteria'][0]['evidence_type'] = 'astrology';

        $this->actingAs($this->lecturer)->post($url, $payload)->assertSessionHasErrors('sections.1.criteria.0.evidence_type');
    }

    /*
    | Evidence type on a saved rubric
    */

    public function test_the_saved_rubric_shows_a_select_for_each_system_suggested_criterion_only(): void
    {
        $html = $this->actingAs($this->lecturer)->get(route('lecturer.rubrics.show', [$this->subject, $this->rubric]))->assertOk()->getContent();

        $this->assertSame(5, preg_match_all('/<select id="evidence-\d+" name="evidence\[\d+\]"/', $html));
        $this->assertStringContainsString('Save evidence types', $html);
        $this->assertStringContainsString('The rubric\'s content is not edited', $html);
    }

    public function test_evidence_types_can_be_changed_on_a_saved_rubric(): void
    {
        $initiative = $this->criterion('Initiative and problem solving');
        $reliability = $this->criterion('Reliability and deadlines');

        $this->actingAs($this->lecturer)->put(route('lecturer.rubrics.evidence', [$this->subject, $this->rubric]), [
            'evidence' => [$initiative->id => 'collaboration', $reliability->id => ''],
        ])->assertRedirect(route('lecturer.rubrics.show', [$this->subject, $this->rubric]))->assertSessionHas('status', 'Criterion settings saved.');

        $this->assertSame('collaboration', $initiative->fresh()->evidence_type);
        $this->assertNull($reliability->fresh()->evidence_type, 'Cleared means none.');
    }

    public function test_only_evidence_changes_and_never_a_manual_criterion_or_another_rubrics(): void
    {
        $manual = $this->criterion('Design');
        $before = $this->rubric->criteria()->get()->map->only(['id', 'name', 'weight', 'mode'])->all();
        $otherRubric = $this->saveSampleRubric();
        $theirs = $this->criterion('Collaboration', $otherRubric);

        $this->actingAs($this->lecturer)->put(route('lecturer.rubrics.evidence', [$this->subject, $this->rubric]), [
            'evidence' => [$manual->id => 'communication', $theirs->id => 'none'],
        ]);

        $this->assertNull($manual->fresh()->evidence_type, 'A manual criterion takes no evidence.');
        $this->assertSame('collaboration', $theirs->fresh()->evidence_type, 'Another version is untouched.');
        $this->assertSame($before, $this->rubric->criteria()->get()->map->only(['id', 'name', 'weight', 'mode'])->all());
    }

    public function test_an_invalid_evidence_type_on_a_saved_rubric_is_refused(): void
    {
        $criterion = $this->criterion('Collaboration');

        $this->actingAs($this->lecturer)->put(route('lecturer.rubrics.evidence', [$this->subject, $this->rubric]), [
            'evidence' => [$criterion->id => 'telepathy'],
        ])->assertSessionHasErrors('evidence.'.$criterion->id);

        $this->assertSame('collaboration', $criterion->fresh()->evidence_type);
    }

    /*
    | Level-only flag
    */

    public function test_the_helper_flags_a_top_level_that_differs_from_the_weight(): void
    {
        $this->assertFalse(RubricChecks::topLevelDiffersFromWeight(['weight' => '2', 'levels' => [['marks' => 1], ['marks' => 2.0]]]));
        $this->assertTrue(RubricChecks::topLevelDiffersFromWeight(['weight' => '2', 'levels' => [['marks' => 1], ['marks' => 2.5]]]));
        $this->assertTrue(RubricChecks::topLevelDiffersFromWeight(['weight' => '3', 'levels' => [['marks' => 2]]]));
        $this->assertFalse(RubricChecks::topLevelDiffersFromWeight(['weight' => null, 'levels' => [['marks' => 2]]]), 'No weight, nothing to compare.');
        $this->assertFalse(RubricChecks::topLevelDiffersFromWeight(['weight' => '2', 'levels' => []]));
        $this->assertStringContainsString('top level is worth 2.5', RubricChecks::topLevelMessage(['weight' => '2', 'levels' => [['marks' => 2.5]]]));
    }

    public function test_the_sample_rubric_raises_no_flag(): void
    {
        $html = $this->actingAs($this->lecturer)->get(route('lecturer.rubrics.show', [$this->subject, $this->rubric]))->getContent();

        $this->assertStringNotContainsString('Check which is right', $html);
    }

    public function test_the_saved_rubric_flags_a_level_only_criterion_whose_top_level_differs_from_its_weight(): void
    {
        $rubric = $this->saveSampleRubric(function ($data) {
            $data['sections'][1]['criteria'][2]['weight'] = '3';   // top level is 2.0
            $data['sections'][0]['criteria'][0]['weight'] = '9';   // a level × weight section: no such rule

            return $data;
        });

        $html = $this->actingAs($this->lecturer)->get(route('lecturer.rubrics.show', [$this->subject, $rubric]))->getContent();

        $this->assertSame(1, substr_count($html, 'Check which is right'));
        $this->assertStringContainsString('The top level is worth 2 but the weight, which is this criterion&#039;s maximum, is 3.', $html);
    }

    public function test_the_review_flags_it_too_when_the_lecturer_returns_to_it(): void
    {
        $url = $this->reviewUrl();
        $draft = (new \App\Support\Rubric\RubricParser)->parse(__DIR__.'/../../../docs/samples/MarkingRubric.xlsx');
        $payload = ['name' => '', 'sections' => $draft['sections']];   // refused, so the form comes back
        $payload['sections'][1]['criteria'][0]['weight'] = '2.5';

        foreach ($payload['sections'] as &$section) {
            foreach ($section['criteria'] as &$criterion) {
                foreach ($criterion['levels'] as &$level) {
                    $level['range'] = $level['range'] ?? '';
                }
            }
        }
        unset($section, $criterion, $level);

        $this->actingAs($this->lecturer)->from($url)->post($url, $payload)->assertRedirect($url);

        $html = $this->actingAs($this->lecturer)->get($url)->getContent();

        $this->assertStringContainsString('The top level is worth 2 but the weight', $html);
        $this->assertStringContainsString('data-weight-flag-text', $html);
    }

    /*
    | marking:preview
    */

    public function test_the_preview_command_shows_facts_baseline_and_why_the_ai_is_unavailable(): void
    {
        $this->useProvider('none');

        $this->assertSame(0, Artisan::call('marking:preview', ['rubric' => $this->rubric->id, 'group' => $this->group->id]));
        $out = Artisan::output();

        $this->assertStringContainsString('Aminah Binti Rashid', $out);
        $this->assertStringContainsString('Tasks assigned 4', $out);
        $this->assertStringContainsString('Rule-based :', $out);
        $this->assertStringContainsString('Completed 3 of 4 assigned tasks (75%)', $out);
        $this->assertStringContainsString('level 3 of 4, 1.5 marks', $out);
        $this->assertStringContainsString('AI-suggested: unavailable. AI suggestions are switched off', $out);
        $this->assertStringContainsString('Needs lecturer input. No tasks are assigned to this student.', $out);
        $this->assertStringContainsString('Needs lecturer input. This criterion is not drawn from recorded activity.', $out);
    }

    public function test_the_preview_command_shows_the_ai_suggestion_and_flags_when_it_runs(): void
    {
        $this->useProvider('fake');
        \App\Support\Ai\Drivers\FakeDriver::reset();
        \App\Support\Ai\Drivers\FakeDriver::respondWith(fn ($payload) => ['suggestions' => array_map(
            fn ($c) => ['criterion' => $c['id'], 'level' => collect($c['levels'])->sortBy('marks')->last()['id'], 'rationale' => $this->cite($c, 'The counts are strong')],
            $payload['criteria'],
        )]);

        Artisan::call('marking:preview', ['rubric' => $this->rubric->id, 'group' => $this->group->id]);
        $out = Artisan::output();

        $this->assertStringContainsString('AI-suggested: Excellent (level 4 of 4, 2 marks) | The counts are strong (', $out);
        $this->assertStringContainsString('Flag: the baseline and the AI suggestion differ by more than one level.', $out);
        $this->assertStringContainsString('requests 4', $out);
    }

    public function test_the_preview_command_can_skip_the_ai(): void
    {
        Http::fake();
        $this->useProvider('openai', ['ai.drivers.openai.key' => 'sk-test-SECRETKEY-1234567890', 'ai.drivers.openai.model' => 'm']);

        Artisan::call('marking:preview', ['rubric' => $this->rubric->id, 'group' => $this->group->id, '--no-ai' => true]);

        Http::assertNothingSent();
        $this->assertStringContainsString('rule-based only', Artisan::output());
    }

    public function test_the_preview_command_never_prints_the_key(): void
    {
        Http::fake(['*' => Http::response(['error' => ['message' => 'bad key sk-test-SECRETKEY-1234567890']], 401)]);
        $this->useProvider('openai', ['ai.drivers.openai.key' => 'sk-test-SECRETKEY-1234567890', 'ai.drivers.openai.model' => 'gpt-test']);

        Artisan::call('marking:preview', ['rubric' => $this->rubric->id, 'group' => $this->group->id]);
        $out = Artisan::output();

        $this->assertStringNotContainsString('SECRETKEY', $out);
        $this->assertStringNotContainsString('sk-test', $out);
        $this->assertStringContainsString('did not respond (HTTP 401', $out);
    }

    public function test_the_preview_command_refuses_a_group_from_another_subject_or_a_missing_id(): void
    {
        $second = \App\Models\Subject::create(['lecturer_id' => $this->lecturer->id, 'code' => 'SECV2', 'name' => 'Second Subject', 'group_size' => 4]);
        $elsewhere = \App\Models\Group::create(['subject_id' => $second->id, 'name' => 'Elsewhere', 'group_size' => 4]);

        $this->assertSame(1, Artisan::call('marking:preview', ['rubric' => $this->rubric->id, 'group' => $elsewhere->id]));
        $this->assertStringContainsString('does not belong to the subject', Artisan::output());

        $this->assertSame(1, Artisan::call('marking:preview', ['rubric' => 99999, 'group' => $this->group->id]));
        $this->assertStringContainsString('no rubric with that id', Artisan::output());
    }

    public function test_a_key_echoed_back_in_an_error_body_is_not_kept_in_the_audit_record(): void
    {
        Http::fake(['*' => Http::response(['error' => ['message' => 'bad key sk-test-SECRETKEY-1234567890']], 401)]);
        $this->useProvider('openai', ['ai.drivers.openai.key' => 'sk-test-SECRETKEY-1234567890', 'ai.drivers.openai.model' => 'gpt-test']);

        Artisan::call('marking:preview', ['rubric' => $this->rubric->id, 'group' => $this->group->id]);

        $run = \App\Models\MarkingRun::firstOrFail();

        $this->assertStringContainsString('bad key', $run->response, 'The reply is kept.');
        $this->assertStringNotContainsString('SECRETKEY', $run->response);
        $this->assertStringNotContainsString('SECRETKEY', (string) $run->message);
        $this->assertStringNotContainsString('SECRETKEY', $run->prompt);
    }

    /*
    | Ownership and roles
    */

    public function test_another_lecturer_cannot_set_evidence_types(): void
    {
        $other = User::factory()->lecturer()->create(['must_change_password' => false]);
        $criterion = $this->criterion('Collaboration');

        $this->actingAs($other)->put(route('lecturer.rubrics.evidence', [$this->subject, $this->rubric]), [
            'evidence' => [$criterion->id => 'none'],
        ])->assertNotFound();

        $this->assertSame('collaboration', $criterion->fresh()->evidence_type);
    }

    public function test_students_cannot_reach_the_evidence_route_or_see_any_marking_data(): void
    {
        \App\Models\MarkSuggestion::create([
            'rubric_criterion_id' => $this->criterion('Collaboration')->id, 'group_id' => $this->group->id,
            'student_id' => $this->students['s1']->id, 'evidence_type' => 'collaboration', 'needs_input' => false,
            'baseline_note' => 'A distinctive baseline note.', 'ai_status' => 'ok', 'ai_rationale' => 'A distinctive rationale.',
            'facts' => ['tasks_assigned' => 4],
        ]);

        $student = $this->students['s1'];

        $this->actingAs($student)->put(route('lecturer.rubrics.evidence', [$this->subject, $this->rubric]), ['evidence' => []])->assertForbidden();
        $this->actingAs($student)->get(route('lecturer.rubrics.show', [$this->subject, $this->rubric]))->assertForbidden();

        foreach ([route('student.dashboard'), route('student.workspace.show', $this->group), route('student.board.show', $this->group), route('student.notifications')] as $url) {
            $html = $this->actingAs($student)->get($url)->assertOk()->getContent();

            foreach (['distinctive', 'Rule-based', 'AI-suggested', 'evidence', 'baseline', 'Needs lecturer input'] as $needle) {
                $this->assertStringNotContainsStringIgnoringCase($needle, $html, "{$needle} on {$url}");
            }
        }

        $admin = User::factory()->admin()->create(['must_change_password' => false]);
        $this->actingAs($admin)->put(route('lecturer.rubrics.evidence', [$this->subject, $this->rubric]), ['evidence' => []])->assertForbidden();
    }
}
