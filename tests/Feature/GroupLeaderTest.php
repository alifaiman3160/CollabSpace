<?php

namespace Tests\Feature;

use App\Livewire\TaskBoard;
use App\Models\ActivityLog;
use App\Models\AppNotification;
use App\Models\Assessment;
use App\Models\Document;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\Subject;
use App\Models\Submission;
use App\Models\Task;
use App\Models\User;
use App\Support\ContributionReport;
use App\Support\Formation\GroupFormation;
use App\Support\Formation\ImportBuilder;
use App\Support\Formation\ImportReview;
use App\Support\GroupLeader\GroupLeader;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Concerns\BuildsFormationCohort;
use Tests\TestCase;

/** One leader per group: how it is set, who can change it, what it allows, and that it is never worth points. */
class GroupLeaderTest extends TestCase
{
    use BuildsFormationCohort;
    use RefreshDatabase;

    protected Group $group;

    protected User $lead;     // CGPA 3.95: the default leader

    protected User $second;   // 3.88

    protected User $third;    // 3.75

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Carbon::setTestNow('2026-11-03 10:00:00');

        $this->buildCohort();
        [$this->lead, $this->second, $this->third] = [$this->cohort[0], $this->cohort[1], $this->cohort[2]];
        $this->group = $this->makeGroup('Group A', [$this->third, $this->second, $this->lead]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    protected function leaders(): GroupLeader
    {
        return app(GroupLeader::class);
    }

    /*
    | Data and the default
    */

    public function test_the_backfill_picks_the_highest_cgpa_with_the_lowest_id_on_ties_and_is_idempotent(): void
    {
        $a = User::factory()->student()->create(['cgpa' => null]);
        $b = User::factory()->student()->create(['cgpa' => 3.50]);
        $c = User::factory()->student()->create(['cgpa' => 3.50]);
        $tied = Group::create(['subject_id' => $this->subject->id, 'name' => 'Tied', 'group_size' => 4]);
        foreach ([$c, $a, $b] as $u) {
            GroupMember::create(['group_id' => $tied->id, 'user_id' => $u->id, 'joined_at' => now()]);
        }

        $noCgpa = Group::create(['subject_id' => $this->subject->id, 'name' => 'None', 'group_size' => 4]);
        $d = User::factory()->student()->create(['cgpa' => null]);
        $e = User::factory()->student()->create(['cgpa' => null]);
        foreach ([$e, $d] as $u) {
            GroupMember::create(['group_id' => $noCgpa->id, 'user_id' => $u->id, 'joined_at' => now()]);
        }

        $empty = Group::create(['subject_id' => $this->subject->id, 'name' => 'Empty', 'group_size' => 4]);

        Group::query()->update(['leader_id' => null]);

        $migration = require base_path('database/migrations/2026_11_18_000001_add_leader_id_to_groups_table.php');
        $migration->up();
        $migration->up();

        $this->assertSame($this->lead->id, $this->group->fresh()->leader_id);
        $this->assertSame($b->id, $tied->fresh()->leader_id, 'Ties go to the lowest id.');
        $this->assertSame(min($d->id, $e->id), $noCgpa->fresh()->leader_id, 'Missing CGPAs go to the lowest id.');
        $this->assertNull($empty->fresh()->leader_id);

        DB::table('groups')->where('id', $tied->id)->update(['leader_id' => $c->id]);
        $migration->up();
        $this->assertSame($c->id, $tied->fresh()->leader_id, 'A group that has a leader is left alone.');
    }

    public function test_formation_sets_the_highest_cgpa_member_or_the_marked_one_as_leader(): void
    {
        $subject = Subject::create(['lecturer_id' => $this->lecturer->id, 'code' => 'SECJ2000', 'name' => 'Other', 'group_size' => 4]);
        $students = $this->makeStudents([3.1, 3.9, 3.5, 2.8, 3.3, 3.0], 'B');
        foreach ($students as $s) {
            $this->enrol($s, null, $subject);
        }

        $groups = app(GroupFormation::class)->create($subject, $this->lecturer, Group::FORMED_BALANCED, [
            ['name' => 'F1', 'size' => 4, 'member_ids' => [$students[0]->id, $students[1]->id, $students[2]->id], 'meta' => []],
            ['name' => 'F2', 'size' => 4, 'member_ids' => [$students[3]->id, $students[4]->id, $students[5]->id], 'leader_id' => $students[3]->id, 'meta' => []],
        ]);

        $this->assertSame($students[1]->id, $groups[0]->fresh()->leader_id, 'Highest CGPA by default.');
        $this->assertSame($students[3]->id, $groups[1]->fresh()->leader_id, 'The marked leader wins.');
    }

    /*
    | CSV leader column
    */

    protected function importDraft(array $rows, array $headers): array
    {
        $builder = new ImportBuilder();
        $roles = $builder->guessRoles($headers);
        $cells = array_map(fn ($r, $i) => ['n' => $i + 2, 'cells' => $r], $rows, array_keys($rows));

        return [$roles, $builder->build($cells, $roles, ImportBuilder::LAYOUT_STUDENT_ROWS)];
    }

    protected function reviewImport(array $draft, array $decisions = []): array
    {
        return app(ImportReview::class)->evaluate($draft, $decisions, $this->subject, $this->section);
    }

    public function test_the_leader_column_is_detected_in_english_and_malay(): void
    {
        $roles = (new ImportBuilder())->guessRoles(['Timestamp', 'Group name', 'Matric number', 'Ketua']);
        $this->assertSame([ImportBuilder::ROLE_IGNORE, ImportBuilder::ROLE_GROUP, ImportBuilder::ROLE_MEMBER, ImportBuilder::ROLE_LEADER], $roles);

        $this->assertSame(ImportBuilder::ROLE_LEADER, (new ImportBuilder())->guessRoles(['Group', 'Matric', 'LEADER'])[2]);
    }

    public function test_one_marked_student_leads_and_none_marked_falls_back_to_the_highest_cgpa(): void
    {
        $x = $this->makeStudents([3.0, 3.9], 'X');
        $x[0]->update(['matric_no' => 'A21XX0001']);
        $x[1]->update(['matric_no' => 'A21XX0002']);
        $x->each(fn ($u) => $this->enrol($u));

        [, $draft] = $this->importDraft([
            ['Team 1', 'A21XX0001', 'Ya'],
            ['Team 1', 'A21XX0002', ''],
        ], ['Group', 'Matric', 'Ketua']);
        $review = $this->reviewImport($draft);
        $group = collect($review['groups'])->first();

        $this->assertSame($x[0]->id, $group['leader_user_id'], 'The marked student leads, whatever the CGPA.');
        $this->assertTrue($group['clean']);
        $this->assertSame($x[0]->id, app(ImportReview::class)->plans($review, 'f.csv', false)[0]['leader_id']);

        [, $unmarked] = $this->importDraft([
            ['Team 2', 'A21XX0001', ''],
            ['Team 2', 'A21XX0002', 'no'],
        ], ['Group', 'Matric', 'Leader']);
        $group = collect($this->reviewImport($unmarked)['groups'])->first();
        $this->assertNull($group['leader_user_id']);
        $this->assertSame($x[1]->id, $group['leader_preview_id'], 'The highest CGPA is shown as the default.');
    }

    public function test_two_marked_in_one_group_block_it_until_one_is_chosen(): void
    {
        $x = $this->makeStudents([3.0, 3.9], 'Y');
        $x[0]->update(['matric_no' => 'A21YY0001']);
        $x[1]->update(['matric_no' => 'A21YY0002']);
        $x->each(fn ($u) => $this->enrol($u));

        [, $draft] = $this->importDraft([
            ['Team 1', 'A21YY0001', 'yes'],
            ['Team 1', 'A21YY0002', 'leader'],
        ], ['Group', 'Matric', 'Leader']);

        $review = $this->reviewImport($draft);
        $group = collect($review['groups'])->first();
        $this->assertTrue($group['problems']['multi_leader']);
        $this->assertFalse($group['clean']);

        $gkey = array_key_first($review['groups']);
        $pkey = $group['leader_marked'][1]['key'];
        $fixed = collect($this->reviewImport($draft, ['leader' => [$gkey => $pkey]])['groups'])->first();
        $this->assertFalse($fixed['problems']['multi_leader']);
        $this->assertTrue($fixed['clean']);
        $this->assertSame($x[1]->id, $fixed['leader_user_id']);
    }

    public function test_a_file_without_a_leader_column_works_as_before(): void
    {
        $x = $this->makeStudents([3.0, 3.9], 'Z');
        $x[0]->update(['matric_no' => 'A21ZZ0001']);
        $x[1]->update(['matric_no' => 'A21ZZ0002']);
        $x->each(fn ($u) => $this->enrol($u));

        [$roles, $draft] = $this->importDraft([['Team 1', 'A21ZZ0001'], ['Team 1', 'A21ZZ0002']], ['Group', 'Matric']);
        $this->assertNotContains(ImportBuilder::ROLE_LEADER, $roles);

        $group = collect($this->reviewImport($draft)['groups'])->first();
        $this->assertTrue($group['clean']);
        $this->assertNull($group['leader_user_id']);
    }

    /*
    | Manual groups, changing and removing
    */

    public function test_the_first_member_of_a_manual_group_becomes_its_leader(): void
    {
        $empty = Group::create(['subject_id' => $this->subject->id, 'section_id' => $this->section->id, 'name' => 'Manual', 'group_size' => 4]);
        $student = $this->makeStudents([3.0], 'M')->first();
        $this->enrol($student);

        $this->actingAs($this->lecturer)->post(route('lecturer.groups.members.store', $empty), ['user_id' => $student->id])->assertSessionHasNoErrors();

        $this->assertSame($student->id, $empty->fresh()->leader_id);
    }

    public function test_only_the_owning_lecturer_changes_the_leader_and_only_to_a_member(): void
    {
        $this->actingAs($this->lecturer)->put(route('lecturer.groups.leader', $this->group), ['user_id' => $this->second->id])->assertSessionHasNoErrors();

        $this->assertSame($this->second->id, $this->group->fresh()->leader_id);

        $log = ActivityLog::where('activity_type', 'leader_changed')->sole();
        $this->assertSame(0, $log->weight);
        $this->assertSame($this->second->id, $log->reference_id);

        $this->assertSame(1, AppNotification::where('user_id', $this->second->id)->where('type', 'leader_changed')->where('message', 'You are now the leader of Group A')->count());
        $this->assertSame(1, AppNotification::where('user_id', $this->lead->id)->where('type', 'leader_changed')->where('message', 'like', '%is now the leader of Group A')->count());

        $outsider = $this->makeStudents([3.0], 'O')->first();
        $this->actingAs($this->lecturer)->put(route('lecturer.groups.leader', $this->group), ['user_id' => $outsider->id])->assertSessionHasErrors('user_id');
        $this->assertSame($this->second->id, $this->group->fresh()->leader_id);

        $other = User::factory()->lecturer()->create(['must_change_password' => false]);
        $this->actingAs($other)->put(route('lecturer.groups.leader', $this->group), ['user_id' => $this->third->id])->assertNotFound();

        $this->actingAs($this->second)->put(route('lecturer.groups.leader', $this->group), ['user_id' => $this->third->id])->assertForbidden();
        $this->actingAs($this->lead)->put(route('lecturer.groups.leader', $this->group), ['user_id' => $this->lead->id])->assertForbidden();
        $this->assertSame($this->second->id, $this->group->fresh()->leader_id);
    }

    public function test_the_service_refuses_a_non_member(): void
    {
        $outsider = $this->makeStudents([3.0], 'S')->first();

        $this->expectException(\InvalidArgumentException::class);
        $this->leaders()->setLeader($this->group, $outsider);
    }

    public function test_removing_the_leader_hands_over_to_the_highest_cgpa_and_tells_them(): void
    {
        $this->actingAs($this->lecturer)->delete(route('lecturer.groups.members.destroy', [$this->group, $this->lead]))->assertSessionHasNoErrors();

        $this->assertSame($this->second->id, $this->group->fresh()->leader_id);
        $this->assertSame(1, AppNotification::where('user_id', $this->second->id)->where('type', 'leader_changed')->count());
        $this->assertSame(1, ActivityLog::where('activity_type', 'leader_changed')->where('weight', 0)->count());

        $this->actingAs($this->lecturer)->delete(route('lecturer.groups.members.destroy', [$this->group, $this->third]));
        $this->actingAs($this->lecturer)->delete(route('lecturer.groups.members.destroy', [$this->group, $this->second]));
        $this->assertNull($this->group->fresh()->leader_id, 'No members left, no leader.');
    }

    public function test_removing_someone_else_leaves_the_leader_alone(): void
    {
        $this->actingAs($this->lecturer)->delete(route('lecturer.groups.members.destroy', [$this->group, $this->third]));

        $this->assertSame($this->lead->id, $this->group->fresh()->leader_id);
        $this->assertSame(0, ActivityLog::where('activity_type', 'leader_changed')->count());
    }

    /*
    | Tasks
    */

    protected function board(User $as)
    {
        return Livewire::actingAs($as)->test(TaskBoard::class, ['group' => $this->group]);
    }

    public function test_the_leader_assigns_to_anyone_and_a_member_only_to_themselves(): void
    {
        $this->board($this->lead)->set('title', 'For second')->set('assignedTo', $this->second->id)->call('createTask')->assertHasNoErrors();
        $this->assertSame($this->second->id, Task::where('title', 'For second')->value('assigned_to'));

        $this->board($this->third)->set('title', 'For lead')->set('assignedTo', $this->lead->id)->call('createTask')->assertHasErrors('assignedTo');
        $this->assertSame(0, Task::where('title', 'For lead')->count());

        $this->board($this->third)->set('title', 'Mine')->set('assignedTo', $this->third->id)->call('createTask')->assertHasNoErrors();
        $mine = Task::where('title', 'Mine')->first();
        $this->assertSame($this->third->id, $mine->assigned_to);

        $this->board($this->third)->set('title', 'Blank')->call('createTask')->assertHasNoErrors();
        $this->assertSame($this->third->id, Task::where('title', 'Blank')->value('assigned_to'), 'A member who leaves it blank gets it themselves.');
    }

    public function test_a_member_cannot_edit_reassign_or_delete_others_tasks_but_can_manage_their_own(): void
    {
        $others = Task::create(['group_id' => $this->group->id, 'created_by' => $this->lead->id, 'assigned_to' => $this->third->id, 'title' => 'Given', 'status' => Task::STATUS_TODO]);
        $own = Task::create(['group_id' => $this->group->id, 'created_by' => $this->third->id, 'assigned_to' => $this->third->id, 'title' => 'Own', 'status' => Task::STATUS_TODO]);

        $board = $this->board($this->third);
        $board->call('startEdit', $others->id)->assertSet('editingTask', null);
        $board->call('confirmDelete', $others->id)->assertSet('confirmingDelete', null);
        $this->assertNotNull($others->fresh());

        $board->call('startEdit', $own->id)->assertSet('editingTask', $own->id)
            ->set('title', 'Own renamed')->set('assignedTo', $this->lead->id)->call('saveTask')->assertHasErrors('assignedTo');
        $this->assertSame($this->third->id, $own->fresh()->assigned_to);

        $board->set('assignedTo', $this->third->id)->call('saveTask')->assertHasNoErrors();
        $this->assertSame('Own renamed', $own->fresh()->title);

        $board->call('confirmDelete', $own->id)->call('deleteTask');
        $this->assertNull(Task::find($own->id));

        // The leader manages any task of the group.
        $leaderBoard = $this->board($this->lead);
        $leaderBoard->call('startEdit', $others->id)->set('assignedTo', $this->second->id)->call('saveTask')->assertHasNoErrors();
        $this->assertSame($this->second->id, $others->fresh()->assigned_to);
        $leaderBoard->call('confirmDelete', $others->id)->call('deleteTask');
        $this->assertNull(Task::find($others->id));
    }

    public function test_only_the_assignee_changes_the_status_even_for_the_leader(): void
    {
        $task = Task::create(['group_id' => $this->group->id, 'created_by' => $this->lead->id, 'assigned_to' => $this->third->id, 'title' => 'Third task', 'status' => Task::STATUS_TODO]);

        $this->board($this->lead)->call('moveTask', $task->id, Task::STATUS_DONE);
        $this->assertSame(Task::STATUS_TODO, $task->fresh()->status);

        $this->board($this->third)->call('moveTask', $task->id, Task::STATUS_DONE);
        $this->assertSame(Task::STATUS_DONE, $task->fresh()->status);
    }

    public function test_a_group_without_a_leader_lets_any_member_assign(): void
    {
        $this->group->forceFill(['leader_id' => null])->save();

        $this->board($this->third)->set('title', 'Anyone')->set('assignedTo', $this->lead->id)->call('createTask')->assertHasNoErrors();
        $this->assertSame($this->lead->id, Task::where('title', 'Anyone')->value('assigned_to'));
    }

    public function test_assigning_work_to_others_earns_the_leader_nothing_and_a_self_task_scores_as_before(): void
    {
        $this->board($this->lead)->set('title', 'Delegated')->set('assignedTo', $this->second->id)->call('createTask');
        $this->assertSame(0, ActivityLog::where('activity_type', 'task_created')->where('user_id', $this->lead->id)->count());

        $this->board($this->lead)->set('title', 'Mine')->set('assignedTo', $this->lead->id)->call('createTask');
        $this->assertSame(1, ActivityLog::where('activity_type', 'task_created')->where('user_id', $this->lead->id)->count());
        $this->assertSame(config('contribution.weights.task_created'), ActivityLog::where('activity_type', 'task_created')->value('weight'));
    }

    public function test_contribution_is_identical_for_a_leader_and_a_member_with_the_same_activity(): void
    {
        $recorder = app(\App\Support\ActivityRecorder::class);

        foreach ([$this->lead, $this->second] as $user) {
            $recorder->record($user, $this->group, 'message_sent');
            $recorder->record($user, $this->group, 'document_edited');
        }

        $report = app(ContributionReport::class)->for($this->group);
        $scores = collect($report['members'])->mapWithKeys(fn ($m) => [$m['user']->id => $m['score']]);

        $this->assertSame($scores[$this->lead->id], $scores[$this->second->id]);
        $this->assertGreaterThan(0, $scores[$this->lead->id]);
    }

    /*
    | Submitting
    */

    protected function submitAs(User $user, Assessment $assessment, array $extra = [])
    {
        $doc = Document::where('group_id', $this->group->id)->first() ?? Document::create([
            'group_id' => $this->group->id, 'created_by' => $this->lead->id, 'last_edited_by' => $this->lead->id, 'title' => 'Report', 'content' => '<p>Hello</p>',
        ]);

        return $this->actingAs($user)->post(route('student.submissions.store', $this->group), [
            'assessment_id' => $assessment->id, 'documents' => [$doc->id], ...$extra,
        ]);
    }

    public function test_the_leader_submits_and_withdraws_but_a_member_only_in_the_last_day(): void
    {
        $assessment = Assessment::defaultFor($this->subject);
        $assessment->update(['due_on' => '2026-11-10']);

        $this->submitAs($this->third, $assessment)->assertSessionHasErrors('submission');
        $this->assertSame(0, Submission::count());

        $this->submitAs($this->lead, $assessment)->assertSessionHasNoErrors();
        $submission = Submission::sole();

        $this->actingAs($this->third)->post(route('student.submissions.withdraw', $submission))->assertSessionHasErrors('submission');
        $this->assertNull($submission->fresh()->withdrawn_at);

        $page = $this->actingAs($this->third)->get(route('student.documents.group', $this->group))->getContent();
        $this->assertStringContainsString('Only the group leader can submit.', $page);
        $this->assertStringContainsString('Leader: '.$this->lead->name, $page);
        $this->assertStringContainsString('data-submission-current', $page, 'The status and history still show.');
        $this->assertStringContainsString('Download PDF', $page);
        $this->assertStringNotContainsString('Withdraw this submission?', $page);

        $leaderPage = $this->actingAs($this->lead)->get(route('student.documents.group', $this->group))->getContent();
        $this->assertStringContainsString('Withdraw this submission?', $leaderPage);

        // The due day itself opens it for members (and a late one stays allowed).
        Carbon::setTestNow('2026-11-10 00:00:00');
        $this->submitAs($this->third, $assessment)->assertSessionHasNoErrors();
        $this->assertSame(2, Submission::count());

        Carbon::setTestNow('2026-11-12 09:00:00');
        $this->submitAs($this->second, $assessment)->assertSessionHasNoErrors();
        $this->assertTrue(Submission::latest('id')->first()->is_late);
    }

    public function test_with_no_due_date_only_the_leader_submits_and_everyone_downloads(): void
    {
        $assessment = Assessment::defaultFor($this->subject);
        $assessment->update(['due_on' => null]);

        $this->submitAs($this->third, $assessment)->assertSessionHasErrors('submission');
        $this->submitAs($this->lead, $assessment)->assertSessionHasNoErrors();

        $url = route('submissions.pdf', Submission::sole());
        $this->actingAs($this->third)->get($url)->assertOk();
        $this->actingAs($this->lecturer)->get($url)->assertOk();
    }

    /*
    | Badges and labels
    */

    public function test_the_badge_shows_on_the_pages_that_list_members(): void
    {
        $manage = $this->actingAs($this->lecturer)->get(route('lecturer.groups.show', $this->group))->assertOk()->getContent();
        $this->assertStringContainsString('data-leader-badge', $manage);
        $this->assertStringContainsString('data-current-leader', $manage);
        $this->assertSame(2, substr_count($manage, 'aria-label="Make '), 'Make leader for everyone but the leader.');

        $workspace = $this->actingAs($this->second)->get(route('student.workspace.show', $this->group))->assertOk()->getContent();
        $this->assertStringContainsString('data-leader-badge', $workspace);

        $progress = $this->actingAs($this->lecturer)->get(route('lecturer.progress.show', $this->group))->assertOk()->getContent();
        $this->assertStringContainsString('data-leader-badge', $progress);

        $report = $this->actingAs($this->lecturer)->get(route('lecturer.reports.show', $this->group))->assertOk()->getContent();
        $this->assertStringContainsString('data-leader-badge', $report);

        $class = $this->actingAs($this->lecturer)->get(route('lecturer.classlist', $this->subject))->assertOk()->getContent();
        $this->assertStringContainsString('data-leader-badge', $class);

        $groups = $this->actingAs($this->lecturer)->get(route('lecturer.progress', ['subject' => $this->subject->id]))->assertOk()->getContent();
        $this->assertStringContainsString('data-leader-mark', $groups);
    }

    public function test_the_task_board_shows_who_leads_and_marks_the_leaders_cards(): void
    {
        Task::create(['group_id' => $this->group->id, 'created_by' => $this->lead->id, 'assigned_to' => $this->lead->id, 'title' => 'Lead task', 'status' => Task::STATUS_TODO]);
        Task::create(['group_id' => $this->group->id, 'created_by' => $this->lead->id, 'assigned_to' => $this->third->id, 'title' => 'Third task', 'status' => Task::STATUS_TODO]);

        $html = $this->board($this->third)->html();

        $this->assertStringContainsString('data-board-leader', $html);
        $this->assertStringContainsString('Leader: '.$this->lead->name, $html);
        $this->assertSame(1, substr_count($html, 'data-leader-badge data-tooltip="Leader: '.$this->lead->name.'"') - 1, 'One on the card of the leader, one in the header.');
    }

    public function test_the_dashboard_card_says_you_are_the_leader_only_to_the_leader(): void
    {
        $leaderPage = $this->actingAs($this->lead)->get(route('student.dashboard'))->assertOk()->getContent();
        $this->assertStringContainsString('data-you-lead', $leaderPage);

        $memberPage = $this->actingAs($this->third)->get(route('student.dashboard'))->assertOk()->getContent();
        $this->assertStringNotContainsString('data-you-lead', $memberPage);
    }

    public function test_the_task_form_offers_everyone_to_the_leader_and_only_themselves_to_a_member(): void
    {
        $leader = $this->board($this->lead)->call('toggleForm')->html();
        $this->assertStringContainsString('Nobody yet', $leader);
        $this->assertStringContainsString($this->third->name, $leader);

        $member = $this->board($this->third)->call('toggleForm')->html();
        $this->assertStringNotContainsString('Nobody yet', $member);
        $this->assertStringNotContainsString('<option value="'.$this->lead->id.'"', $member);
        $this->assertStringContainsString('Only the group leader can assign tasks to other members.', $member);
    }
}
