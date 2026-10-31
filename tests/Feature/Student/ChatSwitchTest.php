<?php

namespace Tests\Feature\Student;

use App\Livewire\GroupChat;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\Message;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Switching between group chats in place, and what the page needs for it. */
class ChatSwitchTest extends TestCase
{
    use RefreshDatabase;

    protected User $me;

    protected Group $falcon;

    protected Group $owl;

    protected Group $strangers;

    protected function setUp(): void
    {
        parent::setUp();

        $lecturer = User::factory()->lecturer()->create(['must_change_password' => false]);
        $one = Subject::create(['lecturer_id' => $lecturer->id, 'code' => 'SECJ3104', 'name' => 'App Dev', 'group_size' => 4]);
        $two = Subject::create(['lecturer_id' => $lecturer->id, 'code' => 'SECJ2013', 'name' => 'Data Structures', 'group_size' => 4]);
        $this->falcon = Group::create(['subject_id' => $one->id, 'name' => 'Group Falcon', 'group_size' => 4]);
        $this->owl = Group::create(['subject_id' => $two->id, 'name' => 'Night Owls', 'group_size' => 4]);
        $this->strangers = Group::create(['subject_id' => $one->id, 'name' => 'Not Mine', 'group_size' => 4]);

        $this->me = User::factory()->student()->create(['name' => 'Ana Rahim', 'must_change_password' => false]);
        $other = User::factory()->student()->create(['name' => 'Ben Lee', 'must_change_password' => false]);

        foreach ([$this->falcon, $this->owl] as $g) {
            GroupMember::create(['group_id' => $g->id, 'user_id' => $this->me->id, 'joined_at' => now()]);
        }
        GroupMember::create(['group_id' => $this->strangers->id, 'user_id' => $other->id, 'joined_at' => now()]);

        Message::create(['group_id' => $this->falcon->id, 'user_id' => $this->me->id, 'body' => 'Falcon talk']);
        Message::create(['group_id' => $this->owl->id, 'user_id' => $this->me->id, 'body' => 'Owl talk']);
        Message::create(['group_id' => $this->strangers->id, 'user_id' => $other->id, 'body' => 'Secret talk']);
    }

    protected function chat(): \Livewire\Features\SupportTesting\Testable
    {
        return Livewire::actingAs($this->me)->test(GroupChat::class, ['group' => $this->falcon]);
    }

    public function test_opening_another_group_returns_that_groups_header_and_messages_and_not_the_old_ones(): void
    {
        $this->chat()
            ->assertSee('Group Falcon')->assertSee('Falcon talk')
            ->dispatch('open-group', id: $this->owl->id)
            ->assertSee('Night Owls')
            ->assertSee('SECJ2013')
            ->assertSee('Owl talk')
            ->assertDontSee('Falcon talk')
            ->assertDontSee('Group Falcon')
            ->assertSet('group.id', $this->owl->id)
            ->assertDispatched('chat-opened', id: $this->owl->id, name: 'Night Owls');
    }

    public function test_a_group_that_is_not_the_students_does_not_load_and_the_pane_says_so(): void
    {
        $this->chat()
            ->dispatch('open-group', id: $this->strangers->id)
            ->assertSet('missing', true)
            ->assertSee('That chat is not available')
            ->assertDontSee('Secret talk')
            ->assertDontSee('Not Mine')
            ->assertDispatched('chat-missing');

        $this->chat()->dispatch('open-group', id: 999999)->assertSet('missing', true)->assertDontSee('Secret talk');
    }

    public function test_after_a_refused_switch_a_valid_one_works_again(): void
    {
        $this->chat()
            ->dispatch('open-group', id: $this->strangers->id)
            ->dispatch('open-group', id: $this->owl->id)
            ->assertSet('missing', false)
            ->assertSee('Owl talk');
    }

    public function test_when_two_requests_overlap_the_last_one_wins_and_nothing_from_the_first_is_left(): void
    {
        $this->chat()
            ->dispatch('open-group', id: $this->owl->id)
            ->dispatch('open-group', id: $this->falcon->id)
            ->assertSet('group.id', $this->falcon->id)
            ->assertSee('Falcon talk')
            ->assertDontSee('Owl talk');
    }

