<div class="mx-auto flex w-full max-w-4xl flex-col gap-10">
    @if ($this->pendingMeetings->isNotEmpty())
        <section class="flex flex-col gap-4">
            <flux:heading size="lg" level="2">{{ __('Waiting for your answer') }}</flux:heading>
            @foreach ($this->pendingMeetings as $meeting)
                <flux:card wire:key="pending-{{ $meeting->id }}" class="flex flex-wrap items-center justify-between gap-4 bg-sun/40! dark:bg-sun/10!">
                    <div>
                        <flux:heading>{{ $meeting->title }}</flux:heading>
                        <flux:text>
                            {{ $meeting->group->name }} ·
                            {{ $meeting->status === \App\Enums\MeetingStatus::Voting ? __('Vote in progress') : __('Answer before :date', ['date' => $meeting->deadline->translatedFormat('j F')]) }}
                        </flux:text>
                    </div>
                    <flux:button variant="primary" :href="route('meetings.show', $meeting)" wire:navigate>
                        {{ $meeting->status === \App\Enums\MeetingStatus::Voting ? __('Vote') : __('Give my availability') }}
                    </flux:button>
                </flux:card>
            @endforeach
        </section>
    @endif

    @if ($this->confirmedMeetings->isNotEmpty())
        <section class="flex flex-col gap-4">
            <flux:heading size="lg" level="2">{{ __('Confirmed meetings') }}</flux:heading>
            @foreach ($this->confirmedMeetings as $meeting)
                <a wire:key="confirmed-{{ $meeting->id }}" href="{{ route('meetings.show', $meeting) }}" wire:navigate class="group/card block">
                    <flux:card class="flex flex-wrap items-center justify-between gap-4 transition group-hover/card:border-forest! dark:group-hover/card:border-sun!">
                        <div>
                            <flux:heading>{{ $meeting->title }}</flux:heading>
                            <flux:text>{{ $meeting->group->name }}</flux:text>
                        </div>
                        <flux:badge color="green">{{ ucfirst($meeting->confirmed_starts_at->copy()->setTimezone(config('app.display_timezone'))->translatedFormat('D j F · H\hi')) }}</flux:badge>
                    </flux:card>
                </a>
            @endforeach
        </section>
    @endif

    <section class="flex flex-col gap-4">
        <div class="flex items-center justify-between gap-4">
            <flux:heading size="lg" level="2">{{ __('My groups') }}</flux:heading>
            @if ($this->groups->isNotEmpty())
                <flux:modal.trigger name="create-group">
                    <flux:button icon="plus">{{ __('New group') }}</flux:button>
                </flux:modal.trigger>
            @endif
        </div>

        @forelse ($this->groups as $group)
            @php($nextMeeting = $group->meetings->first())
            <a wire:key="group-{{ $group->id }}" href="{{ route('groups.show', $group) }}" wire:navigate class="group/card block">
                <flux:card class="flex flex-wrap items-center justify-between gap-4 transition group-hover/card:border-forest! dark:group-hover/card:border-sun!">
                    <div>
                        <flux:heading>{{ $group->name }}</flux:heading>
                        <flux:text>{{ trans_choice(':count member|:count members', $group->members_count) }}</flux:text>
                    </div>
                    <flux:text>{{ $nextMeeting ? __('Next: :title', ['title' => $nextMeeting->title]) : __('No upcoming meeting') }}</flux:text>
                </flux:card>
            </a>
        @empty
            <flux:card class="flex flex-col items-start gap-4 bg-sun/40! dark:bg-sun/10!">
                <flux:heading size="lg">{{ __('Start by creating a group') }}</flux:heading>
                <flux:text>{{ __('A group gathers the people you meet regularly: your board, your club, your collective.') }}</flux:text>
                <flux:modal.trigger name="create-group">
                    <flux:button variant="primary" icon="plus">{{ __('Create my first group') }}</flux:button>
                </flux:modal.trigger>
            </flux:card>
        @endforelse
    </section>

    <flux:modal name="create-group" class="max-w-md">
        <form wire:submit="createGroup" class="flex flex-col gap-6">
            <flux:heading size="lg">{{ __('New group') }}</flux:heading>
            <flux:input wire:model="name" :label="__('Group name')" :placeholder="__('e.g. Board of the community garden')" required autofocus />
            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="filled">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button variant="primary" type="submit">{{ __('Create the group') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</div>
