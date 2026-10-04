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
                    @php
                        $days = ['lun.', 'mar.', 'mer.', 'jeu.', 'ven.'];
                        $grid = ['pppp00', 'dddddd', 'ppdddd', '00pppp', 'pppppp'];
                    @endphp
                    <div class="max-w-lg -rotate-1 rounded-2xl bg-white p-5 text-forest shadow-[0_24px_50px_-24px_rgba(18,61,47,0.5)] dark:bg-night dark:text-milk dark:ring-1 dark:ring-white/10">
                        <p class="sr-only">Exemple fictif : une grille de disponibilités où chacun colorie ses créneaux, sur place ou à distance.</p>
                        <div aria-hidden="true">
                            <h2 class="mb-3 text-lg font-bold">Mes disponibilités</h2>
                            <div class="grid grid-cols-[2.5rem_repeat(5,minmax(0,1fr))] gap-1.5 text-center text-xs font-semibold">
                                <span></span>
                                @foreach ($days as $day)
                                    <span>{{ $day }}</span>
                                @endforeach
                                @foreach (range(0, 5) as $cell)
                                    <span class="self-center text-right opacity-70">{{ 18 + intdiv($cell, 2) }}h{{ $cell % 2 ? '30' : '' }}</span>
                                    @foreach ($grid as $cells)
                                        @php($state = $cells[$cell])
                                        <span @class(['h-6 rounded-md', 'bg-forest/10 dark:bg-white/10' => $state === '0', 'bg-onsite' => $state === 'p', 'bg-remote' => $state === 'd'])></span>
                                    @endforeach
                                @endforeach
                            </div>
                            <p class="mt-3 flex flex-wrap items-center gap-x-4 gap-y-1 text-xs font-semibold">
                                <span class="inline-flex items-center gap-1.5"><span class="size-3 rounded-sm bg-onsite"></span>Sur place</span>
                                <span class="inline-flex items-center gap-1.5"><span class="size-3 rounded-sm bg-remote"></span>À distance</span>
                                <span class="ms-auto font-normal opacity-75">Exemple fictif.</span>
                            </p>
                        </div>
                    </div>
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
