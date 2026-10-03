# Réglages du groupe — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let a group's creator open three actions (request a meeting, invite members, validate a date) to all members, with meeting authors able to edit/delete their own requests.

**Architecture:** Three boolean columns on `groups`; one policy ability per action (`GroupPolicy::update/requestMeeting/invite`, `MeetingPolicy::update/validate`) replacing the catch-all `manage`; components and views switch from `isOrganizer()` checks to these abilities.

**Tech Stack:** Laravel 13, PHP 8.4, Livewire 4 (class components), Flux Pro, Pest 4, French UI via `lang/fr.json` (English keys).

**Spec:** `docs/superpowers/specs/2026-10-03-reglages-groupe-design.md`

## Global Constraints

- Settings: `members_can_request_meetings`, `members_can_invite`, `members_can_validate`, booleans, not null, default `false` (= reserved to the group creator).
- Group creator = `groups.owner_id`; meeting author = `meetings.created_by`.
- Non-members always get 404; members without the right get 403.
- Rename / delete group / remove member / change settings: group creator only, whatever the settings.
- Edit (only while collecting) / delete a meeting: its author or the group creator, whatever the settings.
- Validate = summary (view + actions), confirm a window, open the vote, confirm a voted slot, cancel the vote.
- UI label for the creator: « Créateur du groupe ».
- Never run migrate:fresh/refresh, db:wipe or seeders on the local DB (the controller migrates the local DB).
- Bash: files only via Write/Edit tools; no shell loops/variables; no `cd` prefix; `git --no-pager`.
- `vendor/bin/pint --dirty --format agent` after PHP edits; `npm run build` after view edits.
- Commits end with `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`. No push.

## Review Focus

1. A member who authored a request, in a group where validation is reserved to the creator, can edit/delete it but cannot see the summary nor confirm (Task 1 test « the author edits but does not validate »).
2. A member with every setting open still cannot rename, delete the group or remove a member (Task 1 test « settings never open group administration »).
3. The creator turns a setting back off while a member has the page open: the member's next action is refused (Task 2 test « a setting turned off applies to the next request »).
4. A non-member calling any new action gets 404, not 403 (Task 1 dataset « non-members get 404 »).
5. A member whose request was created when requests were open keeps the right to edit it after the creator closes requests (Task 1 test « authorship outlives the setting »).

---

### Task 1: Settings storage and server-side permissions

**Files:**
- Create: `database/migrations/2026_10_03_210000_add_permission_settings_to_groups.php`
- Modify: `app/Models/Group.php`, `database/factories/GroupFactory.php`, `app/Policies/GroupPolicy.php`, `app/Policies/MeetingPolicy.php`, `app/Livewire/Groups/Show.php`, `app/Livewire/Meetings/{Show,Form,Summary,Vote}.php`, and every other `isOrganizer` caller (grep)
- Modify tests using `manage` / `isOrganizer`: `tests/Feature/Groups/{PoliciesTest,GroupModelTest,GroupPageTest}.php`, `tests/Feature/DashboardTest.php` (grep `isOrganizer\|'manage'` in tests)
- Test: `tests/Feature/Groups/PermissionSettingsTest.php`

**Interfaces:**
- Produces: `Group::isCreator(User $user): bool` (renamed from `isOrganizer`, every caller updated); `Group::allowsMeetingRequests(User $user): bool`, `Group::allowsInvitations(User $user): bool`, `Group::allowsValidation(User $user): bool`; `GroupFactory::openToMembers()`; abilities `GroupPolicy::update`, `requestMeeting`, `invite` (and `view`, `leave` unchanged); `MeetingPolicy::update`, `validate` (and `view`, `editAvailability`, `vote` unchanged). `manage` no longer exists on either policy.

- [ ] **Step 1: Write the failing tests**

`php artisan make:test --pest Groups/PermissionSettingsTest --no-interaction`:

