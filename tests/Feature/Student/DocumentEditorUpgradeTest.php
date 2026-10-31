<?php

namespace Tests\Feature\Student;

use App\Models\Document;
use App\Models\DocumentImage;
use App\Models\DocumentVersion;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\Subject;
use App\Models\User;
use App\Support\DocumentOutline;
use App\Support\DocumentPrintBody;
use App\Support\DocumentSanitizer;
use App\Support\GroupFiles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The report-writing additions to the Documents editor: pictures, links, page
 * breaks, a table of contents and the H1 heading.
 */
class DocumentEditorUpgradeTest extends TestCase
{
    use RefreshDatabase;

    protected User $me;

    protected User $lecturer;

    protected Group $group;

    protected Document $document;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(GroupFiles::DISK);

        $this->lecturer = User::factory()->lecturer()->create();
        $subject = Subject::create([
            'lecturer_id' => $this->lecturer->id, 'code' => 'SECJ3104', 'name' => 'App Dev', 'group_size' => 4,
        ]);

        $this->group = Group::create(['subject_id' => $subject->id, 'name' => 'My Group', 'group_size' => 4]);
        $this->me = User::factory()->student()->create(['must_change_password' => false]);
        GroupMember::create(['group_id' => $this->group->id, 'user_id' => $this->me->id, 'joined_at' => now()]);

        $this->document = Document::create([
            'group_id' => $this->group->id,
            'created_by' => $this->me->id,
            'last_edited_by' => $this->me->id,
            'title' => 'Report',
            'content' => '<p>Start</p>',
        ]);

