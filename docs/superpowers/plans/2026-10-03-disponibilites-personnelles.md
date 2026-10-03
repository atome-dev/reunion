# Disponibilités personnelles — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make availability a personal, dated calendar of each registered user (independent of groups and meetings), reused by every meeting request.

**Architecture:** `availability_days` loses `meeting_id` and becomes unique per (`user_id`, `day`). Meetings compute their data on the fly from their current members' rows within `range_start..range_end`. The grid component moves to `App\Livewire\Availability\Grid`, usable alone (new page `/disponibilites`) or restricted to a meeting's range.

**Tech Stack:** Laravel 13, PHP 8.4, Livewire 4 (class components, views in `resources/views/livewire/<area>/<name>.blade.php`, no `render()` unless a dynamic title is needed), Flux Pro, Alpine, Pest 4, Tailwind v4, French UI via `lang/fr.json` (English keys).

**Spec:** `docs/superpowers/specs/2026-10-03-disponibilites-personnelles-design.md` (and, for everything not about storage, `docs/superpowers/specs/2026-10-03-disponibilites-design.md`).

## Global Constraints

- Grid: 28 half-hour cells from 8:00 to 22:00, chars `0` / `p` (on site) / `d` (remote), regex `^[0pd]{28}$`; an all-`0` day is never stored.
- Editable window: from today to today + 3 months (`addMonthsNoOverflow(3)`), both inclusive, computed in `config('app.display_timezone')` (Europe/Paris).
- A meeting's range must end no later than today + 3 months (checked on creation, and on edit only when the end changed); the 62-day limit stays.
- « A répondu » = at least one stored (non-empty) day within the meeting's range.
- Nobody reads another user's calendar; only a meeting's summary (organizer) reads the current members' rows within that meeting's range.
- Removing a member from a group never deletes their availability.
- Never run `migrate:fresh`, `migrate:refresh`, `db:wipe` or seeders on the local DB; `php artisan migrate` on the local DB only with the user's consent (controller handles it).
- Bash style: files are created/modified only with Write/Edit tools; no shell loops/variables; no `cd` prefix; `git --no-pager`.
- After PHP edits: `vendor/bin/pint --dirty --format agent`. After view/JS edits: `npm run build`.
- Commit messages end with `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`. No push.

## Review Focus

1. A user who belongs to no group opens `/disponibilites`, fills a day and reloads: it is still there (Task 2 test « a user without any group saves their availability »).
2. A day filled from a meeting of group A shows up in a meeting of group B covering the same date, and on `/disponibilites` (Task 2 test « availability is shared between meetings and the personal page »).
3. Boundaries in Paris time: at 00:30 Paris (22:30 UTC the previous day) today is editable, yesterday is not; today + 3 months is editable, the day after is not (Task 2 test « the editable window follows Paris time and stops at three months »).
4. A member removed from a group keeps their rows, and is no longer counted in that group's summary (Task 1 test « a former member keeps their availability but is not counted »).
5. A meeting whose range already started (editable since the earlier fix): its past days are shown but not editable (Task 2 test « past days of a meeting range are not editable »).

---

### Task 1: Personal storage

**Files:**
- Create: `database/migrations/2026_10_03_200000_make_availability_days_personal.php`
- Modify: `app/Models/AvailabilityDay.php`, `app/Models/User.php`, `app/Models/Meeting.php`, `app/Models/Group.php`, `app/Livewire/Meetings/Form.php`, `app/Livewire/Meetings/AvailabilityGrid.php`, `app/Livewire/Meetings/Vote.php`, `app/Livewire/Dashboard.php`, `database/factories/AvailabilityDayFactory.php`, `database/seeders/DemoSeeder.php`
- Modify tests that create `AvailabilityDay` with `->for($meeting)`: `tests/Feature/DashboardTest.php`, `tests/Feature/Meetings/{SummaryTest,MeetingFormTest,RemindersTest,FinalReviewFixesTest,AvailabilityDataTest,VoteTest,AvailabilityGridTest}.php`
- Test: `tests/Feature/Availability/PersonalStorageTest.php`

