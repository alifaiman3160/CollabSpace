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
 * The lecturer's document pages are sub-pages of Monitor progress: their own addresses, the sidebar stays on Monitor
 * progress, a breadcrumb, a card grid and a read-only paged viewer, and the old addresses still work.
 */
class MonitorDocumentsTest extends TestCase
{
    use RefreshDatabase;

    protected User $lecturer;

    protected User $student;

    protected Group $group;

    protected Document $document;

    protected function setUp(): void
    {
        parent::setUp();

        $this->lecturer = User::factory()->lecturer()->create(['must_change_password' => false]);
        $subject = Subject::create(['lecturer_id' => $this->lecturer->id, 'code' => 'BCS3323', 'name' => 'Software Engineering', 'group_size' => 4]);
        $this->group = Group::create(['subject_id' => $subject->id, 'name' => 'Group SE1', 'group_size' => 4]);
        $this->student = User::factory()->student()->create(['must_change_password' => false]);
        GroupMember::create(['group_id' => $this->group->id, 'user_id' => $this->student->id, 'joined_at' => now()]);

        $this->document = $this->makeDocument('Assignment 1 - 26/27', '<h1>Intro</h1><p>Page one</p><p data-page-break="1"><br></p><p>Page two</p>');
    }

    protected function makeDocument(string $title, string $content): Document
    {
        $document = Document::create(['group_id' => $this->group->id, 'created_by' => $this->student->id, 'last_edited_by' => $this->student->id, 'title' => $title, 'content' => $content]);
        DocumentVersion::create(['document_id' => $document->id, 'edited_by' => $this->student->id, 'version_no' => 1, 'content' => $content]);

        return $document;
    }

    protected function get_(string $url)
    {
        return $this->actingAs($this->lecturer)->get($url);
    }

    /** The label of the one sidebar entry marked current. */
    protected function current(string $html): ?string
    {
        preg_match('/<nav[^>]*aria-label="Main".*?<\/nav>/s', $html, $nav);
        preg_match_all('/<a[^>]*aria-current="page"[^>]*>(.*?)<\/a>/s', $nav[0], $m);

        return isset($m[1][0]) ? trim(preg_replace('/\s+/', ' ', strip_tags($m[1][0]))) : null;
    }

    /*
    | Addresses
    */

    public function test_the_new_addresses_work_for_the_lecturer_of_the_group(): void
    {
        $version = $this->document->versions()->first();

        $this->get_(route('lecturer.progress.documents', $this->group))->assertOk();
        $this->get_(route('lecturer.progress.documents.show', [$this->group, $this->document]))->assertOk();
        $this->get_(route('lecturer.progress.documents.version', [$this->group, $this->document, $version]))->assertOk();

        $this->assertStringStartsWith('/lecturer/progress/'.$this->group->id.'/documents', route('lecturer.progress.documents', $this->group, false));
    }

    public function test_the_old_addresses_redirect_to_the_new_ones(): void
    {
        $version = $this->document->versions()->first();

        $this->get_(route('lecturer.documents', $this->group))->assertRedirect(route('lecturer.progress.documents', $this->group));
        $this->get_(route('lecturer.documents.show', $this->document))->assertRedirect(route('lecturer.progress.documents.show', [$this->group, $this->document]));
        $this->get_(route('lecturer.documents.version', [$this->document, $version]))->assertRedirect(route('lecturer.progress.documents.version', [$this->group, $this->document, $version]));
        $this->assertSame(301, $this->get_(route('lecturer.documents', $this->group))->getStatusCode());
    }

    public function test_other_lecturers_and_students_are_rejected_old_and_new(): void
    {
        $stranger = User::factory()->lecturer()->create(['must_change_password' => false]);
        $version = $this->document->versions()->first();

        foreach ([
            route('lecturer.progress.documents', $this->group),
            route('lecturer.progress.documents.show', [$this->group, $this->document]),
            route('lecturer.progress.documents.version', [$this->group, $this->document, $version]),
            route('lecturer.documents', $this->group),
            route('lecturer.documents.show', $this->document),
        ] as $url) {
            $this->actingAs($stranger)->get($url)->assertNotFound();
            $this->actingAs($this->student)->get($url)->assertForbidden();
        }
    }

    public function test_a_document_from_another_group_is_not_found_under_this_group(): void
    {
        $other = Group::create(['subject_id' => $this->group->subject_id, 'name' => 'Group SE2', 'group_size' => 4]);

        $this->get_(route('lecturer.progress.documents.show', [$other, $this->document]))->assertNotFound();
    }

    /*
    | The sidebar and the breadcrumb
    */

