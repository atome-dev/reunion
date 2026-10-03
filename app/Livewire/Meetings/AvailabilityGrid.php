<?php

namespace App\Livewire\Meetings;

use App\Models\AvailabilityDay;
use App\Models\Meeting;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Component;

class AvailabilityGrid extends Component
{
    public Meeting $meeting;

    public function mount(Meeting $meeting): void
    {
        $this->authorize('view', $meeting);

        $this->meeting = $meeting;
    }

    public function hydrate(): void
    {
        $this->authorize('view', $this->meeting);
    }

    /**
     * Save the days changed in the grid; a day left empty is removed.
     *
     * @param  array<string, string>  $days
     */
    public function saveDays(array $days): void
    {
        $this->authorize('editAvailability', $this->meeting);

        $allowedDays = $this->meeting->rangeDays();

        foreach ($days as $day => $cells) {
            if (! in_array($day, $allowedDays, true) || ! is_string($cells) || ! preg_match('/^[0pd]{28}$/', $cells)) {
                throw ValidationException::withMessages(['days' => __('This availability could not be saved.')]);
            }
        }

        foreach ($days as $day => $cells) {
            $query = AvailabilityDay::query()->where('meeting_id', $this->meeting->id)->where('user_id', Auth::id())->where('day', $day);

            if ($cells === AvailabilityDay::Empty) {
                $query->delete();

                continue;
            }

            $existing = $query->first() ?? tap(new AvailabilityDay, function (AvailabilityDay $new) use ($day): void {
                $new->meeting()->associate($this->meeting);
                $new->user()->associate(Auth::user());
                $new->day = $day;
            });

            $existing->cells = $cells;
            $existing->save();
        }

        unset($this->cells, $this->respondentCount);
    }

    #[Computed]
    public function canEdit(): bool
    {
        return Gate::allows('editAvailability', $this->meeting);
    }

    /**
     * @return array<string, string>
     */
    #[Computed]
    public function cells(): array
    {
        return $this->meeting->availabilityDays()->where('user_id', Auth::id())->orderBy('day')->pluck('cells', 'day')->all();
    }

    /**
     * Weeks from monday to sunday covering the range.
     *
     * @return list<list<array{date: string, label: string, inRange: bool}>>
     */
    #[Computed]
    public function weeks(): array
    {
        $start = CarbonImmutable::parse($this->meeting->range_start->toDateString())->startOfWeek();
        $end = CarbonImmutable::parse($this->meeting->range_end->toDateString())->endOfWeek();
        $inRange = array_flip($this->meeting->rangeDays());
        $weeks = [];

        for ($day = $start; $day->lte($end); $day = $day->addDay()) {
            $weeks[intdiv((int) $start->diffInDays($day), 7)][] = [
                'date' => $day->toDateString(),
                'label' => ucfirst($day->locale(app()->getLocale())->translatedFormat('D j M')),
                'inRange' => isset($inRange[$day->toDateString()]),
            ];
        }

        return $weeks;
    }

    #[Computed]
    public function respondentCount(): int
    {
        return $this->meeting->respondentIds()->count();
    }

    #[Computed]
    public function memberCount(): int
    {
        return $this->meeting->group->members()->count();
    }
}
