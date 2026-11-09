<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\AppNotification;
use App\Models\Assessment;
use App\Models\Document;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\Subject;
use App\Models\Submission;
use App\Models\User;
use App\Support\DocumentPrintBody;
use App\Support\Marking\EvidenceReport;
use App\Support\Submissions\SubmissionPdf;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsMarkingFixture;
use Tests\TestCase;

/** A group hands in its report for an assessment as one frozen PDF of its documents. */
class SubmissionTest extends TestCase
{
    use BuildsMarkingFixture;
    use RefreshDatabase;

    protected Document $docA;

    protected Document $docB;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Carbon::setTestNow('2026-11-03 10:00:00');

        $this->buildMarkingFixture();
        $this->assessment->update(['due_on' => '2026-11-10']);

        $this->docA = $this->doc('Introduction', '<p>Hello alpha world.</p>');
        $this->docB = $this->doc('Design', '<h1>Design</h1><p>Beta section text.</p>');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    protected function doc(string $title, string $content, ?Group $group = null): Document
    {
        return Document::create([
            'group_id' => ($group ?? $this->group)->id, 'created_by' => $this->students['s1']->id, 'last_edited_by' => $this->students['s1']->id,
            'title' => $title, 'content' => $content,
        ]);
    }

    protected function submit(array $extra = [], ?User $as = null, ?Group $group = null)
    {
        return $this->actingAs($as ?? $this->students['s1'])->post(route('student.submissions.store', $group ?? $this->group), [
            'assessment_id' => $this->assessment->id,
            'documents' => [$this->docB->id, $this->docA->id],
            ...$extra,
        ]);
    }

    public function test_submit_creates_the_pdf_on_the_private_disk_with_rows_in_order(): void
    {
        $this->submit(['note' => 'Final draft'])->assertSessionHasNoErrors()
            ->assertRedirect(route('student.documents.group', $this->group).'#submit');

        $submission = Submission::sole();
        $this->assertSame($this->group->id, $submission->group_id);
        $this->assertSame($this->assessment->id, $submission->assessment_id);
        $this->assertSame($this->students['s1']->id, $submission->submitted_by);
        $this->assertFalse($submission->is_late);
        $this->assertSame('Final draft', $submission->note);
        $this->assertStringStartsWith('submissions/'.$this->group->id.'/', $submission->pdf_path);

        Storage::disk('local')->assertExists($submission->pdf_path);
        $this->assertStringStartsWith('%PDF', Storage::disk('local')->get($submission->pdf_path));
        $this->assertSame(Storage::disk('local')->size($submission->pdf_path), $submission->pdf_size);
        $this->assertGreaterThanOrEqual(3, (int) $submission->page_count, 'A cover page and a page per document.');

        $this->assertSame(['Design', 'Introduction'], $submission->documents->pluck('title')->all());
        $this->assertSame([0, 1], $submission->documents->pluck('position')->all());
    }

    public function test_the_late_flag_follows_the_end_of_the_due_date(): void
    {
        $this->submit();
        $this->assertFalse(Submission::latest('id')->first()->is_late, 'Before the due date.');

        Carbon::setTestNow('2026-11-10 23:30:00');
        $this->submit();
        $this->assertFalse(Submission::latest('id')->first()->is_late, 'Still the due day.');

        Carbon::setTestNow('2026-11-11 00:05:00');
        $this->submit()->assertSessionHasNoErrors();
        $this->assertTrue(Submission::latest('id')->first()->is_late, 'After the end of the due day.');

        $this->assessment->update(['due_on' => null]);
        $this->submit();
        $this->assertFalse(Submission::latest('id')->first()->is_late, 'No due date, never late.');
    }

    public function test_resubmitting_makes_the_newest_current_and_keeps_history(): void
    {
        $this->submit();
        Carbon::setTestNow('2026-11-04 10:00:00');
        $this->submit(['documents' => [$this->docA->id]]);

        $this->assertSame(2, Submission::count());
        $current = Submission::currentFor($this->group->id, $this->assessment->id);
        $this->assertSame(['Introduction'], $current->documents->pluck('title')->all());
        $this->assertSame(2, collect(Submission::all())->filter(fn ($s) => Storage::disk('local')->exists($s->pdf_path))->count());

        $html = $this->actingAs($this->students['s2'])->get(route('student.documents.group', $this->group))->assertOk()->getContent();
        $this->assertStringContainsString('data-submission-history', $html);
        $this->assertStringContainsString('Download PDF', $html);
        $this->assertStringContainsString('Withdraw', $html);
    }

    public function test_withdraw_works_before_the_deadline_and_keeps_the_file(): void
    {
        $this->submit();
        $submission = Submission::sole();

        $this->actingAs($this->students['s2'])->post(route('student.submissions.withdraw', $submission))->assertSessionHasNoErrors();

        $submission->refresh();
        $this->assertNotNull($submission->withdrawn_at);
        $this->assertSame($this->students['s2']->id, $submission->withdrawn_by);
        Storage::disk('local')->assertExists($submission->pdf_path);
        $this->assertNull(Submission::currentFor($this->group->id, $this->assessment->id));
    }

