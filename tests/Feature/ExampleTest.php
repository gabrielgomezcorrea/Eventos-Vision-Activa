<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use RefreshDatabase;

    public function test_home_sends_guests_to_login()
    {
        $this->get(route('home'))->assertRedirect(route('login'));
    }

    public function test_home_sends_authenticated_users_to_dashboard()
    {
        $this->actingAs($this->usuarioConRol())
            ->get(route('home'))
            ->assertRedirect(route('dashboard'));
    }
}
