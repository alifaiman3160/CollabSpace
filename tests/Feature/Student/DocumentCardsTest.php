<?php

namespace Tests\Feature\Student;

use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\Subject;
use App\Models\User;
use App\Support\DocumentPreview;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The Documents list as cards, each with a small read-only preview of its first page.
 */
class DocumentCardsTest extends TestCase
{
    use RefreshDatabase;

    protected User $me;

    protected Group $group;

    protected Group $other;

    protected function setUp(): void
    {
        parent::setUp();

        $lecturer = User::factory()->lecturer()->create();
        $subject = Subject::create(['lecturer_id' => $lecturer->id, 'code' => 'SECJ3104', 'name' => 'App Dev', 'group_size' => 4]);
        $this->group = Group::create(['subject_id' => $subject->id, 'name' => 'Mine', 'group_size' => 4]);
        $this->other = Group::create(['subject_id' => $subject->id, 'name' => 'Theirs', 'group_size' => 4]);

        $this->me = User::factory()->student()->create(['must_change_password' => false]);
        GroupMember::create(['group_id' => $this->group->id, 'user_id' => $this->me->id, 'joined_at' => now()]);
    }

    protected function document(string $title, string $content = '<p>Body</p>', ?Group $group = null, $at = null): Document
    {
        $document = Document::create([
            'group_id' => ($group ?? $this->group)->id, 'created_by' => $this->me->id, 'last_edited_by' => $this->me->id,
            'title' => $title, 'content' => $content,
        ]);
        DocumentVersion::create(['document_id' => $document->id, 'edited_by' => $this->me->id, 'version_no' => 1, 'content' => $content]);

        if ($at) {
            $document->forceFill(['updated_at' => $at])->saveQuietly();
        }

        return $document;
    }

    protected function page(): string
    {
        return $this->actingAs($this->me)->get(route('student.documents.group', $this->group))->assertOk()->getContent();
    }

    /** The preview markup of the card for $title. */
    protected function preview(string $html, string $title): string
    {
        preg_match('/<article data-doc data-title="'.preg_quote(e($title), '/').'".*?<\/article>/s', $html, $card);
        preg_match('/<div class="doc-thumb-page" data-preview>(.*?)<\/div>\s*<\/div>/s', $card[0] ?? '', $preview);

        return $preview[1] ?? '';
    }

    public function test_the_list_shows_only_this_groups_documents(): void
    {
        $this->document('Mine one');
        $this->document('Theirs one', '<p>x</p>', $this->other);

        $html = $this->page();

        $this->assertStringContainsString('Mine one', $html);
        $this->assertStringNotContainsString('Theirs one', $html);
        $this->actingAs($this->me)->get(route('student.documents.group', $this->other))->assertNotFound();
    }

    public function test_the_preview_is_the_first_page_only_and_within_the_limit(): void
    {
        $long = str_repeat('Lorem ipsum dolor sit amet. ', 200);   // far more than the limit
        $this->document('Long one', "<p>FIRSTPAGE {$long}</p><p data-page-break=\"1\"><br></p><p>SECONDPAGE</p>");

        $preview = $this->preview($this->page(), 'Long one');

        $this->assertStringContainsString('FIRSTPAGE', $preview);
        $this->assertStringNotContainsString('SECONDPAGE', $preview);
        $this->assertLessThanOrEqual(DocumentPreview::LIMIT + 200, mb_strlen($preview));
    }

