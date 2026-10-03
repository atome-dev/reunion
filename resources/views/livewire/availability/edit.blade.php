<div class="space-y-6">
    <div>
        <flux:heading size="xl" level="1">{{ __('My availability') }}</flux:heading>
        <flux:text class="mt-2 max-w-2xl">{{ __('Your availability is used by every request of your groups. The organizer of a request sees, in its summary, who is available during its period.') }}</flux:text>
    </div>

    <livewire:availability.grid />
</div>
