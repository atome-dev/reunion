# Refuser une réunion confirmée — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let a member decline a confirmed meeting, which frees that slot in their own availability, shows them as « Ne viendront pas » on the meeting page and on their dashboard.

**Architecture:** A `meeting_declines` table (meeting, user, unique). `MeetingPolicy::decline` gates it; `Meetings\Show` gets `decline`/`undoDecline`; `BusyCells` skips users who declined a meeting; dashboard shows a badge.

**Tech Stack:** Laravel 13, PHP 8.4, Livewire 4, Flux Pro, Pest 4, French UI via `lang/fr.json`.

**Spec:** `docs/superpowers/specs/2026-10-04-refus-reunion-design.md`

## Global Constraints

- Only `confirmed` meetings can be declined; any current member may decline (incl. group creator and author); non-member → 404, member on a non-confirmed meeting → 403.
- One decline per (meeting, user) — unique index; clicking twice keeps one row.
- A decline frees the slot only for that user (BusyCells), the meeting stays confirmed for others.
- Declines are deleted with the meeting (FK cascade) and when the member leaves/is removed from the group (`Group::removeMember`).
- No email. Copy (fr): « Je ne viendrai pas », « Finalement, je viendrai », « Vous avez indiqué que vous ne viendrez pas. », « Ne viendront pas : :names », « Vous ne venez pas », confirmation « Vous ne viendrez pas à cette réunion ? Le créneau sera libéré dans vos disponibilités. »
- Never run migrate:fresh/refresh, db:wipe or seeders on the local DB (the controller migrates it).
- Bash: files only via Write/Edit; no shell loops/variables; no `cd` prefix; `git --no-pager`.
- `vendor/bin/pint --dirty --format agent` after PHP edits; `npm run build` after view edits.
- Commits end with `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`. No push.

## Review Focus

1. Declining frees the slot for the decliner only; other members stay blocked (Task 1 test « a decline frees only the decliner »).
2. Undoing a decline blocks the slot again (Task 1 test « undoing blocks again »).
3. A member who leaves the group loses their decline; rejoining does not resurrect it (Task 1 test « leaving clears declines »).
4. Declining a meeting still collecting/voting is refused with 403 (Task 2 test « only confirmed meetings can be declined »).
5. Double click keeps a single decline (Task 2 test « declining twice keeps one decline »).

---

### Task 1: Decline storage, policy and busy cells

**Files:**
- Create: migration `create_meeting_declines_table`, `app/Models/MeetingDecline.php`, `database/factories/MeetingDeclineFactory.php`
- Modify: `app/Models/Meeting.php`, `app/Models/Group.php`, `app/Policies/MeetingPolicy.php`, `app/Actions/Availability/BusyCells.php`
- Test: `tests/Feature/Meetings/DeclineStorageTest.php`

**Interfaces:**
- Produces: `MeetingDecline` (fillable none; relations `meeting()`, `user()`); `Meeting::declines(): HasMany<MeetingDecline>`, `Meeting::decliners(): BelongsToMany<User>` (table `meeting_declines`, timestamps), `Meeting::isDeclinedBy(User $user): bool`; `MeetingPolicy::decline(User $user, Meeting $meeting): Response`.

- [ ] **Step 1: Write the failing tests**

`php artisan make:test --pest Meetings/DeclineStorageTest --no-interaction`:

```php
<?php

use App\Actions\Availability\BusyCells;
use App\Enums\MeetingStatus;
use App\Models\Group;
use App\Models\Meeting;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Gate;

beforeEach(function () {
    $this->travelTo(now('Europe/Paris')->setDate(2026, 11, 2)->setTime(10, 0));
    $this->group = Group::factory()->create();
    $this->member = User::factory()->create();
    $this->other = User::factory()->create();
    $this->group->addMember($this->member);
    $this->group->addMember($this->other);
    $this->meeting = Meeting::factory()->for($this->group)
        ->confirmed(CarbonImmutable::parse('2026-11-05 17:00:00', 'UTC'))
        ->create();
});

test('a decline frees only the decliner', function () {
    $this->meeting->decliners()->attach($this->member->id);

    $busy = (new BusyCells)([$this->member->id, $this->other->id], '2026-11-05', '2026-11-05');

    expect($busy)->not->toHaveKey($this->member->id)
        ->and(array_keys($busy[$this->other->id]['2026-11-05']))->toBe([20, 21, 22, 23])
        ->and($this->meeting->isDeclinedBy($this->member))->toBeTrue();
});

test('undoing blocks again', function () {
    $this->meeting->decliners()->attach($this->member->id);
    $this->meeting->decliners()->detach($this->member->id);

    expect((new BusyCells)([$this->member->id], '2026-11-05', '2026-11-05'))->toHaveKey($this->member->id);
});

test('leaving clears declines', function () {
    $this->meeting->decliners()->attach($this->member->id);

    $this->group->removeMember($this->member);
    $this->group->addMember($this->member);

    expect($this->meeting->fresh()->isDeclinedBy($this->member))->toBeFalse();
});

test('deleting the meeting deletes its declines', function () {
    $this->meeting->decliners()->attach($this->member->id);
    $this->meeting->delete();

    expect(\App\Models\MeetingDecline::count())->toBe(0);
});

test('only members may decline, and only a confirmed meeting', function () {
    expect(Gate::forUser($this->member)->inspect('decline', $this->meeting)->allowed())->toBeTrue()
        ->and(Gate::forUser(User::factory()->create())->inspect('decline', $this->meeting)->status())->toBe(404);

    $this->meeting->forceFill(['status' => MeetingStatus::Voting])->save();

    expect(Gate::forUser($this->member)->inspect('decline', $this->meeting->fresh())->status())->toBe(403);
});
```