**Interfaces:**
- Produces: `AvailabilityDay::lastEditableDay(): CarbonImmutable` (today Paris + 3 months, start of day); `User::availabilityDays(): HasMany<AvailabilityDay>`; `Meeting::cellsByMember(): array<int, array<string, string>>` and `Meeting::respondentIds(): Collection<int,int>` now read personal rows in range; `Meeting::availabilityDays()` is REMOVED; `AvailabilityDay::meeting()` is REMOVED; factory `AvailabilityDay::factory()` has no `meeting_id` (default `day` = tomorrow in Paris).

- [ ] **Step 1: Write the failing tests**

`php artisan make:test --pest Availability/PersonalStorageTest --no-interaction`, then:

```php
<?php

use App\Models\AvailabilityDay;
use App\Models\Group;
use App\Models\Meeting;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    $this->travelTo(now('Europe/Paris')->setDate(2026, 11, 2)->setTime(10, 0));
});

test('the migration keeps the most recent row per user and day', function () {
    $migration = require database_path('migrations/2026_10_03_200000_make_availability_days_personal.php');
    $migration->down();

    $user = User::factory()->create();
    $other = User::factory()->create();
    $first = Meeting::factory()->create();
    $second = Meeting::factory()->create();

    DB::table('availability_days')->insert([
        ['meeting_id' => $first->id, 'user_id' => $user->id, 'day' => '2026-11-05', 'cells' => str_repeat('p', 28), 'created_at' => now(), 'updated_at' => now()->subDay()],
        ['meeting_id' => $second->id, 'user_id' => $user->id, 'day' => '2026-11-05', 'cells' => str_repeat('d', 28), 'created_at' => now(), 'updated_at' => now()],
        ['meeting_id' => $first->id, 'user_id' => $other->id, 'day' => '2026-11-05', 'cells' => str_repeat('p', 28), 'created_at' => now(), 'updated_at' => now()],
    ]);

    $migration->up();

    expect(Schema::hasColumn('availability_days', 'meeting_id'))->toBeFalse()
        ->and(AvailabilityDay::where('user_id', $user->id)->pluck('cells', 'day')->all())->toBe(['2026-11-05' => str_repeat('d', 28)])
        ->and(AvailabilityDay::where('user_id', $other->id)->count())->toBe(1);
});

test('a meeting reads its current members personal rows within its range', function () {
    $group = Group::factory()->create();
    $member = User::factory()->create();
    $group->addMember($member);
    $meeting = Meeting::factory()->for($group)->create(['range_start' => '2026-11-04', 'range_end' => '2026-11-06', 'deadline' => '2026-11-03']);

    AvailabilityDay::factory()->for($member)->cells(str_repeat('p', 28))->create(['day' => '2026-11-05']);
    AvailabilityDay::factory()->for($member)->cells(str_repeat('d', 28))->create(['day' => '2026-11-09']);

    expect($meeting->cellsByMember())->toBe([$member->id => ['2026-11-05' => str_repeat('p', 28)]])
        ->and($meeting->respondentIds()->all())->toBe([$member->id]);
});

test('a member with rows only outside the range has not answered', function () {
    $group = Group::factory()->create();
    $member = User::factory()->create();
    $group->addMember($member);
    $meeting = Meeting::factory()->for($group)->create(['range_start' => '2026-11-04', 'range_end' => '2026-11-06', 'deadline' => '2026-11-03']);

    AvailabilityDay::factory()->for($member)->cells(str_repeat('p', 28))->create(['day' => '2026-11-20']);

    expect($meeting->respondentIds()->all())->toBe([]);
});

test('a former member keeps their availability but is not counted', function () {
    $group = Group::factory()->create();
    $former = User::factory()->create();
    $group->addMember($former);
    $meeting = Meeting::factory()->for($group)->create(['range_start' => '2026-11-04', 'range_end' => '2026-11-06', 'deadline' => '2026-11-03']);
    AvailabilityDay::factory()->for($former)->cells(str_repeat('p', 28))->create(['day' => '2026-11-05']);

    $group->removeMember($former);

    expect(AvailabilityDay::where('user_id', $former->id)->count())->toBe(1)
        ->and($meeting->fresh()->cellsByMember())->toBe([]);
});

test('the last editable day is three months ahead in Paris time', function () {
    $this->travelTo(now('UTC')->setDate(2026, 11, 30)->setTime(23, 30));

    expect(AvailabilityDay::lastEditableDay()->toDateString())->toBe('2027-03-01');
});
```

