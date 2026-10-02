<?php

namespace App\Livewire\Meetings;

use App\Models\Group;
use Livewire\Component;

class Form extends Component
{
    public ?Group $group = null;

    public function mount(?Group $group = null): void
    {
        $this->authorize('manage', $group);

        $this->group = $group;
    }
}