    public function test_the_sidebar_stays_on_monitor_progress_everywhere_in_the_documents(): void
    {
        $version = $this->document->versions()->first();

        $deleted = $this->makeDocument('Removed one', '<p>x</p>');
        $deleted->forceFill(['deleted_by' => $this->student->id])->save();
        $deleted->delete();

        foreach ([
            route('lecturer.progress.documents', $this->group),
            route('lecturer.progress.documents.show', [$this->group, $this->document]),
            route('lecturer.progress.documents.version', [$this->group, $this->document, $version]),
            route('lecturer.progress.documents.show', [$this->group, $deleted]),
        ] as $url) {
            $this->assertSame('Monitor progress', $this->current($this->get_($url)->assertOk()->getContent()), $url);
        }
    }

    public function test_the_breadcrumb_links_each_part_but_the_last(): void
    {
        $list = $this->get_(route('lecturer.progress.documents', $this->group))->getContent();

        $this->assertStringContainsString('aria-label="Breadcrumb"', $list);
        $this->assertStringContainsString('href="'.route('lecturer.progress').'"', $list);
        $this->assertMatchesRegularExpression('/<a href="'.preg_quote(route('lecturer.progress.show', $this->group), '/').'"[^>]*>Group SE1<\/a>/', $list);
        $this->assertMatchesRegularExpression('/<span[^>]*aria-current="page"\s*>Documents<\/span>/', $list);
        $this->assertStringContainsString('Back to monitoring', $list);

        $viewer = $this->get_(route('lecturer.progress.documents.show', [$this->group, $this->document]))->getContent();

        $this->assertMatchesRegularExpression('/<a href="'.preg_quote(route('lecturer.progress.documents', $this->group), '/').'"[^>]*>Documents<\/a>/', $viewer);
        $this->assertMatchesRegularExpression('/<span[^>]*aria-current="page"\s*>Assignment 1 - 26\/27<\/span>/', $viewer);
        $this->assertStringContainsString('All documents', $viewer);
        $this->assertStringNotContainsString('Back to monitoring', $viewer, 'The lone back link is replaced by the breadcrumb.');
        $this->assertStringContainsString('BCS3323 — Group SE1', $viewer);
    }

    /*
    | The list
    */

    public function test_the_list_is_a_card_grid_with_previews_a_toolbar_and_only_a_read_action(): void
    {
        $this->makeDocument('Single page', '<p>One</p>');

        $html = $this->get_(route('lecturer.progress.documents', $this->group))->getContent();

        $this->assertStringNotContainsString('<table', $html);
        $this->assertSame(2, substr_count($html, 'data-doc '));
        $this->assertStringContainsString('Page one', $html, 'The first page as a small preview.');
        $this->assertStringNotContainsString('Page two', $html);
        $this->assertSame(1, substr_count($html, 'data-page-pill'));
        $this->assertStringContainsString('2 pages', $html);
        $this->assertStringContainsString('data-version-pill', $html);
        $this->assertStringContainsString('Started by '.e($this->student->name), $html);

        // The toolbar on the left, above the grid; read-only strip kept.
        $this->assertSame(1, substr_count($html, 'data-documents-toolbar'));
        $this->assertLessThan(strpos($html, 'data-document-grid'), strpos($html, 'data-documents-toolbar'));
        $this->assertStringContainsString('data-read-only-note', $html);

        // Read, and nothing else: no Open, no PDF, no Delete.
        $this->assertMatchesRegularExpression('/>\s*Read\s*</', $html);
        foreach (['Download PDF', 'Delete', 'student.documents', '/student/'] as $absent) {
            $this->assertStringNotContainsString($absent, $html, $absent);
        }
    }

    public function test_deleted_documents_sit_closed_under_the_grid_with_their_deletion_note(): void
    {
        $deleted = $this->makeDocument('Removed one', '<p>x</p>');
        $deleted->forceFill(['deleted_by' => $this->student->id])->save();
        $deleted->delete();

        $html = $this->get_(route('lecturer.progress.documents', $this->group))->getContent();

        $this->assertStringContainsString('data-deleted-documents-section', $html);
        $this->assertStringContainsString("x-data=\"{ open: false }\"", $html);
        $this->assertMatchesRegularExpression('/data-deleted-count>\s*1\s*</', $html);
        $this->assertStringContainsString('Deleted by '.e($this->student->name), $html);
        $this->assertGreaterThan(strpos($html, 'data-document-grid'), strpos($html, 'data-deleted-documents-section'));

        // The document search never touches them (they are outside its scope).
        $this->assertStringNotContainsString('Removed one', substr($html, 0, strpos($html, 'data-deleted-documents-section')));
    }