    public function test_switching_clears_the_unsent_text_on_the_server_it_is_kept_in_the_browser(): void
    {
        $this->chat()
            ->set('body', 'half a thought')
            ->dispatch('open-group', id: $this->owl->id)
            ->assertSet('body', '');

        $this->assertSame(0, Message::where('body', 'half a thought')->count(), 'Drafts are never stored on the server.');
    }

    public function test_sending_after_a_switch_goes_to_the_group_now_open_and_only_if_it_is_the_students(): void
    {
        $this->chat()
            ->dispatch('open-group', id: $this->owl->id)
            ->set('body', 'Hello owls')
            ->call('send');

        $this->assertSame($this->owl->id, Message::where('body', 'Hello owls')->value('group_id'));

        $this->chat()->dispatch('open-group', id: $this->strangers->id)->set('body', 'Nope')->call('send');
        $this->assertSame($this->falcon->id, Message::where('body', 'Nope')->value('group_id'), 'A refused switch never changes where a message goes.');
    }

    public function test_the_full_page_route_still_works_and_still_404s_for_someone_elses_group(): void
    {
        $this->actingAs($this->me)->get(route('student.chat.show', $this->falcon))->assertOk()->assertSee('Falcon talk');
        $this->actingAs($this->me)->get(route('student.chat.show', $this->owl))->assertOk()->assertSee('Owl talk');
        $this->actingAs($this->me)->get(route('student.chat.show', $this->strangers))->assertNotFound();
    }

    public function test_the_page_has_the_switching_hooks_and_the_rows_stay_ordinary_links(): void
    {
        $html = $this->actingAs($this->me)->get(route('student.chat.show', $this->falcon))->getContent();

        $this->assertStringContainsString('x-data="chatSwitcher(', $html);
        $this->assertStringContainsString('aria-live="polite"', $html);
        $this->assertStringContainsString('x-on:click="go($event, '.$this->owl->id.')"', $html);
        $this->assertStringContainsString('href="'.route('student.chat.show', $this->owl).'"', $html, 'Without JavaScript it is a normal link.');
        $this->assertStringContainsString("x-bind:aria-current=\"current === {$this->owl->id} ? 'page' : null\"", $html);
        $this->assertStringContainsString('data-skeleton', $html);
        $this->assertStringContainsString('cs-pane', $html);
        $this->assertStringContainsString('data-chat-body', $html);
        $this->assertStringNotContainsString('data-glow', $html, 'The blurred glows are gone.');
        $this->assertStringNotContainsString('blur-3xl', $html);
        $this->assertStringContainsString('cs-surface-soft', $html);
    }

    public function test_the_script_cancels_by_coalescing_keeps_drafts_in_memory_and_follows_back_and_forward(): void
    {
        $js = file_get_contents(resource_path('js/chat-switcher.js'));

        $this->assertStringContainsString("this.listen('popstate'", $js);
        $this->assertStringContainsString('window.addEventListener(name, handler)', $js);
        $this->assertStringContainsString('history.pushState', str_replace('window.', '', $js));
        $this->assertStringContainsString('document.title =', $js);
        $this->assertStringContainsString('if (id !== this.want)', $js, 'A stale answer is skipped in favour of the last click.');
        $this->assertStringContainsString('this.drafts[this.loaded] = field.value', $js);
        $this->assertStringNotContainsString('localStorage', $js);
        $this->assertStringNotContainsString('sessionStorage', $js);
        $this->assertStringContainsString('(prefers-reduced-motion: reduce)', $js);
        $this->assertStringContainsString('setTimeout(() => (this.skeleton = true), 150)', $js);
    }

    public function test_the_swap_timings_and_the_reduced_motion_rules_are_in_the_stylesheet(): void
    {
        $css = file_get_contents(resource_path('css/app.css'));

        $this->assertStringContainsString('.cs-pane {', $css);
        $this->assertStringContainsString('transition: opacity 120ms ease-in;', $css);
        $this->assertStringContainsString('animation: cs-swap-in 200ms ease-out;', $css);
        $this->assertStringContainsString('.cs-shimmer { animation: none; }', $css);
    }
}
