<?php

namespace App\Livewire\Groups;

use App\Models\Group;
use Livewire\Component;

class Show extends Component
{
    public Group $group;

    public function mount(Group $group): void
    {
        $this->authorize('view', $group);

        $this->group = $group;
    }
}
