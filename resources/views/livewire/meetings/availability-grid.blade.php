<div
    x-data="availabilityGrid({ weeks: @js($this->weeks), cells: @js($this->cells), canEdit: @js($this->canEdit) })"
    x-on:pointerup.window="end()"
    x-on:pointercancel.window="end()"
    class="flex flex-col gap-4"
>
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="flex flex-wrap items-center gap-3 text-sm">
            <span class="inline-flex items-center gap-1.5"><span class="size-4 rounded bg-forest dark:bg-sun"></span>{{ __('On site') }}</span>
            <span class="inline-flex items-center gap-1.5"><span class="size-4 rounded bg-blush"></span>{{ __('Remote') }}</span>
            <span class="inline-flex items-center gap-1.5"><span class="size-4 rounded border border-zinc-300 dark:border-white/20"></span>{{ __('Unavailable') }}</span>
        </div>
        <flux:text size="sm">
            {{ trans_choice(':count person has answered|:count people have answered', $this->respondentCount) }} {{ __('out of :total', ['total' => $this->memberCount]) }}
            · <span x-show="status === 'saving'">{{ __('Saving…') }}</span><span x-show="status === 'saved'" x-cloak>{{ __('Saved') }}</span><span x-show="status === 'error'" x-cloak class="text-red-600">{{ __('Not saved, try again') }}</span>
        </flux:text>
    </div>

    @if ($this->canEdit)
        <flux:callout icon="cursor-arrow-rays" :heading="__('Tap or drag over the grid')" :text="__('Once for on site, twice for remote, three times to clear. Times are in Paris time.')" />
    @else
        <flux:callout icon="lock-closed" :heading="__('The grid is closed')" :text="__('The organizer is choosing the date from everyone\'s availability.')" />
    @endif

    <div class="flex items-center justify-between gap-2">
        <flux:button size="sm" icon="chevron-left" x-on:click="goToWeek(week - 1)" x-bind:disabled="week === 0" :aria-label="__('Previous week')" />
        <flux:heading x-text="currentWeek[0].label + ' – ' + currentWeek[6].label"></flux:heading>
        <flux:button size="sm" icon="chevron-right" x-on:click="goToWeek(week + 1)" x-bind:disabled="week === weeks.length - 1" :aria-label="__('Next week')" />
    </div>

    {{-- Mobile: one day at a time --}}
    <div class="flex gap-1 overflow-x-auto sm:hidden">
        <template x-for="(day, index) in currentWeek" :key="day.date">
            <button type="button" x-on:click="mobileDay = index" x-text="day.label" x-bind:disabled="!day.inRange"
                class="shrink-0 rounded-full px-3 py-1 text-sm disabled:opacity-40"
                x-bind:class="mobileDay === index ? 'bg-forest text-sun dark:bg-sun dark:text-forest' : 'bg-zinc-100 dark:bg-white/10'"></button>
        </template>
    </div>

    <flux:card class="overflow-x-auto p-2!">
        <div class="grid select-none grid-cols-[3.5rem_1fr] gap-px sm:grid-cols-[3.5rem_repeat(7,minmax(2.75rem,1fr))]" x-on:pointerdown="start($event)" x-on:pointermove="move($event)">
            <div></div>
            <template x-for="(day, index) in currentWeek" :key="'head-' + day.date">
                <div class="px-1 pb-2 text-center text-xs font-semibold" x-bind:class="{ 'max-sm:hidden': index !== mobileDay, 'opacity-40': !day.inRange }">
                    <span x-text="day.label"></span>
                    <button type="button" x-show="canEdit && day.inRange" x-on:click="copyToWeek(day.date)" class="mt-1 block w-full text-[11px] font-normal underline opacity-70 hover:opacity-100">{{ __('Copy to week') }}</button>
                </div>
            </template>

            @foreach (range(0, \App\Models\AvailabilityDay::CellCount - 1) as $cell)
                <div class="pe-2 text-end text-xs tabular-nums text-zinc-500 dark:text-zinc-400" style="touch-action: pan-y;">
                    @if ($cell % 2 === 0) {{ \App\Models\AvailabilityDay::cellTime($cell) }} @endif
                </div>
                <template x-for="(day, index) in currentWeek" :key="day.date + '-{{ $cell }}'">
                    <button type="button" data-cell x-bind:data-date="day.date" data-index="{{ $cell }}"
                        x-on:keydown.space.prevent="cycle(day.date, {{ $cell }})"
                        x-on:keydown.enter.prevent="cycle(day.date, {{ $cell }})"
                        x-bind:disabled="!canEdit || !day.inRange"
                        x-bind:aria-label="day.label + ' {{ \App\Models\AvailabilityDay::cellTime($cell) }}'"
                        x-bind:aria-pressed="value(day.date, {{ $cell }}) !== '0'"
                        class="h-5 rounded-sm border border-zinc-200 transition-colors disabled:cursor-not-allowed dark:border-white/10 @if ($cell % 2 === 1) mb-0.5 @endif"
                        style="touch-action: none;"
                        x-bind:class="{
                            'max-sm:hidden': index !== mobileDay,
                            'bg-zinc-100 dark:bg-white/5': !day.inRange,
                            'bg-forest dark:bg-sun': day.inRange && value(day.date, {{ $cell }}) === 'p',
                            'bg-blush': day.inRange && value(day.date, {{ $cell }}) === 'd',
                            'bg-white dark:bg-night': day.inRange && value(day.date, {{ $cell }}) === '0',
                        }"></button>
                </template>
            @endforeach
        </div>
    </flux:card>
</div>
