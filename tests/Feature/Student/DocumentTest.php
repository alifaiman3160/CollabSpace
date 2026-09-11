<?php

namespace Tests\Feature\Student;

use App\Models\ActivityLog;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DocumentTest extends TestCase
{
    use RefreshDatabase;

    protected User $me;

    protected Group $group;

    protected Group $otherGroup;

    protected function setUp(): void
    {
        parent::setUp();

        $lecturer = User::factory()->lecturer()->create();
        $subject = Subject::create([
            'lecturer_id' => $lecturer->id, 'code' => 'SECJ3104', 'name' => 'App Dev', 'group_size' => 4,
        ]);

        $this->group = Group::create(['subject_id' => $subject->id, 'name' => 'My Group', 'group_size' => 4]);
        $this->otherGroup = Group::create(['subject_id' => $subject->id, 'name' => 'Other Group', 'group_size' => 4]);

        $this->me = User::factory()->student()->create(['must_change_password' => false]);
        GroupMember::create(['group_id' => $this->group->id, 'user_id' => $this->me->id, 'joined_at' => now()]);
    }

    protected function document(string $content = '<p>Original</p>'): Document
    {
        $document = Document::create([
            'group_id' => $this->group->id,
            'created_by' => $this->me->id,
            'last_edited_by' => $this->me->id,
            'title' => 'Chapter 1',
            'content' => $content,
        ]);

        DocumentVersion::create([
            'document_id' => $document->id, 'edited_by' => $this->me->id,
            'version_no' => 1, 'content' => $content,
        ]);

        return $document;
    }

    /*
    | Deleting — use case 3.4.3.5, alternative flow A2
    */

    public function test_deleting_a_document_removes_it_and_its_whole_history(): void
    {
        $document = $this->document();

        // Give it a second version, so there is a history to lose.
        $this->actingAs($this->me)->put(route('student.documents.update', $document), [
            'title' => 'Chapter 1',
            'content' => '<p>Rewritten</p>',
        ]);

        $this->assertSame(2, DocumentVersion::where('document_id', $document->id)->count());

        $this->actingAs($this->me)
            ->delete(route('student.documents.destroy', $document))
            ->assertRedirect(route('student.documents.group', $this->group))
            ->assertSessionHas('status');

        $this->assertDatabaseMissing('documents', ['id' => $document->id]);
        $this->assertDatabaseMissing('document_versions', ['document_id' => $document->id]);
    }

    public function test_deleting_a_document_removes_its_contribution_rows(): void
    {
        // Create and edit through the application, so the activity rows are the
        // ones the real flow writes.
        $this->actingAs($this->me)->post(route('student.documents.store', $this->group), [
            'title' => 'Chapter 2',
        ]);

        $document = Document::where('title', 'Chapter 2')->sole();

        $this->actingAs($this->me)->put(route('student.documents.update', $document), [
            'title' => 'Chapter 2',
            'content' => '<p>Some real writing</p>',
        ]);

        $this->assertSame(2, ActivityLog::where('reference_id', $document->id)
            ->whereIn('activity_type', ['document_created', 'document_edited'])
            ->count());

        $scoreBefore = $this->me->contributionScoreFor($this->group);
        $this->assertGreaterThan(0, $scoreBefore);

        $this->actingAs($this->me)->delete(route('student.documents.destroy', $document));

        $this->assertSame(0, ActivityLog::where('reference_id', $document->id)
            ->whereIn('activity_type', ['document_created', 'document_edited'])
            ->count());

        $this->assertLessThan($scoreBefore, $this->me->contributionScoreFor($this->group));
    }

    public function test_deleting_one_document_leaves_the_others_and_their_scores_alone(): void
    {
        $keep = $this->document('<p>Keep this</p>');
        $doomed = $this->document('<p>Delete this</p>');

        // An activity row against each.
        app(\App\Support\ActivityRecorder::class)->documentCreated($keep, $this->me->id);
        app(\App\Support\ActivityRecorder::class)->documentCreated($doomed, $this->me->id);

        $this->actingAs($this->me)->delete(route('student.documents.destroy', $doomed));

        $this->assertDatabaseHas('documents', ['id' => $keep->id]);
        $this->assertSame(1, ActivityLog::where('reference_id', $keep->id)->count());
        $this->assertSame(0, ActivityLog::where('reference_id', $doomed->id)->count());
    }

    public function test_task_activity_with_the_same_reference_id_is_not_swept_up(): void
    {
        $document = $this->document();

        // A task whose id happens to match the document's — reference_id is a
        // plain column shared across tables, so the type has to be part of the
        // match or deleting one would silently score away the other.
        ActivityLog::create([
            'user_id' => $this->me->id,
            'group_id' => $this->group->id,
            'activity_type' => 'task_completed',
            'reference_id' => $document->id,
            'weight' => 5,
        ]);

        $this->actingAs($this->me)->delete(route('student.documents.destroy', $document));

        $this->assertSame(1, ActivityLog::where('activity_type', 'task_completed')
            ->where('reference_id', $document->id)
            ->count());
    }

    public function test_a_document_in_another_group_cannot_be_deleted(): void
    {
        $stranger = Document::create([
            'group_id' => $this->otherGroup->id,
            'created_by' => $this->me->id,
            'last_edited_by' => $this->me->id,
            'title' => 'Not mine',
            'content' => '<p>Theirs</p>',
        ]);

        $this->actingAs($this->me)
            ->delete(route('student.documents.destroy', $stranger))
            ->assertNotFound();

        $this->assertDatabaseHas('documents', ['id' => $stranger->id]);
    }

    public function test_a_lecturer_cannot_delete_a_group_document(): void
    {
        $document = $this->document();

        $lecturer = User::factory()->lecturer()->create(['must_change_password' => false]);

        $this->actingAs($lecturer)
            ->delete(route('student.documents.destroy', $document))
            ->assertForbidden();

        $this->assertDatabaseHas('documents', ['id' => $document->id]);
    }

    public function test_any_member_of_the_group_may_delete_it_not_only_the_author(): void
    {
        $document = $this->document();

        $mate = User::factory()->student()->create(['must_change_password' => false]);
        GroupMember::create(['group_id' => $this->group->id, 'user_id' => $mate->id, 'joined_at' => now()]);

        $this->actingAs($mate)
            ->delete(route('student.documents.destroy', $document))
            ->assertRedirect(route('student.documents.group', $this->group));

        $this->assertDatabaseMissing('documents', ['id' => $document->id]);
    }

    public function test_the_list_and_the_editor_both_offer_delete(): void
    {
        $document = $this->document();

        $this->actingAs($this->me)
            ->get(route('student.documents.group', $this->group))
            ->assertOk()
            ->assertSee(route('student.documents.destroy', $document), false)
            ->assertSee('Delete document', false);

        $this->actingAs($this->me)
            ->get(route('student.documents.edit', $document))
            ->assertOk()
            ->assertSee(route('student.documents.destroy', $document), false);
    }

    /*
    | Listing and creating
    */

    public function test_the_list_shows_the_groups_documents(): void
    {
        $this->document();

        $this->actingAs($this->me)
            ->get(route('student.documents.group', $this->group))
            ->assertOk()
            ->assertSee('Chapter 1')
            ->assertSee('Last edited by');
    }

    public function test_an_empty_group_gets_an_empty_state(): void
    {
        $this->actingAs($this->me)
            ->get(route('student.documents.group', $this->group))
            ->assertOk()
            ->assertSee('No documents yet');
    }

    public function test_creating_takes_a_title_only_and_opens_the_editor(): void
    {
        $response = $this->actingAs($this->me)
            ->post(route('student.documents.store', $this->group), ['title' => 'Meeting notes']);

        $document = Document::where('title', 'Meeting notes')->sole();

        $response->assertRedirect(route('student.documents.edit', $document));

        $this->assertSame('', $document->content);
        $this->assertSame($this->me->id, $document->created_by);
    }

    public function test_creating_stores_version_one_and_logs_document_created(): void
    {
        $this->actingAs($this->me)
            ->post(route('student.documents.store', $this->group), ['title' => 'Meeting notes']);

        $document = Document::where('title', 'Meeting notes')->sole();

        $version = DocumentVersion::where('document_id', $document->id)->sole();
        $this->assertSame(1, $version->version_no);

        $log = ActivityLog::where('activity_type', 'document_created')
            ->where('reference_id', $document->id)->sole();

        $this->assertSame($this->me->id, $log->user_id);
        $this->assertSame(config('contribution.weights.document_created'), $log->weight);
    }

    public function test_a_title_is_required(): void
    {
        $this->actingAs($this->me)
            ->post(route('student.documents.store', $this->group), ['title' => ''])
            ->assertSessionHasErrors('title');
    }

    /*
    | Saving
    */

    public function test_saving_new_content_writes_the_next_version_and_logs_the_edit(): void
    {
        $document = $this->document();

        $this->actingAs($this->me)->put(route('student.documents.update', $document), [
            'title' => 'Chapter 1',
            'content' => '<p>Rewritten body</p>',
        ]);

        $versions = DocumentVersion::where('document_id', $document->id)->orderBy('version_no')->get();

        $this->assertCount(2, $versions);
        $this->assertSame(2, $versions->last()->version_no);
        $this->assertSame('<p>Rewritten body</p>', $versions->last()->content);
        $this->assertSame('<p>Rewritten body</p>', $document->fresh()->content);

        $log = ActivityLog::where('activity_type', 'document_edited')
            ->where('reference_id', $document->id)->sole();

        $this->assertSame(config('contribution.weights.document_edited'), $log->weight);
    }

    /**
     * The rule that stops a student inflating their contribution score by
     * opening a document and pressing save.
     */
    public function test_saving_unchanged_content_writes_no_version_and_no_log(): void
    {
        $document = $this->document('<p>Original</p>');

        $this->actingAs($this->me)->put(route('student.documents.update', $document), [
            'title' => 'Chapter 1',
            'content' => '<p>Original</p>',
        ]);

        $this->assertSame(1, DocumentVersion::where('document_id', $document->id)->count());
        $this->assertSame(0, ActivityLog::where('activity_type', 'document_edited')->count());
    }

    public function test_whitespace_only_differences_do_not_create_a_version(): void
    {
        $document = $this->document('<p>Original</p>');

        $this->actingAs($this->me)->put(route('student.documents.update', $document), [
            'title' => 'Chapter 1',
            'content' => "<p>Original</p>\n\n  ",
        ]);

        $this->assertSame(1, DocumentVersion::where('document_id', $document->id)->count());
        $this->assertSame(0, ActivityLog::where('activity_type', 'document_edited')->count());
    }

    public function test_a_title_only_change_renames_without_creating_a_version(): void
    {
        $document = $this->document('<p>Original</p>');

        $this->actingAs($this->me)->put(route('student.documents.update', $document), [
            'title' => 'Chapter One',
            'content' => '<p>Original</p>',
        ]);

        $this->assertSame('Chapter One', $document->fresh()->title);
        $this->assertSame(1, DocumentVersion::where('document_id', $document->id)->count());
        $this->assertSame(0, ActivityLog::where('activity_type', 'document_edited')->count());
    }

    /*
    | Sanitising — content is rendered back to the whole group
    */

    public function test_a_script_tag_never_reaches_storage(): void
    {
        $document = $this->document();

        $this->actingAs($this->me)->put(route('student.documents.update', $document), [
            'title' => 'Chapter 1',
            'content' => '<p>Hello</p><script>alert(document.cookie)</script>',
        ]);

        $stored = $document->fresh()->content;

        $this->assertStringNotContainsString('<script', $stored);
        $this->assertStringNotContainsString('alert(', $stored);
        $this->assertStringContainsString('Hello', $stored);
    }

    public function test_event_handlers_and_javascript_urls_are_stripped(): void
    {
        $document = $this->document();

        $this->actingAs($this->me)->put(route('student.documents.update', $document), [
            'title' => 'Chapter 1',
            'content' => '<p onclick="alert(1)">Click</p><a href="javascript:alert(1)">bad</a><img src=x onerror=alert(1)>',
        ]);

        $stored = $document->fresh()->content;

        $this->assertStringNotContainsString('onclick', $stored);
        $this->assertStringNotContainsString('onerror', $stored);
        $this->assertStringNotContainsString('javascript:', $stored);
        $this->assertStringNotContainsString('<img', $stored);
    }

    public function test_the_stored_payload_is_not_served_back_to_another_member(): void
    {
        $mate = User::factory()->student()->create(['must_change_password' => false]);
        GroupMember::create(['group_id' => $this->group->id, 'user_id' => $mate->id, 'joined_at' => now()]);

        $document = $this->document();

        $this->actingAs($this->me)->put(route('student.documents.update', $document), [
            'title' => 'Chapter 1',
            'content' => '<p>Safe</p><script>alert(1)</script>',
        ]);

        // The other member is the actual victim of a stored XSS, so assert on
        // what their browser receives.
        $html = $this->actingAs($mate)
            ->get(route('student.documents.edit', $document))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
    }

    public function test_allowed_formatting_survives(): void
    {
        $document = $this->document();

        $this->actingAs($this->me)->put(route('student.documents.update', $document), [
            'title' => 'Chapter 1',
            'content' => '<h2>Heading</h2><p><strong>Bold</strong> <em>italic</em></p><ul><li>one</li></ul>',
        ]);

        $stored = $document->fresh()->content;

        foreach (['<h2>', '<strong>', '<em>', '<ul>', '<li>'] as $tag) {
            $this->assertStringContainsString($tag, $stored);
        }
    }

    /*
    | Versions
    */

    public function test_a_past_version_is_viewable_and_read_only(): void
    {
        $document = $this->document('<p>First</p>');

        $this->actingAs($this->me)->put(route('student.documents.update', $document), [
            'title' => 'Chapter 1', 'content' => '<p>Second</p>',
        ]);

        $first = DocumentVersion::where('document_id', $document->id)->where('version_no', 1)->sole();

        $html = $this->actingAs($this->me)
            ->get(route('student.documents.version', [$document, $first]))
            ->assertOk()
            ->assertSee('Read only')
            ->assertSee('First')
            ->getContent();

        // No editable surface on the read-only screen.
        $this->assertStringNotContainsString('contenteditable', $html);
    }

    public function test_restoring_keeps_the_current_body_and_applies_the_old_one(): void
    {
        $document = $this->document('<p>First</p>');

        $this->actingAs($this->me)->put(route('student.documents.update', $document), [
            'title' => 'Chapter 1', 'content' => '<p>Second</p>',
        ]);

        $first = DocumentVersion::where('document_id', $document->id)->where('version_no', 1)->sole();

        $this->actingAs($this->me)
            ->post(route('student.documents.restore', [$document, $first]))
            ->assertRedirect(route('student.documents.edit', $document));

        $document->refresh();

        // The body is back to version 1's content...
        $this->assertSame('<p>First</p>', $document->content);

        $versions = DocumentVersion::where('document_id', $document->id)->orderBy('version_no')->get();

        // ...added as a new version rather than overwriting anything,
        $this->assertCount(3, $versions);
        $this->assertSame('<p>First</p>', $versions[2]->content);

        // ...and the body that was current before the restore is still there.
        $this->assertSame('<p>Second</p>', $versions[1]->content);
    }

    public function test_restoring_logs_an_edit(): void
    {
        $document = $this->document('<p>First</p>');

        $this->actingAs($this->me)->put(route('student.documents.update', $document), [
            'title' => 'Chapter 1', 'content' => '<p>Second</p>',
        ]);

        $first = DocumentVersion::where('document_id', $document->id)->where('version_no', 1)->sole();

        $this->actingAs($this->me)->post(route('student.documents.restore', [$document, $first]));

        $this->assertSame(2, ActivityLog::where('activity_type', 'document_edited')
            ->where('reference_id', $document->id)->count());
    }

    public function test_restoring_the_current_content_is_a_no_op(): void
    {
        $document = $this->document('<p>Only</p>');
        $only = DocumentVersion::where('document_id', $document->id)->sole();

        $this->actingAs($this->me)->post(route('student.documents.restore', [$document, $only]));

        $this->assertSame(1, DocumentVersion::where('document_id', $document->id)->count());
        $this->assertSame(0, ActivityLog::where('activity_type', 'document_edited')->count());
    }

    /*
    | Scoping
    */

    public function test_another_groups_document_cannot_be_opened(): void
    {
        $foreign = Document::create([
            'group_id' => $this->otherGroup->id, 'created_by' => $this->me->id,
            'title' => 'Not yours', 'content' => '<p>secret</p>',
        ]);

        $this->actingAs($this->me)
            ->get(route('student.documents.edit', $foreign))
            ->assertNotFound();
    }

    public function test_another_groups_document_cannot_be_saved(): void
    {
        $foreign = Document::create([
            'group_id' => $this->otherGroup->id, 'created_by' => $this->me->id,
            'title' => 'Not yours', 'content' => '<p>secret</p>',
        ]);

        $this->actingAs($this->me)
            ->put(route('student.documents.update', $foreign), ['title' => 'Hijacked', 'content' => '<p>x</p>'])
            ->assertNotFound();

        $this->assertSame('Not yours', $foreign->fresh()->title);
    }

    public function test_a_document_cannot_be_created_in_another_group(): void
    {
        $this->actingAs($this->me)
            ->post(route('student.documents.store', $this->otherGroup), ['title' => 'Sneaky'])
            ->assertNotFound();

        $this->assertDatabaseMissing('documents', ['title' => 'Sneaky']);
    }

    public function test_a_version_from_a_different_document_is_a_404(): void
    {
        $mine = $this->document();

        $foreign = Document::create([
            'group_id' => $this->otherGroup->id, 'created_by' => $this->me->id,
            'title' => 'Not yours', 'content' => '<p>secret</p>',
        ]);
        $foreignVersion = DocumentVersion::create([
            'document_id' => $foreign->id, 'edited_by' => $this->me->id,
            'version_no' => 1, 'content' => '<p>secret</p>',
        ]);

        // Pairing my document with their version must not read across.
        $this->actingAs($this->me)
            ->get(route('student.documents.version', [$mine, $foreignVersion]))
            ->assertNotFound();

        $this->actingAs($this->me)
            ->post(route('student.documents.restore', [$mine, $foreignVersion]))
            ->assertNotFound();

        $this->assertSame('<p>Original</p>', $mine->fresh()->content);
    }

    public function test_documents_are_closed_to_other_roles(): void
    {
        $lecturer = User::factory()->lecturer()->create(['must_change_password' => false]);

        $this->actingAs($lecturer)
            ->get(route('student.documents'))
            ->assertForbidden();
    }

    public function test_a_student_with_no_group_gets_an_empty_state(): void
    {
        $orphan = User::factory()->student()->create(['must_change_password' => false]);

        $this->actingAs($orphan)
            ->get(route('student.documents'))
            ->assertOk()
            ->assertSee('You are not in a group yet');
    }
}
