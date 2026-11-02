<?php

namespace Tests\Feature\Lecturer;

use App\Models\Section;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** A lecturer fills in the details of a subject given to them; the code, name, lecturer and sections stay the administrator's. */
class SubjectDetailsTest extends TestCase
{
    use RefreshDatabase;

    protected User $lecturer;

    protected Subject $subject;

    protected function setUp(): void
    {
        parent::setUp();

        $this->lecturer = User::factory()->lecturer()->create(['must_change_password' => false]);
        $this->subject = Subject::create(['lecturer_id' => $this->lecturer->id, 'code' => 'SECJ3104', 'name' => 'Application Development', 'group_size' => 4]);
        Section::create(['subject_id' => $this->subject->id, 'name' => '1A']);
        Section::create(['subject_id' => $this->subject->id, 'name' => '1B']);
    }

    public function test_the_edit_page_shows_the_code_name_and_sections_as_text_with_a_hint(): void
    {
        $html = $this->actingAs($this->lecturer)->get(route('lecturer.subjects.edit', $this->subject))->assertOk()->getContent();

        $this->assertStringContainsString('data-subject-readonly', $html);
        $this->assertStringContainsString('Managed by the administrator.', $html);
        $this->assertStringContainsString('Application Development', $html);
        $this->assertStringContainsString('Section 1A', $html);
        $this->assertStringContainsString('Section 1B', $html);

        $this->assertStringNotContainsString('name="code"', $html);
        $this->assertStringNotContainsString('name="name"', $html);
        $this->assertStringNotContainsString('name="sections', $html);

        // What a lecturer owns is still a form field.
        foreach (['name="brief"', 'name="project_deadline"', 'name="group_size"'] as $field) {
            $this->assertStringContainsString($field, $html);
        }
    }

    public function test_the_brief_deadline_and_group_size_can_be_saved(): void
    {
        $this->actingAs($this->lecturer)->put(route('lecturer.subjects.update', $this->subject), [
            'brief' => 'Build it.', 'project_deadline' => '2026-12-20', 'group_size' => 5,
        ])->assertSessionHasNoErrors()->assertRedirect(route('lecturer.subjects'));

        $fresh = $this->subject->fresh();
        $this->assertSame('Build it.', $fresh->brief);
        $this->assertSame('2026-12-20', $fresh->project_deadline->toDateString());
        $this->assertSame(5, $fresh->group_size);
    }

    public function test_a_crafted_request_cannot_change_the_code_name_lecturer_or_sections(): void
    {
        $other = User::factory()->lecturer()->create();
        $a = $this->subject->sections()->where('name', '1A')->first();

        $this->actingAs($this->lecturer)->put(route('lecturer.subjects.update', $this->subject), [
            'code' => 'HACKED', 'name' => 'Hacked', 'lecturer_id' => $other->id,
            'sections' => [['id' => $a->id, 'name' => 'Renamed']],
            'brief' => 'Only this should change.', 'group_size' => 4,
        ])->assertSessionHasNoErrors();

        $fresh = $this->subject->fresh();
        $this->assertSame('SECJ3104', $fresh->code);
        $this->assertSame('Application Development', $fresh->name);
        $this->assertSame($this->lecturer->id, $fresh->lecturer_id);
        $this->assertSame('1A', $a->fresh()->name);
        $this->assertSame(2, $fresh->sections()->count());
        $this->assertSame('Only this should change.', $fresh->brief);
    }

    public function test_another_lecturers_subject_and_other_roles_stay_out(): void
    {
        $stranger = User::factory()->lecturer()->create(['must_change_password' => false]);
        $student = User::factory()->student()->create(['must_change_password' => false]);

        $this->actingAs($stranger)->get(route('lecturer.subjects.edit', $this->subject))->assertNotFound();
        $this->actingAs($stranger)->put(route('lecturer.subjects.update', $this->subject), ['brief' => 'x', 'group_size' => 4])->assertNotFound();
        $this->actingAs($student)->put(route('lecturer.subjects.update', $this->subject), ['brief' => 'x', 'group_size' => 4])->assertForbidden();

        $this->assertNull($this->subject->fresh()->brief);
    }

    public function test_nothing_on_the_lecturers_pages_offers_to_create_a_subject(): void
    {
        foreach (['lecturer.dashboard', 'lecturer.subjects', 'lecturer.groups', 'lecturer.marking', 'lecturer.students'] as $route) {
            $html = $this->actingAs($this->lecturer)->get(route($route))->assertOk()->getContent();

            $this->assertStringNotContainsString('New subject', $html, $route);
            $this->assertStringNotContainsString('Create a subject', $html, $route);
        }

        $nobody = User::factory()->lecturer()->create(['must_change_password' => false]);
        $this->actingAs($nobody)->get(route('lecturer.subjects'))->assertOk()
            ->assertSee('No subjects assigned yet. The administrator assigns subjects to lecturers.');
        $this->actingAs($nobody)->get(route('lecturer.dashboard'))->assertOk()->assertSee('No subjects assigned yet');
    }
}
