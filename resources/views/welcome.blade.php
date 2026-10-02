<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head', ['title' => 'Réunir tout le monde, sans le casse-tête'])
    </head>
    <body class="min-h-screen bg-milk text-forest antialiased dark:bg-night dark:text-milk">
        {{-- Hero: drenched in sunflower (deep forest at night), the poll is the invitation --}}
        <div class="bg-sun dark:bg-forest">
            <header class="mx-auto flex max-w-6xl items-center justify-between gap-4 px-5 py-6 sm:px-8">
                <a href="{{ route('home') }}" class="flex items-center gap-2 text-xl font-extrabold tracking-tight">
                    <x-app-logo-icon class="h-5 [&_.stroke-milk]:stroke-sun dark:[&_.stroke-milk]:stroke-forest" />
                    {{ config('app.name') }}
                </a>

                <nav class="flex items-center gap-1 font-semibold sm:gap-2">
                    <x-appearance-toggle />
                    @auth
                        <a href="{{ route('dashboard') }}" class="rounded-full px-4 py-2 transition hover:bg-forest/10 dark:hover:bg-white/10">{{ __('Dashboard') }}</a>
                    @else
                        <a href="{{ route('login') }}" class="rounded-full px-4 py-2 whitespace-nowrap transition hover:bg-forest/10 dark:hover:bg-white/10">{{ __('Log in') }}</a>
                        @if (Route::has('register'))
                            <a href="{{ route('register') }}" class="hidden rounded-full bg-forest px-5 py-2 text-sun transition hover:-translate-y-0.5 sm:inline-block dark:bg-sun dark:text-forest">C'est parti</a>
                        @endif
                    @endauth
                </nav>
            </header>

            <section class="mx-auto grid max-w-6xl gap-12 px-5 pt-10 pb-20 sm:px-8 lg:grid-cols-[1fr_1.15fr] lg:items-center lg:pt-16 lg:pb-28">
                <div class="flex flex-col gap-7">
                    <h1 class="text-5xl leading-[0.98] font-extrabold tracking-[-0.03em] text-balance sm:text-6xl lg:text-[4.75rem]">
                        Réunir tout le monde, sans le <span class="dark:text-sun">casse-tête.</span>
                    </h1>
                    <p class="max-w-md text-xl leading-relaxed text-forest-soft dark:text-milk/80">
                        Chacun dit quand il peut venir — en vrai ou en visio. Vous, vous voyez tout de suite la date qui arrange le plus de monde.
                    </p>
                    <div class="flex flex-wrap items-center gap-4">
                        <a href="{{ Route::has('register') ? route('register') : route('login') }}" class="group inline-flex items-center gap-2 rounded-full bg-forest px-7 py-4 text-lg font-bold text-sun shadow-[0_12px_24px_-10px_rgba(18,61,47,0.5)] transition hover:-translate-y-0.5 dark:bg-sun dark:text-forest dark:shadow-[0_12px_24px_-10px_rgba(0,0,0,0.5)]">
                            Créer mon groupe
                            <flux:icon.arrow-right variant="mini" class="transition group-hover:translate-x-1" />
                        </a>
                        <span class="text-forest-soft dark:text-milk/70">Essayez le sondage juste là →</span>
                    </div>
                </div>

                <x-demo-poll interactive class="lg:rotate-[1.2deg]" />
            </section>
        </div>

        <main>
            {{-- Three moments, three colours --}}
            <section class="mx-auto max-w-6xl px-5 py-24 sm:px-8 sm:py-32">
                <h2 class="max-w-2xl text-4xl font-extrabold tracking-[-0.025em] text-balance sm:text-5xl">Comment ça se passe&nbsp;?</h2>

                <div class="mt-14 grid gap-5 lg:grid-cols-6">
                    <article class="flex flex-col justify-between gap-10 rounded-[28px] bg-forest p-8 text-milk lg:col-span-4 lg:row-span-2 lg:p-10 dark:bg-night-raised dark:ring-1 dark:ring-white/10">
                        <flux:icon.users class="size-10 text-sun" />
                        <div class="flex flex-col gap-3">
                            <h3 class="text-3xl font-bold sm:text-4xl">Vous lancez le groupe</h3>
                            <p class="max-w-md text-lg leading-relaxed opacity-85">Ajoutez les membres de votre bureau, de votre club ou de votre collectif, proposez quelques dates, et envoyez le lien. C'est tout.</p>
                        </div>
                    </article>
                    <article class="flex flex-col gap-3 rounded-[28px] bg-blush p-8 text-forest lg:col-span-2">
                        <h3 class="text-2xl font-bold">Chacun répond en deux clics</h3>
                        <p class="leading-relaxed">En vrai, en visio, ou pas dispo. Pas besoin d'être à l'aise avec l'informatique.</p>
                    </article>
                    <article class="flex flex-col gap-3 rounded-[28px] bg-lagoon p-8 text-forest lg:col-span-2">
                        <h3 class="text-2xl font-bold">La bonne date saute aux yeux</h3>
                        <p class="leading-relaxed">Vous voyez qui vient, et comment. Il ne reste qu'à réserver la salle — et la visio.</p>
                    </article>
                </div>
            </section>

            {{-- Who it's for --}}
            <section class="mx-auto max-w-6xl px-5 pb-24 sm:px-8 sm:pb-32">
                <div class="flex flex-col gap-8 rounded-[28px] border-2 border-forest p-8 sm:p-12 dark:border-white/15">
                    <h2 class="max-w-2xl text-3xl font-extrabold tracking-[-0.02em] text-balance sm:text-4xl">Fait pour la vie associative.</h2>
                    <ul class="flex flex-wrap gap-3 text-lg font-semibold">
                        @foreach (['Assemblée générale', 'Conseil d\'administration', 'Réunion de bénévoles', 'Comité de quartier', 'Répétition', 'Conseil syndical', 'Bureau du club'] as $occasion)
                            <li class="rounded-full bg-sun px-5 py-2 text-forest">{{ $occasion }}</li>
                        @endforeach
                    </ul>
                </div>
            </section>

            {{-- Animated walkthrough --}}
            <section class="mx-auto max-w-6xl px-5 pb-24 sm:px-8 sm:pb-32">
                <h2 class="max-w-2xl text-4xl font-extrabold tracking-[-0.025em] text-balance sm:text-5xl">Comment ça marche, en 30 secondes</h2>
                <x-how-it-works class="mt-14" />
            </section>

            {{-- Close --}}
            <section class="bg-forest text-milk dark:bg-night-raised">
                <div class="mx-auto flex max-w-6xl flex-col items-start gap-8 px-5 py-24 sm:px-8 lg:flex-row lg:items-center lg:justify-between">
                    <h2 class="max-w-2xl text-4xl font-extrabold tracking-[-0.025em] text-balance sm:text-5xl">Votre prochaine réunion commence ici.</h2>
                    <a href="{{ Route::has('register') ? route('register') : route('login') }}" class="group inline-flex shrink-0 items-center gap-2 rounded-full bg-sun px-7 py-4 text-lg font-bold text-forest transition hover:-translate-y-0.5">
                        Créer mon groupe
                        <flux:icon.arrow-right variant="mini" class="transition group-hover:translate-x-1" />
                    </a>
                </div>
            </section>
        </main>

        <footer class="bg-forest text-milk/70 dark:bg-night-raised">
            <div class="mx-auto flex max-w-6xl flex-wrap items-center justify-between gap-4 border-t border-milk/15 px-5 py-8 text-sm sm:px-8">
                <span>© {{ date('Y') }} {{ config('app.name') }}</span>
            </div>
        </footer>

        @fluxScripts
    </body>
</html>
