<?php

namespace Tests\Feature\Student;

use App\Models\Document;
use App\Models\DocumentImage;
use App\Models\DocumentVersion;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\Subject;
use App\Models\User;
use App\Support\DocumentPrintBody;
use App\Support\GroupFiles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * The paged editor's wiring, the page-marker contract the print layout relies on, and the Download PDF action.
 *
 * The page logic itself (split/join, new page at the cursor, overflow, last page) is pure JavaScript and is
 * tested with Node in tests/js; the first test below runs that suite so one command covers everything.
 */
class DocumentPagesAndPdfTest extends TestCase
{
    use RefreshDatabase;

    protected User $me;

    protected Group $group;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(GroupFiles::DISK);

        $lecturer = User::factory()->lecturer()->create();
        $subject = Subject::create(['lecturer_id' => $lecturer->id, 'code' => 'SECJ3104', 'name' => 'App Dev', 'group_size' => 4]);

        $this->group = Group::create(['subject_id' => $subject->id, 'name' => 'Team: Alpha/1', 'group_size' => 4]);
        $this->me = User::factory()->student()->create(['must_change_password' => false]);
        GroupMember::create(['group_id' => $this->group->id, 'user_id' => $this->me->id, 'joined_at' => now()]);
    }

    protected function document(string $content = '<p>Hello</p>', string $title = 'Final Report'): Document
    {
        $document = Document::create([
            'group_id' => $this->group->id, 'created_by' => $this->me->id, 'last_edited_by' => $this->me->id,
            'title' => $title, 'content' => $content,
        ]);

        DocumentVersion::create(['document_id' => $document->id, 'edited_by' => $this->me->id, 'version_no' => 1, 'content' => $content]);

        return $document;
    }

    /*
    | The page logic (Node)
    */

    public function test_the_page_logic_passes_its_node_tests(): void
    {
        $node = (new Process(['node', '--version']));
        $node->run();

        if (! $node->isSuccessful()) {
            $this->markTestSkipped('Node is not available to run tests/js.');
        }

        $run = new Process(['node', '--test', 'tests/js/document-pages.test.mjs', 'tests/js/document-format.test.mjs', 'tests/js/document-grid-logic.test.mjs', 'tests/js/progress-filter-logic.test.mjs', 'tests/js/tooltip-logic.test.mjs', 'tests/js/sidebar-logic.test.mjs', 'tests/js/student-search-logic.test.mjs', 'tests/js/group-switcher-logic.test.mjs'], base_path(), null, null, 60);
        $run->run();

        $this->assertTrue($run->isSuccessful(), $run->getOutput().$run->getErrorOutput());
    }

    /*
    | The paged editor
    */

    public function test_the_editor_is_paged_with_controls_and_without_a_pdf_button(): void
    {
        $document = $this->document();

        $this->actingAs($this->me)->get(route('student.documents.edit', $document))
            ->assertOk()
            ->assertSee('aria-label="Pages"', false)
            ->assertSee('x-text="pages.length"', false)
            ->assertSee('Previous page', false)
            ->assertSee('Next page', false)
            ->assertSee('New page', false)
            ->assertSee('Delete page', false)
            ->assertSee('Jump to a page', false)
            ->assertSee('doc-measure', false)
            ->assertDontSee('Download PDF', false)
            ->assertDontSee(route('student.documents.pdf', $document), false);
    }

    public function test_the_editor_module_has_the_navigation_and_overflow_rules(): void
    {
        $js = file_get_contents(resource_path('js/document-editor.js'));

        foreach (['ArrowLeft', 'ArrowRight', 'PageDown', 'PageUp', 'deltaX', 'prefers-reduced-motion', 'historyUndo', 'cascadeOverflow', 'removePage'] as $needle) {
            $this->assertStringContainsString($needle, $js);
        }

        // No mouse-drag paging: it would fight with selecting text.
        $this->assertStringNotContainsString('mousedown', $js);
        $this->assertStringNotContainsString('pointerdown', $js);
    }

    public function test_page_markers_survive_a_save_and_unchanged_saves_make_no_version(): void
    {
        $document = $this->document();
        $body = '<p>One</p><p data-page-break="1"><br></p><p data-page-break="1"><br></p><p>Three</p><p data-page-break="1"><br></p>';

        $save = fn () => $this->actingAs($this->me)->put(route('student.documents.update', $document), ['title' => 'Final Report', 'content' => $body]);

        $save();
        $this->assertSame(3, substr_count($document->fresh()->content, 'data-page-break'), 'Consecutive and trailing markers (empty pages) are kept.');

        $versions = $document->versions()->count();
        $save();

        $this->assertSame($versions, $document->versions()->count());
    }

    /*
    | The print layout: each page marker is a real break
    */

    public function test_each_page_marker_becomes_a_page_break_in_the_print_view(): void
    {
        $body = app(DocumentPrintBody::class)->render('<p>One</p><p data-page-break="1"><br></p><p data-page-break="1"><br></p><p>Three</p>');

        $this->assertSame(2, substr_count($body, 'class="page-break"'));
        $this->assertStringNotContainsString('data-page-break', $body);

        $page = view('documents.print', ['title' => 'Report', 'body' => $body])->render();

        $this->assertStringContainsString('page-break-after: always', $page);
        $this->assertStringContainsString('counter(page)', $page);
    }

    /*
    | Download PDF
    */

    public function test_a_member_downloads_a_pdf_named_after_the_document_and_group(): void
    {
        $document = $this->document('<h1>Intro</h1><p>Body</p><p data-page-break="1"><br></p><h2>Second page</h2><p>Soalan ini dalam Bahasa Melayu.</p>');

        $response = $this->actingAs($this->me)->get(route('student.documents.pdf', $document))->assertOk();

        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF', $response->getContent());
        $this->assertStringContainsString('attachment', $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('Final Report - Team Alpha1.pdf', $response->headers->get('Content-Disposition'));
    }

    public function test_the_pdf_has_a_page_for_each_marker(): void
    {
        $document = $this->document('<p>One</p><p data-page-break="1"><br></p><p>Two</p><p data-page-break="1"><br></p><p data-page-break="1"><br></p><p>Four</p>');

        $output = $this->actingAs($this->me)->get(route('student.documents.pdf', $document))->assertOk()->getContent();

        preg_match_all('/\/Type\s*\/Page\b(?!s)/', $output, $pages);

        $this->assertSame(4, count($pages[0]));
    }

    public function test_a_picture_is_embedded_in_the_pdf(): void
    {
        $document = $this->document('<p>Chart</p>');

        $file = UploadedFile::fake()->image('chart.png', 60, 40);
        $this->actingAs($this->me)->postJson(route('student.documents.images.store', $document), ['image' => $file])->assertOk();

        $image = DocumentImage::firstOrFail();
        $document->update(['content' => '<figure data-size="medium"><img src="/documents/images/'.$image->id.'" data-image="'.$image->id.'" alt=""><figcaption>Figure 1</figcaption></figure>']);

        $body = app(DocumentPrintBody::class)->render($document->content, $document);

        $this->assertStringContainsString('src="data:image/png;base64,', $body);
        $this->assertStringNotContainsString('/documents/images/', $body);

        $this->actingAs($this->me)->get(route('student.documents.pdf', $document))->assertOk();
    }

    public function test_another_documents_picture_is_dropped_from_the_print_body(): void
    {
        $mine = $this->document('<p>x</p>');
        $other = $this->document('<p>y</p>', 'Other');

        $this->actingAs($this->me)->postJson(route('student.documents.images.store', $other), ['image' => UploadedFile::fake()->image('a.png')])->assertOk();
        $image = DocumentImage::firstOrFail();

        $body = app(DocumentPrintBody::class)->render('<figure><img src="/documents/images/'.$image->id.'" data-image="'.$image->id.'"></figure><p>kept</p>', $mine);

        $this->assertStringNotContainsString('<img', $body);
        $this->assertStringContainsString('kept', $body);
    }

    public function test_a_non_member_gets_403_and_a_missing_document_404(): void
    {
        $document = $this->document();
        $outsider = User::factory()->student()->create(['must_change_password' => false]);

        $this->actingAs($outsider)->get(route('student.documents.pdf', $document))->assertForbidden();
        $this->actingAs($this->me)->get('/student/documents/d/999999/pdf')->assertNotFound();
        $this->actingAs($this->me)->get(route('student.documents.pdf', $document))->assertOk();

        auth()->logout();
        $this->get(route('student.documents.pdf', $document))->assertRedirect(route('login'));
    }

    public function test_the_filename_is_cleaned(): void
    {
        $document = $this->document('<p>x</p>', 'A/B\\C:D*E?F"G<H>I|J  K');
        $document->load('group');

        $name = app(\App\Http\Controllers\Student\DocumentController::class)->pdfFilename($document);

        $this->assertSame('ABCDEFGHIJ K - Team Alpha1.pdf', $name);
        $this->assertDoesNotMatchRegularExpression('/[\\\\\/:*?"<>|]/', $name);
    }

    public function test_a_very_large_document_fails_politely(): void
    {
        $document = $this->document('<p>'.str_repeat('word ', 110000).'</p>');

        $this->actingAs($this->me)->get(route('student.documents.pdf', $document), ['Accept' => 'application/json', 'X-Requested-With' => 'XMLHttpRequest'])
            ->assertStatus(422)
            ->assertJsonStructure(['message']);
    }

    public function test_the_list_shows_download_pdf_to_members_only(): void
    {
        $document = $this->document();
        $pdf = route('student.documents.pdf', $document);

        $this->actingAs($this->me)->get(route('student.documents.group', $this->group))
            ->assertOk()
            ->assertSee('Download PDF', false)
            ->assertSee($pdf, false);

        $outsider = User::factory()->student()->create(['must_change_password' => false]);

        $this->actingAs($outsider)->get(route('student.documents.group', $this->group))->assertNotFound();
    }
}