(At 2026-11-30 23:30 UTC it is already 2026-12-01 00:30 in Paris, so + 3 months = 2027-03-01.)

- [ ] **Step 2: Run to verify they fail**

Run: `php artisan test --compact tests/Feature/Availability/PersonalStorageTest.php`
Expected: FAIL (migration file missing, `lastEditableDay` undefined).

- [ ] **Step 3: Migration**

`php artisan make:migration make_availability_days_personal --no-interaction`, then rename the file to `2026_10_03_200000_make_availability_days_personal.php` (the test requires this exact name) and write:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Availability becomes personal: one row per user and day, whatever the meeting.
     */
    public function up(): void
    {
        $seen = [];
        $duplicateIds = [];

        foreach (DB::table('availability_days')->orderByDesc('updated_at')->orderByDesc('id')->get(['id', 'user_id', 'day']) as $row) {
            $key = $row->user_id.'|'.$row->day;

            if (isset($seen[$key])) {
                $duplicateIds[] = $row->id;
            } else {
                $seen[$key] = true;
            }
        }

        foreach (array_chunk($duplicateIds, 500) as $ids) {
            DB::table('availability_days')->whereIn('id', $ids)->delete();
        }

        Schema::table('availability_days', function (Blueprint $table) {
            $table->dropForeign(['meeting_id']);
            $table->dropUnique(['meeting_id', 'user_id', 'day']);
        });

        Schema::table('availability_days', function (Blueprint $table) {
            $table->dropColumn('meeting_id');
            $table->unique(['user_id', 'day']);
        });
    }

    /**
     * Reverse the migrations (rows lose their meeting; acceptable).
     */
    public function down(): void
    {
        Schema::table('availability_days', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'day']);
        });

        Schema::table('availability_days', function (Blueprint $table) {
            $table->foreignId('meeting_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
            $table->unique(['meeting_id', 'user_id', 'day']);
        });
    }
};
```

If SQLite or MySQL rejects the order (e.g. MySQL needing an index for the `user_id` foreign key when dropping the unique in `down()`), adjust minimally and note it in the report. The test runs on SQLite; the controller verifies on the local MySQL DB.

- [ ] **Step 4: Models**

`app/Models/AvailabilityDay.php`: remove `meeting()` and `@property int $meeting_id`; change the class doc to « One user's availability for one day: … »; add:

```php
    /**
     * The last day a user may fill in: three months from today, in the display timezone.
     */
    public static function lastEditableDay(): CarbonImmutable
    {
        return CarbonImmutable::today(config('app.display_timezone'))->addMonthsNoOverflow(3);
    }
```

`app/Models/User.php`: add

```php
    /** @return HasMany<AvailabilityDay, $this> */
    public function availabilityDays(): HasMany
    {
        return $this->hasMany(AvailabilityDay::class);
    }
```

`app/Models/Meeting.php`: delete `availabilityDays()`; replace `cellsByMember()`:

```php
    public function cellsByMember(): array
    {
        $memberIds = $this->group->members()->pluck('users.id');

        return AvailabilityDay::query()
            ->whereIn('user_id', $memberIds)
            ->whereBetween('day', [$this->range_start->toDateString(), $this->range_end->toDateString()])
            ->where('cells', '!=', AvailabilityDay::Empty)
            ->orderBy('day')
            ->get()
            ->groupBy('user_id')
            ->map(fn (Collection $days): array => $days->mapWithKeys(fn (AvailabilityDay $day): array => [$day->day => $day->cells])->all())
            ->all();
    }