```php
<?php

use App\Enums\MeetingStatus;
use App\Livewire\Groups\Show as GroupShow;
use App\Livewire\Meetings\Form;
use App\Livewire\Meetings\Show as MeetingShow;
use App\Livewire\Meetings\Summary;
use App\Livewire\Meetings\Vote;
use App\Models\Group;
use App\Models\GroupInvitation;
use App\Models\Meeting;
use App\Models\MeetingSlot;
use App\Models\User;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

beforeEach(function () {
    Notification::fake();
    $this->travelTo(now('Europe/Paris')->setDate(2026, 11, 2)->setTime(10, 0));
    $this->group = Group::factory()->create();
    $this->creator = $this->group->owner;
    $this->member = User::factory()->create();
    $this->group->addMember($this->member);
    $this->outsider = User::factory()->create();
});

function requestFor(Group $group, User $author, array $attributes = []): Meeting
{
    return Meeting::factory()->for($group)->create(['created_by' => $author->id, 'range_start' => '2026-11-04', 'range_end' => '2026-11-10', 'deadline' => '2026-11-03', ...$attributes]);
}

test('settings default to the group creator only', function () {
    $group = Group::factory()->create()->fresh();

    expect($group->members_can_request_meetings)->toBeFalse()
        ->and($group->members_can_invite)->toBeFalse()
        ->and($group->members_can_validate)->toBeFalse();
});

test('requesting a meeting follows the setting', function () {
    $this->actingAs($this->member)->get(route('meetings.create', $this->group))->assertForbidden();
    $this->actingAs($this->creator)->get(route('meetings.create', $this->group))->assertOk();

    $this->group->update(['members_can_request_meetings' => true]);

    $this->actingAs($this->member)->get(route('meetings.create', $this->group))->assertOk();
    $this->actingAs($this->outsider)->get(route('meetings.create', $this->group))->assertNotFound();
});

test('inviting follows the setting', function () {
    $invitation = GroupInvitation::factory()->for($this->group)->create();

    Livewire::actingAs($this->member)->test(GroupShow::class, ['group' => $this->group])
        ->set('invitationEmails', 'amina@example.com')
        ->call('invite')
        ->assertForbidden();

    $this->group->update(['members_can_invite' => true]);

    Livewire::actingAs($this->member)->test(GroupShow::class, ['group' => $this->group])
        ->set('invitationEmails', 'amina@example.com')
        ->call('invite')
        ->assertOk();

    Livewire::actingAs($this->member)->test(GroupShow::class, ['group' => $this->group])
        ->call('cancelInvitation', $invitation->id)
        ->assertOk();

    expect(GroupInvitation::find($invitation->id))->toBeNull();
});

test('validating follows the setting', function () {
    $meeting = requestFor($this->group, $this->creator);

    Livewire::actingAs($this->member)->test(Summary::class, ['meeting' => $meeting])->assertForbidden();

    $this->group->update(['members_can_validate' => true]);

    Livewire::actingAs($this->member)->test(Summary::class, ['meeting' => $meeting])->assertOk();
});

test('confirming or cancelling a vote follows the validation setting', function () {
    $meeting = requestFor($this->group, $this->creator, ['status' => MeetingStatus::Voting]);
    $slot = MeetingSlot::factory()->for($meeting)->create(['starts_at' => '2026-11-05 17:00:00', 'ends_at' => '2026-11-05 19:00:00']);

    Livewire::actingAs($this->member)->test(Vote::class, ['meeting' => $meeting])
        ->call('confirmSlot', $slot->id)
        ->assertForbidden();

    $this->group->update(['members_can_validate' => true]);

    Livewire::actingAs($this->member)->test(Vote::class, ['meeting' => $meeting])
        ->call('confirmSlot', $slot->id)
        ->assertHasNoErrors();

    expect($meeting->fresh()->status)->toBe(MeetingStatus::Confirmed);
});

test('the author edits but does not validate', function () {
    $meeting = requestFor($this->group, $this->member);

    $this->actingAs($this->member)->get(route('meetings.edit', $meeting))->assertOk();
    Livewire::actingAs($this->member)->test(Summary::class, ['meeting' => $meeting])->assertForbidden();

    Livewire::actingAs($this->member)->test(MeetingShow::class, ['meeting' => $meeting])->call('deleteMeeting');

    expect(Meeting::find($meeting->id))->toBeNull();
});

test('another member cannot edit or delete a request, even with validation open', function () {
    $other = User::factory()->create();
    $this->group->addMember($other);
    $this->group->update(['members_can_validate' => true]);
    $meeting = requestFor($this->group, $this->member);

    $this->actingAs($other)->get(route('meetings.edit', $meeting))->assertForbidden();
    Livewire::actingAs($other)->test(MeetingShow::class, ['meeting' => $meeting])->call('deleteMeeting')->assertForbidden();
});

test('the group creator edits and deletes any request', function () {
    $meeting = requestFor($this->group, $this->member);

    $this->actingAs($this->creator)->get(route('meetings.edit', $meeting))->assertOk();
    Livewire::actingAs($this->creator)->test(MeetingShow::class, ['meeting' => $meeting])->call('deleteMeeting');

    expect(Meeting::find($meeting->id))->toBeNull();
});

test('authorship outlives the setting', function () {
    $this->group->update(['members_can_request_meetings' => true]);
    $meeting = requestFor($this->group, $this->member);
    $this->group->update(['members_can_request_meetings' => false]);

    $this->actingAs($this->member)->get(route('meetings.edit', $meeting))->assertOk();
});

test('settings never open group administration', function () {
    $this->group->update(['members_can_request_meetings' => true, 'members_can_invite' => true, 'members_can_validate' => true]);
    $other = User::factory()->create();
    $this->group->addMember($other);

    Livewire::actingAs($this->member)->test(GroupShow::class, ['group' => $this->group])->set('name', 'Pirate')->call('rename')->assertForbidden();
    Livewire::actingAs($this->member)->test(GroupShow::class, ['group' => $this->group])->call('deleteGroup')->assertForbidden();
    Livewire::actingAs($this->member)->test(GroupShow::class, ['group' => $this->group])->call('removeMember', $other->id)->assertForbidden();

    expect($this->group->fresh()->name)->not->toBe('Pirate')
        ->and($this->group->hasMember($other))->toBeTrue();
});

test('non-members get 404', function (string $ability) {
    $meeting = requestFor($this->group, $this->creator);
    $this->group->update(['members_can_request_meetings' => true, 'members_can_invite' => true, 'members_can_validate' => true]);

    $target = in_array($ability, ['update', 'validate'], true) ? $meeting : $this->group;

    expect(Gate::forUser($this->outsider)->inspect($ability, $target)->status())->toBe(404);
})->with(['requestMeeting', 'invite', 'update', 'validate']);
```

