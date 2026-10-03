<?php

namespace App\Policies;

use App\Enums\MeetingStatus;
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

    /**
     * Fill in one's own availability grid, while the request is collecting.
     */
    public function editAvailability(User $user, Meeting $meeting): bool
    {
        return $meeting->status === MeetingStatus::Collecting && $meeting->group->hasMember($user);
    }

    /**
     * Answer the slots put to a vote, while the vote is open.
     */
    public function vote(User $user, Meeting $meeting): bool
    {
        return $meeting->status === MeetingStatus::Voting && $meeting->group->hasMember($user);
    }
}
