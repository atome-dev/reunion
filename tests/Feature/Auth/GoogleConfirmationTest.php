<?php

use App\Models\User;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as GoogleUser;

function googleUserWithId(string $id): GoogleUser
{
    return (new GoogleUser)
        ->setRaw(['sub' => $id, 'email_verified' => true])
        ->map(['id' => $id, 'name' => 'Amina', 'email' => 'amina@example.com']);
}

test('the confirm route marks the confirmation intent and goes to google', function () {
    Socialite::fake('google');
    $user = User::factory()->withoutPassword()->withGoogle('google-123')->create();

    $this->actingAs($user)
        ->get(route('auth.google.confirm'))
        ->assertRedirect()
        ->assertSessionHas('google.confirming', true);
});

test('the linked google account confirms the password', function () {
    Socialite::fake('google', googleUserWithId('google-123'));
    $user = User::factory()->withoutPassword()->withGoogle('google-123')->create();

    $this->actingAs($user)
        ->withSession(['google.confirming' => true, 'url.intended' => route('security.edit')])
        ->get(route('auth.google.callback'))
        ->assertRedirect(route('security.edit'))
        ->assertSessionHas('auth.password_confirmed_at');
});

test('another google account does not confirm the password', function () {
    Socialite::fake('google', googleUserWithId('google-999'));
    $user = User::factory()->withoutPassword()->withGoogle('google-123')->create();

    $this->actingAs($user)
        ->withSession(['google.confirming' => true])
        ->get(route('auth.google.callback'))
        ->assertRedirect(route('password.confirm'))
        ->assertSessionHasErrors('google')
        ->assertSessionMissing('auth.password_confirmed_at');
});

test('the confirm page offers google and hides the password field for passwordless accounts', function () {
    $user = User::factory()->withoutPassword()->withGoogle()->create();

    $this->actingAs($user)
        ->get(route('password.confirm'))
        ->assertSee(route('auth.google.confirm'), escape: false)
        ->assertDontSee('name="password"', escape: false);
});

test('the confirm page keeps the password field for accounts with a password', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('password.confirm'))
        ->assertSee('name="password"', escape: false)
        ->assertDontSee(route('auth.google.confirm'), escape: false);
});

test('the confirm page does not mention a password to passwordless accounts', function () {
    $user = User::factory()->withoutPassword()->withGoogle()->create();

    $this->actingAs($user)
        ->get(route('password.confirm'))
        ->assertDontSee(__('Or confirm with password'));
});
