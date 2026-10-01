@props([
    'sidebar' => false,
])

@if($sidebar)
    <flux:sidebar.brand :name="config('app.name', 'Reunion')" {{ $attributes->class('[&>div:nth-child(2)]:text-lg! [&>div:nth-child(2)]:font-extrabold! [&>div:nth-child(2)]:text-forest! dark:[&>div:nth-child(2)]:text-milk!') }}>
        <x-slot name="logo" class="flex h-8 w-11 items-center justify-center">
            <x-app-logo-icon class="h-5" />
        </x-slot>
    </flux:sidebar.brand>
@else
    <flux:brand :name="config('app.name', 'Reunion')" {{ $attributes }}>
        <x-slot name="logo" class="flex h-8 w-11 items-center justify-center">
            <x-app-logo-icon class="h-5" />
        </x-slot>
    </flux:brand>
@endif