(Add `use Illuminate\Support\Facades\Gate;`. For `non-members get 404`, `update` on a meeting and `update` on the group both exist; the dataset checks the meeting one — add a second line `expect(Gate::forUser($this->outsider)->inspect('update', $this->group)->status())->toBe(404);` inside the test when `$ability === 'update'`.) Adapt factory names if `GroupInvitation::factory()` / `MeetingSlot::factory()` states differ — check `database/factories`.

- [ ] **Step 2: Run to verify they fail**

Run: `php artisan test --compact tests/Feature/Groups/PermissionSettingsTest.php`
Expected: FAIL (columns missing, `manage` still in use).

- [ ] **Step 3: Migration, model, factory**

`php artisan make:migration add_permission_settings_to_groups --no-interaction`, rename to `2026_10_03_210000_add_permission_settings_to_groups.php`:

```php
    public function up(): void
    {
        Schema::table('groups', function (Blueprint $table) {
            $table->boolean('members_can_request_meetings')->default(false);
            $table->boolean('members_can_invite')->default(false);
            $table->boolean('members_can_validate')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('groups', function (Blueprint $table) {
            $table->dropColumn(['members_can_request_meetings', 'members_can_invite', 'members_can_validate']);
        });
    }
```

`app/Models/Group.php`: add the three columns to `#[Fillable]` (or the fillable mechanism used there) and to casts as `'boolean'`; add `@property bool` lines; rename `isOrganizer` → `isCreator` (doc « The user who created the group ») and update every caller (`grep -rn isOrganizer app resources tests`); add:

