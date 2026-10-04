<?php

namespace App\Policies;

use App\Models\Group;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class GroupPolicy
{
    /**
     * Members see the group; for anyone else it does not exist.
     */
    public function view(User $user, Group $group): Response
    {
        return $group->hasMember($user) ? Response::allow() : Response::denyAsNotFound();
    }

    /**
     * Rename, delete, remove members and change the settings: the group creator only.
     */
    public function update(User $user, Group $group): Response
    {
        return $this->allowMember($user, $group, $group->isCreator($user));
    }

    public function requestMeeting(User $user, Group $group): Response
    {
        return $this->allowMember($user, $group, $group->allowsMeetingRequests($user));
    }

    /**
     * Invite people, resend or cancel pending invitations.
     */
    public function invite(User $user, Group $group): Response
    {
        return $this->allowMember($user, $group, $group->allowsInvitations($user));
    }

    public function leave(User $user, Group $group): bool
    {
        return $group->hasMember($user) && ! $group->isCreator($user);
    }

    private function allowMember(User $user, Group $group, bool $allowed): Response
    {
        if (! $group->hasMember($user)) {
            return Response::denyAsNotFound();
        }

        return $allowed ? Response::allow() : Response::denyWithStatus(403);
    }
}
