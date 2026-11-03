<?php

namespace Tests\Feature\Assessments;

use App\Models\AppNotification;
use App\Models\Assessment;
use App\Models\Enrolment;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\Section;
use App\Models\Subject;
use App\Models\SubjectFile;
use App\Models\User;
use App\Support\Assessments\Backfill;
use App\Support\SubjectFiles;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Stage 2: the brief, the files, the student pages and the reminders, per assessment. */
class AssessmentBriefsTest extends TestCase
{
    use RefreshDatabase;

    protected User $lecturer;

    protected User $student;

    protected Subject $subject;

    protected Group $group;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(SubjectFiles::DISK);
        Carbon::setTestNow(Carbon::parse('2026-11-03 10:00', 'Asia/Kuala_Lumpur'));

        $this->lecturer = User::factory()->lecturer()->create(['must_change_password' => false]);
        $this->subject = Subject::create(['lecturer_id' => $this->lecturer->id, 'code' => 'SECJ3104', 'name' => 'Application Development', 'group_size' => 4]);
        $section = Section::create(['subject_id' => $this->subject->id, 'name' => '1A']);

        $this->student = User::factory()->student()->create(['must_change_password' => false]);
        Enrolment::create(['user_id' => $this->student->id, 'subject_id' => $this->subject->id, 'section_id' => $section->id]);
        $this->group = Group::create(['subject_id' => $this->subject->id, 'section_id' => $section->id, 'name' => 'Team Alpha', 'group_size' => 4]);
        GroupMember::create(['group_id' => $this->group->id, 'user_id' => $this->student->id, 'joined_at' => now()]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    protected function assessment(string $title, ?string $start, ?string $due, ?string $brief = null, string $type = 'assignment'): Assessment
    {
        return Assessment::create([
            'subject_id' => $this->subject->id, 'title' => $title, 'brief' => $brief, 'type' => $type, 'starts_on' => $start, 'due_on' => $due,
            'position' => ((int) $this->subject->assessments()->max('position')) + 1,
        ]);
    }

    /** Assignment 1 (closed), the Project (on now), a later Assignment 2 (opens later). */
    protected function three(): array
    {
        return [
            $this->assessment('Assignment 1', '2026-09-01', '2026-10-15', 'Brief of the first.'),
            $this->assessment('Project', '2026-10-16', '2026-12-15', 'Brief of the project.', 'project'),
            $this->assessment('Assignment 2', '2027-01-05', '2027-01-30', 'Brief of the second.'),
        ];
    }

    protected function pdf(string $name = 'q.pdf'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n");
    }

    protected function upload(array $extra = [], string $title = 'A file')
    {
        return $this->actingAs($this->lecturer)->post(route('lecturer.subjects.files.store', $this->subject), [
            'file' => $this->pdf(), 'title' => $title, 'kind' => 'question', ...$extra,
        ]);
    }

    protected function page(?int $assessment = null, string $route = 'student.workspace.show'): string
    {
        return $this->actingAs($this->student)->get(route($route, $this->group).($assessment ? '?assessment='.$assessment : ''))->assertOk()->getContent();
    }

    /*
    | The backfill
    */

    public function test_the_backfill_copies_the_subject_brief_and_files_into_the_first_assessment_once(): void
    {
        $this->subject->update(['brief' => 'The old single brief.']);
        $first = $this->assessment('Project', null, null);
        $second = $this->assessment('Later', '2027-01-01', '2027-01-10');

        $file = SubjectFile::create(['subject_id' => $this->subject->id, 'kind' => 'question', 'title' => 'Old', 'original_name' => 'old.pdf', 'stored_path' => 'subject-files/1/x.pdf', 'mime' => 'application/pdf', 'size' => 10]);

        Backfill::briefsAndFiles();

        $this->assertSame('The old single brief.', $first->fresh()->brief);
        $this->assertNull($second->fresh()->brief, 'Only the first assessment.');
        $this->assertSame($first->id, $file->fresh()->assessment_id);

        // Repeating it changes nothing, and never overwrites what was written since.
        $first->update(['brief' => 'Rewritten by the lecturer.']);
        Backfill::briefsAndFiles();
        Backfill::briefsAndFiles();

        $this->assertSame('Rewritten by the lecturer.', $first->fresh()->brief);
        $this->assertSame($first->id, $file->fresh()->assessment_id);
        $this->assertSame('The old single brief.', $this->subject->fresh()->brief, 'subjects.brief is kept.');
    }

    public function test_a_subject_with_no_assessment_is_skipped_by_the_backfill(): void
    {
        $this->subject->update(['brief' => 'Kept.']);
        DB::table('assessments')->where('subject_id', $this->subject->id)->delete();

        Backfill::briefsAndFiles();

        $this->assertSame('Kept.', $this->subject->fresh()->brief);
    }

    /*
    | The lecturer writes a brief per assessment
    */

    public function test_the_assessment_forms_carry_a_brief_and_it_is_saved_and_cleared(): void
    {
        $this->actingAs($this->lecturer)->post(route('lecturer.subjects.assessments.store', $this->subject), [
            'title' => 'Assignment 1', 'type' => 'assignment', 'starts_on' => '2026-09-01', 'due_on' => '2026-09-30',
            'brief' => "  Write the report.\nTwo pages.  ",
        ])->assertSessionHasNoErrors();
        $a = Assessment::where('title', 'Assignment 1')->sole();

        $this->assertSame("Write the report.\nTwo pages.", $a->brief);

        $html = $this->actingAs($this->lecturer)->get(route('lecturer.subjects.edit', $this->subject))->getContent();
        $this->assertStringContainsString('name="brief"', substr($html, strpos($html, 'data-assessments')));
        $this->assertStringContainsString('Write the report.', $html);

        $this->actingAs($this->lecturer)->put(route('lecturer.subjects.assessments.update', [$this->subject, $a]), [
            'title' => 'Assignment 1', 'type' => 'assignment', 'brief' => '', 'starts_on' => '2026-09-01', 'due_on' => '2026-09-30',
        ])->assertSessionHasNoErrors();
        $this->assertNull($a->fresh()->brief);

        $this->actingAs($this->lecturer)->put(route('lecturer.subjects.assessments.update', [$this->subject, $a]), [
            'title' => 'Assignment 1', 'type' => 'assignment', 'brief' => str_repeat('x', 5001),
        ])->assertSessionHasErrors('brief', errorBag: 'assessment');
    }

    public function test_the_subject_brief_is_read_only_once_there_are_assessments(): void
    {
        $this->subject->update(['brief' => 'The old single brief.']);
        $this->assessment('Project', null, null);

        $html = $this->actingAs($this->lecturer)->get(route('lecturer.subjects.edit', $this->subject))->getContent();
        $this->assertStringNotContainsString('Write the brief per assessment below.', $html);
        $this->assertDoesNotMatchRegularExpression('/<textarea id="brief" name="brief"/', $html);
        $this->assertStringNotContainsString('name="project_deadline"', $html);

        $this->actingAs($this->lecturer)->put(route('lecturer.subjects.update', $this->subject), ['brief' => 'Sneaked in', 'group_size' => 4]);
        $this->assertSame('The old single brief.', $this->subject->fresh()->brief);
    }

    public function test_the_subject_brief_stays_editable_for_a_subject_with_no_assessment(): void
    {
        Assessment::query()->where('subject_id', $this->subject->id)->delete();

        $this->assertMatchesRegularExpression('/<textarea id="brief" name="brief"/', $this->actingAs($this->lecturer)->get(route('lecturer.subjects.edit', $this->subject))->getContent());

        $this->actingAs($this->lecturer)->put(route('lecturer.subjects.update', $this->subject), ['brief' => 'Written here', 'group_size' => 4]);
        $this->assertSame('Written here', $this->subject->fresh()->brief);
    }

    public function test_only_the_owner_writes_a_brief(): void
    {
        [$a] = $this->three();
        $stranger = User::factory()->lecturer()->create(['must_change_password' => false]);

        $this->actingAs($stranger)->put(route('lecturer.subjects.assessments.update', [$this->subject, $a]), ['title' => 'Hacked', 'type' => 'project', 'brief' => 'Hacked'])->assertNotFound();
        $this->actingAs($this->student)->put(route('lecturer.subjects.assessments.update', [$this->subject, $a]), ['title' => 'Hacked', 'type' => 'project'])->assertForbidden();

        $this->assertSame('Brief of the first.', $a->fresh()->brief);
    }

    /*
    | What the student reads
    */

    public function test_the_student_reads_the_active_assessments_brief_with_its_title_due_date_and_week(): void
    {
        [$a, $b] = $this->three();
        $this->subject->update(['semester_start_date' => '2026-10-06']);

        $html = preg_replace('/\s+/', ' ', $this->page());

        $this->assertStringContainsString('Project brief', $html);
        $this->assertStringContainsString('Brief of the project.', $html);
        $this->assertStringNotContainsString('Brief of the first.', $html);
        $this->assertStringNotContainsString('Brief of the second.', $html);
        $this->assertStringContainsString('Group project due 15 December 2026 · Week 11', $html);
    }

    public function test_the_switcher_reads_another_assessment_and_labels_closed_and_upcoming_ones(): void
    {
        [$a, $b, $c] = $this->three();

        $html = $this->page($a->id);

        $this->assertStringContainsString('Assignment 1 brief', $html);
        $this->assertStringContainsString('Brief of the first.', $html);
        $this->assertStringNotContainsString('Brief of the project.', $html);
        $this->assertStringContainsString('x-data="{ open: true, instant: true }"', $html, 'Stays open after choosing.');

        $this->assertSame(3, substr_count($html, 'data-brief-option='));
        $this->assertMatchesRegularExpression('/data-brief-option="'.$a->id.'".*?data-brief-state>Closed</s', $html);
        $this->assertMatchesRegularExpression('/data-brief-option="'.$c->id.'".*?data-brief-state>Opens 5 Jan</s', $html);
        $this->assertStringContainsString('Assignment 2 brief', $this->page($c->id));
    }

    public function test_the_switcher_ignores_a_foreign_or_unknown_assessment(): void
    {
        [$a, $b] = $this->three();
        $other = Subject::create(['lecturer_id' => User::factory()->lecturer()->create()->id, 'code' => 'OTH1', 'name' => 'Other', 'group_size' => 4]);
        $foreign = Assessment::create(['subject_id' => $other->id, 'title' => 'Foreign', 'brief' => 'Foreign brief.', 'type' => 'project', 'position' => 1]);

        foreach ([$foreign->id, 999999] as $bad) {
            $html = $this->page($bad);

            $this->assertStringContainsString('Project brief', $html, 'Falls back to the active assessment.');
            $this->assertStringContainsString('Brief of the project.', $html);
            $this->assertStringNotContainsString('Foreign', $html);
        }
    }

    public function test_the_switcher_shows_only_for_two_or_more_assessments(): void
    {
        $this->assessment('Project', null, null, 'Only one.');

        $html = $this->page();

        $this->assertStringNotContainsString('data-brief-switcher', $html);
        $this->assertStringContainsString('Only one.', $html);
    }

    public function test_the_old_subject_brief_is_the_fallback_only_while_every_assessment_brief_is_empty(): void
    {
        $this->subject->update(['brief' => 'The old single brief.']);
        [$a, $b] = [$this->assessment('Assignment 1', '2026-09-01', '2026-10-15'), $this->assessment('Project', '2026-10-16', '2026-12-15', 'Written for the project.')];

        $some = $this->page($a->id);
        $this->assertStringNotContainsString('The old single brief.', $some, 'One brief is written, so the old column is not used.');
        $this->assertStringContainsString('not yet written the brief', $some);

        $b->update(['brief' => null]);
        $this->assertStringContainsString('The old single brief.', $this->page($a->id), 'Every assessment brief is empty: the old column shows.');
    }

    public function test_a_subject_with_one_default_assessment_reads_as_before(): void
    {
        $this->subject->update(['brief' => 'Build a working system.', 'project_deadline' => '2026-12-20']);
        Assessment::query()->where('subject_id', $this->subject->id)->delete();
        Assessment::defaultFor($this->subject->fresh());

        $html = preg_replace('/\s+/', ' ', $this->page());

        $this->assertStringContainsString('Project brief', $html);
        $this->assertStringContainsString('Build a working system.', $html);
        $this->assertStringContainsString('Group project due 20 December 2026', $html);
        $this->assertStringNotContainsString('data-brief-switcher', $html);
    }

    /*
    | Files
    */

    public function test_a_file_is_uploaded_for_an_assessment_or_the_whole_subject_and_the_card_says_which(): void
    {
        [$a, $b] = $this->three();

        $this->upload(['assessment_id' => $a->id], 'Question 1')->assertSessionHasNoErrors();
        $this->upload(['assessment_id' => $b->id], 'Project question')->assertSessionHasNoErrors();
        $this->upload(['assessment_id' => ''], 'Handbook')->assertSessionHasNoErrors();

        $this->assertSame($a->id, SubjectFile::where('title', 'Question 1')->value('assessment_id'));
        $this->assertNull(SubjectFile::where('title', 'Handbook')->value('assessment_id'));

        $html = $this->actingAs($this->lecturer)->get(route('lecturer.subjects.edit', $this->subject))->getContent();
        $this->assertStringNotContainsString('data-file-assessment', $html, 'The block is the assessment: no select.');
        $this->assertStringContainsString('name="assessment_id" value="'.$b->id.'"', $html);
        $this->assertStringContainsString('Course materials', $html);
        $this->assertSame(1, preg_match('/data-assessment="'.$a->id.'".*?Question 1/s', $html), 'Its file is in its own block.');
        $this->assertSame(0, preg_match('/data-assessment="'.$a->id.'"(?:(?!data-assessment=).)*?Handbook/s', $html), 'The shared file is not in an assessment block.');
    }

    public function test_a_file_cannot_be_given_to_another_subjects_assessment(): void
    {
        $other = Subject::create(['lecturer_id' => $this->lecturer->id, 'code' => 'OTH1', 'name' => 'Other', 'group_size' => 4]);
        $foreign = Assessment::create(['subject_id' => $other->id, 'title' => 'Foreign', 'type' => 'project', 'position' => 1]);

        $this->upload(['assessment_id' => $foreign->id])->assertSessionHasErrors('assessment_id');
        $this->assertSame(0, SubjectFile::count());
    }

    public function test_a_students_page_lists_the_assessments_files_and_the_whole_subject_ones_under_every_assessment(): void
    {
        [$a, $b] = $this->three();
        $this->upload(['assessment_id' => $a->id], 'File of one');
        $this->upload(['assessment_id' => $b->id], 'File of project');
        $this->upload(['assessment_id' => ''], 'File of all');

        $project = $this->page();
        $this->assertStringContainsString('File of project', $project);
        $this->assertStringContainsString('File of all', $project);
        $this->assertStringContainsString('Course materials', $project);
        $this->assertStringNotContainsString('File of one', $project);

        $first = $this->page($a->id);
        $this->assertStringContainsString('File of one', $first);
        $this->assertStringContainsString('File of all', $first);
        $this->assertStringNotContainsString('File of project', $first);
    }

    public function test_a_student_without_a_group_sees_the_files_grouped_by_assessment(): void
    {
        [$a, $b] = $this->three();
        $this->upload(['assessment_id' => $a->id], 'File of one');
        $this->upload(['assessment_id' => $b->id], 'File of project');
        $this->upload(['assessment_id' => ''], 'File of all');

        GroupMember::query()->delete();

        $dashboard = $this->actingAs($this->student)->get(route('student.dashboard'))->assertOk()->getContent();
        $this->assertStringContainsString('Files (3)', $dashboard, 'N counts every file the student can see.');

        $page = $this->actingAs($this->student)->get(route('student.subject.files', $this->subject))->assertOk()->getContent();

        foreach (['Assignment 1', 'Project', 'Course materials'] as $heading) {
            $this->assertStringContainsString('data-files-group="'.$heading.'"', $page);
        }

        $this->assertLessThan(strpos($page, 'data-files-group="Project"'), strpos($page, 'data-files-group="Assignment 1"'));
        $this->assertLessThan(strpos($page, 'data-files-group="Course materials"'), strpos($page, 'data-files-group="Project"'));
        $this->assertStringNotContainsString('Assignment 2"', substr($page, strpos($page, 'data-files-group')), 'No heading for an assessment with no file.');
    }

    public function test_deleting_an_assessment_keeps_its_files_as_whole_subject_files(): void
    {
        [$a, $b] = $this->three();
        $this->upload(['assessment_id' => $a->id], 'File of one');
        $file = SubjectFile::sole();

        $page = $this->actingAs($this->lecturer)->get(route('lecturer.subjects.edit', $this->subject))->getContent();
        $this->assertStringContainsString('will stay, under Course materials', $page);

        $this->actingAs($this->lecturer)->delete(route('lecturer.subjects.assessments.destroy', [$this->subject, $a]))->assertSessionHasNoErrors();

        $this->assertNull(Assessment::find($a->id));
        $this->assertNull($file->fresh()->assessment_id);
        Storage::disk(SubjectFiles::DISK)->assertExists($file->stored_path);
    }

    public function test_students_only_see_the_briefs_and_files_of_their_own_subjects(): void
    {
        $this->three();
        $this->upload(['assessment_id' => ''], 'Mine');
        $stranger = User::factory()->student()->create(['must_change_password' => false]);

        $this->actingAs($stranger)->get(route('student.subject.files', $this->subject))->assertNotFound();
        $this->actingAs($stranger)->get(route('student.workspace.show', $this->group))->assertNotFound();
        $this->actingAs($stranger)->get(route('subject-files.download', SubjectFile::sole()))->assertForbidden();
    }

    /*
    | The dashboard chip
    */

    public function test_the_chip_names_the_assessment_only_when_there_are_several(): void
    {
        $this->assessment('Project', null, '2026-11-14');   // one assessment

        $one = $this->actingAs($this->student)->get(route('student.dashboard'))->getContent();
        $this->assertStringContainsString('Due 14 Nov · 11 days left', $one);
        $this->assertDoesNotMatchRegularExpression('/data-tooltip="[^"]*Project · Due/', $one);

        Assessment::query()->where('subject_id', $this->subject->id)->delete();
        $this->assessment('Assignment 1', '2026-10-01', '2026-11-14');
        $this->assessment('Final project with a very long and detailed title', '2026-11-15', '2026-12-20', null, 'project');

        $many = $this->actingAs($this->student)->get(route('student.dashboard'))->getContent();
        $this->assertStringContainsString('Assignment 1 · Due 14 Nov', $many);
        $this->assertStringContainsString('data-tooltip="Assignment 1 · Due 14 Nov · 11 days left"', $many);
        $this->assertSame(1, substr_count($many, 'data-deadline-chip'));

        Carbon::setTestNow(Carbon::parse('2026-11-20 10:00', 'Asia/Kuala_Lumpur'));
        $later = $this->actingAs($this->student)->get(route('student.dashboard'))->getContent();
        $this->assertStringContainsString('Final project with… · Due 20 Dec', $later, 'A long title is cut with an ellipsis');
        $this->assertStringContainsString('Final project with a very long and detailed title · Due 20 Dec', $later, 'The tooltip has all of it.');
    }

    /*
    | The reminders
    */

    protected function remind()
    {
        return $this->artisan('collabspace:deadline-reminders')->assertSuccessful();
    }

    public function test_every_assessment_with_a_due_date_ahead_is_reminded_once_each(): void
    {
        Assessment::query()->where('subject_id', $this->subject->id)->delete();
        $a = $this->assessment('Assignment 1', '2026-10-01', now()->addDays(2)->toDateString());
        $b = $this->assessment('Project', now()->addDays(3)->toDateString(), now()->addDays(5)->toDateString(), null, 'project');

        $this->remind();

        $this->assertSame(1, AppNotification::where('user_id', $this->student->id)->where('type', 'assessment_deadline')->where('reference_id', $a->id)->count());
        $this->assertSame(1, AppNotification::where('user_id', $this->student->id)->where('type', 'assessment_deadline')->where('reference_id', $b->id)->count());
        $this->assertSame(0, AppNotification::where('type', 'project_deadline')->count(), 'The several-assessment kind, not the old one.');

        $messages = AppNotification::where('type', 'assessment_deadline')->pluck('message')->all();
        $this->assertContains('Assignment 1 for SECJ3104 is due on '.now()->addDays(2)->format('j M'), $messages);
        $this->assertContains('Project for SECJ3104 is due on '.now()->addDays(5)->format('j M'), $messages);

        // Running it again tells nobody twice.
        $this->remind();
        $this->assertSame(2, AppNotification::where('user_id', $this->student->id)->where('type', 'assessment_deadline')->count());
    }

    public function test_one_assessments_reminder_never_suppresses_anothers(): void
    {
        Assessment::query()->where('subject_id', $this->subject->id)->delete();
        $a = $this->assessment('Assignment 1', '2026-10-01', now()->addDays(2)->toDateString());
        $this->remind();

        // A second one comes into range later: it is still told, though the subject was already reminded once.
        $b = $this->assessment('Assignment 2', now()->addDays(3)->toDateString(), now()->addDays(4)->toDateString());
        $this->remind();

        $this->assertSame(2, AppNotification::where('user_id', $this->student->id)->where('type', 'assessment_deadline')->count());
        $this->assertSame(1, AppNotification::where('reference_id', $b->id)->where('type', 'assessment_deadline')->count());
    }

    public function test_past_undated_and_far_away_assessments_are_skipped(): void
    {
        Assessment::query()->where('subject_id', $this->subject->id)->delete();
        $this->assessment('Past', '2026-08-01', now()->subDays(3)->toDateString());
        $this->assessment('Undated', null, null, null, 'project');
        $this->assessment('Far', now()->addDays(1)->toDateString(), now()->addDays(60)->toDateString());

        $this->remind();

        $this->assertSame(0, AppNotification::whereIn('type', ['assessment_deadline', 'project_deadline'])->count());
    }

    public function test_a_subject_with_only_the_default_assessment_is_reminded_exactly_as_before(): void
    {
        Assessment::query()->where('subject_id', $this->subject->id)->delete();
        $this->assessment('Project', null, null, null, 'project');
        $this->subject->update(['project_deadline' => now()->addDays(2)->toDateString()]);

        $this->remind();

        $note = AppNotification::where('user_id', $this->student->id)->where('type', 'project_deadline')->sole();
        $this->assertSame($this->subject->id, $note->reference_id, 'Keyed on the subject, like before.');
        $this->assertStringContainsString('the group project is due in 2 days', $note->message);
        $this->assertSame(0, AppNotification::where('type', 'assessment_deadline')->count());

        $this->remind();
        $this->assertSame(1, AppNotification::where('user_id', $this->student->id)->where('type', 'project_deadline')->count());
    }

    public function test_the_dry_run_lists_each_assessment_and_writes_nothing(): void
    {
        Assessment::query()->where('subject_id', $this->subject->id)->delete();
        $this->assessment('Assignment 1', '2026-10-01', now()->addDays(2)->toDateString());
        $this->assessment('Project', now()->addDays(3)->toDateString(), now()->addDays(5)->toDateString(), null, 'project');

        $this->artisan('collabspace:deadline-reminders', ['--dry-run' => true])->assertSuccessful()->expectsOutputToContain('Assignment 1');

        $this->assertSame(0, AppNotification::count());
    }

    /*
    | The lecturer's pages name the active assessment
    */

    public function test_my_subjects_names_the_active_assessment_when_there_are_several(): void
    {
        $this->three();

        $html = $this->actingAs($this->lecturer)->get(route('lecturer.subjects'))->assertOk()->getContent();

        $this->assertStringContainsString('Project due', $html);
        $this->assertStringContainsString('15 Dec 2026', $html);
        $this->assertStringContainsString('data-deadline-assessment', $html);
    }

    public function test_my_subjects_is_unchanged_with_one_assessment(): void
    {
        $this->assessment('Project', null, '2026-12-15', null, 'project');

        $html = $this->actingAs($this->lecturer)->get(route('lecturer.subjects'))->getContent();

        $this->assertStringContainsString('Group project due', $html);
        $this->assertStringNotContainsString('data-deadline-assessment', $html);
    }
}
