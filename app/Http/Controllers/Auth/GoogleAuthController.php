<?php

namespace App\Http\Controllers\Auth;

use App\Actions\Auth\ResolveGoogleUser;
use App\Exceptions\GoogleAccountRejected;
use App\Http\Controllers\Controller;
use App\Models\User;
use GuzzleHttp\Exception\GuzzleException;
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
     * Send the logged in user to Google to confirm their identity instead of typing a password.
     */
    public function confirm(Request $request): SymfonyRedirectResponse
    {
        $request->session()->put('google.confirming', true);

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
        } catch (InvalidStateException|GuzzleException) {
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
