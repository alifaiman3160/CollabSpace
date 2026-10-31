<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\Subject;
use App\Models\User;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The document lists (the students' and the lecturer's) are one stack: the toolbar, then a full-width grid. The toolbar
 * once lost its closing tag, which put the whole grid inside its row and squeezed the cards into one narrow column.
 */
class DocumentListLayoutTest extends TestCase
{
    use RefreshDatabase;

    protected User $lecturer;

    protected User $student;

    protected Group $group;

    protected function setUp(): void
    {
        parent::setUp();

        $this->lecturer = User::factory()->lecturer()->create(['must_change_password' => false]);
        $subject = Subject::create(['lecturer_id' => $this->lecturer->id, 'code' => 'BCS3323', 'name' => 'SE', 'group_size' => 4]);
        $this->group = Group::create(['subject_id' => $subject->id, 'name' => 'Group SE1', 'group_size' => 4]);
        $this->student = User::factory()->student()->create(['must_change_password' => false]);
        GroupMember::create(['group_id' => $this->group->id, 'user_id' => $this->student->id, 'joined_at' => now()]);

        foreach (range(1, 3) as $i) {
            $d = Document::create(['group_id' => $this->group->id, 'created_by' => $this->student->id, 'last_edited_by' => $this->student->id, 'title' => "Doc {$i}", 'content' => '<p>Text</p>']);
            DocumentVersion::create(['document_id' => $d->id, 'edited_by' => $this->student->id, 'version_no' => 1, 'content' => '<p>Text</p>']);
        }
    }

    protected function pages(): array
    {
        return [
            'lecturer' => $this->actingAs($this->lecturer)->get(route('lecturer.progress.documents', $this->group))->assertOk()->getContent(),
            'student' => $this->actingAs($this->student)->get(route('student.documents.group', $this->group))->assertOk()->getContent(),
        ];
    }

    protected function xpath(string $html): DOMXPath
    {
        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">'.$html);
        libxml_clear_errors();

        return new DOMXPath($dom);
    }

    public function test_the_toolbar_is_above_the_grid_in_one_stack_and_the_grid_is_not_inside_it(): void
    {
        foreach ($this->pages() as $who => $html) {
            $x = $this->xpath($html);

            $this->assertSame(1, $x->query('//*[@data-documents-toolbar]')->length, $who);
            $this->assertSame(1, $x->query('//*[@data-document-list]')->length, $who);

            // The toolbar and the grid are siblings in the list's own vertical stack...
            $this->assertSame(1, $x->query('//*[@data-document-list]/*[@data-documents-toolbar]')->length, "{$who}: the toolbar is a child of the list");
            $this->assertSame(1, $x->query('//*[@data-document-list]/*[@data-document-grid]')->length, "{$who}: the grid is a child of the list");

            // ...and the grid is never inside the toolbar's row (the bug).
            $this->assertSame(0, $x->query('//*[@data-documents-toolbar]//*[@data-document-grid]')->length, "{$who}: the grid is not nested in the toolbar");
            $this->assertSame(0, $x->query('//*[@data-documents-toolbar]//*[@data-doc]')->length, "{$who}: no card is inside the toolbar");

            $this->assertTrue(strpos($html, 'data-documents-toolbar') < strpos($html, 'data-document-grid'), $who);
            $this->assertStringContainsString('flex w-full flex-col gap-[18px]', $html);
        }
    }

    public function test_the_grid_has_the_full_width_auto_fill_classes_and_every_card_is_a_direct_child(): void
    {
        foreach ($this->pages() as $who => $html) {
            $x = $this->xpath($html);

            $grid = $x->query('//*[@data-document-grid]')->item(0);
            $this->assertStringContainsString('grid-cols-[repeat(auto-fill,minmax(18.75rem,1fr))]', $grid->getAttribute('class'), $who);
            $this->assertContains('grid', explode(' ', $grid->getAttribute('class')));

            $this->assertSame(3, $x->query('//*[@data-document-grid]/article[@data-doc]')->length, "{$who}: three cards, each a direct child of the grid");
        }
    }

