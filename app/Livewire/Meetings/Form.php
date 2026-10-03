<?php

namespace App\Livewire\Meetings;

use App\Enums\MeetingStatus;
use App\Models\Group;
use App\Models\Meeting;
use App\Notifications\MeetingRequestedNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

class Form extends Component
{
    public const int MaxRangeDays = 62;

    public Group $group;

    public ?Meeting $meeting = null;

    public string $title = '';

    public string $description = '';

    public string $location = '';

    public string $rangeStart = '';

    public string $rangeEnd = '';

    public string $deadline = '';

    public function mount(?Group $group = null, ?Meeting $meeting = null): void
    {
        if ($meeting?->exists) {
            $this->authorizeEditing($meeting);

            $this->meeting = $meeting;
            $this->group = $meeting->group;
            $this->title = $meeting->title;
            $this->description = (string) $meeting->description;
            $this->location = (string) $meeting->location;
            $this->rangeStart = $meeting->range_start->toDateString();
            $this->rangeEnd = $meeting->range_end->toDateString();
            $this->deadline = $meeting->deadline->toDateString();

            return;
        }

        $this->authorize('manage', $group);

        $this->group = $group;
    }

    public function hydrate(): void
    {
        $this->meeting?->exists ? $this->authorizeEditing($this->meeting) : $this->authorize('manage', $this->group);
    }

    public function save(): void
    {
        $today = today(config('app.display_timezone'))->toDateString();

        $startChanged = ! $this->meeting?->exists || $this->meeting->range_start->toDateString() !== $this->rangeStart;
        $deadlineChanged = ! $this->meeting?->exists || $this->meeting->deadline->toDateString() !== $this->deadline;

        $this->validate([
            'title' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:2000'],
            'location' => ['nullable', 'string', 'max:255'],
            'rangeStart' => ['required', 'date_format:Y-m-d', ...($startChanged ? ['after_or_equal:'.$today] : [])],
            'rangeEnd' => ['required', 'date_format:Y-m-d', 'after_or_equal:rangeStart'],
            'deadline' => ['required', 'date_format:Y-m-d', ...($deadlineChanged ? ['after_or_equal:'.$today] : []), 'before_or_equal:rangeEnd'],
        ]);

        if (Carbon::parse($this->rangeStart)->diffInDays(Carbon::parse($this->rangeEnd)) + 1 > self::MaxRangeDays) {
            throw ValidationException::withMessages(['rangeEnd' => __('The range cannot exceed :days days.', ['days' => self::MaxRangeDays])]);
        }

        $creating = ! $this->meeting?->exists;

        $meeting = DB::transaction(function (): Meeting {
            $meeting = $this->meeting ?? new Meeting;
            $meeting->fill([
                'title' => $this->title,
                'description' => $this->description ?: null,
                'location' => $this->location ?: null,
                'range_start' => $this->rangeStart,
                'range_end' => $this->rangeEnd,
                'deadline' => $this->deadline,
            ]);

            if (! $meeting->exists) {
                $meeting->group()->associate($this->group);
                $meeting->creator()->associate(Auth::user());
            }

            $meeting->save();

            $meeting->availabilityDays()
                ->where(fn ($query) => $query->where('day', '<', $this->rangeStart)->orWhere('day', '>', $this->rangeEnd))
                ->delete();

            return $meeting;
        });

        if ($creating) {
            foreach ($this->group->members()->whereKeyNot(Auth::id())->get() as $recipient) {
                rescue(fn () => $recipient->notify(new MeetingRequestedNotification($meeting)), report: true);
            }
        }

        $this->redirectRoute('meetings.show', $meeting, navigate: true);
    }

    public function deleteMeeting(): void
    {
        $this->authorize('manage', $this->meeting);

        $this->meeting->delete();

        $this->redirectRoute('groups.show', $this->group, navigate: true);
    }

    private function authorizeEditing(Meeting $meeting): void
    {
        $this->authorize('manage', $meeting);

        abort_unless($meeting->status === MeetingStatus::Collecting, 403);
    }
}
