<?php

namespace App\Livewire\Meetings;

use App\Models\Meeting;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class Show extends Component
{
    public Meeting $meeting;

    public function mount(Meeting $meeting): void
    {
        $this->authorize('view', $meeting);

        $this->meeting = $meeting;
    }

    public function hydrate(): void
    {
        $this->authorize('view', $this->meeting);
    }

    public function isOrganizer(): bool
    {
        return $this->meeting->group->isOrganizer(Auth::user());
    }
}
