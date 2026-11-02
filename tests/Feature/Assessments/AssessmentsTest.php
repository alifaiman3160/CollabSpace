<?php

namespace Tests\Feature\Assessments;

use App\Models\ActivityLog;
use App\Models\AppNotification;
use App\Models\Assessment;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\MarkApproval;
use App\Models\MarkChoice;
use App\Models\Rubric;
use App\Models\Subject;
use App\Models\User;
use App\Support\Assessments\Backfill;
use App\Support\ContributionReport;
use App\Support\Marking\GroupFacts;
use App\Support\Rubric\RubricStore;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsMarkingFixture;
use Tests\TestCase;

/** Several assessments per subject: the data, the dates, the lecturer's card, and the per-assessment scoping. */
class AssessmentsTest extends TestCase
{
    use BuildsMarkingFixture;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-11-03 10:00', 'Asia/Kuala_Lumpur'));
        $this->buildMarkingFixture(withRubric: false);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    protected function assessment(string $title, ?string $start, ?string $due, string $type = 'assignment', ?Subject $subject = null): Assessment
    {
        $subject ??= $this->subject;

        return Assessment::create([
            'subject_id' => $subject->id, 'title' => $title, 'type' => $type, 'starts_on' => $start, 'due_on' => $due,
            'position' => ((int) $subject->assessments()->max('position')) + 1,
        ]);
    }

    /** Two assessments with windows: A in October, B in November and December. */
    protected function two(): array
    {
        Assessment::query()->where('subject_id', $this->subject->id)->delete();

        return [
            $this->assessment('Assignment 1', '2026-10-01', '2026-10-31'),
            $this->assessment('Project', '2026-11-01', '2026-12-15', 'project'),
        ];
    }

    protected function post_(array $extra = [], ?User $as = null)
    {
        return $this->actingAs($as ?? $this->lecturer)->post(route('lecturer.subjects.assessments.store', $this->subject), $extra + [
            'title' => 'Assignment 2', 'type' => 'assignment',
        ]);
    }

    /** The fixture records its own activity at "now"; tests about windows start from an empty log. */
    protected function emptyLog(): void
    {
        ActivityLog::query()->delete();
    }

    protected function log(string $when, ?User $who = null, string $type = 'message_sent', int $weight = 1): void
    {
        $who ??= $this->students['s1'];
        $log = ActivityLog::create(['user_id' => $who->id, 'group_id' => $this->group->id, 'activity_type' => $type, 'weight' => $weight]);
        $log->forceFill(['created_at' => Carbon::parse($when, 'Asia/Kuala_Lumpur')])->save();
    }

    /*
    | The data and the backfill
    */