    public function test_withdraw_after_the_deadline_is_refused(): void
    {
        $this->submit();
        $submission = Submission::sole();

        Carbon::setTestNow('2026-11-11 08:00:00');

        $this->actingAs($this->students['s1'])->post(route('student.submissions.withdraw', $submission))->assertSessionHasErrors('submission');
        $this->assertNull($submission->fresh()->withdrawn_at);

        $html = $this->actingAs($this->students['s1'])->get(route('student.documents.group', $this->group))->getContent();
        $this->assertStringNotContainsString('Withdraw this submission?', $html, 'The button is gone after the deadline.');
    }

    public function test_documents_of_another_group_deleted_documents_and_foreign_assessments_are_rejected(): void
    {
        $other = Group::create(['subject_id' => $this->subject->id, 'name' => 'Group Other', 'group_size' => 4]);
        $foreignDoc = $this->doc('Not ours', '<p>x</p>', $other);
        $this->submit(['documents' => [$this->docA->id, $foreignDoc->id]])->assertSessionHasErrors('submission');

        $this->docB->delete();
        $this->submit(['documents' => [$this->docA->id, $this->docB->id]])->assertSessionHasErrors('submission');

        $otherSubject = Subject::create(['lecturer_id' => $this->lecturer->id, 'code' => 'SECJ9999', 'name' => 'Other', 'group_size' => 4]);
        $foreign = Assessment::defaultFor($otherSubject);
        $this->submit(['assessment_id' => $foreign->id, 'documents' => [$this->docA->id]])->assertSessionHasErrors('assessment_id');

        $this->submit(['documents' => []])->assertSessionHasErrors('documents');

        $this->assertSame(0, Submission::count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_a_failed_pdf_leaves_no_row_and_no_file(): void
    {
        $this->app->bind(SubmissionPdf::class, fn () => new class(app(DocumentPrintBody::class)) extends SubmissionPdf
        {
            public function render(Group $group, Assessment $assessment, \Illuminate\Support\Collection $documents, User $by, \Carbon\CarbonInterface $at, bool $late): array
            {
                throw new \RuntimeException('image unreadable');
            }
        });

        $this->submit()->assertSessionHasErrors('submission');

        $this->assertSame(0, Submission::count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_a_non_member_a_lecturer_and_a_guest_cannot_submit(): void
    {
        $outsider = User::factory()->student()->create(['must_change_password' => false]);

        $this->submit([], $outsider)->assertNotFound();
        $this->actingAs($this->lecturer)->post(route('student.submissions.store', $this->group), ['assessment_id' => $this->assessment->id, 'documents' => [$this->docA->id]])->assertForbidden();
        $this->assertSame(0, Submission::count());

        auth()->logout();
        $this->post(route('student.submissions.store', $this->group), [])->assertRedirect(route('login'));
    }

    public function test_members_and_the_lecturer_are_notified_and_activity_is_worth_nothing(): void
    {
        $before = (int) ActivityLog::where('group_id', $this->group->id)->sum('weight');

        $this->submit();

        $this->assertSame($before, (int) ActivityLog::where('group_id', $this->group->id)->sum('weight'), 'Contribution points are unchanged.');
        $log = ActivityLog::where('activity_type', 'submission_submitted')->sole();
        $this->assertSame(0, $log->weight);

        foreach (['s2', 's3', 's4'] as $key) {
            $note = AppNotification::where('user_id', $this->students[$key]->id)->where('type', 'submission_made')->sole();
            $this->assertStringContainsString('Aminah Binti Rashid submitted Project for Group Falcon', $note->message);
        }
        $this->assertSame(0, AppNotification::where('user_id', $this->students['s1']->id)->where('type', 'submission_made')->count());
        $this->assertSame(1, AppNotification::where('user_id', $this->lecturer->id)->where('type', 'submission_made')->count());

        $this->submit();
        $this->assertSame(1, ActivityLog::where('activity_type', 'submission_resubmitted')->count());

        $this->actingAs($this->students['s3'])->post(route('student.submissions.withdraw', Submission::latest('id')->first()));
        $this->assertSame(1, ActivityLog::where('activity_type', 'submission_withdrawn')->where('weight', 0)->count());
        $this->assertSame(1, AppNotification::where('user_id', $this->students['s1']->id)->where('type', 'submission_withdrawn')->count());
    }

    public function test_download_is_for_members_and_the_owning_lecturer_only(): void
    {
        $this->submit();
        $submission = Submission::sole();
        $url = route('submissions.pdf', $submission);

        $member = $this->actingAs($this->students['s4'])->get($url)->assertOk();
        $this->assertSame('application/pdf', $member->headers->get('Content-Type'));
        $this->assertSame('nosniff', $member->headers->get('X-Content-Type-Options'));
        $this->assertStringContainsString('attachment', $member->headers->get('Content-Disposition'));
        $this->assertStringContainsString('SECJ3104-Group-Falcon-Project-2026-11-03.pdf', $member->headers->get('Content-Disposition'));

        $inline = $this->actingAs($this->lecturer)->get($url.'?inline=1')->assertOk();
        $this->assertStringContainsString('inline', $inline->headers->get('Content-Disposition'));

        $otherLecturer = User::factory()->lecturer()->create(['must_change_password' => false]);
        $this->actingAs($otherLecturer)->get($url)->assertForbidden();

        $outsider = User::factory()->student()->create(['must_change_password' => false]);
        $this->actingAs($outsider)->get($url)->assertForbidden();

        $admin = User::factory()->admin()->create(['must_change_password' => false]);
        $this->actingAs($admin)->get($url)->assertForbidden();

        auth()->logout();
        $this->get($url)->assertRedirect(route('login'));

        Storage::disk('local')->delete($submission->pdf_path);
        $this->actingAs($this->students['s1'])->get($url)->assertNotFound();
    }

    public function test_lecturer_sees_the_card_and_the_list_chips_and_the_csv_lines(): void
    {
        $url = route('lecturer.progress.show', $this->group);
        $html = $this->actingAs($this->lecturer)->get($url)->assertOk()->getContent();
        $this->assertStringContainsString('data-submission-card', $html);
        $this->assertStringContainsString('Not submitted', $html);

        $this->submit(['note' => 'See appendix']);
        Carbon::setTestNow('2026-11-11 09:00:00');
        $this->submit();

        $card = $this->actingAs($this->lecturer)->get($url)->getContent();
        $this->assertStringContainsString('Submitted late', $card);
        $this->assertStringContainsString('Open PDF', $card);
        $this->assertStringContainsString('data-submission-history', $card);

        $marking = $this->actingAs($this->lecturer)->get(route('lecturer.marking.group', [$this->rubric, $this->group]))->assertOk()->getContent();
        $this->assertStringContainsString('data-submission-card', $marking);

        $list = $this->actingAs($this->lecturer)->get(route('lecturer.marking', ['subject' => $this->subject->id]))->getContent();
        $this->assertStringContainsString('data-submission-chip="late"', $list);
        $progress = $this->actingAs($this->lecturer)->get(route('lecturer.progress', ['subject' => $this->subject->id]))->getContent();
        $this->assertStringContainsString('data-submission-chip="late"', $progress);

        $all = $this->actingAs($this->lecturer)->get(route('lecturer.marking'))->getContent();
        $this->assertStringNotContainsString('data-submission-chip', $all, 'All subjects: nothing new.');

        $rows = app(EvidenceReport::class)->rows($this->rubric, $this->group);
        $lines = collect($rows)->filter(fn ($r) => in_array($r[0] ?? null, ['Submitted', 'Submission'], true))->mapWithKeys(fn ($r) => [$r[0] => $r[1]]);
        $this->assertSame('2026-11-11 09:00:00', $lines['Submitted']);
        $this->assertSame('Late', $lines['Submission']);
        $this->assertSame('Assessment', $rows[5][0], 'The new lines follow Assessment.');
        $this->assertSame('Submitted', $rows[6][0]);
        $this->assertSame('Submission', $rows[7][0]);
        $this->assertSame(['Leader', ''], $rows[8], 'A group with no leader: an empty name.');

        $this->group->forceFill(['leader_id' => $this->students['s2']->id])->save();
        $this->assertSame(['Leader', 'Bakri Bin Hamdan'], app(EvidenceReport::class)->rows($this->rubric, $this->group->fresh())[8], 'The leader is a label: the line names them.');
    }

    public function test_deleting_a_document_later_and_purging_leave_the_submission_alone(): void
    {
        $this->submit();
        $submission = Submission::sole();

        $this->docA->delete();
        $this->docB->delete();

        $this->artisan('documents:purge-deleted', ['--days' => 0])->assertSuccessful();

        Storage::disk('local')->assertExists($submission->pdf_path);
        $this->assertSame(2, $submission->documents()->count());
        $this->assertSame(['Design', 'Introduction'], $submission->documents()->pluck('title')->all());

        $this->actingAs($this->students['s1'])->get(route('submissions.pdf', $submission))->assertOk();
    }

    public function test_the_student_card_has_empty_states(): void
    {
        $empty = Group::create(['subject_id' => $this->subject->id, 'name' => 'Group Empty', 'group_size' => 4]);
        GroupMember::create(['group_id' => $empty->id, 'user_id' => $this->students['s1']->id, 'joined_at' => now()]);

        $html = $this->actingAs($this->students['s1'])->get(route('student.documents.group', $empty))->assertOk()->getContent();
        $this->assertStringContainsString('data-submit-card', $html);
        $this->assertStringContainsString('Write a document first', $html);

        $full = $this->actingAs($this->students['s1'])->get(route('student.documents.group', $this->group))->getContent();
        $this->assertStringContainsString('Yes, submit', $full);
        $this->assertStringContainsString('This creates a PDF of the selected documents as they are now.', $full);
    }
}
