# Groupes, réunions et disponibilités — plan d'implémentation

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Un organisateur crée un groupe, invite des membres par e-mail, crée des réunions à plusieurs dates ; les membres répondent (sur place / à distance / pas dispo) et tous voient la meilleure date.

**Architecture:** Modèles Eloquent (`Group`, `GroupInvitation`, `Meeting`, `MeetingSlot`, `Availability`) + enums ; logique métier dans des actions (`App\Actions\Groups\*`, `App\Actions\Meetings\FindBestSlot`) ; droits dans deux policies ; écrans en composants Livewire classe + vue (convention du projet) ; acceptation d'invitation par un contrôleur classique.

**Tech Stack:** Laravel 13, Livewire 4, Flux Pro, Fortify, Pest 4, SQLite.

**Spec:** `docs/superpowers/specs/2026-10-02-groupes-reunions-design.md`

## Global Constraints

- Composants Livewire au format **classe** : `app/Livewire/<Domaine>/<Nom>.php` + `resources/views/livewire/<domaine>/<nom>.blade.php` (sans méthode `render()`, comme `App\Livewire\Settings\Profile`), routes `Route::livewire(...)`.
- Fichiers générés par `php artisan make:* --no-interaction`.
- Modèles : attributs `#[Fillable([...])]` (comme `User`), `casts()` en méthode, PHPDoc `@property`.
- Tout texte visible en clé anglaise via `__()` + traduction dans `lang/fr.json` (tri alphabétique insensible à la casse, `ensure_ascii=False`).
- Stockage des dates en UTC ; saisie/affichage dans `config('app.display_timezone')` = `Europe/Paris`.
- Invitations : valables **7 jours**, jeton aléatoire de 40 caractères, seul `hash('sha256', $token)` est stocké.
- E-mails envoyés immédiatement (pas de `ShouldQueue`).
- Non-membre → **404** ; membre sans droit d'organisateur → **403**.
- Meilleure date : plus de présents, puis plus de présents sur place, puis date la plus proche ; aucune présence → pas de meilleure date.
- Avant chaque commit : `vendor/bin/pint --dirty --format agent`. Tests : `php artisan test --compact <fichiers>`.
- Commits sur la branche `feature/groupes-reunions` (déjà créée), terminés par `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.

## Review Focus

1. **Changer l'heure d'une date existante** dans le formulaire d'édition → les réponses données pour l'ancienne heure sont supprimées (personne n'a répondu pour la nouvelle). Test en tâche 7.
2. **Réponse forgée pour une date d'une autre réunion** (clé `responses[<id d'un autre slot>]`) → ignorée, aucune ligne créée. Test en tâche 8.
3. **Adresses invitées en double ou avec majuscules** (`Amina@X.org, amina@x.org`) → une seule invitation. Test en tâche 6.
4. **Lien d'invitation ouvert par un compte déjà membre** → pas de doublon de membre, redirection vers le groupe, invitation marquée acceptée. Test en tâche 6.
5. **Dates de part et d'autre du passage à l'heure d'hiver** (`2026-10-24T20:00` et `2026-10-25T20:00`, Europe/Paris) → stockées à 18:00 et 19:00 UTC, réaffichées à 20:00. Test en tâche 7.

---

## Structure des fichiers

| Fichier | Rôle |
|---|---|
| `app/Enums/GroupRole.php`, `app/Enums/AvailabilityStatus.php` | rôles et statuts |
| `app/Models/{Group,GroupInvitation,Meeting,MeetingSlot,Availability}.php` + factories + migrations | données |
| `app/Models/User.php` | relation `groups()` |
| `config/app.php` | `display_timezone` |
| `app/Actions/Meetings/FindBestSlot.php` | règle de la meilleure date (unique implémentation) |
| `app/View/Components/DemoPoll.php` | réaligné sur `FindBestSlot` |
| `app/Policies/{GroupPolicy,MeetingPolicy}.php` | droits |
| `app/Actions/Groups/{CreateGroup,InviteToGroup,SendGroupInvitation}.php` | création et invitations |
| `app/Notifications/GroupInvitationNotification.php` | e-mail d'invitation |
| `app/Http/Controllers/GroupInvitationController.php` + `resources/views/invitations/{show,invalid}.blade.php` | acceptation |
| `app/Livewire/Dashboard.php` + vue | tableau de bord, création de groupe |
| `app/Livewire/Groups/Show.php` + vue | page du groupe |
| `app/Livewire/Meetings/Form.php` + vue | créer / modifier une réunion |
| `app/Livewire/Meetings/Show.php` + vue | répondre, tableau, non-répondants |
| `resources/views/layouts/app/sidebar.blade.php` | groupes dans la barre latérale |
| `database/seeders/DemoSeeder.php` | données de démonstration |

---

### Task 1 : Données

**Files:**
- Create: `app/Enums/GroupRole.php`, `app/Enums/AvailabilityStatus.php`, modèles + migrations + factories `Group`, `GroupInvitation`, `Meeting`, `MeetingSlot`, `Availability`
- Modify: `app/Models/User.php`, `config/app.php`
- Test: `tests/Feature/Groups/GroupModelTest.php`

**Interfaces:**
- Produces:
  - `GroupRole::Organizer` (`'organizer'`), `GroupRole::Member` (`'member'`) ; `AvailabilityStatus::OnSite` (`'on_site'`), `::Remote` (`'remote'`), `::Unavailable` (`'unavailable'`) avec `label(): string`.
  - `Group` : `owner()`, `members()` (pivot `role`), `invitations()`, `meetings()`, `isOrganizer(User): bool`, `hasMember(User): bool`, `addMember(User, GroupRole = Member): void`, `removeMember(User): void` (supprime aussi ses réponses aux réunions du groupe).
  - `GroupInvitation` : `group()`, `inviter()`, scope `pending()`, `static findByToken(string): ?self`, `isUsable(): bool`.
  - `Meeting` : `group()`, `creator()`, `slots()` (triées), scopes `upcoming()` / `past()`, `isPast(): bool`.
  - `MeetingSlot` : `meeting()`, `availabilities()`, `startsAtLocal(): CarbonInterface`.
  - `Availability` : `slot()`, `user()`, cast `status` → `AvailabilityStatus`.
  - `User::groups()` (pivot `role`).
  - Factories : `Group::factory()` (le propriétaire est ajouté comme organisateur), `GroupInvitation::factory()` (états `expired()`, `accepted()`), `Meeting::factory()`, `MeetingSlot::factory()`, `Availability::factory()`.

- [ ] **Step 1 : Générer les fichiers**

```bash
php artisan make:enum GroupRole --string --no-interaction
php artisan make:enum AvailabilityStatus --string --no-interaction
php artisan make:model Group -mf --no-interaction
php artisan make:model GroupInvitation -mf --no-interaction
php artisan make:model Meeting -mf --no-interaction
php artisan make:model MeetingSlot -mf --no-interaction
php artisan make:model Availability -mf --no-interaction
php artisan make:test --pest Groups/GroupModelTest --no-interaction
```

(Si `make:enum` n'accepte pas `--string`, le lancer sans option puis écrire le contenu ci-dessous.)

- [ ] **Step 2 : Écrire le test qui échoue**

`tests/Feature/Groups/GroupModelTest.php` :

```php
<?php

use App\Enums\AvailabilityStatus;
use App\Enums\GroupRole;
use App\Models\Availability;
use App\Models\Group;
use App\Models\GroupInvitation;
use App\Models\Meeting;
use App\Models\MeetingSlot;
use App\Models\User;

test('the owner of a new group is its organizer', function () {
    $group = Group::factory()->create();

    expect($group->isOrganizer($group->owner))->toBeTrue()
        ->and($group->hasMember($group->owner))->toBeTrue()
        ->and($group->members()->first()->pivot->role)->toBe(GroupRole::Organizer->value);
});

test('members can be added and removed with their answers', function () {
    $group = Group::factory()->create();
    $member = User::factory()->create();
    $group->addMember($member);
    $slot = MeetingSlot::factory()->for(Meeting::factory()->for($group))->create();
    Availability::factory()->for($slot, 'slot')->for($member)->create();

    $group->removeMember($member);

    expect($group->hasMember($member))->toBeFalse()
        ->and(Availability::count())->toBe(0);
});

test('deleting a group deletes its meetings, slots, answers and invitations', function () {
    $group = Group::factory()->create();
    $slot = MeetingSlot::factory()->for(Meeting::factory()->for($group))->create();
    Availability::factory()->for($slot, 'slot')->for($group->owner)->create();
    GroupInvitation::factory()->for($group)->create();

    $group->delete();

    expect(Meeting::count())->toBe(0)
        ->and(MeetingSlot::count())->toBe(0)
        ->and(Availability::count())->toBe(0)
        ->and(GroupInvitation::count())->toBe(0);
});

test('an invitation is found by its token and only usable while pending', function () {
    $invitation = GroupInvitation::factory()->create(['token_hash' => hash('sha256', 'secret-token')]);

    expect(GroupInvitation::findByToken('secret-token')?->is($invitation))->toBeTrue()
        ->and(GroupInvitation::findByToken('wrong'))->toBeNull()
        ->and($invitation->isUsable())->toBeTrue()
        ->and(GroupInvitation::factory()->expired()->create()->isUsable())->toBeFalse()
        ->and(GroupInvitation::factory()->accepted()->create()->isUsable())->toBeFalse();
});

test('meetings are upcoming while one of their dates is ahead', function () {
    $upcoming = Meeting::factory()->create();
    MeetingSlot::factory()->for($upcoming)->create(['starts_at' => now()->subDay()]);
    MeetingSlot::factory()->for($upcoming)->create(['starts_at' => now()->addDay()]);
    $past = Meeting::factory()->create();
    MeetingSlot::factory()->for($past)->create(['starts_at' => now()->subDay()]);

    expect(Meeting::upcoming()->pluck('id')->all())->toBe([$upcoming->id])
        ->and(Meeting::past()->pluck('id')->all())->toBe([$past->id])
        ->and($past->isPast())->toBeTrue();
});

test('slots are shown in the display timezone and statuses have labels', function () {
    $slot = MeetingSlot::factory()->create(['starts_at' => '2026-10-14 16:30:00']);

    expect($slot->startsAtLocal()->format('H:i'))->toBe('18:30')
        ->and(AvailabilityStatus::OnSite->label())->toBe(__('On site'));
});
```

- [ ] **Step 3 : Lancer le test, vérifier l'échec**

Run: `php artisan test --compact tests/Feature/Groups/GroupModelTest.php`
Expected: FAIL (méthodes / colonnes absentes).

- [ ] **Step 4 : Enums**

`app/Enums/GroupRole.php` :

```php
<?php

namespace App\Enums;

enum GroupRole: string
{
    case Organizer = 'organizer';
    case Member = 'member';
}
```

`app/Enums/AvailabilityStatus.php` :

```php
<?php

namespace App\Enums;

enum AvailabilityStatus: string
{
    case OnSite = 'on_site';
    case Remote = 'remote';
    case Unavailable = 'unavailable';

    public function label(): string
    {
        return match ($this) {
            self::OnSite => __('On site'),
            self::Remote => __('Remote'),
            self::Unavailable => __('Unavailable'),
        };
    }

    public function isPresent(): bool
    {
        return $this !== self::Unavailable;
    }
}
```

- [ ] **Step 5 : Migrations**

Dans chaque migration générée, méthode `up()` (garder les noms de fichiers générés ; l'ordre de génération du Step 1 garantit les clés étrangères) :

`create_groups_table` :

```php
Schema::create('groups', function (Blueprint $table) {
    $table->id();
    $table->string('name');
    $table->foreignId('owner_id')->constrained('users')->cascadeOnDelete();
    $table->timestamps();
});

Schema::create('group_user', function (Blueprint $table) {
    $table->id();
    $table->foreignId('group_id')->constrained()->cascadeOnDelete();
    $table->foreignId('user_id')->constrained()->cascadeOnDelete();
    $table->string('role');
    $table->timestamps();
    $table->unique(['group_id', 'user_id']);
});
```

(et `down()` : `Schema::dropIfExists('group_user'); Schema::dropIfExists('groups');`)

`create_group_invitations_table` :

```php
Schema::create('group_invitations', function (Blueprint $table) {
    $table->id();
    $table->foreignId('group_id')->constrained()->cascadeOnDelete();
    $table->string('email');
    $table->string('token_hash', 64)->unique();
    $table->foreignId('invited_by')->constrained('users')->cascadeOnDelete();
    $table->timestamp('expires_at');
    $table->timestamp('accepted_at')->nullable();
    $table->foreignId('accepted_by')->nullable()->constrained('users')->nullOnDelete();
    $table->timestamps();
    $table->index(['group_id', 'email']);
});
```

`create_meetings_table` :

```php
Schema::create('meetings', function (Blueprint $table) {
    $table->id();
    $table->foreignId('group_id')->constrained()->cascadeOnDelete();
    $table->string('title');
    $table->text('description')->nullable();
    $table->string('location')->nullable();
    $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
    $table->timestamps();
});
```

`create_meeting_slots_table` :

```php
Schema::create('meeting_slots', function (Blueprint $table) {
    $table->id();
    $table->foreignId('meeting_id')->constrained()->cascadeOnDelete();
    $table->dateTime('starts_at');
    $table->timestamps();
    $table->unique(['meeting_id', 'starts_at']);
});
```

`create_availabilities_table` :

```php
Schema::create('availabilities', function (Blueprint $table) {
    $table->id();
    $table->foreignId('meeting_slot_id')->constrained()->cascadeOnDelete();
    $table->foreignId('user_id')->constrained()->cascadeOnDelete();
    $table->string('status');
    $table->timestamps();
    $table->unique(['meeting_slot_id', 'user_id']);
});
```

SQLite applique les clés étrangères (`DB_FOREIGN_KEYS` vrai par défaut) : les cascades suffisent.

- [ ] **Step 6 : Configuration**

Dans `config/app.php`, après `'timezone' => 'UTC',` :

```php
    /*
    | Timezone in which meeting dates are entered and displayed. Dates are stored in UTC.
    */

    'display_timezone' => env('APP_DISPLAY_TIMEZONE', 'Europe/Paris'),
```

- [ ] **Step 7 : Modèles**

`app/Models/Group.php` :

```php
<?php

namespace App\Models;

use App\Enums\GroupRole;
use Database\Factories\GroupFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $name
 * @property int $owner_id
 */
#[Fillable(['name'])]
class Group extends Model
{
    /** @use HasFactory<GroupFactory> */
    use HasFactory;

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /** @return BelongsToMany<User, $this> */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withPivot('role')->withTimestamps();
    }

    /** @return HasMany<GroupInvitation, $this> */
    public function invitations(): HasMany
    {
        return $this->hasMany(GroupInvitation::class);
    }

    /** @return HasMany<Meeting, $this> */
    public function meetings(): HasMany
    {
        return $this->hasMany(Meeting::class);
    }

    public function isOrganizer(User $user): bool
    {
        return $this->owner_id === $user->id;
    }

    public function hasMember(User $user): bool
    {
        return $this->members()->whereKey($user->id)->exists();
    }

    public function addMember(User $user, GroupRole $role = GroupRole::Member): void
    {
        $this->members()->syncWithoutDetaching([$user->id => ['role' => $role->value]]);
    }

    /**
     * Remove a member along with the answers they gave to this group's meetings.
     */
    public function removeMember(User $user): void
    {
        Availability::query()
            ->where('user_id', $user->id)
            ->whereIn('meeting_slot_id', MeetingSlot::query()->select('meeting_slots.id')
                ->join('meetings', 'meetings.id', '=', 'meeting_slots.meeting_id')
                ->where('meetings.group_id', $this->id))
            ->delete();

        $this->members()->detach($user->id);
    }
}
```

`app/Models/GroupInvitation.php` :

```php
<?php

namespace App\Models;

use Database\Factories\GroupInvitationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $group_id
 * @property string $email
 * @property string $token_hash
 * @property int $invited_by
 * @property Carbon $expires_at
 * @property Carbon|null $accepted_at
 * @property int|null $accepted_by
 */
#[Fillable(['email'])]
class GroupInvitation extends Model
{
    /** @use HasFactory<GroupInvitationFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Group, $this> */
    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    /** @return BelongsTo<User, $this> */
    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    /**
     * Invitations not yet accepted and not expired.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function pending(Builder $query): void
    {
        $query->whereNull('accepted_at')->where('expires_at', '>', now());
    }

    public static function findByToken(string $token): ?self
    {
        return static::where('token_hash', hash('sha256', $token))->first();
    }

    public function isUsable(): bool
    {
        return is_null($this->accepted_at) && $this->expires_at->isFuture();
    }
}
```

`app/Models/Meeting.php` :

```php
<?php

namespace App\Models;

use Database\Factories\MeetingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $group_id
 * @property string $title
 * @property string|null $description
 * @property string|null $location
 * @property int $created_by
 */
#[Fillable(['title', 'description', 'location'])]
class Meeting extends Model
{
    /** @use HasFactory<MeetingFactory> */
    use HasFactory;

    /** @return BelongsTo<Group, $this> */
    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return HasMany<MeetingSlot, $this> */
    public function slots(): HasMany
    {
        return $this->hasMany(MeetingSlot::class)->orderBy('starts_at');
    }

    /**
     * Meetings with at least one date still ahead.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function upcoming(Builder $query): void
    {
        $query->whereHas('slots', fn (Builder $slots) => $slots->where('starts_at', '>=', now()));
    }

    /**
     * Meetings whose dates are all behind us.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function past(Builder $query): void
    {
        $query->whereDoesntHave('slots', fn (Builder $slots) => $slots->where('starts_at', '>=', now()));
    }

    public function isPast(): bool
    {
        return ! $this->slots()->where('starts_at', '>=', now())->exists();
    }
}
```

`app/Models/MeetingSlot.php` :

```php
<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Database\Factories\MeetingSlotFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $meeting_id
 * @property Carbon $starts_at
 */
#[Fillable(['starts_at'])]
class MeetingSlot extends Model
{
    /** @use HasFactory<MeetingSlotFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Meeting, $this> */
    public function meeting(): BelongsTo
    {
        return $this->belongsTo(Meeting::class);
    }

    /** @return HasMany<Availability, $this> */
    public function availabilities(): HasMany
    {
        return $this->hasMany(Availability::class);
    }

    public function startsAtLocal(): CarbonInterface
    {
        return $this->starts_at->copy()->setTimezone(config('app.display_timezone'));
    }
}
```

`app/Models/Availability.php` :

```php
<?php

namespace App\Models;

use App\Enums\AvailabilityStatus;
use Database\Factories\AvailabilityFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $meeting_slot_id
 * @property int $user_id
 * @property AvailabilityStatus $status
 */
#[Fillable(['status'])]
class Availability extends Model
{
    /** @use HasFactory<AvailabilityFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'status' => AvailabilityStatus::class,
        ];
    }

    /** @return BelongsTo<MeetingSlot, $this> */
    public function slot(): BelongsTo
    {
        return $this->belongsTo(MeetingSlot::class, 'meeting_slot_id');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
```

Dans `app/Models/User.php`, ajouter (avec `use Illuminate\Database\Eloquent\Relations\BelongsToMany;`) :

```php
    /**
     * Groups the user belongs to, as organizer or member.
     *
     * @return BelongsToMany<Group, $this>
     */
    public function groups(): BelongsToMany
    {
        return $this->belongsToMany(Group::class)->withPivot('role')->withTimestamps()->orderBy('groups.name');
    }
```

- [ ] **Step 8 : Factories**

`database/factories/GroupFactory.php` — `definition()` et `configure()` :

```php
    public function definition(): array
    {
        return [
            'name' => fake()->company(),
            'owner_id' => User::factory(),
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(fn (Group $group) => $group->addMember($group->owner, GroupRole::Organizer));
    }
```

`GroupInvitationFactory` :

```php
    public function definition(): array
    {
        return [
            'group_id' => Group::factory(),
            'email' => fake()->unique()->safeEmail(),
            'token_hash' => hash('sha256', Str::random(40)),
            'invited_by' => fn (array $attributes) => Group::find($attributes['group_id'])->owner_id,
            'expires_at' => now()->addDays(7),
        ];
    }

    public function expired(): static
    {
        return $this->state(fn (array $attributes) => ['expires_at' => now()->subMinute()]);
    }

    public function accepted(): static
    {
        return $this->state(fn (array $attributes) => ['accepted_at' => now()]);
    }
```

`MeetingFactory` :

```php
    public function definition(): array
    {
        return [
            'group_id' => Group::factory(),
            'title' => fake()->sentence(3),
            'created_by' => fn (array $attributes) => Group::find($attributes['group_id'])->owner_id,
        ];
    }
```

`MeetingSlotFactory` :

```php
    public function definition(): array
    {
        return [
            'meeting_id' => Meeting::factory(),
            'starts_at' => now()->addDays(fake()->numberBetween(1, 30))->setTime(18, 30),
        ];
    }
```

`AvailabilityFactory` :

```php
    public function definition(): array
    {
        return [
            'meeting_slot_id' => MeetingSlot::factory(),
            'user_id' => User::factory(),
            'status' => AvailabilityStatus::OnSite,
        ];
    }
```

(Imports nécessaires dans chaque factory : modèles, `App\Enums\*`, `Illuminate\Support\Str`.)

- [ ] **Step 9 : Traductions**

Ajouter à `lang/fr.json` : `"On site": "Sur place"`, `"Remote": "À distance"`, `"Unavailable": "Pas dispo"`.

- [ ] **Step 10 : Migrer et lancer les tests**

Run: `php artisan migrate --no-interaction && php artisan test --compact tests/Feature/Groups/GroupModelTest.php`
Expected: PASS (6 tests).

- [ ] **Step 11 : Commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Enums app/Models config/app.php database lang/fr.json tests/Feature/Groups
git commit -m "Add groups, invitations, meetings and availabilities models"
```

---

### Task 2 : Meilleure date

**Files:**
- Create: `app/Actions/Meetings/FindBestSlot.php`
- Modify: `app/View/Components/DemoPoll.php`
- Test: `tests/Feature/Meetings/FindBestSlotTest.php`, `tests/Feature/HomePageTest.php` (inchangé, doit rester vert)

**Interfaces:**
- Produces: `App\Actions\Meetings\FindBestSlot::__invoke(iterable $tallies): int|string|null` où chaque élément est `array{onSite: int, remote: int, startsAt: CarbonInterface}` ; renvoie la clé de la meilleure date ou `null`.

- [ ] **Step 1 : Écrire le test qui échoue**

`php artisan make:test --pest Meetings/FindBestSlotTest --no-interaction` :

```php
<?php

use App\Actions\Meetings\FindBestSlot;
use Illuminate\Support\Carbon;

function tally(int $onSite, int $remote, string $startsAt): array
{
    return ['onSite' => $onSite, 'remote' => $remote, 'startsAt' => Carbon::parse($startsAt)];
}

test('the date with the most attendees wins', function () {
    expect((new FindBestSlot)(['a' => tally(2, 1, '2026-10-14'), 'b' => tally(3, 1, '2026-10-16')]))->toBe('b');
});

test('a tie is broken by the number of people on site', function () {
    expect((new FindBestSlot)(['a' => tally(1, 3, '2026-10-14'), 'b' => tally(3, 1, '2026-10-16')]))->toBe('b');
});

test('a complete tie is broken by the earliest date', function () {
    expect((new FindBestSlot)(['late' => tally(2, 1, '2026-10-20'), 'early' => tally(2, 1, '2026-10-14')]))->toBe('early');
});

test('there is no best date when nobody can come', function () {
    expect((new FindBestSlot)(['a' => tally(0, 0, '2026-10-14')]))->toBeNull()
        ->and((new FindBestSlot)([]))->toBeNull();
});
```

- [ ] **Step 2 : Lancer, vérifier l'échec**

Run: `php artisan test --compact tests/Feature/Meetings/FindBestSlotTest.php`
Expected: FAIL (classe inexistante).

- [ ] **Step 3 : Implémenter**

`php artisan make:class Actions/Meetings/FindBestSlot --no-interaction`, puis :

```php
<?php

namespace App\Actions\Meetings;

use Carbon\CarbonInterface;

/**
 * Pick the best meeting date: most attendees, then most on site, then the earliest.
 */
class FindBestSlot
{
    /**
     * @param  iterable<int|string, array{onSite: int, remote: int, startsAt: CarbonInterface}>  $tallies
     */
    public function __invoke(iterable $tallies): int|string|null
    {
        $bestKey = null;
        $best = null;

        foreach ($tallies as $key => $tally) {
            $total = $tally['onSite'] + $tally['remote'];

            if ($total === 0) {
                continue;
            }

            if ($best === null || $this->beats($total, $tally, $best)) {
                $bestKey = $key;
                $best = ['total' => $total] + $tally;
            }
        }

        return $bestKey;
    }

    /**
     * @param  array{onSite: int, remote: int, startsAt: CarbonInterface}  $tally
     * @param  array{total: int, onSite: int, remote: int, startsAt: CarbonInterface}  $best
     */
    private function beats(int $total, array $tally, array $best): bool
    {
        if ($total !== $best['total']) {
            return $total > $best['total'];
        }

        if ($tally['onSite'] !== $best['onSite']) {
            return $tally['onSite'] > $best['onSite'];
        }

        return $tally['startsAt']->lt($best['startsAt']);
    }
}
```

- [ ] **Step 4 : Réaligner `DemoPoll`**

Dans `app/View/Components/DemoPoll.php`, remplacer le corps de `bestDateIndex()` par :

```php
    public function bestDateIndex(): int
    {
        $tallies = array_map(
            fn (array $tally, int $index): array => $tally + ['startsAt' => now()->startOfDay()->addDays($index)],
            $this->tallies,
            array_keys($this->tallies),
        );

        return (new FindBestSlot)($tallies) ?? 0;
    }
```

(import `App\Actions\Meetings\FindBestSlot` ; mettre à jour le PHPDoc : « Delegates to the same rule as real meetings. »)

- [ ] **Step 5 : Lancer les tests**

Run: `php artisan test --compact tests/Feature/Meetings/FindBestSlotTest.php tests/Feature/HomePageTest.php`
Expected: PASS.

- [ ] **Step 6 : Commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Actions/Meetings app/View/Components/DemoPoll.php tests/Feature/Meetings
git commit -m "Add the best date rule shared by meetings and the demo poll"
```

---

### Task 3 : Droits d'accès

**Files:**
- Create: `app/Policies/GroupPolicy.php`, `app/Policies/MeetingPolicy.php`
- Test: `tests/Feature/Groups/PoliciesTest.php`

**Interfaces:**
- Consumes: `Group::hasMember()`, `Group::isOrganizer()` (Task 1).
- Produces (abilities, auto-découvertes) :
  - `GroupPolicy` : `view`, `manage` (renommer, supprimer, inviter, gérer les membres, créer une réunion), `leave`.
  - `MeetingPolicy` : `view`, `manage` (modifier, supprimer), `respond`.
  - Non-membre : `Response::denyAsNotFound()` ; membre non organisateur sur `manage` : `Response::deny()`.

- [ ] **Step 1 : Écrire le test qui échoue**

`php artisan make:test --pest Groups/PoliciesTest --no-interaction` :

```php
<?php

use App\Models\Group;
use App\Models\Meeting;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

beforeEach(function () {
    $this->group = Group::factory()->create();
    $this->organizer = $this->group->owner;
    $this->member = User::factory()->create();
    $this->group->addMember($this->member);
    $this->outsider = User::factory()->create();
    $this->meeting = Meeting::factory()->for($this->group)->create();
});

test('only members can see a group and its meetings, others get a 404', function () {
    expect(Gate::forUser($this->member)->allows('view', $this->group))->toBeTrue()
        ->and(Gate::forUser($this->member)->allows('view', $this->meeting))->toBeTrue()
        ->and(Gate::forUser($this->outsider)->inspect('view', $this->group)->status())->toBe(404)
        ->and(Gate::forUser($this->outsider)->inspect('view', $this->meeting)->status())->toBe(404);
});

test('only the organizer manages, a member gets a 403 and an outsider a 404', function () {
    expect(Gate::forUser($this->organizer)->allows('manage', $this->group))->toBeTrue()
        ->and(Gate::forUser($this->organizer)->allows('manage', $this->meeting))->toBeTrue()
        ->and(Gate::forUser($this->member)->inspect('manage', $this->group)->status())->toBe(403)
        ->and(Gate::forUser($this->member)->inspect('manage', $this->meeting)->status())->toBe(403)
        ->and(Gate::forUser($this->outsider)->inspect('manage', $this->group)->status())->toBe(404);
});

test('members respond, outsiders cannot', function () {
    expect(Gate::forUser($this->member)->allows('respond', $this->meeting))->toBeTrue()
        ->and(Gate::forUser($this->organizer)->allows('respond', $this->meeting))->toBeTrue()
        ->and(Gate::forUser($this->outsider)->allows('respond', $this->meeting))->toBeFalse();
});

test('a member can leave but the organizer cannot', function () {
    expect(Gate::forUser($this->member)->allows('leave', $this->group))->toBeTrue()
        ->and(Gate::forUser($this->organizer)->allows('leave', $this->group))->toBeFalse();
});
```

- [ ] **Step 2 : Lancer, vérifier l'échec**

Run: `php artisan test --compact tests/Feature/Groups/PoliciesTest.php`
Expected: FAIL (aucune policy : tout est refusé).

- [ ] **Step 3 : Implémenter**

`php artisan make:policy GroupPolicy --model=Group --no-interaction` puis remplacer le contenu :

```php
<?php

namespace App\Policies;

use App\Models\Group;
use App\Models\User;
use Illuminate\Auth\Access\Response;

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
     * Rename, delete, invite, manage members and create meetings.
     */
    public function manage(User $user, Group $group): Response
    {
        if (! $group->hasMember($user)) {
            return Response::denyAsNotFound();
        }

        return $group->isOrganizer($user) ? Response::allow() : Response::deny();
    }

    public function leave(User $user, Group $group): bool
    {
        return $group->hasMember($user) && ! $group->isOrganizer($user);
    }
}
```

`php artisan make:policy MeetingPolicy --model=Meeting --no-interaction` puis :

```php
<?php

namespace App\Policies;

use App\Models\Meeting;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class MeetingPolicy
{
    public function __construct(private GroupPolicy $groups) {}

    public function view(User $user, Meeting $meeting): Response
    {
        return $this->groups->view($user, $meeting->group);
    }

    public function manage(User $user, Meeting $meeting): Response
    {
        return $this->groups->manage($user, $meeting->group);
    }

    public function respond(User $user, Meeting $meeting): bool
    {
        return $meeting->group->hasMember($user);
    }
}
```

- [ ] **Step 4 : Lancer les tests**

Run: `php artisan test --compact tests/Feature/Groups/PoliciesTest.php`
Expected: PASS (4 tests).

- [ ] **Step 5 : Commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Policies tests/Feature/Groups/PoliciesTest.php
git commit -m "Add group and meeting access policies"
```

---

### Task 4 : Tableau de bord et création de groupe

**Files:**
- Create: `app/Actions/Groups/CreateGroup.php`, `app/Livewire/Dashboard.php`, `resources/views/livewire/dashboard.blade.php`
- Modify: `routes/web.php`, `resources/views/layouts/app/sidebar.blade.php`, `lang/fr.json`
- Delete: `resources/views/dashboard.blade.php` (remplacé par le composant)
- Test: `tests/Feature/DashboardTest.php` (ajouts)

**Interfaces:**
- Consumes: modèles (Task 1), `GroupPolicy` (Task 3).
- Produces: `App\Actions\Groups\CreateGroup::__invoke(User $owner, string $name): Group` ; composant `App\Livewire\Dashboard` (propriété `name`, action `createGroup()`, computed `groups`, `pendingMeetings`) ; route `dashboard` inchangée de nom ; route `groups.show` utilisée ici et **créée en Task 5** — pour que cette tâche soit testable seule, déclarer dès maintenant la route `groups.show` vers le composant `App\Livewire\Groups\Show` créé à vide en Step 3.

- [ ] **Step 1 : Écrire les tests qui échouent**

Ajouter à `tests/Feature/DashboardTest.php` (imports : `App\Enums\AvailabilityStatus`, `App\Livewire\Dashboard`, `App\Models\Availability`, `App\Models\Group`, `App\Models\Meeting`, `App\Models\MeetingSlot`, `Livewire\Livewire`) :

```php
test('a new user is invited to create their first group', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('dashboard'))
        ->assertSee(__('Create my first group'));
});

test('creating a group makes the user its organizer and opens it', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(Dashboard::class)
        ->set('name', 'Bureau de l\'AMAP')
        ->call('createGroup')
        ->assertHasNoErrors()
        ->assertRedirect(route('groups.show', Group::sole()));

    expect(Group::sole()->isOrganizer($user))->toBeTrue();
});

