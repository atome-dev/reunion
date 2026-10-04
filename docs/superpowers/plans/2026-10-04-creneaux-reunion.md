# Créneaux « Réunion » — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Confirmed meetings appear as blocked « Réunion » cells in every group member's availability grid and count as unavailable for every other request.

**Architecture:** A `BusyCells` action computes, on the fly, which grid cells each user has taken by confirmed meetings of their current groups. Summary and vote mask those cells (`AvailabilityDay::withoutBusy`); the grid receives them in a `data-busy` attribute, renders them blocked, and the server refuses changes under them.

**Tech Stack:** Laravel 13, PHP 8.4, Livewire 4, Alpine, Flux Pro, Pest 4, French UI via `lang/fr.json`.

**Spec:** `docs/superpowers/specs/2026-10-04-creneaux-reunion-design.md`

## Global Constraints

- Grid: 28 half-hour cells from 8:00 to 22:00 (`AvailabilityDay::CellCount`, `FirstHour`), chars `0`/`p`/`d`; display timezone `config('app.display_timezone')` (Europe/Paris); meeting instants stored in UTC.
- Busy cells come only from `confirmed` meetings of groups the user is a **current** member of; never stored in `availability_days`.
- A request never blocks itself (`$exceptMeetingId`).
- Titles/groups of busy meetings are shown only in the user's own grid; summary and vote only use indexes.
- « A répondu » stays based on stored cells (`Meeting::cellsByMember()` / `respondentIds()` unchanged).
- Busy cells color: `bg-sun`; legend label « Réunion ».
- Never run migrate:fresh/refresh, db:wipe or seeders on the local DB.
- Bash: files only via Write/Edit; no shell loops/variables; no `cd` prefix; `git --no-pager`.
- `vendor/bin/pint --dirty --format agent` after PHP edits; `npm run build` after view/JS edits.
- Commits end with `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`. No push.

## Review Focus

1. A member who left the group no longer sees its meetings as busy (Task 1 test « former members are not blocked »).
2. A meeting on the DST day (25 Oct 2026, 18:30–20:30 Paris) lands on cells 21–24 (Task 1 test « cells follow Paris time across DST »).
3. Saving a day that changes a cell under a meeting is refused, other days in the same batch untouched (Task 2 test « changes under a meeting are refused »).
4. The summary of a request in another group never contains the busy meeting's title (Task 1 test « other groups only see unavailability »).
5. A member whose only filled cells are under a meeting still counts as answered (Task 1 test « answering is unchanged »).

---

### Task 1: Busy cells for summary and vote

**Files:**
- Create: `app/Actions/Availability/BusyCells.php`
- Modify: `app/Models/AvailabilityDay.php`, `app/Models/Meeting.php`, `app/Livewire/Meetings/Summary.php`, `app/Livewire/Meetings/Vote.php`
- Test: `tests/Feature/Availability/BusyCellsTest.php`

**Interfaces:**
- Produces: `BusyCells::__invoke(array $userIds, string $from, string $to, ?int $exceptMeetingId = null): array<int, array<string, array<int, array{meetingId: int, title: string, group: string}>>>` (user id → `Y-m-d` → cell index → meeting); `AvailabilityDay::withoutBusy(string $cells, array $busyIndexes): string`; `Meeting::availableCellsByMember(): array<int, array<string, string>>`.

- [ ] **Step 1: Write the failing tests**

`php artisan make:test --pest Availability/BusyCellsTest --no-interaction`:

