<?php

use App\Models\User;

test('visitors can open the about dialog from the home page', function () {
    $this->get(route('home'))
        ->assertOk()
        ->assertSee(__('About'))
        ->assertSee('Nicolas Chauvet, Atome Dev')
        ->assertSee(__('MIT license'))
        ->assertSee('https://github.com/atome-dev/reunion', false);
});

test('signed-in users find the about dialog in the app', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee(__('About'))
        ->assertSee('Nicolas Chauvet, Atome Dev')
        ->assertSee('https://github.com/atome-dev/reunion/blob/main/LICENSE', false);
});

test('the pages use the Réunion favicon', function () {
    expect(file_get_contents(public_path('favicon.svg')))->toContain('#ffcf3d')
        ->and(public_path('favicon.ico'))->toBeFile()
        ->and(public_path('apple-touch-icon.png'))->toBeFile();
});
