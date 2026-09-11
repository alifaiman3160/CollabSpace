<?php

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * CollabSpace has no self-registration: only the Administrator creates
 * accounts. These tests pin that down so the Breeze scaffolding cannot
 * quietly come back.
 */
class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_registration_screen_is_gone(): void
    {
        $this->get('/register')->assertNotFound();
    }

    public function test_registration_cannot_be_posted_to(): void
    {
        $this->post('/register', [
            'name' => 'Walk In',
            'email' => 'walk.in@student.collabspace.test',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertNotFound();

        $this->assertDatabaseMissing('users', ['email' => 'walk.in@student.collabspace.test']);
    }

    public function test_no_named_register_route_is_registered(): void
    {
        $this->assertFalse(Route::has('register'));
    }

    public function test_the_sign_in_screen_does_not_offer_registration(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertDontSee('Create an account')
            ->assertSee('administrator creates it');
    }
}