```php
<?php

use App\Actions\Availability\BusyCells;
use App\Livewire\Meetings\Summary;
use App\Livewire\Meetings\Vote;
use App\Models\AvailabilityDay;
use App\Models\Group;
use App\Models\Meeting;
use App\Models\MeetingSlot;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

beforeEach(function () {
    Notification::fake();
    $this->travelTo(now('Europe/Paris')->setDate(2026, 11, 2)->setTime(10, 0));
    $this->groupA = Group::factory()->create(['name' => 'Bureau']);
    $this->member = User::factory()->create();
    $this->groupA->addMember($this->member);
    // Thursday 5 Nov 2026, 18:00–20:00 Paris = 17:00–19:00 UTC → cells 20..23
    $this->confirmed = Meeting::factory()->for($this->groupA)
        ->confirmed(CarbonImmutable::parse('2026-11-05 17:00:00', 'UTC'))
        ->create(['title' => 'Conseil secret', 'range_start' => '2026-11-03', 'range_end' => '2026-11-06', 'deadline' => '2026-11-02']);
});

test('a confirmed meeting blocks its cells for every current member', function () {
    $busy = (new BusyCells)([$this->member->id, $this->groupA->owner_id], '2026-11-02', '2026-11-08');

    expect(array_keys($busy[$this->member->id]['2026-11-05']))->toBe([20, 21, 22, 23])
        ->and($busy[$this->member->id]['2026-11-05'][20])->toMatchArray(['title' => 'Conseil secret', 'group' => 'Bureau'])
        ->and(array_keys($busy[$this->groupA->owner_id]['2026-11-05']))->toBe([20, 21, 22, 23]);
});

test('requests still collecting or voting block nothing', function () {
    $this->confirmed->update(['status' => \App\Enums\MeetingStatus::Voting]);

    expect((new BusyCells)([$this->member->id], '2026-11-02', '2026-11-08'))->toBe([]);
});

test('former members are not blocked and the request itself can be excluded', function () {
    expect((new BusyCells)([$this->member->id], '2026-11-02', '2026-11-08', $this->confirmed->id))->toBe([]);

    $this->groupA->removeMember($this->member);

    expect((new BusyCells)([$this->member->id], '2026-11-02', '2026-11-08'))->toBe([]);
});

test('cells follow Paris time across DST', function () {
    // 25 Oct 2026 is the switch to winter time: 18:30 Paris = 17:30 UTC
    Meeting::factory()->for($this->groupA)
        ->confirmed(CarbonImmutable::parse('2026-10-25 17:30:00', 'UTC'))
        ->create();

    $busy = (new BusyCells)([$this->member->id], '2026-10-25', '2026-10-25');

    expect(array_keys($busy[$this->member->id]['2026-10-25']))->toBe([21, 22, 23, 24]);
});

test('other groups only see unavailability', function () {
    $groupB = Group::factory()->create();
    $groupB->addMember($this->member);
    $request = Meeting::factory()->for($groupB)->create(['range_start' => '2026-11-05', 'range_end' => '2026-11-05', 'deadline' => '2026-11-04']);
    AvailabilityDay::factory()->for($this->member)->cells(str_repeat('0', 20).'pppp0000')->create(['day' => '2026-11-05']);
    AvailabilityDay::factory()->for($groupB->owner)->cells(str_repeat('0', 20).'pppp0000')->create(['day' => '2026-11-05']);

    $available = $request->availableCellsByMember();

    expect($available[$this->member->id]['2026-11-05'])->toBe(AvailabilityDay::Empty)
        ->and($available[$groupB->owner_id]['2026-11-05'])->toBe(str_repeat('0', 20).'pppp0000');

    Livewire::actingAs($groupB->owner)->test(Summary::class, ['meeting' => $request])
        ->set('duration', 120)
        ->assertDontSee('Conseil secret');
});

test('answering is unchanged', function () {
    $groupB = Group::factory()->create();
    $groupB->addMember($this->member);
    $request = Meeting::factory()->for($groupB)->create(['range_start' => '2026-11-05', 'range_end' => '2026-11-05', 'deadline' => '2026-11-04']);
    AvailabilityDay::factory()->for($this->member)->cells(str_repeat('0', 20).'pppp0000')->create(['day' => '2026-11-05']);

    expect($request->respondentIds()->all())->toContain($this->member->id);
});

test('the vote is prefilled unavailable on a busy slot', function () {
    $groupB = Group::factory()->create();
    $groupB->addMember($this->member);
    $request = Meeting::factory()->for($groupB)->voting()->create(['range_start' => '2026-11-05', 'range_end' => '2026-11-05', 'deadline' => '2026-11-04']);
    $slot = MeetingSlot::factory()->for($request)->create(['starts_at' => '2026-11-05 17:00:00', 'ends_at' => '2026-11-05 19:00:00']);
    AvailabilityDay::factory()->for($this->member)->cells(str_repeat('0', 20).'pppp0000')->create(['day' => '2026-11-05']);

    $component = Livewire::actingAs($this->member)->test(Vote::class, ['meeting' => $request]);

    expect($component->get('responses')[$slot->id])->toBe(\App\Enums\AvailabilityStatus::Unavailable->value);
});
```

