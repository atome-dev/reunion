@props([
    'title',
    'description',
])

<div class="flex w-full flex-col gap-2 text-center">
    <flux:heading size="xl" level="1" class="text-3xl! font-extrabold! tracking-[-0.02em]">{{ $title }}</flux:heading>
    <flux:subheading>{{ $description }}</flux:subheading>
</div>
