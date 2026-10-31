<?php

namespace Tests\Feature\Student;

use App\Models\Group;
use App\Models\GroupMember;
use App\Models\Message;
use App\Models\Subject;
use App\Models\User;
use App\Support\SubjectPalette;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The two-pane messenger: groups on the left, the open conversation on the right. */
class ChatMessengerLayoutTest extends TestCase
{
    use RefreshDatabase;

    protected User $me;

    protected User $ben;

    protected Group $falcon;

    protected Group $owl;

    protected function setUp(): void
    {
        parent::setUp();

        $lecturer = User::factory()->lecturer()->create(['must_change_password' => false]);
        $one = Subject::create(['lecturer_id' => $lecturer->id, 'code' => 'SECJ3104', 'name' => 'App Dev', 'group_size' => 4]);
        $two = Subject::create(['lecturer_id' => $lecturer->id, 'code' => 'SECJ2013', 'name' => 'Data Structures', 'group_size' => 4]);
        $this->falcon = Group::create(['subject_id' => $one->id, 'name' => 'Group Falcon', 'group_size' => 4]);
        $this->owl = Group::create(['subject_id' => $two->id, 'name' => 'Night Owls', 'group_size' => 4]);

        $this->me = User::factory()->student()->create(['name' => 'Ana Rahim', 'must_change_password' => false]);
        $this->ben = User::factory()->student()->create(['name' => 'Ben Lee', 'must_change_password' => false]);

        foreach ([$this->falcon, $this->owl] as $g) {
            GroupMember::create(['group_id' => $g->id, 'user_id' => $this->me->id, 'joined_at' => now()]);
        }
        GroupMember::create(['group_id' => $this->falcon->id, 'user_id' => $this->ben->id, 'joined_at' => now()]);
    }

    protected function page(?Group $group = null): string
    {
        return $this->actingAs($this->me)->get(route('student.chat.show', $group ?? $this->falcon))->assertOk()->getContent();
    }

    public function test_the_page_is_one_card_with_a_group_list_and_a_conversation(): void
    {
        $html = $this->page();

        $this->assertStringContainsString('data-messenger', $html);
        $this->assertStringContainsString('max-w-[1400px]', $html);
        $this->assertStringContainsString('w-[320px]', $html);
        $this->assertStringContainsString('data-group-list', $html);
        $this->assertStringContainsString('data-chat-header', $html);
        $this->assertStringContainsString('Group chat', $html, 'The page title stays.');
        $this->assertStringContainsString('Group workspace', $html, 'The one workspace link lives in the chat header.');
        $this->assertSame(1, substr_count($html, 'href="'.route('student.workspace.show', $this->falcon).'"'), 'No second button up in the page header.');
        $this->assertSame(2, substr_count($html, 'data-group-row data-search'), 'One row per group.');
    }

    public function test_each_row_is_a_link_to_that_groups_chat_and_the_open_one_is_marked(): void
    {
        $html = $this->page();

        $this->assertStringContainsString('href="'.route('student.chat.show', $this->owl).'"', $html);
        preg_match('/<a href="[^"]*chat\/g\/'.$this->falcon->id.'"[^>]*aria-current="page"[^>]*>/s', $html, $current);
        $this->assertNotEmpty($current, 'The open group has aria-current.');
        $this->assertStringContainsString('bg-periwinkle-soft', $current[0]);
        $this->assertStringContainsString('aria-[current=page]:bg-periwinkle-soft', $current[0], 'The open row is tinted from its aria-current alone.');
        $this->assertSame(2, substr_count($html, 'data-row-bar'), 'Every row has the bar, so it can move from one to the next.');
        $this->assertSame(2, substr_count($html, 'group-aria-[current=page]:scale-y-100'), 'The bar shows through the same attribute.');
        $list = substr($html, strpos($html, 'aria-label="Groups"'), strpos($html, '</nav>', strpos($html, 'aria-label="Groups"')) - strpos($html, 'aria-label="Groups"'));
        $this->assertSame(1, preg_match_all('/\saria-current="page"/', $list), 'Only the open row is marked when the page arrives.');

        // The bug this guards: a class written by the server for the open row is never taken away when you
        // switch, so two rows looked open. Nothing about "open" may be written as a plain class.
        $this->assertDoesNotMatchRegularExpression('/class="[^"]*(?<![\w\]:-])(bg-periwinkle-soft|scale-y-100)(?![\w-])/', $html);
        $this->assertSame(0, substr_count($html, "'bg-periwinkle-soft'"));
        $this->assertStringContainsString('transition-colors duration-150', $html, 'The tint and the bar change smoothly.');
        $this->assertStringContainsString('hover:bg-surface-muted', $html);
    }