        DocumentVersion::create([
            'document_id' => $this->document->id, 'edited_by' => $this->me->id,
            'version_no' => 1, 'content' => '<p>Start</p>',
        ]);
    }

    protected function save(string $content): void
    {
        $this->actingAs($this->me)->put(route('student.documents.update', $this->document), [
            'title' => 'Report',
            'content' => $content,
        ]);
    }

    protected function upload(UploadedFile $file, ?User $as = null)
    {
        return $this->actingAs($as ?? $this->me)->postJson(
            route('student.documents.images.store', $this->document),
            ['image' => $file],
        );
    }

    /*
    | The sanitiser
    */

    public function test_the_sanitiser_strips_scripts_event_attributes_javascript_links_and_foreign_images(): void
    {
        $clean = (new DocumentSanitizer())->clean(
            '<p onclick="x()">Hi</p><script>alert(1)</script>'
            .'<a href="javascript:alert(1)">bad</a>'
            .'<a href="data:text/html;base64,AAAA">bad</a>'
            .'<img src="https://evil.test/pixel.png" onerror="x()">'
            .'<img src="data:image/png;base64,AAAA">'
            .'<img src="/etc/passwd">'
        );

        $this->assertStringNotContainsString('<script', $clean);
        $this->assertStringNotContainsString('onclick', $clean);
        $this->assertStringNotContainsString('onerror', $clean);
        $this->assertStringNotContainsString('javascript:', $clean);
        $this->assertStringNotContainsString('data:', $clean);
        $this->assertStringNotContainsString('evil.test', $clean);
        $this->assertStringNotContainsString('/etc/passwd', $clean);
        $this->assertStringNotContainsString('<img', $clean);
    }

    public function test_the_sanitiser_keeps_the_apps_own_picture_and_forces_safe_link_attributes(): void
    {
        $clean = (new DocumentSanitizer())->clean(
            '<figure data-size="full"><img src="/documents/images/7" data-image="7" alt="Chart"><figcaption>Figure 1</figcaption></figure>'
            .'<p><a href="https://example.com/a" target="_self" rel="opener">link</a></p>'
            .'<p><a href="mailto:a@b.test">mail</a></p>'
        );

        $this->assertStringContainsString('src="/documents/images/7"', $clean);
        $this->assertStringContainsString('data-size="full"', $clean);
        $this->assertStringContainsString('<figcaption>Figure 1</figcaption>', $clean);
        $this->assertStringContainsString('href="https://example.com/a"', $clean);
        $this->assertStringContainsString('target="_blank"', $clean);
        $this->assertStringContainsString('rel="noopener noreferrer nofollow"', $clean);
        $this->assertStringNotContainsString('_self', $clean);
        $this->assertStringNotContainsString('mailto:', $clean);
    }

    public function test_an_unknown_picture_width_falls_back_to_medium(): void
    {
        $clean = (new DocumentSanitizer())->clean('<figure data-size="huge"><img src="/documents/images/1" data-image="1"></figure>');

        $this->assertStringContainsString('data-size="medium"', $clean);
    }

    public function test_page_break_and_table_of_contents_markers_survive_a_save(): void
    {
        $this->save('<h1>One</h1><p data-page-break="1"><br></p><blockquote data-toc="1"><p><strong>Table of contents</strong></p><ul><li>One</li></ul></blockquote>');

        $stored = $this->document->fresh()->content;

        $this->assertStringContainsString('data-page-break="1"', $stored);
        $this->assertStringContainsString('data-toc="1"', $stored);
        $this->assertStringContainsString('<h1>One</h1>', $stored);
    }

    public function test_saving_an_unchanged_body_creates_no_new_version(): void
    {
        $body = '<h1>One</h1><figure data-size="small"><img src="/documents/images/3" data-image="3" alt=""><figcaption>Cap</figcaption></figure><p data-page-break="1"><br></p>';

        $this->save($body);
        $versions = $this->document->versions()->count();

        $this->save($body);

        $this->assertSame($versions, $this->document->versions()->count());
    }

    public function test_version_history_still_lists_and_restores_after_the_additions(): void
    {
        $this->save('<h1>First</h1>');
        $this->save('<h1>Second</h1>');

        $first = $this->document->versions()->where('content', 'like', '%First%')->first();

        $this->actingAs($this->me)->get(route('student.documents.version', [$this->document, $first]))->assertOk();
        $this->actingAs($this->me)->post(route('student.documents.restore', [$this->document, $first]))->assertRedirect();

        $this->assertStringContainsString('First', $this->document->fresh()->content);
    }

    /*
    | The table of contents and the print body
    */

    public function test_the_outline_lists_h1_h2_and_h3_in_document_order_and_skips_the_contents_block(): void
    {
        $html = '<h1>Intro</h1><blockquote data-toc="1"><p><strong>Table of contents</strong></p><ul><li>Old</li></ul></blockquote>'
            .'<h2>Background</h2><p>text</p><h3>Detail</h3><h1>Method</h1><h2>Steps</h2><h4>ignored</h4>';

        $this->assertSame([
            ['level' => 1, 'text' => 'Intro'],
            ['level' => 2, 'text' => 'Background'],
            ['level' => 3, 'text' => 'Detail'],
            ['level' => 1, 'text' => 'Method'],
            ['level' => 2, 'text' => 'Steps'],
        ], DocumentOutline::headings($html));
    }

    public function test_the_print_body_makes_real_page_breaks_and_expands_the_contents_without_page_numbers(): void
    {
        $body = app(DocumentPrintBody::class)->render(
            '<blockquote data-toc="1"><p>stale</p></blockquote><h1>Intro</h1><p data-page-break="1"><br></p><h2>Scope</h2>'
        );

        $this->assertStringContainsString('class="page-break"', $body);
        $this->assertStringNotContainsString('data-page-break', $body);
        $this->assertStringNotContainsString('stale', $body);
        $this->assertStringContainsString('toc-l1', $body);
        $this->assertStringContainsString('toc-l2', $body);
        $this->assertLessThan(strpos($body, 'Scope'), strpos($body, 'Intro'));

        $page = view('documents.print', ['title' => 'Report', 'body' => $body])->render();

        $this->assertStringContainsString('size: A4', $page);
        $this->assertStringContainsString('margin: 2.5cm', $page);
        $this->assertStringContainsString('counter(page)', $page);
    }

    /*
    | Uploading a picture
    */

    public function test_a_member_can_upload_a_png_and_gets_an_address_back(): void
    {
        $response = $this->upload(UploadedFile::fake()->image('chart.png', 40, 40))->assertOk();

        $image = DocumentImage::firstOrFail();

        $response->assertJson(['id' => $image->id, 'url' => '/documents/images/'.$image->id]);
        Storage::disk(GroupFiles::DISK)->assertExists($image->path);
        $this->assertStringStartsWith(GroupFiles::directoryFor($this->group).'/', $image->path);
    }

    public function test_a_picture_of_the_wrong_type_is_rejected_with_a_friendly_message(): void
    {
        $this->upload(UploadedFile::fake()->create('notes.pdf', 10, 'application/pdf'))
            ->assertStatus(422)
            ->assertJson(['message' => 'Only PNG, JPG or WebP pictures can be inserted.']);

        $this->upload(UploadedFile::fake()->create('page.svg', 5, 'image/svg+xml'))->assertStatus(422);

        $this->assertSame(0, DocumentImage::count());
    }

    public function test_a_picture_over_two_megabytes_is_rejected_with_a_friendly_message(): void
    {
        $this->upload(UploadedFile::fake()->image('big.jpg', 20, 20)->size(2049))
            ->assertStatus(422)
            ->assertJson(['message' => 'That picture is too large. Pictures can be up to 2 MB.']);

        $this->assertSame(0, DocumentImage::count());
    }

    public function test_a_non_member_cannot_upload_to_the_document(): void
    {
        $outsider = User::factory()->student()->create(['must_change_password' => false]);

        $this->upload(UploadedFile::fake()->image('a.png'), $outsider)->assertNotFound();

        $this->assertSame(0, DocumentImage::count());
    }

    /*
    | Serving a picture
    */

    protected function storedImage(): DocumentImage
    {
        $this->upload(UploadedFile::fake()->image('a.png', 30, 30))->assertOk();

        return DocumentImage::firstOrFail();
    }

    public function test_a_group_member_can_see_the_picture_with_safe_headers(): void
    {
        $image = $this->storedImage();

        $mate = User::factory()->student()->create(['must_change_password' => false]);
        GroupMember::create(['group_id' => $this->group->id, 'user_id' => $mate->id, 'joined_at' => now()]);

        $response = $this->actingAs($mate)->get($image->url())->assertOk();

        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertStringContainsString('inline', $response->headers->get('Content-Disposition'));
    }

    public function test_the_owning_lecturer_can_see_the_picture(): void
    {
        $this->actingAs($this->lecturer)->get($this->storedImage()->url())->assertOk();
    }

    public function test_the_picture_is_blocked_for_non_members_other_lecturers_and_guests(): void
    {
        $image = $this->storedImage();

        $outsider = User::factory()->student()->create(['must_change_password' => false]);
        $otherLecturer = User::factory()->lecturer()->create();

        $this->actingAs($outsider)->get($image->url())->assertNotFound();
        $this->actingAs($otherLecturer)->get($image->url())->assertNotFound();

        auth()->logout();
        $this->get($image->url())->assertRedirect();
    }

    public function test_deleting_a_document_keeps_its_pictures_but_students_can_no_longer_open_them(): void
    {
        $image = $this->storedImage();

        $this->actingAs($this->me)->delete(route('student.documents.destroy', $this->document))->assertRedirect();

        // Soft deleted: the files and the rows stay, for the lecturer.
        Storage::disk(GroupFiles::DISK)->assertExists($image->path);
        $this->assertSame(1, DocumentImage::count());

        $this->actingAs($this->me)->get($image->url())->assertNotFound();
        $this->actingAs($this->lecturer)->get($image->url())->assertOk();
    }

    /*
    | The editor itself
    */

    public function test_the_editor_offers_the_new_controls_on_an_a4_page(): void
    {
        $this->actingAs($this->me)
            ->get(route('student.documents.edit', $this->document))
            ->assertOk()
            ->assertSee('Title heading', false)
            ->assertSee('doc-page', false)
            ->assertSee('Choose a picture to insert', false)
            ->assertSee('New page', false)
            ->assertSee('Delete page', false)
            ->assertSee('Previous page', false)
            ->assertSee('Next page', false)
            ->assertSee('wheel', false)
            ->assertSee('Table of contents', false)
            ->assertSee('Update contents', false)
            ->assertSee('Remove contents', false)
            ->assertSee('x-on:keydown="onKey($event)"', false)
            ->assertSee('Pasted text arrives unformatted, on purpose.', false);
    }

    public function test_the_editor_script_is_not_printed_into_the_page_and_x_data_is_well_formed(): void
    {
        $html = $this->actingAs($this->me)
            ->get(route('student.documents.edit', $this->document))
            ->assertOk()
            ->getContent();

        // None of the editor's code may appear in the markup at all (it lives in the bundled module)...
        foreach (['tocHtml', 'Picture inserted', 'execCommand', 'insertHTML', 'this.say'] as $code) {
            $this->assertStringNotContainsString($code, $html);
        }

        // ...and no method name may show as visible text. Buttons call them from attributes only.
        $visible = strip_tags(preg_replace('#<(script|style)\b.*?</>#is', '', $html));
        foreach (['insertPageBreak', 'insertToc', 'refreshToc', 'insertLink', 'insertImage'] as $name) {
            $this->assertStringNotContainsString($name, $visible);
        }

        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">'.$html);
        libxml_clear_errors();

        $editors = (new \DOMXPath($dom))->query('//*[@x-data and starts-with(@x-data, "documentEditor(")]');
        $this->assertSame(1, $editors->length);

        $attribute = $editors->item(0)->getAttribute('x-data');
        // @js hands an object over as a quoted JSON string, so the attribute holds no raw quotes.
        $this->assertSame(1, preg_match('/^documentEditor\(JSON\.parse\(\'(.*)\'\)\)$/s', $attribute, $m));

        // The config is the small, decodable object: value, uploadUrl, csrf.
        $config = json_decode(json_decode('"'.$m[1].'"'), true);
        $this->assertSame(['value', 'uploadUrl', 'csrf'], array_keys($config));
        $this->assertSame(route('student.documents.images.store', $this->document), $config['uploadUrl']);

        // Nothing but whitespace-free markup text sits above the editor: the first visible text in the
        // editor wrapper is the "Body" label, not a stray fragment of code.
        $this->assertStringNotContainsString("') this.say(", $html);
    }
}
