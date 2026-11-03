<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\Submission;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsMarkingFixture;
use Tests\TestCase;

/** The "Submit for assessment" card: compact by default, the form opens on a button, and re-opens after a failed try. */
class SubmitCardTest extends TestCase
{
    use BuildsMarkingFixture;
    use RefreshDatabase;

    protected Document $doc;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Carbon::setTestNow('2026-11-03 10:00:00');

        $this->buildMarkingFixture();
        $this->assessment->update(['due_on' => '2026-11-10']);

        $this->doc = Document::create([
            'group_id' => $this->group->id, 'created_by' => $this->students['s1']->id, 'last_edited_by' => $this->students['s1']->id,
            'title' => 'Introduction', 'content' => '<p>Hello</p>',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    protected function page(): string
    {
        return $this->actingAs($this->students['s1'])->get(route('student.documents.group', $this->group))->assertOk()->getContent();
    }

    public function test_compact_state_without_a_submission_offers_submit_report(): void
    {
        $html = $this->page();

        $this->assertStringContainsString('data-submit-open', $html);
        $this->assertStringContainsString('Submit report', $html);
        $this->assertStringContainsString('Not submitted yet', $html);
        $this->assertDoesNotMatchRegularExpression('/data-submit-open[^>]*x-cloak/', $html, 'The primary button shows at first.');
        $this->assertMatchesRegularExpression('/data-submit-again[^>]*x-cloak|x-cloak[^>]*data-submit-again/', $html);
    }

    public function test_the_form_is_in_the_page_but_collapsed_by_default(): void
    {
        $html = $this->page();

        $this->assertStringContainsString('x-data="{ open: false, instant: false,', $html);
        $this->assertMatchesRegularExpression('/<div[^>]*cs-collapse[^>]*data-submit-collapse[^>]*inert|<div[^>]*data-submit-collapse[^>]*cs-collapse[^>]*inert/s', $html);
        $this->assertStringContainsString('data-submit-form', $html);
        $this->assertStringContainsString('data-submit-summary', $html);
        $this->assertStringContainsString('name="note"', $html);
        $this->assertStringContainsString('<textarea id="submit_note"', $html);
        $this->assertStringContainsString('Tick at least one document.', $html);
        $this->assertStringContainsString('data-order-badge', $html);
        $this->assertStringContainsString('min-[900px]:grid-cols-[minmax(0,3fr)_minmax(0,2fr)]', $html);
    }

    public function test_with_a_submission_the_button_is_submit_again_and_the_status_row_is_unchanged(): void
    {
        $this->actingAs($this->students['s1'])->post(route('student.submissions.store', $this->group), [
            'assessment_id' => $this->assessment->id, 'documents' => [$this->doc->id],
        ])->assertSessionHasNoErrors();

        $html = $this->page();

        $this->assertStringContainsString('Submit again', $html);
        $this->assertMatchesRegularExpression('/data-submit-open[^>]*x-cloak|x-cloak[^>]*data-submit-open/', $html, 'The primary one is hidden once something is handed in.');
        $this->assertDoesNotMatchRegularExpression('/data-submit-again[^>]*x-cloak/', $html);
        $this->assertStringContainsString('data-submission-current', $html);
        $this->assertStringContainsString('Submitted · On time', $html);
        $this->assertStringContainsString('Download PDF', $html);
        $this->assertStringContainsString('Withdraw', $html);
        $this->assertStringContainsString('x-data="{ open: false,', $html);

        Carbon::setTestNow('2026-11-11 08:00:00');
        $this->assertStringNotContainsString('Withdraw this submission?', $this->page());
    }

    public function test_after_a_failed_try_the_card_renders_open_with_old_input_and_the_error(): void
    {
        $response = $this->actingAs($this->students['s1'])->from(route('student.documents.group', $this->group))
            ->post(route('student.submissions.store', $this->group), [
                'assessment_id' => $this->assessment->id, 'documents' => [999999], 'note' => 'Keep this note',
            ]);
        $response->assertSessionHasErrors('submission');

        $html = $this->actingAs($this->students['s1'])->get(route('student.documents.group', $this->group))->getContent();

        $this->assertStringContainsString('x-data="{ open: true, instant: true,', $html);
        $this->assertStringContainsString('data-submission-error', $html);
        $this->assertStringContainsString('Keep this note', $html);
    }

    public function test_empty_states_show_no_button(): void
    {
        $this->doc->delete();
        $html = $this->page();

        $this->assertStringContainsString('Write a document first', $html);
        $this->assertStringNotContainsString('data-submit-toggle', $html);
        $this->assertSame(0, Submission::count());
    }
}
