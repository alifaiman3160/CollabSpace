<?php

namespace Tests\Feature\Lecturer;

use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A lecturer reading the documents of a group they supervise.
 *
 * Read-only is the requirement, so most of what is covered here is what the
 * lecturer cannot do: no write route exists, the students' own routes stay shut
 * to them, and opening a document records no activity — which matters, because
 * the contribution report is built from exactly those rows and a lecturer
 * reading the work must not appear in the students' scores.
 */
class DocumentAccessTest extends TestCase
{
    use RefreshDatabase;

    protected User $lecturer;

    protected Group $group;

    protected User $student;

    protected Document $document;

    protected function setUp(): void
    {
        parent::setUp();

        $this->lecturer = User::factory()->lecturer()->create(['must_change_password' => false]);

        $subject = Subject::create([
            'lecturer_id' => $this->lecturer->id,
            'code' => 'SECJ3104',
            'name' => 'App Dev',
            'group_size' => 4,
        ]);

        $this->group = Group::create([
            'subject_id' => $subject->id,
            'name' => 'Group A',
            'group_size' => 4,
        ]);

        $this->student = User::factory()->student()->create(['must_change_password' => false]);
        GroupMember::create([
            'group_id' => $this->group->id,
            'user_id' => $this->student->id,
            'joined_at' => now(),
        ]);

        $this->document = $this->document('Chapter 1', '<p>The current body</p>');
    }

    protected function document(string $title, string $content): Document
    {
        $document = Document::create([
            'group_id' => $this->group->id,
            'created_by' => $this->student->id,
            'last_edited_by' => $this->student->id,
            'title' => $title,
            'content' => $content,
        ]);

        DocumentVersion::create([
            'document_id' => $document->id,
            'edited_by' => $this->student->id,
            'version_no' => 1,
            'content' => $content,
        ]);

        return $document;
    }

    /*
    | Reading
    */

    public function test_the_list_shows_the_groups_documents(): void
    {
        $this->actingAs($this->lecturer)
            ->get(route('lecturer.progress.documents', $this->group))
            ->assertOk()
            ->assertSee('Chapter 1')
            ->assertSee('Read-only', false)
            ->assertSee('Started by '.e($this->student->name), false);
    }

    public function test_a_group_with_no_documents_gets_an_empty_state(): void
    {
        $empty = Group::create([
            'subject_id' => $this->group->subject_id, 'name' => 'Group B', 'group_size' => 4,
        ]);

        $this->actingAs($this->lecturer)
            ->get(route('lecturer.progress.documents', $empty))
            ->assertOk()
            ->assertSee('No documents yet');
    }

    public function test_the_lecturer_can_read_the_current_body(): void
    {
        $this->actingAs($this->lecturer)
            ->get(route('lecturer.progress.documents.show', [$this->group, $this->document]))
            ->assertOk()
            ->assertSee('The current body')
            ->assertSee('Current content');
    }

    public function test_the_lecturer_can_read_a_past_version(): void
    {
        $version = DocumentVersion::create([
            'document_id' => $this->document->id,
            'edited_by' => $this->student->id,
            'version_no' => 2,
            'content' => '<p>What it said before</p>',
        ]);

        $this->actingAs($this->lecturer)
            ->get(route('lecturer.progress.documents.version', [$this->group, $this->document, $version]))
            ->assertOk()
            ->assertSee('What it said before')
            ->assertSee('Version 2 of 2');
    }

    public function test_the_monitoring_screen_links_to_the_documents(): void
    {
        $this->actingAs($this->lecturer)
            ->get(route('lecturer.progress.show', $this->group))
            ->assertOk()
            ->assertSee('Documents')
            ->assertSee(route('lecturer.progress.documents.show', [$this->group, $this->document]), false);
    }

    public function test_document_markup_is_sanitised_on_the_way_out(): void
    {
        $nasty = $this->document('Pasted from somewhere', '<p>Fine</p><script>alert(1)</script>');

        $this->actingAs($this->lecturer)
            ->get(route('lecturer.progress.documents.show', [$this->group, $nasty]))
            ->assertOk()
            ->assertSee('Fine')
            ->assertDontSee('alert(1)', false);
    }

    /*
    | Read-only, and scoped
    */

    public function test_reading_records_no_activity_against_the_students(): void
    {
        $this->actingAs($this->lecturer)->get(route('lecturer.progress.documents', $this->group));
        $this->actingAs($this->lecturer)->get(route('lecturer.progress.documents.show', [$this->group, $this->document]));

        $this->assertDatabaseCount('activity_logs', 0);
    }

    public function test_there_is_no_write_route_in_the_lecturer_document_area(): void
    {
        $verbs = collect(app('router')->getRoutes()->getRoutes())
            ->filter(fn ($route) => str_contains((string) $route->getName(), 'documents') && str_starts_with((string) $route->getName(), 'lecturer.'))
            ->flatMap(fn ($route) => $route->methods())
            ->unique()
            ->values();

        $this->assertEqualsCanonicalizing(['GET', 'HEAD'], $verbs->all());
    }

    public function test_a_lecturer_cannot_use_the_students_own_document_routes(): void
    {
        // The student area is role-gated, so the read-only screens above are
        // the only way in — a lecturer cannot reach the editor or its saves.
        $this->actingAs($this->lecturer)
            ->get(route('student.documents.edit', $this->document))
            ->assertForbidden();

        $this->actingAs($this->lecturer)
            ->put(route('student.documents.update', $this->document), [
                'title' => 'Rewritten by the lecturer',
                'content' => '<p>Not allowed</p>',
            ])
            ->assertForbidden();

        $this->assertSame('Chapter 1', $this->document->fresh()->title);
    }

    public function test_another_lecturers_document_is_a_404(): void
    {
        $stranger = User::factory()->lecturer()->create(['must_change_password' => false]);

        $this->actingAs($stranger)
            ->get(route('lecturer.progress.documents.show', [$this->group, $this->document]))
            ->assertNotFound();

        $this->actingAs($stranger)
            ->get(route('lecturer.progress.documents', $this->group))
            ->assertNotFound();
    }

    public function test_a_version_from_a_different_document_is_a_404(): void
    {
        $other = $this->document('Chapter 2', '<p>Separate</p>');
        $itsVersion = $other->versions()->sole();

        $this->actingAs($this->lecturer)
            ->get(route('lecturer.progress.documents.version', [$this->group, $this->document, $itsVersion]))
            ->assertNotFound();
    }

    public function test_students_cannot_open_the_lecturer_screens(): void
    {
        $this->actingAs($this->student)
            ->get(route('lecturer.progress.documents.show', [$this->group, $this->document]))
            ->assertForbidden();
    }
}
