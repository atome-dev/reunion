<?php

namespace App\Livewire\Meetings;

use App\Models\Meeting;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
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

    public function render(): View
    {
        return view('livewire.meetings.show')->title($this->meeting->title);
    }

    public function deleteMeeting(): void
    {
        $this->authorize('update', $this->meeting);

        $this->meeting->delete();

        $this->redirectRoute('groups.show', $this->meeting->group, navigate: true);
    }

    #[Computed]
    public function canUpdate(): bool
    {
        return Gate::allows('update', $this->meeting);
    }

    #[Computed]
    public function canValidate(): bool
    {
        return Gate::allows('validate', $this->meeting);
    }
}
