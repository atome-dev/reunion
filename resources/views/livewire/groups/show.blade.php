<div class="mx-auto flex w-full max-w-4xl flex-col gap-10">
    <header class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1" class="text-3xl! font-extrabold!">{{ $group->name }}</flux:heading>
            <flux:text>{{ trans_choice(':count member|:count members', $this->members->count()) }}</flux:text>
        </div>

        <div class="flex flex-wrap gap-2">
            @if ($this->canRequestMeeting)
                <flux:button variant="primary" icon="plus" :href="route('meetings.create', $group)" wire:navigate>{{ __('New request') }}</flux:button>
            @endif
            @if ($this->canUpdate)
                <flux:dropdown align="end">
                    <flux:button icon="ellipsis-horizontal" :aria-label="__('Group actions')" />
                    <flux:menu>
                        <flux:modal.trigger name="rename-group">
                            <flux:menu.item icon="pencil">{{ __('Rename') }}</flux:menu.item>
                        </flux:modal.trigger>
                        <flux:menu.item icon="trash" variant="danger" wire:click="deleteGroup" wire:confirm="{{ __('Delete this group, its meetings and all answers?') }}">{{ __('Delete the group') }}</flux:menu.item>
                    </flux:menu>
                </flux:dropdown>
            @endif
            @unless ($group->isCreator(auth()->user()))
                <flux:button icon="arrow-right-start-on-rectangle" wire:click="leave" wire:confirm="{{ __('Leave this group?') }}">{{ __('Leave the group') }}</flux:button>
            @endunless
        </div>
    </header>

    <section class="flex flex-col gap-4">
        <flux:heading size="lg" level="2">{{ __('Meetings') }}</flux:heading>
        @forelse ($this->upcomingMeetings as $meeting)
                        <a wire:key="meeting-{{ $meeting->id }}" href="{{ route('meetings.show', $meeting) }}" wire:navigate class="group/card block">
                <flux:card class="flex flex-wrap items-center justify-between gap-4 transition group-hover/card:border-forest! dark:group-hover/card:border-sun!">
                    <div>
                        <flux:heading>{{ $meeting->title }}</flux:heading>
                        <flux:text>
                            @if ($meeting->status === \App\Enums\MeetingStatus::Confirmed)
                                {{ ucfirst($meeting->confirmed_starts_at->copy()->setTimezone(config('app.display_timezone'))->translatedFormat('l j F · H\hi')) }}
                            @elseif ($meeting->status === \App\Enums\MeetingStatus::Voting)
                                {{ __('Vote in progress') }}
                            @else
                                {{ __('Answer before :date', ['date' => $meeting->deadline->translatedFormat('j F')]) }} · {{ trans_choice(':count answer|:count answers', $meeting->respondentIds()->count()) }}
                            @endif
                        </flux:text>
                    </div>
                    <flux:badge size="sm" :color="match ($meeting->status) { \App\Enums\MeetingStatus::Confirmed => 'green', \App\Enums\MeetingStatus::Voting => 'blue', default => 'yellow' }">{{ $meeting->status->label() }}</flux:badge>
                </flux:card>
            </a>
        @empty
            <flux:text>{{ __('No upcoming meeting') }}</flux:text>
        @endforelse

        @if ($this->pastMeetings->isNotEmpty())
            <flux:accordion>
                <flux:accordion.item :heading="__('Past meetings')">
                    <ul class="flex flex-col gap-2">
                        @foreach ($this->pastMeetings as $meeting)
                            <li wire:key="past-{{ $meeting->id }}"><flux:link :href="route('meetings.show', $meeting)" wire:navigate>{{ $meeting->title }}</flux:link></li>
                        @endforeach
                    </ul>
                </flux:accordion.item>
            </flux:accordion>
        @endif
    </section>

    <section class="flex flex-col gap-4">
        <flux:heading size="lg" level="2">{{ __('Members') }}</flux:heading>
        <flux:card class="p-0!">
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>{{ __('Name') }}</flux:table.column>
                    <flux:table.column>{{ __('Role') }}</flux:table.column>
                    @if ($this->canUpdate)
                        <flux:table.column><span class="sr-only">{{ __('Actions') }}</span></flux:table.column>
                    @endif
                </flux:table.columns>
                <flux:table.rows>
                    @foreach ($this->members as $member)
                        <flux:table.row :key="'member-'.$member->id">
                            <flux:table.cell class="flex items-center gap-3">
                                <flux:avatar :name="$member->name" :initials="$member->initials()" size="xs" />
                                {{ $member->name }}
                            </flux:table.cell>
                            <flux:table.cell>
                                @if ($group->isCreator($member))
                                    <flux:badge size="sm" color="yellow">{{ __('Group creator') }}</flux:badge>
                                @else
                                    <flux:badge size="sm">{{ __('Member') }}</flux:badge>
                                @endif
                            </flux:table.cell>
                            @if ($this->canUpdate)
                                <flux:table.cell align="end">
                                    @unless ($group->isCreator($member))
                                        <flux:button size="sm" variant="ghost" icon="x-mark" wire:click="removeMember({{ $member->id }})" wire:confirm="{{ __('Remove :name from the group?', ['name' => $member->name]) }}" :aria-label="__('Remove :name from the group?', ['name' => $member->name])" />
                                    @endunless
                                </flux:table.cell>
                            @endif
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        </flux:card>

        @if ($this->canInvite)
            @if ($this->pendingInvitations->isNotEmpty())
                <flux:card class="p-0!">
                    <flux:table>
                        <flux:table.columns>
                            <flux:table.column>{{ __('Email address') }}</flux:table.column>
                            <flux:table.column>{{ __('Status') }}</flux:table.column>
                            <flux:table.column><span class="sr-only">{{ __('Actions') }}</span></flux:table.column>
                        </flux:table.columns>
                        <flux:table.rows>
                            @foreach ($this->pendingInvitations as $invitation)
                                <flux:table.row :key="'invitation-'.$invitation->id">
                                    <flux:table.cell>{{ $invitation->email }}</flux:table.cell>
                                    <flux:table.cell>
                                        <flux:badge size="sm" :color="$invitation->isUsable() ? 'sky' : 'zinc'">{{ $invitation->isUsable() ? __('Invitation sent') : __('Invitation expired') }}</flux:badge>
                                    </flux:table.cell>
                                    <flux:table.cell align="end">
                                        <flux:button size="xs" variant="ghost" wire:click="resendInvitation({{ $invitation->id }})">{{ __('Resend') }}</flux:button>
                                        <flux:button size="xs" variant="ghost" wire:click="cancelInvitation({{ $invitation->id }})">{{ __('Cancel') }}</flux:button>
                                    </flux:table.cell>
                                </flux:table.row>
                            @endforeach
                        </flux:table.rows>
                    </flux:table>
                </flux:card>
            @endif

            <form wire:submit="invite" class="flex flex-col gap-3">
                <flux:textarea wire:model="invitationEmails" :label="__('Invite people')" :description="__('One or more email addresses, separated by commas or new lines.')" rows="3" placeholder="amina@example.com, bastien@example.com" />
                <div><flux:button type="submit" variant="primary" icon="paper-airplane">{{ __('Send the invitations') }}</flux:button></div>
            </form>
        @endif
    </section>

    @if ($this->canUpdate)
        <section class="flex flex-col gap-4">
            <flux:heading size="lg" level="2">{{ __('Settings') }}</flux:heading>
            <flux:card class="flex flex-col gap-6">
                @foreach ([
                    'requestMeetingsAudience' => [__('Request a meeting'), __('Who can start a new meeting request in this group.')],
                    'inviteAudience' => [__('Add or invite members'), __('Who can invite people and manage pending invitations.')],
                    'validateAudience' => [__('Validate a date'), __('Who can see the best slots, confirm a date or put slots to a vote.')],
                ] as $property => [$label, $help])
                    <flux:radio.group wire:key="setting-{{ $property }}" wire:model.live="{{ $property }}" :label="$label" :description="$help" variant="segmented">
                        <flux:radio value="creator" :label="__('Group creator')" />
                        <flux:radio value="members" :label="__('All members')" />
                    </flux:radio.group>
                @endforeach
            </flux:card>
        </section>

        <flux:modal name="rename-group" class="max-w-md">
            <form wire:submit="rename" class="flex flex-col gap-6">
                <flux:heading size="lg">{{ __('Rename the group') }}</flux:heading>
                <flux:input wire:model="name" :label="__('Group name')" required />
                <div class="flex justify-end gap-2">
                    <flux:modal.close><flux:button variant="filled">{{ __('Cancel') }}</flux:button></flux:modal.close>
                    <flux:button variant="primary" type="submit">{{ __('Save') }}</flux:button>
                </div>
            </form>
        </flux:modal>
    @endif
</div>
