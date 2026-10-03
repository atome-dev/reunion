<div class="flex flex-col gap-8">
    <flux:error name="meeting" />

    @can('vote', $meeting)
        <section class="flex flex-col gap-3">
            <flux:heading size="lg" level="2">{{ __('Your vote') }}</flux:heading>
            <flux:text>{{ __('We prefilled your answers from your grid. Check them and save.') }}</flux:text>
            <form wire:submit="save" class="flex flex-col gap-3">
                @foreach ($this->proposedSlots as $index => $slot)
                    <flux:card wire:key="vote-{{ $slot->id }}" class="flex flex-col gap-2 p-4! sm:flex-row sm:items-center sm:justify-between">
                        <flux:heading>{{ ucfirst($slot->startsAtLocal()->translatedFormat('l j F · H\hi')) }} – {{ $slot->endsAtLocal()->format('H\hi') }}</flux:heading>
                        <flux:radio.group wire:model="responses.{{ $slot->id }}" variant="segmented" size="sm" :aria-label="__('Slot :number', ['number' => $index + 1])">
                            @foreach (\App\Enums\AvailabilityStatus::cases() as $status)
                                <flux:radio :value="$status->value" :label="$status->label()" />
                            @endforeach
                        </flux:radio.group>
                        <flux:error name="responses.{{ $slot->id }}" />
                    </flux:card>
                @endforeach
                <div><flux:button variant="primary" type="submit">{{ __('Save my vote') }}</flux:button></div>
            </form>
        </section>
    @endcan

    @if ($this->isOrganizer && $meeting->status === \App\Enums\MeetingStatus::Voting)
        <section class="flex flex-col gap-3">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <flux:heading size="lg" level="2">{{ __('Vote results') }}</flux:heading>
                <flux:button size="sm" variant="ghost" icon="arrow-uturn-left" wire:click="cancelVote" wire:confirm="{{ __('Cancel the vote? Votes will be deleted and the grid reopened.') }}">{{ __('Cancel the vote') }}</flux:button>
            </div>
            <flux:card class="p-0!">
                <flux:table>
                    <flux:table.columns>
                        <flux:table.column class="ps-4!">{{ __('Slot') }}</flux:table.column>
                        <flux:table.column align="center">{{ __('On site') }}</flux:table.column>
                        <flux:table.column align="center">{{ __('Remote') }}</flux:table.column>
                        <flux:table.column><span class="sr-only">{{ __('Actions') }}</span></flux:table.column>
                    </flux:table.columns>
                    <flux:table.rows>
                        @foreach ($this->proposedSlots as $slot)
                            <flux:table.row :key="'result-'.$slot->id">
                                <flux:table.cell variant="strong" class="ps-4!">
                                    {{ ucfirst($slot->startsAtLocal()->translatedFormat('l j F · H\hi')) }}
                                    @if ($slot->id === $this->bestSlotId) <flux:badge size="sm" color="yellow" class="ms-2">{{ __('Best date') }}</flux:badge> @endif
                                </flux:table.cell>
                                <flux:table.cell align="center" class="tabular-nums">{{ $this->tallies[$slot->id]['onSite'] }}</flux:table.cell>
                                <flux:table.cell align="center" class="tabular-nums">{{ $this->tallies[$slot->id]['remote'] }}</flux:table.cell>
                                <flux:table.cell align="end">
                                    <flux:button size="sm" :variant="$slot->id === $this->bestSlotId ? 'primary' : 'filled'" wire:click="confirmSlot({{ $slot->id }})" wire:confirm="{{ __('Confirm this date and notify every member?') }}">{{ __('Confirm this date') }}</flux:button>
                                </flux:table.cell>
                            </flux:table.row>
                        @endforeach
                    </flux:table.rows>
                </flux:table>
            </flux:card>
            @if ($this->nonVoters->isNotEmpty())
                <flux:callout icon="clock" :heading="__('Not voted yet')" :text="$this->nonVoters->pluck('name')->join(', ')" />
            @endif
        </section>
    @endif
</div>
