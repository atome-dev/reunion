<?php

use App\Models\User;

test('a user can exist without a password', function () {
    $user = User::factory()->withoutPassword()->withGoogle('google-123')->create();

    expect($user->fresh()->hasPassword())->toBeFalse()
        ->and($user->fresh()->google_id)->toBe('google-123');
});

test('a regular user has a password', function () {
    expect(User::factory()->create()->hasPassword())->toBeTrue();
});

test('google id is never serialized', function () {
    $user = User::factory()->withGoogle()->create();

    expect($user->toArray())->not->toHaveKey('google_id');
});
