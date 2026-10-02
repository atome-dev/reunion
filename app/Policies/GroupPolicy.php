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
     * Rename, delete, invite, manage members and create meetings.
     */
    public function manage(User $user, Group $group): Response
    {
        if (! $group->hasMember($user)) {
            return Response::denyAsNotFound();
        }

        return $group->isOrganizer($user) ? Response::allow() : Response::denyWithStatus(403);
    }

    public function leave(User $user, Group $group): bool
    {
        return $group->hasMember($user) && ! $group->isOrganizer($user);
    }
}
