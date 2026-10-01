<?php

use App\Models\User;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Laravel\Fortify\Features;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\InvalidStateException;
use Laravel\Socialite\Two\User as GoogleUser;

/**
 * Build a fake Google user as returned by Socialite.
 *
 * @param  array{id?: string, name?: string|null, email?: string, email_verified?: bool}  $overrides
 */
function fakeGoogleUser(array $overrides = []): GoogleUser
{
    $data = array_merge([
        'id' => 'google-123',
        'name' => 'Amina Diallo',
        'email' => 'amina@example.com',
        'email_verified' => true,
    ], $overrides);

    return (new GoogleUser)
        ->setRaw(['sub' => $data['id'], 'email' => $data['email'], 'email_verified' => $data['email_verified']])
        ->map(['id' => $data['id'], 'name' => $data['name'], 'email' => $data['email']]);
}

test('the redirect route sends the visitor to google', function () {
    Socialite::fake('google');

    $this->get(route('auth.google.redirect'))->assertRedirect();
});

test('a new google user gets an account and is logged in', function () {
    Socialite::fake('google', fakeGoogleUser());

    $this->get(route('auth.google.callback'))->assertRedirect(route('dashboard'));

    $user = User::where('email', 'amina@example.com')->sole();
    expect($user->google_id)->toBe('google-123')
        ->and($user->hasPassword())->toBeFalse()
        ->and($user->hasVerifiedEmail())->toBeTrue();
    $this->assertAuthenticatedAs($user);
});

test('an account already linked to the google id is reused', function () {
    $user = User::factory()->withGoogle('google-123')->create(['email' => 'other@example.com']);
    Socialite::fake('google', fakeGoogleUser());

    $this->get(route('auth.google.callback'))->assertRedirect(route('dashboard'));

    $this->assertAuthenticatedAs($user);
    expect(User::count())->toBe(1);
});

test('an existing account with the same verified email is linked', function () {
    $user = User::factory()->create(['email' => 'amina@example.com']);
    Socialite::fake('google', fakeGoogleUser());

    $this->get(route('auth.google.callback'))->assertRedirect(route('dashboard'));

    expect($user->fresh()->google_id)->toBe('google-123');
    $this->assertAuthenticatedAs($user);
});

test('email matching ignores case', function () {
    $user = User::factory()->create(['email' => 'amina@example.com']);
    Socialite::fake('google', fakeGoogleUser(['email' => 'Amina@Example.com']));

    $this->get(route('auth.google.callback'))->assertRedirect(route('dashboard'));

    expect(User::count())->toBe(1)->and($user->fresh()->google_id)->toBe('google-123');
});

test('an unverified google email never takes over an existing account', function () {
    $user = User::factory()->create(['email' => 'amina@example.com']);
    Socialite::fake('google', fakeGoogleUser(['email_verified' => false]));

    $this->get(route('auth.google.callback'))
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors('google');

    expect($user->fresh()->google_id)->toBeNull();
    $this->assertGuest();
});

test('an account linked to another google id is not relinked', function () {
    $user = User::factory()->withGoogle('google-999')->create(['email' => 'amina@example.com']);
    Socialite::fake('google', fakeGoogleUser());

    $this->get(route('auth.google.callback'))
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors('google');

    expect($user->fresh()->google_id)->toBe('google-999');
    $this->assertGuest();
});

test('an unverified google email cannot create an account', function () {
    Event::fake([Registered::class]);
    Socialite::fake('google', fakeGoogleUser(['email_verified' => false]));

    $this->get(route('auth.google.callback'))
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors('google');

    expect(User::count())->toBe(0);
    Event::assertNotDispatched(Registered::class);
    $this->assertGuest();
});

test('linking an unverified local account revokes the credentials someone else may have set', function () {
    config(['session.driver' => 'database']);
    $user = User::factory()->unverified()->withTwoFactor()->create([
        'email' => 'amina@example.com',
        'remember_token' => 'attacker-remember-token',
    ]);
    DB::table('sessions')->insert([
        'id' => 'attacker-session',
        'user_id' => $user->id,
        'payload' => '',
        'last_activity' => time(),
    ]);
    Socialite::fake('google', fakeGoogleUser());

    $this->get(route('auth.google.callback'))->assertRedirect(route('dashboard'));

    $user->refresh();
    expect($user->google_id)->toBe('google-123')
        ->and($user->hasVerifiedEmail())->toBeTrue()
        ->and($user->hasPassword())->toBeFalse()
        ->and($user->two_factor_secret)->toBeNull()
        ->and($user->remember_token)->not->toBe('attacker-remember-token')
        ->and(DB::table('sessions')->where('id', 'attacker-session')->exists())->toBeFalse();
});

test('linking a verified local account keeps its password', function () {
    $user = User::factory()->create(['email' => 'amina@example.com']);
    Socialite::fake('google', fakeGoogleUser());

    $this->get(route('auth.google.callback'));

    expect($user->fresh()->hasPassword())->toBeTrue();
});

test('a google user without a name gets one from the email', function () {
    Socialite::fake('google', fakeGoogleUser(['name' => null]));

    $this->get(route('auth.google.callback'));

    expect(User::where('email', 'amina@example.com')->sole()->name)->toBe('amina');
});

test('two factor authentication is enforced after google', function () {
    $this->skipUnlessFortifyHas(Features::twoFactorAuthentication());
    User::factory()->withGoogle('google-123')->withTwoFactor()->create();
    Socialite::fake('google', fakeGoogleUser());

    $this->get(route('auth.google.callback'))
        ->assertRedirect(route('two-factor.login'))
        ->assertSessionHas('login.id');

    $this->assertGuest();
});

test('a failed google sign in returns to the login page', function () {
    Socialite::shouldReceive('driver->user')->andThrow(new InvalidStateException);

    $this->get(route('auth.google.callback'))
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors('google');

    $this->assertGuest();
});

test('a network failure reaching google returns to the login page', function () {
    Socialite::shouldReceive('driver->user')->andThrow(
        new ConnectException('Connection timed out', new Request('POST', 'https://oauth2.googleapis.com/token'))
    );

    $this->get(route('auth.google.callback'))
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors('google');
});

test('the intended url is restored after google sign in', function () {
    Socialite::fake('google', fakeGoogleUser());

    $this->get(route('profile.edit'))->assertRedirect(route('login'));

    $this->get(route('auth.google.callback'))->assertRedirect(route('profile.edit'));
});

test('a logged in user cannot start a google sign in', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('auth.google.redirect'))
        ->assertRedirect(route('dashboard'));
});

test('a logged in user replaying the callback is not switched to another account', function () {
    $user = User::factory()->create();
    Socialite::fake('google', fakeGoogleUser());

    $this->actingAs($user)->get(route('auth.google.callback'))->assertRedirect(route('dashboard'));

    $this->assertAuthenticatedAs($user);
    expect(User::count())->toBe(1);
});

test('login and register pages offer google', function (string $routeName) {
    $this->get(route($routeName))
        ->assertOk()
        ->assertSee(route('auth.google.redirect'), escape: false)
        ->assertSee(__('Continue with Google'));
})->with(['login', 'register']);