```php
    /**
     * Members may request meetings if the creator opened it to them.
     */
    public function allowsMeetingRequests(User $user): bool
    {
        return $this->hasMember($user) && ($this->isCreator($user) || $this->members_can_request_meetings);
    }

    public function allowsInvitations(User $user): bool
    {
        return $this->hasMember($user) && ($this->isCreator($user) || $this->members_can_invite);
    }

    public function allowsValidation(User $user): bool
    {
        return $this->hasMember($user) && ($this->isCreator($user) || $this->members_can_validate);
    }
```

`database/factories/GroupFactory.php`:

```php
    public function openToMembers(): static
    {
        return $this->state(fn (array $attributes) => [
            'members_can_request_meetings' => true,
            'members_can_invite' => true,
            'members_can_validate' => true,
        ]);
    }
```

- [ ] **Step 4: Policies**

`app/Policies/GroupPolicy.php`:

```php
class GroupPolicy
{
    /**
     * Members see the group; for anyone else it does not exist.
     */
    public function view(User $user, Group $group): Response
    {
        return $group->hasMember($user) ? Response::allow() : Response::denyAsNotFound();
    }

    /**
     * Rename, delete, remove members and change the settings: the group creator only.
     */
    public function update(User $user, Group $group): Response
    {
        return $this->allowMember($user, $group, $group->isCreator($user));
    }

    public function requestMeeting(User $user, Group $group): Response
    {
        return $this->allowMember($user, $group, $group->allowsMeetingRequests($user));
    }

    /**
     * Invite people, resend or cancel pending invitations.
     */
    public function invite(User $user, Group $group): Response
    {
        return $this->allowMember($user, $group, $group->allowsInvitations($user));
    }

    public function leave(User $user, Group $group): bool
    {
        return $group->hasMember($user) && ! $group->isCreator($user);
    }

    private function allowMember(User $user, Group $group, bool $allowed): Response
    {
        if (! $group->hasMember($user)) {
            return Response::denyAsNotFound();
        }

        return $allowed ? Response::allow() : Response::denyWithStatus(403);
    }
}
```

`app/Policies/MeetingPolicy.php`: remove `manage`; add

```php
    /**
     * Edit or delete the request: its author or the group creator.
     */
    public function update(User $user, Meeting $meeting): Response
    {
        $group = $meeting->group;

        if (! $group->hasMember($user)) {
            return Response::denyAsNotFound();
        }

        return $meeting->created_by === $user->id || $group->isCreator($user) ? Response::allow() : Response::denyWithStatus(403);
    }

    /**
     * See the summary, confirm a date, open, settle or cancel the vote.
     */
    public function validate(User $user, Meeting $meeting): Response
    {
        $group = $meeting->group;

        if (! $group->hasMember($user)) {
            return Response::denyAsNotFound();
        }

        return $group->allowsValidation($user) ? Response::allow() : Response::denyWithStatus(403);
    }
```

- [ ] **Step 5: Rewire every `manage` call**