    public function test_the_backfill_gives_every_subject_one_project_assessment_and_adopts_its_rubrics(): void
    {
        Assessment::query()->delete();
        $this->subject->update(['project_deadline' => '2026-12-01']);
        $other = Subject::create(['lecturer_id' => $this->lecturer->id, 'code' => 'OTH1', 'name' => 'Other', 'group_size' => 4]);

        // A rubric from before assessments existed: no assessment_id.
        $id = DB::table('rubrics')->insertGetId(['subject_id' => $this->subject->id, 'assessment_id' => null, 'name' => 'Old', 'version' => 1, 'is_current' => true, 'created_at' => now(), 'updated_at' => now()]);
        $approval = DB::table('mark_approvals')->insertGetId([
            'rubric_id' => $id, 'group_id' => $this->group->id, 'student_id' => $this->students['s1']->id,
            'approved_by' => $this->lecturer->id, 'approved_at' => now(), 'snapshot' => json_encode(['total' => 5]), 'audit' => json_encode([]), 'total' => 5,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        Backfill::run();

        $default = Assessment::where('subject_id', $this->subject->id)->sole();
        $this->assertSame('Project', $default->title);
        $this->assertSame('project', $default->type);
        $this->assertSame('2026-12-01', $default->due_on->toDateString());
        $this->assertNull($default->starts_on);
        $this->assertSame($default->id, DB::table('rubrics')->where('id', $id)->value('assessment_id'));
        $this->assertSame(1, Assessment::where('subject_id', $other->id)->count(), 'Even a subject with no rubric.');
        $this->assertDatabaseHas('mark_approvals', ['id' => $approval, 'rubric_id' => $id]);
    }

    public function test_running_the_backfill_again_changes_nothing(): void
    {
        Assessment::query()->delete();
        $this->subject->update(['project_deadline' => '2026-12-01']);

        Backfill::run();
        $before = [Assessment::count(), DB::table('rubrics')->whereNull('assessment_id')->count()];
        Backfill::run();
        Backfill::run();

        $this->assertSame($before, [Assessment::count(), DB::table('rubrics')->whereNull('assessment_id')->count()]);
        $this->assertSame('2026-12-01', $this->subject->fresh()->project_deadline->toDateString(), 'subjects.project_deadline is kept.');
    }

    public function test_it_never_moves_a_rubric_that_already_has_an_assessment(): void
    {
        [$a, $b] = $this->two();
        $rubric = $this->saveSampleRubric(null, $b);

        Backfill::run();

        $this->assertSame($b->id, $rubric->fresh()->assessment_id);
        $this->assertSame(2, Assessment::where('subject_id', $this->subject->id)->count());
    }

    public function test_a_rubric_made_without_naming_an_assessment_gets_the_subjects_default(): void
    {
        $rubric = $this->saveSampleRubric();

        $this->assertSame($this->assessment->id, $rubric->assessment_id);
        $this->assertSame(1, Assessment::where('subject_id', $this->subject->id)->count());
    }

    /*
    | Per-assessment rubric versions
    */

    public function test_versions_and_current_are_counted_per_assessment(): void
    {
        [$a, $b] = $this->two();

        $a1 = $this->saveSampleRubric(null, $a);
        $a2 = $this->saveSampleRubric(null, $a);
        $b1 = $this->saveSampleRubric(null, $b);

        $this->assertSame([1, 2, 1], [$a1->version, $a2->version, $b1->version]);
        $this->assertFalse($a1->fresh()->is_current);
        $this->assertTrue($a2->fresh()->is_current);
        $this->assertTrue($b1->fresh()->is_current, 'Saving A\'s second version did not touch B\'s current rubric.');
        $this->assertSame(2, Rubric::where('subject_id', $this->subject->id)->where('is_current', true)->count());
    }

    public function test_the_rubric_page_has_the_selector_and_uploads_to_the_chosen_assessment(): void
    {
        [$a, $b] = $this->two();
        $this->saveSampleRubric(null, $b);

        $html = $this->actingAs($this->lecturer)->get(route('lecturer.rubrics', [$this->subject, 'assessment' => $b->id]))->assertOk()->getContent();

        $this->assertStringContainsString('data-assessment-filter', $html);
        $this->assertStringContainsString('name="assessment" value="'.$b->id.'"', $html);
        $this->assertStringContainsString('Saved versions', $html);

        $other = $this->actingAs($this->lecturer)->get(route('lecturer.rubrics', [$this->subject, 'assessment' => $a->id]))->assertOk()->getContent();
        $this->assertStringNotContainsString('Saved versions', $other, 'A has no rubric yet.');
    }

    /*
    | Which assessment is active, and the project deadline
    */

    public function test_the_active_assessment_before_during_in_a_gap_and_after(): void
    {
        Assessment::query()->where('subject_id', $this->subject->id)->delete();
        $a = $this->assessment('A', '2026-10-01', '2026-10-31');
        $b = $this->assessment('B', '2026-12-01', '2026-12-20');
        $subject = fn () => Subject::find($this->subject->id);
        $at = fn (string $day) => Carbon::parse($day.' 12:00', 'Asia/Kuala_Lumpur');

        $this->assertSame($a->id, $subject()->activeAssessment($at('2026-09-01'))->id, 'Before everything: the next one coming up.');
        $this->assertSame($a->id, $subject()->activeAssessment($at('2026-10-01'))->id, 'The start day is inside.');
        $this->assertSame($a->id, $subject()->activeAssessment($at('2026-10-31'))->id, 'The due day is inside.');
        $this->assertSame($b->id, $subject()->activeAssessment($at('2026-11-15'))->id, 'In the gap: the next one.');
        $this->assertSame($b->id, $subject()->activeAssessment($at('2026-12-20'))->id);
        $this->assertSame($b->id, $subject()->activeAssessment($at('2027-02-01'))->id, 'After everything: the most recent.');
    }

    public function test_no_assessment_means_no_active_one_and_the_subjects_own_deadline_is_the_fallback(): void
    {
        Assessment::query()->where('subject_id', $this->subject->id)->delete();
        $this->subject->update(['project_deadline' => '2026-12-05']);
        $subject = Subject::find($this->subject->id);

        $this->assertNull($subject->activeAssessment());
        $this->assertSame('2026-12-05', $subject->projectDeadline()->toDateString());
        $this->assertSame(32, $subject->daysToProjectDeadline());
    }

    public function test_the_project_deadline_is_the_active_assessments_due_date(): void
    {
        [$a, $b] = $this->two();
        $this->subject->update(['project_deadline' => '2027-01-01']);

        $this->assertSame('2026-12-15', Subject::find($this->subject->id)->projectDeadline()->toDateString(), 'Today (3 Nov) is inside the Project window.');

        Carbon::setTestNow(Carbon::parse('2026-10-10 10:00', 'Asia/Kuala_Lumpur'));
        $this->assertSame('2026-10-31', Subject::find($this->subject->id)->projectDeadline()->toDateString());
    }

    public function test_a_subject_with_an_assessment_without_a_due_date_falls_back_to_its_own_deadline(): void
    {
        Assessment::query()->where('subject_id', $this->subject->id)->delete();
        $this->assessment('Open', null, null);
        $this->subject->update(['project_deadline' => '2026-12-05']);

        $this->assertSame('2026-12-05', Subject::find($this->subject->id)->projectDeadline()->toDateString());
    }

    public function test_the_reminder_command_still_finds_a_subject_with_only_the_default_assessment(): void
    {
        Assessment::query()->where('subject_id', $this->subject->id)->delete();
        $default = Assessment::defaultFor(Subject::find($this->subject->id));
        $default->update(['due_on' => null]);
        $this->subject->update(['project_deadline' => now()->addDays(2)->toDateString()]);

        $this->artisan('collabspace:deadline-reminders')->assertSuccessful();

        $this->assertSame(4, AppNotification::where('type', 'project_deadline')->count(), 'All four students of the group are told.');
    }

    public function test_the_reminder_uses_the_active_assessments_due_date(): void
    {
        Assessment::query()->where('subject_id', $this->subject->id)->delete();
        $this->assessment('Assignment 1', '2026-10-01', now()->addDays(2)->toDateString());
        $this->subject->update(['project_deadline' => now()->addDays(60)->toDateString()]);

        $this->artisan('collabspace:deadline-reminders')->assertSuccessful();

        $this->assertSame(4, AppNotification::where('type', 'project_deadline')->count());
    }

    /*
    | The lecturer's card
    */

    public function test_the_edit_page_lists_the_assessments_with_active_marker_and_actions(): void
    {
        [$a, $b] = $this->two();

        $html = $this->actingAs($this->lecturer)->get(route('lecturer.subjects.edit', $this->subject))->assertOk()->getContent();

        $this->assertStringContainsString('data-assessments', $html);
        $this->assertSame(2, substr_count($html, 'data-assessment-dates'));
        $this->assertStringContainsString('Assignment 1', $html);
        $this->assertSame(1, preg_match_all('/<span[^>]*data-assessment-active[^>]*>/', $html), 'Only the Project is on today.');
        $this->assertStringContainsString(route('lecturer.subjects.assessments.update', [$this->subject, $a]), $html);
        $this->assertStringContainsString(route('lecturer.subjects.assessments.destroy', [$this->subject, $b]), $html);
        $this->assertStringContainsString('counts toward none of them', $html);
        $this->assertStringNotContainsString('data-due-per-assessment', $html);
    }

    public function test_the_project_due_date_is_editable_only_while_there_are_no_assessments(): void
    {
        Assessment::query()->where('subject_id', $this->subject->id)->delete();

        $html = $this->actingAs($this->lecturer)->get(route('lecturer.subjects.edit', $this->subject))->getContent();
        $this->assertStringNotContainsString('data-due-per-assessment', $html);

        $this->actingAs($this->lecturer)->put(route('lecturer.subjects.update', $this->subject), ['project_deadline' => '2026-12-12', 'group_size' => 4])->assertSessionHasNoErrors();
        $this->assertSame('2026-12-12', $this->subject->fresh()->project_deadline->toDateString());

        Assessment::defaultFor($this->subject->fresh());
        $this->actingAs($this->lecturer)->put(route('lecturer.subjects.update', $this->subject), ['project_deadline' => '2027-01-01', 'group_size' => 4]);
        $this->assertSame('2026-12-12', $this->subject->fresh()->project_deadline->toDateString(), 'Ignored once assessments exist.');
    }

    public function test_a_lecturer_adds_edits_and_deletes_an_assessment(): void
    {
        [$a, $b] = $this->two();

        $this->post_(['title' => 'Assignment 2', 'starts_on' => '2027-01-05', 'due_on' => '2027-01-20', 'weight' => '15.5'])->assertSessionHasNoErrors();
        $c = Assessment::where('title', 'Assignment 2')->sole();
        $this->assertSame(3, $c->position);
        $this->assertSame('15.50', (string) $c->weight);

        $this->actingAs($this->lecturer)->put(route('lecturer.subjects.assessments.update', [$this->subject, $c]), [
            'title' => 'Assignment 2b', 'type' => 'project', 'starts_on' => '2027-01-06', 'due_on' => '2027-01-21',
        ])->assertSessionHasNoErrors();
        $this->assertSame('Assignment 2b', $c->fresh()->title);
        $this->assertSame('project', $c->fresh()->type);
        $this->assertNull($c->fresh()->weight);

        $this->actingAs($this->lecturer)->delete(route('lecturer.subjects.assessments.destroy', [$this->subject, $c]))->assertSessionHasNoErrors();
        $this->assertNull(Assessment::find($c->id));
    }

    public function test_only_the_owning_lecturer_can_manage_assessments(): void
    {
        [$a] = $this->two();
        $stranger = User::factory()->lecturer()->create(['must_change_password' => false]);
        $student = $this->students['s1'];
        $admin = User::factory()->admin()->create(['must_change_password' => false]);

        $this->post_([], $stranger)->assertNotFound();
        $this->actingAs($stranger)->put(route('lecturer.subjects.assessments.update', [$this->subject, $a]), ['title' => 'Hacked', 'type' => 'project'])->assertNotFound();
        $this->actingAs($stranger)->delete(route('lecturer.subjects.assessments.destroy', [$this->subject, $a]))->assertNotFound();

        foreach ([$student, $admin] as $user) {
            $this->post_([], $user)->assertForbidden();
            $this->actingAs($user)->delete(route('lecturer.subjects.assessments.destroy', [$this->subject, $a]))->assertForbidden();
        }

        $this->assertSame('Assignment 1', $a->fresh()->title);
        $this->assertSame(2, Assessment::where('subject_id', $this->subject->id)->count());
    }

    public function test_an_assessment_cannot_be_changed_through_another_subjects_url(): void
    {
        [$a] = $this->two();
        $mine = Subject::create(['lecturer_id' => $this->lecturer->id, 'code' => 'OTH1', 'name' => 'Other', 'group_size' => 4]);

        $this->actingAs($this->lecturer)->put(route('lecturer.subjects.assessments.update', [$mine, $a]), ['title' => 'Moved', 'type' => 'project'])->assertNotFound();
        $this->actingAs($this->lecturer)->delete(route('lecturer.subjects.assessments.destroy', [$mine, $a]))->assertNotFound();
    }

    /*
    | Validation
    */

    public function test_titles_are_required_short_and_unique_within_the_subject(): void
    {
        $this->two();

        $this->post_(['title' => ''])->assertSessionHasErrors('title', errorBag: 'assessment');
        $this->post_(['title' => str_repeat('x', 81)])->assertSessionHasErrors('title', errorBag: 'assessment');
        $this->post_(['title' => 'Assignment 1', 'starts_on' => '2027-01-05'])->assertSessionHasErrors('title', errorBag: 'assessment');
        $this->post_(['type' => 'exam'])->assertSessionHasErrors('type', errorBag: 'assessment');
        $this->post_(['weight' => '120'])->assertSessionHasErrors('weight', errorBag: 'assessment');

        $this->assertSame(2, Assessment::where('subject_id', $this->subject->id)->count());
    }

    public function test_the_start_cannot_be_after_the_due_date(): void
    {
        $this->post_(['starts_on' => '2027-02-01', 'due_on' => '2027-01-01'])->assertSessionHasErrors('due_on', errorBag: 'assessment');
    }

    public function test_windows_in_one_subject_cannot_overlap(): void
    {
        $this->two();   // October, then 1 Nov to 15 Dec

        foreach ([['2026-10-15', '2026-11-05'], ['2026-10-31', '2026-10-31'], ['2026-12-15', '2027-01-10'], ['2026-09-01', '2026-10-01'], ['2026-12-01', null], [null, '2026-10-10']] as [$start, $due]) {
            $this->post_(['title' => 'Overlap '.$start.$due, 'starts_on' => $start, 'due_on' => $due])->assertSessionHasErrors('starts_on', errorBag: 'assessment');
        }

        $this->post_(['title' => 'Fits', 'starts_on' => '2026-12-16', 'due_on' => '2027-01-10'])->assertSessionHasNoErrors();
        $this->post_(['title' => 'Also fits', 'starts_on' => '2026-08-01', 'due_on' => '2026-09-30'])->assertSessionHasNoErrors();
    }

    public function test_an_assessment_without_dates_overlaps_everything_so_it_must_be_dated_first(): void
    {
        // The subject has only the default (no dates).
        $this->post_(['starts_on' => '2027-01-05', 'due_on' => '2027-01-20'])->assertSessionHasErrors('starts_on', errorBag: 'assessment');

        $this->assertStringContainsString('give it dates first', session('errors')->getBag('assessment')->first('starts_on'));
    }

    public function test_a_subject_has_at_most_six_assessments(): void
    {
        Assessment::query()->where('subject_id', $this->subject->id)->delete();

        foreach (range(1, 6) as $i) {
            $this->post_(['title' => "Task {$i}", 'starts_on' => "2026-0{$i}-01", 'due_on' => "2026-0{$i}-20"])->assertSessionHasNoErrors();
        }

        $this->post_(['title' => 'Seventh', 'starts_on' => '2026-12-01', 'due_on' => '2026-12-20'])->assertSessionHasErrors('title', errorBag: 'assessment');
        $this->assertSame(6, Assessment::where('subject_id', $this->subject->id)->count());

        $html = $this->actingAs($this->lecturer)->get(route('lecturer.subjects.edit', $this->subject))->getContent();
        $this->assertStringContainsString('data-assessments-full', $html);
    }

    /*
    | Deleting, and editing under approved marks
    */

    public function test_an_assessment_with_marks_approvals_or_suggestions_cannot_be_deleted(): void
    {
        [$a, $b] = $this->two();
        $rubric = $this->saveSampleRubric(null, $a);
        $criterion = $rubric->criteria()->first();

        // 1. suggestions
        \App\Models\MarkSuggestion::query()->forceCreate([
            'rubric_criterion_id' => $criterion->id, 'group_id' => $this->group->id, 'student_id' => $this->students['s1']->id,
            'evidence_type' => 'task_completion', 'facts' => json_encode([]), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->actingAs($this->lecturer)->delete(route('lecturer.subjects.assessments.destroy', [$this->subject, $a]))->assertSessionHasErrors(null, 'assessment');
        $this->assertStringContainsString('it has suggestions', session('errors')->getBag('assessment')->first('assessment_delete'));
        \App\Models\MarkSuggestion::query()->delete();

        // 2. marks
        MarkChoice::query()->forceCreate([
            'rubric_id' => $rubric->id, 'rubric_criterion_id' => $criterion->id, 'group_id' => $this->group->id, 'student_id' => $this->students['s1']->id,
            'rubric_level_id' => $criterion->levels()->first()->id, 'source' => 'manual', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->actingAs($this->lecturer)->delete(route('lecturer.subjects.assessments.destroy', [$this->subject, $a]))->assertSessionHasErrors(null, 'assessment');
        $this->assertStringContainsString('it has marks', session('errors')->getBag('assessment')->first('assessment_delete'));
        MarkChoice::query()->delete();

        // 3. approvals
        MarkApproval::query()->forceCreate([
            'rubric_id' => $rubric->id, 'group_id' => $this->group->id, 'student_id' => $this->students['s1']->id, 'approved_by' => $this->lecturer->id,
            'approved_at' => now(), 'snapshot' => [], 'audit' => [], 'total' => 5,
        ]);
        $this->actingAs($this->lecturer)->delete(route('lecturer.subjects.assessments.destroy', [$this->subject, $a]))->assertSessionHasErrors(null, 'assessment');
        $this->assertStringContainsString('it has approved marks', session('errors')->getBag('assessment')->first('assessment_delete'));

        $this->assertNotNull(Assessment::find($a->id));
        $this->assertNotNull(Rubric::find($rubric->id));

        // B has nothing: it can go.
        $this->actingAs($this->lecturer)->delete(route('lecturer.subjects.assessments.destroy', [$this->subject, $b]))->assertSessionHasNoErrors();
        $this->assertNull(Assessment::find($b->id));
    }

    public function test_changing_the_dates_of_an_assessment_with_approved_marks_warns_and_keeps_the_snapshot(): void
    {
        [$a] = $this->two();
        $rubric = $this->saveSampleRubric(null, $a);
        $approval = MarkApproval::query()->forceCreate([
            'rubric_id' => $rubric->id, 'group_id' => $this->group->id, 'student_id' => $this->students['s1']->id, 'approved_by' => $this->lecturer->id,
            'approved_at' => now(), 'snapshot' => ['total' => 7], 'audit' => [], 'total' => 7,
        ]);

        $page = $this->actingAs($this->lecturer)->get(route('lecturer.subjects.edit', $this->subject))->getContent();
        $this->assertStringContainsString('data-assessment-warning', $page);
        $this->assertStringContainsString('frozen marks keep their snapshot', $page);

        $this->actingAs($this->lecturer)->put(route('lecturer.subjects.assessments.update', [$this->subject, $a]), [
            'title' => 'Assignment 1', 'type' => 'assignment', 'starts_on' => '2026-09-20', 'due_on' => '2026-10-31',
        ])->assertSessionHasNoErrors()->assertSessionHas('status', 'Assignment 1 was updated. Marks already approved keep their frozen snapshot.');

        $this->assertSame(['total' => 7], $approval->fresh()->snapshot);
    }

    /*
    | Scoping marking and contribution
    */

    public function test_marks_for_one_assessment_do_not_appear_in_another(): void
    {
        [$a, $b] = $this->two();
        $rubricA = $this->saveSampleRubric(null, $a);
        $rubricB = $this->saveSampleRubric(null, $b);

        $this->choose('s1', [], [], [], $rubricA);
        $this->chooseEverything('s1', 'Excellent', $rubricA);

        $this->assertGreaterThan(0, MarkChoice::where('rubric_id', $rubricA->id)->count());
        $this->assertSame(0, MarkChoice::where('rubric_id', $rubricB->id)->count());

        $sheetB = app(\App\Support\Marking\MarkSheet::class)->build($rubricB, $this->group, $this->students['s1']);
        $this->assertNull($sheetB['total'], 'Nothing is decided for B.');
        $this->assertFalse($sheetB['complete']);
    }

    public function test_the_marking_list_shows_the_chosen_assessments_rubric(): void
    {
        [$a, $b] = $this->two();
        $this->saveSampleRubric(null, $a);
        $rubricB = $this->saveSampleRubric(fn ($d) => ['name' => 'Project rubric'] + $d, $b);

        $html = $this->actingAs($this->lecturer)->get(route('lecturer.marking', ['subject' => $this->subject->id, 'assessment' => $b->id]))->assertOk()->getContent();

        $this->assertStringContainsString('Project rubric', $html);
        $this->assertStringContainsString('data-assessment-filter', $html);
        $this->assertMatchesRegularExpression('/data-assessment-chip[^>]*>.*?Project/s', $html);
    }

    public function test_the_marking_page_names_its_assessment_and_links_to_the_others(): void
    {
        [$a, $b] = $this->two();
        $rubricA = $this->saveSampleRubric(null, $a);
        $rubricB = $this->saveSampleRubric(null, $b);

        $html = $this->actingAs($this->lecturer)->get(route('lecturer.marking.group', [$rubricA, $this->group]))->assertOk()->getContent();

        $this->assertStringContainsString('data-marking-context', $html);
        $this->assertStringContainsString('Assignment 1', substr($html, strpos($html, 'data-marking-context'), 1500));
        $this->assertStringContainsString(route('lecturer.marking.group', [$rubricB, $this->group]), $html);
    }

    public function test_contribution_counts_only_the_activity_inside_the_window(): void
    {
        [$a, $b] = $this->two();
        $this->emptyLog();
        $this->log('2026-09-15 10:00');   // before every window
        $this->log('2026-10-10 10:00');   // in A
        $this->log('2026-10-20 10:00');   // in A
        $this->log('2026-10-31 23:59');   // the last minute of A
        $this->log('2026-11-01 00:01');   // in B
        $this->log('2026-12-30 10:00');   // after every window

        $reports = app(ContributionReport::class);
        $group = Group::with('subject.assessments', 'members')->find($this->group->id);

        $this->assertSame(3, $reports->for($group, $a)['total'], 'A: 10 Oct, 20 Oct and the last minute of 31 Oct.');
        $this->assertSame(1, $reports->for($group, $b)['total'], 'B: only 1 Nov.');
        $this->assertSame(6, $reports->for($group)['total'], 'No assessment: everything, as before.');
    }

    public function test_the_project_starts_from_zero_after_assignment_one(): void
    {
        [$a, $b] = $this->two();
        $this->emptyLog();
        $this->log('2026-10-10 10:00');
        $this->log('2026-10-12 10:00');

        $group = Group::with('subject.assessments', 'members')->find($this->group->id);
        $reports = app(ContributionReport::class);

        $this->assertSame(0, $reports->for($group, $b)['total']);
        $this->assertFalse($reports->for($group, $b)['hasActivity']);
        $this->assertSame(2, $reports->for($group, $a)['total']);
    }

    public function test_forMany_applies_the_same_window(): void
    {
        [$a, $b] = $this->two();
        $this->emptyLog();
        $this->log('2026-10-10 10:00');
        $this->log('2026-11-10 10:00');
        $group = Group::with('subject.assessments', 'members')->find($this->group->id);

        $many = app(ContributionReport::class)->forMany(new \Illuminate\Database\Eloquent\Collection([$group]), $a);

        $this->assertSame(1, $many[$group->id]['total']);
    }

    public function test_facts_for_marking_are_windowed_too(): void
    {
        [$a, $b] = $this->two();
        $group = Group::with('subject.assessments', 'members')->find($this->group->id);
        $this->log('2026-10-10 10:00', $this->students['s3']);
        $this->log('2026-11-10 10:00', $this->students['s3']);
        $this->log('2026-11-11 10:00', $this->students['s3']);

        $facts = app(GroupFacts::class);

        $this->assertSame(1, $facts->forGroup($group, $a)[$this->students['s3']->id]['messages_sent']);
        $this->assertSame(2, $facts->forGroup($group, $b)[$this->students['s3']->id]['messages_sent']);
        $this->assertSame('Assignment 1', $facts->forGroup($group, $a)[$this->students['s3']->id]['assessment'], 'Plain context for the AI.');
    }

    public function test_a_subject_with_only_the_default_assessment_gives_exactly_the_old_numbers(): void
    {
        // Activity at all kinds of times, including long after the default assessment's due date.
        $this->subject->update(['project_deadline' => '2026-01-01']);
        Assessment::query()->where('subject_id', $this->subject->id)->delete();
        $default = Assessment::defaultFor(Subject::find($this->subject->id));

        $this->assertSame('2026-01-01', $default->due_on->toDateString());

        $this->log('2025-06-01 10:00');
        $this->log('2026-03-01 10:00');
        $this->log('2026-10-30 10:00', $this->students['s2'], 'task_completed', 3);

        $group = Group::with('subject.assessments', 'members')->find($this->group->id);
        $reports = app(ContributionReport::class);
        $facts = app(GroupFacts::class);

        $this->assertNull($default->fresh()->load('subject.assessments')->activityWindow());
        $figures = fn ($report) => $report['members']->map(fn ($row) => [$row['user']->id, $row['score'], $row['counts']])->all();
        $this->assertEquals($figures($reports->for($group)), $figures($reports->for($group, $default)));
        $this->assertSame($reports->for($group)['total'], $reports->for($group, $default)['total']);

        $plain = $facts->forGroup($group);
        $withDefault = $facts->forGroup($group, $default);
        $this->assertEquals($plain, $withDefault, 'The facts a suggestion is based on are identical, with no assessment context added either.');
    }

    public function test_a_dateless_assessment_beside_dated_ones_counts_everything_not_excluded(): void
    {
        Assessment::query()->where('subject_id', $this->subject->id)->delete();
        $open = $this->assessment('Open', null, null);
        $this->assessment('Other', '2026-11-01', '2026-11-30');   // overlaps an open one, but built directly here
        $group = Group::with('subject.assessments', 'members')->find($this->group->id);
        $before = app(ContributionReport::class)->for($group)['total'];
        $this->log('2026-01-01 10:00');

        $this->assertNull($open->load('subject.assessments')->activityWindow(), 'Both ends open: everything counts.');
        $this->assertSame(1, app(ContributionReport::class)->for($group, $open)['total'] - $before);
    }

    /*
    | The selector rows
    */

    public function test_the_assessment_row_appears_only_for_a_chosen_subject_with_two_or_more(): void
    {
        $this->two();
        $solo = Subject::create(['lecturer_id' => $this->lecturer->id, 'code' => 'SOLO1', 'name' => 'Solo', 'group_size' => 4]);
        Assessment::defaultFor($solo);
        Group::create(['subject_id' => $solo->id, 'name' => 'Solo group', 'group_size' => 4]);   // so Monitor progress lists, not redirects

        foreach (['lecturer.marking', 'lecturer.reports', 'lecturer.progress'] as $route) {
            $all = $this->actingAs($this->lecturer)->get(route($route))->assertOk()->getContent();
            $this->assertStringNotContainsString('data-assessment-filter', $all, $route.' with all subjects');

            $two = $this->actingAs($this->lecturer)->get(route($route, ['subject' => $this->subject->id]))->assertOk()->getContent();
            $this->assertStringContainsString('data-assessment-filter', $two, $route);
            $this->assertSame(2, substr_count(substr($two, strpos($two, 'data-assessment-filter')), 'data-assessment-option='), $route);

            $one = $this->actingAs($this->lecturer)->get(route($route, ['subject' => $solo->id]))->assertOk()->getContent();
            $this->assertStringNotContainsString('data-assessment-filter', $one, $route.' with one assessment');
        }
    }

    public function test_changing_the_subject_drops_the_assessment_and_a_foreign_id_is_ignored(): void
    {
        [$a, $b] = $this->two();
        $other = Subject::create(['lecturer_id' => $this->lecturer->id, 'code' => 'AAA0001', 'name' => 'Another', 'group_size' => 4]);
        $theirs = Subject::create(['lecturer_id' => User::factory()->lecturer()->create()->id, 'code' => 'THEIRS', 'name' => 'Theirs', 'group_size' => 4]);
        $foreign = Assessment::defaultFor($theirs);

        $html = $this->actingAs($this->lecturer)->get(route('lecturer.reports', ['subject' => $this->subject->id, 'assessment' => $b->id]))->getContent();
        preg_match('/data-subject-filter.*?<\/div>/s', $html, $row);
        $this->assertStringNotContainsString('assessment=', $row[0]);
        $this->assertStringContainsString('subject='.$other->id, $row[0]);

        foreach ([$foreign->id, 999999] as $bad) {
            $page = $this->actingAs($this->lecturer)->get(route('lecturer.reports', ['subject' => $this->subject->id, 'assessment' => $bad]))->assertOk()->getContent();

            $this->assertMatchesRegularExpression('/aria-current="page"[^>]*data-assessment-option="'.$b->id.'"/', $page, 'Falls back to the active one (the Project).');
            $this->assertStringNotContainsString('THEIRS', $page);
        }
    }

    public function test_the_report_and_the_group_monitor_page_scope_by_the_chosen_assessment(): void
    {
        [$a, $b] = $this->two();
        $this->emptyLog();
        $this->log('2026-10-10 10:00');
        $this->log('2026-10-11 10:00');

        $reportA = $this->actingAs($this->lecturer)->get(route('lecturer.reports.show', [$this->group, 'assessment' => $a->id]))->assertOk()->getContent();
        $reportB = $this->actingAs($this->lecturer)->get(route('lecturer.reports.show', [$this->group, 'assessment' => $b->id]))->assertOk()->getContent();

        $this->assertStringContainsString('data-assessment-filter', $reportA);
        $this->assertStringNotContainsString('No activity recorded yet', $reportA);
        $this->assertStringContainsString('No activity recorded yet', $reportB, 'The Project has nothing yet.');

        $this->actingAs($this->lecturer)->get(route('lecturer.progress.show', [$this->group, 'assessment' => $b->id]))->assertOk()->assertSee('data-assessment-filter', false);
    }

    public function test_the_contribution_csv_names_the_assessment_only_when_there_are_several(): void
    {
        [$a, $b] = $this->two();
        $this->emptyLog();
        $this->log('2026-10-10 10:00');

        $csv = $this->actingAs($this->lecturer)->get(route('lecturer.reports.export', [$this->group, 'assessment' => $a->id]))->assertOk()->streamedContent();
        $this->assertMatchesRegularExpression('/Assessment,"?Assignment 1"?/', $csv);

        Assessment::query()->where('id', $b->id)->delete();
        $single = $this->actingAs($this->lecturer)->get(route('lecturer.reports.export', $this->group))->assertOk()->streamedContent();
        $this->assertStringNotContainsString('Assessment', $single);
    }

    /*
    | The evidence report and frozen snapshots
    */

    public function test_the_evidence_csv_has_an_assessment_line_right_after_the_class_section(): void
    {
        $rubric = $this->saveSampleRubric();

        $csv = $this->actingAs($this->lecturer)->get(route('lecturer.marking.export', [$rubric, $this->group]))->assertOk()->streamedContent();

        $this->assertMatchesRegularExpression('/"?Class section"?,[^\r\n]*\r?\n"?Assessment"?,"?Project"?\r?\n/', $csv);
        $this->assertMatchesRegularExpression('/"?Student"?,"?Matric no\."?,"?Status"?,"?Section"?,"?Scoring formula"?/', $csv, 'The columns are as they were.');
    }

    public function test_new_approvals_carry_the_assessment_label_and_old_snapshots_still_render(): void
    {
        $rubric = $this->saveSampleRubric();

        // An approval made before assessments existed: no label in its snapshot.
        MarkApproval::query()->forceCreate([
            'rubric_id' => $rubric->id, 'group_id' => $this->group->id, 'student_id' => $this->students['s4']->id, 'approved_by' => $this->lecturer->id,
            'approved_at' => now(), 'snapshot' => ['rubric' => ['id' => $rubric->id, 'name' => 'Old', 'version' => 1], 'total' => 3, 'sections' => []], 'audit' => [], 'total' => 3,
        ]);

        $this->actingAs($this->lecturer)->get(route('lecturer.marking.group', [$rubric, $this->group]))->assertOk();
        $this->actingAs($this->lecturer)->get(route('lecturer.marking.export', [$rubric, $this->group]))->assertOk();
    }
}
