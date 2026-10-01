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

    public static function cannotCreateWithUnverifiedEmail(): self
    {
        return new self(__('Your Google email address is not verified. Please create an account with your email address instead.'));
    }

    public static function linkedToAnotherGoogleAccount(): self
    {
        return new self(__('This account is already linked to another Google account.'));
    }
}
