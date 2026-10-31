<?php

namespace Tests\Feature;

use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Hover is decoration, never the way to find an action.
 *
 * Workflow actions (Manage, Edit, Open, Groups, Auto-assign, Report, Monitor,
 * Reset password, Activate...) must always be on screen. Destructive ones
 * (Delete, Remove, Deactivate) are quiet, but present too, because a touch
 * screen has no hover. So nothing in the app may be revealed by hover or
 * focus-within at all.
 */
class RevealRulesTest extends TestCase
{
    use RefreshDatabase;

    public function test_no_action_is_revealed_by_hover(): void
    {
        $patterns = [
            '/group-hover[^"\s]*:(opacity-(?!\d)|opacity-100|visible|block|flex|inline|translate)/',
            '/\bhover:(opacity-100|visible|block\b)/',
            '/\bgroup-focus-within:/',
            '/\bpeer-hover:/',
            '/class="[^"]*(?<![\w-])(opacity-0|invisible)(?![\w-])/',
            '/(class="[^"]*|\.)row-actions\b(?!>)/',
        ];

        $files = array_merge(
            glob(resource_path('views/{,*/,*/*/,*/*/*/}*.blade.php'), GLOB_BRACE),
            glob(app_path('Livewire/*.php')),
            [resource_path('css/app.css')],
        );

        $hits = [];

        foreach ($files as $file) {
            $path = str_replace(DIRECTORY_SEPARATOR, '/', $file);

            if (str_contains($path, 'vendor/mail')) {
                continue;
            }

            foreach (file($file) as $n => $line) {
                // Alpine enter/leave states are animation frames, not reveals.
                if (preg_match('/x-transition:(enter|leave)/', $line) || str_contains($line, 'x-cloak')) {
                    continue;
                }

                foreach ($patterns as $pattern) {
                    if (preg_match($pattern, $line)) {
                        $hits[] = basename(dirname($path)).'/'.basename($path).':'.($n + 1).'  '.trim($line);
                    }
                }
            }
        }

        $this->assertSame([], $hits, "Hover-only reveals found:\n".implode("\n", $hits));
    }

    public function test_the_guard_does_catch_a_reveal(): void
    {
        // The patterns themselves, run against known-bad markup.
        foreach (['<div class="opacity-0 group-hover:opacity-100">', '<a class="invisible hover:visible">', '<tr class="group"><td class="group-hover:block">'] as $bad) {
            $this->assertTrue(
                (bool) preg_match('/group-hover[^"\s]*:(opacity-(?!\d)|opacity-100|visible|block|flex)|(?<![\w-])(opacity-0|invisible)(?![\w-])/', $bad),
                $bad,
            );
        }
    }

    public function test_workflow_actions_are_always_rendered_and_extras_collapse_into_a_more_menu(): void
    {
        $lecturer = User::factory()->lecturer()->create(['must_change_password' => false]);
        Subject::create(['lecturer_id' => $lecturer->id, 'code' => 'SECJ3104', 'name' => 'Application Development', 'group_size' => 4]);

        $html = $this->actingAs($lecturer)->get(route('lecturer.subjects'))->assertOk()->getContent();

        foreach (['Groups', 'Auto-assign students', 'Edit', 'Delete'] as $label) {
            $this->assertStringContainsString($label, $html, $label);
        }

        // On a subject card every action is on the card itself, none behind a menu or a hover; the primary one comes first.
        $this->assertStringContainsString('Announcements', $html);
        $this->assertStringContainsString('Rubric', $html);
        $this->assertStringNotContainsString('<table', $html);
        $this->assertMatchesRegularExpression('/<a[^>]*>\s*Edit\s*<\/a>.*?Auto-assign students.*?Delete subject/s', $html);

        $admin = User::factory()->admin()->create(['must_change_password' => false]);
        $users = $this->actingAs($admin)->get(route('admin.users'))->assertOk()->getContent();

        foreach (['Reset password', 'Deactivate', 'Edit'] as $label) {
            $this->assertStringContainsString($label, $users, $label);
        }
        $this->assertStringContainsString('aria-label="More actions"', $users);
    }

    public function test_empty_optional_values_are_a_dash(): void
    {
        $lecturer = User::factory()->lecturer()->create(['must_change_password' => false]);
        Subject::create(['lecturer_id' => $lecturer->id, 'code' => 'SECJ3104', 'name' => 'Application Development', 'group_size' => 4]);

        $this->actingAs($lecturer)->get(route('lecturer.subjects'))->assertOk()
            ->assertSee('No deadline')
            ->assertDontSee('Not set');
    }

    public function test_the_seeded_subjects_are_in_title_case(): void
    {
        $this->seed();

        Subject::all()->each(function (Subject $subject) {
            $this->assertSame(
                collect(explode(' ', $subject->name))->map(fn ($w) => in_array(strtolower($w), ['and', 'of', 'the', 'in', 'for', 'to'], true) ? strtolower($w) : ucfirst($w))->implode(' '),
                $subject->name,
            );
        });
    }
}
