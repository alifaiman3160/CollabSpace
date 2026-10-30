<?php

namespace Tests\Feature\Marking;

use App\Models\MarkApproval;
use App\Models\MarkSuggestion;
use App\Models\User;
use App\Support\Ai\AiManager;
use App\Support\Ai\Drivers\FakeDriver;
use App\Support\Marking\SuggestionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\Concerns\BuildsMarkingFixture;
use Tests\TestCase;

/**
 * "Refresh suggestions" works one student per request: each request makes at
 * most one provider call, finishes quickly, and reports a rate limit instead of
 * sleeping through it, for the page to wait out and retry.
 */
class PerStudentSuggestionTest extends TestCase
{
    use BuildsMarkingFixture;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildMarkingFixture();
        Sleep::fake();

        $this->provider('fake');
        FakeDriver::reset();
    }

    protected function provider(string $driver, array $extra = []): void
    {
        config(array_merge([
            'ai.driver' => $driver,
            'ai.daily_limit' => 100,
            'ai.drivers.openai.key' => 'sk-test-SECRETKEY-1234567890',
            'ai.drivers.openai.model' => 'gpt-test',
            'ai.drivers.openai.timeout' => 30,
            'marking.ai.spacing_ms' => 500,
        ], $extra));

        app()->forgetInstance(AiManager::class);
        app()->forgetInstance(\App\Support\Ai\AiGateway::class);
        app()->forgetInstance(SuggestionService::class);
    }

    protected function url(string $student): string
    {
        return route('lecturer.marking.suggest', [$this->rubric, $this->group, $this->students[$student]]);
    }

    protected function suggest(string $student)
    {
        return $this->actingAs($this->lecturer)->postJson($this->url($student));
    }

    protected function fakeAnswers(): void
    {
        FakeDriver::respondWith(fn ($payload) => ['suggestions' => array_map(fn ($c) => [
            'criterion' => $c['id'],
            'level' => collect($c['levels'])->sortBy('marks')->values()[2]['id'],
            'rationale' => $this->cite($c),
        ], $payload['criteria'])]);
    }

    /** A provider reply that picks the third level of each criterion asked about. */
    protected function good(Request $request)
    {
        $payload = json_decode($request['messages'][1]['content'], true);

        $items = array_map(fn ($c) => [
            'criterion' => $c['id'],
            'level' => collect($c['levels'])->sortBy('marks')->values()[2]['id'],
            'rationale' => $this->cite($c),
        ], $payload['criteria']);

        return Http::response(['choices' => [['message' => ['content' => json_encode(['suggestions' => $items])]]]]);
    }

    protected function row(string $student, string $criterion): ?MarkSuggestion
    {
        return MarkSuggestion::where('student_id', $this->students[$student]->id)
            ->where('rubric_criterion_id', $this->criterion($criterion)->id)->first();
    }

    /** The settings the page hands to its progress script, read back out of the attribute. */
    protected function pageConfig(string $html): array
    {
        $this->assertSame(1, preg_match("/markingSuggest\(JSON\.parse\('(.*?)'\)\)\"/s", $html, $m), 'The group page has the progress component.');

        // The attribute is a JavaScript string: \u0022 for quotes and \/ escaped once more for the slashes.
        $json = preg_replace_callback('/\\\\u([0-9a-fA-F]{4})/', fn ($u) => mb_chr(hexdec($u[1])), $m[1]);
        $json = str_replace(['\\\\\\/', '\\/'], '/', $json);

        $config = json_decode($json, true);
        $this->assertIsArray($config, 'Could not read the page config: '.json_last_error_msg());

        return $config;
    }

    /*
    | One student, one request
    */

    public function test_each_request_does_exactly_one_student_and_calls_the_provider_once(): void
    {
        $this->fakeAnswers();

        $this->suggest('s1')->assertOk()->assertJsonPath('status', 'done')->assertJsonPath('student.name', $this->students['s1']->name);

        $this->assertCount(1, FakeDriver::sent(), 'One provider call for one request.');
        $this->assertSame([$this->students['s1']->id], MarkSuggestion::distinct()->pluck('student_id')->all(), 'Only that student was worked on.');

        $this->suggest('s2')->assertOk();

        $this->assertCount(2, FakeDriver::sent());
        $this->assertEqualsCanonicalizing([$this->students['s1']->id, $this->students['s2']->id], MarkSuggestion::distinct()->pluck('student_id')->all());
    }

    public function test_the_whole_group_takes_one_provider_call_per_student_with_something_to_ask(): void
    {
        $this->fakeAnswers();

        foreach (array_keys($this->students) as $key) {
            $this->suggest($key)->assertOk();
        }

        // s3 has no tasks, but still has communication and collaboration to ask about.
        $this->assertCount(4, FakeDriver::sent());
        $this->assertSame(4 * 12 - 4 * 7, MarkSuggestion::count(), 'Five suggested criteria for each of four students.');
    }

    public function test_a_student_with_nothing_to_ask_costs_no_provider_call(): void
    {
        config(['marking.min_group_activity.task_completion' => 99, 'marking.min_group_activity.reliability' => 99,
            'marking.min_group_activity.communication' => 99, 'marking.min_group_activity.collaboration' => 99]);
        $this->fakeAnswers();

        $this->suggest('s1')->assertOk()->assertJsonPath('status', 'done')->assertJsonPath('counts.insufficient', 4);

        $this->assertSame([], FakeDriver::sent());
    }

    public function test_the_response_says_what_was_made(): void
    {
        $this->fakeAnswers();

        $this->suggest('s1')->assertOk()->assertJsonStructure([
            'student' => ['id', 'name'],
            'status', 'message', 'retry_after',
            'counts' => ['ai', 'rule_based', 'insufficient', 'needs_input', 'discarded', 'flagged'],
        ])->assertJsonPath('counts.ai', 4);
    }

    /*
    | A 429 is reported, not slept through
    */

    public function test_a_rate_limit_for_one_student_reports_retry_after_without_sleeping(): void
    {
        $this->provider('openai');
        Http::fake(['*' => Http::response(['error' => ['message' => 'slow down']], 429, ['Retry-After' => '7'])]);

        $this->suggest('s1')->assertOk()
            ->assertJsonPath('status', 'rate_limited')
            ->assertJsonPath('retry_after', 7);

        Http::assertSentCount(1);                  // one attempt, not three
        Sleep::assertNeverSlept();                 // and nothing slept inside the request
    }

    public function test_a_rate_limited_student_keeps_the_rule_based_suggestion(): void
    {
        $this->provider('openai');
        Http::fake(['*' => Http::response(['error' => ['message' => 'slow down']], 429)]);

        $this->suggest('s1')->assertJsonPath('status', 'rate_limited');

        $row = $this->row('s1', 'Contribution to tasks');
        $this->assertNotNull($row->baseline_level_id);
        $this->assertNull($row->ai_level_id);
        $this->assertSame('failed', $row->ai_status);
        $this->assertStringContainsString('rate limit', $row->ai_message);
        $this->assertSame('Rule-based', $row->sourceLabel());
    }

    public function test_without_retry_after_the_wait_is_the_backoff(): void
    {
        $this->provider('openai', ['marking.ai.backoff_ms' => 3000]);
        Http::fake(['*' => Http::response([], 429)]);

        $this->suggest('s1')->assertJsonPath('status', 'rate_limited')->assertJsonPath('retry_after', 3);

        Sleep::assertNeverSlept();
    }

    public function test_a_very_long_retry_after_is_capped(): void
    {
        $this->provider('openai', ['marking.ai.retry_wait_cap_seconds' => 60]);
        Http::fake(['*' => Http::response([], 429, ['Retry-After' => '3600'])]);

        $this->suggest('s1')->assertJsonPath('retry_after', 60);
    }

    public function test_retry_after_may_be_a_date(): void
    {
        $this->provider('openai');
        Http::fake(['*' => Http::response([], 429, ['Retry-After' => gmdate('D, d M Y H:i:s', time() + 15).' GMT'])]);

        $seconds = $this->suggest('s1')->json('retry_after');

        $this->assertGreaterThanOrEqual(13, $seconds);
        $this->assertLessThanOrEqual(16, $seconds);
    }

    public function test_a_rate_limit_on_one_student_does_not_touch_the_others(): void
    {
        $this->provider('openai');
        $calls = 0;
        Http::fake(function (Request $request) use (&$calls) {
            return ++$calls === 1 ? Http::response([], 429, ['Retry-After' => '2']) : $this->good($request);
        });

        $this->suggest('s1')->assertJsonPath('status', 'rate_limited');
        $this->suggest('s2')->assertJsonPath('status', 'done')->assertJsonPath('counts.ai', 4);

        $this->assertNull($this->row('s1', 'Communication')->ai_level_id);
        $this->assertNotNull($this->row('s2', 'Communication')->ai_level_id, 'The next student was asked, and answered.');
    }

    public function test_trying_the_rate_limited_student_again_completes_them(): void
    {
        $this->provider('openai');
        $calls = 0;
        Http::fake(function (Request $request) use (&$calls) {
            return ++$calls === 1 ? Http::response([], 429, ['Retry-After' => '2']) : $this->good($request);
        });

        $this->suggest('s1')->assertJsonPath('status', 'rate_limited');
        $this->suggest('s1')->assertJsonPath('status', 'done')->assertJsonPath('counts.ai', 4);

        $row = $this->row('s1', 'Contribution to tasks');
        $this->assertNotNull($row->ai_level_id);
        $this->assertSame('ok', $row->ai_status);
        $this->assertSame(1, MarkSuggestion::where('student_id', $this->students['s1']->id)->where('rubric_criterion_id', $row->rubric_criterion_id)->count(), 'Replaced, not duplicated.');
    }

    /*
    | Bounded
    */

    public function test_the_provider_is_held_to_the_web_timeout_during_the_request_and_restored_after(): void
    {
        $this->provider('openai', ['ai.drivers.openai.timeout' => 60, 'marking.ai.web_timeout_seconds' => 20]);

        $seen = [];
        Http::fake(function (Request $request) use (&$seen) {
            $seen[] = config('ai.drivers.openai.timeout');

            return $this->good($request);
        });

        $this->suggest('s1')->assertOk();

        $this->assertSame([20], array_values(array_unique($seen)), 'A request cannot outlast the browser.');
        $this->assertSame(60, config('ai.drivers.openai.timeout'), 'The command line keeps the provider\'s own timeout.');
    }

    public function test_a_timeout_shorter_than_the_limit_is_left_alone(): void
    {
        $this->provider('openai', ['ai.drivers.openai.timeout' => 8]);

        $seen = [];
        Http::fake(function (Request $request) use (&$seen) {
            $seen[] = config('ai.drivers.openai.timeout');

            return $this->good($request);
        });

        $this->suggest('s1')->assertOk();

        $this->assertSame([8], array_values(array_unique($seen)));
    }

    public function test_the_defaults_keep_a_request_well_inside_thirty_seconds(): void
    {
        $this->assertLessThanOrEqual(20, (int) config('marking.ai.web_timeout_seconds'));
        $this->assertSame(3, (int) config('marking.ai.web_max_attempts'));
        $this->assertSame(60, (int) config('marking.ai.retry_wait_cap_seconds'));
    }

    /*
    | Failure keeps the rule-based suggestion
    */

    public function test_a_provider_failure_for_one_student_keeps_their_rule_based_suggestion(): void
    {
        $this->provider('openai');
        Http::fake(['*' => Http::response(['error' => ['message' => 'boom']], 500)]);

        $this->suggest('s1')->assertOk()
            ->assertJsonPath('status', 'failed')
            ->assertJson(fn ($json) => $json->where('message', fn ($m) => str_contains($m, 'did not respond'))->etc());

        $row = $this->row('s1', 'Contribution to tasks');
        $this->assertNotNull($row->baseline_level_id);
        $this->assertNull($row->ai_level_id);
        $this->assertSame('Rule-based', $row->sourceLabel());
        Sleep::assertNeverSlept();
    }

    public function test_a_failed_student_can_be_tried_again_and_the_others_are_unaffected(): void
    {
        $this->provider('openai');
        $calls = 0;
        Http::fake(function (Request $request) use (&$calls) {
            return ++$calls === 1 ? Http::response([], 500) : $this->good($request);
        });

        $this->suggest('s1')->assertJsonPath('status', 'failed');
        $this->suggest('s2')->assertJsonPath('status', 'done');
        $this->suggest('s1')->assertJsonPath('status', 'done');

        $this->assertNotNull($this->row('s1', 'Communication')->ai_level_id);
        $this->assertNotNull($this->row('s2', 'Communication')->ai_level_id);
    }

    public function test_the_error_message_carries_no_key(): void
    {
        $this->provider('openai');
        Http::fake(['*' => Http::response(['error' => ['message' => 'bad key sk-test-SECRETKEY-1234567890']], 401)]);

        $body = $this->suggest('s1')->assertJsonPath('status', 'failed')->getContent();

        $this->assertStringNotContainsString('SECRETKEY', $body);
    }

    public function test_with_no_key_the_student_gets_rule_based_suggestions_and_no_request_is_made(): void
    {
        $this->provider('openai', ['ai.drivers.openai.key' => '']);
        Http::fake();

        $this->suggest('s1')->assertJsonPath('status', 'disabled');

        Http::assertNothingSent();
        $this->assertNotNull($this->row('s1', 'Contribution to tasks')->baseline_level_id);
    }

    /*
    | What still holds
    */

    public function test_an_approved_student_is_skipped_and_says_so(): void
    {
        $this->fakeAnswers();
        $this->suggest('s1');
        $this->chooseEverything('s1');
        app(\App\Support\Marking\ApprovalService::class)->approve($this->lecturer, $this->rubric, $this->group, $this->students['s1']);
        FakeDriver::reset();
        $this->fakeAnswers();

        $before = MarkSuggestion::where('student_id', $this->students['s1']->id)->get()->map->only(['id', 'updated_at'])->all();

        $this->suggest('s1')->assertOk()->assertJsonPath('status', 'skipped')->assertJsonPath('message', 'Approved, so left as it is.');

        $this->assertSame([], FakeDriver::sent(), 'No provider call for an approved student.');
        $this->assertEquals($before, MarkSuggestion::where('student_id', $this->students['s1']->id)->get()->map->only(['id', 'updated_at'])->all());
        $this->assertSame(1, MarkApproval::active()->count());
    }

    public function test_the_daily_limit_still_applies(): void
    {
        $this->provider('fake', ['ai.daily_limit' => 1]);
        $this->fakeAnswers();

        $this->suggest('s1')->assertJsonPath('status', 'done');

        $second = $this->suggest('s2')->assertOk()->assertJsonPath('status', 'limited');
        $this->assertStringContainsString('daily limit of 1', $second->json('message'));

        $this->assertCount(1, FakeDriver::sent(), 'The second student cost no provider call.');
        $this->assertNotNull($this->row('s2', 'Contribution to tasks')->baseline_level_id, 'They still have the rule-based suggestion.');
        $this->assertNull($this->row('s2', 'Contribution to tasks')->ai_level_id);
    }

    public function test_a_level_from_another_criterion_is_still_discarded(): void
    {
        $foreign = $this->criterion('Collaboration')->levels->first()->id;
        $target = $this->criterion('Contribution to tasks')->id;

        FakeDriver::respondWith(fn ($payload) => ['suggestions' => array_map(fn ($c) => [
            'criterion' => $c['id'],
            'level' => $c['id'] === $target ? $foreign : collect($c['levels'])->sortBy('marks')->values()[2]['id'],
            'rationale' => $this->cite($c),
        ], $payload['criteria'])]);

        $this->suggest('s1')->assertOk()->assertJsonPath('counts.discarded', 1)->assertJsonPath('counts.ai', 3);

        $this->assertSame('invalid', $this->row('s1', 'Contribution to tasks')->ai_status);
        $this->assertNull($this->row('s1', 'Contribution to tasks')->ai_level_id);
    }

    public function test_a_reason_that_cites_no_figure_is_still_discarded(): void
    {
        FakeDriver::respondWith(fn ($payload) => ['suggestions' => array_map(fn ($c) => [
            'criterion' => $c['id'],
            'level' => collect($c['levels'])->sortBy('marks')->values()[2]['id'],
            'rationale' => 'A strong contributor.',
        ], $payload['criteria'])]);

        $this->suggest('s1')->assertJsonPath('counts.ai', 0)->assertJsonPath('counts.discarded', 4);
    }

    public function test_the_minimum_activity_guard_still_applies(): void
    {
        config(['marking.min_group_activity.communication' => 99]);
        $this->fakeAnswers();

        $this->suggest('s1')->assertOk()->assertJsonPath('counts.insufficient', 1);

        $row = $this->row('s1', 'Communication');
        $this->assertTrue($row->insufficient_data);
        $this->assertNull($row->baseline_level_id);

        foreach (FakeDriver::sent() as $call) {
            $this->assertNotContains($row->rubric_criterion_id, array_column(json_decode($call['payload'], true)['criteria'], 'id'));
        }
    }

    public function test_the_student_in_the_payload_is_still_an_opaque_id(): void
    {
        $this->fakeAnswers();

        $this->suggest('s1');

        $payload = json_decode(FakeDriver::sent()[0]['payload'], true);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{10}$/', $payload['student']);
        $this->assertStringNotContainsString($this->students['s1']->name, FakeDriver::sent()[0]['payload']);
        $this->assertStringNotContainsString($this->students['s1']->email, FakeDriver::sent()[0]['payload']);
    }

    /*
    | Access
    */

    public function test_another_lecturer_a_stranger_and_students_cannot_use_it(): void
    {
        $this->fakeAnswers();
        $other = User::factory()->lecturer()->create(['must_change_password' => false]);
        $stranger = User::factory()->student()->create(['must_change_password' => false]);

        $this->actingAs($other)->postJson($this->url('s1'))->assertNotFound();
        $this->actingAs($this->lecturer)->postJson(route('lecturer.marking.suggest', [$this->rubric, $this->group, $stranger]))->assertNotFound();
        $this->actingAs($this->students['s1'])->postJson($this->url('s2'))->assertForbidden();
        $this->actingAs(User::factory()->admin()->create(['must_change_password' => false]))->postJson($this->url('s1'))->assertForbidden();

        $this->assertSame([], FakeDriver::sent());
        $this->assertSame(0, MarkSuggestion::count());
    }

    public function test_the_old_whole_group_request_no_longer_exists(): void
    {
        $this->actingAs($this->lecturer)
            ->post('/lecturer/marking/'.$this->rubric->id.'/groups/'.$this->group->id.'/suggestions')
            ->assertNotFound();
    }

    /*
    | The page
    */

    public function test_the_group_page_works_through_the_students_with_progress_and_a_busy_button(): void
    {
        $html = $this->actingAs($this->lecturer)->get(route('lecturer.marking.group', [$this->rubric, $this->group]))->assertOk()->getContent();

        $this->assertStringContainsString('markingSuggest(', $html);
        $this->assertStringContainsString('x-on:click="start()"', $html);
        $this->assertStringContainsString('x-bind:disabled="running"', $html, 'The button is disabled while it runs.');
        $this->assertStringContainsString('animate-spin', $html, 'And shows a spinner.');
        $this->assertStringContainsString('role="progressbar"', $html);
        $this->assertStringContainsString('aria-live="polite"', $html);
        $this->assertStringContainsString('Generating', file_get_contents(resource_path('js/marking-suggest.js')));

        // One URL per student, each for that student alone, and a token to send with it.
        $config = $this->pageConfig($html);
        $urls = collect($config['students'])->pluck('url', 'name');

        foreach ($this->students as $student) {
            $this->assertSame(route('lecturer.marking.suggest', [$this->rubric, $this->group, $student]), $urls[$student->name]);
        }

        $this->assertNotEmpty($config['token']);
        $this->assertStringContainsString('you can leave this page while it runs', strtolower(strip_tags($html)));
    }

    public function test_approved_students_are_marked_as_such_for_the_page_to_skip(): void
    {
        $this->chooseEverything('s2');
        app(\App\Support\Marking\ApprovalService::class)->approve($this->lecturer, $this->rubric, $this->group, $this->students['s2']);

        $html = $this->actingAs($this->lecturer)->get(route('lecturer.marking.group', [$this->rubric, $this->group]))->getContent();

        $config = $this->pageConfig($html);
        $byName = collect($config['students'])->keyBy('name');
        $this->assertTrue($byName[$this->students['s2']->name]['approved']);
        $this->assertFalse($byName[$this->students['s1']->name]['approved']);
        $this->assertSame(3, $config['maxAttempts']);
    }

    public function test_a_rubric_without_suggested_criteria_has_no_refresh_button(): void
    {
        $manual = $this->saveSampleRubric(function ($data) {
            foreach ($data['sections'][1]['criteria'] as &$c) {
                $c['mode'] = 'manual';
            }

            return $data;
        });

        $html = $this->actingAs($this->lecturer)->get(route('lecturer.marking.group', [$manual, $this->group]))->getContent();

        $this->assertStringNotContainsString('data-refresh', $html);
        $this->assertStringContainsString('every level is yours to choose', $html);
    }
}
