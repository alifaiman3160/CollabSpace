<?php

namespace Tests\Feature\Student;

use App\Models\ActivityLog;
use App\Models\Document;
use App\Models\DocumentImage;
use App\Models\DocumentVersion;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\Subject;
use App\Models\User;
use App\Support\ContributionReport;
use App\Support\GroupFiles;
use App\Support\Marking\GroupFacts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * A deleted document is hidden from its group but kept for the lecturer, and what it earned stays as evidence.
 */
class DocumentSoftDeleteTest extends TestCase
{
    use RefreshDatabase;

    protected User $me;

    protected User $lecturer;

    protected Group $group;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(GroupFiles::DISK);

        $this->lecturer = User::factory()->lecturer()->create(['must_change_password' => false]);
        $subject = Subject::create(['lecturer_id' => $this->lecturer->id, 'code' => 'SECJ3104', 'name' => 'App Dev', 'group_size' => 4]);
        $this->group = Group::create(['subject_id' => $subject->id, 'name' => 'My Group', 'group_size' => 4]);

        $this->me = User::factory()->student()->create(['must_change_password' => false]);
        GroupMember::create(['group_id' => $this->group->id, 'user_id' => $this->me->id, 'joined_at' => now()]);
    }

    /** Made through the application, so versions, pictures and activity are the real ones. */
    protected function written(string $title = 'Report', string $body = '<p>First draft</p>'): Document
    {
        $this->actingAs($this->me)->post(route('student.documents.store', $this->group), ['title' => $title]);
        $document = Document::where('title', $title)->latest('id')->firstOrFail();

        $this->actingAs($this->me)->put(route('student.documents.update', $document), ['title' => $title, 'content' => $body])->assertRedirect();

        return $document;
    }

    protected function picture(Document $document): DocumentImage
    {
        $this->actingAs($this->me)->postJson(route('student.documents.images.store', $document), ['image' => UploadedFile::fake()->image('a.png')])->assertOk();

        return DocumentImage::where('document_id', $document->id)->latest('id')->firstOrFail();
    }

    /*
    | Delete
    */

    public function test_a_delete_is_soft_and_keeps_versions_pictures_and_files(): void
    {
        $document = $this->written();
        $image = $this->picture($document);

        $this->actingAs($this->me)->delete(route('student.documents.destroy', $document))->assertRedirect();

        $this->assertSoftDeleted('documents', ['id' => $document->id, 'deleted_by' => $this->me->id]);
        $this->assertNotNull(Document::onlyTrashed()->find($document->id)->deleted_at);
        $this->assertSame(2, DocumentVersion::where('document_id', $document->id)->count());
        $this->assertSame(1, DocumentImage::where('document_id', $document->id)->count());
        Storage::disk(GroupFiles::DISK)->assertExists($image->path);
    }

    public function test_a_deleted_document_is_gone_for_students(): void
    {
        $document = $this->written('Gone soon');

        $this->actingAs($this->me)->delete(route('student.documents.destroy', $document));

        $this->actingAs($this->me)->get(route('student.documents.group', $this->group))->assertOk()->assertDontSee(route('student.documents.edit', $document), false);
        $this->actingAs($this->me)->get(route('student.documents.edit', $document))->assertNotFound();
        $this->actingAs($this->me)->put(route('student.documents.update', $document), ['title' => 'x', 'content' => '<p>x</p>'])->assertNotFound();
        $this->actingAs($this->me)->get(route('student.documents.pdf', $document))->assertNotFound();
    }

    public function test_the_credit_and_the_evidence_counts_are_exactly_what_they_were(): void
    {
        $document = $this->written();
        $this->actingAs($this->me)->put(route('student.documents.update', $document), ['title' => 'Report', 'content' => '<p>Second draft</p>']);

        $facts = fn () => app(GroupFacts::class)->forGroup($this->group)[$this->me->id]['document_edits'];
        $report = fn () => app(ContributionReport::class)->for($this->group->fresh());

        $before = ['facts' => $facts(), 'score' => $this->me->contributionScoreFor($this->group), 'counts' => $report()['members']->first()['counts'], 'weights' => $report()['weights']];
        $this->assertGreaterThanOrEqual(3, $before['facts']);

        $this->actingAs($this->me)->delete(route('student.documents.destroy', $document));

        $this->assertSame($before['facts'], $facts(), 'Edits on a deleted document still count.');
        $this->assertSame($before['score'], $this->me->contributionScoreFor($this->group));
        $this->assertSame($before['counts'], $report()['members']->first()['counts']);
        $this->assertSame($before['weights'], $report()['weights'], 'The deletion row is not listed as a scoring rule.');
        $this->assertSame(['document_created', 'document_edited'], ActivityLog::whereIn('activity_type', ['document_created', 'document_edited'])->distinct()->orderBy('activity_type')->pluck('activity_type')->all());
        $this->assertSame(1, ActivityLog::where('activity_type', 'document_deleted')->count());
        $this->assertSame(0, (int) ActivityLog::where('activity_type', 'document_deleted')->value('weight'));
    }

    public function test_the_deletion_shows_in_the_feeds_with_a_proper_label(): void
    {
        $document = $this->written();
        $this->actingAs($this->me)->delete(route('student.documents.destroy', $document));

        $this->actingAs($this->me)->get(route('student.workspace.show', $this->group))->assertOk()->assertSee('removed a document');
        $this->actingAs($this->lecturer)->get(route('lecturer.progress.show', $this->group))->assertOk()->assertSee('removed a document');
    }

    public function test_the_wording_is_honest(): void
    {
        $document = $this->written();

        foreach ([route('student.documents.edit', $document), route('student.documents.group', $this->group)] as $url) {
            $html = $this->actingAs($this->me)->get($url)->assertOk()->getContent();

            $this->assertStringContainsString('will be removed from your group. Your lecturer can still see it.', $html);
            $this->assertStringNotContainsString('whole history', $html);
            $this->assertStringNotContainsString('This cannot be undone', $html);
        }

        $this->actingAs($this->me)->get(route('student.documents.edit', $document))->assertSee('Removes the document from your group. Your lecturer can still see it.');
    }

    public function test_only_members_can_delete(): void
    {
        $document = $this->written();
        $outsider = User::factory()->student()->create(['must_change_password' => false]);

        $this->actingAs($outsider)->delete(route('student.documents.destroy', $document))->assertNotFound();
        $this->actingAs($this->lecturer)->delete(route('student.documents.destroy', $document))->assertForbidden();

        $this->assertNotSoftDeleted('documents', ['id' => $document->id]);
    }

    /*
    | The lecturer's view
    */

    public function test_the_lecturer_sees_deleted_documents_and_can_read_but_not_change_them(): void
    {
        $document = $this->written('Kept for marking', '<p>The real chapter</p>');
        $image = $this->picture($document);

        $this->actingAs($this->me)->delete(route('student.documents.destroy', $document));

        $page = $this->actingAs($this->lecturer)->get(route('lecturer.progress.show', $this->group))->assertOk();
        $page->assertSee('Deleted documents')->assertSee('Kept for marking')->assertSee('Deleted by '.e($this->me->name), false);
        $this->assertStringContainsString('2 versions', $page->getContent());

        // Read only, with the banner; the picture still opens for the lecturer.
        $this->actingAs($this->lecturer)->get(route('lecturer.progress.documents.show', [$this->group, $document]))
            ->assertOk()->assertSee('data-deleted-banner', false)->assertSee('The real chapter')->assertSee('Read only');
        $this->actingAs($this->lecturer)->get(route('lecturer.progress.documents.version', [$this->group, $document, $document->versions()->first()]))->assertOk();
        $this->actingAs($this->lecturer)->get($image->url())->assertOk();

        // It is out of the ordinary grid and in the closed Deleted documents section, and nothing here can delete, restore or edit.
        $list = $this->actingAs($this->lecturer)->get(route('lecturer.progress.documents', $this->group))->assertOk()->getContent();
        $this->assertStringNotContainsString('Kept for marking', substr($list, 0, strpos($list, 'data-deleted-documents-section')));
        $this->assertStringContainsString('Kept for marking', substr($list, strpos($list, 'data-deleted-documents-section')));
        // (Forbidden for a live document, not found for a deleted one: either way, refused.)
        $refused = fn ($response) => $this->assertContains($response->getStatusCode(), [403, 404]);
        $refused($this->actingAs($this->lecturer)->delete(route('student.documents.destroy', $document)));
        $refused($this->actingAs($this->lecturer)->post(route('student.documents.restore', [$document, $document->versions()->first()])));
        $refused($this->actingAs($this->lecturer)->put(route('student.documents.update', $document), ['title' => 'x', 'content' => '<p>x</p>']));
        $this->assertSoftDeleted('documents', ['id' => $document->id]);
    }

    public function test_the_section_is_hidden_when_nothing_is_deleted_and_another_lecturer_cannot_open_it(): void
    {
        $document = $this->written();

        $this->actingAs($this->lecturer)->get(route('lecturer.progress.show', $this->group))->assertOk()->assertDontSee('Deleted documents');

        $this->actingAs($this->me)->delete(route('student.documents.destroy', $document));

        $other = User::factory()->lecturer()->create(['must_change_password' => false]);
        $this->actingAs($other)->get(route('lecturer.progress.documents.show', [$this->group, $document]))->assertNotFound();
    }

    /*
    | Edit counts reflect real changes
    */

    public function test_saving_an_unchanged_body_records_no_edit_and_a_change_records_exactly_one(): void
    {
        $document = $this->written('Counted', '<p>Draft one</p>');
        $edits = fn () => ActivityLog::where('reference_id', $document->id)->where('activity_type', 'document_edited')->count();

        $this->assertSame(1, $edits());

        $save = fn (string $body, string $title = 'Counted') => $this->actingAs($this->me)->put(route('student.documents.update', $document), ['title' => $title, 'content' => $body]);

        $save('<p>Draft one</p>');
        $save('<p>Draft one</p>');
        $this->assertSame(1, $edits(), 'Saving the same body again records nothing.');

        $save('<p>Draft one</p>', 'Renamed');
        $this->assertSame(1, $edits(), 'A rename alone is not an edit of the writing.');

        $save('<p>Draft two</p>', 'Renamed');
        $this->assertSame(2, $edits(), 'A changed body records exactly one.');
        $this->assertSame(3, $document->versions()->count());
    }

    /*
    | documents:purge-deleted
    */

    public function test_the_purge_command_needs_days_and_a_dry_run_removes_nothing(): void
    {
        $document = $this->written();
        $image = $this->picture($document);
        $this->actingAs($this->me)->delete(route('student.documents.destroy', $document));
        Document::onlyTrashed()->whereKey($document->id)->update(['deleted_at' => now()->subDays(200)]);

        $this->artisan('documents:purge-deleted')->assertFailed();
        $this->artisan('documents:purge-deleted', ['--days' => 'soon'])->assertFailed();
        $this->artisan('documents:purge-deleted', ['--days' => 30, '--dry-run' => true])->expectsOutputToContain('Would remove 1 document')->assertSuccessful();

        $this->assertTrue(Document::onlyTrashed()->whereKey($document->id)->exists());
        $this->assertSame(2, DocumentVersion::where('document_id', $document->id)->count());
        Storage::disk(GroupFiles::DISK)->assertExists($image->path);
    }

    public function test_a_real_purge_removes_only_older_deleted_documents_and_their_files(): void
    {
        $old = $this->written('Old');
        $oldImage = $this->picture($old);
        $recent = $this->written('Recent');
        $recentImage = $this->picture($recent);
        $live = $this->written('Live');
        $liveImage = $this->picture($live);

        foreach ([$old, $recent] as $document) {
            $this->actingAs($this->me)->delete(route('student.documents.destroy', $document));
        }
        Document::onlyTrashed()->whereKey($old->id)->update(['deleted_at' => now()->subDays(100)]);
        Document::onlyTrashed()->whereKey($recent->id)->update(['deleted_at' => now()->subDays(5)]);

        $this->artisan('documents:purge-deleted', ['--days' => 30])->assertSuccessful();

        $this->assertDatabaseMissing('documents', ['id' => $old->id]);
        $this->assertDatabaseMissing('document_versions', ['document_id' => $old->id]);
        $this->assertDatabaseMissing('document_images', ['document_id' => $old->id]);
        Storage::disk(GroupFiles::DISK)->assertMissing($oldImage->path);

        $this->assertTrue(Document::onlyTrashed()->whereKey($recent->id)->exists(), 'Younger than the cut-off: kept.');
        Storage::disk(GroupFiles::DISK)->assertExists($recentImage->path);

        $this->assertNotSoftDeleted('documents', ['id' => $live->id]);
        Storage::disk(GroupFiles::DISK)->assertExists($liveImage->path);
        $this->assertSame(0, ActivityLog::where('reference_id', $old->id)->whereIn('activity_type', ['document_created', 'document_edited', 'document_deleted'])->count(), 'A purge takes its credit with it.');
        $this->assertGreaterThan(0, ActivityLog::where('reference_id', $recent->id)->count());
    }

    /*
    | Housekeeping
    */

    public function test_the_backup_guide_and_the_ignore_rules_exist(): void
    {
        $guide = file_get_contents(base_path('docs/BACKUP.md'));

        foreach (['collabspace_v2', '--result-file', 'storage/app/private', 'documents:purge-deleted', 'Where NOT to put backups'] as $needle) {
            $this->assertStringContainsString($needle, $guide);
        }
        $this->assertStringNotContainsString('password=', strtolower($guide));

        $ignore = file_get_contents(base_path('.gitignore'));
        $this->assertMatchesRegularExpression('/^\*\.sql$/m', $ignore);
        $this->assertMatchesRegularExpression('/^backups\/$/m', $ignore);

        $this->assertStringContainsString('docs/BACKUP.md', file_get_contents(base_path('README.md')));
        $this->assertStringContainsString('BACKUP.md', file_get_contents(base_path('docs/MANUAL-TEST.md')));
    }
}