- [ ] **Step 2: Run to verify they fail**

Run: `php artisan test --compact tests/Feature/Meetings/DeclineStorageTest.php` → FAIL (no `decliners`).

- [ ] **Step 3: Migration and model**

`php artisan make:migration create_meeting_declines_table --no-interaction`:

```php
        Schema::create('meeting_declines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('meeting_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['meeting_id', 'user_id']);
        });
```

`php artisan make:model MeetingDecline --factory --no-interaction`; model with `meeting(): BelongsTo` and `user(): BelongsTo` (follow `SlotVote` style); factory definition `['meeting_id' => Meeting::factory()->confirmed(), 'user_id' => User::factory()]`.

`Meeting.php`:

```php
    /** @return HasMany<MeetingDecline, $this> */
    public function declines(): HasMany
    {
        return $this->hasMany(MeetingDecline::class);
    }

    /** @return BelongsToMany<User, $this> */
    public function decliners(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'meeting_declines')->withTimestamps();
    }

    public function isDeclinedBy(User $user): bool
    {
        return $this->declines()->where('user_id', $user->id)->exists();
    }
```

`Group::removeMember`, inside the transaction before `detach`:

```php
            MeetingDecline::query()->where('user_id', $user->id)->whereIn('meeting_id', $meetingIds)->delete();
```

- [ ] **Step 4: Policy**

`MeetingPolicy`:

```php
    /**
     * Say one will not come to a confirmed meeting, or take it back.
     */
    public function decline(User $user, Meeting $meeting): Response
    {
        if (! $meeting->group->hasMember($user)) {
            return Response::denyAsNotFound();
        }

        return $meeting->status === MeetingStatus::Confirmed ? Response::allow() : Response::denyWithStatus(403);
    }
```

- [ ] **Step 5: BusyCells skips decliners**

In `BusyCells::__invoke`, eager-load declines: `->with(['group:id,name', 'group.members:users.id', 'declines:id,meeting_id,user_id'])`, and in the meeting loop:

```php
            $declinedBy = $meeting->declines->pluck('user_id')->all();

            foreach ($meeting->group->members as $member) {
                if (! in_array($member->id, $userIds, true) || in_array($member->id, $declinedBy, true)) {
                    continue;
                }
```

- [ ] **Step 6: Run tests, commit**

Run: `php artisan test --compact tests/Feature/Meetings/DeclineStorageTest.php` → PASS; `php artisan test --compact` → green.

```bash
vendor/bin/pint --dirty --format agent
git add app database tests
git commit -m "Store meeting declines and free declined slots"
```

---

### Task 2: Decline buttons, decliners list and dashboard badge

**Files:**
- Modify: `app/Livewire/Meetings/Show.php`, `resources/views/livewire/meetings/show.blade.php`, `app/Livewire/Dashboard.php`, `resources/views/livewire/dashboard.blade.php`, `lang/fr.json`
- Test: `tests/Feature/Meetings/DeclineMeetingTest.php`

**Interfaces:**
- Consumes (Task 1): `Meeting::decliners()`, `isDeclinedBy()`, `MeetingPolicy::decline`.
- Produces: `Meetings\Show::decline(): void`, `undoDecline(): void`, computed `hasDeclined: bool`, `decliners: Collection<int, User>`; dashboard meetings carry `declined_by_me` (bool).

- [ ] **Step 1: Write the failing tests**

`php artisan make:test --pest Meetings/DeclineMeetingTest --no-interaction`:

