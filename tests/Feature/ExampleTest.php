<?php

namespace Tests\Feature;

// use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * A basic test example.
     */
    public function test_the_application_returns_a_successful_response(): void
    {
        // The root URL is the sign-in page itself (see FrontPageTest)
        $this->withoutVite();
        $this->get('/')->assertOk()->assertSee('action="'.route('login').'"', false);
    }
}