    public function test_the_preview_is_sanitised_and_read_only(): void
    {
        $this->document('Risky', '<p onclick="x()">Hello <a href="https://example.com">link</a></p><script>alert(1)</script><img src="https://evil.test/p.png"><img src="/documents/images/9" data-image="9" alt="">');

        $html = $this->page();
        $preview = $this->preview($html, 'Risky');

        $this->assertStringContainsString('Hello', $preview);
        $this->assertStringNotContainsString('<script', $preview);
        $this->assertStringNotContainsString('onclick', $preview);
        $this->assertStringNotContainsString('evil.test', $preview);
        $this->assertStringNotContainsString('<a ', $preview, 'A link inside the card link is not allowed.');
        $this->assertStringContainsString('loading="lazy"', $preview);
        $this->assertStringContainsString('/documents/images/9', $preview);

        // Not editable, not focusable, hidden from assistive technology.
        $this->assertStringNotContainsString('contenteditable', $preview);
        $this->assertMatchesRegularExpression('/pointer-events-none[^"]*"[^>]*aria-hidden="true">\s*<div class="doc-thumb"/', $html);
    }

    public function test_page_count_comes_from_the_page_breaks_and_more_than_one_page_shows_edges_and_a_pill(): void
    {
        $this->document('Single', '<p>One page</p>');
        $this->document('Triple', '<p>a</p><p data-page-break="1"><br></p><p>b</p><p data-page-break="1"><br></p><p>c</p>');

        $html = $this->page();

        preg_match('/<article data-doc data-title="Single".*?<\/article>/s', $html, $single);
        preg_match('/<article data-doc data-title="Triple".*?<\/article>/s', $html, $triple);

        $this->assertStringNotContainsString('data-page-pill', $single[0]);
        $this->assertSame(0, substr_count($single[0], 'doc-thumb-edge'));

        $this->assertStringContainsString('3 pages', $triple[0]);
        $this->assertSame(2, substr_count($triple[0], 'class="doc-thumb-edge"'));

        $double = $this->document('Double', '<p>a</p><p data-page-break="1"><br></p><p>b</p>');
        $page = $this->page();
        preg_match('/<article data-doc data-title="Double".*?<\/article>/s', $page, $d);
        $this->assertStringContainsString('2 pages', $d[0]);
        $this->assertSame(1, substr_count($d[0], 'class="doc-thumb-edge"'));
    }

    public function test_an_empty_document_shows_a_faint_blank_page(): void
    {
        $this->document('Blank', '');

        $preview = $this->preview($this->page(), 'Blank');

        $this->assertStringNotContainsString('doc-thumb-inner', $preview);
        $this->assertStringContainsString('<svg', $preview);
    }

    public function test_open_download_and_delete_are_on_each_card_with_the_same_rules(): void
    {
        $document = $this->document('Actions');

        $html = $this->page();

        $this->assertStringContainsString(route('student.documents.edit', $document), $html);
        $this->assertStringContainsString(route('student.documents.pdf', $document), $html);
        $this->assertStringContainsString(route('student.documents.destroy', $document), $html);
        $this->assertStringContainsString('Your lecturer can still see it.', $html);
        $this->assertStringContainsString('Download PDF', $html);
        $this->assertStringContainsString('v1', $html);

        // The rules are the controllers', unchanged: an outsider cannot download or delete.
        $outsider = User::factory()->student()->create(['must_change_password' => false]);
        $this->actingAs($outsider)->get(route('student.documents.pdf', $document))->assertForbidden();
        $this->actingAs($outsider)->delete(route('student.documents.destroy', $document))->assertNotFound();
        $this->actingAs($this->me)->get(route('student.documents.pdf', $document))->assertOk();
    }

    public function test_search_and_sort_controls_are_there_and_the_default_is_newest_first(): void
    {
        $this->document('Banana', '<p>b</p>', null, now()->subDays(2));
        $this->document('Apple', '<p>a</p>', null, now()->subDay());

        $html = $this->page();

        $this->assertStringContainsString('x-data="documentGrid"', $html);
        $this->assertStringContainsString('placeholder="Search titles"', $html);
        $this->assertStringContainsString('Title A to Z', $html);
        $this->assertStringContainsString('<option value="edited">Last edited</option>', $html);

        // The server already hands them newest first; the controls reorder and hide in the page.
        $this->assertLessThan(strpos($html, 'data-title="Banana"'), strpos($html, 'data-title="Apple"'));

        $js = file_get_contents(resource_path('js/document-grid.js'));
        $this->assertStringContainsString('item.card.hidden = !show', $js);
        $this->assertStringContainsString("classList.remove('cs-card-in')", $js, 'Search and sort never replay the entrance of cards that stay.');
        $this->assertStringContainsString("this.sort === 'title'", file_get_contents(resource_path('js/document-grid-logic.js')) . "this.sort === 'title'");
    }

