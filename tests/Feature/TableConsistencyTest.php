<?php

namespace Tests\Feature;

use App\Models\Group;
use App\Models\GroupMember;
use App\Models\Subject;
use App\Models\Task;
use App\Models\User;
use App\Support\ActivityRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Every table goes through x-data-table and the shared <x-table.*> cells, shows
 * the same facts it always did, and never leaks lecturer-only judgements to a
 * student.
 */
class TableConsistencyTest extends TestCase
{
    use RefreshDatabase;

    /*
    | Structure
    */

    public function test_no_view_builds_a_raw_table_outside_the_shared_component(): void
    {
        $offenders = [];

        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(resource_path('views'), \FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            $path = str_replace(DIRECTORY_SEPARATOR, '/', $file->getPathname());

            if (! str_ends_with($path, '.blade.php')
                || str_contains($path, 'vendor/mail')                  // email markup
                || str_ends_with($path, 'components/data-table.blade.php')   // the component itself
                || str_ends_with($path, 'components/rich-text.blade.php')) { // tables inside a document
                continue;
            }

            if (preg_match('/<table\b/', file_get_contents($path))) {
                $offenders[] = substr($path, strlen(str_replace(DIRECTORY_SEPARATOR, '/', resource_path('views'))) + 1);
            }
        }

        $this->assertSame([], $offenders, "Use <x-data-table> instead of a raw <table>:\n".implode("\n", $offenders));
    }

    public function test_the_shared_table_has_a_quiet_header_and_scrolls_on_narrow_screens(): void
    {
        $html = \Illuminate\Support\Facades\Blade::render(
            '<x-data-table :headings="[\'A\', [\'B\', \'right\']]"><tr><td>x</td><td class="num">1</td></tr></x-data-table>',
        );

        $this->assertStringContainsString('overflow-x-auto', $html);
        $this->assertStringContainsString('min-width: 480px', $html);
        $this->assertStringContainsString('text-tiny font-semibold text-ink-300', $html);
        $this->assertStringContainsString('text-right', $html);
        $this->assertStringNotContainsString('hover:-translate', $html);
    }

    public function test_no_row_action_is_revealed_by_hover(): void
    {
        $css = file_get_contents(resource_path('css/app.css'));

        $this->assertStringNotContainsString('.row-actions', $css);
        $this->assertStringNotContainsString('.data-table tbody tr:hover .', $css);
    }

    /*
    | Lecturer tables keep their data
    */

    protected function cohort(): array
    {
        $lecturer = User::factory()->lecturer()->create(['must_change_password' => false]);
        $subject = Subject::create([
            'lecturer_id' => $lecturer->id, 'code' => 'SECJ3104', 'name' => 'Application Development',
            'group_size' => 4, 'project_deadline' => now()->addDays(3)->toDateString(),
        ]);
        $group = Group::create(['subject_id' => $subject->id, 'name' => 'Group A', 'group_size' => 4]);

        $busy = User::factory()->student()->create(['name' => 'Busy Bee', 'cgpa' => 3.91, 'must_change_password' => false]);
        $idle = User::factory()->student()->create(['name' => 'Idle Ian', 'cgpa' => 2.10, 'must_change_password' => false]);
        $other = User::factory()->student()->create(['name' => 'Other Olu', 'must_change_password' => false]);

        foreach ([$busy, $idle, $other] as $s) {
            GroupMember::create(['group_id' => $group->id, 'user_id' => $s->id, 'joined_at' => now()]);
        }

        $recorder = app(ActivityRecorder::class);
        for ($i = 0; $i < 8; $i++) {
            $recorder->record($busy, $group, 'task_completed', null);
            $recorder->record($other, $group, 'task_completed', null);
        }

        Task::create(['group_id' => $group->id, 'created_by' => $lecturer->id, 'title' => 'Done', 'status' => Task::STATUS_DONE, 'completed_at' => now()]);
        Task::create(['group_id' => $group->id, 'created_by' => $lecturer->id, 'title' => 'Late', 'status' => Task::STATUS_TODO, 'deadline' => now()->subWeek()->toDateString()]);

        return compact('lecturer', 'subject', 'group', 'busy', 'idle', 'other');
    }

    public function test_the_students_table_keeps_scores_cgpa_and_the_low_flag(): void
    {
        $c = $this->cohort();

        $html = $this->actingAs($c['lecturer'])->get(route('lecturer.students'))->assertOk()->getContent();

        foreach (['Busy Bee', 'Idle Ian', 'Other Olu', '3.91', '2.10', 'Group A', 'SECJ3104', '40 pts', '0 pts'] as $needle) {
            $this->assertStringContainsString($needle, $html, $needle);
        }

        // Exactly one student is below the threshold, and it is the idle one.
        $this->assertSame(1, substr_count($html, '>Low<') + substr_count($html, "Low\n"));
        $this->assertMatchesRegularExpression('/Idle Ian.*?Low/s', $html);
    }