```

Update its docblock (« Personal cells of the group's current members within the range »). `respondentIds()` keeps calling `cellsByMember()`.

`app/Models/Group.php` `removeMember()`: delete the `AvailabilityDay::query()…->delete();` line (and the import if unused). Keep the slot votes deletion.

- [ ] **Step 5: Readers and writers**

`app/Livewire/Meetings/Form.php`: delete the block that removes availability days outside a shrunk range (`$meeting->availabilityDays()->where(…)->delete()`).

`app/Livewire/Meetings/AvailabilityGrid.php` (minimal, Task 2 replaces it): in `saveDays`, the query and `updateOrCreate` key become `['user_id' => Auth::id(), 'day' => $day]` (no `meeting_id`); `cells()` becomes:

```php
        return AvailabilityDay::query()
            ->where('user_id', Auth::id())
            ->whereBetween('day', [$this->meeting->range_start->toDateString(), $this->meeting->range_end->toDateString()])
            ->orderBy('day')
            ->pluck('cells', 'day')
            ->all();
```

`app/Livewire/Meetings/Vote.php` `mount()`: replace the `$grid = $meeting->availabilityDays()…` line with

```php
        $grid = AvailabilityDay::query()
            ->where('user_id', Auth::id())
            ->whereBetween('day', [$meeting->range_start->toDateString(), $meeting->range_end->toDateString()])
            ->pluck('cells', 'day');
```

`app/Livewire/Dashboard.php` `pendingMeetings()`: replace `->whereDoesntHave('availabilityDays', …)` with

```php
                    ->whereNotExists(fn (QueryBuilder $days) => $days
                        ->selectRaw('1')
                        ->from('availability_days')
                        ->where('availability_days.user_id', $userId)
                        ->whereColumn('availability_days.day', '>=', 'meetings.range_start')
                        ->whereColumn('availability_days.day', '<=', 'meetings.range_end')))
```

with `use Illuminate\Database\Query\Builder as QueryBuilder;`.

The reminder command uses `respondentIds()` and needs no change; check it with `RemindersTest`.

- [ ] **Step 6: Factory, seeder, existing tests**

`database/factories/AvailabilityDayFactory.php` definition:

```php
        return [
            'user_id' => User::factory(),
            'day' => fn () => now(config('app.display_timezone'))->addDay()->toDateString(),
            'cells' => AvailabilityDay::Empty,
        ];
```

(remove the `Meeting` import).

`database/seeders/DemoSeeder.php`: remove `$availability->meeting()->associate($meeting);`.

In every test listed under Files, remove `->for($meeting)` / `->for($this->meeting)` / `->for($answered)` (the meeting) from `AvailabilityDay::factory()` chains, keeping `->for($user)` and an explicit `day` inside the meeting's range. Where a test creates a row without `day` (e.g. `AvailabilityDataTest.php:58`, `DashboardTest.php:58`), pass `['day' => <a day of that meeting's range>]`. Delete or rewrite assertions that rely on per-meeting rows:
- `MeetingFormTest` « shrinking the range deletes days outside it » → assert rows are kept;
- `AvailabilityDataTest` « removing a member deletes their availability » → assert they are kept;
- any test reading `$meeting->availabilityDays` → read `AvailabilityDay::where('user_id', …)`.

- [ ] **Step 7: Run tests**

Run: `php artisan test --compact tests/Feature/Availability/PersonalStorageTest.php` → PASS.
Run: `php artisan test --compact` → all green.

- [ ] **Step 8: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add -A app database tests
git commit -m "Store availability per user and day, independent of meetings"
```

---

### Task 2: Personal availability page and shared grid component

**Files:**
- Create: `app/Livewire/Availability/Grid.php`, `app/Livewire/Availability/Edit.php`, `resources/views/livewire/availability/edit.blade.php`
- Move: `resources/views/livewire/meetings/availability-grid.blade.php` → `resources/views/livewire/availability/grid.blade.php` (`git mv`)
- Delete: `app/Livewire/Meetings/AvailabilityGrid.php`
- Move: `tests/Feature/Meetings/AvailabilityGridTest.php` → `tests/Feature/Availability/GridTest.php` (`git mv`, then update class references)
- Modify: `routes/web.php`, `resources/views/layouts/app/sidebar.blade.php`, `resources/views/livewire/meetings/show.blade.php`, `app/Livewire/Dashboard.php`, `resources/views/livewire/dashboard.blade.php`, `tests/Feature/Meetings/FinalReviewFixesTest.php` (class references), `lang/fr.json`
- Test: `tests/Feature/Availability/PersonalPageTest.php`

**Interfaces:**
- Consumes: `AvailabilityDay::lastEditableDay()`, personal `availability_days` (Task 1), `MeetingPolicy::view` / `editAvailability`.
- Produces: `<livewire:availability.grid />` and `<livewire:availability.grid :meeting="$meeting" />`; `Grid::saveDays(array $days): bool`, computed `canEdit`, `cells`, `weeks` (each day `array{date: string, label: string, inRange: bool}` where `inRange` means « editable »), `respondentCount`, `memberCount`; event `availability-saved` (unchanged, Summary listens to it); route `availability.edit` (GET `/disponibilites`); `Dashboard::filledDayCount(): int`.

- [ ] **Step 1: Write the failing tests**

`php artisan make:test --pest Availability/PersonalPageTest --no-interaction`:

```php
<?php

use App\Livewire\Availability\Grid;
use App\Models\AvailabilityDay;
use App\Models\Group;
use App\Models\Meeting;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->travelTo(now('Europe/Paris')->setDate(2026, 11, 2)->setTime(10, 0));
    $this->user = User::factory()->create();
    $this->evening = str_repeat('0', 20).'pppp0000';
});

