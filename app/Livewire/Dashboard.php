<?php

namespace App\Livewire;

use App\Actions\Groups\CreateGroup;
use App\Models\Group;
use App\Models\Meeting;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Validate;
use Livewire\Component;

#[Title('Dashboard')]
class Dashboard extends Component
{
    #[Validate('required|string|max:120')]
    public string $name = '';

    public function createGroup(CreateGroup $createGroup): void
    {
        $this->validate();

        $group = $createGroup(Auth::user(), $this->name);

        $this->redirectRoute('groups.show', $group, navigate: true);
    }

    /**
     * @return Collection<int, Group>
     */
    #[Computed]
    public function groups(): Collection
    {
        return Auth::user()->groups()
            ->withCount('members')
            ->with(['meetings' => fn ($query) => $query->upcoming()->with('slots')])
            ->get();
    }

    /**
     * Upcoming meetings of my groups that I have not answered yet.
     *
     * @return Collection<int, Meeting>
     */
    #[Computed]
    public function pendingMeetings(): Collection
    {
        $userId = Auth::id();

        return Meeting::query()
            ->upcoming()
            ->whereHas('group.members', fn (Builder $members) => $members->whereKey($userId))
            ->whereDoesntHave('slots.availabilities', fn (Builder $answers) => $answers->where('user_id', $userId))
            ->with(['group', 'slots'])
            ->get();
    }
}