    public function test_the_subjects_table_flags_a_deadline_within_a_week_and_one_that_has_passed(): void
    {
        $c = $this->cohort();

        $this->actingAs($c['lecturer'])->get(route('lecturer.subjects'))->assertOk()
            ->assertSee('SECJ3104')
            ->assertSee('Application Development')
            ->assertSee('Due in 3 days');

        $c['subject']->update(['project_deadline' => now()->subDays(2)->toDateString()]);
        $this->actingAs($c['lecturer'])->get(route('lecturer.subjects'))->assertSee('Overdue');

        $c['subject']->update(['project_deadline' => now()->addDays(30)->toDateString()]);
        $this->actingAs($c['lecturer'])->get(route('lecturer.subjects'))->assertDontSee('Due in')->assertDontSee('Overdue');

        $c['subject']->update(['project_deadline' => null]);
        $this->actingAs($c['lecturer'])->get(route('lecturer.subjects'))->assertDontSee('Not set')->assertDontSee('None');
    }

    public function test_the_group_cells_are_the_same_on_groups_the_dashboard_and_monitor(): void
    {
        $c = $this->cohort();

        // Monitor goes straight to the group when there is only one; a second makes it a list.
        Group::create(['subject_id' => $c['subject']->id, 'name' => 'Group B', 'group_size' => 4]);

        foreach (['lecturer.groups', 'lecturer.dashboard', 'lecturer.progress'] as $route) {
            $html = $this->actingAs($c['lecturer'])->get(route($route))->assertOk()->getContent();

            $this->assertStringContainsString('3/4', $html, $route);
            $this->assertStringContainsString('1 of 2 tasks', $html, $route);
            $this->assertStringContainsString('1 overdue', $html, $route);
            $this->assertStringContainsString('Application Development', $html, $route);
        }
    }

    public function test_the_contribution_breakdown_keeps_every_member_and_the_free_rider_flag(): void
    {
        $c = $this->cohort();

        $html = $this->actingAs($c['lecturer'])->get(route('lecturer.reports.show', $c['group']))->assertOk()->getContent();

        $this->assertStringContainsString('Detailed breakdown', $html);
        $this->assertStringContainsString('Free rider', $html);
        $this->assertStringContainsString('Threshold', $html);   // the threshold line stays visible
        $this->assertMatchesRegularExpression('/Idle Ian.*?Free rider/s', $html);
        $this->assertStringContainsString('40', $html);
    }

    public function test_the_admin_user_table_keeps_roles_status_and_adds_a_created_date(): void
    {
        $admin = User::factory()->admin()->create(['must_change_password' => false]);
        $inactive = User::factory()->student()->create(['name' => 'Gone Gus', 'is_active' => false]);

        $html = $this->actingAs($admin)->get(route('admin.users'))->assertOk()->getContent();

        foreach (['Gone Gus', 'Student', 'Administrator', 'Inactive', 'Active', 'Added', '(you)', 'Reset password', 'Deactivate', 'Activate'] as $needle) {
            $this->assertStringContainsString($needle, $html, $needle);
        }
        $this->assertStringContainsString($inactive->created_at->format('j F Y'), $html);
    }

    /*
    | Students never see the lecturer's verdicts
    */

    public function test_no_student_page_shows_the_low_flag_a_free_rider_label_or_cgpa(): void
    {
        $c = $this->cohort();
        $student = $c['idle'];   // the flagged one, looking at their own pages

        $urls = [
            route('student.dashboard'),
            route('student.workspace.show', $c['group']),
            route('student.board.show', $c['group']),
            route('student.chat.show', $c['group']),
            route('student.documents.group', $c['group']),
            route('student.notifications'),
            route('account'),
        ];

        foreach ([$student, $c['busy']] as $viewer) {
            foreach ($urls as $url) {
                $html = $this->actingAs($viewer)->get($url)->assertOk()->getContent();

                foreach (['Low participation', 'Free rider', 'Free-rider', 'free rider', 'CGPA', 'cgpa', '3.91', '2.10', 'threshold'] as $forbidden) {
                    $this->assertStringNotContainsString($forbidden, $html, "{$forbidden} appears on {$url} for {$viewer->name}");
                }

                $this->assertDoesNotMatchRegularExpression('/>\s*Low\s*</', $html, "A Low pill appears on {$url}");
            }
        }
    }
}
