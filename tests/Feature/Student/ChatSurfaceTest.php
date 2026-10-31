<?php

namespace Tests\Feature\Student;

use App\Models\Group;
use App\Models\GroupMember;
use App\Models\Message;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The still, login-like surface behind the chat messages. */
class ChatSurfaceTest extends TestCase
{
    use RefreshDatabase;

    protected User $me;

    protected Group $group;

    protected function setUp(): void
    {
        parent::setUp();

        $lecturer = User::factory()->lecturer()->create(['must_change_password' => false]);
        $subject = Subject::create(['lecturer_id' => $lecturer->id, 'code' => 'SECJ3104', 'name' => 'App Dev', 'group_size' => 4]);
        $this->group = Group::create(['subject_id' => $subject->id, 'name' => 'Group Falcon', 'group_size' => 4]);
        $this->me = User::factory()->student()->create(['must_change_password' => false]);
        GroupMember::create(['group_id' => $this->group->id, 'user_id' => $this->me->id, 'joined_at' => now()]);
    }

    protected function rule(): string
    {
        $css = file_get_contents(resource_path('css/app.css'));
        preg_match('/\.cs-surface-soft\s*\{[^}]*\}/s', $css, $m);

        return $m[0] ?? '';
    }

    protected function luminance(string $hex): float
    {
        $c = array_map(fn ($i) => hexdec(substr(ltrim($hex, '#'), $i, 2)) / 255, [0, 2, 4]);
        $c = array_map(fn ($v) => $v <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4, $c);

        return 0.2126 * $c[0] + 0.7152 * $c[1] + 0.0722 * $c[2];
    }

    protected function blend(string $top, float $alpha, string $under): string
    {
        $mix = fn ($i) => (int) round(hexdec(substr(ltrim($top, '#'), $i, 2)) * $alpha + hexdec(substr(ltrim($under, '#'), $i, 2)) * (1 - $alpha));

        return sprintf('#%02x%02x%02x', $mix(0), $mix(2), $mix(4));
    }

    protected function ratio(string $a, string $b): float
    {
        [$x, $y] = [$this->luminance($a), $this->luminance($b)];

        return (max($x, $y) + 0.05) / (min($x, $y) + 0.05);
    }

    public function test_the_surface_is_four_corner_glows_over_periwinkle_and_nothing_moves(): void
    {
        $rule = $this->rule();

        $this->assertNotSame('', $rule);
        $this->assertSame(4, substr_count($rule, 'radial-gradient('), 'One glow in each corner.');
        $this->assertSame(2, substr_count($rule, 'rgba(143, 164, 240, 0.18),'), 'The login\'s #8fa4f0, faded to under a fifth: top left and bottom right.');
        $this->assertStringContainsString('rgba(255, 255, 255, 0.4)', $rule, 'Soft white.');
        $this->assertStringContainsString('rgba(195, 207, 249, 0.6)', $rule, '#c3cff9.');
        $this->assertStringContainsString('background-color: #eef1fc;', $rule, 'A pale periwinkle base.');
        $this->assertStringContainsString('at 0% 0%', $rule);
        $this->assertStringContainsString('at 100% 100%', $rule);
        $this->assertStringContainsString('at 100% 0%', $rule);
        $this->assertStringContainsString('at 0% 100%', $rule);

        foreach (['animation', 'transition', 'transform', 'background-attachment', 'background-size'] as $banned) {
            $this->assertStringNotContainsString($banned, $rule, "{$banned} has no place in a still backdrop.");
        }
    }

    public function test_the_old_dotted_backdrop_is_gone_and_dots_stay_on_the_login_only(): void
    {
        $css = file_get_contents(resource_path('css/app.css'));

        $this->assertStringNotContainsString('.cs-chat-backdrop', $css);
        $this->assertStringNotContainsString('cs-chat-backdrop', file_get_contents(resource_path('views/livewire/group-chat.blade.php')));

        // The dot grid, blobs and floating cards are drawn by the sign-in layout, behind its own flag.
        $guest = file_get_contents(resource_path('views/layouts/guest.blade.php'));
        $this->assertStringContainsString('cs-dots', $guest);
        $this->assertStringNotContainsString('cs-dots', file_get_contents(resource_path('views/livewire/group-chat.blade.php')));
        $this->assertStringNotContainsString('cs-blob', file_get_contents(resource_path('views/livewire/group-chat.blade.php')));
    }

