<?php

namespace App\Policies;

use App\Models\Meeting;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class MeetingPolicy
{
    public function __construct(private GroupPolicy $groups) {}

    public function view(User $user, Meeting $meeting): Response
    {
        return $this->groups->view($user, $meeting->group);
    }

    public function manage(User $user, Meeting $meeting): Response
    {
        return $this->groups->manage($user, $meeting->group);
    }

    public function respond(User $user, Meeting $meeting): bool
    {
        return $meeting->group->hasMember($user);
    }
}