test('a group needs a name', function () {
    Livewire::actingAs(User::factory()->create())
        ->test(Dashboard::class)
        ->set('name', '')
        ->call('createGroup')
        ->assertHasErrors(['name' => 'required']);
});

test('the dashboard lists my groups and only the upcoming meetings I have not answered', function () {
    $user = User::factory()->create();
    $group = Group::factory()->create(['name' => 'Jardin partagé']);
    $group->addMember($user);
    $toAnswer = Meeting::factory()->for($group)->create(['title' => 'Assemblée à répondre']);
    MeetingSlot::factory()->for($toAnswer)->create(['starts_at' => now()->addWeek()]);
    $answered = Meeting::factory()->for($group)->create(['title' => 'Réunion déjà répondue']);
    $slot = MeetingSlot::factory()->for($answered)->create(['starts_at' => now()->addWeek()]);
    Availability::factory()->for($slot, 'slot')->for($user)->create(['status' => AvailabilityStatus::Remote]);
    $past = Meeting::factory()->for($group)->create(['title' => 'Réunion passée']);
    MeetingSlot::factory()->for($past)->create(['starts_at' => now()->subWeek()]);
    $otherGroup = Meeting::factory()->create(['title' => 'Réunion d\'un autre groupe']);
    MeetingSlot::factory()->for($otherGroup)->create(['starts_at' => now()->addWeek()]);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertSee('Jardin partagé')
        ->assertSee('Assemblée à répondre')
        ->assertDontSee('Réunion déjà répondue')
        ->assertDontSee('Réunion passée')
        ->assertDontSee('Réunion d\'un autre groupe');
});
```

- [ ] **Step 2 : Lancer, vérifier l'échec**

Run: `php artisan test --compact tests/Feature/DashboardTest.php`
Expected: FAIL (composant `Dashboard` inexistant).

- [ ] **Step 3 : Action, composants et routes**

`php artisan make:class Actions/Groups/CreateGroup --no-interaction` :

```php
<?php