    public function test_the_surface_is_on_the_wrapper_that_does_not_scroll_and_the_scroller_is_transparent(): void
    {
        $html = $this->actingAs($this->me)->get(route('student.chat.show', $this->group))->getContent();

        $this->assertStringContainsString('<div class="cs-surface-soft relative min-h-0 flex-1">', $html);
        preg_match('/<div x-ref="thread"\s+class="([^"]*)"/', $html, $scroller);
        $this->assertStringContainsString('overflow-y-auto', $scroller[1]);
        $this->assertStringContainsString('bg-transparent', $scroller[1]);
        $this->assertStringNotContainsString('cs-surface-soft', $scroller[1], 'It never rides on the scrolling element.');
        $this->assertSame(1, substr_count($html, 'cs-surface-soft'), 'Only the message area has it.');

        // The header, the group list and the composer are as they were.
        foreach (['data-chat-header', 'data-group-list', 'data-composer'] as $part) {
            $this->assertStringContainsString($part, $html);
        }
        $this->assertStringNotContainsString('cs-surface-soft', substr($html, strpos($html, 'data-composer') - 200));
    }

    public function test_text_that_sits_straight_on_the_surface_has_a_pale_pill_and_enough_contrast(): void
    {
        Message::create(['group_id' => $this->group->id, 'user_id' => $this->me->id, 'body' => 'Mine']);
        $other = User::factory()->student()->create(['name' => 'Ben Lee', 'must_change_password' => false]);
        GroupMember::create(['group_id' => $this->group->id, 'user_id' => $other->id, 'joined_at' => now()]);
        Message::create(['group_id' => $this->group->id, 'user_id' => $other->id, 'body' => 'Theirs']);

        $html = $this->actingAs($this->me)->get(route('student.chat.show', $this->group))->getContent();

        $this->assertMatchesRegularExpression('/<span class="rounded-pill bg-white\/85[^"]*"[^>]*data-sender>/', $html);
        $this->assertMatchesRegularExpression('/<span class="rounded-pill bg-white\/75[^"]*"[^>]*data-time>/', $html);
        $this->assertStringContainsString('rounded-pill bg-white/85 px-2.5 py-[3px] text-micro', $html, 'The day label.');

        // Worst case: the deepest blue on the surface (the glow's centre over the base), with the pill on it.
        $deepest = $this->blend('#8fa4f0', 0.18, '#eef1fc');
        $time = $this->blend('#ffffff', 0.75, $deepest);
        $name = $this->blend('#ffffff', 0.85, $deepest);
        $day = $this->blend('#ffffff', 0.85, $deepest);

        $this->assertGreaterThanOrEqual(4.5, $this->ratio('#4d4d57', $time), 'Time (ink-600) on its pill.');
        $this->assertGreaterThanOrEqual(4.5, $this->ratio('#4d4d57', $day), 'Day label on its pill.');
        foreach (['#4155de', '#097171', '#8e38ab', '#a1521f', '#3c5b7a', '#c91f13', '#137345', '#736a0c'] as $member) {
            $this->assertGreaterThanOrEqual(4.5, $this->ratio($member, $name), "Sender name {$member} on its pill.");
        }
        $this->assertGreaterThanOrEqual(4.5, $this->ratio('#111114', '#e7ebfc'), 'Text in my bubble.');
    }

    public function test_my_bubble_is_told_apart_from_the_blue_and_theirs_is_white(): void
    {
        Message::create(['group_id' => $this->group->id, 'user_id' => $this->me->id, 'body' => 'Mine']);

        $html = $this->actingAs($this->me)->get(route('student.chat.show', $this->group))->getContent();

        $this->assertStringContainsString('border border-periwinkle-deep/60 bg-periwinkle-soft text-ink', $html);
        $this->assertStringContainsString('border border-surface-line bg-surface text-ink shadow-subtle', file_get_contents(resource_path('views/livewire/group-chat.blade.php')));
    }

    public function test_the_empty_state_is_a_short_friendly_card_with_no_animation(): void
    {
        $html = $this->actingAs($this->me)->get(route('student.chat.show', $this->group))->getContent();

        $this->assertStringContainsString('data-empty-chat', $html);
        $this->assertStringContainsString('No messages yet', $html);
        $this->assertStringContainsString('Say hello.', $html);
        $this->assertStringContainsString('bg-white/80', substr($html, strpos($html, 'data-empty-chat'), 300), 'Readable on the blue.');
        $this->assertStringNotContainsString('animate-', substr($html, strpos($html, 'data-empty-chat'), 900));
    }
}