Check factory state names (`MeetingFactory::voting()`, `MeetingSlotFactory`) and adapt if they differ.

- [ ] **Step 2: Run to verify they fail**

Run: `php artisan test --compact tests/Feature/Availability/BusyCellsTest.php` → FAIL (class `BusyCells` missing).

- [ ] **Step 3: `BusyCells`**

```php
<?php

namespace App\Actions\Availability;

use App\Enums\MeetingStatus;
use App\Models\AvailabilityDay;
use App\Models\Meeting;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Grid cells taken by confirmed meetings of the users' current groups, computed on the fly.
 */
class BusyCells
{
    /**
     * @param  list<int>  $userIds
     * @return array<int, array<string, array<int, array{meetingId: int, title: string, group: string}>>>
     */
    public function __invoke(array $userIds, string $from, string $to, ?int $exceptMeetingId = null): array
    {
        if ($userIds === []) {
            return [];
        }

        $timezone = config('app.display_timezone');

        $meetings = Meeting::query()
            ->where('status', MeetingStatus::Confirmed->value)
            ->whereNotNull('confirmed_starts_at')
            ->whereNotNull('confirmed_ends_at')
            ->when($exceptMeetingId !== null, fn (Builder $query) => $query->whereKeyNot($exceptMeetingId))
            ->whereBetween('confirmed_starts_at', [
                CarbonImmutable::parse($from, $timezone)->startOfDay()->utc(),
                CarbonImmutable::parse($to, $timezone)->endOfDay()->utc(),
            ])
            ->whereHas('group.members', fn (Builder $members) => $members->whereIn('users.id', $userIds))
            ->with(['group:id,name', 'group.members:users.id'])
            ->orderBy('confirmed_starts_at')
            ->get();

        $busy = [];

        foreach ($meetings as $meeting) {
            $start = CarbonImmutable::instance($meeting->confirmed_starts_at)->setTimezone($timezone);
            $end = CarbonImmutable::instance($meeting->confirmed_ends_at)->setTimezone($timezone);
            $day = $start->toDateString();
            $first = max(0, ($start->hour - AvailabilityDay::FirstHour) * 2 + intdiv($start->minute, 30));
            $endMinutes = $end->toDateString() === $day
                ? ($end->hour - AvailabilityDay::FirstHour) * 60 + $end->minute
                : AvailabilityDay::CellCount * 30;
            $last = min(AvailabilityDay::CellCount, (int) ceil($endMinutes / 30));
            $info = ['meetingId' => $meeting->id, 'title' => $meeting->title, 'group' => $meeting->group->name];

            foreach ($meeting->group->members as $member) {
                if (! in_array($member->id, $userIds, true)) {
                    continue;
                }

                for ($cell = $first; $cell < $last; $cell++) {
                    $busy[$member->id][$day][$cell] ??= $info;
                }
            }
        }

        return $busy;
    }
}
```

(If `group.members:users.id` fails because of the pivot select, load `group.members` without a column list.)

- [ ] **Step 4: `withoutBusy` and `availableCellsByMember`**

`app/Models/AvailabilityDay.php`:

```php
    /**
     * The cells with every busy index set to unavailable.
     *
     * @param  list<int>  $busyIndexes
     */
    public static function withoutBusy(string $cells, array $busyIndexes): string
    {
        foreach ($busyIndexes as $index) {
            $cells[$index] = '0';
        }

        return $cells;
    }
```

`app/Models/Meeting.php` (after `cellsByMember()`):

```php
    /**
     * Members' cells within the range, minus the cells taken by their other confirmed meetings.
     *
     * @return array<int, array<string, string>>
     */
    public function availableCellsByMember(): array
    {
        $cells = $this->cellsByMember();
        $busy = (new BusyCells)(array_keys($cells), $this->range_start->toDateString(), $this->range_end->toDateString(), $this->id);

        foreach ($cells as $userId => $days) {
            foreach ($days as $day => $value) {
                $cells[$userId][$day] = AvailabilityDay::withoutBusy($value, array_keys($busy[$userId][$day] ?? []));
            }
        }

        return $cells;
    }
```