    public function test_an_empty_group_is_friendly_without_a_dashed_box(): void
    {
        Document::query()->delete();

        $html = $this->get_(route('lecturer.progress.documents', $this->group))->getContent();

        $this->assertStringContainsString('No documents yet', $html);
        $this->assertStringContainsString('data-documents-empty', $html);
        $this->assertStringNotContainsString('border-dashed', $html);
    }

    /*
    | The viewer
    */

    public function test_the_viewer_is_a_read_only_a4_sheet_with_page_controls_and_a_history_panel(): void
    {
        $html = $this->get_(route('lecturer.progress.documents.show', [$this->group, $this->document]))->getContent();

        $this->assertStringContainsString('doc-body doc-page', $html);
        $this->assertStringContainsString('x-data="documentViewer(', $html);
        $this->assertStringContainsString('aria-label="Previous page"', $html);
        $this->assertStringContainsString('aria-label="Next page"', $html);
        $this->assertStringContainsString('Page one', $html);

        // Not editable, no toolbar, nothing to focus.
        foreach (['contenteditable', 'role="textbox"', 'aria-label="Formatting"', 'role="toolbar"', 'x-on:paste', 'x-on:input', 'rich-text'] as $absent) {
            $this->assertStringNotContainsString($absent, $html, $absent);
        }

        // The history as a panel with its title inside, one row per version, each a lecturer link.
        $this->assertSame(1, substr_count($html, 'data-history-panel="'));
        $this->assertMatchesRegularExpression('/aria-label="History"[^>]*>\s*<h2[^>]*>\s*History/s', $html);
        $this->assertStringContainsString('Current content', $html);
        $this->assertStringContainsString('Read only', $html);
    }

    public function test_an_empty_document_shows_a_blank_sheet_and_says_so(): void
    {
        $empty = $this->makeDocument('Nothing yet', '');

        $html = $this->get_(route('lecturer.progress.documents.show', [$this->group, $empty]))->getContent();

        $this->assertStringContainsString('This document is empty.', $html);
        $this->assertStringContainsString('doc-body doc-page', $html);
    }

    public function test_a_version_view_keeps_the_strip_and_a_way_back(): void
    {
        $version = DocumentVersion::create(['document_id' => $this->document->id, 'edited_by' => $this->student->id, 'version_no' => 2, 'content' => '<p>Earlier words</p>']);

        $html = $this->get_(route('lecturer.progress.documents.version', [$this->group, $this->document, $version]))->getContent();

        $this->assertStringContainsString('Earlier words', $html);
        $this->assertStringContainsString('Version 2 of 2', $html);
        $this->assertStringContainsString('Back to current', $html);
        $this->assertStringContainsString(route('lecturer.progress.documents.show', [$this->group, $this->document]), $html);
    }

    public function test_deleted_documents_open_with_the_banner(): void
    {
        $deleted = $this->makeDocument('Removed one', '<p>Still readable</p>');
        $deleted->forceFill(['deleted_by' => $this->student->id])->save();
        $deleted->delete();

        $this->get_(route('lecturer.progress.documents.show', [$this->group, $deleted]))
            ->assertOk()->assertSee('data-deleted-banner', false)->assertSee('Still readable')->assertSee('Deleted by '.e($this->student->name), false);
    }

    public function test_no_link_on_these_pages_goes_to_the_students_documents(): void
    {
        $version = $this->document->versions()->first();

        foreach ([
            route('lecturer.progress.documents', $this->group),
            route('lecturer.progress.documents.show', [$this->group, $this->document]),
            route('lecturer.progress.documents.version', [$this->group, $this->document, $version]),
        ] as $url) {
            $html = $this->get_($url)->getContent();
            preg_match_all('/href="([^"]+)"/', $html, $links);

            foreach ($links[1] as $link) {
                $this->assertStringNotContainsString('/student/', $link, $url.' links to '.$link);
                $this->assertStringNotContainsString('/documents/d/', $link, $link);
            }
        }
    }

    public function test_reading_records_no_activity(): void
    {
        $version = $this->document->versions()->first();

        $this->get_(route('lecturer.progress.documents', $this->group));
        $this->get_(route('lecturer.progress.documents.show', [$this->group, $this->document]));
        $this->get_(route('lecturer.progress.documents.version', [$this->group, $this->document, $version]));

        $this->assertDatabaseCount('activity_logs', 0);
    }

    public function test_the_monitor_page_links_to_the_new_addresses(): void
    {
        $html = $this->get_(route('lecturer.progress.show', $this->group))->getContent();

        $this->assertStringContainsString(route('lecturer.progress.documents', $this->group), $html);
        $this->assertStringContainsString(route('lecturer.progress.documents.show', [$this->group, $this->document]), $html);
        $this->assertStringNotContainsString(route('lecturer.documents.show', $this->document), $html);
    }
}
