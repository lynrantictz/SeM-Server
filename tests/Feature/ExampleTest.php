<?php

it('exposes a minimal public API status response', function () {
    $response = $this->getJson('/');

    $response->assertOk()
        ->assertExactJson([
            'name' => 'Paperstic API',
            'status' => 'operational',
            'version' => 'v1',
        ]);
});

it('exposes a health check without implementation details', function () {
    $this->getJson('/health')
        ->assertOk()
        ->assertExactJson(['status' => 'ok']);
});

it('returns JSON for unknown API routes', function () {
    $this->getJson('/v1/not-a-route')
        ->assertNotFound()
        ->assertExactJson(['message' => 'Not Found.']);
});