test('guests are sent to the login page', function () {
    $this->get(route('availability.edit'))->assertRedirect(route('login'));
});

test('a user without any group saves their availability', function () {
    $this->actingAs($this->user)->get(route('availability.edit'))->assertOk()->assertSeeLivewire(Grid::class);

    Livewire::actingAs($this->user)->test(Grid::class)
        ->call('saveDays', ['2026-11-05' => $this->evening])
        ->assertHasNoErrors()
        ->assertDispatched('availability-saved');

    expect(Livewire::actingAs($this->user)->test(Grid::class)->instance()->cells)->toBe(['2026-11-05' => $this->evening]);
});

test('the editable window follows Paris time and stops at three months', function (string $day, bool $accepted) {
    $this->travelTo(now('UTC')->setDate(2026, 11, 1)->setTime(23, 30)); // 2026-11-02 00:30 in Paris

    $component = Livewire::actingAs($this->user)->test(Grid::class)->call('saveDays', [$day => $this->evening]);

    $accepted ? $component->assertHasNoErrors() : $component->assertHasErrors('days');
})->with([
    'today' => ['2026-11-02', true],
    'yesterday' => ['2026-11-01', false],
    'three months ahead' => ['2027-02-02', true],
    'the day after' => ['2027-02-03', false],
]);

test('malformed cells are refused and nothing is written for anyone else', function () {
    $other = User::factory()->create();

    Livewire::actingAs($this->user)->test(Grid::class)
        ->call('saveDays', ['2026-11-05' => 'xx'])
        ->assertHasErrors('days');

    Livewire::actingAs($this->user)->test(Grid::class)->call('saveDays', ['2026-11-05' => $this->evening]);

    expect(AvailabilityDay::where('user_id', $other->id)->count())->toBe(0)
        ->and(AvailabilityDay::where('user_id', $this->user->id)->count())->toBe(1);
});

test('an empty day is removed', function () {
    AvailabilityDay::factory()->for($this->user)->cells($this->evening)->create(['day' => '2026-11-05']);

    Livewire::actingAs($this->user)->test(Grid::class)->call('saveDays', ['2026-11-05' => AvailabilityDay::Empty]);

    expect(AvailabilityDay::count())->toBe(0);
});

test('availability is shared between meetings and the personal page', function () {
    $groupA = Group::factory()->create();
    $groupB = Group::factory()->create();
    $groupA->addMember($this->user);
    $groupB->addMember($this->user);
    $meetingA = Meeting::factory()->for($groupA)->create(['range_start' => '2026-11-04', 'range_end' => '2026-11-10', 'deadline' => '2026-11-03']);
    $meetingB = Meeting::factory()->for($groupB)->create(['range_start' => '2026-11-05', 'range_end' => '2026-11-06', 'deadline' => '2026-11-04']);

    Livewire::actingAs($this->user)->test(Grid::class, ['meeting' => $meetingA])
        ->call('saveDays', ['2026-11-05' => $this->evening])
        ->assertHasNoErrors();

    expect(Livewire::actingAs($this->user)->test(Grid::class, ['meeting' => $meetingB])->instance()->cells)->toBe(['2026-11-05' => $this->evening])
        ->and(Livewire::actingAs($this->user)->test(Grid::class)->instance()->cells)->toBe(['2026-11-05' => $this->evening]);
});

