# Connexion avec Google — plan d'implémentation

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Permettre de se connecter et de s'inscrire avec Google, avec des comptes sans mot de passe pleinement gérables depuis les réglages.

**Architecture:** Laravel Socialite gère l'aller-retour OAuth. Une action `ResolveGoogleUser` trouve, relie ou crée le compte ; un contrôleur `GoogleAuthController` orchestre la connexion, l'écran 2FA de Fortify et la reconfirmation par Google. Le mot de passe devient facultatif ; les réglages (Sécurité, suppression) et la page de confirmation s'adaptent via `User::hasPassword()`.

**Tech Stack:** Laravel 13, Fortify, Livewire 4, Flux Pro, Pest 4, Laravel Socialite.

**Spec:** `docs/superpowers/specs/2026-10-01-google-login-design.md`

## Global Constraints

- Variables d'environnement : `GOOGLE_OAUTH_ID`, `GOOGLE_OAUTH_SECRET` (déjà présentes dans `.env`) ; ajoutées vides dans `.env.example`.
- Adresse de retour dérivée d'`APP_URL` : `{APP_URL}/auth/google/callback`, jamais codée en dur.
- Liaison par e-mail **uniquement** si Google déclare l'e-mail vérifié (`email_verified` dans les données brutes).
- La 2FA confirmée d'un compte n'est jamais contournée : écran de code Fortify avant toute session.
- « Se souvenir de moi » activé pour les connexions Google.
- Tout texte visible passe par `__()` avec sa traduction dans `lang/fr.json`.
- Conventions du projet : `php artisan make:*` avec `--no-interaction`, PHPDoc plutôt que commentaires en ligne, types de retour explicites, accolades systématiques, `vendor/bin/pint --dirty --format agent` avant chaque commit.
- Tests : Pest, `php artisan test --compact <fichier>`.

## Review Focus

1. **Casse de l'e-mail** : Google renvoie `Jean@Gmail.com`, le compte existe en `jean@gmail.com` → même compte, relié (pas de doublon ni d'erreur d'unicité). Test dans la tâche 2.
2. **Nouveau compte avec e-mail non vérifié chez Google** → compte créé mais **non** marqué vérifié, e-mail de vérification envoyé. Test dans la tâche 2.
3. **Compte déjà relié à un autre identifiant Google** avec le même e-mail → refus, `google_id` existant jamais écrasé. Test dans la tâche 2.
4. **Google sans nom** (`name` vide) → nom de repli = partie locale de l'e-mail. Test dans la tâche 2.
5. **URL demandée avant connexion** (ex. `/settings/profile`) → après connexion Google, retour sur cette URL. Test dans la tâche 2.

---

## Avant de commencer

Le travail précédent (style B, français) n'est pas encore commité sur `main`. L'exécutant ne commence qu'une fois ce travail commité (avec accord de l'utilisateur), puis crée une branche :

```bash
git switch -c feature/google-login
```

## Structure des fichiers

| Fichier | Rôle |
|---|---|
| `database/migrations/xxxx_add_google_id_to_users_table.php` | `google_id` + `password` nullable |
| `app/Models/User.php` | `hasPassword()`, `google_id` caché |
| `database/factories/UserFactory.php` | états `withoutPassword()`, `withGoogle()` |
| `config/services.php` | entrée `google` |
| `app/Exceptions/GoogleAccountRejected.php` | refus métier (e-mail non vérifié, compte déjà relié ailleurs) |
| `app/Actions/Auth/ResolveGoogleUser.php` | trouver / relier / créer le compte |
| `app/Http/Controllers/Auth/GoogleAuthController.php` | redirection, retour, confirmation |
| `routes/web.php` | 3 routes `auth/google/*` |
| `resources/views/components/google-button.blade.php` | bouton + logo + erreur `google` |
| `resources/views/livewire/auth/{login,register,confirm-password}.blade.php` | boutons Google |
| `app/Livewire/Settings/Security.php` + vue | définir un mot de passe, mention Google |
| `app/Livewire/Settings/DeleteUserForm.php` + vue | confirmation par e-mail |
| `lang/fr.json` | nouveaux textes |
| `tests/Feature/Auth/GoogleAuthenticationTest.php` | parcours de connexion |
| `tests/Feature/Auth/GoogleConfirmationTest.php` | reconfirmation |
| `tests/Feature/Settings/PasswordlessAccountTest.php` | réglages sans mot de passe |

