<?php

namespace App\View\Components;

use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/**
 * Illustrative availability poll shown on public pages to demonstrate how the best date emerges.
 * The data is fictional and labelled as such wherever it is rendered.
 */
class DemoPoll extends Component
{
    public const string OnSite = 'p';

    public const string Remote = 'd';

    public string $group = 'Jardin partagé des Lilas';

    /**
     * @var list<array{day: string, date: string, time: string}>
     */
    public array $dates = [
        ['day' => 'Mar.', 'date' => '14 oct.', 'time' => '18h30'],
        ['day' => 'Jeu.', 'date' => '16 oct.', 'time' => '18h30'],
        ['day' => 'Sam.', 'date' => '18 oct.', 'time' => '10h00'],
        ['day' => 'Lun.', 'date' => '20 oct.', 'time' => '19h00'],
        ['day' => 'Mer.', 'date' => '22 oct.', 'time' => '18h30'],
    ];

    /**
     * Each answer is on site ("p"), remote ("d") or unavailable (null), one per date.
     *
     * @var list<array{name: string, answers: list<string|null>}>
     */
    public array $members = [
        ['name' => 'Amina', 'answers' => ['p', 'd', null, 'p', 'p']],
        ['name' => 'Bastien', 'answers' => [null, 'p', 'p', 'd', 'p']],
        ['name' => 'Chloé', 'answers' => ['p', 'p', null, null, 'd']],
        ['name' => 'David', 'answers' => ['d', 'p', 'p', 'p', 'p']],
        ['name' => 'Élise', 'answers' => ['p', null, 'p', 'd', 'p']],
        ['name' => 'Farid', 'answers' => [null, 'd', 'd', 'p', 'p']],
        ['name' => 'Gaëlle', 'answers' => ['p', 'p', null, 'p', 'd']],
        ['name' => 'Hugo', 'answers' => ['d', null, 'p', null, 'p']],
        ['name' => 'Inès', 'answers' => ['p', 'p', 'p', null, null]],
    ];

    /**
     * @var list<array{onSite: int, remote: int}>
     */
    public array $tallies;

    public int $best;

    public function __construct(public bool $interactive = false, public bool $compact = false)
    {
        $this->tallies = array_map(fn (int $index): array => [
            'onSite' => count(array_filter($this->members, fn (array $member): bool => $member['answers'][$index] === self::OnSite)),
            'remote' => count(array_filter($this->members, fn (array $member): bool => $member['answers'][$index] === self::Remote)),
        ], array_keys($this->dates));

        $this->best = $this->bestDateIndex();
    }

    /**
     * The date gathering the most members, preferring the one with more people on site on a tie.
     */
    public function bestDateIndex(): int
    {
        $best = 0;

        foreach ($this->tallies as $index => $tally) {
            $total = $tally['onSite'] + $tally['remote'];
            $bestTotal = $this->tallies[$best]['onSite'] + $this->tallies[$best]['remote'];

            if ($total > $bestTotal || ($total === $bestTotal && $tally['onSite'] > $this->tallies[$best]['onSite'])) {
                $best = $index;
            }
        }

        return $best;
    }

    public function render(): View|Closure|string
    {
        return view('components.demo-poll');
    }
}
