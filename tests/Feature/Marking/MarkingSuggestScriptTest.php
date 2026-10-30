<?php

namespace Tests\Feature\Marking;

use Tests\TestCase;

/**
 * The page's progress script, run under Node with a pretend server: one request
 * per student in turn, a wait and retry on a 429, a Retry button when it still
 * fails, approved students skipped, and one student's trouble never stopping the
 * rest. Skipped where Node is not installed.
 */
class MarkingSuggestScriptTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $version = trim((string) @shell_exec('node --version 2>&1'));

        if (! str_starts_with($version, 'v')) {
            $this->markTestSkipped('Node is not available to run the page script.');
        }
    }

    /**
     * Runs the script against a scripted server.
     *
     * $replies maps a student id to the replies to give them in turn; the last is repeated.
     * A reply is ['status' => 200, 'body' => [...]] or ['throw' => 'network' | 'abort'].
     *
     * @param  list<array<string, mixed>>  $students
     * @param  array<int, list<array<string, mixed>>>  $replies
     * @return array<string, mixed>
     */
    protected function run_(array $students, array $replies, string $actions = 'await c.start()', int $maxAttempts = 3): array
    {
        $module = 'file:///'.str_replace('\\', '/', realpath(resource_path('js/marking-suggest.js')));

        $script = <<<JS
import factory from '{$module}'

const students = %STUDENTS%
const replies = %REPLIES%
const log = { requests: [], headlines: [], delays: [], active: 0, maxActive: 0, tokens: [] }
const used = {}

globalThis.setTimeout = (fn, ms) => {
    if (ms >= 30000) return 0          // the page's own guard against a hung request; never fires here
    log.delays.push(ms)
    fn()
    return 0
}
globalThis.clearTimeout = () => {}

globalThis.fetch = async (url, options) => {
    const id = Number(url.match(/students\/(\d+)/)[1])
    log.requests.push(id)
    log.tokens.push(options.headers['X-CSRF-TOKEN'])
    log.headlines.push(c.headline)
    log.active++
    log.maxActive = Math.max(log.maxActive, log.active)
    await Promise.resolve()
    log.active--

    const list = replies[id] ?? replies['*']
    const n = used[id] = (used[id] ?? 0) + 1
    const reply = list[Math.min(n, list.length) - 1]

    if (reply.throw === 'network') throw new TypeError('network down')
    if (reply.throw === 'abort') { const e = new Error('aborted'); e.name = 'AbortError'; throw e }

    return { status: reply.status, ok: reply.status >= 200 && reply.status < 300, json: async () => reply.body }
}

const c = factory({ students, token: 'tok-123', maxAttempts: {$maxAttempts}, waitCap: 60 })

%ACTIONS%

console.log(JSON.stringify({
    log,
    running: c.running,
    headline: c.headline,
    percent: c.percent,
    problems: c.problems,
    items: c.items.map((i) => ({ id: i.id, state: i.state, note: i.note, ruleOnly: i.ruleOnly, attempt: i.attempt })),
}))
JS;

        $script = str_replace(
            ['%STUDENTS%', '%REPLIES%', '%ACTIONS%'],
            [json_encode($students), json_encode($replies), $actions],
            $script,
        );

        $path = tempnam(sys_get_temp_dir(), 'ms').'.mjs';
        file_put_contents($path, $script);

        $output = shell_exec('node '.escapeshellarg($path).' 2>&1');
        @unlink($path);

        $result = json_decode(trim((string) $output), true);
        $this->assertIsArray($result, 'The script did not run: '.$output);

        return $result;
    }

    protected function students(array $approved = []): array
    {
        $names = [1 => 'Aminah Binti Rashid', 2 => 'Bakri Bin Hamdan', 3 => 'Chong Mei Ling', 4 => 'Devika Nair'];

        return array_map(fn ($id, $name) => [
            'id' => $id, 'name' => $name, 'approved' => in_array($id, $approved, true), 'url' => "http://x.test/marking/1/groups/1/students/{$id}/suggestion",
        ], array_keys($names), $names);
    }

    protected function done(): array
    {
        return ['status' => 200, 'body' => ['status' => 'done', 'counts' => ['ai' => 4, 'rule_based' => 0, 'insufficient' => 0]]];
    }

    protected function limited(int $seconds = 2): array
    {
        return ['status' => 200, 'body' => ['status' => 'rate_limited', 'retry_after' => $seconds, 'message' => 'slow down']];
    }

    protected function state(array $result, int $id): array
    {
        return collect($result['items'])->firstWhere('id', $id);
    }

    public function test_it_asks_for_one_student_at_a_time_in_order_with_the_token(): void
    {
        $result = $this->run_($this->students(), ['*' => [$this->done()]]);

        $this->assertSame([1, 2, 3, 4], $result['log']['requests']);
        $this->assertSame(1, $result['log']['maxActive'], 'Never two requests at once.');
        $this->assertSame(['tok-123'], array_values(array_unique($result['log']['tokens'])));
        $this->assertFalse($result['running']);
        $this->assertSame(100, $result['percent']);
        $this->assertSame('Done', $result['headline']);
    }

    public function test_the_headline_names_the_student_being_worked_on(): void
    {
        $result = $this->run_($this->students(), ['*' => [$this->done()]]);

        $this->assertSame([
            'Generating 1 of 4: Aminah Binti Rashid',
            'Generating 2 of 4: Bakri Bin Hamdan',
            'Generating 3 of 4: Chong Mei Ling',
            'Generating 4 of 4: Devika Nair',
        ], $result['log']['headlines']);
    }

    public function test_an_approved_student_is_skipped_without_a_request(): void
    {
        $result = $this->run_($this->students(approved: [2]), ['*' => [$this->done()]]);

        $this->assertSame([1, 3, 4], $result['log']['requests']);
        $this->assertSame('skipped', $this->state($result, 2)['state']);
        $this->assertSame('Approved, left as it is.', $this->state($result, 2)['note']);
        $this->assertSame(100, $result['percent'], 'A skipped student counts as finished.');
    }

    public function test_a_rate_limit_waits_the_time_asked_in_the_page_then_asks_again(): void
    {
        $result = $this->run_($this->students(), [2 => [$this->limited(3), $this->done()], '*' => [$this->done()]]);

        $this->assertSame([1, 2, 2, 3, 4], $result['log']['requests'], 'Student 2 is asked again; nobody else is.');
        $this->assertSame([1000, 1000, 1000], $result['log']['delays'], 'A three second countdown, one tick a second, in the page.');
        $this->assertSame('done', $this->state($result, 2)['state']);
        $this->assertSame('Done', $result['headline']);
    }

    public function test_a_wait_is_never_longer_than_the_cap(): void
    {
        $result = $this->run_([$this->students()[0]], ['*' => [$this->limited(600), $this->done()]]);

        $this->assertCount(60, $result['log']['delays']);
    }

    public function test_a_student_still_rate_limited_after_the_attempts_is_failed_with_a_retry_and_the_rest_carry_on(): void
    {
        $result = $this->run_($this->students(), [2 => [$this->limited(1)], '*' => [$this->done()]]);

        $this->assertSame([1, 2, 2, 2, 3, 4], $result['log']['requests'], 'Three attempts for student 2, then on to the others.');

        $failed = $this->state($result, 2);
        $this->assertSame('failed', $failed['state']);
        $this->assertTrue($failed['ruleOnly']);
        $this->assertStringContainsString('keeps their rule-based suggestions', $failed['note']);

        $this->assertSame('done', $this->state($result, 3)['state']);
        $this->assertSame('done', $this->state($result, 4)['state']);
        $this->assertSame(1, $result['problems']);
        $this->assertSame('Done, with 1 problem', $result['headline']);
    }

    public function test_retry_runs_just_that_student_and_clears_the_failure(): void
    {
        $result = $this->run_(
            $this->students(),
            [2 => [$this->limited(1), $this->limited(1), $this->limited(1), $this->done()], '*' => [$this->done()]],
            "await c.start()\nawait c.retry(c.items[1])",
        );

        $this->assertSame([1, 2, 2, 2, 3, 4, 2], $result['log']['requests'], 'Retry asked for that student alone.');
        $this->assertSame('done', $this->state($result, 2)['state']);
        $this->assertSame(0, $result['problems']);
        $this->assertSame('Done', $result['headline']);
    }

    public function test_a_network_failure_is_a_plain_message_and_does_not_stop_the_rest(): void
    {
        $result = $this->run_($this->students(), [3 => [['throw' => 'network']], '*' => [$this->done()]]);

        $this->assertSame([1, 2, 3, 4], $result['log']['requests']);
        $this->assertSame('failed', $this->state($result, 3)['state']);
        $this->assertStringContainsString('Could not reach the server', $this->state($result, 3)['note']);
        $this->assertSame('done', $this->state($result, 4)['state']);
    }

    public function test_a_hung_request_is_given_up_on_with_a_plain_message(): void
    {
        $result = $this->run_([$this->students()[0]], ['*' => [['throw' => 'abort']]]);

        $this->assertStringContainsString('took too long to answer', $this->state($result, 1)['note']);
    }

    public function test_an_expired_session_and_a_server_error_each_get_their_own_message(): void
    {
        $session = $this->run_([$this->students()[0]], ['*' => [['status' => 419, 'body' => []]]]);
        $server = $this->run_([$this->students()[0]], ['*' => [['status' => 500, 'body' => []]]]);
        $forbidden = $this->run_([$this->students()[0]], ['*' => [['status' => 403, 'body' => []]]]);

        $this->assertStringContainsString('session has expired', $this->state($session, 1)['note']);
        $this->assertStringContainsString('error 500', $this->state($server, 1)['note']);
        $this->assertStringContainsString('cannot generate suggestions', $this->state($forbidden, 1)['note']);
    }

    public function test_no_provider_or_a_used_up_allowance_is_not_a_failure(): void
    {
        $result = $this->run_($this->students(), ['*' => [['status' => 200, 'body' => ['status' => 'disabled', 'message' => 'AI suggestions are switched off.', 'counts' => ['ai' => 0, 'rule_based' => 4]]]]]);

        $this->assertSame(0, $result['problems']);
        $this->assertSame('done', $this->state($result, 1)['state']);
        $this->assertTrue($this->state($result, 1)['ruleOnly']);
        $this->assertStringContainsString('switched off', $this->state($result, 1)['note']);
    }

    public function test_a_provider_failure_marks_the_student_failed_but_keeps_the_rule_based_suggestion_in_the_message(): void
    {
        $result = $this->run_([$this->students()[0]], ['*' => [['status' => 200, 'body' => ['status' => 'failed', 'message' => 'The AI provider did not respond (HTTP 500).']]]]);

        $this->assertSame('failed', $this->state($result, 1)['state']);
        $this->assertStringContainsString('did not respond', $this->state($result, 1)['note']);
        $this->assertStringContainsString('keeps their rule-based suggestions', $this->state($result, 1)['note']);
    }

    public function test_stopping_leaves_the_rest_waiting_and_untouched(): void
    {
        $result = $this->run_($this->students(), ['*' => [$this->done()]], "const p = c.start()\nc.stop()\nawait p");

        $this->assertLessThan(4, count($result['log']['requests']));
        $this->assertFalse($result['running']);
        $this->assertStringStartsWith('Stopped after', $result['headline']);
    }

    public function test_it_can_be_started_again_and_never_runs_twice_at_once(): void
    {
        $result = $this->run_($this->students(), ['*' => [$this->done()]], "const first = c.start()\nconst second = c.start()\nawait Promise.all([first, second])");

        $this->assertSame([1, 2, 3, 4], $result['log']['requests'], 'The second start while running did nothing.');
    }
}