- [ ] **Step 5: Summary and vote use it**

`Summary::windows()`: replace `$this->meeting->cellsByMember()` with `$this->meeting->availableCellsByMember()`.

`Vote::mount()`: after building `$grid`:

```php
        $busy = (new BusyCells)([Auth::id()], $meeting->range_start->toDateString(), $meeting->range_end->toDateString(), $meeting->id)[Auth::id()] ?? [];
        $grid = $grid->map(fn (string $cells, string $day): string => AvailabilityDay::withoutBusy($cells, array_keys($busy[$day] ?? [])));
```

- [ ] **Step 6: Run tests**

Run: `php artisan test --compact tests/Feature/Availability/BusyCellsTest.php` → PASS; `php artisan test --compact` → all green.

- [ ] **Step 7: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add app tests
git commit -m "Confirmed meetings count as unavailable for other requests"
```

---

### Task 2: Blocked « Réunion » cells in the grid

**Files:**
- Modify: `app/Livewire/Availability/Grid.php`, `resources/views/livewire/availability/grid.blade.php`, `resources/js/availability-grid.js`, `lang/fr.json`
- Test: `tests/Feature/Availability/BusyGridTest.php`

**Interfaces:**
- Consumes: `BusyCells` (Task 1).
- Produces: computed `Grid::busy(): array<string, array<int, array{title: string, group: string}>>` for the displayed weeks; root attribute `data-busy`; JS `isBusy(date, index): bool`, `busyTitle(date, index): ?string`.

- [ ] **Step 1: Write the failing tests**

`php artisan make:test --pest Availability/BusyGridTest --no-interaction`:

```php
<?php

use App\Livewire\Availability\Grid;
use App\Models\AvailabilityDay;
use App\Models\Group;
use App\Models\Meeting;
use App\Models\User;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

beforeEach(function () {
    $this->travelTo(now('Europe/Paris')->setDate(2026, 11, 2)->setTime(10, 0));
    $this->group = Group::factory()->create(['name' => 'Bureau']);
    $this->member = User::factory()->create();
    $this->group->addMember($this->member);
    $this->meeting = Meeting::factory()->for($this->group)
        ->confirmed(CarbonImmutable::parse('2026-11-05 17:00:00', 'UTC'))
        ->create(['title' => 'Conseil']);
});

test('the grid exposes the meeting cells to each member', function () {
    $busy = Livewire::actingAs($this->member)->test(Grid::class)->instance()->busy;

    expect(array_keys($busy['2026-11-05']))->toBe([20, 21, 22, 23])
        ->and($busy['2026-11-05'][20])->toBe(['title' => 'Conseil', 'group' => 'Bureau']);
});

test('changes under a meeting are refused, the rest of the day is accepted', function () {
    $underMeeting = str_repeat('0', 20).'p'.str_repeat('0', 7);
    $beside = 'pp'.str_repeat('0', 26);

    Livewire::actingAs($this->member)->test(Grid::class)
        ->call('saveDays', ['2026-11-05' => $underMeeting])
        ->assertHasErrors('days');

    Livewire::actingAs($this->member)->test(Grid::class)
        ->call('saveDays', ['2026-11-05' => $beside])
        ->assertHasNoErrors();

    expect(AvailabilityDay::where('user_id', $this->member->id)->value('cells'))->toBe($beside);
});

test('availability filled before the meeting is kept and can still be saved unchanged', function () {
    $evening = str_repeat('0', 20).'pppp0000';
    AvailabilityDay::factory()->for($this->member)->cells($evening)->create(['day' => '2026-11-05']);

    Livewire::actingAs($this->member)->test(Grid::class)
        ->call('saveDays', ['2026-11-05' => 'p'.substr($evening, 1)])
        ->assertHasNoErrors();
});