test('a meeting grid refuses days outside the meeting range', function () {
    $group = Group::factory()->create();
    $group->addMember($this->user);
    $meeting = Meeting::factory()->for($group)->create(['range_start' => '2026-11-04', 'range_end' => '2026-11-10', 'deadline' => '2026-11-03']);

    Livewire::actingAs($this->user)->test(Grid::class, ['meeting' => $meeting])
        ->call('saveDays', ['2026-11-20' => $this->evening])
        ->assertHasErrors('days');
});

test('past days of a meeting range are not editable', function () {
    $group = Group::factory()->create();
    $group->addMember($this->user);
    $meeting = Meeting::factory()->for($group)->create(['range_start' => '2026-10-30', 'range_end' => '2026-11-05', 'deadline' => '2026-10-29']);

    $component = Livewire::actingAs($this->user)->test(Grid::class, ['meeting' => $meeting]);
    $days = collect($component->instance()->weeks)->flatten(1)->keyBy('date');

    expect($days['2026-11-01']['inRange'])->toBeFalse()
        ->and($days['2026-11-02']['inRange'])->toBeTrue();

    $component->call('saveDays', ['2026-11-01' => $this->evening])->assertHasErrors('days');
});

test('the personal grid starts on the monday of the current week and ends three months ahead', function () {
    $days = collect(Livewire::actingAs($this->user)->test(Grid::class)->instance()->weeks)->flatten(1);

    expect($days->first()['date'])->toBe('2026-11-02')
        ->and($days->firstWhere('date', '2027-02-02')['inRange'])->toBeTrue()
        ->and($days->last()['date'])->toBe('2027-02-07');
});

test('the dashboard links to the personal page with the number of filled days', function () {
    AvailabilityDay::factory()->for($this->user)->cells($this->evening)->create(['day' => '2026-11-05']);
    AvailabilityDay::factory()->for($this->user)->cells($this->evening)->create(['day' => '2026-11-06']);

    $this->actingAs($this->user)->get(route('dashboard'))
        ->assertSee(route('availability.edit'))
        ->assertSee(trans_choice(':count day filled in over the next 3 months|:count days filled in over the next 3 months', 2));
});

test('the meeting page uses the shared grid and links to the whole calendar', function () {
    $group = Group::factory()->create();
    $group->addMember($this->user);
    $meeting = Meeting::factory()->for($group)->create(['range_start' => '2026-11-04', 'range_end' => '2026-11-10', 'deadline' => '2026-11-03']);

    $this->actingAs($this->user)->get(route('meetings.show', $meeting))
        ->assertSeeLivewire(Grid::class)
        ->assertSee(__('See my whole calendar'));
});
```

Also `git mv tests/Feature/Meetings/AvailabilityGridTest.php tests/Feature/Availability/GridTest.php` and replace `App\Livewire\Meetings\AvailabilityGrid` by `App\Livewire\Availability\Grid` there and in `FinalReviewFixesTest.php`; their existing meeting-scoped assertions (authorization 404/403, retry, error display, `true` return) must keep passing.

- [ ] **Step 2: Run to verify they fail**

Run: `php artisan test --compact tests/Feature/Availability/PersonalPageTest.php`
Expected: FAIL (route `availability.edit` and class `App\Livewire\Availability\Grid` missing).

- [ ] **Step 3: Grid component**

`php artisan make:livewire Availability/Grid --no-interaction` (then delete any generated `render()` and view stub you don't need), write `app/Livewire/Availability/Grid.php`:

```php
<?php

