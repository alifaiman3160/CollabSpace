<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

/**
 * Motion is defined once, in tokens and shared components. These pin the rules
 * that are easy to break by accident: durations stay in range, the reduced-motion
 * override exists, and nothing that is dragged or polled picks up hover movement.
 */
class MotionTest extends TestCase
{
    public function test_motion_durations_are_tokens_within_120_to_180_ms(): void
    {
        $config = file_get_contents(base_path('tailwind.config.js'));

        preg_match_all("/'?motion[a-z-]*'?:\s*'(\d+)ms'/", $config, $m);

        $this->assertCount(3, $m[1], 'Expected the fast, default and slow motion tokens.');

        foreach ($m[1] as $ms) {
            $this->assertGreaterThanOrEqual(120, (int) $ms);
            $this->assertLessThanOrEqual(180, (int) $ms);
        }
    }

    public function test_the_stylesheet_has_a_reduced_motion_override(): void
    {
        $css = file_get_contents(resource_path('css/app.css'));

        $this->assertStringContainsString('@media (prefers-reduced-motion: reduce)', $css);
        $this->assertStringContainsString('transition-duration: 0s !important', $css);

        // Every movement rule is gated behind no-preference.
        $this->assertMatchesRegularExpression(
            '/@media \(prefers-reduced-motion: no-preference\)\s*\{[^@]*translateY\(-1px\)/s',
            $css,
        );
        $this->assertDoesNotMatchRegularExpression('/\n\.btn-motion[^{]*:hover\s*\{[^}]*transform/', $css);
    }

    public function test_buttons_carry_the_shared_motion_class(): void
    {
        foreach (['<x-button>Go</x-button>', '<x-primary-button>Go</x-primary-button>',
            '<x-secondary-button>Go</x-secondary-button>', '<x-danger-button>Go</x-danger-button>'] as $tpl) {
            $this->assertStringContainsString('btn-motion', Blade::render($tpl), $tpl);
        }
    }

    public function test_overlays_use_token_durations_and_a_small_scale(): void
    {
        foreach (['modal', 'dropdown', 'confirm-dialog', 'toast'] as $name) {
            $src = file_get_contents(resource_path("views/components/{$name}.blade.php"));

            $this->assertStringNotContainsString('duration-300', $src, $name);
            $this->assertStringNotContainsString('duration-200', $src, $name);
            $this->assertStringContainsString('duration-motion', $src, $name);
            $this->assertStringContainsString('ease-motion', $src, $name);
        }
    }

    public function test_dragged_task_cards_and_polled_regions_get_no_hover_movement(): void
    {
        $board = file_get_contents(resource_path('views/livewire/task-board.blade.php'));
        $chat = file_get_contents(resource_path('views/livewire/group-chat.blade.php'));

        // The tag holds `->` inside attributes, so end at the class attribute instead.
        preg_match('/draggable="true".*?class="[^"]*"/s', $board, $card);
        $this->assertNotEmpty($card);
        $this->assertStringNotContainsString('transform', $card[0]);
        $this->assertStringNotContainsString('card-lift', $card[0]);
        $this->assertStringNotContainsString('translate', $card[0]);

        // No keyframe entrance on content that is re-rendered every few seconds.
        $this->assertStringNotContainsString('animate-', $chat);
    }
}