namespace App\Actions\Groups;

use App\Enums\GroupRole;
use App\Models\Group;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class CreateGroup
{
    public function __invoke(User $owner, string $name): Group
    {
        return DB::transaction(function () use ($owner, $name): Group {
            $group = new Group(['name' => $name]);
            $group->owner()->associate($owner);
            $group->save();

            $group->addMember($owner, GroupRole::Organizer);

            return $group;
        });
    }
}
```

`php artisan make:livewire Dashboard --class --no-interaction` puis `php artisan make:livewire Groups/Show --class --no-interaction` (vérifier que les fichiers sont `app/Livewire/Dashboard.php` + `resources/views/livewire/dashboard.blade.php` et `app/Livewire/Groups/Show.php` + `resources/views/livewire/groups/show.blade.php`). Supprimer la méthode `render()` générée si elle ne fait que retourner la vue par défaut.

`app/Livewire/Dashboard.php` :

```php
<?php

namespace App\Livewire;

use App\Actions\Groups\CreateGroup;
use App\Models\Group;
use App\Models\Meeting;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Validate;
use Livewire\Component;

#[Title('Dashboard')]
class Dashboard extends Component
{
    #[Validate('required|string|max:120')]
    public string $name = '';

    public function createGroup(CreateGroup $createGroup): void
    {
        $this->validate();

        $group = $createGroup(Auth::user(), $this->name);

        $this->redirectRoute('groups.show', $group, navigate: true);
    }

    /**
     * @return Collection<int, Group>
     */
    #[Computed]
    public function groups(): Collection
    {
        return Auth::user()->groups()
            ->withCount('members')
            ->with(['meetings' => fn ($query) => $query->upcoming()->with('slots')])
            ->get();
    }

    /**
     * Upcoming meetings of my groups that I have not answered yet.
     *
     * @return Collection<int, Meeting>
     */
    #[Computed]
    public function pendingMeetings(): Collection
    {
        $userId = Auth::id();

        return Meeting::query()
            ->upcoming()
            ->whereHas('group.members', fn (Builder $members) => $members->whereKey($userId))
            ->whereDoesntHave('slots.availabilities', fn (Builder $answers) => $answers->where('user_id', $userId))
            ->with(['group', 'slots'])
            ->get();
    }
}
```

`app/Livewire/Groups/Show.php` (version minimale, complétée en Task 5) :

```php
<?php

namespace App\Livewire\Groups;

use App\Models\Group;
use Livewire\Component;

class Show extends Component
{
    public Group $group;

    public function mount(Group $group): void
    {
        $this->authorize('view', $group);

        $this->group = $group;
    }
}
```

`resources/views/livewire/groups/show.blade.php` (minimal) :

```blade
<div>
    <flux:heading size="xl" level="1">{{ $group->name }}</flux:heading>
</div>
```

Dans `routes/web.php`, remplacer `Route::view('dashboard', 'dashboard')->name('dashboard');` par :

```php
    Route::livewire('dashboard', Dashboard::class)->name('dashboard');
    Route::livewire('groups/{group}', GroupsShow::class)->name('groups.show');
