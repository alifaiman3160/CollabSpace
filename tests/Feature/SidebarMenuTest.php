<?php

namespace Tests\Feature;

use App\Models\Group;
use App\Models\GroupMember;
use App\Models\Rubric;
use App\Models\Subject;
use App\Models\Task;
use App\Models\User;
use App\Support\SidebarState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The sidebar for every role: the same items in the same order, a slim rail that is remembered, a role chip, and
 * (for a lecturer, and for a student with two or more groups) items that open into the person's own groups.
 */
class SidebarMenuTest extends TestCase
{
    use RefreshDatabase;

    protected const LECTURER_ITEMS = ['Dashboard', 'My subjects', 'Groups', 'Marking', 'Contribution', 'Students', 'Monitor progress', 'Notifications', 'My account'];

    protected const STUDENT_ITEMS = ['Dashboard', 'My workspace', 'Task board', 'Documents', 'Group chat', 'Notifications', 'My account'];

    protected User $lecturer;

    protected Subject $subject;

    protected function setUp(): void
    {
        parent::setUp();

        $this->lecturer = User::factory()->lecturer()->create(['must_change_password' => false]);
        $this->subject = Subject::create(['lecturer_id' => $this->lecturer->id, 'code' => 'SECJ3104', 'name' => 'App Dev', 'group_size' => 4]);
    }

    protected function group(string $name, ?Subject $subject = null): Group
    {
        return Group::create(['subject_id' => ($subject ?? $this->subject)->id, 'name' => $name, 'group_size' => 4]);
    }

    protected function student(Group ...$groups): User
    {
        $student = User::factory()->student()->create(['must_change_password' => false]);

        foreach ($groups as $group) {
            GroupMember::create(['group_id' => $group->id, 'user_id' => $student->id, 'joined_at' => now()]);
        }

        return $student;
    }