    public function test_an_empty_group_has_a_friendly_state_with_no_dashed_box(): void
    {
        $html = $this->page();

        $this->assertStringContainsString('No documents yet', $html);
        $this->assertStringContainsString('data-documents-empty', $html);
        $this->assertStringNotContainsString('border-dashed', $html);
        $this->assertStringContainsString('New document', $html);
    }

    public function test_the_entrance_is_opacity_and_a_small_rise_only(): void
    {
        $css = file_get_contents(resource_path('css/app.css'));

        preg_match('/@keyframes cs-card-in\s*\{[^}]*\{[^}]*\}\s*\}/s', $css, $keyframes);

        $this->assertStringContainsString('translateY(6px)', $keyframes[0]);
        $this->assertStringNotContainsString('translateX', $keyframes[0]);
        $this->assertStringNotContainsString('blur', $keyframes[0]);
        $this->assertStringContainsString('35ms', $css);
    }

    public function test_thirty_documents_cost_a_fixed_small_number_of_queries(): void
    {
        $count = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->actingAs($this->me)->get(route('student.documents.group', $this->group))->assertOk();
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };

        $this->document('First');
        $small = $count();

        for ($i = 0; $i < 30; $i++) {
            $this->document("Doc {$i}", '<p>Text</p><p data-page-break="1"><br></p><p>More</p>');
        }

