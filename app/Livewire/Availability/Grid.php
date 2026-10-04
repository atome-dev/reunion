<?php

namespace App\Livewire\Availability;

use App\Actions\Availability\BusyCells;
use App\Models\AvailabilityDay;
use App\Models\Meeting;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Carbon\CarbonPeriod;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * The signed-in user's availability calendar, alone or restricted to a meeting's range.
 */
class Grid extends Component
{
    #[Locked]
    public ?Meeting $meeting = null;

    public function mount(?Meeting $meeting = null): void
    {
        if ($meeting !== null) {
            $this->authorize('view', $meeting);
        }

        $this->meeting = $meeting;
    }

    public function hydrate(): void
    {
        if ($this->meeting !== null) {
            $this->authorize('view', $this->meeting);
        }
    }

    /**
     * Save the days changed in the grid; a day left empty is removed.
     *
     * @param  array<string, string>  $days
     */
    public function saveDays(array $days): bool
    {
        if ($this->meeting !== null) {
            $this->authorize('editAvailability', $this->meeting);
        }

        $this->resetErrorBag('days');

        $editableDays = array_flip($this->editableDays());

        foreach ($days as $day => $cells) {
            if (! isset($editableDays[$day]) || ! is_string($cells) || ! preg_match('/^[0pd]{28}$/', $cells)) {
                throw ValidationException::withMessages(['days' => __('This availability could not be saved.')]);
            }
        }

        $stored = AvailabilityDay::query()->where('user_id', Auth::id())->whereIn('day', array_keys($days))->pluck('cells', 'day');

        foreach ($days as $day => $cells) {
            $current = $stored[$day] ?? AvailabilityDay::Empty;

            foreach (array_keys($this->busy[$day] ?? []) as $index) {
                if ($cells[$index] !== $current[$index]) {
                    throw ValidationException::withMessages(['days' => __('This availability could not be saved.')]);
                }
            }
        }

        DB::transaction(function () use ($days): void {
            foreach ($days as $day => $cells) {
                if ($cells === AvailabilityDay::Empty) {
                    AvailabilityDay::query()->where('user_id', Auth::id())->where('day', $day)->delete();

                    continue;
                }

                AvailabilityDay::unguarded(fn () => AvailabilityDay::query()->updateOrCreate(
                    ['user_id' => Auth::id(), 'day' => $day],
                    ['cells' => $cells],
                ));
            }
        });

        unset($this->cells, $this->respondentCount);

        $this->dispatch('availability-saved');

        return true;
    }

    #[Computed]
    public function canEdit(): bool
    {
        return $this->meeting === null || Gate::allows('editAvailability', $this->meeting);
    }

    /**
     * @return array<string, string>
     */
    #[Computed]
    public function cells(): array
    {
        [$first, $last] = $this->shownBounds();

        if ($this->meeting === null) {
            $first = $first->startOfWeek(CarbonInterface::MONDAY);
        }

        return AvailabilityDay::query()
            ->where('user_id', Auth::id())
            ->whereBetween('day', [$first->toDateString(), $last->toDateString()])
            ->orderBy('day')
            ->pluck('cells', 'day')
            ->all();
    }

    /**
     * Cells taken by the user's confirmed meetings on the displayed weeks, with what to show on them.
     *
     * @return array<string, array<int, array{title: string, group: string}>>
     */
    #[Computed]
    public function busy(): array
    {
        [$first, $last] = $this->shownBounds();
        $busy = (new BusyCells)(
            [Auth::id()],
            $first->startOfWeek(CarbonInterface::MONDAY)->toDateString(),
            $last->endOfWeek(CarbonInterface::SUNDAY)->toDateString(),
        )[Auth::id()] ?? [];

        return array_map(
            fn (array $cells): array => array_map(fn (array $meeting): array => ['title' => $meeting['title'], 'group' => $meeting['group']], $cells),
            $busy,
        );
    }

    /**
     * Weeks from monday to sunday covering the shown days; "inRange" marks the editable ones.
     *
     * @return list<list<array{date: string, label: string, inRange: bool}>>
     */
    #[Computed]
    public function weeks(): array
    {
        [$first, $last] = $this->shownBounds();
        $start = $first->startOfWeek(CarbonInterface::MONDAY);
        $end = $last->endOfWeek(CarbonInterface::SUNDAY);
        $editable = array_flip($this->editableDays());
        $weeks = [];

        for ($day = $start; $day->lte($end); $day = $day->addDay()) {
            $weeks[intdiv((int) $start->diffInDays($day), 7)][] = [
                'date' => $day->toDateString(),
                'label' => ucfirst($day->locale(app()->getLocale())->translatedFormat('D j M')),
                'inRange' => isset($editable[$day->toDateString()]),
            ];
        }

        return $weeks;
    }

    #[Computed]
    public function respondentCount(): int
    {
        return $this->meeting?->respondentIds()->count() ?? 0;
    }

    #[Computed]
    public function memberCount(): int
    {
        return $this->meeting?->group->members()->count() ?? 0;
    }

    /**
     * First and last day displayed: the meeting range, or today to the last editable day.
     *
     * @return array{CarbonImmutable, CarbonImmutable}
     */
    private function shownBounds(): array
    {
        if ($this->meeting !== null) {
            return [
                CarbonImmutable::parse($this->meeting->range_start->toDateString()),
                CarbonImmutable::parse($this->meeting->range_end->toDateString()),
            ];
        }

        return [CarbonImmutable::parse($this->today()), AvailabilityDay::lastEditableDay()];
    }

    /**
     * Days the user may change: shown days between today and the last editable day.
     *
     * @return list<string>
     */
    private function editableDays(): array
    {
        [$first, $last] = $this->shownBounds();
        $today = $this->today();
        $lastEditable = AvailabilityDay::lastEditableDay()->toDateString();

        return collect(CarbonPeriod::create($first->toDateString(), $last->toDateString()))
            ->map(fn (CarbonInterface $day): string => $day->toDateString())
            ->filter(fn (string $day): bool => $day >= $today && $day <= $lastEditable)
            ->values()
            ->all();
    }

    private function today(): string
    {
        return CarbonImmutable::today(config('app.display_timezone'))->toDateString();
    }
}
