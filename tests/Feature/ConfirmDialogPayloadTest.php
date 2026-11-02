<?php

namespace Tests\Feature;

use App\Models\Group;
use App\Models\GroupMember;
use App\Models\Subject;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Blade compiles {{ }} echoes inside a component attribute, but not @
 * directives. Writing `@js($var)` in an x-on:click on an <x-button> therefore
 * renders the literal text "@js($var)" into the Alpine object, which is a
 * syntax error — the dialog silently never opens.
 *
 * These tests assert the rendered payload is real JSON, so the mistake cannot
 * come back unnoticed.
 */
class ConfirmDialogPayloadTest extends TestCase
{
    use RefreshDatabase;

    protected function assertPayloadIsCompiled(TestResponse $response): void
    {
        $response->assertOk();

        $html = $response->getContent();

        $this->assertStringNotContainsString('@js(', $html,
            'An @js() directive reached the browser uncompiled — use {{ Js::from(...) }} inside component attributes.');

        $this->assertStringNotContainsString('{{ Js::from', $html,
            'A Js::from() echo reached the browser uncompiled.');

        // Js::from renders a string as a quoted JS literal. The dialog only
        // works if body: carries one.
        $this->assertMatchesRegularExpression("/body:\s*'/", $html,
            'The confirm payload has no compiled body string.');
    }

    public function test_the_admin_user_list_payload_is_compiled(): void
    {
        $admin = User::factory()->admin()->create(['must_change_password' => false]);
        User::factory()->student()->create();

        $this->assertPayloadIsCompiled($this->actingAs($admin)->get(route('admin.users')));
    }

    public function test_the_admin_user_edit_payload_is_compiled(): void
    {
        $admin = User::factory()->admin()->create(['must_change_password' => false]);
        $user = User::factory()->student()->create();

        $this->assertPayloadIsCompiled($this->actingAs($admin)->get(route('admin.users.edit', $user)));
    }

    public function test_the_admin_subject_list_payload_is_compiled(): void
    {
        $this->cohort();
        $admin = User::factory()->admin()->create(['must_change_password' => false]);

        $this->assertPayloadIsCompiled($this->actingAs($admin)->get(route('admin.subjects')));
    }

    public function test_the_lecturer_group_list_payload_is_compiled(): void
    {
        [$lecturer, $subject, $group] = $this->cohort();

        $this->assertPayloadIsCompiled($this->actingAs($lecturer)->get(route('lecturer.groups')));
    }

    public function test_the_lecturer_group_detail_payload_is_compiled(): void
    {
        [$lecturer, $subject, $group] = $this->cohort();

        $this->assertPayloadIsCompiled($this->actingAs($lecturer)->get(route('lecturer.groups.show', $group)));
    }

    public function test_the_group_delete_payload_lists_the_cascade(): void
    {
        [$lecturer, $subject, $group] = $this->cohort();

        $html = $this->actingAs($lecturer)->get(route('lecturer.groups'))->getContent();

        // Js::from renders an array as JSON.parse('[...]').
        $this->assertMatchesRegularExpression("/consequences:\s*JSON\.parse\('\[/", $html,
            'The cascade list did not render as a parseable JSON array.');

        $this->assertStringContainsString('every contribution record for this group', $html);
        $this->assertStringContainsString('1 task', $html);
        $this->assertStringContainsString('the students keep their accounts', $html);
    }

    /** @return array{0: User, 1: Subject, 2: Group} */
    protected function cohort(): array
    {
        $lecturer = User::factory()->lecturer()->create(['must_change_password' => false]);

        $subject = Subject::create([
            'lecturer_id' => $lecturer->id, 'code' => 'SECJ3104', 'name' => "Alif's Module", 'group_size' => 4,
        ]);

        $group = Group::create(['subject_id' => $subject->id, 'name' => 'Group A', 'group_size' => 4]);

        $student = User::factory()->student()->create();
        GroupMember::create(['group_id' => $group->id, 'user_id' => $student->id, 'joined_at' => now()]);

        Task::create([
            'group_id' => $group->id, 'created_by' => $lecturer->id,
            'title' => 'A task', 'status' => Task::STATUS_TODO,
        ]);

        return [$lecturer, $subject, $group];
    }
}