---

### Task 1 : Socialite, configuration et données

**Files:**
- Modify: `composer.json` (via composer), `config/services.php`, `.env.example`, `app/Models/User.php`, `database/factories/UserFactory.php`
- Create: `database/migrations/xxxx_xx_xx_xxxxxx_add_google_id_to_users_table.php`
- Test: `tests/Feature/Settings/PasswordlessAccountTest.php`

**Interfaces:**
- Produces: colonne `users.google_id` (string, nullable, unique) ; `users.password` nullable ; `User::hasPassword(): bool` ; états de factory `withoutPassword()` et `withGoogle(?string $googleId = null)` ; `config('services.google')`.

- [ ] **Step 1 : Installer Socialite**

```bash
composer require laravel/socialite --no-interaction
```

Vérifier le chemin de la façade dans la version installée : `ls vendor/laravel/socialite/src/Facades/Socialite.php vendor/laravel/socialite/src/Socialite.php`. Utiliser dans tout le plan l'import qui existe (`Laravel\Socialite\Facades\Socialite` en v5 ; la doc 13.x montre `Laravel\Socialite\Socialite`).

- [ ] **Step 2 : Configuration**

Dans `config/services.php`, ajouter à la fin du tableau :

```php
    'google' => [
        'client_id' => env('GOOGLE_OAUTH_ID'),
        'client_secret' => env('GOOGLE_OAUTH_SECRET'),
        'redirect' => '/auth/google/callback',
    ],
```

