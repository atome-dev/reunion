<?php

namespace App\Livewire\Settings;

use App\Concerns\PasswordValidationRules;
use App\Livewire\Actions\Logout;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

class DeleteUserForm extends Component
{
    use PasswordValidationRules;

    public string $password = '';

    public string $email = '';

    /**
     * Delete the currently authenticated user, confirmed by password, or by email for accounts without one.
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
}