```

(imports : `use App\Livewire\Dashboard;`, `use App\Livewire\Groups\Show as GroupsShow;`). Supprimer `resources/views/dashboard.blade.php`.

- [ ] **Step 4 : Vue du tableau de bord**

`resources/views/livewire/dashboard.blade.php` :

```blade
<div class="mx-auto flex w-full max-w-4xl flex-col gap-10">
    @if ($this->pendingMeetings->isNotEmpty())
        <section class="flex flex-col gap-4">
            <flux:heading size="lg" level="2">{{ __('Waiting for your answer') }}</flux:heading>
            <ul class="flex flex-col gap-3">
                @foreach ($this->pendingMeetings as $meeting)
                    <li wire:key="pending-{{ $meeting->id }}" class="flex flex-wrap items-center justify-between gap-4 rounded-2xl bg-sun/40 px-5 py-4 dark:bg-sun/10">
                        <div>
                            <p class="font-semibold">{{ $meeting->title }}</p>
                            <p class="text-sm text-zinc-600 dark:text-zinc-300">
                                {{ $meeting->group->name }} · {{ trans_choice(':count date|:count dates', $meeting->slots->count()) }}
                            </p>
                        </div>
                        <flux:button variant="primary" :href="route('meetings.show', $meeting)" wire:navigate>{{ __('Answer') }}</flux:button>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    <section class="flex flex-col gap-4">
        <div class="flex items-center justify-between gap-4">
            <flux:heading size="lg" level="2">{{ __('My groups') }}</flux:heading>
            @if ($this->groups->isNotEmpty())
                <flux:modal.trigger name="create-group">
                    <flux:button icon="plus">{{ __('New group') }}</flux:button>
                </flux:modal.trigger>
            @endif
        </div>

        @forelse ($this->groups as $group)
            @php($nextMeeting = $group->meetings->sortBy(fn ($meeting) => $meeting->slots->first()?->starts_at)->first())
            <a wire:key="group-{{ $group->id }}" href="{{ route('groups.show', $group) }}" wire:navigate class="flex flex-wrap items-center justify-between gap-4 rounded-2xl border border-zinc-200 bg-white px-5 py-4 transition hover:border-forest dark:border-white/10 dark:bg-night-raised dark:hover:border-sun">
                <div>
                    <p class="font-semibold">{{ $group->name }}</p>
                    <p class="text-sm text-zinc-600 dark:text-zinc-300">{{ trans_choice(':count member|:count members', $group->members_count) }}</p>
                </div>
                <p class="text-sm text-zinc-600 dark:text-zinc-300">
                    {{ $nextMeeting ? __('Next: :title', ['title' => $nextMeeting->title]) : __('No upcoming meeting') }}
                </p>
            </a>
        @empty
            <div class="flex flex-col items-start gap-4 rounded-2xl bg-sun/40 p-8 dark:bg-sun/10">
                <flux:heading size="lg">{{ __('Start by creating a group') }}</flux:heading>
                <flux:text>{{ __('A group gathers the people you meet regularly: your board, your club, your collective.') }}</flux:text>
                <flux:modal.trigger name="create-group">
                    <flux:button variant="primary" icon="plus">{{ __('Create my first group') }}</flux:button>
                </flux:modal.trigger>
            </div>
        @endforelse
    </section>

    <flux:modal name="create-group" class="max-w-md">
        <form wire:submit="createGroup" class="flex flex-col gap-6">
            <flux:heading size="lg">{{ __('New group') }}</flux:heading>
            <flux:input wire:model="name" :label="__('Group name')" :placeholder="__('e.g. Board of the community garden')" required autofocus />
            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="filled">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button variant="primary" type="submit">{{ __('Create the group') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</div>
```

La route `meetings.show` est créée en Task 8 ; pour que la vue compile dès maintenant, déclarer dès cette tâche dans `routes/web.php` (groupe `auth` + `verified`) : `Route::livewire('meetings/{meeting}', MeetingsShow::class)->name('meetings.show');` et créer `App\Livewire\Meetings\Show` minimal (`mount(Meeting $meeting)` avec `$this->authorize('view', $meeting)`, vue `<div><flux:heading size="xl" level="1">{{ $meeting->title }}</flux:heading></div>`) via `php artisan make:livewire Meetings/Show --class --no-interaction`.

- [ ] **Step 5 : Barre latérale**

Dans `resources/views/layouts/app/sidebar.blade.php`, après le `</flux:sidebar.group>` du groupe « Platform » (dans le même `<flux:sidebar.nav>`) :

```blade
                @if (auth()->user()->groups->isNotEmpty())
                    <flux:sidebar.group :heading="__('My groups')" class="grid">
                        @foreach (auth()->user()->groups as $sidebarGroup)
                            <flux:sidebar.item
                                wire:key="sidebar-group-{{ $sidebarGroup->id }}"
                                icon="user-group"
                                :href="route('groups.show', $sidebarGroup)"
                                :current="request()->is('groups/'.$sidebarGroup->id)"
                                class="data-current:bg-sun! data-current:text-forest! data-current:border-transparent!"
                                wire:navigate
                            >
                                {{ $sidebarGroup->name }}
                            </flux:sidebar.item>
                        @endforeach
                    </flux:sidebar.group>
                @endif
```

- [ ] **Step 6 : Traductions**

Ajouter à `lang/fr.json` :

```json
":count date|:count dates": ":count date|:count dates",
":count member|:count members": ":count membre|:count membres",
"A group gathers the people you meet regularly: your board, your club, your collective.": "Un groupe rassemble les personnes avec qui vous vous réunissez régulièrement : votre bureau, votre club, votre collectif.",
"Answer": "Répondre",
"Cancel": "Annuler",
"Create my first group": "Créer mon premier groupe",
"Create the group": "Créer le groupe",
"e.g. Board of the community garden": "ex. : Bureau du jardin partagé",
"Group name": "Nom du groupe",
"My groups": "Mes groupes",
"New group": "Nouveau groupe",
"Next: :title": "Prochaine : :title",
"No upcoming meeting": "Aucune réunion à venir",
"Start by creating a group": "Commencez par créer un groupe",
"Waiting for your answer": "À vous de répondre"
```

(`"Cancel"` existe déjà : ne pas le dupliquer.)

- [ ] **Step 7 : Lancer les tests**

Run: `php artisan test --compact tests/Feature/DashboardTest.php`
Expected: PASS (6 tests).

- [ ] **Step 8 : Commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Actions/Groups app/Livewire resources/views routes/web.php lang/fr.json tests/Feature/DashboardTest.php
git commit -m "Dashboard with my groups, pending answers and group creation"
```

---

### Task 5 : Page du groupe (membres, renommer, supprimer, quitter)

**Files:**
- Modify: `app/Livewire/Groups/Show.php`, `resources/views/livewire/groups/show.blade.php`, `lang/fr.json`
- Test: `tests/Feature/Groups/GroupPageTest.php`

**Interfaces:**
- Consumes: `Group::removeMember()`, policies `view` / `manage` / `leave`.
- Produces: `Groups\Show` : propriétés `Group $group`, `string $name`, `string $invitationEmails` (utilisée en Task 6) ; actions `rename()`, `deleteGroup()`, `removeMember(int $userId)`, `leave()` ; computed `members`, `upcomingMeetings`, `pastMeetings`.

- [ ] **Step 1 : Écrire les tests qui échouent**

`php artisan make:test --pest Groups/GroupPageTest --no-interaction` :

```php
<?php

use App\Livewire\Groups\Show;
use App\Models\Group;
use App\Models\Meeting;
use App\Models\MeetingSlot;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->group = Group::factory()->create(['name' => 'Jardin partagé']);
    $this->organizer = $this->group->owner;
    $this->member = User::factory()->create(['name' => 'Bastien']);
    $this->group->addMember($this->member);
});

test('members see the group, its members and its meetings', function () {
    $meeting = Meeting::factory()->for($this->group)->create(['title' => 'Assemblée de rentrée']);
    MeetingSlot::factory()->for($meeting)->create(['starts_at' => now()->addWeek()]);

    $this->actingAs($this->member)
        ->get(route('groups.show', $this->group))
        ->assertOk()
        ->assertSee('Jardin partagé')
        ->assertSee('Bastien')
        ->assertSee('Assemblée de rentrée');
});

test('outsiders get a 404', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('groups.show', $this->group))
        ->assertNotFound();
});

test('the organizer renames and deletes the group', function () {
    Livewire::actingAs($this->organizer)
        ->test(Show::class, ['group' => $this->group])
        ->set('name', 'Jardin des Lilas')
        ->call('rename')
        ->assertHasNoErrors();

    expect($this->group->fresh()->name)->toBe('Jardin des Lilas');

    Livewire::actingAs($this->organizer)
        ->test(Show::class, ['group' => $this->group])
        ->call('deleteGroup')
        ->assertRedirect(route('dashboard'));

    expect(Group::count())->toBe(0);
});

test('the organizer removes a member', function () {
    Livewire::actingAs($this->organizer)
        ->test(Show::class, ['group' => $this->group])
        ->call('removeMember', $this->member->id);

    expect($this->group->hasMember($this->member))->toBeFalse();
});

test('the organizer cannot remove themselves', function () {
    Livewire::actingAs($this->organizer)
        ->test(Show::class, ['group' => $this->group])
        ->call('removeMember', $this->organizer->id)
        ->assertForbidden();
});

test('a member cannot manage the group', function (string $action, array $arguments) {
    Livewire::actingAs($this->member)
        ->test(Show::class, ['group' => $this->group])
        ->set('name', 'Piraté')
        ->call($action, ...$arguments)
        ->assertForbidden();
})->with([
    'rename' => ['rename', []],
    'delete' => ['deleteGroup', []],
    'remove a member' => ['removeMember', [1]],
]);

test('a member leaves the group but the organizer cannot', function () {
    Livewire::actingAs($this->member)
        ->test(Show::class, ['group' => $this->group])
        ->call('leave')
        ->assertRedirect(route('dashboard'));

    expect($this->group->hasMember($this->member))->toBeFalse();

    Livewire::actingAs($this->organizer)
        ->test(Show::class, ['group' => $this->group])
        ->call('leave')
        ->assertForbidden();
});
```

- [ ] **Step 2 : Lancer, vérifier l'échec**

Run: `php artisan test --compact tests/Feature/Groups/GroupPageTest.php`
Expected: FAIL (méthodes absentes, contenu non affiché).

- [ ] **Step 3 : Composant**

`app/Livewire/Groups/Show.php` :

```php
<?php

namespace App\Livewire\Groups;

use App\Models\Group;
use App\Models\Meeting;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Component;

class Show extends Component
{
    public Group $group;

    public string $name = '';

    public string $invitationEmails = '';

    public function mount(Group $group): void
    {
        $this->authorize('view', $group);

        $this->group = $group;
        $this->name = $group->name;
    }

    public function rename(): void
    {
        $this->authorize('manage', $this->group);

        $this->validate(['name' => ['required', 'string', 'max:120']]);

        $this->group->update(['name' => $this->name]);

        $this->modal('rename-group')->close();
    }

    public function deleteGroup(): void
    {
        $this->authorize('manage', $this->group);

        $this->group->delete();

        $this->redirectRoute('dashboard', navigate: true);
    }

    public function removeMember(int $userId): void
    {
        $this->authorize('manage', $this->group);

        $member = $this->group->members()->findOrFail($userId);

        abort_if($this->group->isOrganizer($member), 403);

        $this->group->removeMember($member);
    }

    public function leave(): void
    {
        $this->authorize('leave', $this->group);

        $this->group->removeMember(Auth::user());

        $this->redirectRoute('dashboard', navigate: true);
    }

    public function isOrganizer(): bool
    {
        return $this->group->isOrganizer(Auth::user());
    }

    /**
     * @return Collection<int, User>
     */
    #[Computed]
    public function members(): Collection
    {
        return $this->group->members()->orderBy('name')->get();
    }

    /**
     * @return Collection<int, Meeting>
     */
    #[Computed]
    public function upcomingMeetings(): Collection
    {
        return $this->group->meetings()->upcoming()->with('slots.availabilities')->get();
    }

    /**
     * @return Collection<int, Meeting>
     */
    #[Computed]
    public function pastMeetings(): Collection
    {
        return $this->group->meetings()->past()->with('slots')->latest()->get();
    }
}
```

(`removeMember` avec un id inexistant pour un membre non organisateur : l'`authorize` passe avant et renvoie 403, ce qui satisfait le test « remove a member ».)

- [ ] **Step 4 : Vue**

`resources/views/livewire/groups/show.blade.php` :

```blade
<div class="mx-auto flex w-full max-w-4xl flex-col gap-10">
    <header class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1" class="text-3xl! font-extrabold!">{{ $group->name }}</flux:heading>
            <flux:text>{{ trans_choice(':count member|:count members', $this->members->count()) }}</flux:text>
        </div>

        <div class="flex flex-wrap gap-2">
            @if ($this->isOrganizer())
                <flux:button variant="primary" icon="plus" :href="route('meetings.create', $group)" wire:navigate>{{ __('New meeting') }}</flux:button>
                <flux:dropdown align="end">
                    <flux:button icon="ellipsis-horizontal" :aria-label="__('Group actions')" />
                    <flux:menu>
                        <flux:modal.trigger name="rename-group">
                            <flux:menu.item icon="pencil">{{ __('Rename') }}</flux:menu.item>
                        </flux:modal.trigger>
                        <flux:menu.item icon="trash" variant="danger" wire:click="deleteGroup" wire:confirm="{{ __('Delete this group, its meetings and all answers?') }}">{{ __('Delete the group') }}</flux:menu.item>
                    </flux:menu>
                </flux:dropdown>
            @else
                <flux:button icon="arrow-right-start-on-rectangle" wire:click="leave" wire:confirm="{{ __('Leave this group?') }}">{{ __('Leave the group') }}</flux:button>
            @endif
        </div>
    </header>

    <section class="flex flex-col gap-4">
        <flux:heading size="lg" level="2">{{ __('Upcoming meetings') }}</flux:heading>
        @forelse ($this->upcomingMeetings as $meeting)
            @php($answers = $meeting->slots->flatMap->availabilities->pluck('user_id')->unique()->count())
            <a wire:key="meeting-{{ $meeting->id }}" href="{{ route('meetings.show', $meeting) }}" wire:navigate class="flex flex-wrap items-center justify-between gap-4 rounded-2xl border border-zinc-200 bg-white px-5 py-4 transition hover:border-forest dark:border-white/10 dark:bg-night-raised dark:hover:border-sun">
                <p class="font-semibold">{{ $meeting->title }}</p>
                <p class="text-sm text-zinc-600 dark:text-zinc-300">{{ trans_choice(':count answer|:count answers', $answers) }}</p>
            </a>
        @empty
            <flux:text>{{ __('No upcoming meeting') }}</flux:text>
        @endforelse

        @if ($this->pastMeetings->isNotEmpty())
            <details class="rounded-2xl border border-zinc-200 px-5 py-3 dark:border-white/10">
                <summary class="cursor-pointer font-semibold">{{ __('Past meetings') }}</summary>
                <ul class="mt-3 flex flex-col gap-2">
                    @foreach ($this->pastMeetings as $meeting)
                        <li wire:key="past-{{ $meeting->id }}"><flux:link :href="route('meetings.show', $meeting)" wire:navigate>{{ $meeting->title }}</flux:link></li>
                    @endforeach
                </ul>
            </details>
        @endif
    </section>

    <section class="flex flex-col gap-4">
        <flux:heading size="lg" level="2">{{ __('Members') }}</flux:heading>
        <ul class="divide-y divide-zinc-200 rounded-2xl border border-zinc-200 bg-white dark:divide-white/10 dark:border-white/10 dark:bg-night-raised">
            @foreach ($this->members as $member)
                <li wire:key="member-{{ $member->id }}" class="flex items-center justify-between gap-4 px-5 py-3">
                    <div class="flex items-center gap-3">
                        <flux:avatar :name="$member->name" :initials="$member->initials()" size="sm" />
                        <span class="font-medium">{{ $member->name }}</span>
                        @if ($group->isOrganizer($member))
                            <flux:badge size="sm" color="yellow">{{ __('Organizer') }}</flux:badge>
                        @endif
                    </div>
                    @if ($this->isOrganizer() && ! $group->isOrganizer($member))
                        <flux:button size="sm" variant="ghost" icon="x-mark" wire:click="removeMember({{ $member->id }})" wire:confirm="{{ __('Remove :name from the group?', ['name' => $member->name]) }}" :aria-label="__('Remove :name from the group?', ['name' => $member->name])" />
                    @endif
                </li>
            @endforeach
        </ul>
    </section>

    @if ($this->isOrganizer())
        <flux:modal name="rename-group" class="max-w-md">
            <form wire:submit="rename" class="flex flex-col gap-6">
                <flux:heading size="lg">{{ __('Rename the group') }}</flux:heading>
                <flux:input wire:model="name" :label="__('Group name')" required />
                <div class="flex justify-end gap-2">
                    <flux:modal.close><flux:button variant="filled">{{ __('Cancel') }}</flux:button></flux:modal.close>
                    <flux:button variant="primary" type="submit">{{ __('Save') }}</flux:button>
                </div>
            </form>
        </flux:modal>
    @endif
</div>
```

La route `meetings.create` est créée en Task 7 ; pour que la vue compile maintenant, déclarer dès cette tâche (groupe `auth` + `verified`) : `Route::livewire('groups/{group}/meetings/create', MeetingsForm::class)->name('meetings.create');` et créer `App\Livewire\Meetings\Form` minimal via `php artisan make:livewire Meetings/Form --class --no-interaction` (`mount(?Group $group = null)` avec `$this->authorize('manage', $group)` ; vue `<div></div>`).

- [ ] **Step 5 : Traductions**

```json
":count answer|:count answers": ":count réponse|:count réponses",
"Delete the group": "Supprimer le groupe",
"Delete this group, its meetings and all answers?": "Supprimer ce groupe, ses réunions et toutes les réponses ?",
"Group actions": "Actions du groupe",
"Leave the group": "Quitter le groupe",
"Leave this group?": "Quitter ce groupe ?",
"Members": "Membres",
"New meeting": "Nouvelle réunion",
"Organizer": "Organisateur",
"Past meetings": "Réunions passées",
"Remove :name from the group?": "Retirer :name du groupe ?",
"Rename": "Renommer",
"Rename the group": "Renommer le groupe",
"Upcoming meetings": "Réunions à venir"
```

- [ ] **Step 6 : Lancer les tests**

Run: `php artisan test --compact tests/Feature/Groups`
Expected: PASS.

- [ ] **Step 7 : Commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Livewire resources/views routes/web.php lang/fr.json tests/Feature/Groups
git commit -m "Group page: members, rename, delete and leave"
```

---

### Task 6 : Invitations

**Files:**
- Create: `app/Actions/Groups/InviteToGroup.php`, `app/Actions/Groups/SendGroupInvitation.php`, `app/Notifications/GroupInvitationNotification.php`, `app/Http/Controllers/GroupInvitationController.php`, `resources/views/invitations/show.blade.php`, `resources/views/invitations/invalid.blade.php`
- Modify: `app/Livewire/Groups/Show.php` + vue, `routes/web.php`, `lang/fr.json`
- Test: `tests/Feature/Groups/InvitationTest.php`

**Interfaces:**
- Consumes: `GroupInvitation::findByToken()`, `isUsable()`, scope `pending()`, `Group::addMember()`.
- Produces:
  - `SendGroupInvitation::__invoke(GroupInvitation $invitation): void` — nouveau jeton, `expires_at` = +7 jours, e-mail envoyé.
  - `InviteToGroup::__invoke(Group $group, User $inviter, string $emails): array{invited: list<string>, skipped: list<string>}` — lève `ValidationException` (clé `invitationEmails`) si une adresse est invalide ou si la liste est vide.
  - Routes : `invitations.show` (GET `/invitations/{token}`, sans middleware d'auth), `invitations.accept` (POST `/invitations/{token}`, `auth`).
  - `Groups\Show` : actions `invite()`, `resendInvitation(int $invitationId)`, `cancelInvitation(int $invitationId)`, computed `pendingInvitations`.

- [ ] **Step 1 : Écrire les tests qui échouent**

`php artisan make:test --pest Groups/InvitationTest --no-interaction` :

```php
<?php

use App\Livewire\Groups\Show;
use App\Models\Group;
use App\Models\GroupInvitation;
use App\Models\User;
use App\Notifications\GroupInvitationNotification;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

beforeEach(function () {
    Notification::fake();
    $this->group = Group::factory()->create(['name' => 'Jardin partagé']);
    $this->organizer = $this->group->owner;
});

/**
 * Invite an address and return the plain token that was emailed.
 */
function inviteAndGetToken(Group $group, string $email): string
{
    Livewire::actingAs($group->owner)
        ->test(Show::class, ['group' => $group])
        ->set('invitationEmails', $email)
        ->call('invite');

    $token = null;
    Notification::assertSentOnDemand(GroupInvitationNotification::class, function (GroupInvitationNotification $notification, array $channels, AnonymousNotifiable $notifiable) use ($email, &$token) {
        if ($notifiable->routes['mail'] === $email) {
            $token = $notification->token;
        }

        return true;
    });

    return $token;
}

test('the organizer invites several addresses at once', function () {
    Livewire::actingAs($this->organizer)
        ->test(Show::class, ['group' => $this->group])
        ->set('invitationEmails', "amina@example.com, bastien@example.com\nchloe@example.com")
        ->call('invite')
        ->assertHasNoErrors();

    expect(GroupInvitation::pluck('email')->sort()->values()->all())
        ->toBe(['amina@example.com', 'bastien@example.com', 'chloe@example.com']);
    Notification::assertSentOnDemandTimes(GroupInvitationNotification::class, 3);
});

test('duplicates, letter case, existing members and pending invitations are not invited twice', function () {
    $member = User::factory()->create(['email' => 'member@example.com']);
    $this->group->addMember($member);
    GroupInvitation::factory()->for($this->group)->create(['email' => 'pending@example.com']);

    Livewire::actingAs($this->organizer)
        ->test(Show::class, ['group' => $this->group])
        ->set('invitationEmails', 'Amina@Example.com, amina@example.com, MEMBER@example.com, pending@example.com')
        ->call('invite');

    expect(GroupInvitation::where('email', 'amina@example.com')->count())->toBe(1)
        ->and(GroupInvitation::count())->toBe(2);
    Notification::assertSentOnDemandTimes(GroupInvitationNotification::class, 1);
});

test('invalid addresses are refused', function () {
    Livewire::actingAs($this->organizer)
        ->test(Show::class, ['group' => $this->group])
        ->set('invitationEmails', 'amina@example.com, not-an-email')
        ->call('invite')
        ->assertHasErrors('invitationEmails');

    expect(GroupInvitation::count())->toBe(0);
});

test('a member cannot invite', function () {
    $member = User::factory()->create();
    $this->group->addMember($member);

    Livewire::actingAs($member)
        ->test(Show::class, ['group' => $this->group])
        ->set('invitationEmails', 'amina@example.com')
        ->call('invite')
        ->assertForbidden();
});

test('a logged in user accepts a valid invitation and joins the group', function () {
    $token = inviteAndGetToken($this->group, 'amina@example.com');
    $amina = User::factory()->create(['email' => 'amina.perso@example.com']);

    $this->actingAs($amina)->get(route('invitations.show', $token))
        ->assertOk()
        ->assertSee('Jardin partagé');

    $this->actingAs($amina)->post(route('invitations.accept', $token))
        ->assertRedirect(route('groups.show', $this->group));

    expect($this->group->hasMember($amina))->toBeTrue()
        ->and(GroupInvitation::sole()->accepted_by)->toBe($amina->id);

    $this->actingAs(User::factory()->create())->post(route('invitations.accept', $token))
        ->assertOk()
        ->assertSee(__('This invitation is no longer valid'));
});

test('an existing member opening the link is sent to the group without duplicate', function () {
    $token = inviteAndGetToken($this->group, 'amina@example.com');

    $this->actingAs($this->organizer)->post(route('invitations.accept', $token))
        ->assertRedirect(route('groups.show', $this->group));

    expect($this->group->members()->count())->toBe(1)
        ->and(GroupInvitation::sole()->accepted_at)->not->toBeNull();
});

test('expired, cancelled or unknown links do not let anyone join', function () {
    $token = inviteAndGetToken($this->group, 'amina@example.com');
    GroupInvitation::query()->update(['expires_at' => now()->subMinute()]);
    $user = User::factory()->create();

    $this->actingAs($user)->post(route('invitations.accept', $token))->assertSee(__('This invitation is no longer valid'));
    $this->actingAs($user)->get(route('invitations.show', 'made-up-token'))->assertSee(__('This invitation is no longer valid'));

    expect($this->group->hasMember($user))->toBeFalse();
});

test('a guest opening the link is asked to log in and comes back to the invitation', function () {
    $token = inviteAndGetToken($this->group, 'amina@example.com');
    auth()->logout();

    $this->get(route('invitations.show', $token))
        ->assertOk()
        ->assertSee(route('login'), escape: false)
        ->assertSee(route('auth.google.redirect'), escape: false);

    $amina = User::factory()->create(['password' => 'password']);
    $this->post(route('login.store'), ['email' => $amina->email, 'password' => 'password'])
        ->assertRedirect(route('invitations.show', $token));
});

test('the organizer resends and cancels an invitation', function () {
    $oldToken = inviteAndGetToken($this->group, 'amina@example.com');
    $invitation = GroupInvitation::sole();

    Livewire::actingAs($this->organizer)
        ->test(Show::class, ['group' => $this->group])
        ->call('resendInvitation', $invitation->id);

    expect(GroupInvitation::findByToken($oldToken))->toBeNull();
    Notification::assertSentOnDemandTimes(GroupInvitationNotification::class, 2);

    Livewire::actingAs($this->organizer)
        ->test(Show::class, ['group' => $this->group])
        ->call('cancelInvitation', $invitation->id);

    expect(GroupInvitation::count())->toBe(0);
});

test('the invitation email names the group, the inviter and the expiry', function () {
    $token = inviteAndGetToken($this->group, 'amina@example.com');
    $notification = new GroupInvitationNotification(GroupInvitation::sole(), $token);

    $mail = $notification->toMail(new AnonymousNotifiable);

    expect($mail->subject)->toContain('Jardin partagé')
        ->and($mail->actionUrl)->toBe(route('invitations.show', $token))
        ->and(implode(' ', $mail->introLines))->toContain($this->organizer->name);
});
```

- [ ] **Step 2 : Lancer, vérifier l'échec**

Run: `php artisan test --compact tests/Feature/Groups/InvitationTest.php`
Expected: FAIL (classes et routes absentes).

- [ ] **Step 3 : Notification**

`php artisan make:notification GroupInvitationNotification --no-interaction` :

```php
<?php

namespace App\Notifications;

use App\Models\GroupInvitation;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class GroupInvitationNotification extends Notification
{
    public function __construct(public GroupInvitation $invitation, public string $token) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $group = $this->invitation->group;

        return (new MailMessage)
            ->subject(__('Invitation to join :group', ['group' => $group->name]))
            ->line(__(':inviter invites you to join the group :group on :app.', [
                'inviter' => $this->invitation->inviter->name,
                'group' => $group->name,
                'app' => config('app.name'),
            ]))
            ->line(__('You will be able to tell when you are available for the group\'s meetings, on site or remotely.'))
            ->action(__('Join the group'), route('invitations.show', $this->token))
            ->line(__('This invitation expires on :date.', [
                'date' => $this->invitation->expires_at->setTimezone(config('app.display_timezone'))->translatedFormat('j F Y'),
            ]));
    }
}
```

- [ ] **Step 4 : Actions**

`php artisan make:class Actions/Groups/SendGroupInvitation --no-interaction` :

```php
<?php

namespace App\Actions\Groups;

use App\Models\GroupInvitation;
use App\Notifications\GroupInvitationNotification;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

/**
 * Give the invitation a fresh secret link valid for 7 days and email it.
 */
class SendGroupInvitation
{
    public const int ValidityDays = 7;

    public function __invoke(GroupInvitation $invitation): void
    {
        $token = Str::random(40);

        $invitation->forceFill([
            'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addDays(self::ValidityDays),
        ])->save();

        Notification::route('mail', $invitation->email)
            ->notify(new GroupInvitationNotification($invitation, $token));
    }
}
```

`php artisan make:class Actions/Groups/InviteToGroup --no-interaction` :

```php
<?php

namespace App\Actions\Groups;

use App\Models\Group;
use App\Models\GroupInvitation;
use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class InviteToGroup
{
    public function __construct(private SendGroupInvitation $sendGroupInvitation) {}

    /**
     * @return array{invited: list<string>, skipped: list<string>}
     *
     * @throws ValidationException
     */
    public function __invoke(Group $group, User $inviter, string $emails): array
    {
        $addresses = collect(preg_split('/[\s,;]+/', $emails, flags: PREG_SPLIT_NO_EMPTY))
            ->map(fn (string $email): string => Str::lower($email))
            ->unique()
            ->values();

        $invalid = $addresses->reject(fn (string $email): bool => Validator::make(['email' => $email], ['email' => 'email'])->passes());

        if ($addresses->isEmpty() || $invalid->isNotEmpty()) {
            throw ValidationException::withMessages([
                'invitationEmails' => $addresses->isEmpty()
                    ? __('Enter at least one email address.')
                    : __('These addresses are not valid: :emails', ['emails' => $invalid->implode(', ')]),
            ]);
        }

        $memberEmails = $group->members()->pluck('email')->map(fn (string $email): string => Str::lower($email));
        $pendingEmails = $group->invitations()->pending()->pluck('email');

        [$skipped, $toInvite] = $addresses->partition(fn (string $email): bool => $memberEmails->contains($email) || $pendingEmails->contains($email));

        foreach ($toInvite as $email) {
            $invitation = $group->invitations()->make(['email' => $email]);
            $invitation->forceFill([
                'invited_by' => $inviter->id,
                'token_hash' => hash('sha256', Str::random(40)),
                'expires_at' => now()->addDays(SendGroupInvitation::ValidityDays),
            ])->save();

            ($this->sendGroupInvitation)($invitation);
        }

        return ['invited' => $toInvite->values()->all(), 'skipped' => $skipped->values()->all()];
    }
}
```


- [ ] **Step 5 : Composant groupe**

Dans `app/Livewire/Groups/Show.php`, ajouter (imports `App\Actions\Groups\InviteToGroup`, `App\Actions\Groups\SendGroupInvitation`, `App\Models\GroupInvitation`, `Flux\Flux`) :

```php
    public function invite(InviteToGroup $inviteToGroup): void
    {
        $this->authorize('manage', $this->group);

        $result = $inviteToGroup($this->group, Auth::user(), $this->invitationEmails);

        $this->reset('invitationEmails');
        unset($this->pendingInvitations);

        Flux::toast(variant: 'success', text: trans_choice(':count invitation sent.|:count invitations sent.', count($result['invited'])));

        if ($result['skipped'] !== []) {
            Flux::toast(text: __('Already member or invited: :emails', ['emails' => implode(', ', $result['skipped'])]));
        }
    }

    public function resendInvitation(int $invitationId, SendGroupInvitation $sendGroupInvitation): void
    {
        $this->authorize('manage', $this->group);

        $sendGroupInvitation($this->group->invitations()->whereNull('accepted_at')->findOrFail($invitationId));

        Flux::toast(variant: 'success', text: __('Invitation sent again.'));
    }

    public function cancelInvitation(int $invitationId): void
    {
        $this->authorize('manage', $this->group);

        $this->group->invitations()->whereNull('accepted_at')->findOrFail($invitationId)->delete();

        unset($this->pendingInvitations);
    }

    /**
     * Invitations not accepted yet, expired ones included so they can be resent.
     *
     * @return Collection<int, GroupInvitation>
     */
    #[Computed]
    public function pendingInvitations(): Collection
    {
        return $this->group->invitations()->whereNull('accepted_at')->latest()->get();
    }
```

Dans la vue, dans la section « Membres », après la liste (organisateur seulement) :

```blade
        @if ($this->isOrganizer())
            @if ($this->pendingInvitations->isNotEmpty())
                <ul class="flex flex-col gap-2">
                    @foreach ($this->pendingInvitations as $invitation)
                        <li wire:key="invitation-{{ $invitation->id }}" class="flex flex-wrap items-center justify-between gap-3 rounded-xl bg-zinc-100 px-4 py-2 text-sm dark:bg-white/5">
                            <span>{{ $invitation->email }} · {{ $invitation->isUsable() ? __('Invitation sent') : __('Invitation expired') }}</span>
                            <span class="flex gap-1">
                                <flux:button size="xs" variant="ghost" wire:click="resendInvitation({{ $invitation->id }})">{{ __('Resend') }}</flux:button>
                                <flux:button size="xs" variant="ghost" wire:click="cancelInvitation({{ $invitation->id }})">{{ __('Cancel') }}</flux:button>
                            </span>
                        </li>
                    @endforeach
                </ul>
            @endif

            <form wire:submit="invite" class="flex flex-col gap-3">
                <flux:textarea wire:model="invitationEmails" :label="__('Invite people')" :description="__('One or more email addresses, separated by commas or new lines.')" rows="3" placeholder="amina@example.com, bastien@example.com" />
                <div><flux:button type="submit" variant="primary" icon="paper-airplane">{{ __('Send the invitations') }}</flux:button></div>
            </form>
        @endif
```

- [ ] **Step 6 : Contrôleur, vues et routes**

`php artisan make:controller GroupInvitationController --no-interaction` :

```php
<?php

namespace App\Http\Controllers;

use App\Models\GroupInvitation;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class GroupInvitationController extends Controller
{
    public function show(Request $request, string $token): View
    {
        $invitation = GroupInvitation::findByToken($token);

        if (! $invitation?->isUsable()) {
            return view('invitations.invalid');
        }

        if (! $request->user()) {
            redirect()->setIntendedUrl(route('invitations.show', $token));
        }

        return view('invitations.show', ['invitation' => $invitation, 'token' => $token]);
    }

    public function accept(Request $request, string $token): View|RedirectResponse
    {
        $invitation = GroupInvitation::findByToken($token);

        if (! $invitation?->isUsable()) {
            return view('invitations.invalid');
        }

        if (! $invitation->group->hasMember($request->user())) {
            $invitation->group->addMember($request->user());
        }

        $invitation->forceFill([
            'accepted_at' => now(),
            'accepted_by' => $request->user()->id,
        ])->save();

        return redirect()->route('groups.show', $invitation->group);
    }
}
```

(La vérification `hasMember` évite qu'un organisateur qui ouvre le lien soit rétrogradé en membre : `addMember` met à jour le pivot.)

`resources/views/invitations/show.blade.php` :

```blade
<x-layouts::auth :title="__('Invitation to join :group', ['group' => $invitation->group->name])">
    <div class="flex flex-col gap-6">
        <x-auth-header
            :title="__('Join :group', ['group' => $invitation->group->name])"
            :description="__(':inviter invites you to join this group to plan its meetings together.', ['inviter' => $invitation->inviter->name])"
        />

        @auth
            <form method="POST" action="{{ route('invitations.accept', $token) }}">
                @csrf
                <flux:button variant="primary" type="submit" class="w-full">{{ __('Join the group') }}</flux:button>
            </form>
        @else
            <x-google-button :href="route('auth.google.redirect')" :label="__('Continue with Google')" />
            <flux:button :href="route('login')" class="w-full">{{ __('Log in') }}</flux:button>
            @if (Route::has('register'))
                <flux:button variant="primary" :href="route('register')" class="w-full">{{ __('Create an account') }}</flux:button>
            @endif
        @endauth
    </div>
</x-layouts::auth>
```

`resources/views/invitations/invalid.blade.php` :

```blade
<x-layouts::auth :title="__('This invitation is no longer valid')">
    <div class="flex flex-col gap-6">
        <x-auth-header
            :title="__('This invitation is no longer valid')"
            :description="__('The link has expired, was already used or was cancelled. Ask the organizer of the group to send you a new invitation.')"
        />
        <flux:button :href="route('home')" class="w-full">{{ __('Back to home') }}</flux:button>
    </div>
</x-layouts::auth>
```

Dans `routes/web.php` (hors groupes `auth`) :

```php
Route::get('invitations/{token}', [GroupInvitationController::class, 'show'])->name('invitations.show');
Route::post('invitations/{token}', [GroupInvitationController::class, 'accept'])->middleware('auth')->name('invitations.accept');
```

- [ ] **Step 7 : Traductions**

```json
":count invitation sent.|:count invitations sent.": ":count invitation envoyée.|:count invitations envoyées.",
":inviter invites you to join the group :group on :app.": ":inviter vous invite à rejoindre le groupe :group sur :app.",
":inviter invites you to join this group to plan its meetings together.": ":inviter vous invite à rejoindre ce groupe pour organiser ensemble ses réunions.",
"Already member or invited: :emails": "Déjà membre ou déjà invité : :emails",
"Back to home": "Retour à l'accueil",
"Enter at least one email address.": "Saisissez au moins une adresse e-mail.",
"Invitation expired": "Invitation expirée",
"Invitation sent": "Invitation envoyée",
"Invitation sent again.": "Invitation renvoyée.",
"Invitation to join :group": "Invitation à rejoindre :group",
"Invite people": "Inviter des personnes",
"Join :group": "Rejoindre :group",
"Join the group": "Rejoindre le groupe",
"One or more email addresses, separated by commas or new lines.": "Une ou plusieurs adresses e-mail, séparées par des virgules ou des retours à la ligne.",
"Resend": "Renvoyer",
"Send the invitations": "Envoyer les invitations",
"The link has expired, was already used or was cancelled. Ask the organizer of the group to send you a new invitation.": "Le lien a expiré, a déjà été utilisé ou a été annulé. Demandez à l'organisateur du groupe de vous envoyer une nouvelle invitation.",
"These addresses are not valid: :emails": "Ces adresses ne sont pas valides : :emails",
"This invitation expires on :date.": "Cette invitation expire le :date.",
"This invitation is no longer valid": "Cette invitation n'est plus valable",
"You will be able to tell when you are available for the group's meetings, on site or remotely.": "Vous pourrez indiquer vos disponibilités pour les réunions du groupe, sur place ou à distance."
```

- [ ] **Step 8 : Lancer les tests**

Run: `php artisan test --compact tests/Feature/Groups`
Expected: PASS.

- [ ] **Step 9 : Commit**

```bash
vendor/bin/pint --dirty --format agent
git add app resources/views routes/web.php lang/fr.json tests/Feature/Groups
git commit -m "Invite people to a group by email"
```

---

### Task 7 : Créer et modifier une réunion

**Files:**
- Modify: `app/Livewire/Meetings/Form.php`, `resources/views/livewire/meetings/form.blade.php`, `routes/web.php`, `lang/fr.json`
- Test: `tests/Feature/Meetings/MeetingFormTest.php`

**Interfaces:**
- Consumes: `MeetingPolicy::manage`, `GroupPolicy::manage`, `MeetingSlot::startsAtLocal()`.
- Produces: routes `meetings.create` (GET `/groups/{group}/meetings/create`), `meetings.edit` (GET `/meetings/{meeting}/edit`) ; composant `Meetings\Form` : propriétés `title`, `description`, `location`, `array $slots` (liste de `array{id: int|null, starts_at: string}` au format `Y-m-d\TH:i` dans le fuseau d'affichage) ; actions `addSlot()`, `removeSlot(int $index)`, `save()`, `deleteMeeting()`.

- [ ] **Step 1 : Écrire les tests qui échouent**

`php artisan make:test --pest Meetings/MeetingFormTest --no-interaction` :

```php
<?php

use App\Livewire\Meetings\Form;
use App\Models\Availability;
use App\Models\Group;
use App\Models\Meeting;
use App\Models\MeetingSlot;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->group = Group::factory()->create();
    $this->organizer = $this->group->owner;
});

