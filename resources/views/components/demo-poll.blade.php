@php
    $chip = $compact ? 'size-5' : 'size-7';
    $chipIcon = $compact ? 'size-3' : 'size-3.5';
    $avatarColors = ['bg-lagoon text-forest', 'bg-blush text-forest', 'bg-sun text-forest', 'bg-forest text-sun dark:bg-sun dark:text-forest'];
@endphp

<figure {{ $attributes->class([
    'demo-poll rounded-[28px] bg-white text-forest shadow-[0_24px_50px_-20px_rgba(18,61,47,0.45)] dark:bg-night dark:text-milk dark:shadow-[0_24px_50px_-20px_rgba(0,0,0,0.6)] dark:ring-1 dark:ring-white/10',
    'p-4 sm:p-6' => ! $compact,
    'p-4' => $compact,
]) }} @if ($interactive) data-interactive @endif>
    <div class="mb-4 flex flex-wrap items-center justify-between gap-2 px-1">
        <p @class(['font-bold', 'text-lg' => ! $compact, 'text-sm' => $compact])>{{ $group }} · Assemblée de rentrée</p>
        <p class="flex items-center gap-3 text-xs font-medium sm:text-sm">
            <span class="inline-flex items-center gap-1"><span class="grid size-5 place-items-center rounded-full bg-forest text-white dark:bg-sun dark:text-forest"><flux:icon.map-pin variant="micro" class="size-3" /></span>en vrai</span>
            <span class="inline-flex items-center gap-1"><span class="grid size-5 place-items-center rounded-full bg-blush text-forest"><flux:icon.video-camera variant="micro" class="size-3" /></span>en visio</span>
        </p>
    </div>

    <table class="w-full border-separate border-spacing-0 text-xs sm:text-sm">
        <thead>
            <tr>
                <th scope="col" class="sm:w-28"><span class="sr-only">Membre</span></th>
                @foreach ($dates as $index => $date)
                    <th scope="col" data-col="{{ $index }}" @class(['relative rounded-t-2xl px-0.5 pt-6 pb-2 text-center font-semibold sm:px-1.5', 'is-best' => $index === $best])>
                        <span class="best-flag absolute inset-x-0 top-0 mx-auto w-fit rounded-full bg-forest px-2 py-0.5 text-[11px] font-bold text-sun dark:bg-sun dark:text-forest">Top</span>
                        <span class="block text-xs font-medium opacity-60">{{ $date['day'] }}</span>
                        <span class="block">{{ $date['date'] }}</span>
                    </th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @foreach ($members as $memberIndex => $member)
                <tr>
                    <th scope="row" class="py-1 pr-2 text-left font-semibold">
                        <span class="flex items-center gap-2">
                            @unless ($compact)
                                <span class="hidden size-7 shrink-0 place-items-center rounded-full text-xs font-bold sm:grid {{ $avatarColors[$memberIndex % 4] }}" aria-hidden="true">{{ mb_substr($member['name'], 0, 1) }}</span>
                            @endunless
                            {{ $member['name'] }}
                        </span>
                    </th>
                    @foreach ($member['answers'] as $index => $answer)
                        <td data-col="{{ $index }}" @class(['px-0.5 py-1 text-center sm:px-1.5', 'is-best' => $index === $best])>
                            @if ($answer === \App\View\Components\DemoPoll::OnSite)
                                <span class="mx-auto grid {{ $chip }} place-items-center rounded-full bg-forest text-white dark:bg-sun dark:text-forest"><flux:icon.map-pin variant="micro" class="{{ $chipIcon }}" /><span class="sr-only">En vrai</span></span>
                            @elseif ($answer === \App\View\Components\DemoPoll::Remote)
                                <span class="mx-auto grid {{ $chip }} place-items-center rounded-full bg-blush text-forest"><flux:icon.video-camera variant="micro" class="{{ $chipIcon }}" /><span class="sr-only">En visio</span></span>
                            @else
                                <span class="mx-auto block size-2 rounded-full bg-forest/15 dark:bg-white/15"></span><span class="sr-only">Pas dispo</span>
                            @endif
                        </td>
                    @endforeach
                </tr>
            @endforeach

            @if ($interactive)
                <tr>
                    <th scope="row" class="pt-3 pr-2 pb-1 text-left font-bold">
                        <span class="flex items-center gap-2">
                            <span class="hidden size-7 shrink-0 place-items-center rounded-full border-2 border-dashed border-current text-xs sm:grid" aria-hidden="true">?</span>
                            Vous
                        </span>
                    </th>
                    @foreach ($dates as $index => $date)
                        <td data-col="{{ $index }}" @class(['px-0.5 pt-3 pb-1 text-center sm:px-1.5', 'is-best' => $index === $best])>
                            <button type="button" class="you-cell mx-auto grid size-9 cursor-pointer place-items-center rounded-full border-2 border-dashed border-forest/40 bg-white transition hover:border-forest dark:border-white/30 dark:bg-night dark:hover:border-sun" data-you="{{ $index }}" data-state="" aria-label="Votre disponibilité le {{ $date['day'] }} {{ $date['date'] }} : pas dispo">
                                <span class="pointer-events-none" data-icon></span>
                            </button>
                        </td>
                    @endforeach
                </tr>
            @endif

            <tr>
                <th scope="row" class="pt-3 pr-2 text-left text-sm font-semibold opacity-70">Total</th>
                @foreach ($tallies as $index => $tally)
                    <td class="pt-3">
                        <span data-total="{{ $index }}" data-base="{{ $tally['onSite'] + $tally['remote'] }}" data-base-onsite="{{ $tally['onSite'] }}" @class(['mx-auto block w-fit rounded-full px-3 py-1 font-extrabold tabular-nums transition', 'text-base' => ! $compact, 'text-sm' => $compact, 'is-best' => $index === $best])>{{ $tally['onSite'] + $tally['remote'] }}</span>
                    </td>
                @endforeach
            </tr>
        </tbody>
    </table>

    <figcaption class="mt-4 px-1 text-xs opacity-75">
        @if ($interactive)
            Cliquez sur vos cases : pas dispo → en vrai → en visio.
        @endif
        Exemple fictif.
    </figcaption>

    @if ($interactive)
        <template data-icon-onsite><span class="grid size-7 place-items-center rounded-full bg-forest text-white dark:bg-sun dark:text-forest"><flux:icon.map-pin variant="micro" class="size-3.5" /></span></template>
        <template data-icon-remote><span class="grid size-7 place-items-center rounded-full bg-blush text-forest"><flux:icon.video-camera variant="micro" class="size-3.5" /></span></template>
    @endif