test('a deleted meeting frees its cells', function () {
    $this->meeting->delete();

    expect(Livewire::actingAs($this->member)->test(Grid::class)->instance()->busy)->toBe([]);
});

test('the busy cells reach the browser outside x-data', function () {
    $html = Livewire::actingAs($this->member)->test(Grid::class)->html();

    expect($html)->toContain('data-busy=')->toContain('Conseil');
});
```

- [ ] **Step 2: Run to verify they fail**

Run: `php artisan test --compact tests/Feature/Availability/BusyGridTest.php` → FAIL (`busy` missing).

- [ ] **Step 3: Grid component**

In `app/Livewire/Availability/Grid.php`:

```php
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
```

In `saveDays`, after the existing format/day validation loop and before the transaction:

```php
        $stored = AvailabilityDay::query()->where('user_id', Auth::id())->whereIn('day', array_keys($days))->pluck('cells', 'day');

        foreach ($days as $day => $cells) {
            $current = $stored[$day] ?? AvailabilityDay::Empty;

            foreach (array_keys($this->busy[$day] ?? []) as $index) {
                if ($cells[$index] !== $current[$index]) {
                    throw ValidationException::withMessages(['days' => __('This availability could not be saved.')]);
                }
            }
        }
```

Also `unset($this->busy)` is not needed after saving (busy does not depend on saved cells).

- [ ] **Step 4: View**

`resources/views/livewire/availability/grid.blade.php`:
- root element: add `data-busy="{{ json_encode($this->busy, JSON_FORCE_OBJECT) }}"` next to `data-cells`;
- labels passed to `availabilityGrid(...)`: add `'busy' => __('Meeting')`;
- legend: add `<span class="inline-flex items-center gap-1.5"><span class="size-4 rounded bg-sun"></span>{{ __('Meeting') }}</span>` after « Remote »;
- each cell button: `x-bind:disabled="!canEdit || !day.inRange || isBusy(day.date, {{ $cell }})"`, `x-bind:title="busyTitle(day.date, {{ $cell }})"`, and in `x-bind:class` add `'bg-sun': isBusy(day.date, {{ $cell }})` and append `&& !isBusy(day.date, {{ $cell }})` to the `bg-onsite`, `bg-remote`, `bg-white dark:bg-night` and `bg-zinc-100 dark:bg-white/5` conditions.

`lang/fr.json`: `"Meeting": "Réunion"` (if absent) and `"Meeting: :title (:group)": "Réunion : :title (:group)"`; pass the second through the labels too: `'busyTitle' => __('Meeting: :title (:group)')`.

- [ ] **Step 5: JS**

In `resources/js/availability-grid.js`:
- state `busy: {}`; in `init()`: `this.busy = JSON.parse(this.$el.dataset.busy || '{}');`
- methods:

```js
        isBusy(date, index) {
            return Boolean(this.busy[date]?.[index]);
        },

        busyTitle(date, index) {
            const meeting = this.busy[date]?.[index];

            return meeting ? this.labels.busyTitle.replace(':title', meeting.title).replace(':group', meeting.group) : null;
        },
```

- `label(day, index)`: when `isBusy`, return `${day.label} ${this.times[index]} — ${this.busyTitle(day.date, index)}`.
- `set(date, index, value)`: return early when `this.isBusy(date, index)`.
- `copyToWeek(date)` and `copyWeekToNext()`: build the target string cell by cell — keep the target's current value where the target **or** the source cell is busy, otherwise take the source value:

```js
        mergeInto(sourceDate, targetDate) {
            const source = this.cells[sourceDate] ?? EMPTY;
            const target = this.cells[targetDate] ?? EMPTY;

            return [...target].map((value, index) => (this.isBusy(targetDate, index) || this.isBusy(sourceDate, index) ? value : source[index])).join('');
        },
```

and use `this.mergeInto(day.date, target.date)` (resp. `this.mergeInto(date, day.date)`) where they currently copy the whole string.

- [ ] **Step 6: Run tests and build**

Run: `php artisan test --compact tests/Feature/Availability` → PASS; `npm run build && php artisan test --compact` → all green.

- [ ] **Step 7: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add app resources lang tests
git commit -m "Show confirmed meetings as blocked cells in the availability grid"
```