    protected function overdue(Group $group, int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            Task::create(['group_id' => $group->id, 'created_by' => $this->lecturer->id, 'title' => "Late {$i}", 'status' => Task::STATUS_TODO, 'deadline' => now()->subDays(3)->toDateString()]);
        }
    }

    protected function nav(string $html): string
    {
        preg_match('/<nav[^>]*aria-label="Main".*?<\/nav>/s', $html, $nav);
        $this->assertNotEmpty($nav, 'No sidebar rendered.');

        return $nav[0];
    }

    /** The names of the top-level items, in order. */
    protected function items(string $nav): array
    {
        preg_match_all('/<a(?=[^>]*data-nav-link)[^>]*>.*?<span class="cs-label[^>]*>(.*?)<\/span>/s', $nav, $m);

        return array_map(fn ($label) => trim(strip_tags($label)), $m[1]);
    }

    /** The sub-items of the section with this key: [label, href] each. */
    protected function children(string $nav, string $key): array
    {
        if (! preg_match('/data-nav-group="'.$key.'".*?<ul class="cs-sublist">(.*?)<\/ul>/s', $nav, $list)) {
            return [];
        }

        preg_match_all('/<a href="([^"]+)"[^>]*data-child[^>]*>.*?<span class="truncate">(.*?)<\/span>/s', $list[1], $m);

        return array_map(fn ($label, $href) => [trim($label), html_entity_decode($href)], $m[2], $m[1]);
    }

    protected function page(User $user, string $url, array $cookies = []): string
    {
        $request = $this->actingAs($user);

        foreach ($cookies as $name => $value) {
            $request = $request->withUnencryptedCookie($name, $value);
        }

        return $request->get($url)->assertOk()->getContent();
    }

    public function test_every_role_keeps_its_items_in_their_original_names_and_order(): void
    {
        $this->group('Group A');
        $admin = User::factory()->admin()->create(['must_change_password' => false]);

        $this->assertSame(self::LECTURER_ITEMS, $this->items($this->nav($this->page($this->lecturer, route('lecturer.dashboard')))));
        $this->assertSame(self::STUDENT_ITEMS, $this->items($this->nav($this->page($this->student(), route('student.dashboard')))));
        $this->assertSame(['Dashboard', 'User accounts', 'Subjects', 'Notifications', 'My account'], $this->items($this->nav($this->page($admin, route('admin.dashboard')))));
    }

    public function test_no_invented_section_headings_exist(): void
    {
        $this->group('Group A');

        foreach ([[$this->lecturer, route('lecturer.dashboard')], [$this->student($this->group('S')), route('student.dashboard')]] as [$user, $url]) {
            $sidebar = $this->nav($this->page($user, $url));

            foreach (['Teaching', 'Assessment', 'Overview', 'Workspace</p>'] as $heading) {
                $this->assertStringNotContainsString($heading, $sidebar);
            }
        }
    }

    public function test_marking_contribution_and_monitor_progress_open_into_the_lecturers_groups_and_nothing_else_does(): void
    {
        $this->group('Group A');

        $nav = $this->nav($this->page($this->lecturer, route('lecturer.dashboard')));

        $this->assertSame(3, substr_count($nav, 'data-nav-group='));

        foreach (['marking', 'reports', 'progress'] as $key) {
            $this->assertSame('All groups', last($this->children($nav, $key))[0]);
            $this->assertSame('Group A', $this->children($nav, $key)[0][0]);
        }

        $this->assertSame([], $this->children($nav, 'groups'), 'Groups stays a plain item.');
    }

    public function test_the_group_rows_go_to_that_items_page_for_the_group(): void
    {
        $group = $this->group('Group A');
        $rubric = Rubric::create(['subject_id' => $this->subject->id, 'name' => 'Rubric', 'version' => 1, 'is_current' => true, 'created_by' => $this->lecturer->id]);

        $nav = $this->nav($this->page($this->lecturer, route('lecturer.dashboard')));

        $this->assertSame([['Group A', route('lecturer.marking.group', [$rubric, $group])], ['All groups', route('lecturer.marking')]], $this->children($nav, 'marking'));
        $this->assertSame([['Group A', route('lecturer.reports.show', $group)], ['All groups', route('lecturer.reports')]], $this->children($nav, 'reports'));
        $this->assertSame([['Group A', route('lecturer.progress.show', $group)], ['All groups', route('lecturer.progress')]], $this->children($nav, 'progress'));
    }

    public function test_the_groups_with_the_most_overdue_tasks_come_first_and_only_six_are_listed(): void
    {
        $names = [];

        foreach (range(1, 8) as $i) {
            $names[$i] = $this->group("Group {$i}");
        }

        $this->overdue($names[7], 3);
        $this->overdue($names[4], 1);
        $this->overdue($names[2], 2);

        $rows = array_column($this->children($this->nav($this->page($this->lecturer, route('lecturer.dashboard'))), 'reports'), 0);

        $this->assertSame(['Group 7', 'Group 2', 'Group 4', 'Group 1', 'Group 3', 'Group 5', 'All groups'], $rows);
    }

    public function test_a_lecturer_with_no_groups_sees_plain_items(): void
    {
        $nav = $this->nav($this->page($this->lecturer, route('lecturer.dashboard')));

        $this->assertStringNotContainsString('data-nav-group', $nav);
        $this->assertSame(self::LECTURER_ITEMS, $this->items($nav));
    }

    public function test_a_lecturer_only_sees_their_own_groups(): void
    {
        $this->group('Mine');
        $other = User::factory()->lecturer()->create(['must_change_password' => false]);
        $theirs = Subject::create(['lecturer_id' => $other->id, 'code' => 'OTHER1', 'name' => 'Other', 'group_size' => 4]);
        $this->group('Not mine', $theirs);

        $nav = $this->nav($this->page($this->lecturer, route('lecturer.dashboard')));

        $this->assertStringContainsString('Mine', $nav);
        $this->assertStringNotContainsString('Not mine', $nav);
    }

    public function test_a_student_with_one_group_sees_plain_items(): void
    {
        $nav = $this->nav($this->page($this->student($this->group('Only')), route('student.dashboard')));

        $this->assertStringNotContainsString('data-nav-group', $nav);
        $this->assertSame(self::STUDENT_ITEMS, $this->items($nav));
    }

    public function test_a_student_with_two_groups_sees_their_groups_under_four_items_with_the_right_links(): void
    {
        $other = Subject::create(['lecturer_id' => $this->lecturer->id, 'code' => 'BCS3323', 'name' => 'SE', 'group_size' => 4]);
        $alpha = $this->group('Alpha');
        $beta = $this->group('Beta', $other);
        $this->group('Stranger');
        $student = $this->student($alpha, $beta);

        $html = $this->page($student, route('student.dashboard'));
        $nav = $this->nav($html);

        $this->assertSame(4, substr_count($nav, 'data-nav-group='));
        $this->assertSame([['Alpha', route('student.workspace.show', $alpha)], ['Beta', route('student.workspace.show', $beta)]], $this->children($nav, 'workspace'));
        $this->assertSame([['Alpha', route('student.board.show', $alpha)], ['Beta', route('student.board.show', $beta)]], $this->children($nav, 'board'));
        $this->assertSame([['Alpha', route('student.documents.group', $alpha)], ['Beta', route('student.documents.group', $beta)]], $this->children($nav, 'documents'));
        $this->assertSame([['Alpha', route('student.chat.show', $alpha)], ['Beta', route('student.chat.show', $beta)]], $this->children($nav, 'chat'));
        $this->assertStringContainsString('SECJ3104', $nav);
        $this->assertStringContainsString('BCS3323', $nav);
        $this->assertStringNotContainsString('Stranger', $nav, 'Only the student\'s own groups.');
        $this->assertSame(self::STUDENT_ITEMS, $this->items($nav));
    }

    public function test_the_section_with_the_current_page_is_open_and_the_group_row_is_the_black_leaf(): void
    {
        $a = $this->group('Alpha');
        $b = $this->group('Beta');

        $nav = $this->nav($this->page($this->lecturer, route('lecturer.progress.documents', $b)));

        // The item stays the black pill, the right group's row is lit too, and the other two sections stay shut.
        $this->assertMatchesRegularExpression('/<a(?=[^>]*data-nav-link)(?=[^>]*\sdata-parent-current[\s>])(?=[^>]*\sdata-current)[^>]*>.*?Monitor progress/s', $nav);
        $this->assertMatchesRegularExpression('/<a href="[^"]*progress\/'.$b->id.'"[^>]*aria-current="page"[^>]*data-current/s', $nav);
        $this->assertDoesNotMatchRegularExpression('/<a href="[^"]*progress\/'.$a->id.'"[^>]*aria-current/s', $nav);
        $this->assertMatchesRegularExpression('/id="nav-sub-progress" class="cs-subnav is-open"/', $nav);
        $this->assertMatchesRegularExpression('/id="nav-sub-marking" class="cs-subnav "/', $nav);
        $this->assertMatchesRegularExpression('/id="nav-sub-reports" class="cs-subnav "/', $nav);
    }

    public function test_on_the_items_own_list_page_the_item_is_the_black_pill_and_no_row_is(): void
    {
        $this->group('Alpha');

        $nav = $this->nav($this->page($this->lecturer, route('lecturer.reports')));

        $this->assertMatchesRegularExpression('/<a(?=[^>]*data-nav-link)(?=[^>]*aria-current="page")(?=[^>]*\sdata-current)[^>]*>.*?Contribution/s', $nav);
        $this->assertSame(0, preg_match_all('/data-child[^>]*aria-current/', $nav));
        $this->assertStringNotContainsString(' data-parent-current>', $nav);
    }

    public function test_the_open_section_is_the_one_holding_the_page_and_the_cookie_does_not_add_more(): void
    {
        $this->group('Alpha');

        // On the dashboard nothing holds the page: all closed, whatever an earlier visit left in the cookie.
        $html = $this->page($this->lecturer, route('lecturer.dashboard'), [SidebarState::OPEN_COOKIE => 'marking,reports']);
        $nav = $this->nav($html);

        $this->assertSame(0, preg_match_all('/class="cs-subnav is-open"/', $nav));
        $this->assertMatchesRegularExpression('/aria-controls="nav-sub-marking"[^>]*aria-expanded="false"/', $nav);
        $this->assertStringContainsString('data-nav-current=""', $html);
        $this->assertStringContainsString('data-open=""', $html);
    }

    public function test_a_lecturer_page_opens_only_its_own_section(): void
    {
        $this->group('Alpha');

        $html = $this->page($this->lecturer, route('lecturer.reports'), [SidebarState::OPEN_COOKIE => 'marking']);
        $nav = $this->nav($html);

        $this->assertSame(1, preg_match_all('/class="cs-subnav is-open"/', $nav));
        $this->assertMatchesRegularExpression('/id="nav-sub-reports" class="cs-subnav is-open"/', $nav);
        $this->assertMatchesRegularExpression('/aria-controls="nav-sub-reports"[^>]*aria-expanded="true"/', $nav);
        $this->assertStringContainsString('data-nav-current="reports"', $html);
        $this->assertStringContainsString('data-open="reports"', $html);
    }

    public function test_student_task_board_and_workspace_pages_mark_their_section_open(): void
    {
        $a = $this->group('Group A');
        $b = $this->group('Group B');
        $student = $this->student($a, $b);

        $board = $this->page($student, route('student.board.show', $b), [SidebarState::OPEN_COOKIE => 'workspace']);
        $this->assertStringContainsString('data-nav-current="board"', $board);
        $this->assertSame(1, preg_match_all('/class="cs-subnav is-open"/', $this->nav($board)));
        $this->assertMatchesRegularExpression('/id="nav-sub-board" class="cs-subnav is-open"/', $this->nav($board));

        $workspace = $this->page($student, route('student.workspace.show', $a));
        $this->assertStringContainsString('data-nav-current="workspace"', $workspace);
        $this->assertSame(1, preg_match_all('/class="cs-subnav is-open"/', $this->nav($workspace)));
        $this->assertMatchesRegularExpression('/id="nav-sub-workspace" class="cs-subnav is-open"/', $this->nav($workspace));

        $dashboard = $this->page($student, route('student.dashboard'));
        $this->assertStringContainsString('data-nav-current=""', $dashboard);
        $this->assertSame(0, preg_match_all('/class="cs-subnav is-open"/', $this->nav($dashboard)));
    }

    public function test_one_shared_script_drives_the_accordion_for_every_role(): void
    {
        $js = file_get_contents(resource_path('js/sidebar.js'));

        $this->assertStringContainsString('followPage()', $js);
        $this->assertStringContainsString("data-nav-current", $js);
    }
    public function test_the_sidebar_is_expanded_by_default_and_collapsed_from_the_cookie(): void
    {
        $student = $this->student();

        $default = $this->page($student, route('student.dashboard'));
        preg_match('/<aside[^>]*>/s', $default, $aside);
        $this->assertStringNotContainsString('data-collapsed', $aside[0]);
        $this->assertStringContainsString('data-auto', $aside[0], 'Nobody has chosen: a narrow screen may start slim.');
        $this->assertMatchesRegularExpression('/class="cs-toggle[^"]*"\s+aria-controls="sidebar-nav"\s+aria-expanded="true" aria-label="Collapse sidebar"/', $default);
        $this->assertStringNotContainsString('aria-label="Dashboard"', $default, 'Expanded items are named by their text.');

        $expanded = $this->page($student, route('student.dashboard'), [SidebarState::COLLAPSE_COOKIE => 'expanded']);
        preg_match('/<aside[^>]*>/s', $expanded, $aside);
        $this->assertStringNotContainsString('data-collapsed', $aside[0]);
        $this->assertStringNotContainsString('data-auto', $aside[0], 'An explicit choice wins over the width.');

        $collapsed = $this->page($student, route('student.dashboard'), [SidebarState::COLLAPSE_COOKIE => 'collapsed']);
        preg_match('/<aside[^>]*>/s', $collapsed, $aside);
        $this->assertStringContainsString('data-collapsed', $aside[0]);
        $this->assertStringNotContainsString('data-auto', $aside[0]);
        $this->assertMatchesRegularExpression('/aria-expanded="false" aria-label="Expand sidebar"/', $collapsed);
    }

    public function test_in_the_slim_rail_every_item_is_named_and_has_a_tooltip_to_the_right(): void
    {
        $nav = $this->nav($this->page($this->student(), route('student.dashboard'), [SidebarState::COLLAPSE_COOKIE => 'collapsed']));

        foreach (self::STUDENT_ITEMS as $label) {
            $this->assertMatchesRegularExpression('/<a(?=[^>]*aria-label="'.preg_quote($label, '/').'")(?=[^>]*data-tooltip="'.preg_quote($label, '/').'")(?=[^>]*data-tooltip-side="right")[^>]*>/', $nav, $label);
        }
    }

    public function test_the_notification_count_shows_in_both_states(): void
    {
        $student = $this->student($this->group('A'));
        \App\Models\AppNotification::create(['user_id' => $student->id, 'type' => 'announcement', 'reference_id' => 1, 'message' => 'Hello', 'is_read' => false]);

        foreach ([[], [SidebarState::COLLAPSE_COOKIE => 'collapsed']] as $cookies) {
            $nav = $this->nav($this->page($student, route('student.dashboard'), $cookies));

            $this->assertMatchesRegularExpression('/class="cs-nav-badge">.*?unread notifications/s', $nav);
        }
    }

    public function test_the_role_chip_says_the_role_in_its_own_colour(): void
    {
        $admin = User::factory()->admin()->create(['must_change_password' => false]);

        $lecturer = $this->page($this->lecturer, route('lecturer.dashboard'));
        $this->assertMatchesRegularExpression('/data-role-chip="lecturer".*?Lecturer Portal/s', $lecturer);
        $this->assertMatchesRegularExpression('/bg-success-bg text-success-text[^"]*"[^>]*data-role-chip="lecturer"/', $lecturer);

        $student = $this->page($this->student(), route('student.dashboard'));
        $this->assertMatchesRegularExpression('/data-role-chip="student".*?Student Portal/s', $student);
        $this->assertMatchesRegularExpression('/text-periwinkle-link[^"]*"[^>]*data-role-chip="student"/', $student);

        $adminHtml = $this->page($admin, route('admin.dashboard'));
        $this->assertMatchesRegularExpression('/bg-ink text-white[^"]*"[^>]*data-role-chip="admin".*?Administrator Portal/s', $adminHtml);

        // In the rail the chip is a letter under the logo mark, with its own tooltip.
        $rail = $this->page($this->lecturer, route('lecturer.dashboard'), [SidebarState::COLLAPSE_COOKIE => 'collapsed']);
        $this->assertMatchesRegularExpression('/data-role-dot="lecturer"[^>]*data-tooltip="Lecturer Portal" data-tooltip-side="right"\s*>L</', $rail);
    }

    public function test_logout_still_works_from_the_sidebar(): void
    {
        $student = $this->student();

        $html = $this->page($student, route('student.dashboard'));
        $this->assertMatchesRegularExpression('/<form method="POST" action="[^"]*logout">.*?aria-label="Log out"/s', $html);

        $this->actingAs($student)->post(route('logout'))->assertRedirect();
        $this->assertGuest();
    }

    public function test_the_sidebar_adds_a_fixed_number_of_queries_however_many_groups_there_are(): void
    {
        $count = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->actingAs($this->lecturer)->get(route('lecturer.dashboard'))->assertOk();

            return count(DB::getQueryLog());
        };

        $this->group('One');
        $few = $count();

        foreach (range(2, 12) as $i) {
            $this->group("Group {$i}");
        }

        $this->assertSame($few, $count(), 'The sidebar must not query once per group.');

        $student = $this->student(...array_map(fn ($i) => $this->group("S{$i}"), range(1, 8)));
        DB::flushQueryLog();
        $this->actingAs($student)->get(route('student.dashboard'))->assertOk();
        $withEight = count(DB::getQueryLog());

        $other = $this->student($this->group('X1'), $this->group('X2'));
        DB::flushQueryLog();
        $this->actingAs($other)->get(route('student.dashboard'))->assertOk();

        $this->assertSame($withEight, count(DB::getQueryLog()));
    }

    public function test_the_chevron_direction_comes_from_aria_expanded_alone(): void
    {
        $this->group('Alpha');

        $nav = $this->nav($this->page($this->lecturer, route('lecturer.dashboard')));
        $css = file_get_contents(resource_path('css/app.css'));

        $this->assertStringNotContainsString('rotate-[-90deg]', $nav);
        $this->assertStringNotContainsString('rotate-90', $nav, 'A fixed rotate class would fight the open state.');
        $this->assertSame(3, substr_count($nav, 'cs-chev__icon'));
        $this->assertStringContainsString(".cs-chev[aria-expanded='true'] .cs-chev__icon { rotate: -90deg; }", $css);
    }

    public function test_the_items_that_open_have_a_real_button_and_a_real_list(): void
    {
        $this->group('Alpha');

        $nav = $this->nav($this->page($this->lecturer, route('lecturer.dashboard')));

        $this->assertSame(3, preg_match_all('/<button type="button"[^>]*class="cs-chev[^>]*aria-controls="nav-sub-[a-z]+"[^>]*aria-expanded="(true|false)"/', $nav));
        $this->assertSame(3, substr_count($nav, '<ul class="cs-sublist">'));
        $this->assertSame(3, preg_match_all('/<button type="button"[^>]*class="cs-chev[^>]*interactive/', $nav));
    }

    public function test_the_sidebar_width_is_one_variable_and_motion_has_a_reduced_motion_override(): void
    {
        $css = file_get_contents(resource_path('css/app.css'));

        $this->assertStringContainsString('--sidebar-w: 222px;', $css);
        $this->assertStringContainsString('--sidebar-w-collapsed: 76px;', $css);
        $this->assertMatchesRegularExpression('/\.cs-sidebar\s*\{\s*width:\s*var\(--sidebar-w\);\s*transition:\s*width 260ms/', $css);
        $this->assertMatchesRegularExpression('/prefers-reduced-motion: reduce\)\s*\{\s*\.cs-sidebar,/', $css);
    }

    public function test_the_toggle_is_a_direct_child_of_the_sidebar_which_does_not_clip_it_and_sits_above_the_content(): void
    {
        $html = $this->page($this->student(), route('student.dashboard'));

        preg_match('/<aside[^>]*>/s', $html, $aside);
        $this->assertDoesNotMatchRegularExpression('/overflow-(hidden|auto|scroll|clip)/', $aside[0], 'The button sits half over the edge, so the sidebar must not clip.');
        $this->assertStringContainsString('z-20', $aside[0], 'Above the content column.');
        $this->assertStringContainsString('x-effect="paint()"', $aside[0], 'The state is drawn by an effect that every start of the sidebar brings with it.');

        // The button is the aside's own first child (no wrapper that could clip it).
        $this->assertMatchesRegularExpression('/<aside[^>]*>\s*(?:\{\{--.*?--\}\}\s*)?<button type="button" class="cs-toggle interactive btn-motion"/s', $html);
        $this->assertStringContainsString('x-on:click="toggle()"', $html);
        $this->assertStringContainsString('aria-controls="sidebar-nav"', $html);

        $css = file_get_contents(resource_path('css/app.css'));
        preg_match('/\.cs-toggle\s*\{([^}]*)\}/', $css, $rule);
        $this->assertStringContainsString('position: absolute', $rule[1]);
        $this->assertStringContainsString('right: -12px', $rule[1]);
        $this->assertMatchesRegularExpression('/z-index:\s*30/', $rule[1]);
        $this->assertStringNotContainsString('pointer-events: none', $rule[1]);
    }

    public function test_the_state_attribute_the_aria_values_and_the_cookie_agree_on_the_server_render(): void
    {
        $student = $this->student();

        foreach ([['expanded', false], ['collapsed', true]] as [$cookie, $collapsed]) {
            $html = $this->page($student, route('student.dashboard'), [SidebarState::COLLAPSE_COOKIE => $cookie]);
            preg_match('/<aside[^>]*>/s', $html, $aside);

            $this->assertSame($collapsed, str_contains($aside[0], 'data-collapsed'), $cookie);
            $this->assertStringContainsString('aria-expanded="'.($collapsed ? 'false' : 'true').'" aria-label="'.($collapsed ? 'Expand' : 'Collapse').' sidebar"', $html);
            $this->assertMatchesRegularExpression('/cs-toggle__icon'.($collapsed ? '' : ' rotate-180').'"/', $html, 'The chevron points right in the rail, left when wide.');
        }
    }

    public function test_the_sidebar_keeps_one_state_across_wire_navigate_starts(): void
    {
        $js = file_get_contents(resource_path('js/sidebar.js'));

        // Livewire moves the kept sidebar into the next page and Alpine starts x-data on it again: the state must be one
        // shared object, listeners are added once, and nothing the toggle depends on is registered only on the first start.
        $this->assertStringContainsString('shared ??= window.Alpine.reactive(', $js);
        $this->assertStringContainsString('if (!this.ready)', $js);
        $this->assertStringNotContainsString('$watch(', $js, 'A watcher belongs to the first start and is lost on the second; the aside\'s x-effect replaces it.');
        $this->assertStringContainsString('import.meta.env?.DEV', $js, 'A failed start is reported in development only.');
    }

    public function test_every_class_the_sidebar_uses_is_styled_and_the_rail_rules_are_written_in_full(): void
    {
        $css = file_get_contents(resource_path('css/app.css'));
        $views = file_get_contents(resource_path('views/components/layouts/app.blade.php')).file_get_contents(resource_path('views/components/nav-item.blade.php'));

        preg_match_all('/\bcs-[a-z]+(?:-[a-z]+|__[a-z]+)*\b/', $views, $found);

        // Hooks for other rules, not styles of their own.
        $hooks = ['cs-role', 'cs-user', 'cs-navgroup'];

        foreach (array_unique($found[0]) as $class) {
            if (! in_array($class, $hooks, true)) {
                $this->assertStringContainsString('.'.$class, $css, "{$class} is used in the sidebar but has no rule in app.css.");
            }
        }

        foreach (['.cs-sidebar[data-collapsed] {', '.cs-sidebar[data-collapsed] .cs-label {', '.cs-sidebar[data-collapsed] .cs-navlink {', '.cs-sidebar[data-collapsed] .cs-subnav {'] as $rule) {
            $this->assertStringContainsString($rule, $css);
        }
    }

    public function test_the_compiled_stylesheet_has_the_collapsed_rail_rules(): void
    {
        $manifest = public_path('build/manifest.json');

        if (! is_file($manifest)) {
            $this->markTestSkipped('No build to check: run `npm run build` first.');
        }

        $file = json_decode(file_get_contents($manifest), true)['resources/css/app.css']['file'] ?? null;
        $built = $file ? file_get_contents(public_path('build/'.$file)) : '';

        foreach (['.cs-sidebar[data-collapsed]{width:var(--sidebar-w-collapsed)}', '.cs-toggle{', '.cs-subnav.is-open', '.cs-sidebar[data-collapsed] .cs-label{'] as $needle) {
            $this->assertStringContainsString($needle, $built, "{$needle} is missing from the compiled CSS: is a folder missing from the @source lines?");
        }
    }

    public function test_the_card_of_an_item_that_opens_is_fixed_to_the_window_and_shows_no_second_tooltip(): void
    {
        $this->group('Alpha');

        $nav = $this->nav($this->page($this->lecturer, route('lecturer.dashboard'), [SidebarState::COLLAPSE_COOKIE => 'collapsed']));

        // The item that opens has no tooltip of its own in the rail (its card's bold title names it)...
        foreach (['Marking', 'Contribution', 'Monitor progress'] as $label) {
            $this->assertMatchesRegularExpression('/<a(?=[^>]*data-rail-tip="'.preg_quote($label, '/').'")(?![^>]*data-tooltip=)[^>]*aria-label="'.preg_quote($label, '/').'"/', $nav, $label);
            $this->assertMatchesRegularExpression('/<a href="[^"]+" wire:navigate class="cs-fly-title"[^>]*>'.preg_quote($label, '/').'<\/a>/', $nav, 'The title is a link to the list page.');
        }

        // ...while a plain item still has its tooltip, to the right.
        $this->assertMatchesRegularExpression('/<a(?=[^>]*aria-label="Students")(?=[^>]*data-tooltip="Students")(?=[^>]*data-tooltip-side="right")/', $nav);

        // The card is out of the flow and fixed to the window, placed from the icon by the script.
        $css = file_get_contents(resource_path('css/app.css'));
        $this->assertMatchesRegularExpression('/\.cs-sidebar\[data-collapsed\] \.cs-subnav \{\s*position: fixed;\s*left: var\(--fly-left/', $css);
        $this->assertStringContainsString('max-width: 15rem', $css);
        $this->assertMatchesRegularExpression('/\.cs-subnav::before \{[^}]*left: -12px/', $css, 'A bridge over the gap.');

        $js = file_get_contents(resource_path('js/sidebar.js'));
        $this->assertStringContainsString('placeFlyout(rect(root), rect(link)', $js, 'Placed from the icon that opened it.');
        $this->assertStringContainsString("window.addEventListener('resize'", $js);
        $this->assertStringContainsString("addEventListener('scroll'", $js);
        $this->assertStringContainsString("'cs:hide-tooltip'", $js);
        $this->assertStringContainsString("'cs:hide-tooltip'", file_get_contents(resource_path('js/tooltip.js')));
        $this->assertStringNotContainsString('this.$el', $js, 'Methods run from inner elements, where $el is not the sidebar.');
        $this->assertStringContainsString("window.addEventListener('keydown'", $js, 'Enter is caught before wire:navigate follows the link.');
    }
}