namespace App\Livewire\Availability;

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

        return AvailabilityDay::query()
            ->where('user_id', Auth::id())
            ->whereBetween('day', [$first->toDateString(), $last->toDateString()])
            ->orderBy('day')
            ->pluck('cells', 'day')
            ->all();
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
```

`git mv resources/views/livewire/meetings/availability-grid.blade.php resources/views/livewire/availability/grid.blade.php`; in it, wrap the « :count people have answered … out of :total » line in `@if ($meeting)` … `@endif` (keep the save status span outside the condition). No JS change: `inRange` keeps its role (editable). Delete `app/Livewire/Meetings/AvailabilityGrid.php`.

- [ ] **Step 4: Page, route, menu**

`php artisan make:livewire Availability/Edit --no-interaction`; class:

```php
<?php

namespace App\Livewire\Availability;

use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('My availability')]
class Edit extends Component
{
}
```

(if the generator adds a `render()`, remove it — the view is resolved by convention like `Dashboard`). View `resources/views/livewire/availability/edit.blade.php`:

```blade
<div class="space-y-6">
    <div>
        <flux:heading size="xl" level="1">{{ __('My availability') }}</flux:heading>
        <flux:text class="mt-2 max-w-2xl">{{ __('Your availability is used by every request of your groups. The organizer of a request sees, in its summary, who is available during its period.') }}</flux:text>
    </div>

    <livewire:availability.grid />
</div>
```

`routes/web.php`, inside the `['auth', 'verified']` group, after the dashboard route:

```php
    Route::livewire('disponibilites', AvailabilityEdit::class)->name('availability.edit');
```

with `use App\Livewire\Availability\Edit as AvailabilityEdit;`.

`resources/views/layouts/app/sidebar.blade.php`: after the dashboard `flux:sidebar.item`, add the same markup with `icon="calendar-days"`, `:href="route('availability.edit')"`, `:current="request()->routeIs('availability.edit')"`, label `{{ __('My availability') }}`.

- [ ] **Step 5: Meeting page and dashboard**

`resources/views/livewire/meetings/show.blade.php`: replace `<livewire:meetings.availability-grid :meeting="$meeting" :key="'grid-'.$meeting->id" />` with `<livewire:availability.grid :meeting="$meeting" :key="'grid-'.$meeting->id" />`, and next to the « My availability » heading add `<flux:link :href="route('availability.edit')" wire:navigate class="text-sm">{{ __('See my whole calendar') }}</flux:link>` (heading and link in a `flex items-baseline justify-between` row).

`app/Livewire/Dashboard.php`:

```php
    /**
     * Days I filled in between today and the last editable day.
     */
    #[Computed]
    public function filledDayCount(): int
    {
        return AvailabilityDay::query()
            ->where('user_id', Auth::id())
            ->whereBetween('day', [now(config('app.display_timezone'))->toDateString(), AvailabilityDay::lastEditableDay()->toDateString()])
            ->count();
    }
```

`resources/views/livewire/dashboard.blade.php`: at the top of the content, a card:

```blade
<flux:card class="flex flex-wrap items-center justify-between gap-4">
    <div>
        <flux:heading size="lg" level="2">{{ __('My availability') }}</flux:heading>
        <flux:text class="mt-1">
            {{ $this->filledDayCount > 0
                ? trans_choice(':count day filled in over the next 3 months|:count days filled in over the next 3 months', $this->filledDayCount)
                : __('No availability filled in yet') }}
        </flux:text>
    </div>
    <flux:button variant="primary" icon="calendar-days" :href="route('availability.edit')" wire:navigate>{{ __('Fill in my availability') }}</flux:button>
</flux:card>
```

Match the dashboard's existing spacing/card conventions.

`lang/fr.json` (alphabetical position, check none exists already):

```json
":count day filled in over the next 3 months|:count days filled in over the next 3 months": ":count jour renseigné sur les 3 prochains mois|:count jours renseignés sur les 3 prochains mois",
"Fill in my availability": "Renseigner mes disponibilités",
"No availability filled in yet": "Aucune disponibilité renseignée",
"See my whole calendar": "Voir tout mon calendrier",
"Your availability is used by every request of your groups. The organizer of a request sees, in its summary, who is available during its period.": "Vos disponibilités servent à toutes les demandes de vos groupes. L'organisateur d'une demande voit, dans son résumé, qui est disponible sur sa période."
```

- [ ] **Step 6: Run tests and build**

Run: `php artisan test --compact tests/Feature/Availability` → PASS.
Run: `npm run build && php artisan test --compact` → all green.

- [ ] **Step 7: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add -A app resources routes lang tests
git commit -m "Personal availability page shared with meeting requests"
```