`grep -rn "'manage'" app resources` and replace each:
- `Groups\Show`: `rename`, `deleteGroup`, `removeMember` → `update`; `invite`, `resendInvitation`, `cancelInvitation` → `invite`. In `removeMember`, `isOrganizer` → `isCreator`.
- `Meetings\Form`: creation (mount and hydrate when creating) → `requestMeeting` on the group; `authorizeEditing` and `deleteMeeting` → `update` on the meeting.
- `Meetings\Show::deleteMeeting` → `update`.
- `Meetings\Summary` (mount, hydrate, `confirmWindow`, `openVote`) → `validate`.
- `Meetings\Vote` (`confirmSlot`, `cancelVote`) → `validate`.
Any `@can('manage', …)` in views → the matching ability. View-level `isOrganizer()` helpers are rewired in Task 2; for now rename the model call so the app keeps working.

- [ ] **Step 6: Existing tests**

`grep -rn "isOrganizer\|'manage'" tests` and update: `isOrganizer` → `isCreator`; policy tests on `manage` → `update` (group) or `validate`/`update` (meeting) with the same expectations for creator / member / outsider.

- [ ] **Step 7: Run tests**

Run: `php artisan test --compact tests/Feature/Groups/PermissionSettingsTest.php` → PASS.
Run: `php artisan test --compact` → all green.

- [ ] **Step 8: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add -A app database tests
git commit -m "Group permission settings and per-action policies"
```

---

### Task 2: Settings screen and permission-aware views

**Files:**
- Modify: `app/Livewire/Groups/Show.php`, `resources/views/livewire/groups/show.blade.php`, `app/Livewire/Meetings/Show.php`, `resources/views/livewire/meetings/show.blade.php`, `app/Livewire/Meetings/Vote.php`, `resources/views/livewire/meetings/vote.blade.php`, `lang/fr.json`
- Test: `tests/Feature/Groups/PermissionSettingsPageTest.php`

**Interfaces:**
- Consumes (Task 1): abilities `update`, `requestMeeting`, `invite` (group), `update`, `validate` (meeting); `Group::isCreator()`.
- Produces: `Groups\Show` public props `bool $membersCanRequestMeetings`, `bool $membersCanInvite`, `bool $membersCanValidate` saved by `updated()`; computed `canUpdate`, `canRequestMeeting`, `canInvite` on `Groups\Show`; computed `canUpdate`, `canValidate` on `Meetings\Show`; computed `canValidate` on `Meetings\Vote` (replaces `isOrganizer`).

- [ ] **Step 1: Write the failing tests**

`php artisan make:test --pest Groups/PermissionSettingsPageTest --no-interaction`:

```php
<?php

use App\Enums\MeetingStatus;
use App\Livewire\Groups\Show as GroupShow;
use App\Livewire\Meetings\Show as MeetingShow;
use App\Models\Group;
use App\Models\Meeting;
use App\Models\User;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

beforeEach(function () {
    Notification::fake();
    $this->travelTo(now('Europe/Paris')->setDate(2026, 11, 2)->setTime(10, 0));
    $this->group = Group::factory()->create();
    $this->creator = $this->group->owner;
    $this->member = User::factory()->create(['name' => 'Bastien']);
    $this->group->addMember($this->member);
});

test('the creator changes the settings and they are saved', function () {
    Livewire::actingAs($this->creator)->test(GroupShow::class, ['group' => $this->group])
        ->assertSee(__('Settings'))
        ->set('membersCanRequestMeetings', true)
        ->set('membersCanValidate', true)
        ->assertHasNoErrors();

    $group = $this->group->fresh();

    expect($group->members_can_request_meetings)->toBeTrue()
        ->and($group->members_can_invite)->toBeFalse()
        ->and($group->members_can_validate)->toBeTrue();
});

test('a member neither sees nor changes the settings', function () {
    Livewire::actingAs($this->member)->test(GroupShow::class, ['group' => $this->group])
        ->assertDontSee(__('Settings'))
        ->set('membersCanInvite', true)
        ->assertForbidden();

    expect($this->group->fresh()->members_can_invite)->toBeFalse();
});

test('a member sees the actions the settings open to them', function () {
    $page = fn () => $this->actingAs($this->member)->get(route('groups.show', $this->group));

    $page()->assertDontSee(__('New request'))->assertDontSee(__('Invite people'));

    $this->group->update(['members_can_request_meetings' => true, 'members_can_invite' => true]);

    $page()->assertSee(__('New request'))->assertSee(__('Invite people'))->assertDontSee(__('Delete the group'));
});