```php
<?php

use App\Livewire\Meetings\Show;
use App\Models\Group;
use App\Models\Meeting;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->travelTo(now('Europe/Paris')->setDate(2026, 11, 2)->setTime(10, 0));
    $this->group = Group::factory()->create();
    $this->member = User::factory()->create(['name' => 'Amina']);
    $this->group->addMember($this->member);
    $this->meeting = Meeting::factory()->for($this->group)->confirmed()->create();
});

test('a member declines then comes back', function () {
    Livewire::actingAs($this->member)->test(Show::class, ['meeting' => $this->meeting])
        ->assertSee(__("I won't come"))
        ->call('decline')
        ->assertSee(__("You said you won't come."))
        ->assertSee(__("Not coming: :names", ['names' => 'Amina']))
        ->call('undoDecline')
        ->assertSee(__("I won't come"));

    expect($this->meeting->fresh()->isDeclinedBy($this->member))->toBeFalse();
});

test('declining twice keeps one decline', function () {
    Livewire::actingAs($this->member)->test(Show::class, ['meeting' => $this->meeting])->call('decline')->call('decline');

    expect($this->meeting->declines()->count())->toBe(1);
});

test('only confirmed meetings can be declined', function () {
    $collecting = Meeting::factory()->for($this->group)->create(['range_start' => '2026-11-04', 'range_end' => '2026-11-10', 'deadline' => '2026-11-03']);

    Livewire::actingAs($this->member)->test(Show::class, ['meeting' => $collecting])
        ->assertDontSee(__("I won't come"))
        ->call('decline')
        ->assertForbidden();
});

test('the dashboard flags a declined meeting', function () {
    $this->meeting->decliners()->attach($this->member->id);

    $this->actingAs($this->member)->get(route('dashboard'))->assertSee(__("You're not coming"));
});
```

- [ ] **Step 2: Run to verify they fail**

Run: `php artisan test --compact tests/Feature/Meetings/DeclineMeetingTest.php` → FAIL.

- [ ] **Step 3: Component**

`Meetings\Show`:

```php
    public function decline(): void
    {
        $this->authorize('decline', $this->meeting);

        $this->meeting->decliners()->syncWithoutDetaching([Auth::id()]);
        unset($this->hasDeclined, $this->decliners);
    }

    public function undoDecline(): void
    {
        $this->authorize('decline', $this->meeting);

        $this->meeting->decliners()->detach(Auth::id());
        unset($this->hasDeclined, $this->decliners);
    }

    #[Computed]
    public function hasDeclined(): bool
    {
        return $this->meeting->isDeclinedBy(Auth::user());
    }

    /**
     * @return Collection<int, User>
     */
    #[Computed]
    public function decliners(): Collection
    {
        return $this->meeting->decliners()->orderBy('name')->get();
    }
```

(`syncWithoutDetaching` keeps one row even under a double click; the unique index guards a race.)

- [ ] **Step 4: Views**

`meetings/show.blade.php`, in the confirmed « Date retenue » card, after the calendar buttons:

```blade
@if ($this->hasDeclined)
    <div class="flex flex-wrap items-center gap-3">
        <flux:text>{{ __("You said you won't come.") }}</flux:text>
        <flux:button size="sm" wire:click="undoDecline">{{ __('Actually, I will come') }}</flux:button>
    </div>
@else
    <div><flux:button size="sm" variant="ghost" icon="x-mark" wire:click="decline" wire:confirm="{{ __("You won't come to this meeting? The slot will be freed in your availability.") }}">{{ __("I won't come") }}</flux:button></div>
@endif
```

after the card:

```blade
@if ($this->decliners->isNotEmpty())
    <flux:text>{{ __('Not coming: :names', ['names' => $this->decliners->pluck('name')->join(', ')]) }}</flux:text>
@endif
```

`Dashboard::confirmedMeetings()`: add `->withExists(['declines as declined_by_me' => fn (Builder $declines) => $declines->where('user_id', Auth::id())])`. In `dashboard.blade.php`, next to the date badge: `@if ($meeting->declined_by_me) <flux:badge color="zinc">{{ __("You're not coming") }}</flux:badge> @endif`.

`lang/fr.json` (alphabetical):
`"Actually, I will come": "Finalement, je viendrai"`, `"I won't come": "Je ne viendrai pas"`, `"Not coming: :names": "Ne viendront pas : :names"`, `"You said you won't come.": "Vous avez indiqué que vous ne viendrez pas."`, `"You won't come to this meeting? The slot will be freed in your availability.": "Vous ne viendrez pas à cette réunion ? Le créneau sera libéré dans vos disponibilités."`, `"You're not coming": "Vous ne venez pas"`.

- [ ] **Step 5: Run tests and build**

Run: `php artisan test --compact tests/Feature/Meetings/DeclineMeetingTest.php` → PASS; `npm run build && php artisan test --compact` → green.

- [ ] **Step 6: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add app resources lang tests
git commit -m "Decline a confirmed meeting from its page"
```
