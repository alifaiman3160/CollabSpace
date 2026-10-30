<?php

namespace Tests\Feature;

use App\Models\AppNotification;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Two things every action in the app owes the person doing it: a visible answer
 * afterwards, and a chance to stop beforehand.
 *
 * Feedback used to depend on each screen remembering to include the flash
 * banner, and six of them had not — so creating an account, for one, looked
 * exactly like doing nothing. The toast is mounted once in the layout instead,
 * which is what these assert: not that one screen shows it, but that it cannot
 * be forgotten on any of them.
 */
class FeedbackAndConfirmationTest extends TestCase
{
    use RefreshDatabase;

    protected function admin(): User
    {
        return User::factory()->admin()->create(['must_change_password' => false]);
    }

    protected function lecturerWithGroup(): array
    {
        $lecturer = User::factory()->lecturer()->create(['must_change_password' => false]);

        $subject = Subject::create([
            'lecturer_id' => $lecturer->id, 'code' => 'SECJ3104', 'name' => 'App Dev', 'group_size' => 4,
        ]);

        $group = Group::create(['subject_id' => $subject->id, 'name' => 'Group A', 'group_size' => 4]);

        return [$lecturer, $subject, $group];
    }

    /*
    | Success feedback
    */

    public function test_creating_a_user_reports_success(): void
    {
        \Illuminate\Support\Facades\Mail::fake();

        $admin = $this->admin();

        $this->actingAs($admin)
            ->post(route('admin.users.store'), [
                'name' => 'Nurul Izzah',
                'email' => 'nurul@student.collabspace.test',
                'role' => 'student',
                'matric_no' => 'A21EC0024',
            ])
            ->assertSessionHas('status');

        // And the message names what happened, rather than saying "Done".
        $this->assertStringContainsString('Nurul Izzah', session('status'));

        // The toast is what the administrator actually sees on landing back on
        // the list. This is the one that was missing: the message existed, but
        // the password panel suppressed it, so the screen looked unchanged.
        $this->actingAs($admin)
            ->get(route('admin.users'))
            ->assertOk()
            ->assertSee("show('Account created for Nurul Izzah", false);
    }

    public function test_the_toast_is_mounted_for_every_role(): void
    {
        // Mounted in the layout, so no screen can forget it.
        foreach ([
            'admin' => 'admin.dashboard',
            'lecturer' => 'lecturer.dashboard',
            'student' => 'student.dashboard',
        ] as $role => $route) {
            $user = User::factory()->create(['role' => $role, 'must_change_password' => false]);

            $this->actingAs($user)
                ->withSession(['status' => 'A thing happened.'])
                ->get(route($route))
                ->assertOk()
                // The container is always rendered so the Livewire listener
                // exists on every page; what proves a toast actually fires is
                // the message handed to it.
                ->assertSee("show('A thing happened.'", false);
        }
    }

    public function test_the_toast_appears_on_screens_that_never_had_a_banner(): void
    {
        [$lecturer, $subject] = $this->lecturerWithGroup();

        // The create screens carried no flash of their own, so a validation
        // round trip or a status message vanished on them.
        $this->actingAs($lecturer)
            ->withSession(['status' => 'Saved that for you.'])
            ->get(route('lecturer.subjects.create'))
            ->assertOk()
            ->assertSee('Saved that for you.');

        $this->actingAs($this->admin())
            ->withSession(['status' => 'Saved that for you.'])
            ->get(route('admin.users.create'))
            ->assertOk()
            ->assertSee('Saved that for you.');
    }

    public function test_a_failure_is_shown_in_the_danger_tone(): void
    {
        $admin = $this->admin();

        // Deactivating your own account is refused, and the refusal has to be
        // as visible as a success would be.
        $this->actingAs($admin)
            ->put(route('admin.users.status', $admin))
            ->assertSessionHasErrors('status');

        $this->actingAs($admin)
            ->get(route('admin.users'))
            ->assertOk()
            ->assertSee('You cannot deactivate your own account')
            ->assertSee('bg-danger-bg', false);
    }

    public function test_issuing_a_password_reports_success_without_showing_it(): void
    {
        \Illuminate\Support\Facades\Mail::fake();

        $user = User::factory()->create(['name' => 'Syafiq Idris']);
        $admin = $this->admin();

        $this->actingAs($admin)
            ->put(route('admin.users.password', $user))
            ->assertSessionHas('status')
            ->assertSessionMissing('temporary_password');

        $this->actingAs($admin)
            ->get(route('admin.users'))
            ->assertOk()
            // A plain toast, and no secret anywhere on the screen.
            ->assertSee("show('A new temporary password was issued for Syafiq Idris", false)
            ->assertDontSee('Email failed');
    }

