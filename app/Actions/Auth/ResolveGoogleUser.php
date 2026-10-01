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