test('the organizer creates a meeting with several dates stored in UTC', function () {
    Livewire::actingAs($this->organizer)
        ->test(Form::class, ['group' => $this->group])
        ->set('title', 'Assemblée de rentrée')
        ->set('location', 'Salle des fêtes')
        ->set('slots', [
            ['id' => null, 'starts_at' => '2026-10-14T18:30'],
            ['id' => null, 'starts_at' => '2026-10-16T18:30'],
        ])
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect(route('meetings.show', Meeting::sole()));

    $meeting = Meeting::sole();
    expect($meeting->title)->toBe('Assemblée de rentrée')
        ->and($meeting->slots->map->starts_at->map->format('Y-m-d H:i')->all())->toBe(['2026-10-14 16:30', '2026-10-16 16:30']);
});

test('a meeting needs at least two different dates and a title', function () {
    Livewire::actingAs($this->organizer)
        ->test(Form::class, ['group' => $this->group])
        ->set('title', '')
        ->set('slots', [['id' => null, 'starts_at' => '2026-10-14T18:30']])
        ->call('save')
        ->assertHasErrors(['title', 'slots']);

    Livewire::actingAs($this->organizer)
        ->test(Form::class, ['group' => $this->group])
        ->set('title', 'Doublon')
        ->set('slots', [['id' => null, 'starts_at' => '2026-10-14T18:30'], ['id' => null, 'starts_at' => '2026-10-14T18:30']])
        ->call('save')
        ->assertHasErrors('slots.1.starts_at');

    expect(Meeting::count())->toBe(0);
});

