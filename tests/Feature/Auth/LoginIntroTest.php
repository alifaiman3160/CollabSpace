<?php

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The sign-in opening animation is markup and CSS only; these check it appears where it should
 * and stays out of the way where it should not. The sign-in form itself is untouched.
 */
class LoginIntroTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_sign_in_page_carries_the_preloader_and_the_head_script(): void
    {
        $html = $this->get('/login')->assertOk()->getContent();

        $this->assertStringContainsString('cs-preloader', $html);
        $this->assertStringContainsString('aria-hidden="true" inert', $html);
        $this->assertStringContainsString("sessionStorage.getItem('csIntro')", $html);
        $this->assertStringContainsString('prefers-reduced-motion:reduce', $html);
        $this->assertGreaterThanOrEqual(15, substr_count($html, 'data-reveal'));
    }

    public function test_the_form_is_unchanged(): void
    {
        $html = $this->get('/login')->getContent();

        $this->assertStringContainsString('action="'.route('login').'"', $html);
        foreach (['name="email"', 'name="password"', 'name="remember"', '_token'] as $needle) {
            $this->assertStringContainsString($needle, $html);
        }
    }

    public function test_a_failed_sign_in_comes_back_without_the_preloader_and_with_the_error_visible(): void
    {
        $html = $this->from('/login')->followingRedirects()->post('/login', ['email' => 'nobody@example.test', 'password' => 'wrong'])->getContent();

        $this->assertStringNotContainsString('cs-preloader', $html);
        $this->assertStringNotContainsString("sessionStorage.getItem('csIntro')", $html);
        $this->assertMatchesRegularExpression('/do not match|credentials|failed/i', $html);
    }

    public function test_a_page_arriving_with_a_status_message_skips_the_preloader(): void
    {
        $html = $this->withSession(['status' => 'Your password has been reset.'])->get('/login')->getContent();

        $this->assertStringNotContainsString('cs-preloader', $html);
        $this->assertStringContainsString('Your password has been reset.', $html);
    }

    public function test_the_other_guest_pages_do_not_play_it(): void
    {
        $this->assertStringNotContainsString('cs-preloader', $this->get('/forgot-password')->getContent());
    }
    public function test_the_living_backdrop_is_on_the_sign_in_page_and_is_decorative_only(): void
    {
        $html = $this->get('/login')->getContent();

        $this->assertMatchesRegularExpression('/<div class="cs-bg" aria-hidden="true" inert/', $html);
        $this->assertSame(4, substr_count($html, 'class="cs-blob '));
        $this->assertSame(6, substr_count($html, 'class="cs-float '));
        $this->assertStringContainsString('relative z-10', $html, 'The content sits above the backdrop.');

        // Nothing inside it can take focus.
        preg_match('/<div class="cs-bg".*?(?=<div class="relative z-10)/s', $html, $m);
        $this->assertDoesNotMatchRegularExpression('/<(a|button|input|select|textarea)\b|tabindex/i', $m[0]);
    }

    public function test_the_backdrop_stays_when_the_page_returns_with_an_error_or_a_status(): void
    {
        $this->assertStringContainsString('class="cs-bg"', $this->withSession(['status' => 'Done.'])->get('/login')->getContent());
    }

    public function test_the_backdrop_does_not_appear_on_the_other_guest_pages(): void
    {
        foreach (['/forgot-password'] as $path) {
            $this->assertStringNotContainsString('cs-bg', $this->get($path)->getContent());
            $this->assertStringNotContainsString('cs-float', $this->get($path)->getContent());
        }
    }
    public function test_the_svg_pattern_is_inline_decorative_and_loads_no_image_file(): void
    {
        $html = $this->get('/login')->getContent();

        $this->assertStringContainsString('class="cs-pattern"', $html);
        $this->assertStringContainsString('id="cs-dots"', $html);
        $this->assertSame(3, preg_match_all('/<rect x="\d+%" y="8%"/', $html), 'Three kanban columns.');
        $this->assertDoesNotMatchRegularExpression('/login-bg|\.webp|\.avif|background-image/i', $html);

        // It sits inside the inert, aria-hidden backdrop, behind the blobs.
        $this->assertLessThan(strpos($html, 'cs-blob--1'), strpos($html, 'class="cs-pattern"'));
        preg_match('/<div class="cs-bg".*?(?=<div class="relative z-10)/s', $html, $m);
        $this->assertStringContainsString('class="cs-pattern"', $m[0]);
    }

    public function test_the_page_height_uses_the_dynamic_viewport_and_the_canvas_is_periwinkle(): void
    {
        $html = $this->get('/login')->getContent();

        $this->assertMatchesRegularExpression('/<html[^>]*class="bg-periwinkle"/', $html);
        $this->assertStringContainsString('min-h-dvh', $html);
        $this->assertStringContainsString('overflow-x-clip', $html);
        $this->assertStringNotContainsString('min-h-screen', $html, 'No 100vh, which is taller than the visible area on phones.');
    }

    public function test_the_pattern_is_not_on_the_other_guest_pages(): void
    {
        $this->assertStringNotContainsString('cs-pattern', $this->get('/forgot-password')->getContent());
    }
}