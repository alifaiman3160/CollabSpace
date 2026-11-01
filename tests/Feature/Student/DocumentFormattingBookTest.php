<?php

namespace Tests\Feature\Student;

use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\Subject;
use App\Models\User;
use App\Support\DocumentPrintBody;
use App\Support\DocumentSanitizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Selection-aware formatting (what the sanitiser keeps and the print view draws) and the open-book look.
 * The reading and applying of formatting itself is pure JavaScript, tested with Node in tests/js.
 */
class DocumentFormattingBookTest extends TestCase
{
    use RefreshDatabase;

    protected function clean(string $html): string
    {
        return (new DocumentSanitizer())->clean($html);
    }

    /*
    | The sanitiser
    */

    public function test_the_new_paragraph_styles_are_kept(): void
    {
        $clean = $this->clean('<p style="line-height: 1.3; margin-top: 12pt; margin-bottom: 6pt; margin-left: 2cm; text-indent: 1.25cm; text-align: justify">x</p>');

        foreach (['line-height: 1.3', 'margin-top: 12pt', 'margin-bottom: 6pt', 'margin-left: 2cm', 'text-indent: 1.25cm', 'text-align: justify'] as $kept) {
            $this->assertStringContainsString($kept, $clean);
        }
    }

    public function test_values_outside_the_allowed_range_or_unit_are_stripped(): void
    {
        foreach ([
            'line-height: 3.5', 'line-height: 0.5', 'line-height: 2px', 'line-height: calc(2)',
            'margin-top: 100pt', 'margin-top: 12px', 'margin-bottom: -4pt', 'margin-left: 30cm', 'margin-left: 5em',
            'text-indent: 9cm', 'text-indent: 10px', 'margin-right: 2cm', 'position: fixed', 'margin-top: 6pt; background-image: url(//evil.test/x)',
        ] as $style) {
            $clean = $this->clean("<p style=\"{$style}\">x</p>");

            $this->assertStringNotContainsString('url(', $clean, $style);
            $this->assertStringNotContainsString('evil', $clean, $style);
            $this->assertStringNotContainsString('position', $clean, $style);
            $this->assertStringNotContainsString('margin-right', $clean, $style);
            $this->assertDoesNotMatchRegularExpression('/line-height: (3\.5|0\.5|2px)/', $clean, $style);
            $this->assertStringNotContainsString('100pt', $clean, $style);
            $this->assertStringNotContainsString('30cm', $clean, $style);
            $this->assertStringNotContainsString('9cm', $clean, $style);
            $this->assertStringNotContainsString('12px', $clean, $style);
        }
    }

    public function test_superscript_and_subscript_survive_and_carry_nothing_else(): void
    {
        $clean = $this->clean('<p>H<sub onclick="x()">2</sub>O and E=mc<sup style="color:red">2</sup></p>');

        $this->assertStringContainsString('<sub>2</sub>', $clean);
        $this->assertStringContainsString('<sup>2</sup>', $clean);
        $this->assertStringNotContainsString('onclick', $clean);
    }

    /*
    | The print view
    */

    public function test_the_print_view_renders_spacing_indent_first_line_and_script_positions(): void
    {
        $body = app(DocumentPrintBody::class)->render(
            '<p style="line-height: 2; margin-top: 12pt; margin-bottom: 18pt; margin-left: 2cm; text-indent: 1.25cm">Report</p><p>H<sub>2</sub>O m<sup>2</sup></p>'
        );

        foreach (['line-height: 2', 'margin-top: 12pt', 'margin-bottom: 18pt', 'margin-left: 2cm', 'text-indent: 1.25cm', '<sub>2</sub>', '<sup>2</sup>'] as $needle) {
            $this->assertStringContainsString($needle, $body);
        }

        $page = view('documents.print', ['title' => 'Report', 'body' => $body])->render();

        $this->assertStringContainsString('sup { vertical-align: super', $page);
        $this->assertStringContainsString('sub { vertical-align: sub', $page);
    }

