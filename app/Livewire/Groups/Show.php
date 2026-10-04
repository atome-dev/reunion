<?php

namespace App\Livewire\Groups;

use App\Actions\Groups\InviteToGroup;
use App\Actions\Groups\SendGroupInvitation;
use App\Models\Group;
use App\Models\GroupInvitation;
use App\Models\Meeting;
use App\Models\User;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Component;

class Show extends Component
{
    public Group $group;

    public string $name = '';

    public string $invitationEmails = '';

    /** 'creator' or 'members': Flux segmented radios cannot bind booleans. */
    public string $requestMeetingsAudience = 'creator';

    public string $inviteAudience = 'creator';

    public string $validateAudience = 'creator';

    public function mount(Group $group): void
    {
        $this->authorize('view', $group);

        $this->group = $group;
        $this->name = $group->name;
        $this->requestMeetingsAudience = $group->members_can_request_meetings ? 'members' : 'creator';
        $this->inviteAudience = $group->members_can_invite ? 'members' : 'creator';
        $this->validateAudience = $group->members_can_validate ? 'members' : 'creator';
    }

    /**
     * Settings are saved as soon as a choice changes.
     */
    public function updated(string $property): void
    {
        $columns = [
            'requestMeetingsAudience' => 'members_can_request_meetings',
            'inviteAudience' => 'members_can_invite',
            'validateAudience' => 'members_can_validate',
        ];

        if (! isset($columns[$property])) {
            return;
        }

        $this->authorize('update', $this->group);

        $this->validateOnly($property, [$property => ['required', 'in:creator,members']]);

        $this->group->update([$columns[$property] => $this->{$property} === 'members']);

        Flux::toast(variant: 'success', text: __('Settings saved.'));
    }

    /**
     * Re-check access on every subsequent request: membership can change while the page is open.
     */
    public function hydrate(): void
    {
        $this->authorize('view', $this->group);
    }

    public function render(): View
    {
        return view('livewire.groups.show')->title($this->group->name);
    }

    public function rename(): void
    {
        $this->authorize('update', $this->group);

        $this->validate(['name' => ['required', 'string', 'max:120']]);

        $this->group->update(['name' => $this->name]);

        $this->modal('rename-group')->close();
    }

    public function deleteGroup(): void
    {
        $this->authorize('update', $this->group);

        $this->group->delete();

        $this->redirectRoute('dashboard', navigate: true);
    }

    public function removeMember(int $userId): void
    {
        $this->authorize('update', $this->group);

        $member = $this->group->members()->findOrFail($userId);

        abort_if($this->group->isCreator($member), 403);

        $this->group->removeMember($member);
    }

    public function leave(): void
    {
        $this->authorize('leave', $this->group);

        $this->group->removeMember(Auth::user());

        $this->redirectRoute('dashboard', navigate: true);
    }

    public function invite(InviteToGroup $inviteToGroup): void
    {
        $this->authorize('invite', $this->group);

        $result = $inviteToGroup($this->group, Auth::user(), $this->invitationEmails);

        $this->reset('invitationEmails');
        unset($this->pendingInvitations);

        Flux::toast(variant: 'success', text: trans_choice(':count invitation sent.|:count invitations sent.', count($result['invited'])));

        if ($result['skipped'] !== []) {
            Flux::toast(text: __('Already member or invited: :emails', ['emails' => implode(', ', $result['skipped'])]));
        }
    }

    public function resendInvitation(int $invitationId, SendGroupInvitation $sendGroupInvitation): void
    {
        $this->authorize('invite', $this->group);

        $sendGroupInvitation($this->group->invitations()->whereNull('accepted_at')->findOrFail($invitationId));

        Flux::toast(variant: 'success', text: __('Invitation sent again.'));
    }

    public function cancelInvitation(int $invitationId): void
    {
        $this->authorize('invite', $this->group);

        $this->group->invitations()->whereNull('accepted_at')->findOrFail($invitationId)->delete();

        unset($this->pendingInvitations);
    }

    /**
     * Invitations not accepted yet, expired ones included so they can be resent.
     *
     * @return Collection<int, GroupInvitation>
     */
    #[Computed]
    public function pendingInvitations(): Collection
    {
        return $this->group->invitations()->whereNull('accepted_at')->latest()->get();
    }

    #[Computed]
    public function canUpdate(): bool
    {
        return Gate::allows('update', $this->group);
    }

    #[Computed]
    public function canRequestMeeting(): bool
    {
        return Gate::allows('requestMeeting', $this->group);
    }

    #[Computed]
    public function canInvite(): bool
    {
        return Gate::allows('invite', $this->group);
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
        return $this->group->meetings()->upcoming()->latest()->get();
    }

    /**
     * @return Collection<int, Meeting>
     */
    #[Computed]
    public function pastMeetings(): Collection
    {
        return $this->group->meetings()->past()->latest('confirmed_starts_at')->get();
    }
}
