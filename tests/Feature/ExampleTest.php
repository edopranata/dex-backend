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
        $index = tempnam(sys_get_temp_dir(), 'spa');
        file_put_contents($index, '<!doctype html>');
        config(['market.spa_index' => $index]);

        $response = $this->get('/');

        $response->assertStatus(200);
    }
}
