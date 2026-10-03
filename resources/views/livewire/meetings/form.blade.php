<div class="mx-auto flex w-full max-w-2xl flex-col gap-8">
    <div>
        <flux:link :href="route('groups.show', $group)" wire:navigate class="text-sm">← {{ $group->name }}</flux:link>
        <flux:heading size="xl" level="1" class="mt-2 text-3xl! font-extrabold!">{{ $meeting ? __('Edit the request') : __('New availability request') }}</flux:heading>
        <flux:text class="mt-1">{{ __('Members will color their availability over this range, on site or remotely.') }}</flux:text>
    </div>

    <form wire:submit="save" class="flex flex-col gap-6">
        <flux:input wire:model="title" :label="__('Title')" :placeholder="__('e.g. Back-to-school general meeting')" required />
        <flux:input wire:model="location" :label="__('Location')" :description="__('Optional')" />
        <flux:textarea wire:model="description" :label="__('Description')" :description="__('Optional')" rows="3" />

        <flux:card class="flex flex-col gap-4">
            <flux:heading>{{ __('Dates') }}</flux:heading>
            <div class="grid gap-4 sm:grid-cols-2">
                <flux:date-picker wire:model="rangeStart" :label="__('From')" locale="fr" :min="today(config('app.display_timezone'))->toDateString()" :max="\App\Models\AvailabilityDay::lastEditableDay()->toDateString()" with-today />
                <flux:date-picker wire:model="rangeEnd" :label="__('To')" locale="fr" :min="today(config('app.display_timezone'))->toDateString()" :max="\App\Models\AvailabilityDay::lastEditableDay()->toDateString()" />
            </div>
            <flux:date-picker wire:model="deadline" :label="__('Answer before')" :description="__('Indicative: members can still change their availability until you confirm a date.')" locale="fr" :min="today(config('app.display_timezone'))->toDateString()" />
            <flux:text size="sm">{{ __('At most :days days. Times are in Paris time.', ['days' => \App\Livewire\Meetings\Form::MaxRangeDays]) }}</flux:text>
        </flux:card>

        <div class="flex flex-wrap justify-between gap-3">
            @if ($meeting)
                <flux:button variant="danger" wire:click="deleteMeeting" wire:confirm="{{ __('Delete this request and all its answers?') }}">{{ __('Delete the request') }}</flux:button>
            @endif
            <flux:button variant="primary" type="submit" class="ms-auto">{{ $meeting ? __('Save') : __('Send the request') }}</flux:button>
        </div>
    </form>
</div>
