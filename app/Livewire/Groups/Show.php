<?php

namespace App\Livewire\Groups;

use App\Models\Group;
use App\Models\Meeting;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Component;

class Show extends Component
{
    public Group $group;

    public string $name = '';

    public string $invitationEmails = '';

    public function mount(Group $group): void
    {
        $this->authorize('view', $group);

        $this->group = $group;
        $this->name = $group->name;
    }

    /**
     * Re-check access on every subsequent request: membership can change while the page is open.
     */
    public function hydrate(): void
    {
        $this->authorize('view', $this->group);
    }

    public function rename(): void
    {
        $this->authorize('manage', $this->group);

        $this->validate(['name' => ['required', 'string', 'max:120']]);

        $this->group->update(['name' => $this->name]);

        $this->modal('rename-group')->close();
    }

    public function deleteGroup(): void
    {
        $this->authorize('manage', $this->group);

        $this->group->delete();

        $this->redirectRoute('dashboard', navigate: true);
    }

    public function removeMember(int $userId): void
    {
        $this->authorize('manage', $this->group);

        $member = $this->group->members()->findOrFail($userId);

        abort_if($this->group->isOrganizer($member), 403);

        $this->group->removeMember($member);
    }

    public function leave(): void
    {
        $this->authorize('leave', $this->group);

        $this->group->removeMember(Auth::user());

        $this->redirectRoute('dashboard', navigate: true);
    }

    public function isOrganizer(): bool
    {
        return $this->group->isOrganizer(Auth::user());
    }

    /**
     * @return Collection<int, User>
     */
    #[Computed]
    public function members(): Collection
    {
        return $this->group->members()->orderBy('name')->get();
    }

    /**
     * @return Collection<int, Meeting>
     */
    #[Computed]
    public function upcomingMeetings(): Collection
    {
        return $this->group->meetings()->upcoming()->with('slots.availabilities')->get();
    }

    /**
     * @return Collection<int, Meeting>
     */
    #[Computed]
    public function pastMeetings(): Collection
    {
        return $this->group->meetings()->past()->with('slots')->latest()->get();
    }
}