test('a date around the autumn clock change keeps its local time', function () {
    Livewire::actingAs($this->organizer)
        ->test(Form::class, ['group' => $this->group])
        ->set('title', 'Changement d\'heure')
        ->set('slots', [['id' => null, 'starts_at' => '2026-10-24T20:00'], ['id' => null, 'starts_at' => '2026-10-25T20:00']])
        ->call('save');

    expect(MeetingSlot::orderBy('starts_at')->get()->map->startsAtLocal()->map->format('Y-m-d\TH:i')->all())
        ->toBe(['2026-10-24T20:00', '2026-10-25T20:00'])
        ->and(MeetingSlot::orderBy('starts_at')->get()->map->starts_at->map->format('H:i')->all())->toBe(['18:00', '19:00']);
});

test('editing keeps answers of untouched dates and drops answers of removed or moved dates', function () {
    $meeting = Meeting::factory()->for($this->group)->create();
    $kept = MeetingSlot::factory()->for($meeting)->create(['starts_at' => '2026-11-02 17:30:00']);
    $moved = MeetingSlot::factory()->for($meeting)->create(['starts_at' => '2026-11-03 17:30:00']);
    $removed = MeetingSlot::factory()->for($meeting)->create(['starts_at' => '2026-11-04 17:30:00']);
    foreach ([$kept, $moved, $removed] as $slot) {
        Availability::factory()->for($slot, 'slot')->for($this->organizer)->create();
    }

    Livewire::actingAs($this->organizer)
        ->test(Form::class, ['meeting' => $meeting])
        ->assertSet('slots.0.starts_at', '2026-11-02T18:30')
        ->set('slots', [
            ['id' => $kept->id, 'starts_at' => '2026-11-02T18:30'],
            ['id' => $moved->id, 'starts_at' => '2026-11-03T20:00'],
        ])
        ->call('save')
        ->assertHasNoErrors();

    expect(MeetingSlot::count())->toBe(2)
        ->and($kept->availabilities()->count())->toBe(1)
        ->and($moved->availabilities()->count())->toBe(0);
});

test('slot ids of another meeting are ignored when editing', function () {
    $meeting = Meeting::factory()->for($this->group)->create();
    MeetingSlot::factory()->for($meeting)->count(2)->create();
    $foreign = MeetingSlot::factory()->create(['starts_at' => '2026-12-01 10:00:00']);

    Livewire::actingAs($this->organizer)
        ->test(Form::class, ['meeting' => $meeting])
        ->set('slots', [
            ['id' => $foreign->id, 'starts_at' => '2026-12-05T10:00'],
            ['id' => null, 'starts_at' => '2026-12-06T10:00'],
        ])
        ->call('save');

    expect($foreign->fresh()->starts_at->format('Y-m-d H:i'))->toBe('2026-12-01 10:00')
        ->and($meeting->slots()->count())->toBe(2);
});