    public function test_the_pdf_is_made_with_the_new_formatting(): void
    {
        $lecturer = User::factory()->lecturer()->create();
        $subject = Subject::create(['lecturer_id' => $lecturer->id, 'code' => 'SECJ3104', 'name' => 'App Dev', 'group_size' => 4]);
        $group = Group::create(['subject_id' => $subject->id, 'name' => 'G1', 'group_size' => 4]);
        $me = User::factory()->student()->create(['must_change_password' => false]);
        GroupMember::create(['group_id' => $group->id, 'user_id' => $me->id, 'joined_at' => now()]);

        $document = Document::create([
            'group_id' => $group->id, 'created_by' => $me->id, 'last_edited_by' => $me->id, 'title' => 'T',
            'content' => '<p style="line-height: 2; text-indent: 1.25cm; margin-left: 1cm">Body H<sub>2</sub>O m<sup>2</sup></p>',
        ]);
        DocumentVersion::create(['document_id' => $document->id, 'edited_by' => $me->id, 'version_no' => 1, 'content' => $document->content]);

        $response = $this->actingAs($me)->get(route('student.documents.pdf', $document))->assertOk();

        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    /*
    | The editor
    */

    protected function editorPage(): string
    {
        $lecturer = User::factory()->lecturer()->create();
        $subject = Subject::create(['lecturer_id' => $lecturer->id, 'code' => 'SECJ3104', 'name' => 'App Dev', 'group_size' => 4]);
        $group = Group::create(['subject_id' => $subject->id, 'name' => 'G1', 'group_size' => 4]);
        $me = User::factory()->student()->create(['must_change_password' => false]);
        GroupMember::create(['group_id' => $group->id, 'user_id' => $me->id, 'joined_at' => now()]);
        $document = Document::create(['group_id' => $group->id, 'created_by' => $me->id, 'last_edited_by' => $me->id, 'title' => 'T', 'content' => '<p>Hi</p>']);

        return $this->actingAs($me)->get(route('student.documents.edit', $document))->assertOk()->getContent();
    }

    public function test_the_toolbar_offers_the_selection_aware_controls_and_keeps_the_selection(): void
    {
        $html = $this->editorPage();

        foreach ([
            'Superscript', 'Subscript', 'Line spacing', 'Space before paragraph', 'Space after paragraph',
            'Decrease indent', 'Increase indent', 'First-line indent', 'Custom…', 'Mixed', 'Choose spacing', 'Compact, no gaps',
        ] as $control) {
            $this->assertStringContainsString($control, $html);
        }

        // Buttons do not take the focus, so the selection survives a click; the controls show the current value.
        $this->assertSame(1, substr_count($html, 'x-on:mousedown="keepSelection($event)"'), 'One ribbon, one handler.');
        $this->assertStringContainsString('x-bind:value="fmt.spacing"', $html);
        $this->assertStringContainsString('x-bind:value="fmt.font"', $html);
        $this->assertStringContainsString('x-bind:aria-pressed="fmt.bold"', $html);
    }

    public function test_the_module_has_the_word_shortcuts_and_does_not_break_the_browsers_own(): void
    {
        $js = file_get_contents(resource_path('js/document-editor.js'));

        foreach (["Digit7", "Digit8", "justifyFull", "'formatBlock'", "event.key === ']'"] as $needle) {
            $this->assertStringContainsString($needle, $js);
        }

        // Only taken with the cursor in the sheet (the handler lives on it), and Ctrl+B, I and U stay native.
        $this->assertStringNotContainsString("exec('bold')", $js);
    }

    public function test_the_book_look_has_two_read_only_miniatures_and_a_new_sheet(): void
    {
        $html = $this->editorPage();

        $this->assertSame(2, substr_count($html, 'class="doc-peek-inner doc-body"'));
        $this->assertSame(2, preg_match_all('/<div class="doc-peek-inner doc-body" inert tabindex="-1"/', $html));
        $this->assertSame(1, substr_count($html, 'data-peek="previous"'));
        $this->assertSame(1, substr_count($html, 'data-peek="next"'));
        $this->assertSame(1, substr_count($html, 'data-peek="new"'));

        // Hidden from assistive technology on both sides.
        $this->assertSame(2, substr_count($html, 'class="doc-side doc-side-'));
        $this->assertSame(2, preg_match_all('/class="doc-side doc-side-(left|right)" aria-hidden="true"/', $html));

        // Edge layers are plain CSS, drawn from the plan (at most 3 a side): never one element per page.
        $this->assertStringContainsString('x-for="n in plan.edgesLeft"', $html);
        $this->assertStringContainsString('x-for="n in plan.edgesRight"', $html);
    }

    public function test_the_book_look_is_clipped_and_calm(): void
    {
        $css = file_get_contents(resource_path('css/app.css'));

        preg_match('/\.doc-viewport\s*\{[^}]*\}/', $css, $viewport);
        $this->assertStringContainsString('overflow: hidden', $viewport[0]);
        $this->assertStringContainsString('grid-template-columns: 90px minmax(0, 21cm) 90px', $viewport[0]);

        $book = substr($css, strpos($css, '.doc-side {'), 4000);
        $this->assertStringNotContainsString('dashed', $book);
        $this->assertStringNotContainsString('blur', $book);
    }
    public function test_a_plain_text_document_keeps_its_line_breaks_and_saves_with_no_new_version(): void
    {
        $lecturer = User::factory()->lecturer()->create();
        $subject = Subject::create(['lecturer_id' => $lecturer->id, 'code' => 'SECJ3104', 'name' => 'App Dev', 'group_size' => 4]);
        $group = Group::create(['subject_id' => $subject->id, 'name' => 'G1', 'group_size' => 4]);
        $me = User::factory()->student()->create(['must_change_password' => false]);
        GroupMember::create(['group_id' => $group->id, 'user_id' => $me->id, 'joined_at' => now()]);

        $plain = "Functional Requirements\n\nFR1  First.\nFR2  Second.\nFR3  Third.\n";
        $document = Document::create(['group_id' => $group->id, 'created_by' => $me->id, 'last_edited_by' => $me->id, 'title' => 'T', 'content' => $plain]);
        DocumentVersion::create(['document_id' => $document->id, 'edited_by' => $me->id, 'version_no' => 1, 'content' => $plain]);

        // Opened as lines separated by line breaks, not as one run-on paragraph. The editor keeps each line a line.
        $html = $this->actingAs($me)->get(route('student.documents.edit', $document))->assertOk()->getContent();
        $this->assertGreaterThanOrEqual(5, substr_count($html, '&lt;br /&gt;') + substr_count($html, '\u003Cbr'));

        // Sent back exactly as it was opened (nothing edited), it makes no new version.
        $this->actingAs($me)->put(route('student.documents.update', $document), ['title' => 'T', 'content' => $plain]);
        $this->assertSame(1, $document->versions()->count());

        // The editor turns line breaks into paragraphs without adding any gap between lines.
        $js = file_get_contents(resource_path('js/document-editor.js'));
        $this->assertStringContainsString("style.marginBottom = '0pt'", $js);
        $this->assertStringContainsString("node.nodeName === 'BR'", $js);
        $this->assertStringContainsString('if (! this.touched) return', $js);
    }
    public function test_every_control_is_rendered_once_with_the_page_controls_and_counts_under_the_sheet_not_in_the_ribbon(): void
    {
        $html = $this->editorPage();

        foreach (['aria-label="Undo"', 'aria-label="Redo"', 'aria-label="Bold"', 'aria-label="Previous page"', 'aria-label="Next page"',
            'aria-label="Pages"', 'data-counts', 'x-text="words"', 'x-text="characters"', 'Pasted text arrives unformatted, on purpose.'] as $needle) {
            $this->assertSame(1, substr_count($html, $needle), "{$needle} should appear once.");
        }

        foreach (['newPage()', 'deletePage()', 'insertTable()', 'insertLink()', 'toggleFirstLine()', 'removeToc()'] as $call) {
            $this->assertSame(1, preg_match_all('/<button[^>]*x-on:click="'.preg_quote($call, '/').'"/', $html), "{$call} once.");
        }

        foreach (['Font', 'Paragraph', 'Spacing', 'Styles', 'Insert'] as $group) {
            $this->assertSame(1, preg_match_all('/<span class="text-center text-\[10px\][^"]*">'.$group.'<\/span>/', $html), "{$group} group label.");
        }

        // The ribbon is everything above the sheet. The page controls and the counts are not in it, and are under the sheet.
        $ribbonStart = strpos($html, 'role="toolbar"');
        $stage = strpos($html, 'class="doc-stage"');
        $ribbon = substr($html, $ribbonStart, $stage - $ribbonStart);

        foreach (['aria-label="Pages"', 'data-counts', 'Previous page', 'Next page', 'New page', 'Delete page', 'x-text="words"', 'Pasted text'] as $absent) {
            $this->assertStringNotContainsString($absent, $ribbon, "{$absent} must not be in the ribbon.");
        }

        foreach (['aria-label="Pages"', 'data-counts'] as $below) {
            $this->assertGreaterThan(strpos($html, 'data-peek="new"'), strpos($html, $below), "{$below} is under the sheet.");
        }

        $this->assertSame(1, substr_count($html, 'role="toolbar"'));
        $this->assertStringNotContainsString('aria-label="Paragraph formatting"', $html);
        $this->assertStringNotContainsString('aria-label="Text formatting"', $html);
    }

    public function test_the_page_controls_are_centred_under_the_sheet_and_the_ribbon_spreads_evenly(): void
    {
        $html = $this->editorPage();
        $css = file_get_contents(resource_path('css/app.css'));

        // The controls sit in the sheet's own column of the same grid as the stage, and outside what slides.
        $this->assertStringContainsString('class="doc-controls sticky bottom-0 z-10"', $html);
        $this->assertStringContainsString('class="relative col-start-2 flex', $html);
        $this->assertSame(2, substr_count($css, 'grid-template-columns: 90px minmax(0, 21cm) 90px'), 'Stage and controls share the columns.');
        $this->assertGreaterThan(strpos($html, 'class="doc-stage"'), strpos($html, 'class="doc-controls'));

        // Quiet Delete page: muted, red only on hover; the page indicator opens the jump list.
        $this->assertStringContainsString('hover:!text-danger-text', $html);
        $this->assertStringContainsString('aria-label="Jump to a page"', $html);

        // The groups share the width: they grow, and the ribbon spreads them.
        $this->assertStringContainsString('justify-between gap-y-2', $html);
        $this->assertSame(1, preg_match_all('/class="flex grow basis-auto flex-col justify-between gap-1 border-l border-surface-line px-3" role="group" aria-label="Font"/', $html));
    }
    public function test_the_ribbon_is_sticky_without_making_a_second_scroller(): void
    {
        $html = $this->editorPage();

        $this->assertStringContainsString('sticky top-0 z-20', $html);
        $this->assertStringContainsString('overflow-clip rounded-[16px]', $html);
        // overflow-hidden on the card would stop it sticking and make a scroll container.
        $this->assertStringNotContainsString('overflow-hidden rounded-[16px]', $html);
        $this->assertMatchesRegularExpression('/html\s*\{\s*scrollbar-gutter:\s*stable;/', file_get_contents(resource_path('css/app.css')));
    }

    public function test_the_icon_only_buttons_name_their_shortcut(): void
    {
        $html = $this->editorPage();

        foreach (['Undo (Ctrl + Z)', 'Redo (Ctrl + Y)', 'Bold (Ctrl + B)', 'Align left (Ctrl + L)', 'Bulleted list (Ctrl + Shift + 8)', 'Previous page (Alt + Left)', 'Next page (Alt + Right)'] as $title) {
            $this->assertStringContainsString('data-tooltip="'.$title.'"', $html);
        }
    }
}
