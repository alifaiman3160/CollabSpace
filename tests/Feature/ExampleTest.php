<?php

namespace Tests\Feature;

use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * CollabSpace has no public landing page - the root sends you to sign in,
     * or on to your dashboard once you are authenticated.
     */
    public function test_the_root_redirects_a_guest_to_sign_in(): void
    {
        $this->get('/')->assertRedirect(route('login'));
    }
}