(Socialite résout un chemin relatif en URL complète à partir d'`APP_URL`.)

Dans `.env.example`, ajouter :

```
GOOGLE_OAUTH_ID=
GOOGLE_OAUTH_SECRET=
```

- [ ] **Step 3 : Écrire le test qui échoue**

Créer le fichier : `php artisan make:test --pest Settings/PasswordlessAccountTest --no-interaction`, puis :

```php
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
```

- [ ] **Step 4 : Lancer le test, vérifier l'échec**

Run: `php artisan test --compact tests/Feature/Settings/PasswordlessAccountTest.php`
Expected: FAIL (`withoutPassword` indéfini).

- [ ] **Step 5 : Migration**

```bash
php artisan make:migration add_google_id_to_users_table --no-interaction
```

```php
public function up(): void
{
    Schema::table('users', function (Blueprint $table) {
        $table->string('google_id')->nullable()->unique()->after('email');
        $table->string('password')->nullable()->change();
    });
}

public function down(): void
{
    Schema::table('users', function (Blueprint $table) {
        $table->dropUnique(['google_id']);
        $table->dropColumn('google_id');
        $table->string('password')->nullable(false)->change();
    });
}
```

- [ ] **Step 6 : Modèle**

Dans `app/Models/User.php` :
- PHPDoc : `@property string|null $google_id` et remplacer `@property string $password` par `@property string|null $password` ;
- `#[Hidden([...])]` : ajouter `'google_id'` ;
- ne **pas** ajouter `google_id` à `#[Fillable]` (renseigné par `forceFill` dans l'action) ;
- ajouter :

```php
    /**
     * Determine whether the user has set a password (accounts created with Google may not).
     */
    public function hasPassword(): bool
    {
        return filled($this->password);
    }
```

- [ ] **Step 7 : Factory**

Dans `database/factories/UserFactory.php`, ajouter :

```php
    /**
     * Indicate that the user has no password (account created with Google).
     */
    public function withoutPassword(): static
    {
        return $this->state(fn (array $attributes) => [
            'password' => null,
        ]);
    }

    /**
     * Indicate that the user is linked to a Google account.
     */
    public function withGoogle(?string $googleId = null): static
    {
        return $this->state(fn (array $attributes) => [
            'google_id' => $googleId ?? (string) fake()->unique()->numerify('####################'),
        ]);
    }
```

- [ ] **Step 8 : Migrer et relancer le test**

Run: `php artisan migrate --no-interaction && php artisan test --compact tests/Feature/Settings/PasswordlessAccountTest.php`
Expected: PASS (3 tests).

- [ ] **Step 9 : Commit**

```bash
vendor/bin/pint --dirty --format agent
git add composer.json composer.lock config/services.php .env.example app/Models/User.php database/factories/UserFactory.php database/migrations tests/Feature/Settings/PasswordlessAccountTest.php
git commit -m "Add Socialite, google_id column and optional password"
```

---

### Task 2 : Connexion et inscription via Google

**Files:**
- Create: `app/Exceptions/GoogleAccountRejected.php`, `app/Actions/Auth/ResolveGoogleUser.php`, `app/Http/Controllers/Auth/GoogleAuthController.php`
- Modify: `routes/web.php`, `lang/fr.json`
- Test: `tests/Feature/Auth/GoogleAuthenticationTest.php`

**Interfaces:**
- Consumes: `User::hasPassword()`, états `withGoogle()` / `withoutPassword()` / `withTwoFactor()` (Task 1).
- Produces:
  - `App\Actions\Auth\ResolveGoogleUser::__invoke(Laravel\Socialite\Two\User $googleUser): App\Models\User` (lève `GoogleAccountRejected`) ;
  - `App\Exceptions\GoogleAccountRejected` avec `::unverifiedEmail(): self` et `::linkedToAnotherGoogleAccount(): self` ;
  - routes nommées `auth.google.redirect` (GET `/auth/google/redirect`, `guest`), `auth.google.callback` (GET `/auth/google/callback`, sans middleware d'auth) ;
  - `GoogleAuthController::redirect()`, `::callback()` ; clé de session `google.confirming` (lue ici, écrite en Task 3) ; clé d'erreur de validation `google`.

- [ ] **Step 1 : Écrire les tests qui échouent**

`php artisan make:test --pest Auth/GoogleAuthenticationTest --no-interaction`, puis (adapter l'import de façade selon Task 1 Step 1) :

```php
<?php

use App\Models\User;
use Illuminate\Auth\Events\Registered;
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

test('a new account with an unverified google email must verify its email', function () {
    Event::fake([Registered::class]);
    Socialite::fake('google', fakeGoogleUser(['email_verified' => false]));

    $this->get(route('auth.google.callback'));

    $user = User::where('email', 'amina@example.com')->sole();
    expect($user->hasVerifiedEmail())->toBeFalse();
    Event::assertDispatched(Registered::class);
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
```

- [ ] **Step 2 : Lancer les tests, vérifier l'échec**

Run: `php artisan test --compact tests/Feature/Auth/GoogleAuthenticationTest.php`
Expected: FAIL (route `auth.google.redirect` inexistante).

- [ ] **Step 3 : Exception métier**

`php artisan make:exception GoogleAccountRejected --no-interaction`, puis :

```php
<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Raised when a Google account cannot be used to sign in to, or link with, an existing account.
 */
class GoogleAccountRejected extends RuntimeException
{
    public static function unverifiedEmail(): self
    {
        return new self(__('Your Google email address is not verified. Please sign in with your email and password.'));
    }

    public static function linkedToAnotherGoogleAccount(): self
    {
        return new self(__('This account is already linked to another Google account.'));
    }
}
```

- [ ] **Step 4 : Action**

`php artisan make:class Actions/Auth/ResolveGoogleUser --no-interaction`, puis :

```php
<?php

namespace App\Actions\Auth;

use App\Exceptions\GoogleAccountRejected;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Str;
use Laravel\Socialite\Two\User as GoogleUser;

/**
 * Find, link or create the local account matching a Google user.
 */
class ResolveGoogleUser
{
    /**
     * @throws GoogleAccountRejected
     */
    public function __invoke(GoogleUser $googleUser): User
    {
        $googleId = (string) $googleUser->getId();
        $email = Str::lower((string) $googleUser->getEmail());

        $user = User::where('google_id', $googleId)->first();

        if ($user) {
            return $user;
        }

        $user = User::whereRaw('lower(email) = ?', [$email])->first();

        if ($user) {
            return $this->link($user, $googleId, $googleUser);
        }

        return $this->create($googleUser, $googleId, $email);
    }

    /**
     * Link an existing account, only when Google vouches for the email address.
     */
    private function link(User $user, string $googleId, GoogleUser $googleUser): User
    {
        if (! $this->hasVerifiedEmail($googleUser)) {
            throw GoogleAccountRejected::unverifiedEmail();
        }

        if (filled($user->google_id)) {
            throw GoogleAccountRejected::linkedToAnotherGoogleAccount();
        }

        $user->forceFill([
            'google_id' => $googleId,
            'email_verified_at' => $user->email_verified_at ?? now(),
        ])->save();

        return $user;
    }

    private function create(GoogleUser $googleUser, string $googleId, string $email): User
    {
        $user = new User;

        $user->forceFill([
            'name' => filled($googleUser->getName()) ? $googleUser->getName() : Str::before($email, '@'),
            'email' => $email,
            'google_id' => $googleId,
            'email_verified_at' => $this->hasVerifiedEmail($googleUser) ? now() : null,
        ])->save();

        event(new Registered($user));

        return $user;
    }

    private function hasVerifiedEmail(GoogleUser $googleUser): bool
    {
        return filter_var($googleUser->getRaw()['email_verified'] ?? false, FILTER_VALIDATE_BOOLEAN);
    }
}
```

- [ ] **Step 5 : Contrôleur**

`php artisan make:controller Auth/GoogleAuthController --no-interaction`, puis :

```php
<?php

namespace App\Http\Controllers\Auth;

use App\Actions\Auth\ResolveGoogleUser;
use App\Exceptions\GoogleAccountRejected;
use App\Http\Controllers\Controller;
use App\Models\User;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Fortify\Events\TwoFactorAuthenticationChallenged;
use Laravel\Fortify\Features;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\InvalidStateException;
use Laravel\Socialite\Two\User as GoogleUser;
use Symfony\Component\HttpFoundation\RedirectResponse as SymfonyRedirectResponse;

class GoogleAuthController extends Controller
{
    /**
     * Send the visitor to Google to sign in or sign up.
     */
    public function redirect(): SymfonyRedirectResponse
    {
        return Socialite::driver('google')->redirect();
    }

    /**
     * Handle the user coming back from Google.
     */
    public function callback(Request $request, ResolveGoogleUser $resolveGoogleUser): RedirectResponse
    {
        $confirming = (bool) $request->session()->pull('google.confirming', false);

        try {
            /** @var GoogleUser $googleUser */
            $googleUser = Socialite::driver('google')->user();
        } catch (InvalidStateException|RequestException) {
            return $this->failed($confirming && $request->user() ? 'password.confirm' : 'login', __('Google sign-in failed. Please try again.'));
        }

        if ($request->user()) {
            return $confirming
                ? $this->confirmPassword($request, $googleUser)
                : redirect()->route('dashboard');
        }

        try {
            $user = $resolveGoogleUser($googleUser);
        } catch (GoogleAccountRejected $exception) {
            return $this->failed('login', $exception->getMessage());
        }

        return $this->login($request, $user);
    }

    /**
     * Log the user in, going through the two-factor challenge when it is enabled.
     */
    private function login(Request $request, User $user): RedirectResponse
    {
        if (Features::enabled(Features::twoFactorAuthentication()) && $user->hasEnabledTwoFactorAuthentication()) {
            $request->session()->put([
                'login.id' => $user->getKey(),
                'login.remember' => true,
            ]);

            TwoFactorAuthenticationChallenged::dispatch($user);

            return redirect()->route('two-factor.login');
        }

        Auth::login($user, remember: true);

        $request->session()->regenerate();

        return redirect()->intended(route('dashboard'));
    }

    /**
     * Treat a round trip to the linked Google account as a password confirmation.
     */
    private function confirmPassword(Request $request, GoogleUser $googleUser): RedirectResponse
    {
        if ((string) $googleUser->getId() !== $request->user()->google_id) {
            return $this->failed('password.confirm', __('This Google account is not linked to your account.'));
        }

        $request->session()->put('auth.password_confirmed_at', time());

        return redirect()->intended(route('dashboard'));
    }

    private function failed(string $routeName, string $message): RedirectResponse
    {
        return redirect()->route($routeName)->withErrors(['google' => $message]);
    }
}
```

- [ ] **Step 6 : Routes**

Dans `routes/web.php`, après la route `home` :

```php
use App\Http\Controllers\Auth\GoogleAuthController;

Route::middleware('guest')->group(function () {
    Route::get('auth/google/redirect', [GoogleAuthController::class, 'redirect'])->name('auth.google.redirect');
});

Route::get('auth/google/callback', [GoogleAuthController::class, 'callback'])->name('auth.google.callback');
```

- [ ] **Step 7 : Traductions**

Ajouter à `lang/fr.json` (garder le tri alphabétique insensible à la casse) :

```json
"Google sign-in failed. Please try again.": "La connexion avec Google a échoué. Veuillez réessayer.",
"This account is already linked to another Google account.": "Ce compte est déjà relié à un autre compte Google.",
"This Google account is not linked to your account.": "Ce compte Google n'est pas relié à votre compte.",
"Your Google email address is not verified. Please sign in with your email and password.": "Votre adresse e-mail Google n'est pas vérifiée. Connectez-vous avec votre e-mail et votre mot de passe."
```

- [ ] **Step 8 : Lancer les tests**

Run: `php artisan test --compact tests/Feature/Auth/GoogleAuthenticationTest.php`
Expected: PASS (14 tests). Si `Socialite::fake` n'existe pas dans la version installée, remplacer par `Socialite::shouldReceive('driver->user')->andReturn(fakeGoogleUser(...))` et `Socialite::shouldReceive('driver->redirect')->andReturn(redirect('https://accounts.google.com'))`.

- [ ] **Step 9 : Commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Exceptions app/Actions/Auth app/Http/Controllers/Auth routes/web.php lang/fr.json tests/Feature/Auth/GoogleAuthenticationTest.php
git commit -m "Sign in and sign up with Google"
```

---

### Task 3 : Reconfirmation via Google

**Files:**
- Modify: `routes/web.php`, `app/Http/Controllers/Auth/GoogleAuthController.php`, `resources/views/livewire/auth/confirm-password.blade.php`
- Create: `resources/views/components/google-button.blade.php`
- Test: `tests/Feature/Auth/GoogleConfirmationTest.php`

**Interfaces:**
- Consumes: `GoogleAuthController::callback()` et sa branche `confirmPassword()` (Task 2) ; `User::hasPassword()`.
- Produces: route `auth.google.confirm` (GET `/auth/google/confirm`, `auth`) ; méthode `GoogleAuthController::confirm(Request $request)` ; composant `<x-google-button :href :label />` (réutilisé en Task 5).

- [ ] **Step 1 : Écrire les tests qui échouent**

`php artisan make:test --pest Auth/GoogleConfirmationTest --no-interaction` :

```php
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
```

- [ ] **Step 2 : Lancer les tests, vérifier l'échec**

Run: `php artisan test --compact tests/Feature/Auth/GoogleConfirmationTest.php`
Expected: FAIL (route `auth.google.confirm` inexistante).

- [ ] **Step 3 : Route et méthode**

Dans `routes/web.php` :

```php
Route::middleware('auth')->group(function () {
    Route::get('auth/google/confirm', [GoogleAuthController::class, 'confirm'])->name('auth.google.confirm');
});
```

Dans `GoogleAuthController` :

```php
    /**
     * Send the logged in user to Google to confirm their identity instead of typing a password.
     */
    public function confirm(Request $request): SymfonyRedirectResponse
    {
        $request->session()->put('google.confirming', true);

        return Socialite::driver('google')->redirect();
    }
```

- [ ] **Step 4 : Composant bouton**

`resources/views/components/google-button.blade.php` (logo « G » officiel, quatre couleurs) :

```blade
@props([
    'href',
    'label',
])

<div class="flex flex-col gap-2">
    <flux:button :href="$href" class="w-full">
        <svg viewBox="0 0 48 48" class="size-5" aria-hidden="true">
            <path fill="#FFC107" d="M43.6 20.5H42V20H24v8h11.3C33.7 32.7 29.2 36 24 36c-6.6 0-12-5.4-12-12s5.4-12 12-12c3.1 0 5.8 1.2 7.9 3.1l5.7-5.7C34 6.1 29.3 4 24 4 12.9 4 4 12.9 4 24s8.9 20 20 20 20-8.9 20-20c0-1.3-.1-2.4-.4-3.5z"/>
            <path fill="#FF3D00" d="m6.3 14.7 6.6 4.8C14.7 15.1 19 12 24 12c3.1 0 5.8 1.2 7.9 3.1l5.7-5.7C34 6.1 29.3 4 24 4 16.3 4 9.7 8.3 6.3 14.7z"/>
            <path fill="#4CAF50" d="M24 44c5.2 0 9.9-2 13.4-5.2l-6.2-5.2C29.2 35.1 26.7 36 24 36c-5.2 0-9.6-3.3-11.3-7.9l-6.5 5C9.5 39.6 16.2 44 24 44z"/>
            <path fill="#1976D2" d="M43.6 20.5H42V20H24v8h11.3c-.8 2.2-2.2 4.2-4.1 5.6l6.2 5.2C36.9 39.2 44 34 44 24c0-1.3-.1-2.4-.4-3.5z"/>
        </svg>
        {{ $label }}
    </flux:button>

    <flux:error name="google" />
</div>
```

- [ ] **Step 5 : Page de confirmation**

Dans `resources/views/livewire/auth/confirm-password.blade.php`, juste après `<x-auth-session-status ... />` :

```blade
        @if (auth()->user()->google_id)
            <x-google-button :href="route('auth.google.confirm')" :label="__('Confirm with Google')" />
        @endif
```

et entourer le `<form method="POST" action="{{ route('password.confirm.store') }}" ...>...</form>` de `@if (auth()->user()->hasPassword()) ... @endif`.

Ajouter à `lang/fr.json` : `"Confirm with Google": "Confirmer avec Google"`.

- [ ] **Step 6 : Lancer les tests**

Run: `php artisan test --compact tests/Feature/Auth/GoogleConfirmationTest.php tests/Feature/Auth/PasswordConfirmationTest.php`
Expected: PASS.

- [ ] **Step 7 : Commit**

```bash
vendor/bin/pint --dirty --format agent
git add routes/web.php app/Http/Controllers/Auth/GoogleAuthController.php resources/views/components/google-button.blade.php resources/views/livewire/auth/confirm-password.blade.php lang/fr.json tests/Feature/Auth/GoogleConfirmationTest.php
git commit -m "Confirm identity with Google for passwordless accounts"
```

---

### Task 4 : Réglages pour les comptes sans mot de passe

**Files:**
- Modify: `app/Livewire/Settings/Security.php`, `resources/views/livewire/settings/security.blade.php`, `app/Livewire/Settings/DeleteUserForm.php`, `resources/views/livewire/settings/delete-user-form.blade.php`, `lang/fr.json`
- Test: `tests/Feature/Settings/PasswordlessAccountTest.php` (ajouts)

**Interfaces:**
- Consumes: `User::hasPassword()`, état `withoutPassword()` (Task 1).
- Produces: propriété Livewire `DeleteUserForm::$email` (string).

- [ ] **Step 1 : Ajouter les tests qui échouent**

À la fin de `tests/Feature/Settings/PasswordlessAccountTest.php` (ajouter les `use` en tête : `App\Livewire\Settings\DeleteUserForm`, `App\Livewire\Settings\Security`, `Illuminate\Support\Facades\Hash`, `Livewire\Livewire`) :

```php
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
```

- [ ] **Step 2 : Lancer les tests, vérifier l'échec**

Run: `php artisan test --compact tests/Feature/Settings/PasswordlessAccountTest.php`
Expected: FAIL (mot de passe actuel exigé ; propriété `email` inexistante).

- [ ] **Step 3 : Security::updatePassword**

Remplacer le bloc de validation de `updatePassword()` par :

```php
            $validated = $this->validate(array_filter([
                'current_password' => Auth::user()->hasPassword() ? $this->currentPasswordRules() : null,
                'password' => $this->passwordRules(),
            ]));
```

- [ ] **Step 4 : Vue Sécurité**

Dans `resources/views/livewire/settings/security.blade.php`, ligne 6, remplacer l'ouverture du layout par :

```blade
    <x-settings.layout
        :heading="auth()->user()->hasPassword() ? __('Update password') : __('Set a password')"
        :subheading="auth()->user()->hasPassword() ? __('Ensure your account is using a long, random password to stay secure') : __('Add a password to also sign in with your email address')"
    >
        @if (auth()->user()->google_id)
            <flux:callout icon="check-circle" class="mt-6">
                <flux:callout.text>{{ __('Your account is linked to Google.') }}</flux:callout.text>
            </flux:callout>
        @endif
```

et entourer le champ `current_password` (lignes 8-14) de `@if (auth()->user()->hasPassword()) ... @endif`.

- [ ] **Step 5 : Suppression du compte**

`DeleteUserForm` :

```php
    public string $password = '';

    public string $email = '';

    /**
     * Delete the currently authenticated user.
     */
    public function deleteUser(Logout $logout): void
    {
        $user = Auth::user();

        if ($user->hasPassword()) {
            $this->validate(['password' => $this->currentPasswordRules()]);
        } else {
            $this->validate(['email' => ['required', 'string', 'email']]);

            if (Str::lower($this->email) !== Str::lower($user->email)) {
                throw ValidationException::withMessages(['email' => __('The email address does not match your account.')]);
            }
        }

        tap($user, $logout(...))->delete();

        $this->redirect('/', navigate: true);
    }
```

(imports : `Illuminate\Support\Str`, `Illuminate\Validation\ValidationException`.)

Vue `delete-user-form.blade.php` : remplacer le `<flux:subheading>` et le champ mot de passe par :

```blade
                <flux:subheading>
                    @if (auth()->user()->hasPassword())
                        {{ __('Once your account is deleted, all of its resources and data will be permanently deleted. Please enter your password to confirm you would like to permanently delete your account.') }}
                    @else
                        {{ __('Once your account is deleted, all of its resources and data will be permanently deleted. Please enter your email address to confirm you would like to permanently delete your account.') }}
                    @endif
                </flux:subheading>
            </div>

            @if (auth()->user()->hasPassword())
                <flux:input wire:model="password" :label="__('Password')" type="password" viewable />
            @else
                <flux:input wire:model="email" :label="__('Email address')" type="email" autocomplete="off" />
            @endif
```

- [ ] **Step 6 : Traductions**

Ajouter à `lang/fr.json` :

```json
"Add a password to also sign in with your email address": "Ajoutez un mot de passe pour pouvoir aussi vous connecter avec votre e-mail",
"Once your account is deleted, all of its resources and data will be permanently deleted. Please enter your email address to confirm you would like to permanently delete your account.": "Une fois votre compte supprimé, toutes ses données seront définitivement effacées. Saisissez votre adresse e-mail pour confirmer la suppression définitive de votre compte.",
"Set a password": "Définir un mot de passe",
"The email address does not match your account.": "Cette adresse e-mail ne correspond pas à votre compte.",
"Your account is linked to Google.": "Votre compte est relié à Google."
```

- [ ] **Step 7 : Lancer les tests**

Run: `php artisan test --compact tests/Feature/Settings`
Expected: PASS (nouveaux tests + SecurityTest et ProfileUpdateTest existants).

- [ ] **Step 8 : Commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Livewire/Settings resources/views/livewire/settings lang/fr.json tests/Feature/Settings/PasswordlessAccountTest.php
git commit -m "Support passwordless accounts in settings"
```

---

### Task 5 : Boutons Google sur la connexion et l'inscription

**Files:**
- Modify: `resources/views/livewire/auth/login.blade.php`, `resources/views/livewire/auth/register.blade.php`, `lang/fr.json`
- Test: `tests/Feature/Auth/GoogleAuthenticationTest.php` (ajout)

**Interfaces:**
- Consumes: `<x-google-button :href :label />` (Task 3), route `auth.google.redirect` (Task 2).

- [ ] **Step 1 : Ajouter le test qui échoue**

À la fin de `tests/Feature/Auth/GoogleAuthenticationTest.php` :

```php
test('login and register pages offer google', function (string $routeName) {
    $this->get(route($routeName))
        ->assertOk()
        ->assertSee(route('auth.google.redirect'), escape: false)
        ->assertSee(__('Continue with Google'));
})->with(['login', 'register']);
```

- [ ] **Step 2 : Lancer le test, vérifier l'échec**

Run: `php artisan test --compact tests/Feature/Auth/GoogleAuthenticationTest.php --filter=offer`
Expected: FAIL.

- [ ] **Step 3 : Vues**

Dans `login.blade.php` et `register.blade.php`, juste après `<x-auth-session-status ... />` :

```blade
        <x-google-button :href="route('auth.google.redirect')" :label="__('Continue with Google')" />
```

Dans `register.blade.php` uniquement, juste après ce bouton, ajouter le séparateur (la page de connexion a déjà « Ou continuez avec votre e-mail » via `<x-passkey-verify>`) :

```blade
        <flux:separator :text="__('Or continue with email')" />
```

Ajouter à `lang/fr.json` : `"Continue with Google": "Continuer avec Google"`.

- [ ] **Step 4 : Lancer les tests**

Run: `php artisan test --compact tests/Feature/Auth`
Expected: PASS.

- [ ] **Step 5 : Commit**

```bash
vendor/bin/pint --dirty --format agent
git add resources/views/livewire/auth/login.blade.php resources/views/livewire/auth/register.blade.php lang/fr.json tests/Feature/Auth/GoogleAuthenticationTest.php
git commit -m "Add Google buttons to login and register"
```

---

### Task 6 : Vérification finale

**Files:** aucun nouveau (corrections éventuelles seulement).

- [ ] **Step 1 : Suite complète et build**

Run: `npm run build && php artisan test --compact`
Expected: tout passe.

- [ ] **Step 2 : Vérification visuelle**

Avec le serveur (`php artisan serve` sur `http://localhost:8000`) : capturer connexion et inscription en clair et en sombre, desktop 1440 px et mobile 390 px ; réglages Sécurité et suppression pour un compte sans mot de passe (créer via factory en tinker **seulement avec accord de l'utilisateur**, ou réutiliser `test@reunion.test` après `password` mis à `null` avec accord).

- [ ] **Step 3 : Parcours Google réel**

Si l'utilisateur a déclaré `http://localhost:8000/auth/google/callback` dans la Google Cloud Console : lancer `php artisan serve` avec `APP_URL=http://localhost:8000` le temps du test, cliquer « Continuer avec Google », vérifier création du compte, déconnexion, reconnexion, page « Confirmer » via Google. Sinon, consigner que le parcours réel reste à faire et pourquoi.

- [ ] **Step 4 : Rapport**

Lister à l'utilisateur : ce qui est vérifié, ce qui ne l'est pas, les étapes Google Cloud Console restantes (écran de consentement, adresses de retour local et production, variables sur Forge).
