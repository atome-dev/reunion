<?php

namespace App\Http\Controllers;

use App\Models\GroupInvitation;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class GroupInvitationController extends Controller
{
    public function show(Request $request, string $token): View
    {
        $invitation = GroupInvitation::findByToken($token);

        if (! $invitation?->isUsable()) {
            return view('invitations.invalid');
        }

        if (! $request->user()) {
            redirect()->setIntendedUrl(route('invitations.show', $token));
        }

        return view('invitations.show', ['invitation' => $invitation, 'token' => $token]);
    }

    public function accept(Request $request, string $token): View|RedirectResponse
    {
        $invitation = GroupInvitation::findByToken($token);

        if (! $invitation?->isUsable()) {
            return view('invitations.invalid');
        }

        $claimed = DB::transaction(function () use ($invitation, $request): bool {
            $updated = GroupInvitation::query()
                ->whereKey($invitation->id)
                ->whereNull('accepted_at')
                ->where('expires_at', '>', now())
                ->update(['accepted_at' => now(), 'accepted_by' => $request->user()->id]);

            if ($updated !== 1) {
                return false;
            }

            if (! $invitation->group->hasMember($request->user())) {
                $invitation->group->addMember($request->user());
            }

            return true;
        });

        if (! $claimed) {
            return view('invitations.invalid');
        }

        return redirect()->route('groups.show', $invitation->group);
    }
}