    public function test_a_row_shows_the_subject_colour_code_preview_and_time(): void
    {
        Message::create(['group_id' => $this->falcon->id, 'user_id' => $this->ben->id, 'body' => 'Me too']);
        Message::create(['group_id' => $this->owl->id, 'user_id' => $this->me->id, 'file_path' => 'group-files/x/'.str_repeat('a', 40).'.png', 'file_name' => 'pic.png', 'file_size' => 100]);

        $html = $this->page();
        $pal = SubjectPalette::for($this->falcon->subject_id);

        $this->assertStringContainsString("background-color: {$pal['accent']};", $html, 'The avatar wears the subject colour.');
        $this->assertStringContainsString('SECJ3104', $html);
        $this->assertStringContainsString('Ben:</span> Me too', $html);
        $this->assertMatchesRegularExpression('/You:<\/span>\s*<svg[^>]*>.*?<\/svg>\s*<span class="truncate">Photo<\/span>/s', $html, 'An attachment reads as Photo with an icon.');
        $this->assertSame(2, substr_count($html, 'data-last-time'));
        $this->assertStringContainsString('>GF<', $html, 'Initials of the group name.');
    }

    public function test_a_group_without_messages_says_so(): void
    {
        $this->assertStringContainsString('No messages yet', $this->page());
    }

    public function test_the_list_scrolls_inside_its_pane_and_has_a_search_field(): void
    {
        $html = $this->page();

        $this->assertStringContainsString('overflow-y-auto', substr($html, strpos($html, 'aria-label="Groups"') - 80, 160));
        $this->assertStringContainsString('data-group-search', $html);
        $this->assertStringContainsString('Search groups', $html);
        $this->assertStringContainsString('data-search="group falcon secj3104"', $html);
        $this->assertStringContainsString('data-search="night owls secj2013"', $html);
    }

    public function test_the_header_names_the_group_with_its_code_member_count_and_workspace_link(): void
    {
        $html = $this->page();
        $header = substr($html, strpos($html, 'data-chat-header'), 1800);

        $this->assertStringContainsString('Group Falcon', $header);
        $this->assertStringContainsString('SECJ3104', $header);
        $this->assertStringContainsString('2 members', $header);
        $this->assertStringContainsString(route('student.workspace.show', $this->falcon), $header);
    }

    public function test_below_900px_the_list_gives_way_to_a_strip_of_chips(): void
    {
        $html = $this->page();

        $this->assertStringContainsString('hidden w-[320px] shrink-0 flex-col border-r border-surface-line bg-surface-soft min-[900px]:flex', $html);
        $this->assertStringContainsString('min-[900px]:hidden" data-group-strip', $html);
    }

    public function test_unread_badges_are_not_shown_because_chat_has_no_read_tracking(): void
    {
        Message::create(['group_id' => $this->owl->id, 'user_id' => $this->me->id, 'body' => 'Hi']);

        $this->assertStringNotContainsString('data-unread', $this->page());
    }