---

### Task 3: Three-month limit on requests and copy

**Files:**
- Modify: `app/Livewire/Meetings/Form.php`, `resources/views/livewire/meetings/form.blade.php`, `resources/views/components/how-it-works.blade.php`, `lang/fr.json`
- Test: `tests/Feature/Meetings/MeetingFormTest.php` (additions)

**Interfaces:**
- Consumes: `AvailabilityDay::lastEditableDay()` (Task 1).

- [ ] **Step 1: Write the failing tests** (append to `MeetingFormTest.php`, reusing its `beforeEach` users/group; set the clock explicitly)

```php
test('a request cannot end more than three months ahead', function () {
    $this->travelTo(now('Europe/Paris')->setDate(2026, 11, 2)->setTime(10, 0));

    Livewire::actingAs($this->owner)->test(Form::class, ['group' => $this->group])
        ->set('title', 'Assemblée')
        ->set('rangeStart', '2027-01-20')
        ->set('rangeEnd', '2027-02-03')
        ->set('deadline', '2027-01-15')
        ->call('save')
        ->assertHasErrors('rangeEnd');

    Livewire::actingAs($this->owner)->test(Form::class, ['group' => $this->group])
        ->set('title', 'Assemblée')
        ->set('rangeStart', '2027-01-20')
        ->set('rangeEnd', '2027-02-02')
        ->set('deadline', '2027-01-15')
        ->call('save')
        ->assertHasNoErrors();
});

test('an unchanged end is not checked again when editing', function () {
    $this->travelTo(now('Europe/Paris')->setDate(2026, 11, 2)->setTime(10, 0));
    $meeting = Meeting::factory()->for($this->group)->create(['created_by' => $this->owner->id, 'range_start' => '2027-01-20', 'range_end' => '2027-02-02', 'deadline' => '2027-01-15']);
    $this->travelTo(now('Europe/Paris')->setDate(2026, 10, 25)->setTime(10, 0));

    Livewire::actingAs($this->owner)->test(Form::class, ['meeting' => $meeting])
        ->set('title', 'Nouveau titre')
        ->call('save')
        ->assertHasNoErrors();
});
```

Adapt variable names (`$this->owner`, `$this->group`, the save method name, the `created_by` attribute) to those actually used in `MeetingFormTest.php` and `Form.php`.

- [ ] **Step 2: Run to verify the first fails**

Run: `php artisan test --compact tests/Feature/Meetings/MeetingFormTest.php --filter="three months|unchanged end"`
Expected: the first test FAILS (no error on `rangeEnd`).

- [ ] **Step 3: Implement**

In `Form.php`, next to `$startChanged` / `$deadlineChanged`:

```php
        $endChanged = ! $this->meeting?->exists || $this->meeting->range_end->toDateString() !== $this->rangeEnd;
```

and the rule becomes

```php
            'rangeEnd' => ['required', 'date_format:Y-m-d', 'after_or_equal:rangeStart', ...($endChanged ? ['before_or_equal:'.AvailabilityDay::lastEditableDay()->toDateString()] : [])],
```

with the custom message `'rangeEnd.before_or_equal' => __('The request must end within three months.')` (add a messages array to the `validate()` call if there is none). In `form.blade.php`, give the end date picker `:max="\App\Models\AvailabilityDay::lastEditableDay()->toDateString()"` (or the prop the start picker uses for `min`).

`lang/fr.json`: `"The request must end within three months.": "La demande doit se terminer dans les trois mois."`

`resources/views/components/how-it-works.blade.php` step 2 text: `'Une fois pour toutes, sur place ou à distance, du bout du doigt.'` (title unchanged, so `HomePageTest` stays green).

- [ ] **Step 4: Run tests and build**

Run: `php artisan test --compact tests/Feature/Meetings/MeetingFormTest.php` → PASS.
Run: `npm run build && php artisan test --compact` → all green.

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add app resources lang tests
git commit -m "Limit requests to the three-month availability window"
```
