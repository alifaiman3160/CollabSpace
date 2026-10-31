<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

/**
 * The one shared way things open, close and appear: what can be checked without a browser.
 * The timings, and how it feels, are in docs/MANUAL-TEST.md (13o).
 */
class SharedMotionTest extends TestCase
{
    public function test_a_collapse_is_closed_inert_hidden_from_readers_and_bound_to_its_open_state(): void
    {
        $html = Blade::render('<x-collapse open="why" id="p1" data-why>Inside</x-collapse>');

        $this->assertStringContainsString('class="cs-collapse"', $html);
        $this->assertStringContainsString(' inert ', ' '.$html.' ');
        $this->assertStringContainsString('aria-hidden="true"', $html);
        $this->assertStringContainsString("x-bind:class=\"{ 'is-open': why }\"", $html);
        $this->assertStringContainsString('x-bind:inert="! (why)"', $html);
        $this->assertStringContainsString("x-bind:aria-hidden=\"! (why) ? 'true' : 'false'\"", $html);
        $this->assertStringContainsString('id="p1"', $html);
        $this->assertStringContainsString('<div class="cs-collapse__inner">', $html);
    }

    public function test_a_collapse_can_also_hold_still_while_the_page_arrives_open(): void
    {
        $html = Blade::render('<x-collapse open="open" instant="instant">x</x-collapse>');

        $this->assertStringContainsString("'cs-instant': instant", $html);
    }

    public function test_fade_adds_the_enter_and_leave_transitions_and_leave_can_be_left_out(): void
    {
        $both = Blade::render('<x-fade x-show="a">x</x-fade>');
        $this->assertStringContainsString('x-transition:enter="cs-t-enter"', $both);
        $this->assertStringContainsString('x-transition:leave="cs-t-leave"', $both);

        $enterOnly = Blade::render('<x-fade tag="span" :leave="false" x-show="a">x</x-fade>');
        $this->assertStringStartsWith('<span ', $enterOnly);
        $this->assertStringContainsString('x-transition:enter="cs-t-enter"', $enterOnly);
        $this->assertStringNotContainsString('x-transition:leave', $enterOnly);
    }

    public function test_the_stylesheet_has_one_set_of_timings_and_switches_everything_off_for_reduced_motion(): void
    {
        $css = file_get_contents(resource_path('css/app.css'));

        $this->assertStringContainsString('grid-template-rows 280ms ease-out', $css);
        $this->assertStringContainsString('grid-template-rows 200ms ease-out', $css);
        $this->assertStringContainsString('.cs-t-enter { transition: opacity 150ms ease-out, transform 150ms ease-out; }', $css);
        $this->assertStringContainsString('.cs-in { animation: cs-enter 200ms ease-out; }', $css);
        $this->assertMatchesRegularExpression('/prefers-reduced-motion: reduce\)\s*\{[^}]*animation-duration:\s*0\.01ms !important[^}]*\}/s', $css);
        $this->assertMatchesRegularExpression('/prefers-reduced-motion: reduce\)\s*\{[^}]*transition-duration:\s*0s !important/s', $css);
    }

    public function test_only_items_added_by_a_livewire_update_are_animated_never_the_first_load(): void
    {
        $js = file_get_contents(resource_path('js/app.js'));

        $this->assertStringContainsString("Livewire.hook('morph.added'", $js);
        $this->assertStringContainsString("classList.add('cs-in')", $js);
        $this->assertStringNotContainsString('DOMContentLoaded', $js);

        // The lists are marked, and the marker alone does nothing: .cs-in is only ever added by that hook.
        foreach (['task-board', 'group-chat', 'notification-list'] as $view) {
            $this->assertStringContainsString('data-cs-item', file_get_contents(resource_path("views/livewire/{$view}.blade.php")), $view);
            $this->assertStringNotContainsString('cs-in', str_replace('data-cs-item', '', file_get_contents(resource_path("views/livewire/{$view}.blade.php"))), $view);
        }
    }

    public function test_the_marking_page_panels_use_the_shared_collapse(): void
    {
        $view = file_get_contents(resource_path('views/lecturer/marking/student.blade.php'));

        $this->assertSame(3, substr_count($view, '<x-collapse'), 'Why?, Level guide and the reason field.');
        $this->assertStringNotContainsString('x-show="why"', $view);
        $this->assertStringContainsString('<x-fade tag="span"', $view);
    }
}
