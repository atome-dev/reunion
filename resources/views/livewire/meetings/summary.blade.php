@php($membersById = $this->members->keyBy('id'))
<div class="flex flex-col gap-6">
    <flux:card class="flex flex-col gap-4">
        <div class="flex flex-wrap items-end gap-4">
            <flux:select wire:model.live="duration" :label="__('Duration')" class="w-36">
                @foreach (range(30, 480, 30) as $minutes)
                    <flux:select.option :value="$minutes">{{ sprintf('%dh%02d', intdiv($minutes, 60), $minutes % 60) }}</flux:select.option>
                @endforeach
            </flux:select>
            <flux:input type="number" min="1" wire:model.live.debounce.400ms="minParticipants" :label="__('At least … attendees')" class="w-40" />
            <flux:input type="number" min="0" wire:model.live.debounce.400ms="minOnSite" :label="__('Of which on site')" class="w-40" />
        </div>
        <flux:text>
            {{ trans_choice(':count answer|:count answers', $this->members->count() - $this->nonRespondents->count()) }} {{ __('out of :total', ['total' => $this->members->count()]) }}
            @if ($this->nonRespondents->isNotEmpty())
                · {{ __('Not answered yet') }} : {{ $this->nonRespondents->pluck('name')->join(', ') }}
            @endif
        </flux:text>
    </flux:card>

    <flux:error name="starts" />
    <flux:error name="selected" />

    @forelse ($this->windows as $window)
        <flux:card wire:key="window-{{ $window['key'] }}" class="flex flex-col gap-3">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="flex items-start gap-3">
                    <flux:checkbox wire:model="selected" :value="$window['key']" :aria-label="__('Add to the vote')" />
                    <div>
                        <flux:heading>{{ ucfirst(\Carbon\CarbonImmutable::parse($window['day'])->translatedFormat('l j F')) }}</flux:heading>
                        <flux:text>
                            @if ($window['firstStart'] === $window['lastStart'])
                                {{ \App\Models\AvailabilityDay::cellTime($window['firstStart']) }} – {{ \App\Models\AvailabilityDay::cellTime($window['firstStart'] + $window['length']) }}
                            @else
                                {{ __('Start between :from and :to, ends by :end', ['from' => \App\Models\AvailabilityDay::cellTime($window['firstStart']), 'to' => \App\Models\AvailabilityDay::cellTime($window['lastStart']), 'end' => \App\Models\AvailabilityDay::cellTime($window['lastStart'] + $window['length'])]) }}
                            @endif
                        </flux:text>
                    </div>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <flux:badge color="yellow">{{ trans_choice(':count present|:count present', count($window['onSite']) + count($window['remote'])) }}</flux:badge>
                    <flux:badge>{{ __(':count on site', ['count' => count($window['onSite'])]) }}</flux:badge>
                    <flux:badge>{{ __(':count remotely', ['count' => count($window['remote'])]) }}</flux:badge>
                </div>
            </div>

            <flux:text size="sm">
                @if ($window['onSite']) <span class="font-semibold">{{ __('On site') }} :</span> {{ collect($window['onSite'])->map(fn ($id) => $membersById[$id]->name)->join(', ') }}. @endif
                @if ($window['remote']) <span class="font-semibold">{{ __('Remote') }} :</span> {{ collect($window['remote'])->map(fn ($id) => $membersById[$id]->name)->join(', ') }}. @endif
                @php($absentNames = $this->members->reject(fn ($member) => in_array($member->id, [...$window['onSite'], ...$window['remote']], true))->pluck('name'))
                @if ($absentNames->isNotEmpty()) <span class="font-semibold">{{ __('Absent') }} :</span> {{ $absentNames->join(', ') }}. @endif
            </flux:text>

            <div class="flex flex-wrap items-end gap-3">
                @if ($window['firstStart'] !== $window['lastStart'])
                    <flux:select wire:model="starts.{{ $window['key'] }}" :label="__('Start at')" class="w-32">
                        @foreach (range($window['firstStart'], $window['lastStart']) as $start)
                            <flux:select.option :value="$start">{{ \App\Models\AvailabilityDay::cellTime($start) }}</flux:select.option>
                        @endforeach
                    </flux:select>
                @endif
                <flux:button variant="primary" size="sm" wire:click="confirmWindow('{{ $window['key'] }}')" wire:confirm="{{ __('Confirm this date and notify every member?') }}">{{ __('Confirm this slot') }}</flux:button>
            </div>
        </flux:card>
    @empty
        <flux:callout icon="magnifying-glass" :heading="__('No slot matches these criteria')" :text="__('Try a shorter duration or lower minimums.')" />
    @endforelse

    @if (count($this->windows) > 1)
        <div class="flex justify-end">
            <flux:button icon="hand-raised" wire:click="openVote" wire:confirm="{{ __('Put the selected slots to a vote? The grid will close.') }}">{{ __('Put the selected slots to a vote') }}</flux:button>
        </div>
    @endif
</div>
