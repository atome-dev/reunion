<?php

namespace App\Http\Controllers;

use App\Models\GroupInvitation;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

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

        if (! $invitation->group->hasMember($request->user())) {
            $invitation->group->addMember($request->user());
        }

        $invitation->forceFill([
            'accepted_at' => now(),
            'accepted_by' => $request->user()->id,
        ])->save();

        return redirect()->route('groups.show', $invitation->group);
    }
}
