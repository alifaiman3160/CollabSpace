<?php

namespace Tests\Feature\Admin;

use App\Mail\AccountCreated;
use App\Mail\TemporaryPasswordIssued;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class AccountEmailTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create([
            'name' => 'Rosli bin Kamarudin', 'must_change_password' => false,
        ]);
    }

    protected function createStudent(): void
    {
        $this->actingAs($this->admin)->post(route('admin.users.store'), [
            'name' => 'Nurul Izzah Hamzah',
            'email' => 'nurul.izzah@student.collabspace.test',
            'role' => 'student',
            'matric_no' => 'A21EC0024',
        ]);
    }

    public function test_creating_an_account_sends_the_notification_email(): void
    {
        Mail::fake();

        $this->createStudent();

        Mail::assertSent(AccountCreated::class, function (AccountCreated $mail) {
            return $mail->hasTo('nurul.izzah@student.collabspace.test');
        });
    }

    public function test_the_email_carries_the_temporary_password_that_was_set(): void
    {
        Mail::fake();

        $this->createStudent();

        $user = User::where('email', 'nurul.izzah@student.collabspace.test')->sole();

        Mail::assertSent(AccountCreated::class, function (AccountCreated $mail) use ($user) {
            // The password in the email must be the one that actually works.
            return Hash::check($mail->temporaryPassword, $user->password);
        });
    }

    public function test_the_email_renders_with_the_details_a_new_user_needs(): void
    {
        Mail::fake();

        $this->createStudent();

        Mail::assertSent(AccountCreated::class, function (AccountCreated $mail) {
            $html = $mail->render();

            return str_contains($html, 'Nurul Izzah Hamzah')
                && str_contains($html, 'nurul.izzah@student.collabspace.test')
                && str_contains($html, $mail->temporaryPassword)
                && str_contains($html, 'A21EC0024')
                && str_contains($html, 'Student')
                && str_contains($html, route('login'))
                && str_contains($html, 'temporary');
        });
    }

    public function test_the_email_names_the_administrator_who_created_the_account(): void
    {
        Mail::fake();

        $this->createStudent();

        Mail::assertSent(AccountCreated::class, fn (AccountCreated $mail) => str_contains($mail->render(), 'Rosli bin Kamarudin'));
    }

    public function test_a_lecturer_account_does_not_mention_group_assignment(): void
    {
        Mail::fake();

        $this->actingAs($this->admin)->post(route('admin.users.store'), [
            'name' => 'Dr Tan', 'email' => 'tan@collabspace.test', 'role' => 'lecturer',
        ]);

        Mail::assertSent(AccountCreated::class, function (AccountCreated $mail) {
            $html = $mail->render();

            return str_contains($html, 'Lecturer')
                && ! str_contains($html, 'Your lecturer assigns you');
        });
    }

    public function test_a_rejected_creation_sends_nothing(): void
    {
        Mail::fake();

        $this->actingAs($this->admin)->post(route('admin.users.store'), [
            'name' => 'No Matric', 'email' => 'bad@student.collabspace.test', 'role' => 'student', 'matric_no' => '',
        ])->assertSessionHasErrors('matric_no');

        Mail::assertNothingSent();
    }

    public function test_the_admin_is_told_the_email_went_out(): void
    {
        Mail::fake();

        $this->createStudent();

        $this->assertStringContainsString(
            'emailed to nurul.izzah@student.collabspace.test',
            session('status'),
        );
    }

    /**
     * The account must survive a mail failure — losing a created user because
     * SMTP was down would be far worse than a missing email.
     */
    public function test_a_mail_failure_does_not_lose_the_account(): void
    {
        Mail::shouldReceive('to')->andThrow(new \RuntimeException('smtp is down'));

        $this->withoutExceptionHandling(['*']);

        $this->createStudent();

        $this->assertDatabaseHas('users', ['email' => 'nurul.izzah@student.collabspace.test']);
        $this->assertStringContainsString('could not be emailed', session('status'));
    }

    /*
    | Who gets to see the password
    */

    public function test_the_administrator_never_sees_a_password_that_was_emailed(): void
    {
        // It belongs to one person and reaches that person directly. A secret
        // on a screen is a secret in whatever is behind that screen.
        Mail::fake();

        $this->createStudent();

        $this->assertNull(session('temporary_password'));
        $this->assertNull(session('temporary_password_for'));
    }

    public function test_the_password_is_not_rendered_on_the_admin_screen(): void
    {
        Mail::fake();

        $this->createStudent();

        $password = null;
        Mail::assertSent(AccountCreated::class, function (AccountCreated $mail) use (&$password) {
            $password = $mail->temporaryPassword;

            return true;
        });

        $this->actingAs($this->admin)
            ->get(route('admin.users'))
            ->assertOk()
            ->assertDontSee($password)
            ->assertDontSee('Email failed');
    }

    public function test_the_password_is_shown_only_when_the_email_failed(): void
    {
        // Then there is no other channel, and the administrator is the only way
        // it can reach its owner. Rare by design, and loud when it happens.
        Mail::shouldReceive('to')->andThrow(new \RuntimeException('smtp is down'));

        $this->createStudent();

        $this->assertNotNull(session('temporary_password'));

        $user = User::where('email', 'nurul.izzah@student.collabspace.test')->sole();

        // And it is the password that actually works, not a stale one.
        $this->assertTrue(Hash::check(session('temporary_password'), $user->password));
    }

    public function test_a_reset_emails_the_new_password_to_its_owner(): void
    {
        // This used to go nowhere: the old password stopped working and the new
        // one existed only on the administrator's screen.
        Mail::fake();

        $user = User::factory()->create([
            'name' => 'Syafiq Idris',
            'email' => 'syafiq@student.collabspace.test',
        ]);

        $this->actingAs($this->admin)->put(route('admin.users.password', $user));

        Mail::assertSent(TemporaryPasswordIssued::class, function (TemporaryPasswordIssued $mail) use ($user) {
            return $mail->hasTo('syafiq@student.collabspace.test')
                && Hash::check($mail->temporaryPassword, $user->fresh()->password)
                && str_contains($mail->render(), 'Syafiq Idris')
                && str_contains($mail->render(), 'Rosli bin Kamarudin');
        });

        $this->assertNull(session('temporary_password'));
    }

    public function test_a_reset_that_cannot_be_emailed_falls_back_to_the_screen(): void
    {
        Mail::shouldReceive('to')->andThrow(new \RuntimeException('smtp is down'));

        $user = User::factory()->create(['name' => 'Syafiq Idris']);

        $this->actingAs($this->admin)->put(route('admin.users.password', $user));

        $this->assertNotNull(session('temporary_password'));
        $this->assertTrue(Hash::check(session('temporary_password'), $user->fresh()->password));
        $this->assertStringContainsString('could not be emailed', session('status'));
    }
}
