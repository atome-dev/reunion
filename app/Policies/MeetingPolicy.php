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

    /**
     * Edit or delete the request: its author or the group creator.
     */
    public function update(User $user, Meeting $meeting): Response
    {
        $group = $meeting->group;

        if (! $group->hasMember($user)) {
            return Response::denyAsNotFound();
        }

        return $meeting->created_by === $user->id || $group->isCreator($user) ? Response::allow() : Response::denyWithStatus(403);
    }

    /**
     * See the summary, confirm a date, open, settle or cancel the vote.
     */
    public function validate(User $user, Meeting $meeting): Response
    {
        $group = $meeting->group;

        if (! $group->hasMember($user)) {
            return Response::denyAsNotFound();
        }

        return $group->allowsValidation($user) ? Response::allow() : Response::denyWithStatus(403);
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
