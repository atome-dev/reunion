{{-- Light / dark / system switcher, synced with the Appearance setting through Flux. --}}
<flux:dropdown x-data align="end" {{ $attributes }}>
    <flux:button variant="subtle" square class="group" :aria-label="__('Appearance')">
        <flux:icon.sun x-show="$flux.appearance === 'light' || ($flux.appearance === 'system' && ! $flux.dark)" variant="mini" />
        <flux:icon.moon x-show="$flux.appearance === 'dark' || ($flux.appearance === 'system' && $flux.dark)" variant="mini" />
    </flux:button>

    <flux:menu>
        <flux:menu.item icon="sun" x-on:click="$flux.appearance = 'light'">{{ __('Light') }}</flux:menu.item>
        <flux:menu.item icon="moon" x-on:click="$flux.appearance = 'dark'">{{ __('Dark') }}</flux:menu.item>
        <flux:menu.item icon="computer-desktop" x-on:click="$flux.appearance = 'system'">{{ __('System') }}</flux:menu.item>
    </flux:menu>
</flux:dropdown>