test('the organizer deletes a meeting', function () {
    $meeting = Meeting::factory()->for($this->group)->create();

    Livewire::actingAs($this->organizer)
        ->test(Form::class, ['meeting' => $meeting])
        ->call('deleteMeeting')
        ->assertRedirect(route('groups.show', $this->group));

    expect(Meeting::count())->toBe(0);
});

test('members cannot create or edit meetings, outsiders get a 404', function () {
    $member = User::factory()->create();
    $this->group->addMember($member);
    $meeting = Meeting::factory()->for($this->group)->create();

    $this->actingAs($member)->get(route('meetings.create', $this->group))->assertForbidden();
    $this->actingAs($member)->get(route('meetings.edit', $meeting))->assertForbidden();
    $this->actingAs(User::factory()->create())->get(route('meetings.create', $this->group))->assertNotFound();
});
```

- [ ] **Step 2 : Lancer, vérifier l'échec**

Run: `php artisan test --compact tests/Feature/Meetings/MeetingFormTest.php`
Expected: FAIL.

- [ ] **Step 3 : Composant**

`app/Livewire/Meetings/Form.php` :

```php
<?php

namespace App\Livewire\Meetings;

use App\Models\Group;
use App\Models\Meeting;
use App\Models\MeetingSlot;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

class Form extends Component
{
    public Group $group;

    public ?Meeting $meeting = null;

    public string $title = '';

    public string $description = '';

    public string $location = '';

    /**
     * @var list<array{id: int|null, starts_at: string}>
     */
    public array $slots = [];

    public function mount(?Group $group = null, ?Meeting $meeting = null): void
    {
        if ($meeting?->exists) {
            $this->authorize('manage', $meeting);

            $this->meeting = $meeting;
            $this->group = $meeting->group;
            $this->title = $meeting->title;
            $this->description = (string) $meeting->description;
            $this->location = (string) $meeting->location;
            $this->slots = $meeting->slots->map(fn (MeetingSlot $slot): array => [
                'id' => $slot->id,
                'starts_at' => $slot->startsAtLocal()->format('Y-m-d\TH:i'),
            ])->all();

            return;
        }

        $this->authorize('manage', $group);

        $this->group = $group;
        $this->slots = [['id' => null, 'starts_at' => ''], ['id' => null, 'starts_at' => '']];
    }

    public function addSlot(): void
    {
        $this->slots[] = ['id' => null, 'starts_at' => ''];
    }

    public function removeSlot(int $index): void
    {
        unset($this->slots[$index]);
        $this->slots = array_values($this->slots);
    }

    public function save(): void
    {
        $this->meeting?->exists
            ? $this->authorize('manage', $this->meeting)
            : $this->authorize('manage', $this->group);

        $this->validate([
            'title' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:2000'],
            'location' => ['nullable', 'string', 'max:255'],
            'slots' => ['array', 'min:2'],
            'slots.*.starts_at' => ['required', 'date_format:Y-m-d\TH:i', 'distinct'],
        ]);

        $meeting = DB::transaction(function (): Meeting {
            $meeting = $this->meeting ?? new Meeting;
            $meeting->fill([
                'title' => $this->title,
                'description' => $this->description ?: null,
                'location' => $this->location ?: null,
            ]);

            if (! $meeting->exists) {
                $meeting->group()->associate($this->group);
                $meeting->creator()->associate(Auth::user());
            }

            $meeting->save();
            $this->syncSlots($meeting);

            return $meeting;
        });

        $this->redirectRoute('meetings.show', $meeting, navigate: true);
    }

    public function deleteMeeting(): void
    {
        $this->authorize('manage', $this->meeting);

        $this->meeting->delete();

        $this->redirectRoute('groups.show', $this->group, navigate: true);
    }

    /**
     * Keep untouched dates and their answers; a moved date loses its answers, a removed one is deleted.
     */
    private function syncSlots(Meeting $meeting): void
    {
        $existing = $meeting->slots()->get()->keyBy('id');
        $requestedIds = collect($this->slots)->pluck('id')->filter()->intersect($existing->keys());

        $meeting->slots()->whereNotIn('id', $requestedIds)->delete();
        $existing = $existing->only($requestedIds->all());

        foreach ($this->slots as $slot) {
            $startsAt = Carbon::createFromFormat('Y-m-d\TH:i', $slot['starts_at'], config('app.display_timezone'))->utc();
            $current = $slot['id'] !== null ? $existing->get($slot['id']) : null;

            if ($current === null) {
                $meeting->slots()->create(['starts_at' => $startsAt]);

                continue;
            }

            if (! $current->starts_at->equalTo($startsAt)) {
                $current->availabilities()->delete();
                $current->update(['starts_at' => $startsAt]);
            }
        }
    }
}
```

(Les dates retirées sont supprimées avant les mises à jour, pour ne pas heurter la contrainte unique (`meeting_id`, `starts_at`) quand une nouvelle date reprend l'heure d'une date retirée. Un id de date d'une autre réunion n'est jamais dans `$existing` : il est traité comme une nouvelle date.)

- [ ] **Step 4 : Vue**

`resources/views/livewire/meetings/form.blade.php` :

```blade
<div class="mx-auto flex w-full max-w-2xl flex-col gap-8">
    <div>
        <flux:link :href="route('groups.show', $group)" wire:navigate class="text-sm">← {{ $group->name }}</flux:link>
        <flux:heading size="xl" level="1" class="mt-2 text-3xl! font-extrabold!">{{ $meeting ? __('Edit the meeting') : __('New meeting') }}</flux:heading>
    </div>

    <form wire:submit="save" class="flex flex-col gap-6">
        <flux:input wire:model="title" :label="__('Title')" :placeholder="__('e.g. Back-to-school general meeting')" required />
        <flux:input wire:model="location" :label="__('Location')" :description="__('Optional')" />
        <flux:textarea wire:model="description" :label="__('Description')" :description="__('Optional')" rows="3" />

        <flux:fieldset>
            <flux:legend>{{ __('Possible dates') }}</flux:legend>
            <flux:description>{{ __('Propose at least two dates. Times are in :timezone time.', ['timezone' => 'Paris']) }}</flux:description>

            <div class="mt-4 flex flex-col gap-3">
                @foreach ($slots as $index => $slot)
                    <div wire:key="slot-{{ $slot['id'] ?? 'new-'.$index }}" class="flex items-start gap-2">
                        <div class="flex-1">
                            <flux:input type="datetime-local" wire:model="slots.{{ $index }}.starts_at" :aria-label="__('Date :number', ['number' => $index + 1])" required />
                            <flux:error name="slots.{{ $index }}.starts_at" />
                        </div>
                        @if (count($slots) > 2)
                            <flux:button variant="ghost" icon="x-mark" wire:click="removeSlot({{ $index }})"
                                @if ($slot['id']) wire:confirm="{{ __('Remove this date? Answers given for it will be deleted.') }}" @endif
                                :aria-label="__('Remove this date')" />
                        @endif
                    </div>
                @endforeach
            </div>
            <flux:error name="slots" />

            <flux:button class="mt-3" icon="plus" wire:click="addSlot">{{ __('Add a date') }}</flux:button>
        </flux:fieldset>

        <div class="flex flex-wrap justify-between gap-3">
            @if ($meeting)
                <flux:button variant="danger" wire:click="deleteMeeting" wire:confirm="{{ __('Delete this meeting and all its answers?') }}">{{ __('Delete the meeting') }}</flux:button>
            @endif
            <flux:button variant="primary" type="submit" class="ms-auto">{{ __('Save the meeting') }}</flux:button>
        </div>
    </form>
</div>
```

- [ ] **Step 5 : Routes**

Dans le groupe `auth` + `verified` de `routes/web.php` (la route `meetings.create` existe déjà depuis la tâche 5) :

```php
    Route::livewire('meetings/{meeting}/edit', MeetingsForm::class)->name('meetings.edit');
```

- [ ] **Step 6 : Traductions**

```json
"Add a date": "Ajouter une date",
"Date :number": "Date :number",
"Delete the meeting": "Supprimer la réunion",
"Delete this meeting and all its answers?": "Supprimer cette réunion et toutes ses réponses ?",
"Description": "Description",
"e.g. Back-to-school general meeting": "ex. : Assemblée de rentrée",
"Edit the meeting": "Modifier la réunion",
"Location": "Lieu",
"Optional": "Facultatif",
"Possible dates": "Dates proposées",
"Propose at least two dates. Times are in :timezone time.": "Proposez au moins deux dates. Les heures sont à l'heure de :timezone.",
"Remove this date": "Retirer cette date",
"Remove this date? Answers given for it will be deleted.": "Retirer cette date ? Les réponses données pour elle seront supprimées.",
"Save the meeting": "Enregistrer la réunion",
"Title": "Titre"
```

- [ ] **Step 7 : Lancer les tests**

Run: `php artisan test --compact tests/Feature/Meetings`
Expected: PASS.

- [ ] **Step 8 : Commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Livewire/Meetings resources/views/livewire/meetings routes/web.php lang/fr.json tests/Feature/Meetings
git commit -m "Create and edit meetings with candidate dates"
```

---

### Task 8 : Répondre et voir la meilleure date

**Files:**
- Modify: `app/Livewire/Meetings/Show.php`, `resources/views/livewire/meetings/show.blade.php`, `lang/fr.json`
- Test: `tests/Feature/Meetings/MeetingPageTest.php`

**Interfaces:**
- Consumes: `FindBestSlot` (Task 2), `AvailabilityStatus`, `MeetingPolicy` (`view`, `respond`, `manage`).
- Produces: `Meetings\Show` : `array $responses` (`slot_id => status value`) ; action `save()` ; computed `slots` (avec réponses), `tallies` (`array<int slotId, array{onSite: int, remote: int, startsAt: Carbon}>`), `bestSlotId` (`?int`), `members`, `nonRespondents`.

- [ ] **Step 1 : Écrire les tests qui échouent**

`php artisan make:test --pest Meetings/MeetingPageTest --no-interaction` :

```php
<?php

use App\Enums\AvailabilityStatus;
use App\Livewire\Meetings\Show;
use App\Models\Availability;
use App\Models\Group;
use App\Models\Meeting;
use App\Models\MeetingSlot;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->group = Group::factory()->create();
    $this->organizer = $this->group->owner;
    $this->member = User::factory()->create(['name' => 'Bastien']);
    $this->group->addMember($this->member);
    $this->meeting = Meeting::factory()->for($this->group)->create(['title' => 'Assemblée de rentrée']);
    $this->first = MeetingSlot::factory()->for($this->meeting)->create(['starts_at' => now()->addDays(3)]);
    $this->second = MeetingSlot::factory()->for($this->meeting)->create(['starts_at' => now()->addDays(5)]);
});

test('a member saves then changes their answer', function () {
    Livewire::actingAs($this->member)
        ->test(Show::class, ['meeting' => $this->meeting])
        ->set("responses.{$this->first->id}", 'on_site')
        ->set("responses.{$this->second->id}", 'unavailable')
        ->call('save')
        ->assertHasNoErrors();

    Livewire::actingAs($this->member)
        ->test(Show::class, ['meeting' => $this->meeting])
        ->assertSet("responses.{$this->first->id}", 'on_site')
        ->set("responses.{$this->first->id}", 'remote')
        ->call('save');

    expect(Availability::where('user_id', $this->member->id)->count())->toBe(2)
        ->and($this->first->availabilities()->sole()->status)->toBe(AvailabilityStatus::Remote);
});

test('every date must be answered', function () {
    Livewire::actingAs($this->member)
        ->test(Show::class, ['meeting' => $this->meeting])
        ->set("responses.{$this->first->id}", 'on_site')
        ->call('save')
        ->assertHasErrors("responses.{$this->second->id}");

    expect(Availability::count())->toBe(0);
});

test('answers for dates of another meeting are ignored', function () {
    $foreign = MeetingSlot::factory()->create();

    Livewire::actingAs($this->member)
        ->test(Show::class, ['meeting' => $this->meeting])
        ->set("responses.{$this->first->id}", 'on_site')
        ->set("responses.{$this->second->id}", 'on_site')
        ->set("responses.{$foreign->id}", 'on_site')
        ->call('save');

    expect($foreign->availabilities()->count())->toBe(0)
        ->and(Availability::count())->toBe(2);
});

test('the page shows the answers, the best date and, for the organizer, who has not answered', function () {
    Availability::factory()->for($this->second, 'slot')->for($this->member)->create(['status' => AvailabilityStatus::OnSite]);
    Availability::factory()->for($this->first, 'slot')->for($this->member)->create(['status' => AvailabilityStatus::Unavailable]);

    $component = Livewire::actingAs($this->organizer)->test(Show::class, ['meeting' => $this->meeting]);

    expect($component->instance()->bestSlotId)->toBe($this->second->id)
        ->and($component->instance()->nonRespondents->pluck('id')->all())->toBe([$this->organizer->id]);

    $this->actingAs($this->organizer)->get(route('meetings.show', $this->meeting))
        ->assertSee('Assemblée de rentrée')
        ->assertSee('Bastien')
        ->assertSee(__('Not answered yet'));

    $this->actingAs($this->member)->get(route('meetings.show', $this->meeting))
        ->assertDontSee(__('Not answered yet'));
});

test('without any attendee there is no best date yet', function () {
    $this->actingAs($this->member)->get(route('meetings.show', $this->meeting))
        ->assertSee(__('Waiting for answers'));
});

test('outsiders get a 404 and cannot answer', function () {
    $outsider = User::factory()->create();

    $this->actingAs($outsider)->get(route('meetings.show', $this->meeting))->assertNotFound();
});
```

- [ ] **Step 2 : Lancer, vérifier l'échec**

Run: `php artisan test --compact tests/Feature/Meetings/MeetingPageTest.php`
Expected: FAIL.

- [ ] **Step 3 : Composant**

`app/Livewire/Meetings/Show.php` :