    public function test_a_password_that_could_not_be_emailed_is_not_a_toast(): void
    {
        // The one case the administrator does see it. It is shown exactly once
        // and cannot be recovered, so it must not sit on a dismissal timer.
        \Illuminate\Support\Facades\Mail::shouldReceive('to')->andThrow(new \RuntimeException('smtp is down'));

        $user = User::factory()->create(['name' => 'Syafiq Idris']);
        $admin = $this->admin();

        $this->actingAs($admin)->put(route('admin.users.password', $user));

        $password = session('temporary_password');
        $this->assertNotEmpty($password);

        $html = $this->actingAs($admin)
            ->get(route('admin.users'))
            ->assertOk()
            ->assertSee('Email failed')
            ->getContent();

        // The toast is mounted but silent: no message was handed to it. The
        // words belong to the panel holding the password, and a second copy
        // floating away on a timer would be noise.
        $this->assertStringContainsString('show(null,', $html);
        $this->assertStringContainsString($password, $html);
    }

    /*
    | Confirmation before destruction
    */

    public function test_deleting_a_subject_asks_for_its_code_to_be_typed(): void
    {
        [$lecturer, $subject] = $this->lecturerWithGroup();

        $this->actingAs($lecturer)
            ->get(route('lecturer.subjects'))
            ->assertOk()
            // A cascading delete takes groups, tasks, documents, chat and the
            // contribution record with it, so it gets a second, deliberate step.
            ->assertSee("confirmText: 'SECJ3104'", false);
    }

    public function test_deleting_a_group_asks_for_its_name_to_be_typed(): void
    {
        [$lecturer, , $group] = $this->lecturerWithGroup();

        $this->actingAs($lecturer)
            ->get(route('lecturer.groups'))
            ->assertOk()
            ->assertSee("confirmText: 'Group A'", false);
    }

    public function test_the_typed_confirmation_gates_the_delete_button(): void
    {
        [$lecturer] = $this->lecturerWithGroup();

        $this->actingAs($lecturer)
            ->get(route('lecturer.subjects'))
            ->assertOk()
            // The button is disabled until what was typed matches exactly.
            ->assertSee('typed.trim() !== required', false)
            ->assertSee('to confirm', false);
    }

    public function test_the_disabled_binding_always_yields_a_real_boolean(): void
    {
        /*
         * The regression this exists for: the binding used to read
         *
         *     :disabled="confirm.confirmText && typed.trim() !== ..."
         *
         * which evaluates to the *value* of confirmText when that is falsy.
         * Alpine strips a bound boolean attribute for exactly null, undefined
         * and false — every other falsy value sets it instead. So a dialog with
         * no typing step could come up with its confirm button disabled and no
         * field anywhere to type into, which is what happened on the
         * notifications screen. Going through a method that returns a real
         * boolean makes that shape impossible.
         */
        $dialog = file_get_contents(resource_path('views/components/confirm-dialog.blade.php'));

        preg_match_all('/:disabled="([^"]+)"/', $dialog, $matches);

        $this->assertNotEmpty($matches[1], 'The dialog has no disabled binding.');

        foreach ($matches[1] as $expression) {
            $this->assertStringStartsWith('locked(', $expression,
                'The confirm button must take its disabled state from locked(), which always returns a boolean.');
        }
    }

    public function test_removing_a_member_does_not_demand_typing(): void
    {
        // Reversible in one click — asking for a name here would teach people
        // to type names without reading them.
        [$lecturer, , $group] = $this->lecturerWithGroup();

        $student = User::factory()->student()->create();
        GroupMember::create(['group_id' => $group->id, 'user_id' => $student->id, 'joined_at' => now()]);

        $html = $this->actingAs($lecturer)
            ->get(route('lecturer.groups.show', $group))
            ->assertOk()
            ->assertSee('Remove this student?', false)
            ->getContent();

        $this->assertStringNotContainsString('confirmText:', $html);
    }

    /*
    | No browser-native dialogs left
    */

    public function test_notifications_use_the_app_dialog_not_the_browser_one(): void
    {
        $user = User::factory()->student()->create(['must_change_password' => false]);

        AppNotification::create([
            'user_id' => $user->id,
            'type' => 'task_assigned',
            'message' => 'Someone assigned you: Draft chapter 1',
            'is_read' => false,
        ]);

        $html = $this->actingAs($user)
            ->get(route('student.notifications'))
            ->assertOk()
            ->assertSee('Clear every notification?', false)
            ->assertSee('Remove this notification?', false)
            ->getContent();

        // wire:confirm raises the browser's own grey confirm() box, carrying
        // the site's hostname — the one dialog in the app that looked like it
        // belonged to something else.
        $this->assertStringNotContainsString('wire:confirm', $html);
    }

    public function test_no_screen_anywhere_falls_back_on_the_browser_dialog(): void
    {
        $offenders = [];

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(resource_path('views'), \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($files as $file) {
            if (! str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }

            $contents = file_get_contents($file->getPathname());

            // Strip Blade comments first: this file's own explanation of why
            // wire:confirm was dropped should not read as a violation.
            $contents = preg_replace('/\{\{--.*?--\}\}/s', '', $contents);

            if (str_contains($contents, 'wire:confirm') || preg_match('/\bwindow\.confirm\s*\(/', $contents)) {
                $offenders[] = $file->getFilename();
            }
        }

        $this->assertSame([], $offenders, 'Confirmations must use x-confirm-dialog, not the browser.');
    }
}
