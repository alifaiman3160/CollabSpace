<?php

namespace Tests\Feature\Lecturer;

use App\Models\Group;
use App\Models\GroupMember;
use App\Models\Message;
use App\Models\Subject;
use App\Models\Task;
use App\Models\User;
use App\Support\ActivityRecorder;
use App\Support\ContributionReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContributionReportTest extends TestCase
{
    use RefreshDatabase;

    protected User $lecturer;

    protected Subject $subject;

    protected Group $group;

    protected function setUp(): void
    {
        parent::setUp();

        $this->lecturer = User::factory()->lecturer()->create([
            'name' => 'Dr Siti', 'must_change_password' => false,
        ]);

        $this->subject = Subject::create([
            'lecturer_id' => $this->lecturer->id,
            'code' => 'SECJ3104', 'name' => 'App Dev', 'group_size' => 4,
        ]);

        $this->group = Group::create([
            'subject_id' => $this->subject->id, 'name' => 'Group A', 'group_size' => 4,
        ]);
    }

    protected function member(string $name): User
    {
        $user = User::factory()->student()->create(['name' => $name]);
        GroupMember::create(['group_id' => $this->group->id, 'user_id' => $user->id, 'joined_at' => now()]);

        return $user;
    }

    /** Writes real log rows through the recorder the whole app uses. */
    protected function log(User $user, string $type, int $times = 1): void
    {
        $recorder = app(ActivityRecorder::class);

        for ($i = 0; $i < $times; $i++) {
            $recorder->record($user, $this->group, $type, null);
        }
    }

    protected function report(): array
    {
        return app(ContributionReport::class)->for($this->group->fresh());
    }

    /*
    | Scoring comes from the log
    */

    public function test_scores_are_summed_from_activity_log_weights(): void
    {
        $alif = $this->member('Alif');

        $this->log($alif, 'task_completed', 2);   // 2 x 5 = 10
        $this->log($alif, 'message_sent', 3);     // 3 x 1 = 3
        $this->log($alif, 'document_edited', 1);  // 1 x 3 = 3

        $report = $this->report();
        $row = $report['members']->firstWhere('user.id', $alif->id);

        $expected = 2 * config('contribution.weights.task_completed')
            + 3 * config('contribution.weights.message_sent')
            + 1 * config('contribution.weights.document_edited');

        $this->assertSame($expected, $row['score']);
        $this->assertSame($expected, $report['total']);
    }

    /**
     * The log is the record. Rows in tasks/messages that never produced a log
     * must not appear in the score, or the report would have two competing
     * definitions of the same number.
     */
    public function test_source_table_rows_without_a_log_do_not_score(): void
    {
        $alif = $this->member('Alif');

        // Created directly, bypassing the recorder — no log rows.
        Task::create([
            'group_id' => $this->group->id, 'created_by' => $alif->id, 'assigned_to' => $alif->id,
            'title' => 'Unlogged', 'status' => Task::STATUS_DONE, 'completed_at' => now(),
        ]);
        Message::create(['group_id' => $this->group->id, 'user_id' => $alif->id, 'body' => 'Unlogged']);

        $report = $this->report();

        $this->assertSame(0, $report['total']);
        $this->assertSame(0, $report['members']->firstWhere('user.id', $alif->id)['score']);
    }

    public function test_the_stored_weight_is_used_not_the_current_config(): void
    {
        $alif = $this->member('Alif');
        $this->log($alif, 'task_completed', 1);

        // Retuning the model must not rewrite a report of past activity.
        config(['contribution.weights.task_completed' => 99]);

        $this->assertSame(5, $this->report()['members']->firstWhere('user.id', $alif->id)['score']);
    }

    /*
    | Per-metric breakdown
    */

    public function test_each_metric_is_counted_separately(): void
    {
        $alif = $this->member('Alif');

        $this->log($alif, 'task_completed', 4);
        $this->log($alif, 'task_created', 3);
        $this->log($alif, 'message_sent', 7);
        $this->log($alif, 'file_uploaded', 2);
        $this->log($alif, 'document_created', 1);
        $this->log($alif, 'document_edited', 5);

        $counts = $this->report()['members']->firstWhere('user.id', $alif->id)['counts'];

        $this->assertSame(4, $counts['tasks_done']);
        $this->assertSame(3, $counts['tasks_created']);
        $this->assertSame(7, $counts['messages']);
        $this->assertSame(2, $counts['files']);
        // Doc edits folds creations in, so the columns account for the score.
        $this->assertSame(6, $counts['documents']);
    }

    /*
    | Shares and the top contributor
    */

    public function test_shares_are_percentages_of_the_group_total(): void
    {
        $a = $this->member('A');
        $b = $this->member('B');

        $this->log($a, 'task_completed', 3);   // 15
        $this->log($b, 'task_completed', 1);   // 5

        $report = $this->report();

        $this->assertSame(20, $report['total']);
        $this->assertEqualsWithDelta(75.0, $report['members']->firstWhere('user.id', $a->id)['share'], 0.01);
        $this->assertEqualsWithDelta(25.0, $report['members']->firstWhere('user.id', $b->id)['share'], 0.01);
        $this->assertSame($a->id, $report['top']['user']->id);
    }

    /*
    | Low participation
    */

    public function test_a_member_below_the_ratio_of_the_average_is_flagged(): void
    {
        $high = $this->member('High');
        $low = $this->member('Low');

        $this->log($high, 'task_completed', 8);   // 40
        $this->log($low, 'message_sent', 2);      // 2

        $report = $this->report();

        // average 21, ratio 0.5 => threshold 10.5
        $this->assertEqualsWithDelta(21.0, $report['average'], 0.01);
        $this->assertEqualsWithDelta(10.5, $report['threshold'], 0.01);

        $this->assertTrue($report['members']->firstWhere('user.id', $low->id)['flagged']);
        $this->assertFalse($report['members']->firstWhere('user.id', $high->id)['flagged']);
        $this->assertSame(1, $report['flagged']->count());
    }

    public function test_the_threshold_follows_the_configured_ratio(): void
    {
        config(['contribution.low_participation_ratio' => 0.9]);

        $a = $this->member('A');
        $b = $this->member('B');

        $this->log($a, 'task_completed', 3);   // 15
        $this->log($b, 'task_completed', 2);   // 10  average 12.5, threshold 11.25

        $report = $this->report();

        $this->assertEqualsWithDelta(11.25, $report['threshold'], 0.01);
        $this->assertTrue($report['members']->firstWhere('user.id', $b->id)['flagged']);
    }

    public function test_the_report_shows_the_threshold_not_just_the_verdict(): void
    {
        $high = $this->member('High');
        $low = $this->member('Low');
        $this->log($high, 'task_completed', 8);
        $this->log($low, 'message_sent', 2);

        $this->actingAs($this->lecturer)
            ->get(route('lecturer.reports.show', $this->group))
            ->assertOk()
            ->assertSee('Threshold')
            ->assertSee('10.5')
            ->assertSee('Average')
            ->assertSee('50% of the average');
    }

    /*
    | The awkward states
    */

    public function test_a_member_with_no_activity_still_appears_as_a_zero_row(): void
    {
        $active = $this->member('Active');
        $silent = $this->member('Silent');

        $this->log($active, 'task_completed', 2);

        $report = $this->report();

        $row = $report['members']->firstWhere('user.id', $silent->id);

        $this->assertNotNull($row, 'A silent member must not be dropped from the report.');
        $this->assertSame(0, $row['score']);
        $this->assertSame(0.0, $row['share']);
        $this->assertTrue($row['flagged']);
        $this->assertSame(2, $report['members']->count());
    }

    public function test_a_silent_member_is_listed_on_the_page(): void
    {
        $this->member('Active One');
        $silent = $this->member('Totally Silent');

        $this->log(User::where('name', 'Active One')->sole(), 'task_completed', 2);

        $this->actingAs($this->lecturer)
            ->get(route('lecturer.reports.show', $this->group))
            ->assertOk()
            ->assertSee('Totally Silent');
    }

    public function test_a_group_with_no_activity_at_all_reports_cleanly(): void
    {
        $this->member('A');
        $this->member('B');

        $report = $this->report();

        $this->assertSame(0, $report['total']);
        $this->assertSame(0.0, $report['average']);
        $this->assertSame(0.0, $report['threshold']);
        $this->assertFalse($report['hasActivity']);
        $this->assertNull($report['top']);

        // Nobody is flagged: a group that has not started is not free riders.
        $this->assertSame(0, $report['flagged']->count());
        $this->assertSame(2, $report['members']->count());
    }

    public function test_the_no_activity_page_explains_itself(): void
    {
        $this->member('A');

        $this->actingAs($this->lecturer)
            ->get(route('lecturer.reports.show', $this->group))
            ->assertOk()
            ->assertSee('No activity recorded yet')
            ->assertDontSee('Free rider detected');
    }

    public function test_a_group_with_no_members_does_not_divide_by_zero(): void
    {
        $report = $this->report();

        $this->assertSame(0, $report['total']);
        $this->assertSame(0.0, $report['average']);
        $this->assertSame(0, $report['members']->count());

        $this->actingAs($this->lecturer)
            ->get(route('lecturer.reports.show', $this->group))
            ->assertOk();
    }

    /*
    | Export
    */

    public function test_the_export_downloads_a_csv_with_the_figures_and_provenance(): void
    {
        $alif = $this->member('Alif Aiman');
        $this->log($alif, 'task_completed', 2);
        $this->log($alif, 'message_sent', 3);

        $response = $this->actingAs($this->lecturer)
            ->get(route('lecturer.reports.export', $this->group))
            ->assertOk();

        $this->assertStringContainsString('text/csv', $response->headers->get('content-type'));
        $this->assertStringStartsWith('attachment;', $response->headers->get('content-disposition'));

        $csv = $response->streamedContent();

        // Provenance
        $this->assertStringContainsString('Group A', $csv);
        $this->assertStringContainsString('SECJ3104', $csv);
        $this->assertStringContainsString('Dr Siti', $csv);
        $this->assertStringContainsString('Generated', $csv);

        // The weights the score was built from
        $this->assertStringContainsString('Weights used', $csv);
        $this->assertStringContainsString('task_completed', $csv);

        // The basis of the flag
        $this->assertStringContainsString('Low participation threshold', $csv);

        // The figures
        $this->assertStringContainsString('Alif Aiman', $csv);
        $this->assertStringContainsString('13', $csv);   // 2*5 + 3*1
    }

    public function test_a_silent_member_appears_in_the_export_too(): void
    {
        $this->member('Loud');
        $this->member('Silent Sam');
        $this->log(User::where('name', 'Loud')->sole(), 'task_completed', 2);

        $csv = $this->actingAs($this->lecturer)
            ->get(route('lecturer.reports.export', $this->group))
            ->streamedContent();

        $this->assertStringContainsString('Silent Sam', $csv);
    }

    /*
    | Ownership
    */

    public function test_another_lecturers_group_report_is_a_404(): void
    {
        $other = User::factory()->lecturer()->create(['must_change_password' => false]);
        $theirSubject = Subject::create([
            'lecturer_id' => $other->id, 'code' => 'X100', 'name' => 'Theirs', 'group_size' => 4,
        ]);
        $theirGroup = Group::create([
            'subject_id' => $theirSubject->id, 'name' => 'Their Group', 'group_size' => 4,
        ]);

        $this->actingAs($this->lecturer)
            ->get(route('lecturer.reports.show', $theirGroup))
            ->assertNotFound();

        $this->actingAs($this->lecturer)
            ->get(route('lecturer.reports.export', $theirGroup))
            ->assertNotFound();
    }

    public function test_the_group_list_shows_only_my_own(): void
    {
        $other = User::factory()->lecturer()->create(['must_change_password' => false]);
        $theirSubject = Subject::create([
            'lecturer_id' => $other->id, 'code' => 'X100', 'name' => 'Theirs', 'group_size' => 4,
        ]);
        Group::create(['subject_id' => $theirSubject->id, 'name' => 'Their Group', 'group_size' => 4]);

        $this->actingAs($this->lecturer)
            ->get(route('lecturer.reports'))
            ->assertOk()
            ->assertSee('Group A')
            ->assertDontSee('Their Group');
    }

    public function test_reports_are_closed_to_students_and_admins(): void
    {
        $student = User::factory()->student()->create(['must_change_password' => false]);
        $admin = User::factory()->admin()->create(['must_change_password' => false]);

        foreach ([$student, $admin] as $user) {
            $this->actingAs($user)->get(route('lecturer.reports'))->assertForbidden();
            $this->actingAs($user)->get(route('lecturer.reports.show', $this->group))->assertForbidden();
        }
    }

    /*
    | Selection screen
    */

    public function test_the_selection_screen_shows_the_flag_count(): void
    {
        $high = $this->member('High');
        $low = $this->member('Low');
        $this->log($high, 'task_completed', 8);
        $this->log($low, 'message_sent', 2);

        $this->actingAs($this->lecturer)
            ->get(route('lecturer.reports'))
            ->assertOk()
            ->assertSee('Group A')
            ->assertSee('SECJ3104')
            ->assertSee('App Dev')
            ->assertSee('1 flagged');
    }
}
