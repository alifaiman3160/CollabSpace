<?php

namespace Tests\Feature;

use App\Models\Group;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Sidebar links move between pages without a reload (Livewire's wire:navigate): the sidebar is kept, only the
 * content column changes, and the highlighted item follows the address.
 */
class SidebarNavigationTest extends TestCase
{
    use RefreshDatabase;

    protected function sidebar(string $html): string
    {
        preg_match('/<aside[^>]*>.*?<\/aside>/s', $html, $aside);
        $this->assertNotEmpty($aside, 'No sidebar rendered.');

        return $aside[0];
    }

    /** Every link inside the main navigation, as its opening tag. */
    protected function navLinks(string $html): array
    {
        preg_match('/<nav[^>]*aria-label="Main".*?<\/nav>/s', $html, $nav);
        preg_match_all('/<a\b[^>]*>/', $nav[0] ?? '', $links);

        return $links[0];
    }

    public function test_student_sidebar_links_navigate_without_a_reload_and_the_sidebar_is_kept(): void
    {
        $student = User::factory()->student()->create(['must_change_password' => false]);

        $html = $this->actingAs($student)->get(route('student.dashboard'))->assertOk()->getContent();

        $links = $this->navLinks($html);
        $this->assertNotEmpty($links);

        foreach ($links as $link) {
            $this->assertStringContainsString('wire:navigate', $link);
            $this->assertStringContainsString('wire:current=', $link);
        }

        // The sidebar is the same element across visits, so it is not re-rendered or re-animated.
        $this->assertStringContainsString('x-persist="sidebar"', $this->sidebar($html));
        $this->assertStringContainsString('data-page-content', $html);
    }

    public function test_lecturer_sidebar_links_navigate_without_a_reload(): void
    {
        $lecturer = User::factory()->lecturer()->create(['must_change_password' => false]);

        $html = $this->actingAs($lecturer)->get(route('lecturer.dashboard'))->assertOk()->getContent();

        foreach ($this->navLinks($html) as $link) {
            $this->assertStringContainsString('wire:navigate', $link);
        }

        $this->assertStringContainsString('x-persist="sidebar"', $this->sidebar($html));
    }

    public function test_logout_and_downloads_are_never_navigated(): void
    {
        $lecturer = User::factory()->lecturer()->create(['must_change_password' => false]);
        $subject = Subject::create(['lecturer_id' => $lecturer->id, 'code' => 'SECJ3104', 'name' => 'App Dev', 'group_size' => 4]);
        $group = Group::create(['subject_id' => $subject->id, 'name' => 'Group A', 'group_size' => 4]);

        $html = $this->actingAs($lecturer)->get(route('lecturer.reports.show', $group))->assertOk()->getContent();

        // Logout is a form that posts, not a link.
        preg_match('/<form[^>]*action="[^"]*logout"[^>]*>/', $html, $logout);
        $this->assertNotEmpty($logout);
        $this->assertStringNotContainsString('wire:navigate', $logout[0]);

        // The CSV export, and anything that downloads or opens a new tab, stays a normal load.
        preg_match_all('/<a\b[^>]*>/', $html, $anchors);

        foreach ($anchors[0] as $anchor) {
            if (str_contains($anchor, '/export') || str_contains($anchor, ' download') || str_contains($anchor, 'target="_blank"')) {
                $this->assertStringNotContainsString('wire:navigate', $anchor, $anchor);
            }
        }
    }

    public function test_the_current_item_is_highlighted_per_route_from_the_first_paint(): void
    {
        $student = User::factory()->student()->create(['must_change_password' => false]);

        foreach ([
            [route('student.dashboard'), 'Dashboard'],
            [route('student.documents'), 'Documents'],
            [route('student.board'), 'Task board'],
        ] as [$url, $label]) {
            $html = $this->actingAs($student)->get($url)->getContent();

            $current = collect($this->navLinks($html))->filter(fn ($link) => preg_match('/\sdata-current[\s>]/', $link));
            $this->assertCount(1, $current, "{$url} should mark exactly one item current.");
            $this->assertStringContainsString('aria-current="page"', $current->first());

            // The label follows that opening tag.
            $this->assertMatchesRegularExpression('/data-current[^>]*>.*?'.preg_quote($label, '/').'/s', $this->sidebar($html));
        }
    }

    public function test_an_unauthenticated_navigate_request_is_sent_to_login(): void
    {
        $this->withHeader('X-Livewire-Navigate', '1')
            ->get(route('student.dashboard'))
            ->assertRedirect(route('login'));
    }

    public function test_a_forbidden_page_still_renders_through_a_navigate_request(): void
    {
        $student = User::factory()->student()->create(['must_change_password' => false]);

        $this->actingAs($student)
            ->withHeader('X-Livewire-Navigate', '1')
            ->get(route('lecturer.dashboard'))
            ->assertForbidden();
    }

    public function test_the_progress_bar_is_thin_and_uses_the_periwinkle_accent(): void
    {
        $student = User::factory()->student()->create(['must_change_password' => false]);

        $this->assertSame('#4a5bd0', config('livewire.navigate.progress_bar_color'));

        $this->actingAs($student)->get(route('student.dashboard'))
            ->assertSee('--livewire-progress-bar-color: #4a5bd0', false);
    }

    public function test_page_motion_is_shared_css_with_a_reduced_motion_override(): void
    {
        $css = file_get_contents(resource_path('css/app.css'));
        $js = file_get_contents(resource_path('js/app.js'));

        $this->assertStringContainsString('.cs-page-in', $css);
        $this->assertMatchesRegularExpression('/prefers-reduced-motion: reduce\)\s*\{\s*\.cs-page-in/', $css);
        $this->assertStringContainsString("'livewire:navigate'", $js);
        $this->assertStringContainsString("'livewire:navigated'", $js);
    }

    public function test_window_listeners_in_page_scripts_are_removed_when_the_page_is_left(): void
    {
        foreach (['chat-switcher.js', 'login-intro.js'] as $file) {
            $source = file_get_contents(resource_path('js/'.$file));

            $this->assertStringContainsString('destroy()', $source, "{$file} must clean up after itself.");
            $this->assertStringContainsString('removeEventListener', $source);
        }
    }
    public function test_the_page_transition_is_calm_and_the_scrollbar_gutter_is_stable(): void
    {
        $css = file_get_contents(resource_path('css/app.css'));

        preg_match('/\.cs-page-in\s*\{[^}]*\}\s*@keyframes cs-page-in\s*\{[^}]*\{[^}]*\}\s*\}/s', $css, $enter);
        $this->assertNotEmpty($enter, 'The page-enter animation is missing.');

        // Opacity and a small vertical shift only: nothing sideways, no blur, no layout properties.
        $this->assertStringNotContainsString('translateX', $enter[0]);
        $this->assertStringNotContainsString('blur', $enter[0]);
        $this->assertMatchesRegularExpression('/translateY\(6px\)/', $enter[0]);
        $this->assertMatchesRegularExpression('/180ms/', $enter[0]);
        $this->assertDoesNotMatchRegularExpression('/\b(width|height|margin|box-shadow|filter)\s*:/', $enter[0]);

        // No leave animation to fight the enter.
        $this->assertStringNotContainsString('cs-page-leaving', $css);
        $this->assertStringNotContainsString('cs-page-leaving', file_get_contents(resource_path('js/app.js')));

        // The one scroller (the page) always reserves its scrollbar's space.
        $this->assertMatchesRegularExpression('/html\s*\{\s*scrollbar-gutter:\s*stable;/', $css);

        // The default whole-page cross-fade is switched off.
        $this->assertMatchesRegularExpression('/::view-transition-old\(root\),\s*::view-transition-new\(root\)\s*\{\s*animation:\s*none;\s*mix-blend-mode:\s*normal;/', $css);
        $this->assertStringContainsString('view-transition-name: page-content', $css);
    }

    public function test_the_content_wrapper_is_named_and_the_sidebar_is_not_inside_it(): void
    {
        $student = User::factory()->student()->create(['must_change_password' => false]);

        $html = $this->actingAs($student)->get(route('student.dashboard'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/<div data-page-content class="cs-page-content /', $html);

        $wrapper = strpos($html, 'data-page-content');
        $this->assertGreaterThan(strpos($html, '</aside>'), $wrapper, 'The sidebar must sit before, not inside, the animated content.');
        $this->assertStringNotContainsString('<aside', substr($html, $wrapper));
    }
}
