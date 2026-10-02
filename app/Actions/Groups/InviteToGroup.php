<?php

namespace App\Actions\Groups;

use App\Models\Group;
use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class InviteToGroup
{
    public function __construct(private SendGroupInvitation $sendGroupInvitation) {}

    /**
     * @return array{invited: list<string>, skipped: list<string>}
     *
     * @throws ValidationException
     */
    public function __invoke(Group $group, User $inviter, string $emails): array
    {
        $addresses = collect(preg_split('/[\s,;]+/', $emails, flags: PREG_SPLIT_NO_EMPTY))
            ->map(fn (string $email): string => Str::lower($email))
            ->unique()
            ->values();

        $invalid = $addresses->reject(fn (string $email): bool => Validator::make(['email' => $email], ['email' => 'email'])->passes());

        if ($addresses->isEmpty() || $invalid->isNotEmpty()) {
            throw ValidationException::withMessages([
                'invitationEmails' => $addresses->isEmpty()
                    ? __('Enter at least one email address.')
                    : __('These addresses are not valid: :emails', ['emails' => $invalid->implode(', ')]),
            ]);
        }

        $memberEmails = $group->members()->pluck('email')->map(fn (string $email): string => Str::lower($email));
        $pendingEmails = $group->invitations()->pending()->pluck('email');

        [$skipped, $toInvite] = $addresses->partition(fn (string $email): bool => $memberEmails->contains($email) || $pendingEmails->contains($email));

        foreach ($toInvite as $email) {
            $invitation = $group->invitations()->make(['email' => $email]);
            $invitation->forceFill([
                'invited_by' => $inviter->id,
                'token_hash' => hash('sha256', Str::random(40)),
                'expires_at' => now()->addDays(SendGroupInvitation::ValidityDays),
            ])->save();

            ($this->sendGroupInvitation)($invitation);
        }

        return ['invited' => $toInvite->values()->all(), 'skipped' => $skipped->values()->all()];
    }
}
