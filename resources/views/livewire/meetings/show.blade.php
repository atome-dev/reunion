@use('App\Enums\MeetingStatus')
<div class="mx-auto flex w-full max-w-5xl flex-col gap-8">
    <header class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <flux:link :href="route('groups.show', $meeting->group)" wire:navigate class="text-sm">← {{ $meeting->group->name }}</flux:link>
            <flux:heading size="xl" level="1" class="mt-2 text-3xl! font-extrabold!">{{ $meeting->title }}</flux:heading>
            <div class="mt-2 flex flex-wrap items-center gap-2">
                <flux:badge size="sm" :color="match ($meeting->status) { MeetingStatus::Confirmed => 'green', MeetingStatus::Voting => 'blue', default => 'yellow' }">{{ $meeting->status->label() }}</flux:badge>
                @if ($meeting->status === MeetingStatus::Collecting)
                    <flux:text>{{ __('From :start to :end · answer before :deadline', ['start' => $meeting->range_start->translatedFormat('j F'), 'end' => $meeting->range_end->translatedFormat('j F'), 'deadline' => $meeting->deadline->translatedFormat('j F')]) }}</flux:text>
                @endif
            </div>
            @if ($meeting->description)
                <flux:text class="mt-2 max-w-prose">{{ $meeting->description }}</flux:text>
            @endif
        </div>
        @if ($this->isOrganizer())
            <div class="flex flex-wrap items-center gap-2">
                @if ($meeting->status === MeetingStatus::Collecting)
                    <flux:button icon="pencil" :href="route('meetings.edit', $meeting)" wire:navigate>{{ __('Edit the request') }}</flux:button>
                @endif
                <flux:button icon="trash" variant="danger" wire:click="deleteMeeting" wire:confirm="{{ __('Delete this request and all its answers?') }}">{{ __('Delete the request') }}</flux:button>
            </div>
        @endif
    </header>

    @if ($meeting->status === MeetingStatus::Confirmed)
        @php($startsAt = $meeting->confirmed_starts_at->copy()->setTimezone(config('app.display_timezone')))
        @php($endsAt = $meeting->confirmed_ends_at->copy()->setTimezone(config('app.display_timezone')))
        <flux:card class="flex flex-col gap-3 border-transparent! bg-sun! text-forest">
            <flux:text class="font-semibold uppercase tracking-wide text-forest/70!">{{ __('Confirmed date') }}</flux:text>
            <flux:heading size="xl" class="text-2xl! font-extrabold! text-forest!">{{ ucfirst($startsAt->translatedFormat('l j F Y')) }} · {{ $startsAt->format('H\hi') }} – {{ $endsAt->format('H\hi') }}</flux:heading>
            @if ($meeting->location)
                <flux:text class="flex items-center gap-1 text-forest!"><flux:icon.map-pin variant="micro" /> {{ $meeting->location }}</flux:text>
            @endif
            <div><flux:button icon="calendar-days" :href="route('meetings.calendar', $meeting)">{{ __('Add to my calendar') }}</flux:button></div>
        </flux:card>
    @elseif ($meeting->status === MeetingStatus::Voting)
        <livewire:meetings.vote :meeting="$meeting" :key="'vote-'.$meeting->id" />
    @else
        {{-- The organizer is a participant too: their own grid comes first, then the summary. --}}
        <section class="flex flex-col gap-4">
            <div class="flex items-baseline justify-between gap-4">
                <flux:heading size="lg" level="2">{{ __('My availability') }}</flux:heading>
                <flux:link :href="route('availability.edit')" wire:navigate class="text-sm">{{ __('See my whole calendar') }}</flux:link>
            </div>
            <livewire:availability.grid :meeting="$meeting" :key="'grid-'.$meeting->id" />
        </section>
        @if ($this->isOrganizer())
            <section class="flex flex-col gap-4">
                <flux:heading size="lg" level="2">{{ __('Best slots') }}</flux:heading>
                <livewire:meetings.summary :meeting="$meeting" :key="'summary-'.$meeting->id" />
            </section>
        @endif
    @endif
</div>
