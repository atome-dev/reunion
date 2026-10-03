@php
    $steps = [
        ['title' => 'Lancez une demande', 'text' => 'Une période, une date butoir : les membres sont prévenus.'],
        ['title' => 'Chacun colorie ses disponibilités', 'text' => 'Sur place ou à distance, du bout du doigt.'],
        ['title' => 'Le résumé trouve les meilleurs créneaux', 'text' => 'Selon la durée et le nombre de présents.'],
        ['title' => 'Validez, ou faites voter', 'text' => 'Un créneau, ou plusieurs soumis au vote.'],
        ['title' => 'Tout le monde est prévenu', 'text' => 'Un e-mail et un rappel dans l’agenda.'],
    ];
    $days = ['lun.', 'mar.', 'mer.', 'jeu.', 'ven.'];
    // p = sur place, d = à distance, 0 = pas dispo ; 6 cases par jour (18h–21h)
    $grid = ['pppp00', 'dddddd', 'ppdddd', '00pppp', 'pppppp'];
    $slots = [
        ['Mer. 12 nov. · 18h00–20h00', '5 présents'],
        ['Ven. 14 nov. · 19h00–21h00', '4 présents'],
        ['Mar. 18 nov. · 18h30–20h30', '4 présents'],
    ];
    $card = 'rounded-2xl bg-white p-5 text-forest shadow-[0_24px_50px_-20px_rgba(18,61,47,0.45)] dark:bg-night dark:text-milk dark:ring-1 dark:ring-white/10';
    $row = 'flex items-center justify-between gap-2 rounded-xl bg-milk px-4 py-3 text-sm font-semibold dark:bg-night-raised';
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
                    <div class="flex flex-col gap-3 {{ $card }}">
                        <h3 class="text-lg font-bold">Nouvelle demande</h3>
                        <div class="hiw-pop rounded-xl border-2 border-forest/20 px-4 py-3 text-sm font-semibold dark:border-white/20">Assemblée de rentrée</div>
                        <div class="hiw-pop hiw-d1 {{ $row }}"><span class="inline-flex items-center gap-2"><flux:icon.calendar-days variant="micro" class="shrink-0" />Du 3 au 21 nov.</span></div>
                        <div class="hiw-pop hiw-d2 {{ $row }}"><span class="inline-flex items-center gap-2"><flux:icon.clock variant="micro" class="shrink-0" />Répondre avant le 31 oct.</span></div>
                    </div>
                </div>

                <div class="hiw-scene w-full max-w-md" data-scene="1" hidden>
                    <div class="{{ $card }}">
                        <h3 class="mb-3 text-lg font-bold">Mes disponibilités</h3>
                        <div class="grid grid-cols-[2.5rem_repeat(5,minmax(0,1fr))] gap-1.5 text-center text-xs font-semibold">
                            <span></span>
                            @foreach ($days as $day)
                                <span>{{ $day }}</span>
                            @endforeach
                            @foreach (range(0, 5) as $cell)
                                <span class="self-center text-right opacity-70">{{ 18 + intdiv($cell, 2) }}h{{ $cell % 2 ? '30' : '' }}</span>
                                @foreach ($grid as $column => $cells)
                                    @php($state = $cells[$cell])
                                    <span @class(['h-7 rounded-md', 'bg-forest/10 dark:bg-white/10' => $state === '0', 'hiw-pop bg-forest dark:bg-sun' => $state === 'p', 'hiw-pop bg-blush' => $state === 'd']) @if ($state !== '0') style="animation-delay: {{ ($column * 6 + $cell) * 40 }}ms" @endif></span>
                                @endforeach
                            @endforeach
                        </div>
                        <p class="mt-3 flex flex-wrap items-center gap-x-4 gap-y-1 text-xs font-semibold">
                            <span class="inline-flex items-center gap-1.5"><span class="size-3 rounded-sm bg-forest dark:bg-sun"></span>Sur place</span>
                            <span class="inline-flex items-center gap-1.5"><span class="size-3 rounded-sm bg-blush"></span>À distance</span>
                        </p>
                    </div>
                </div>

                <div class="hiw-scene w-full max-w-md" data-scene="2" hidden>
                    <div class="flex flex-col gap-2 {{ $card }}">
                        <h3 class="text-lg font-bold">Meilleurs créneaux</h3>
                        <p class="mb-1 text-sm font-semibold opacity-75">2 h · au moins 4 présents · dont 2 sur place</p>
                        @foreach ($slots as $index => [$when, $count])
                            <div @class(['hiw-pop hiw-d'.$index, $row, 'ring-2 ring-forest dark:ring-sun' => $index === 0])><span>{{ $when }}</span><span class="shrink-0 tabular-nums">{{ $count }}</span></div>
                        @endforeach
                    </div>
                </div>

                <div class="hiw-scene w-full max-w-md" data-scene="3" hidden>
                    <div class="flex flex-col gap-2 {{ $card }}">
                        <h3 class="text-lg font-bold">Meilleurs créneaux</h3>
                        @foreach ($slots as $index => [$when, $count])
                            <div @class([$row, 'hiw-glow ring-2 ring-forest dark:ring-sun' => $index === 0])><span>{{ $when }}</span><span class="shrink-0 tabular-nums">{{ $count }}</span></div>
                        @endforeach
                        <div class="mt-2 flex flex-wrap gap-2">
                            <span class="hiw-pop inline-block rounded-full bg-forest px-5 py-2 text-sm font-bold text-sun dark:bg-sun dark:text-forest">Valider ce créneau</span>
                            <span class="hiw-pop hiw-d1 inline-block rounded-full border-2 border-forest/30 px-5 py-2 text-sm font-bold dark:border-white/30">Soumettre au vote</span>
                        </div>
                    </div>
                </div>

                <div class="hiw-scene w-full max-w-md" data-scene="4" hidden>
                    <div class="flex flex-col gap-3 {{ $card }}">
                        <h3 class="flex items-center gap-2 text-lg font-bold"><flux:icon.check-circle variant="mini" class="shrink-0" />Date retenue</h3>
                        <div class="hiw-pop hiw-glow rounded-xl bg-milk px-4 py-3 dark:bg-night-raised">
                            <p class="text-base font-extrabold">mercredi 12 novembre</p>
                            <p class="text-sm font-semibold">18h00–20h00</p>
                        </div>
                        <div class="hiw-pop hiw-d2"><span class="inline-flex items-center gap-2 rounded-full bg-forest px-5 py-2 text-sm font-bold text-sun dark:bg-sun dark:text-forest"><flux:icon.calendar-days variant="micro" />Ajouter à mon agenda</span></div>
                    </div>
                </div>
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
