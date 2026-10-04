<x-layouts::auth :title="__('This invitation is no longer valid')">
    <div class="flex flex-col gap-6">
        <x-auth-header
            :title="__('This invitation is no longer valid')"
            :description="__('The link has expired, was already used or was cancelled. Ask a member of the group to send you a new invitation.')"
        />
        <flux:button :href="route('home')" class="w-full">{{ __('Back to home') }}</flux:button>
    </div>
</x-layouts::auth>
