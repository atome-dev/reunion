<?php

namespace App\Livewire\Meetings;

use App\Actions\Meetings\ConfirmMeeting;
use App\Actions\Meetings\FindBestWindows;
use App\Actions\Meetings\OpenVote;
use App\Exceptions\InvalidMeetingTransition;
use App\Models\AvailabilityDay;
use App\Models\Meeting;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;

class Summary extends Component
{
    public Meeting $meeting;

    #[Url]
    public ?int $duration = 120;

    #[Url(as: 'min')]
    public ?int $minParticipants = 1;

    #[Url(as: 'on_site')]
    public ?int $minOnSite = 0;

    /** @var list<string> */
    public array $selected = [];

    /** @var array<string, int|string> */
    public array $starts = [];

    public function mount(Meeting $meeting): void
    {
        $this->authorize('manage', $meeting);

        $this->meeting = $meeting;
        $this->normalizeSettings();
    }

    public function hydrate(): void
    {
        $this->authorize('manage', $this->meeting);
    }

    public function updated(string $property): void
    {
        if (str_starts_with($property, 'selected') || str_starts_with($property, 'starts')) {
            return;
        }

        $this->normalizeSettings();
        $this->selected = [];
        unset($this->windows);
    }

    /**
     * The organizer edited their own grid on this page: the windows and respondents are stale.
     */
    #[On('availability-saved')]
    public function refreshWindows(): void
    {
        unset($this->windows, $this->nonRespondents);
    }

    /**
     * Bring the settings (possibly empty or coming from the query string) back within their bounds.
     */
    private function normalizeSettings(): void
    {
        $this->duration = max(30, min(480, intdiv($this->duration ?? 120, 30) * 30));
        $this->minParticipants = max(1, $this->minParticipants ?? 1);
        $this->minOnSite = max(0, min($this->minOnSite ?? 0, $this->minParticipants));
    }

    public function confirmWindow(string $key, ConfirmMeeting $confirmMeeting): void
    {
        $this->authorize('manage', $this->meeting);

        [$startsAt, $endsAt] = $this->chosenSlot($key);

        $this->applyTransition(fn () => $confirmMeeting($this->meeting, $startsAt, $endsAt));
    }

    public function openVote(OpenVote $openVote): void
    {
        $this->authorize('manage', $this->meeting);

        $slots = array_map(fn (string $key): array => $this->chosenSlot($key, 'selected'), array_values(array_unique($this->selected)));

        $this->applyTransition(fn () => $openVote($this->meeting, $slots), 'selected');
    }

    /**
     * @return Collection<int, User>
     */
    #[Computed]
    public function members(): Collection
    {
        return $this->meeting->group->members()->orderBy('name')->get();
    }

    /**
     * @return Collection<int, User>
     */
    #[Computed]
    public function nonRespondents(): Collection
    {
        $respondentIds = $this->meeting->respondentIds();

        return $this->members->reject(fn (User $member): bool => $respondentIds->contains($member->id))->values();
    }

    /**
     * @return list<array{key: string, day: string, firstStart: int, lastStart: int, length: int, onSite: list<int>, remote: list<int>}>
     */
    #[Computed]
    public function windows(): array
    {
        $windows = (new FindBestWindows)(
            $this->meeting->cellsByMember(),
            $this->meeting->rangeDays(),
            $this->duration,
            $this->minParticipants,
            $this->minOnSite,
        );

        return array_map(fn (array $window): array => ['key' => $window['day'].'|'.$window['firstStart']] + $window, $windows);
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function chosenSlot(string $key, string $errorKey = 'starts'): array
    {
        $window = collect($this->windows)->firstWhere('key', $key);
        $start = (int) ($this->starts[$key] ?? $window['firstStart'] ?? -1);

        if ($window === null || $start < $window['firstStart'] || $start > $window['lastStart']) {
            throw ValidationException::withMessages([$errorKey => __('Choose a start time within this slot.')]);
        }

        return [
            AvailabilityDay::localDateTime($window['day'], $start)->utc(),
            AvailabilityDay::localDateTime($window['day'], $start + $window['length'])->utc(),
        ];
    }

    private function applyTransition(callable $action, string $errorKey = 'starts'): void
    {
        try {
            $action();
        } catch (InvalidMeetingTransition $exception) {
            throw ValidationException::withMessages([$errorKey => $exception->getMessage()]);
        }

        $this->redirectRoute('meetings.show', $this->meeting, navigate: true);
    }
}
