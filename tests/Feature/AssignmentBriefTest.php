<?php

namespace Tests\Feature;

use App\Models\Group;
use App\Models\GroupMember;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The assignment brief and the project deadline on a subject.
 *
 * Both are optional by design: a subject is normally created so that groups can
 * be set up, and the brief is written afterwards. So the tests here care as
 * much about a subject that has neither — which must stay silent rather than
 * showing an empty panel — as about one that has both.
 */
class AssignmentBriefTest extends TestCase
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
            'lecturer_id' => $this->lecturer->id,
            'code' => 'SECJ3104',
            'name' => 'App Dev',
            'group_size' => 4,
        ]);

        $this->group = Group::create([
            'subject_id' => $this->subject->id,
            'name' => 'Group A',
            'group_size' => 4,
        ]);

        $this->student = User::factory()->student()->create(['must_change_password' => false]);
        GroupMember::create([
            'group_id' => $this->group->id,
            'user_id' => $this->student->id,
            'joined_at' => now(),
        ]);
    }

    protected function setAssignment(?string $brief, ?string $deadline): void
    {
        $this->subject->forceFill([
            'brief' => $brief,
            'project_deadline' => $deadline,
        ])->save();
    }

    /*
    | The lecturer writing it
    */

    public function test_a_subject_can_be_created_with_a_brief_and_a_deadline(): void
    {
        $this->actingAs($this->lecturer)->post(route('lecturer.subjects.store'), [
            'code' => 'SECV2113',
            'name' => 'Web Programming',
            'brief' => "Build a working web system in groups of four.\nMarked on the report and the demo.",
            'project_deadline' => '2026-12-05',
            'group_size' => 4,
        ])->assertSessionHasNoErrors()->assertRedirect(route('lecturer.subjects'));

        $subject = Subject::where('code', 'SECV2113')->sole();

        $this->assertStringContainsString('groups of four', $subject->brief);
        $this->assertSame('2026-12-05', $subject->project_deadline->toDateString());
    }

    public function test_a_subject_can_be_created_without_either(): void
    {
        $this->actingAs($this->lecturer)->post(route('lecturer.subjects.store'), [
            'code' => 'SECV2113',
            'name' => 'Web Programming',
            'brief' => '',
            'project_deadline' => '',
            'group_size' => 4,
        ])->assertSessionHasNoErrors();

        $subject = Subject::where('code', 'SECV2113')->sole();

        // Empty strings would later read as "there is a brief, and it is blank".
        $this->assertNull($subject->brief);
        $this->assertNull($subject->project_deadline);
        $this->assertFalse($subject->hasAssignment());
    }

    public function test_the_brief_and_deadline_can_be_edited_later(): void
    {
        $this->actingAs($this->lecturer)->put(route('lecturer.subjects.update', $this->subject), [
            'code' => 'SECJ3104',
            'name' => 'App Dev',
            'brief' => 'Deliver chapters 1 to 5 and a working prototype.',
            'project_deadline' => '2026-11-20',
            'group_size' => 4,
        ])->assertSessionHasNoErrors();

        $this->subject->refresh();

        $this->assertSame('Deliver chapters 1 to 5 and a working prototype.', $this->subject->brief);
        $this->assertSame('2026-11-20', $this->subject->project_deadline->toDateString());
    }

    public function test_clearing_them_puts_them_back_to_nothing(): void
    {
        $this->setAssignment('Something written earlier', '2026-11-20');

        $this->actingAs($this->lecturer)->put(route('lecturer.subjects.update', $this->subject), [
            'code' => 'SECJ3104',
            'name' => 'App Dev',
            'brief' => '',
            'project_deadline' => '',
            'group_size' => 4,
        ])->assertSessionHasNoErrors();

        $this->subject->refresh();

        $this->assertNull($this->subject->brief);
        $this->assertNull($this->subject->project_deadline);
    }

    public function test_a_deadline_that_is_not_a_date_is_refused(): void
    {
        $this->actingAs($this->lecturer)->put(route('lecturer.subjects.update', $this->subject), [
            'code' => 'SECJ3104',
            'name' => 'App Dev',
            'project_deadline' => 'next semester sometime',
            'group_size' => 4,
        ])->assertSessionHasErrors('project_deadline');
    }

    public function test_the_form_carries_both_fields(): void
    {
        $this->setAssignment('The written brief', '2026-11-20');

        $this->actingAs($this->lecturer)
            ->get(route('lecturer.subjects.edit', $this->subject))
            ->assertOk()
            ->assertSee('Project brief')
            ->assertDontSee('Assignment brief')
            ->assertSee('Group project due date')
            ->assertSee('The written brief')
            ->assertSee('2026-11-20', false);
    }

    public function test_another_lecturer_cannot_write_the_brief(): void
    {
        $stranger = User::factory()->lecturer()->create(['must_change_password' => false]);

        $this->actingAs($stranger)->put(route('lecturer.subjects.update', $this->subject), [
            'code' => 'SECJ3104',
            'name' => 'Hijacked',
            'brief' => 'Do as I say instead',
            'group_size' => 4,
        ])->assertNotFound();

        $this->assertNull($this->subject->fresh()->brief);
    }

    /*
    | The student reading it
    */

    public function test_the_dashboard_shows_only_the_deadline_chip_not_the_brief(): void
    {
        $this->setAssignment('Build a working system.', now()->addDays(10)->toDateString());

        $this->actingAs($this->student)
            ->get(route('student.dashboard'))
            ->assertOk()
            ->assertDontSee('Build a working system.')
            ->assertDontSee('data-project-brief', false)
            ->assertSee('10 days left')
            ->assertSee('SECJ3104');
    }

    public function test_the_workspace_shows_the_brief_and_the_days_remaining(): void
    {
        $this->setAssignment('Build a working system.', now()->addDays(3)->toDateString());

        $this->actingAs($this->student)
            ->get(route('student.workspace.show', $this->group))
            ->assertOk()
            ->assertSee('Build a working system.')
            ->assertSee('3 days left');
    }

    public function test_the_countdown_reads_naturally_on_the_edges(): void
    {
        foreach ([
            [0, 'Due today'],
            [1, 'Due tomorrow'],
            [-1, 'Overdue by a day'],
            [-4, 'Overdue by 4 days'],
        ] as [$offset, $expected]) {
            $this->setAssignment('A brief', now()->addDays($offset)->toDateString());

            $this->actingAs($this->student)
                ->get(route('student.workspace.show', $this->group))
                ->assertOk()
                ->assertSee($expected);
        }
    }

    public function test_a_subject_with_no_assignment_shows_no_panel(): void
    {
        // Normal early in a semester, so silence rather than an empty box.
        $this->actingAs($this->student)
            ->get(route('student.dashboard'))
            ->assertOk()
            ->assertDontSee('Project brief')
            ->assertDontSee('days left');
    }

    public function test_a_deadline_with_no_brief_still_shows_the_countdown(): void
    {
        $this->setAssignment(null, now()->addDays(5)->toDateString());

        $this->actingAs($this->student)
            ->get(route('student.workspace.show', $this->group))
            ->assertOk()
            ->assertSee('5 days left')
            ->assertSee('not yet written the brief');
    }

    public function test_a_brief_with_no_deadline_shows_no_countdown(): void
    {
        $this->setAssignment('Only the brief, no date yet.', null);

        $this->actingAs($this->student)
            ->get(route('student.workspace.show', $this->group))
            ->assertOk()
            ->assertSee('Only the brief, no date yet.')
            ->assertDontSee('days left');
    }

    public function test_the_brief_is_escaped_not_rendered_as_markup(): void
    {
        // It is typed into a plain textarea, so anything that looks like markup
        // is text the lecturer typed, not markup they authored.
        $this->setAssignment('Use <script>alert(1)</script> nowhere', now()->addDay()->toDateString());

        $this->actingAs($this->student)
            ->get(route('student.workspace.show', $this->group))
            ->assertOk()
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertSee('alert(1)');
    }

    public function test_a_student_only_sees_the_briefs_of_their_own_subjects(): void
    {
        $this->setAssignment('My own subject brief', now()->addWeek()->toDateString());

        $otherLecturer = User::factory()->lecturer()->create();
        $otherSubject = Subject::create([
            'lecturer_id' => $otherLecturer->id,
            'code' => 'SECR1013',
            'name' => 'Networks',
            'brief' => 'Someone else\'s assignment',
            'project_deadline' => now()->addWeek()->toDateString(),
            'group_size' => 4,
        ]);
        Group::create(['subject_id' => $otherSubject->id, 'name' => 'Group X', 'group_size' => 4]);

        $this->actingAs($this->student)
            ->get(route('student.workspace.show', $this->group))
            ->assertOk()
            ->assertSee('My own subject brief')
            ->assertDontSee('Someone else');
    }

    public function test_the_dashboard_never_repeats_the_brief_even_with_several_groups(): void
    {
        // The brief lives on the group pages now; the dashboard shows none of it.
        $this->setAssignment('Written once', now()->addWeek()->toDateString());

        $second = Group::create([
            'subject_id' => $this->subject->id, 'name' => 'Group B', 'group_size' => 4,
        ]);
        GroupMember::create([
            'group_id' => $second->id, 'user_id' => $this->student->id, 'joined_at' => now(),
        ]);

        $response = $this->actingAs($this->student)->get(route('student.dashboard'))->assertOk();

        $this->assertSame(0, substr_count($response->getContent(), 'Written once'));
    }
}
