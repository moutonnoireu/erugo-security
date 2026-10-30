<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    /**
     * A basic test example.
     */
    public function test_the_application_returns_a_successful_response(): void
    {
        $this->withoutVite();

        $response = $this->get('/');

        $response->assertStatus(200);
    }

    public function test_theme_and_status_colors_are_stylesheets(): void
    {
        $this->withoutVite();

        $page = $this->get('/')->assertOk()->getContent();
        $this->assertStringContainsString('href="/theme.css"', $page);
        $this->assertStringNotContainsString('<style', $page);
        $this->assertStringNotContainsString('googleapis', $page);
        $this->assertStringNotContainsString('cdnjs', $page);

        $this->get('/theme.css')->assertOk()
            ->assertHeader('Content-Type', 'text/css; charset=UTF-8')
            ->assertSee('--link-color:', false);

        $this->get('/status.css')->assertOk()
            ->assertHeader('Content-Type', 'text/css; charset=UTF-8')
            ->assertSee('--primary-color:', false);
    }
}
