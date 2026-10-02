<?php

namespace App\Actions\Groups;

use App\Enums\GroupRole;
use App\Models\Group;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class CreateGroup
{
    public function __invoke(User $owner, string $name): Group
    {
        return DB::transaction(function () use ($owner, $name): Group {
            $group = new Group(['name' => $name]);
            $group->owner()->associate($owner);
            $group->save();

            $group->addMember($owner, GroupRole::Organizer);

            return $group;
        });
    }
}
