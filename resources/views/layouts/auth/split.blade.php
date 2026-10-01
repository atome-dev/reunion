<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-milk text-forest antialiased dark:bg-night dark:text-milk">
        <div class="grid min-h-dvh grid-rows-[auto_1fr] lg:grid-cols-[minmax(0,1fr)_minmax(0,1.1fr)] lg:grid-rows-none">
            {{-- Brand panel: sunflower by day, deep forest at night --}}
            <aside class="flex flex-col gap-6 bg-sun px-5 py-5 sm:px-8 lg:gap-10 lg:px-12 lg:py-10 dark:bg-forest">
                <div class="flex items-center justify-between gap-4">
                    <a href="{{ route('home') }}" class="flex items-center gap-2 text-xl font-extrabold tracking-tight">
                        <x-app-logo-icon class="h-5 [&_.stroke-milk]:stroke-sun dark:[&_.stroke-milk]:stroke-forest" />
                        {{ config('app.name') }}
                    </a>
                    <x-appearance-toggle class="lg:hidden" />
                </div>

                <div class="hidden flex-1 flex-col justify-center gap-10 lg:flex">
                    <p class="max-w-md text-5xl leading-[1] font-extrabold tracking-[-0.03em] text-balance">
                        Réunir tout le monde, sans le <span class="dark:text-sun">casse-tête.</span>
                    </p>
                    <x-demo-poll compact class="max-w-lg -rotate-1 shadow-[0_24px_50px_-24px_rgba(18,61,47,0.5)]" />
                </div>
            </aside>

            <main class="flex flex-col px-5 py-8 sm:px-8 lg:px-12 lg:py-10">
                <div class="hidden justify-end lg:flex">
                    <x-appearance-toggle />
                </div>
                <div class="flex flex-1 items-center justify-center">
                    <div class="flex w-full max-w-sm flex-col gap-6">
                        {{ $slot }}
                    </div>
                </div>
            </main>
        </div>

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @fluxScripts
    </body>
</html>
