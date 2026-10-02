@php
    $steps = [
        ['title' => 'Créez votre groupe', 'text' => 'Bureau, club, collectif : un nom suffit.'],
        ['title' => 'Invitez les membres', 'text' => 'Un e-mail chacun, un clic pour rejoindre.'],
        ['title' => 'Proposez des dates', 'text' => 'Deux dates ou plus pour la réunion.'],
        ['title' => 'Chacun répond', 'text' => 'Sur place, à distance ou pas dispo.'],
        ['title' => 'La date se dégage', 'text' => 'La meilleure date est mise en avant.'],
    ];
    $emails = ['amina@lilas.org', 'bastien@lilas.org', 'chloe@gmail.com', 'david@lilas.org'];
    $dates = ['mar. 14 oct.', 'jeu. 16 oct.', 'mer. 22 oct.'];
    $dateShort = ['14 oct.', '16 oct.', '22 oct.'];
    // p = sur place, d = à distance, n = pas dispo
    $answers = [
        'Amina' => ['p', 'd', 'p'],
        'Bastien' => ['n', 'p', 'p'],
        'Chloé' => ['p', 'p', 'd'],
        'David' => ['d', 'n', 'p'],
    ];
    $field = 'rounded-xl bg-white px-4 py-3 text-sm font-semibold shadow-sm dark:bg-night dark:ring-1 dark:ring-white/10';
@endphp

