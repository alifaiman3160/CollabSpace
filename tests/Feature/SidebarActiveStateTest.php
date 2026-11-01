<?php

namespace Tests\Feature;

use App\Models\Group;
use App\Models\GroupMember;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The sidebar marks where you are. It used to compare the full URL, query string
 * included, so any screen reached with a filter or a search (Groups for one
 * subject, Users filtered by role) lost its highlight.
 */
class SidebarActiveStateTest extends TestCase
{
    use RefreshDatabase;

    /** The label of the one sidebar entry marked current, or null. */
    protected function current(string $html): ?string
    {
        preg_match('/<nav[^>]*aria-label="Main".*?<\/nav>/s', $html, $nav);
        $this->assertNotEmpty($nav, 'No sidebar rendered.');

        preg_match_all('/<a(?=[^>]*data-nav-link)(?=[^>]*(?:\saria-current="page"|\sdata-parent-current[\s>]))[^>]*>(.*?)<\/a>/s', $nav[0], $m);
        $this->assertLessThanOrEqual(1, count($m[1]), 'More than one sidebar entry is marked current.');

        return isset($m[1][0]) ? trim(preg_replace('/\s+/', ' ', strip_tags($m[1][0]))) : null;
    }

    protected function assertCurrent(User $user, string $url, string $label): void
    {
        $html = $this->actingAs($user)->get($url)->assertOk()->getContent();

        $this->assertStringStartsWith($label, (string) $this->current($html), "{$url} should highlight {$label}");
    }

    public function test_lecturer_screens_keep_their_highlight_with_filters_and_on_sub_pages(): void
    {
        $lecturer = User::factory()->lecturer()->create(['must_change_password' => false]);
        $one = Subject::create(['lecturer_id' => $lecturer->id, 'code' => 'BCS3323', 'name' => 'Software Engineering', 'group_size' => 4]);
        $two = Subject::create(['lecturer_id' => $lecturer->id, 'code' => 'SECJ3104', 'name' => 'Application Development', 'group_size' => 4]);
        $group = Group::create(['subject_id' => $one->id, 'name' => 'Group A', 'group_size' => 4]);
        Group::create(['subject_id' => $two->id, 'name' => 'Group B', 'group_size' => 4]);

        $this->assertCurrent($lecturer, route('lecturer.groups'), 'Groups');
        $this->assertCurrent($lecturer, route('lecturer.groups', ['subject' => $one->id]), 'Groups');
        $this->assertCurrent($lecturer, route('lecturer.groups', ['subject' => $two->id]), 'Groups');
        $this->assertCurrent($lecturer, route('lecturer.groups.show', $group), 'Groups');
        $this->assertCurrent($lecturer, route('lecturer.groups.create', ['subject' => $one->id]), 'Groups');
        $this->assertCurrent($lecturer, route('lecturer.students', ['search' => 'a']), 'Students');
        $this->assertCurrent($lecturer, route('lecturer.subjects'), 'My subjects');
        $this->assertCurrent($lecturer, route('lecturer.formation', $one), 'My subjects');
        $this->assertCurrent($lecturer, route('lecturer.reports.show', $group), 'Contribution');
        $this->assertCurrent($lecturer, route('lecturer.progress.show', $group), 'Monitor progress');
    }

    public function test_admin_screens_keep_their_highlight_with_filters(): void
    {
        $admin = User::factory()->admin()->create(['must_change_password' => false]);

        $this->assertCurrent($admin, route('admin.users'), 'User accounts');
        $this->assertCurrent($admin, route('admin.users', ['role' => 'student', 'search' => 'x', 'page' => 1]), 'User accounts');
    }

    public function test_student_screens_keep_their_highlight_on_group_pages(): void
    {
        $lecturer = User::factory()->lecturer()->create(['must_change_password' => false]);
        $subject = Subject::create(['lecturer_id' => $lecturer->id, 'code' => 'BCS3323', 'name' => 'Software Engineering', 'group_size' => 4]);
        $group = Group::create(['subject_id' => $subject->id, 'name' => 'Group A', 'group_size' => 4]);
        $student = User::factory()->student()->create(['must_change_password' => false]);
        GroupMember::create(['group_id' => $group->id, 'user_id' => $student->id, 'joined_at' => now()]);

        $this->assertCurrent($student, route('student.board.show', $group), 'Task board');
        $this->assertCurrent($student, route('student.documents.group', $group), 'Documents');
        $this->assertCurrent($student, route('student.chat.show', $group), 'Group chat');
    }
}
