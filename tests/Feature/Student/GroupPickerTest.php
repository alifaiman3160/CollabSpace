<?php

namespace Tests\Feature\Student;

use App\Models\Document;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\Subject;
use App\Models\Task;
use App\Models\User;
use App\Support\SubjectPalette;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * One group picker, <x-group-picker>, on every student page that has one: small cards in the subject's soft colour.
 */
class GroupPickerTest extends TestCase
{
    use RefreshDatabase;

    protected User $student;

    protected Group $one;

    protected Group $two;

    protected function setUp(): void
    {
        parent::setUp();

        $lecturer = User::factory()->lecturer()->create();
        $this->student = User::factory()->student()->create(['must_change_password' => false]);

        $this->one = $this->group($lecturer, 'SECJ3104', 'Group A');
        $this->two = $this->group($lecturer, 'SECR1013', 'Group B');
    }

    protected function group(User $lecturer, string $code, string $name): Group
    {
        $subject = Subject::create(['lecturer_id' => $lecturer->id, 'code' => $code, 'name' => "Name of {$code}", 'group_size' => 4]);
        $group = Group::create(['subject_id' => $subject->id, 'name' => $name, 'group_size' => 4]);
        GroupMember::create(['group_id' => $group->id, 'user_id' => $this->student->id, 'joined_at' => now()]);

        return $group;
    }

    protected function cards(string $html): array
    {
        preg_match_all('/<(?:a|button)\b[^>]*data-group-card[^>]*>/', $html, $m);

        return $m[0];
    }

    public function test_each_card_wears_its_subjects_colour_and_only_the_selected_one_is_marked(): void
    {
        $html = $this->actingAs($this->student)->get(route('student.documents.group', $this->one))->assertOk()->getContent();
        $cards = $this->cards($html);

        $this->assertCount(2, $cards);

        foreach ([$this->one, $this->two] as $i => $group) {
            $colour = SubjectPalette::for($group->subject_id);

            $this->assertStringContainsString('data-palette="'.$colour['name'].'"', $cards[$i]);
            $this->assertStringContainsString('--pal-accent: '.$colour['accent'], $cards[$i]);
        }

        $this->assertStringContainsString('aria-current="page"', $cards[0]);
        $this->assertStringNotContainsString('aria-current', $cards[1]);
        $this->assertCount(1, array_filter($cards, fn ($card) => str_contains($card, 'aria-current')), 'Only one card is the current one.');
    }

    public function test_the_second_line_shows_only_when_a_page_gives_one(): void
    {
        Document::create(['group_id' => $this->one->id, 'created_by' => $this->student->id, 'last_edited_by' => $this->student->id, 'title' => 'D', 'content' => '<p>x</p>']);
        Task::create(['group_id' => $this->two->id, 'created_by' => $this->student->id, 'assigned_to' => $this->student->id, 'title' => 'T', 'status' => 'todo']);

        $documents = $this->actingAs($this->student)->get(route('student.documents.group', $this->one))->getContent();
        $this->assertStringContainsString('1 document', $documents);
        $this->assertStringContainsString('0 documents', $documents);

        $board = $this->actingAs($this->student)->get(route('student.board.show', $this->one))->getContent();
        $this->assertStringContainsString('1 open task', $board);

        $workspace = $this->actingAs($this->student)->get(route('student.workspace.show', $this->one))->getContent();
        $this->assertStringContainsString('1 member', $workspace);

        // A page that provides nothing gets no line at all.
        $html = view('components.group-picker', ['groups' => $this->student->groupMemberships()->with('group.subject')->get()->pluck('group'), 'current' => $this->one, 'route' => 'student.board.show'])->render();
        $this->assertStringNotContainsString('data-group-secondary', $html);
    }

    public function test_the_three_pages_share_the_component_and_the_old_markup_is_gone(): void
    {
        foreach (['board', 'workspace', 'documents/index'] as $view) {
            $source = file_get_contents(resource_path("views/student/{$view}.blade.php"));

            $this->assertStringContainsString('<x-group-picker', $source, $view);
            $this->assertStringNotContainsString('group-switcher', $source, $view);
            $this->assertStringNotContainsString('bg-surface-muted p-[3px]', $source, $view);
        }

        $this->assertFileDoesNotExist(resource_path('views/components/group-switcher.blade.php'));

        foreach ([route('student.board.show', $this->one), route('student.workspace.show', $this->one), route('student.documents.group', $this->one)] as $url) {
            $html = $this->actingAs($this->student)->get($url)->assertOk()->getContent();

            $this->assertSame(1, substr_count($html, 'data-group-picker'));
            $this->assertSame(1, substr_count($html, '>Your groups</span>'));
        }
    }

    public function test_the_group_chat_keeps_its_own_list_and_other_groups_are_still_refused(): void
    {
        $this->actingAs($this->student)->get(route('student.chat.show', $this->one))->assertOk()->assertDontSee('data-group-picker', false);

        $stranger = $this->group(User::factory()->lecturer()->create(), 'SECV2113', 'Group Z');
        GroupMember::where('group_id', $stranger->id)->delete();

        foreach (['student.board.show', 'student.workspace.show', 'student.documents.group'] as $route) {
            $this->actingAs($this->student)->get(route($route, $stranger))->assertNotFound();
        }
    }

    public function test_the_cards_are_keyboard_friendly_and_calm(): void
    {
        $html = $this->actingAs($this->student)->get(route('student.board.show', $this->one))->getContent();

        $this->assertStringContainsString('x-data="groupPicker"', $html);
        $this->assertStringContainsString('x-on:keydown="move($event)"', $html);

        $css = file_get_contents(resource_path('css/app.css'));
        $this->assertMatchesRegularExpression('/\.cs-group-card\[aria-current=\'page\'\][^{]*\{[^}]*translateY\(-2px\)/s', $css);
        $this->assertMatchesRegularExpression('/prefers-reduced-motion: reduce\)\s*\{\s*\.cs-group-card \{ transition: none; \}/', $css);
        $this->assertStringNotContainsString('blur(', substr($css, strpos($css, '.cs-group-card')));
    }
}