<div {{ $attributes->class('how-it-works') }} data-how-it-works>
    <div class="grid gap-8 lg:grid-cols-[minmax(0,1fr)_minmax(0,1.2fr)] lg:items-stretch">
        <div class="flex min-w-0 flex-col gap-4">
            <ol class="flex flex-col gap-2">
                @foreach ($steps as $index => $step)
                    <li>
                        <button type="button" data-step="{{ $index }}" @if ($index === 0) aria-current="step" @endif class="hiw-step grid w-full cursor-pointer grid-cols-[2rem_1fr] items-start gap-3 rounded-2xl border-2 border-forest/15 bg-white p-3 text-left transition hover:border-forest/40 dark:border-white/15 dark:bg-night dark:hover:border-white/40">
                            <span class="hiw-n grid size-8 place-items-center rounded-full bg-forest/10 font-extrabold dark:bg-white/10">{{ $index + 1 }}</span>
                            <span>
                                <strong class="block text-lg">{{ $step['title'] }}</strong>
                                <span class="text-forest-soft dark:text-milk/70">{{ $step['text'] }}</span>
                            </span>
                        </button>
                    </li>
                @endforeach
            </ol>

            <div class="flex items-center gap-3">
                <flux:button type="button" size="sm" data-play aria-pressed="true">
                    <span data-label-pause class="inline-flex items-center gap-1.5 [&[hidden]]:hidden"><flux:icon.pause variant="micro" />Pause</span>
                    <span data-label-play hidden class="inline-flex items-center gap-1.5 [&[hidden]]:hidden"><flux:icon.play variant="micro" />Lecture</span>
                </flux:button>
                <div class="h-1 flex-1 overflow-hidden rounded-full bg-forest/15 dark:bg-white/15" aria-hidden="true">
                    <i data-bar class="block h-full w-0 bg-forest dark:bg-sun"></i>
                </div>
            </div>
        </div>

        <div class="flex min-h-80 flex-col justify-center gap-4 overflow-hidden rounded-3xl bg-sun p-5 sm:p-8 dark:bg-forest">
            <div aria-live="polite" class="grid place-items-center">
                <div class="hiw-scene w-full max-w-md" data-scene="0">
                    <div class="flex flex-col gap-3 rounded-2xl bg-white p-5 text-forest shadow-[0_24px_50px_-20px_rgba(18,61,47,0.45)] dark:bg-night dark:text-milk dark:ring-1 dark:ring-white/10">
                        <h3 class="text-lg font-bold">Nouveau groupe</h3>
                        <div class="hiw-pop rounded-xl border-2 border-forest/20 px-4 py-3 text-sm font-semibold dark:border-white/20">Bureau du jardin partagé des Lilas</div>
                        <div class="hiw-pop hiw-d2"><span class="inline-block rounded-full bg-forest px-5 py-2 text-sm font-bold text-sun dark:bg-sun dark:text-forest">Créer le groupe</span></div>
                    </div>
                </div>

                <div class="hiw-scene w-full max-w-md" data-scene="1" hidden>
                    <div class="flex flex-col gap-2 rounded-2xl bg-white p-5 text-forest shadow-[0_24px_50px_-20px_rgba(18,61,47,0.45)] dark:bg-night dark:text-milk dark:ring-1 dark:ring-white/10">
                        <h3 class="mb-1 text-lg font-bold">Invitations envoyées</h3>
                        @foreach ($emails as $index => $email)
                            <div class="hiw-pop hiw-d{{ $index }} flex items-center gap-2 rounded-xl bg-milk px-4 py-3 text-sm font-semibold dark:bg-night-raised"><flux:icon.envelope variant="micro" class="shrink-0" />{{ $email }}</div>
                        @endforeach
                    </div>
                </div>

                <div class="hiw-scene w-full max-w-md" data-scene="2" hidden>
                    <div class="flex flex-col gap-2 rounded-2xl bg-white p-5 text-forest shadow-[0_24px_50px_-20px_rgba(18,61,47,0.45)] dark:bg-night dark:text-milk dark:ring-1 dark:ring-white/10">
                        <h3 class="mb-1 text-lg font-bold">Assemblée de rentrée</h3>
                        @foreach ($dates as $index => $date)
                            <div class="hiw-pop hiw-d{{ $index }} flex items-center gap-2 rounded-xl bg-milk px-4 py-3 text-sm font-semibold dark:bg-night-raised"><flux:icon.calendar-days variant="micro" class="shrink-0" />{{ $date }} · 18h30</div>
                        @endforeach
                    </div>
                </div>

                @foreach ([3, 4] as $scene)
                    <div class="hiw-scene w-full max-w-md" data-scene="{{ $scene }}" hidden>
                        <div class="rounded-2xl bg-white p-5 text-forest shadow-[0_24px_50px_-20px_rgba(18,61,47,0.45)] dark:bg-night dark:text-milk dark:ring-1 dark:ring-white/10">
                            <h3 class="mb-3 text-lg font-bold">{{ $scene === 3 ? 'Les réponses arrivent…' : 'Meilleure date : mer. 22 oct. · 18h30' }}</h3>
                            <table class="w-full border-separate border-spacing-0 text-sm">
                                <thead>
                                    <tr>
                                        <th scope="col"><span class="sr-only">Membre</span></th>
                                        @foreach ($dateShort as $index => $label)
                                            <th scope="col" @class(['rounded-t-xl px-1 py-2 text-center font-semibold', 'hiw-glow' => $scene === 4 && $index === 2])>{{ $label }}</th>
                                        @endforeach
                                    </tr>
                                </thead>
                                <tbody>
                                    @php($row = 0)
                                    @foreach ($answers as $name => $cells)
                                        <tr>
                                            <th scope="row" class="py-1 pr-2 text-left font-semibold">{{ $name }}</th>
                                            @foreach ($cells as $index => $answer)
                                                <td @class(['px-1 py-1 text-center', 'hiw-glow' => $scene === 4 && $index === 2])>
                                                    <span @class(['mx-auto grid size-6 place-items-center rounded-full', 'hiw-pop hiw-d'.min(5, $row + $index) => $scene === 3, 'bg-forest text-white dark:bg-sun dark:text-forest' => $answer === 'p', 'bg-blush text-forest' => $answer === 'd'])>
                                                        @if ($answer === 'p')
                                                            <flux:icon.map-pin variant="micro" class="size-3.5" /><span class="sr-only">Sur place</span>
                                                        @elseif ($answer === 'd')
                                                            <flux:icon.video-camera variant="micro" class="size-3.5" /><span class="sr-only">À distance</span>
                                                        @else
                                                            <span class="block size-2 rounded-full bg-forest/15 dark:bg-white/15"></span><span class="sr-only">Pas dispo</span>
                                                        @endif
                                                    </span>
                                                </td>
                                            @endforeach
                                        </tr>
                                        @php($row++)
                                    @endforeach
                                    @if ($scene === 4)
                                        <tr>
                                            <th scope="row" class="pt-2 pr-2 text-left font-semibold opacity-70">Total</th>
                                            @foreach ([3, 3, 4] as $index => $total)
                                                <td @class(['pt-2 pb-1 text-center font-extrabold tabular-nums', 'hiw-glow rounded-b-xl' => $index === 2])>{{ $total }}</td>
                                            @endforeach
                                        </tr>
                                    @endif
                                </tbody>
                            </table>
                            @if ($scene === 4)
                                <p class="mt-3 text-sm font-semibold">4 présents · 3 sur place, 1 à distance</p>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
            <p class="text-center text-xs opacity-75">Exemple fictif.</p>
        </div>
    </div>
