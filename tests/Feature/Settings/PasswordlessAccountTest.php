<?php

use App\Livewire\Settings\DeleteUserForm;
use App\Livewire\Settings\Security;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;

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

test('a passwordless user can set a password without the current one', function () {
    $user = User::factory()->withoutPassword()->withGoogle()->create();

    Livewire::actingAs($user)
        ->test(Security::class)
        ->set('password', 'new-Password-123')
        ->set('password_confirmation', 'new-Password-123')
        ->call('updatePassword')
        ->assertHasNoErrors();

    expect(Hash::check('new-Password-123', $user->fresh()->password))->toBeTrue();
});

test('a user with a password still needs the current one', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(Security::class)
        ->set('password', 'new-Password-123')
        ->set('password_confirmation', 'new-Password-123')
        ->call('updatePassword')
        ->assertHasErrors(['current_password']);
});

test('the security page offers to set a password and shows the google link', function () {
    $user = User::factory()->withoutPassword()->withGoogle()->create();

    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->get(route('security.edit'))
        ->assertSee(__('Set a password'))
        ->assertSee(__('Your account is linked to Google.'))
        ->assertDontSee(__('Current password'));
});

test('a passwordless user deletes their account by typing their email', function () {
    $user = User::factory()->withoutPassword()->withGoogle()->create();

    Livewire::actingAs($user)
        ->test(DeleteUserForm::class)
        ->set('email', strtoupper($user->email))
        ->call('deleteUser')
        ->assertHasNoErrors()
        ->assertRedirect('/');

    expect($user->fresh())->toBeNull();
});

test('a wrong email does not delete a passwordless account', function () {
    $user = User::factory()->withoutPassword()->withGoogle()->create();

    Livewire::actingAs($user)
        ->test(DeleteUserForm::class)
        ->set('email', 'someone-else@example.com')
        ->call('deleteUser')
        ->assertHasErrors(['email']);

    expect($user->fresh())->not->toBeNull();
});
