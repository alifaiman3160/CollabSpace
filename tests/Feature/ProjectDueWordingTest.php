<?php

namespace Tests\Feature;

use App\Livewire\TaskBoard;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\Subject;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The subject's project date says what it is: a group project due date that shows a countdown and sends a reminder,
 * and locks nothing. The same words everywhere, and a soft hint on a task dated after it.
 */
class ProjectDueWordingTest extends TestCase
{
    use RefreshDatabase;

    protected User $lecturer;

    protected User $student;

    protected Subject $subject;

    protected Group $group;

    protected function setUp(): void
    {
        parent::setUp();

        $this->lecturer = User::factory()->lecturer()->create(['must_change_password' => false]);
        $this->subject = Subject::create([
            'lecturer_id' => $this->lecturer->id, 'code' => 'SECJ3104', 'name' => 'App Dev', 'group_size' => 4,
            'project_deadline' => '2026-12-12',
        ]);
        $this->group = Group::create(['subject_id' => $this->subject->id, 'name' => 'G1', 'group_size' => 4]);
        $this->student = User::factory()->student()->create(['must_change_password' => false]);
        GroupMember::create(['group_id' => $this->group->id, 'user_id' => $this->student->id, 'joined_at' => now()]);
    }

    public function test_the_forms_label_and_explain_the_field(): void
    {
        // Only a subject with no assessment (it should not happen) keeps the subject-level field.
        \App\Models\Assessment::query()->where('subject_id', $this->subject->id)->delete();

        foreach ([route('lecturer.subjects.edit', $this->subject)] as $url) {
            $html = $this->actingAs($this->lecturer)->get($url)->assertOk()->getContent();

            $this->assertStringContainsString('Group project due date', $html);
            $this->assertStringNotContainsString('value="Project deadline"', $html);
            $this->assertStringContainsString('Students see a countdown on their dashboard and get a reminder', $html);
            $this->assertStringContainsString((int) config('notifications.project_reminder_days').' days before', preg_replace('/\s+/', ' ', $html));
            $this->assertStringContainsString('It does not lock tasks or documents.', $html);
            $this->assertStringContainsString('Optional.', $html);
        }
    }

    public function test_the_field_stays_optional(): void
    {
        $this->actingAs($this->lecturer)->put(route('lecturer.subjects.update', $this->subject), [
            'project_deadline' => '', 'group_size' => 4,
        ])->assertRedirect(route('lecturer.subjects'));

        $this->assertNull($this->subject->fresh()->project_deadline);
    }

    public function test_the_lecturers_card_and_the_students_brief_use_the_same_words(): void
    {
        $this->actingAs($this->lecturer)->get(route('lecturer.subjects'))->assertOk()
            ->assertSee('Group project due')
            ->assertDontSee('Project deadline');

        $this->actingAs($this->student)->get(route('student.dashboard'))->assertOk()
            ->assertSee('Due 12 Dec');
        $this->actingAs($this->student)->get(route('student.workspace.show', $this->group))->assertOk()
            ->assertSee('Group project due 12 December 2026');
    }

    public function test_the_notification_already_says_the_group_project(): void
    {
        $notifier = file_get_contents(app_path('Support/Notifier.php'));

        $this->assertStringContainsString('the group project {$when}', $notifier);
    }

    public function test_the_task_form_carries_the_soft_hint_only_when_the_subject_has_a_date(): void
    {
        $form = fn () => Livewire::actingAs($this->student)->test(TaskBoard::class, ['group' => $this->group])->call('toggleForm');

        $form()->assertSee('data-task-after-project', false)
            ->assertSee('This is after the group project due date, 12 Dec 2026.')
            // The hint shows only for a date after it, compared in the page: nothing is saved or checked by it.
            ->assertSee('picked > limit', false)
            ->assertSee('2026-12-12', false);

        $this->subject->update(['project_deadline' => null]);
        $this->group->refresh()->load('subject');

        $form()->assertDontSee('data-task-after-project', false);
    }
    public function test_a_task_after_the_project_date_still_saves(): void
    {
        Livewire::actingAs($this->student)
            ->test(TaskBoard::class, ['group' => $this->group])
            ->set('title', 'Polish the report')
            ->set('deadline', '2027-02-01')
            ->call('createTask')
            ->assertHasNoErrors();

        $task = Task::where('title', 'Polish the report')->sole();
        $this->assertSame('2027-02-01', $task->deadline->format('Y-m-d'));
    }
    public function test_edit_has_the_same_back_link_as_the_other_pages(): void
    {
        // Only a subject with no assessment (it should not happen) keeps the subject-level field.
        \App\Models\Assessment::query()->where('subject_id', $this->subject->id)->delete();

        foreach ([route('lecturer.subjects.edit', $this->subject)] as $url) {
            $html = $this->actingAs($this->lecturer)->get($url)->assertOk()->getContent();

            $this->assertMatchesRegularExpression('/<a href="'.preg_quote(route('lecturer.subjects'), '/').'"[^>]*>\s*<svg.*?<\/svg>\s*My subjects\s*<\/a>/s', $html);
        }
    }
}