test('a setting turned off applies to the next request', function () {
    $this->group->update(['members_can_invite' => true]);
    $component = Livewire::actingAs($this->member)->test(GroupShow::class, ['group' => $this->group]);

    $this->group->update(['members_can_invite' => false]);

    $component->set('invitationEmails', 'amina@example.com')->call('invite')->assertForbidden();
});

test('the meeting page shows the author and actions by right', function () {
    $meeting = Meeting::factory()->for($this->group)->create(['created_by' => $this->member->id, 'range_start' => '2026-11-04', 'range_end' => '2026-11-10', 'deadline' => '2026-11-03']);

    $this->actingAs($this->member)->get(route('meetings.show', $meeting))
        ->assertSee(__('Requested by :name', ['name' => 'Bastien']))
        ->assertSee(__('Edit the request'))
        ->assertDontSee(__('Best slots'));

    $this->group->update(['members_can_validate' => true]);

    $this->actingAs($this->member)->get(route('meetings.show', $meeting))->assertSee(__('Best slots'));
});

test('the vote results and actions follow the validation setting', function () {
    $meeting = Meeting::factory()->for($this->group)->voting()->create(['range_start' => '2026-11-04', 'range_end' => '2026-11-10', 'deadline' => '2026-11-03']);

    $this->actingAs($this->member)->get(route('meetings.show', $meeting))->assertDontSee(__('Cancel the vote'));

    $this->group->update(['members_can_validate' => true]);

    $this->actingAs($this->member)->get(route('meetings.show', $meeting))->assertSee(__('Cancel the vote'));
});

test('the creator badge reads group creator', function () {
    $this->actingAs($this->member)->get(route('groups.show', $this->group))->assertSee(__('Group creator'));
});
```

Adapt labels to the existing keys (check `lang/fr.json` for the exact English keys of « Annuler le vote », « Inviter des personnes », « Nouvelle demande », « Supprimer le groupe », « Meilleurs créneaux »; check `MeetingFactory::voting()` signature).

- [ ] **Step 2: Run to verify they fail**

Run: `php artisan test --compact tests/Feature/Groups/PermissionSettingsPageTest.php` → FAIL.

- [ ] **Step 3: Group page component**

In `app/Livewire/Groups/Show.php`:

```php
    public bool $membersCanRequestMeetings = false;

    public bool $membersCanInvite = false;

    public bool $membersCanValidate = false;
```

in `mount()` after `$this->name = …`:

```php
        $this->membersCanRequestMeetings = $group->members_can_request_meetings;
        $this->membersCanInvite = $group->members_can_invite;
        $this->membersCanValidate = $group->members_can_validate;
```

and:

```php
    /**
     * Settings are saved as soon as a choice changes.
     */
    public function updated(string $property): void
    {
        $columns = [
            'membersCanRequestMeetings' => 'members_can_request_meetings',
            'membersCanInvite' => 'members_can_invite',
            'membersCanValidate' => 'members_can_validate',
        ];

        if (! isset($columns[$property])) {
            return;
        }

        $this->authorize('update', $this->group);

        $this->group->update([$columns[$property] => $this->{$property}]);

        Flux::toast(variant: 'success', text: __('Settings saved.'));
    }

    #[Computed]
    public function canUpdate(): bool
    {
        return Gate::allows('update', $this->group);
    }

    #[Computed]
    public function canRequestMeeting(): bool
    {
        return Gate::allows('requestMeeting', $this->group);
    }

    #[Computed]
    public function canInvite(): bool
    {
        return Gate::allows('invite', $this->group);
    }