    public function test_the_toolbar_has_search_sort_and_the_count_in_one_row(): void
    {
        foreach ($this->pages() as $who => $html) {
            $x = $this->xpath($html);

            $row = '//*[@data-documents-toolbar]';
            $this->assertSame(1, $x->query($row.'//input[@id="document-search"]')->length, $who);
            $this->assertSame(1, $x->query($row.'//select[@id="document-sort"]')->length, $who);
            $this->assertSame(1, $x->query($row.'//*[@data-documents-count]')->length, $who);
            $this->assertStringContainsString('ml-auto', $x->query($row.'//*[@data-documents-count]')->item(0)->getAttribute('class'), 'The count sits at the far right.');
        }
    }

    public function test_the_components_close_everything_they_open(): void
    {
        foreach (['document-toolbar', 'document-no-match', 'document-card', 'document-grid', 'document-list'] as $component) {
            $source = file_get_contents(resource_path("views/components/{$component}.blade.php"));
            $source = preg_replace('/\{\{--.*?--\}\}/s', '', $source);

            $this->assertSame(preg_match_all('/<div\b/', $source), preg_match_all('/<\/div>/', $source), "{$component}: every <div> is closed");
            $this->assertSame(preg_match_all('/<section\b/', $source), preg_match_all('/<\/section>/', $source), "{$component}: every <section> is closed");
        }
    }

    public function test_the_two_pages_share_one_list_component(): void
    {
        foreach (['student/documents/index', 'lecturer/documents/index'] as $view) {
            $source = file_get_contents(resource_path("views/{$view}.blade.php"));

            $this->assertStringContainsString('<x-document-list', $source, $view);
            $this->assertStringNotContainsString('grid-cols-[repeat(auto-fill,minmax(18.75rem,1fr))]', $source, "{$view} has no copy of the grid classes");
        }
    }

    public function test_tailwind_scans_the_views_so_the_new_classes_are_built(): void
    {
        $css = file_get_contents(resource_path('css/app.css'));

        $this->assertStringContainsString("@source '../views';", $css);
        $this->assertStringContainsString("@source '../js';", $css);
        $this->assertDirectoryExists(resource_path('views/components'));
    }

    public function test_the_deleted_section_uses_the_same_grid_and_the_lecturer_page_keeps_its_parts(): void
    {
        $deleted = Document::create(['group_id' => $this->group->id, 'created_by' => $this->student->id, 'last_edited_by' => $this->student->id, 'title' => 'Gone', 'content' => '<p>x</p>']);
        $deleted->forceFill(['deleted_by' => $this->student->id])->save();
        $deleted->delete();

        $html = $this->actingAs($this->lecturer)->get(route('lecturer.progress.documents', $this->group))->getContent();
        $x = $this->xpath($html);

        $this->assertSame(1, $x->query('//*[@data-read-only-note]')->length);
        $this->assertSame(1, $x->query('//*[@data-deleted-documents-section]//*[@data-deleted-grid]')->length);
        $this->assertStringContainsString('grid-cols-[repeat(auto-fill,minmax(18.75rem,1fr))]', $x->query('//*[@data-deleted-grid]')->item(0)->getAttribute('class'));
        $this->assertSame('Monitor progress', $this->currentNav($html));
        $this->assertStringContainsString('aria-label="Breadcrumb"', $html);
    }

    protected function currentNav(string $html): ?string
    {
        preg_match('/<nav[^>]*aria-label="Main".*?<\/nav>/s', $html, $nav);
        preg_match_all('/<a[^>]*aria-current="page"[^>]*>(.*?)<\/a>/s', $nav[0], $m);

        return isset($m[1][0]) ? trim(preg_replace('/\s+/', ' ', strip_tags($m[1][0]))) : null;
    }
}
