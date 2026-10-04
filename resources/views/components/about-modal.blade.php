{{-- "About" dialog, opened by any <flux:modal.trigger name="about">. --}}
<flux:modal name="about" class="w-full max-w-md">
    <div class="flex flex-col gap-6">
        <div class="flex items-center gap-3">
            <x-app-logo-icon class="h-6 w-auto" />
            <div>
                <flux:heading size="lg">{{ config('app.name') }}</flux:heading>
                <flux:text>{{ __('Bring everyone together, without the headache.') }}</flux:text>
            </div>
        </div>

        <dl class="grid grid-cols-[auto_1fr] gap-x-4 gap-y-3 text-sm">
            <dt class="text-zinc-500 dark:text-zinc-400">{{ __('Author') }}</dt>
            <dd>Nicolas Chauvet, Atome Dev</dd>

            <dt class="text-zinc-500 dark:text-zinc-400">{{ __('License') }}</dt>
            <dd>
                {{ __('Open source software under the') }}
                <flux:link href="https://github.com/atome-dev/reunion/blob/main/LICENSE" target="_blank" rel="noopener">{{ __('MIT license') }}</flux:link>
            </dd>

            <dt class="text-zinc-500 dark:text-zinc-400">{{ __('Source code') }}</dt>
            <dd><flux:link href="https://github.com/atome-dev/reunion" target="_blank" rel="noopener">github.com/atome-dev/reunion</flux:link></dd>
        </dl>

        <div class="flex justify-end">
            <flux:modal.close>
                <flux:button>{{ __('Close') }}</flux:button>
            </flux:modal.close>
        </div>
    </div>
</flux:modal>