</figure>

@once
    <style>
        .demo-poll [data-col] { transition: background-color 300ms cubic-bezier(0.16, 1, 0.3, 1); }
        .demo-poll [data-col].is-best { background: color-mix(in srgb, var(--color-sun) 45%, white); }
        .dark .demo-poll [data-col].is-best { background: color-mix(in srgb, var(--color-sun) 16%, var(--color-night)); }
        .demo-poll [data-total].is-best { background: var(--color-forest); color: var(--color-sun); }
        .dark .demo-poll [data-total].is-best { background: var(--color-sun); color: var(--color-forest); }
        .demo-poll .best-flag { opacity: 0; transform: translateY(4px) scale(0.9); transition: all 300ms cubic-bezier(0.16, 1, 0.3, 1); }
        .demo-poll .is-best .best-flag { opacity: 1; transform: none; }
        .demo-poll .you-cell:active { transform: scale(0.92); }

        @media (prefers-reduced-motion: reduce) {
            .demo-poll * { transition: none !important; }
        }
    </style>

    <script>
        const initDemoPolls = () => {
            document.querySelectorAll('.demo-poll[data-interactive]:not([data-ready])').forEach((poll) => {
                poll.dataset.ready = '';
                const cycle = { '': 'p', p: 'd', d: '' };
                const labels = { '': 'pas dispo', p: 'en vrai', d: 'en visio' };
                const icons = {
                    p: poll.querySelector('[data-icon-onsite]'),
                    d: poll.querySelector('[data-icon-remote]'),
                };

                const refresh = () => {
                    const scores = [...poll.querySelectorAll('[data-total]')].map((total) => {
                        const column = total.dataset.total;
                        const yourState = poll.querySelector(`[data-you="${column}"]`).dataset.state;
                        const count = Number(total.dataset.base) + (yourState ? 1 : 0);
                        const onSite = Number(total.dataset.baseOnsite) + (yourState === 'p' ? 1 : 0);
                        total.textContent = count;

                        return { column, count, onSite };
                    });

                    const best = scores.reduce((a, b) => (b.count > a.count || (b.count === a.count && b.onSite > a.onSite) ? b : a));

                    poll.querySelectorAll('[data-col], [data-total]').forEach((cell) => {
                        cell.classList.toggle('is-best', (cell.dataset.col ?? cell.dataset.total) === best.column);
                    });
                };

                poll.querySelectorAll('[data-you]').forEach((button) => {
                    button.addEventListener('click', () => {
                        const next = cycle[button.dataset.state];

                        button.dataset.state = next;
                        button.querySelector('[data-icon]').replaceChildren(next ? icons[next].content.cloneNode(true) : '');
                        button.classList.toggle('border-dashed', ! next);
                        button.setAttribute('aria-label', button.getAttribute('aria-label').replace(/: .*$/, `: ${labels[next]}`));
                        refresh();
                    });
                });
            });
        };

        document.addEventListener('DOMContentLoaded', initDemoPolls);
        document.addEventListener('livewire:navigated', initDemoPolls);
    </script>
@endonce
