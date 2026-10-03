<?php

namespace App\Actions\Groups;

use App\Models\GroupInvitation;
use App\Notifications\GroupInvitationNotification;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

/**
 * Give the invitation a fresh secret link valid for 7 days and email it.
 */
class SendGroupInvitation
{
    public const int ValidityDays = 7;

    public function __invoke(GroupInvitation $invitation): void
    {
        $token = Str::random(40);

        $invitation->forceFill([
            'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addDays(self::ValidityDays),
        ])->save();

        rescue(fn () => Notification::route('mail', $invitation->email)
            ->notify(new GroupInvitationNotification($invitation, $token)), report: true);
    }
}