</div>

@once
    <style>
        .how-it-works .hiw-step[aria-current="step"] { border-color: var(--color-forest); }
        .dark .how-it-works .hiw-step[aria-current="step"] { border-color: var(--color-sun); }
        .how-it-works .hiw-step[aria-current="step"] .hiw-n { background: var(--color-sun); color: var(--color-forest); }
        .how-it-works .hiw-scene[hidden] { display: none; }
        .how-it-works .hiw-pop { animation: hiw-pop 450ms cubic-bezier(0.16, 1, 0.3, 1) both; }
        .how-it-works .hiw-d1 { animation-delay: 150ms; }
        .how-it-works .hiw-d2 { animation-delay: 300ms; }
        .how-it-works .hiw-d3 { animation-delay: 450ms; }
        .how-it-works .hiw-d4 { animation-delay: 600ms; }
        .how-it-works .hiw-d5 { animation-delay: 750ms; }
        .how-it-works .hiw-glow { animation: hiw-glow 1200ms 900ms ease both; }
        @keyframes hiw-pop { from { opacity: 0; transform: scale(0.6); } }
        @keyframes hiw-glow { to { background: color-mix(in srgb, var(--color-sun) 45%, white); } }
        .dark .how-it-works .hiw-glow { animation-name: hiw-glow-dark; }
        @keyframes hiw-glow-dark { to { background: color-mix(in srgb, var(--color-sun) 16%, var(--color-night)); } }

        @media (prefers-reduced-motion: reduce) {
            .how-it-works .hiw-pop { animation: none; }
            .how-it-works .hiw-glow { animation: none; background: color-mix(in srgb, var(--color-sun) 45%, white); }
            .dark .how-it-works .hiw-glow { background: color-mix(in srgb, var(--color-sun) 16%, var(--color-night)); }
        }
    </style>

    <script>
        const initHowItWorks = () => {
            document.querySelectorAll('[data-how-it-works]:not([data-ready])').forEach((root) => {
                root.dataset.ready = '';

                const DURATION = 3200;
                const scenes = [...root.querySelectorAll('[data-scene]')];
                const stepButtons = [...root.querySelectorAll('[data-step]')];
                const bar = root.querySelector('[data-bar]');
                const play = root.querySelector('[data-play]');
                const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
                let step = 0;
                let started = 0;
                let frame = null;
                let wantsPlay = ! reducedMotion;
                let visible = false;

                const showStep = (index) => {
                    step = (index + scenes.length) % scenes.length;
                    scenes.forEach((scene, position) => {
                        scene.hidden = position !== step;

                        if (position === step) {
                            scene.querySelectorAll('.hiw-pop, .hiw-glow').forEach((element) => {
                                element.style.animation = 'none';
                                void element.offsetWidth;
                                element.style.animation = '';
                            });
                        }
                    });
                    stepButtons.forEach((button, position) => {
                        position === step ? button.setAttribute('aria-current', 'step') : button.removeAttribute('aria-current');
                    });
                    started = performance.now();
                    bar.style.width = '0%';
                };

                const tick = (now) => {
                    const progress = Math.min(1, (now - started) / DURATION);

                    bar.style.width = `${progress * 100}%`;

                    if (progress >= 1) {
                        showStep(step + 1);
                    }

                    frame = requestAnimationFrame(tick);
                };

                const sync = () => {
                    const shouldRun = wantsPlay && visible;

                    if (shouldRun && ! frame) {
                        started = performance.now();
                        frame = requestAnimationFrame(tick);
                    } else if (! shouldRun && frame) {
                        cancelAnimationFrame(frame);
                        frame = null;
                    }
                };

                const renderPlay = () => {
                    play.setAttribute('aria-pressed', String(wantsPlay));
                    play.querySelector('[data-label-pause]').hidden = ! wantsPlay;
                    play.querySelector('[data-label-play]').hidden = wantsPlay;
                };

                stepButtons.forEach((button) => {
                    button.addEventListener('click', () => showStep(Number(button.dataset.step)));
                });

                play.addEventListener('click', () => {
                    wantsPlay = ! wantsPlay;
                    renderPlay();
                    sync();
                });

                renderPlay();
                showStep(0);

                new IntersectionObserver((entries) => {
                    visible = entries[entries.length - 1].isIntersecting;
                    sync();
                }).observe(root);
            });
        };

        document.addEventListener('DOMContentLoaded', initHowItWorks);
        document.addEventListener('livewire:navigated', initHowItWorks);
    </script>
@endonce