```

Remove `isOrganizer()` from the component. Note: `updated()` must ignore `name` and `invitationEmails` (the early return does). If the radio group sends strings (`'1'`/`'0'`), cast with `(bool)`; verify with the test.

- [ ] **Step 4: Group page view**

`resources/views/livewire/groups/show.blade.php`:
- header: « New request » button inside `@if ($this->canRequestMeeting)`; dropdown (rename / delete) inside `@if ($this->canUpdate)`; « Leave the group » stays for non-creators (`@unless ($group->isCreator(auth()->user()))`) — a member may see both « New request » and « Leave ».
- members table: actions column and remove buttons with `$this->canUpdate`; badge `{{ __('Group creator') }}` for `$group->isCreator($member)`.
- invitations block (pending list + invite form) with `$this->canInvite`.
- rename modal with `$this->canUpdate`.
- new « Settings » section visible with `$this->canUpdate`, after the members:

```blade
@if ($this->canUpdate)
    <section class="flex flex-col gap-4">
        <flux:heading size="lg" level="2">{{ __('Settings') }}</flux:heading>
        <flux:card class="flex flex-col gap-6">
            @foreach ([
                'membersCanRequestMeetings' => [__('Request a meeting'), __('Who can start a new meeting request in this group.')],
                'membersCanInvite' => [__('Add or invite members'), __('Who can invite people and manage pending invitations.')],
                'membersCanValidate' => [__('Validate a date'), __('Who can see the best slots, confirm a date or put slots to a vote.')],
            ] as $property => [$label, $help])
                <flux:radio.group wire:model.live="{{ $property }}" :label="$label" :description="$help" variant="segmented">
                    <flux:radio :value="false" :label="__('Group creator')" />
                    <flux:radio :value="true" :label="__('All members')" />
                </flux:radio.group>
            @endforeach
        </flux:card>
    </section>
@endif
```

(If Flux segmented radios cannot bind booleans, use string values `'creator'` / `'members'` on three string props and convert in `updated()`; keep the column booleans. Document the choice.)

- [ ] **Step 5: Meeting page and vote**

`app/Livewire/Meetings/Show.php`: replace `isOrganizer()` with

```php
    #[Computed]
    public function canUpdate(): bool
    {
        return Gate::allows('update', $this->meeting);
    }

    #[Computed]
    public function canValidate(): bool
    {
        return Gate::allows('validate', $this->meeting);
    }
```

`show.blade.php`: Edit/Delete block with `$this->canUpdate`; Best slots section with `$this->canValidate`; under the title add `<flux:text size="sm">{{ __('Requested by :name', ['name' => $meeting->creator->name]) }}</flux:text>`.

`app/Livewire/Meetings/Vote.php`: rename computed `isOrganizer` → `canValidate` returning `Gate::allows('validate', $this->meeting)`; `vote.blade.php` uses `$this->canValidate`.

`lang/fr.json` (alphabetical, only missing keys): `"Add or invite members": "Ajouter ou inviter des membres"`, `"All members": "Tous les membres"`, `"Group creator": "Créateur du groupe"`, `"Request a meeting": "Faire une demande de réunion"`, `"Requested by :name": "Demandée par :name"`, `"Settings": "Réglages"`, `"Settings saved.": "Réglages enregistrés."`, `"Validate a date": "Valider une date"`, `"Who can invite people and manage pending invitations.": "Qui peut inviter des personnes et gérer les invitations en attente."`, `"Who can see the best slots, confirm a date or put slots to a vote.": "Qui peut voir les meilleurs créneaux, valider une date ou soumettre des créneaux au vote."`, `"Who can start a new meeting request in this group.": "Qui peut lancer une nouvelle demande de réunion dans ce groupe."`. Remove `"Organizer"` if no longer used.

- [ ] **Step 6: Run tests and build**

Run: `php artisan test --compact tests/Feature/Groups` → PASS.
Run: `npm run build && php artisan test --compact` → all green.

- [ ] **Step 7: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add -A app resources lang tests
git commit -m "Group settings screen and permission-aware pages"
```