```php
<?php

namespace App\Livewire\Meetings;

use App\Actions\Meetings\FindBestSlot;
use App\Enums\AvailabilityStatus;
use App\Models\Availability;
use App\Models\Meeting;
use App\Models\MeetingSlot;
use App\Models\User;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Component;

class Show extends Component
{
    public Meeting $meeting;

    /**
     * @var array<int, string>
     */
    public array $responses = [];

    public function mount(Meeting $meeting): void
    {
        $this->authorize('view', $meeting);

        $this->meeting = $meeting;
        $this->responses = $this->slots
            ->mapWithKeys(fn (MeetingSlot $slot): array => [$slot->id => (string) $slot->availabilities->firstWhere('user_id', Auth::id())?->status->value])
            ->filter()
            ->all();
    }

    public function save(): void
    {
        $this->authorize('respond', $this->meeting);

        $slotIds = $this->slots->pluck('id');

        $this->validate($slotIds->mapWithKeys(fn (int $id): array => [
            "responses.{$id}" => ['required', Rule::enum(AvailabilityStatus::class)],
        ])->all(), [], $slotIds->mapWithKeys(fn (int $id, int $index): array => [
            "responses.{$id}" => __('Date :number', ['number' => $index + 1]),
        ])->all());

        foreach ($slotIds as $slotId) {
            Availability::updateOrCreate(
                ['meeting_slot_id' => $slotId, 'user_id' => Auth::id()],
                ['status' => $this->responses[$slotId]],
            );
        }

        unset($this->slots, $this->tallies, $this->bestSlotId, $this->nonRespondents);

        Flux::toast(variant: 'success', text: __('Your answer has been saved.'));
    }

    public function isOrganizer(): bool
    {
        return $this->meeting->group->isOrganizer(Auth::user());
    }

    /**
     * @return Collection<int, MeetingSlot>
     */
    #[Computed]
    public function slots(): Collection
    {
        return $this->meeting->slots()->with('availabilities')->get();
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
     * @return array<int, array{onSite: int, remote: int, startsAt: \Carbon\CarbonInterface}>
     */
    #[Computed]
    public function tallies(): array
    {
        return $this->slots->mapWithKeys(fn (MeetingSlot $slot): array => [$slot->id => [
            'onSite' => $slot->availabilities->where('status', AvailabilityStatus::OnSite)->count(),
            'remote' => $slot->availabilities->where('status', AvailabilityStatus::Remote)->count(),
            'startsAt' => $slot->starts_at,
        ]])->all();
    }

    #[Computed]
    public function bestSlotId(): ?int
    {
        return (new FindBestSlot)($this->tallies);
    }

    /**
     * @return Collection<int, User>
     */
    #[Computed]
    public function nonRespondents(): Collection
    {
        $respondentIds = $this->slots->flatMap->availabilities->pluck('user_id')->unique();

        return $this->members->reject(fn (User $member): bool => $respondentIds->contains($member->id))->values();
    }
}
```

- [ ] **Step 4 : Vue**

`resources/views/livewire/meetings/show.blade.php` (reprend le rendu du sondage de l'accueil) :

```blade
<div class="mx-auto flex w-full max-w-4xl flex-col gap-10">
    <header class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <flux:link :href="route('groups.show', $meeting->group)" wire:navigate class="text-sm">← {{ $meeting->group->name }}</flux:link>
            <flux:heading size="xl" level="1" class="mt-2 text-3xl! font-extrabold!">{{ $meeting->title }}</flux:heading>
            @if ($meeting->location)
                <flux:text class="mt-1 flex items-center gap-1"><flux:icon.map-pin variant="micro" /> {{ $meeting->location }}</flux:text>
            @endif
            @if ($meeting->description)
                <flux:text class="mt-2 max-w-prose">{{ $meeting->description }}</flux:text>
            @endif
        </div>
        @if ($this->isOrganizer())
            <flux:button icon="pencil" :href="route('meetings.edit', $meeting)" wire:navigate>{{ __('Edit') }}</flux:button>
        @endif
    </header>

    @php($best = $this->bestSlotId ? $this->slots->firstWhere('id', $this->bestSlotId) : null)
    <section @class(['rounded-2xl px-6 py-5', 'bg-sun text-forest' => $best, 'bg-zinc-100 dark:bg-white/5' => ! $best])>
        @if ($best)
            @php($tally = $this->tallies[$best->id])
            <p class="text-sm font-semibold uppercase tracking-wide opacity-70">{{ __('Best date so far') }}</p>
            <p class="text-2xl font-extrabold">{{ ucfirst($best->startsAtLocal()->translatedFormat('l j F · H\hi')) }}</p>
            <p>{{ __(':total present · :onSite on site, :remote remotely', ['total' => $tally['onSite'] + $tally['remote'], 'onSite' => $tally['onSite'], 'remote' => $tally['remote']]) }}</p>
        @else
            <p class="font-semibold">{{ __('Waiting for answers') }}</p>
        @endif
    </section>

    <section class="flex flex-col gap-4">
        <flux:heading size="lg" level="2">{{ __('Your answer') }}</flux:heading>
        <form wire:submit="save" class="flex flex-col gap-3">
            @foreach ($this->slots as $index => $slot)
                <div wire:key="answer-{{ $slot->id }}" class="flex flex-col gap-2 rounded-2xl border border-zinc-200 bg-white px-4 py-3 sm:flex-row sm:items-center sm:justify-between dark:border-white/10 dark:bg-night-raised">
                    <span class="font-semibold">{{ ucfirst($slot->startsAtLocal()->translatedFormat('l j F · H\hi')) }}</span>
                    <flux:radio.group wire:model="responses.{{ $slot->id }}" variant="segmented" size="sm" :aria-label="__('Date :number', ['number' => $index + 1])">
                        @foreach (\App\Enums\AvailabilityStatus::cases() as $status)
                            <flux:radio :value="$status->value" :label="$status->label()" />
                        @endforeach
                    </flux:radio.group>
                    <flux:error name="responses.{{ $slot->id }}" />
                </div>
            @endforeach
            <div><flux:button variant="primary" type="submit">{{ __('Save my answer') }}</flux:button></div>
        </form>
    </section>

    <section class="flex flex-col gap-4">
        <flux:heading size="lg" level="2">{{ __('Everyone\'s answers') }}</flux:heading>
        <div class="overflow-x-auto rounded-2xl border border-zinc-200 bg-white dark:border-white/10 dark:bg-night-raised">
            <table class="w-full border-separate border-spacing-0 text-sm">
                <thead>
                    <tr>
                        <th scope="col" class="px-4 py-3 text-left"><span class="sr-only">{{ __('Member') }}</span></th>
                        @foreach ($this->slots as $slot)
                            <th scope="col" wire:key="head-{{ $slot->id }}" @class(['px-2 py-3 text-center font-semibold', 'bg-sun/50 dark:bg-sun/15' => $slot->id === $this->bestSlotId])>
                                <span class="block text-xs font-medium opacity-60">{{ ucfirst($slot->startsAtLocal()->translatedFormat('D')) }}</span>
                                <span class="block">{{ $slot->startsAtLocal()->translatedFormat('j M') }}</span>
                                <span class="block text-xs opacity-60">{{ $slot->startsAtLocal()->format('H\hi') }}</span>
                            </th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach ($this->members as $member)
                        <tr wire:key="row-{{ $member->id }}">
                            <th scope="row" class="px-4 py-2 text-left font-semibold">{{ $member->name }}</th>
                            @foreach ($this->slots as $slot)
                                @php($status = $slot->availabilities->firstWhere('user_id', $member->id)?->status)
                                <td wire:key="cell-{{ $member->id }}-{{ $slot->id }}" @class(['px-2 py-2 text-center', 'bg-sun/50 dark:bg-sun/15' => $slot->id === $this->bestSlotId])>
                                    @if ($status === \App\Enums\AvailabilityStatus::OnSite)
                                        <span class="mx-auto grid size-7 place-items-center rounded-full bg-forest text-white dark:bg-sun dark:text-forest"><flux:icon.map-pin variant="micro" class="size-3.5" /><span class="sr-only">{{ $status->label() }}</span></span>
                                    @elseif ($status === \App\Enums\AvailabilityStatus::Remote)
                                        <span class="mx-auto grid size-7 place-items-center rounded-full bg-blush text-forest"><flux:icon.video-camera variant="micro" class="size-3.5" /><span class="sr-only">{{ $status->label() }}</span></span>
                                    @elseif ($status === \App\Enums\AvailabilityStatus::Unavailable)
                                        <span class="mx-auto block size-2 rounded-full bg-forest/20 dark:bg-white/20"></span><span class="sr-only">{{ $status->label() }}</span>
                                    @else
                                        <span class="text-xs opacity-40">—</span><span class="sr-only">{{ __('No answer') }}</span>
                                    @endif
                                </td>
                            @endforeach
                        </tr>
                    @endforeach
                    <tr>
                        <th scope="row" class="px-4 py-3 text-left text-sm font-semibold opacity-70">{{ __('Total') }}</th>
                        @foreach ($this->slots as $slot)
                            @php($tally = $this->tallies[$slot->id])
                            <td wire:key="total-{{ $slot->id }}" class="px-2 py-3 text-center">
                                <span @class(['mx-auto block w-fit rounded-full px-3 py-1 font-extrabold tabular-nums', 'bg-forest text-sun dark:bg-sun dark:text-forest' => $slot->id === $this->bestSlotId])>{{ $tally['onSite'] + $tally['remote'] }}</span>
                            </td>
                        @endforeach
                    </tr>
                </tbody>
            </table>
        </div>

        @if ($this->isOrganizer() && $this->nonRespondents->isNotEmpty())
            <flux:callout icon="clock">
                <flux:callout.heading>{{ __('Not answered yet') }}</flux:callout.heading>
                <flux:callout.text>{{ $this->nonRespondents->pluck('name')->join(', ') }}</flux:callout.text>
            </flux:callout>
        @endif
    </section>
</div>
```

(Les cellules vides utilisent « No answer » et non « Not answered yet », réservé au bloc de l'organisateur : le test côté membre vérifie l'absence de ce dernier.)

- [ ] **Step 5 : Traductions**

```json
":total present · :onSite on site, :remote remotely": ":total présents · :onSite sur place, :remote à distance",
"Best date so far": "Meilleure date pour l'instant",
"Edit": "Modifier",
"Everyone's answers": "Les réponses de chacun",
"Member": "Membre",
"No answer": "Pas de réponse",
"Not answered yet": "N'ont pas encore répondu",
"Save my answer": "Enregistrer ma réponse",
"Total": "Total",
"Waiting for answers": "En attente de réponses",
"Your answer": "Votre réponse",
"Your answer has been saved.": "Votre réponse est enregistrée."
```

- [ ] **Step 6 : Lancer les tests**

Run: `php artisan test --compact tests/Feature/Meetings`
Expected: PASS.

- [ ] **Step 7 : Commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Livewire/Meetings resources/views/livewire/meetings lang/fr.json tests/Feature/Meetings
git commit -m "Answer a meeting and see the best date"
```

---

### Task 9 : Données de démonstration et vérification finale

**Files:**
- Create: `database/seeders/DemoSeeder.php`
- Test: aucun nouveau (vérification)

- [ ] **Step 1 : Seeder**

`php artisan make:seeder DemoSeeder --no-interaction` :

```php
<?php

namespace Database\Seeders;

use App\Actions\Groups\CreateGroup;
use App\Enums\AvailabilityStatus;
use App\Models\Availability;
use App\Models\Meeting;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Demo group for local screenshots: attaches to the first user (or creates one).
 * Run with: php artisan db:seed --class=DemoSeeder
 */
class DemoSeeder extends Seeder
{
    public function run(CreateGroup $createGroup): void
    {
        $organizer = User::query()->oldest('id')->first() ?? User::factory()->create(['email' => 'test@reunion.test']);
        $group = $createGroup($organizer, 'Jardin partagé des Lilas (démo)');

        $members = collect(['Amina', 'Bastien', 'Chloé', 'David', 'Élise', 'Farid'])
            ->map(fn (string $name) => User::factory()->create(['name' => $name]))
            ->each(fn (User $member) => $group->addMember($member));

        $meeting = new Meeting(['title' => 'Assemblée de rentrée', 'location' => 'Salle des fêtes']);
        $meeting->group()->associate($group);
        $meeting->creator()->associate($organizer);
        $meeting->save();

        $slots = collect([3, 5, 8, 10])->map(fn (int $days) => $meeting->slots()->create(['starts_at' => now()->addDays($days)->setTime(16, 30)]));
        $statuses = AvailabilityStatus::cases();

        foreach ($members->take(5) as $memberIndex => $member) {
            foreach ($slots as $slotIndex => $slot) {
                $availability = new Availability(['status' => $statuses[($memberIndex + $slotIndex) % 3]]);
                $availability->slot()->associate($slot);
                $availability->user()->associate($member);
                $availability->save();
            }
        }
    }
}
```

- [ ] **Step 2 : Suite complète et build**

Run: `npm run build && php artisan test --compact`
Expected: tout passe.

- [ ] **Step 3 : Vérification visuelle**

**Avec l'accord de l'utilisateur** : `php artisan db:seed --class=DemoSeeder`. Serveur `php artisan serve --port=8000`, connexion avec le compte de l'utilisateur ou le compte de test. Captures clair/sombre, 1440 px et 390 px : tableau de bord (rempli ; vide avec un compte neuf), page du groupe, formulaire de réunion, page de réunion, page d'invitation (non connecté). Inviter une adresse et vérifier l'e-mail dans Mailpit (`http://127.0.0.1:8025`).

- [ ] **Step 4 : Commit**

```bash
vendor/bin/pint --dirty --format agent
git add database/seeders/DemoSeeder.php
git commit -m "Add demo seeder for groups and meetings"
```
