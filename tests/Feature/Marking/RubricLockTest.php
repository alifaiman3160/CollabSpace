<?php

namespace Tests\Feature\Marking;

use App\Models\MarkApproval;
use App\Models\MarkSuggestion;
use App\Models\Rubric;
use App\Models\User;
use App\Support\Ai\AiManager;
use App\Support\Marking\ApprovalService;
use App\Support\Marking\SuggestionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Sleep;
use Tests\Concerns\BuildsMarkingFixture;
use Tests\TestCase;

/**
 * A rubric version is locked once any marks against it are approved. Until then
 * who decides each criterion, and what suggestions look at, can be changed on the
 * saved version; once locked they cannot.
 */
class RubricLockTest extends TestCase
{
    use BuildsMarkingFixture;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildMarkingFixture();
        Sleep::fake();
    }

    protected function showUrl(?Rubric $rubric = null): string
    {
        return route('lecturer.rubrics.show', [$this->subject, $rubric ?? $this->rubric]);
    }

    protected function putSettings(array $data, ?Rubric $rubric = null)
    {
        return $this->actingAs($this->lecturer)->put(route('lecturer.rubrics.evidence', [$this->subject, $rubric ?? $this->rubric]), $data);
    }

    protected function approve(string $student = 's1'): MarkApproval
    {
        $this->chooseEverything($student);

        return app(ApprovalService::class)->approve($this->lecturer, $this->rubric, $this->group, $this->students[$student]);
    }

    /** The settings of every criterion, to show nothing changed. */
    protected function settings(?Rubric $rubric = null): array
    {
        return ($rubric ?? $this->rubric)->criteria()->orderBy('rubric_criteria.id')->get(['rubric_criteria.id', 'rubric_criteria.mode', 'rubric_criteria.evidence_type'])->map->only(['id', 'mode', 'evidence_type'])->all();
    }

    /*
    | isLocked
    */

    public function test_a_version_is_locked_once_marks_are_approved_and_not_before(): void
    {
        $this->assertFalse($this->rubric->isLocked());

        $this->chooseEverything('s1');
        $this->assertFalse($this->rubric->fresh()->isLocked(), 'Choices alone do not lock it.');

        app(ApprovalService::class)->approve($this->lecturer, $this->rubric, $this->group, $this->students['s1']);
        $this->assertTrue($this->rubric->fresh()->isLocked());
    }

    public function test_it_stays_locked_while_any_one_approval_stands(): void
    {
        $this->approve('s1');
        $this->approve('s2');

        app(ApprovalService::class)->revoke($this->lecturer, $this->rubric, $this->group, $this->students['s1']);
        $this->assertTrue($this->rubric->fresh()->isLocked(), 'The second student is still approved.');

        app(ApprovalService::class)->revoke($this->lecturer, $this->rubric, $this->group, $this->students['s2']);
        $this->assertFalse($this->rubric->fresh()->isLocked());
    }

    public function test_approvals_lock_only_their_own_version(): void
    {
        $second = $this->saveSampleRubric();

        $this->approve('s1');

        $this->assertTrue($this->rubric->fresh()->isLocked());
        $this->assertFalse($second->fresh()->isLocked(), 'A new version is the way past a lock.');
    }

    public function test_a_locked_version_cannot_be_deleted_and_an_unlocked_one_can(): void
    {
        $this->approve('s1');

        $this->actingAs($this->lecturer)->delete(route('lecturer.rubrics.destroy', [$this->subject, $this->rubric]))->assertSessionHasErrors('status');
        $this->assertNotNull(Rubric::find($this->rubric->id));

        app(ApprovalService::class)->revoke($this->lecturer, $this->rubric, $this->group, $this->students['s1']);

        $this->actingAs($this->lecturer)->delete(route('lecturer.rubrics.destroy', [$this->subject, $this->rubric]))->assertSessionHasNoErrors();
    }

    /*
    | Changing a criterion's mode on an unlocked version
    */

    public function test_the_saved_rubric_offers_who_decides_each_criterion_when_unlocked(): void
    {
        $html = $this->actingAs($this->lecturer)->get($this->showUrl())->assertOk()->getContent();

        $this->assertSame(12, preg_match_all('/<select id="mode-\d+" name="mode\[\d+\]"/', $html));
        $this->assertStringNotContainsString('data-locked-notice', $html);
        $this->assertStringContainsString('Save evidence types', $html);
        $this->assertSame(0, preg_match_all('/<select id="mode-\d+"[^>]*\sdisabled[\s>=]/', $html), 'Every mode select is editable.');
    }

    public function test_a_criterion_can_be_made_manual_and_loses_its_evidence_and_suggestions(): void
    {
        config(['ai.driver' => 'none']);
        app()->forgetInstance(AiManager::class);
        app()->forgetInstance(\App\Support\Ai\AiGateway::class);
        app(SuggestionService::class)->run($this->lecturer, $this->rubric->fresh(), $this->group->fresh(), false);

        $criterion = $this->criterion('Collaboration');
        $this->assertSame('collaboration', $criterion->evidence_type);
        $this->assertGreaterThan(0, MarkSuggestion::where('rubric_criterion_id', $criterion->id)->count());

        $this->putSettings(['mode' => [$criterion->id => 'manual']])
            ->assertRedirect($this->showUrl())
            ->assertSessionHas('status', 'Criterion settings saved.');

        $criterion->refresh();
        $this->assertSame('manual', $criterion->mode);
        $this->assertNull($criterion->evidence_type);
        $this->assertSame(0, MarkSuggestion::where('rubric_criterion_id', $criterion->id)->count(), 'Suggestions made for the old mode are gone.');
        $this->assertGreaterThan(0, MarkSuggestion::where('rubric_criterion_id', $this->criterion('Communication')->id)->count(), 'Other criteria are untouched.');
    }

    public function test_a_manual_criterion_can_be_made_suggested_and_then_given_evidence(): void
    {
        $design = $this->criterion('Design');
        $this->assertSame('manual', $design->mode);

        $this->putSettings(['mode' => [$design->id => 'suggested']])->assertSessionHasNoErrors();
        $this->assertSame('suggested', $design->fresh()->mode);
        $this->assertNull($design->fresh()->evidence_type);

        // Now it shows its evidence control, and can be set.
        $this->assertStringContainsString('name="evidence['.$design->id.']"', $this->actingAs($this->lecturer)->get($this->showUrl())->getContent());

        $this->putSettings(['evidence' => [$design->id => 'task_completion']])->assertSessionHasNoErrors();
        $this->assertSame('task_completion', $design->fresh()->evidence_type);
    }

    public function test_a_criterion_cannot_become_suggested_in_a_section_with_no_scoring_formula(): void
    {
        $design = $this->criterion('Design');
        $design->section->update(['scoring' => null]);

        $this->putSettings(['mode' => [$design->id => 'suggested']])->assertSessionHasErrors('status');

        $this->assertSame('manual', $design->fresh()->mode);
    }

    public function test_a_refused_change_changes_nothing_at_all(): void
    {
        $design = $this->criterion('Design');
        $design->section->update(['scoring' => null]);
        $before = $this->settings();

        $this->putSettings([
            'mode' => [$design->id => 'suggested', $this->criterion('Collaboration')->id => 'manual'],
            'evidence' => [$this->criterion('Communication')->id => 'none'],
        ])->assertSessionHasErrors('status');

        $this->assertSame($before, $this->settings());
    }

    public function test_unrecognised_values_are_refused(): void
    {
        $criterion = $this->criterion('Collaboration');

        $this->putSettings(['mode' => [$criterion->id => 'automatic']])->assertSessionHasErrors('mode.'.$criterion->id);
        $this->putSettings(['evidence' => [$criterion->id => 'telepathy']])->assertSessionHasErrors('evidence.'.$criterion->id);

        $this->assertSame('suggested', $criterion->fresh()->mode);
        $this->assertSame('collaboration', $criterion->fresh()->evidence_type);
    }

    public function test_another_versions_criteria_are_not_touched(): void
    {
        $second = $this->saveSampleRubric();
        $theirs = $this->criterion('Collaboration', $second);

        $this->putSettings(['mode' => [$theirs->id => 'manual']])->assertSessionHasNoErrors();

        $this->assertSame('suggested', $theirs->fresh()->mode);
    }

    /*
    | Locked
    */

    public function test_a_locked_version_refuses_to_change_a_mode(): void
    {
        $this->approve('s1');
        $before = $this->settings();
        $criterion = $this->criterion('Collaboration');

        $this->putSettings(['mode' => [$criterion->id => 'manual']])
            ->assertRedirect($this->showUrl())
            ->assertSessionHasErrors(['status' => 'This version has approved marks, so its evidence types and criterion modes can no longer be changed. Upload a new version instead.']);

        $this->assertSame($before, $this->settings());
    }

    public function test_a_locked_version_refuses_to_change_an_evidence_type(): void
    {
        $this->approve('s1');
        $before = $this->settings();

        $this->putSettings(['evidence' => [$this->criterion('Collaboration')->id => 'task_completion']])->assertSessionHasErrors('status');

        $this->assertSame($before, $this->settings());
    }

    public function test_a_locked_version_refuses_even_a_change_that_changes_nothing(): void
    {
        $this->approve('s1');

        $this->putSettings(['mode' => [$this->criterion('Collaboration')->id => 'suggested']])->assertSessionHasErrors('status');
    }

    public function test_a_locked_version_leaves_its_suggestions_alone(): void
    {
        config(['ai.driver' => 'none']);
        app()->forgetInstance(AiManager::class);
        app()->forgetInstance(\App\Support\Ai\AiGateway::class);
        app(SuggestionService::class)->run($this->lecturer, $this->rubric->fresh(), $this->group->fresh(), false);

        $this->approve('s1');
        $count = MarkSuggestion::count();

        $this->putSettings(['mode' => [$this->criterion('Collaboration')->id => 'manual']])->assertSessionHasErrors('status');

        $this->assertSame($count, MarkSuggestion::count());
    }

    public function test_the_locked_page_says_so_and_offers_no_way_to_change_it(): void
    {
        $this->approve('s1');

        $html = $this->actingAs($this->lecturer)->get($this->showUrl())->assertOk()->getContent();

        $this->assertStringContainsString('data-locked-notice', $html);
        $this->assertStringContainsString('This version is locked.', $html);
        $this->assertStringNotContainsString('Save evidence types', $html);
        $this->assertSame(12, preg_match_all('/<select id="mode-\d+"[^>]*\sdisabled[\s>=]/', $html), 'Every mode select is disabled.');
        $this->assertSame(5, preg_match_all('/<select id="evidence-\d+"[^>]*\sdisabled[\s>=]/', $html), 'And every evidence select.');
    }

    public function test_revoking_every_approval_reopens_the_version(): void
    {
        $this->approve('s1');
        app(ApprovalService::class)->revoke($this->lecturer, $this->rubric, $this->group, $this->students['s1']);

        $this->putSettings(['mode' => [$this->criterion('Collaboration')->id => 'manual']])->assertSessionHasNoErrors();

        $this->assertSame('manual', $this->criterion('Collaboration')->mode);
        $this->assertStringNotContainsString('data-locked-notice', $this->actingAs($this->lecturer)->get($this->showUrl())->getContent());
    }

    /*
    | Who
    */

    public function test_another_lecturer_cannot_change_modes_and_a_student_is_forbidden(): void
    {
        $other = User::factory()->lecturer()->create(['must_change_password' => false]);
        $criterion = $this->criterion('Collaboration');

        $this->actingAs($other)->put(route('lecturer.rubrics.evidence', [$this->subject, $this->rubric]), ['mode' => [$criterion->id => 'manual']])->assertNotFound();
        $this->actingAs($this->students['s1'])->put(route('lecturer.rubrics.evidence', [$this->subject, $this->rubric]), ['mode' => [$criterion->id => 'manual']])->assertForbidden();

        $this->assertSame('suggested', $criterion->fresh()->mode);
    }
}