    public function test_the_access_rules_are_unchanged(): void
    {
        $outsider = User::factory()->student()->create(['must_change_password' => false]);

        $this->actingAs($outsider)->get(route('student.chat.show', $this->falcon))->assertNotFound();
        $this->get(route('student.chat'))->assertOk();   // an empty state, not someone else's group
    }
    public function test_the_chat_page_is_exactly_the_screen_tall_and_the_card_takes_what_is_left(): void
    {
        $html = $this->page();

        // The shell is fitted from 900px up: the page itself does not scroll.
        $this->assertStringContainsString('min-[900px]:h-dvh min-[900px]:overflow-hidden', $html);
        $this->assertStringContainsString('min-[900px]:flex min-[900px]:min-h-0 min-[900px]:flex-col', $html, 'The content column is a flex chain.');
        $this->assertStringContainsString('flex flex-col pb-5 min-[900px]:min-h-0 min-[900px]:flex-1', $html);

        // The card is the flexible part, not a hand-counted height.
        $this->assertStringContainsString('min-h-[36rem] flex-1 overflow-hidden', $html);
        $this->assertStringContainsString('min-[900px]:min-h-0', $html);
        $this->assertStringNotContainsString('calc(100dvh-8.5rem)', $html);

        // Only the two lists scroll: the groups, and the messages. The header and composer do not.
        $this->assertStringContainsString('overflow-y-auto', substr($html, strpos($html, 'aria-label="Groups"') - 80, 160));
        $this->assertMatchesRegularExpression('/<div x-ref="thread"\s+class="[^"]*overflow-y-auto/', $html);
        $this->assertDoesNotMatchRegularExpression('/data-composer[^>]*overflow-y/', $html);
        $this->assertDoesNotMatchRegularExpression('/data-chat-header[^>]*overflow-y/', $html);
    }

    public function test_no_glows_and_a_normal_page_background(): void
    {
        $html = $this->page();

        $this->assertStringNotContainsString('data-glow', $html);
        $this->assertStringNotContainsString('blur-3xl', $html);
        $this->assertStringNotContainsString('overflow-x-clip', $html, 'No hard-edged wrapper around the card.');
        $this->assertStringContainsString('shadow-card', $html, 'The card keeps its soft shadow.');
        $this->assertStringContainsString('cs-surface-soft', $html, 'And the soft blue surface behind the conversation.');
        $this->assertStringNotContainsString('cs-chat-backdrop', $html);
        $this->assertStringContainsString('bg-surface-soft text-ink', $html);
    }

    public function test_other_pages_keep_the_normal_scrolling_shell(): void
    {
        foreach ([route('student.dashboard'), route('student.workspace.show', $this->falcon), route('account')] as $url) {
            $html = $this->actingAs($this->me)->get($url)->assertOk()->getContent();

            $this->assertStringNotContainsString('min-[900px]:h-dvh', $html, $url);
            $this->assertStringContainsString('pb-12', $html, $url);
        }
    }

    public function test_the_sidebar_is_always_the_full_height_and_pinned_with_its_user_block_at_the_bottom(): void
    {
        foreach ([route('student.chat.show', $this->falcon), route('student.dashboard')] as $url) {
            $html = $this->actingAs($this->me)->get($url)->assertOk()->getContent();

            preg_match('/<aside[^>]*class="[^"]*sticky top-0[^"]*"/', $html, $aside);
            $this->assertStringContainsString('h-dvh', $aside[0], $url);
            $this->assertStringContainsString('self-start', $aside[0], $url);
            $this->assertStringContainsString('flex-col', $aside[0], $url);
            $this->assertStringNotContainsString('h-screen', $aside[0], $url);
            $this->assertStringContainsString('flex flex-1 flex-col gap-1 overflow-y-auto', $html, 'The menu scrolls inside the sidebar.');
            $this->assertStringContainsString('mt-4 flex items-center gap-3 rounded-card bg-white/50 p-3', $html, 'The user block sits under the menu, at the foot of the sidebar.');
        }
    }
}