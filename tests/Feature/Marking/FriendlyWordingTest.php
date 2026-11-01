<?php

namespace Tests\Feature\Marking;

use App\Models\MarkApproval;
use App\Models\MarkSuggestion;
use App\Support\Ai\AiException;
use App\Support\Ai\AiGateway;
use App\Support\Ai\AiManager;
use App\Support\Ai\Drivers\FakeDriver;
use App\Support\Marking\ApprovalService;
use App\Support\Marking\ChoiceRecorder;
use App\Support\Marking\EvidenceReport;
use App\Support\Marking\SuggestionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsMarkingFixture;
use Tests\TestCase;

/**
 * What the lecturer reads while marking sounds like a friendly product: no provider name, no setup talk,
 * no "rule-based" or "baseline". The technical record is kept where the audit needs it.
 */
class FriendlyWordingTest extends TestCase
{
    use BuildsMarkingFixture;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildMarkingFixture();
    }

    /** Words a lecturer should never meet on a marking screen. */
    private const JARGON = ['Groq', 'provider', 'Rule-based', 'rule-based', 'baseline', 'Baseline', 'insufficient data', 'Insufficient data', 'evidence type', 'rubric version', 'Rubric version', 'switched off', 'API key', 'AI suggestions from', 'Both are advice'];

    protected function assertPlain(string $html, string $where): void
    {
        $text = strip_tags(preg_replace('/<(script|style)\b.*?<\/\1>/s', '', $html));

        foreach (self::JARGON as $word) {
            $this->assertStringNotContainsString($word, $text, "{$where} still says “{$word}”.");
        }
    }

    protected function useFakeAi(): void
    {
        config(['ai.driver' => 'fake']);
        app()->forgetInstance(AiManager::class);
        app()->forgetInstance(AiGateway::class);
        FakeDriver::reset();
        FakeDriver::respondWith(fn ($payload) => ['suggestions' => array_map(
            fn ($c) => ['criterion' => $c['id'], 'level' => $c['levels'][0]['id'], 'rationale' => $this->cite($c)],
            $payload['criteria'],
        )]);
    }

    public function test_the_group_page_has_the_friendly_panel_and_buttons(): void
    {
        $html = $this->actingAs($this->lecturer)->get(route('lecturer.marking.group', [$this->rubric, $this->group]))->assertOk()->getContent();

        $this->assertStringContainsString('Suggested levels', $html);
        $this->assertStringContainsString('We suggest a level for each criterion from what every student did in the group. You make the final decision.', $html);
        $this->assertStringContainsString('Refresh suggestions', $html);
        $this->assertStringContainsString('Use all suggestions', $html);
        $this->assertStringContainsString('Download evidence report', $html);
        $this->assertStringContainsString('data-tooltip="Fills in the suggested level for every student who is not approved yet. It does not approve anything."', $html);
        $this->assertStringNotContainsString('Export evidence report', $html);
        $this->assertPlain($html, 'the group page');
    }

    public function test_the_group_page_never_names_the_provider_even_when_one_is_set_up(): void
    {
        config(['ai.driver' => 'groq', 'ai.drivers.groq.key' => 'gsk-test-KEY', 'ai.drivers.groq.model' => 'some-model', 'ai.drivers.groq.base_url' => 'https://example.test/v1']);
        app()->forgetInstance(AiManager::class);
        app()->forgetInstance(AiGateway::class);

        $html = $this->actingAs($this->lecturer)->get(route('lecturer.marking.group', [$this->rubric, $this->group]))->getContent();

        $this->assertStringNotContainsString('Groq', $html);
        $this->assertStringNotContainsString('some-model', $html);
        $this->assertStringNotContainsString('gsk-test', $html);
    }

    public function test_the_student_page_and_the_progress_script_read_plainly(): void
    {
        $this->actingAs($this->lecturer);

        $this->assertPlain($this->get(route('lecturer.marking.student', [$this->rubric, $this->group, $this->students['s1']]))->getContent(), 'the student page');

        $js = file_get_contents(resource_path('js/marking-suggest.js'));
        // The strings the page shows, not the explanations in its comments.
        $strings = implode("\n", array_filter(explode("\n", $js), fn ($l) => ! preg_match('/^\s*(\/\/|\*|\/\*)/', $l)));

        foreach (['provider', 'rule-based', 'Rule-based', 'Generating'] as $word) {
            $this->assertStringNotContainsString($word, $strings, $word);
        }
        $this->assertStringContainsString('Working on it... ', $strings);
    }

    public function test_the_messages_a_lecturer_can_be_shown_are_plain(): void
    {
        foreach ([
            AiException::requestFailed('HTTP 500')->getMessage(),
            AiException::invalidResponse('no json')->getMessage(),
            AiException::limitReached(200)->getMessage(),
            SuggestionService::AI_OFF,
        ] as $message) {
            foreach (['provider', 'Rule-based', 'rule-based', 'HTTP', 'API'] as $word) {
                $this->assertStringNotContainsString($word, $message);
            }
        }

        $this->assertSame('HTTP 500', AiException::requestFailed('HTTP 500')->detail, 'The technical detail is kept apart.');
        $this->assertSame("You have reached today's limit of 200 AI requests. You still see the suggestions from the numbers. The limit starts again tomorrow.", AiException::limitReached(200)->getMessage());
    }

    public function test_the_audit_record_still_names_the_provider_and_model(): void
    {
        $this->useFakeAi();
        $rubric = $this->rubric->fresh();

        app(SuggestionService::class)->suggestForStudent($this->lecturer, $rubric, $this->group, $this->students['s1'], true);

        $row = MarkSuggestion::where('student_id', $this->students['s1']->id)->whereNotNull('ai_level_id')->first();
        $this->assertSame('fake', $row->provider);
        $this->assertSame('fake-model', $row->model);

        $criteria = $rubric->sections->flatMap->criteria;
        $levels = $criteria->mapWithKeys(fn ($c) => [$c->id => $c->levels->sortBy('marks')->last()->id])->all();
        $reasons = $criteria->mapWithKeys(fn ($c) => [$c->id => 'My own choice.'])->all();

        app(ChoiceRecorder::class)->save($this->lecturer, $rubric, $this->group, $this->students['s1'], $levels, $reasons);
        app(ApprovalService::class)->approve($this->lecturer, $rubric, $this->group, $this->students['s1']);

        $snapshot = MarkApproval::first()->snapshot;
        $criterion = collect($snapshot['sections'])->flatMap(fn ($s) => $s['criteria'])->first(fn ($c) => ($c['provider'] ?? null) !== null);

        $this->assertSame('fake', $criterion['provider'], 'The snapshot keeps it.');
        $this->assertSame('fake-model', $criterion['model']);

        $csv = implode("\n", array_map(fn ($r) => implode(',', $r), app(EvidenceReport::class)->rows($rubric, $this->group)));
        $this->assertStringContainsString('fake / fake-model', $csv, 'And so does the evidence report.');
    }
}