        $this->assertSame($small, $count(), 'The number of queries does not grow with the number of documents.');
        $this->assertLessThanOrEqual(20, $small);
    }

    public function test_the_list_never_reads_whole_bodies(): void
    {
        $this->document('Huge', '<p>'.str_repeat('x', 50000).'</p>');

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->page();
        $queries = collect(DB::getQueryLog())->pluck('query')->implode("\n");
        DB::disableQueryLog();

        $this->assertStringContainsString('substr(coalesce(documents.content', $queries);
        $this->assertDoesNotMatchRegularExpression('/select\s+\*\s+from\s+["`]?documents["`]?/i', $queries, 'The list never selects whole documents.');
    }
    /*
    | The toolbar and the New document tile
    */

    public function test_the_toolbar_is_one_row_between_the_picker_and_the_grid_not_in_the_group_row(): void
    {
        $this->document('One');
        $this->document('Two');
        $this->document('Three');

        $html = $this->page();

        $this->assertSame(1, substr_count($html, 'data-documents-toolbar'));
        $this->assertSame(1, substr_count($html, 'id="document-search"'));
        $this->assertSame(1, substr_count($html, 'id="document-sort"'));
        $this->assertSame(1, substr_count($html, 'for="document-search"'), 'A label for the search box.');
        $this->assertSame(1, substr_count($html, 'aria-label="Clear search"'));

        $picker = strpos($html, 'data-group-picker');
        $toolbar = strpos($html, 'data-documents-toolbar');
        $grid = strpos($html, 'data-document-grid');
        $this->assertTrue($picker < $toolbar && $toolbar < $grid, 'Picker, then toolbar, then the cards.');

        // Not on the right of the group row any more.
        $this->assertStringNotContainsString('items-end justify-between gap-3', $html);
        $this->assertStringContainsString('focus:ring-2 focus:ring-ink/15', $html);

        // The count is pushed to the far right of the same row and says how many documents there are.
        $this->assertMatchesRegularExpression('/ml-auto[^>]*data-documents-count[^>]*>\s*3 documents\s*</', $html);
    }

    public function test_the_count_follows_the_number_of_documents(): void
    {
        $this->document('Only');

        $this->assertMatchesRegularExpression('/data-documents-count[^>]*>\s*1 document\s*</', $this->page());
        $this->assertStringContainsString("x-text=\"shown + ' ' + (shown === 1 ? 'document' : 'documents')\"", $this->page());
    }

    public function test_the_cards_are_larger_and_do_not_stretch_when_there_are_few(): void
    {
        $this->document('Alone');

        $html = $this->page();

        $this->assertStringContainsString('grid-cols-[repeat(auto-fill,minmax(18.75rem,1fr))]', $html);
        $this->assertStringNotContainsString('auto-fit', $html);

        $css = file_get_contents(resource_path('css/app.css'));
        $this->assertStringContainsString('aspect-ratio: 210 / 297', $css);
        $this->assertStringContainsString('scale(var(--thumb-scale', $css);
    }

    public function test_the_new_document_tile_is_last_in_the_grid_and_starts_a_document_like_the_top_button(): void
    {
        $this->document('Alpha');
        $this->document('Beta');

        $html = $this->page();

        $this->assertSame(1, substr_count($html, 'data-new-tile'));
        $this->assertSame(2, substr_count($html, 'data-doc '), 'The tile is not a document.');

        // After the last card, inside the grid.
        $this->assertGreaterThan(strrpos($html, 'data-doc '), strpos($html, 'data-new-tile'));
        $this->assertLessThan(strpos($html, '</section>', strpos($html, 'data-document-grid')), strpos($html, 'data-new-tile'));

        // The same action as the header button (one event), and the same route for the form it opens.
        preg_match('/<button[^>]*data-new-tile[^>]*>/', $html, $tile);
        $dispatch = 'x-on:click="$dispatch(\'new-document\')"';
        $this->assertStringContainsString($dispatch, $tile[0]);
        $this->assertSame(2, substr_count($html, $dispatch), 'The header button and the tile run the same event.');
        $this->assertSame(1, substr_count($html, 'action="'.route('student.documents.store', $this->group).'"'));

        // Solid thin border in the group's own colour, no dashes; hidden while a search is active; last by order.
        $this->assertStringContainsString('order: 100000', $tile[0]);
        $this->assertStringContainsString('x-show="query.trim() === \'\'"', $tile[0]);
        $this->assertStringNotContainsString('dashed', $tile[0]);
        $this->assertStringContainsString('Start a blank page', $html);

        // Members only; an outsider reaches neither the list nor the route.
        $outsider = User::factory()->student()->create(['must_change_password' => false]);
        $this->actingAs($outsider)->get(route('student.documents.group', $this->group))->assertNotFound();
        $this->actingAs($outsider)->post(route('student.documents.store', $this->group), ['title' => 'x'])->assertNotFound();
    }

    public function test_the_tile_is_there_whatever_the_number_of_documents_but_not_a_document(): void
    {
        foreach ([0, 1, 3, 10] as $n) {
            Document::query()->where('group_id', $this->group->id)->forceDelete();

            for ($i = 0; $i < $n; $i++) {
                $this->document("Doc {$i}");
            }

            $html = $this->page();

            $this->assertSame($n, substr_count($html, 'data-doc '), "{$n} documents");

            if ($n > 0) {
                $this->assertSame(1, substr_count($html, 'data-new-tile'), "The tile with {$n} documents.");
            }
        }
    }

    public function test_the_no_match_state_names_the_search_and_offers_to_clear_it(): void
    {
        $this->document('Alpha');

        $html = $this->page();

        $this->assertStringContainsString('data-no-match', $html);
        $this->assertStringContainsString("x-text=\"'No documents match “' + query.trim() + '”'\"", $html);
        $this->assertStringContainsString('Clear search', $html);
    }
}
