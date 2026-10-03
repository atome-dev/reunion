# Réunions à partir des disponibilités — plan d'implémentation

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Remplacer les réunions « à dates proposées » par des demandes où chaque membre colorie ses disponibilités, l'administrateur consulte un résumé des meilleurs créneaux, valide une date ou la soumet au vote, avec quatre e-mails dont une relance planifiée.

**Architecture:** Données : `meetings` gagne un état et une plage, nouvelle table `availability_days` (28 cases encodées), `availabilities` renommée `slot_votes`. Logique pure dans des actions (`FindBestWindows`, `FindBestSlot` existante, `OpenVote`, `ConfirmMeeting`, `CancelVote`, `BuildIcsCalendar`). Écrans : la page d'une demande assemble trois composants Livewire imbriqués (grille, résumé, vote), chacun ré-autorisé à chaque requête. La grille est dessinée côté client par un composant Alpine enregistré dans `resources/js/availability-grid.js`.

**Tech Stack:** Laravel 13, Livewire 4 (Alpine inclus), Flux Pro, Fortify, Pest 4, SQLite, Vite.

**Spec:** `docs/superpowers/specs/2026-10-03-disponibilites-design.md`

## Global Constraints

- Branche `feature/groupes-reunions` (déjà active). Tâches 1–6 de l'ancien plan faites : groupes, invitations, policies (`GroupPolicy` view/manage/leave, `MeetingPolicy`), `Dashboard`, `Groups\Show`, `FindBestSlot`, `AvailabilityStatus` (`OnSite`='on_site', `Remote`='remote', `Unavailable`='unavailable', `label()`).
- **Ne pas exécuter `migrate:fresh`** : la base locale contient des données de l'utilisateur. Toute évolution de schéma passe par une **nouvelle** migration.
- Grille : 8h00–22h00, **28 cases** de 30 min ; case *i* commence à 8h00 + 30 min × *i* ; caractères `0` indisponible, `p` sur place, `d` à distance ; une journée entièrement à `0` n'est pas stockée.
- Fuseau : jours et cases en heure de `config('app.display_timezone')` (= `Europe/Paris`) ; instants (`starts_at`, `ends_at`, `confirmed_*`) stockés en UTC.
- États : `collecting` → `voting` → `confirmed` ; `voting` → `collecting` (annulation) ; `collecting` → `confirmed` (validation directe).
- Plage de dates ≤ **62 jours**, début ≥ aujourd'hui (Paris) ; date butoir entre aujourd'hui et la fin de la plage.
- Résumé : durée 30–480 min par pas de 30 (défaut 120), minimum de participants défaut 1, minimum sur place défaut 0 ; 15 lignes maximum ; tri présents ↓, sur place ↓, plus proche ↑.
- Droits : non-membre → **404**, membre sans droit d'administrateur → **403** ; chaque composant Livewire ré-autorise dans `hydrate()`.
- E-mails : notifications Laravel en français, **envoi immédiat** (pas de `ShouldQueue`) ; « demande créée » et « vote ouvert » à tous les membres **sauf le créateur** ; « date validée » à **tous** les membres avec `.ics` en pièce jointe ; relance la veille de la date butoir à 9h00 Paris, une seule fois, aux membres sans aucune ligne `availability_days`.
- **Flux d'abord** : composants Flux partout où il en existe un (`flux:card`, `flux:table`, `flux:badge`, `flux:callout`, `flux:button`, `flux:date-picker`, `flux:input`, `flux:select`, `flux:checkbox`, `flux:radio.group variant="segmented"`, `flux:modal`, `flux:heading`, `flux:text`) ; la grille elle-même est en HTML + Alpine. Vérifier les props avec `search-docs` (`livewire/flux`, `livewire/flux-pro`) ou les stubs `vendor/livewire/flux*/stubs/resources/views/flux/`.
- Livewire au format classe (`app/Livewire/<Domaine>/<Nom>.php` + `resources/views/livewire/<domaine>/<nom>.blade.php`, sans `render()`), composants imbriqués via `<livewire:meetings.availability-grid :meeting="$meeting" />`.
- Textes visibles : clés anglaises via `__()` ou `trans_choice()`, traductions fusionnées dans `lang/fr.json` (objet JSON existant, clés triées sans tenir compte de la casse, UTF-8 non échappé, pas de doublon).
- `php artisan make:* --no-interaction` ; `vendor/bin/pint --dirty --format agent` avant chaque commit ; `php artisan test --compact <fichiers>` puis la suite complète ; commits terminés par `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.

## Review Focus

1. **Plage raccourcie après coup** (l'administrateur modifie la fin de plage alors que des membres ont colorié des jours au-delà) → ces jours sont supprimés et n'apparaissent plus nulle part. Test en tâche 4.
2. **Même créneau coché deux fois pour un vote** (deux lignes donnant le même début) → un seul créneau de vote créé ; moins de deux créneaux distincts → refus. Test en tâche 6.
3. **Validation autour du passage à l'heure d'hiver** (jour `2026-10-25`, case 18h00) → `confirmed_starts_at` = `2026-10-25 17:00:00` UTC. Test en tâche 1.
4. **Grille modifiée après la date butoir** (demande toujours en collecte) → enregistrement accepté, la date butoir est indicative. Test en tâche 5.
5. **Membre arrivé pendant la collecte** → la demande apparaît dans « À vous de répondre » et il compte parmi les non-répondants du résumé. Test en tâche 8.

---

## Structure des fichiers

| Fichier | Rôle |
|---|---|
| `app/Enums/MeetingStatus.php` | états d'une demande |
| `database/migrations/xxxx_rework_meetings_for_availabilities.php` | colonnes `meetings`, `meeting_slots.ends_at`, renommage `slot_votes`, table `availability_days` |
| `app/Models/{Meeting,MeetingSlot,SlotVote,AvailabilityDay,Group}.php` + factories | données (`Availability` supprimé → `SlotVote`) |
| `app/Policies/MeetingPolicy.php` | `editAvailability`, `vote` |
| `app/Actions/Meetings/FindBestWindows.php` | calcul du résumé (pur) |
| `app/Actions/Meetings/{OpenVote,ConfirmMeeting,CancelVote,BuildIcsCalendar}.php` | transitions et `.ics` |
| `app/Exceptions/InvalidMeetingTransition.php` | transition refusée |
| `app/Notifications/{MeetingRequested,VoteOpened,MeetingConfirmed,AvailabilityReminder}Notification.php` | e-mails |
| `app/Console/Commands/SendAvailabilityReminders.php` + `routes/console.php` | relance planifiée |
| `app/Livewire/Meetings/Form.php` + vue | créer / modifier / supprimer une demande |
| `app/Livewire/Meetings/AvailabilityGrid.php` + vue + `resources/js/availability-grid.js` | grille membre |
| `app/Livewire/Meetings/Summary.php` + vue | résumé, validation, ouverture du vote |
| `app/Livewire/Meetings/Vote.php` + vue | vote, résultats, validation, annulation |
| `app/Livewire/Meetings/Show.php` + vue, `app/Http/Controllers/MeetingCalendarController.php` | page d'une demande, téléchargement `.ics` |
| `app/Livewire/Dashboard.php` + vue, `app/Livewire/Groups/Show.php` + vue | listes adaptées |
| `resources/views/components/how-it-works.blade.php`, `database/seeders/DemoSeeder.php` | accueil, démo |

---

### Task 1 : Données et règles

**Files:**
- Create: `app/Enums/MeetingStatus.php`, `app/Models/SlotVote.php`, `app/Models/AvailabilityDay.php`, `database/factories/SlotVoteFactory.php`, `database/factories/AvailabilityDayFactory.php`, migration `rework_meetings_for_availabilities`
- Delete: `app/Models/Availability.php`, `database/factories/AvailabilityFactory.php`
- Modify: `app/Models/Meeting.php`, `app/Models/MeetingSlot.php`, `app/Models/Group.php`, `database/factories/MeetingFactory.php`, `database/factories/MeetingSlotFactory.php`, `app/Policies/MeetingPolicy.php`, `app/Livewire/Dashboard.php`, `resources/views/livewire/groups/show.blade.php`, `tests/Feature/Groups/GroupModelTest.php`, `tests/Feature/DashboardTest.php`, `tests/Feature/Groups/PoliciesTest.php`, `lang/fr.json`
- Test: `tests/Feature/Meetings/AvailabilityDataTest.php`

**Interfaces:**
- Produces:
  - `MeetingStatus::Collecting` (`'collecting'`), `::Voting` (`'voting'`), `::Confirmed` (`'confirmed'`), `label(): string`.
  - `AvailabilityDay` : constantes `CellCount = 28`, `FirstHour = 8`, `Empty = '0000000000000000000000000000'` ; `static cellTime(int $cell): string` (`'18:00'`) ; `static localDateTime(string $day, int $cell): CarbonImmutable` (heure de Paris) ; `static statusFor(string $cells, int $start, int $length): AvailabilityStatus` ; colonnes `meeting_id`, `user_id`, `day` (string `Y-m-d`, sans cast), `cells`.
  - `Meeting` : casts `range_start`/`range_end`/`deadline` (`date`), `status` (`MeetingStatus`), `confirmed_starts_at`/`confirmed_ends_at`/`reminder_sent_at` (`datetime`) ; `availabilityDays(): HasMany` ; `slots(): HasMany` (tri `starts_at`) ; `rangeDays(): list<string>` ; `cellsByMember(): array<int, array<string, string>>` (membres actuels uniquement) ; `respondentIds(): Collection<int,int>` ; scopes `upcoming()` (non confirmée, ou confirmée et `confirmed_starts_at >= now()`) et `past()` (confirmée et `confirmed_starts_at < now()`).
  - `MeetingSlot` : cast `ends_at` ; `votes(): HasMany<SlotVote>` ; `startsAtLocal()`, `endsAtLocal()`.
  - `SlotVote` : table `slot_votes`, `slot()`, `user()`, cast `status` → `AvailabilityStatus`.
  - `Group::removeMember(User)` supprime aussi ses `availability_days` et `slot_votes` des demandes du groupe.
  - `MeetingPolicy` : `editAvailability(User, Meeting): bool` (membre et `Collecting`), `vote(User, Meeting): bool` (membre et `Voting`) ; `view`/`manage` inchangés ; `respond` supprimée.
  - Factories : `Meeting::factory()` (plage J+1 à J+14, butoir J+7, `Collecting`), états `voting()`, `confirmed(?CarbonInterface $startsAt = null)` ; `AvailabilityDay::factory()` (`day` = début de plage, cases vides), état `cells(string $cells)` ; `SlotVote::factory()`.

- [ ] **Step 1 : Générer les fichiers**

```bash
php artisan make:enum MeetingStatus --string --no-interaction
php artisan make:migration rework_meetings_for_availabilities --no-interaction
php artisan make:model SlotVote -f --no-interaction
php artisan make:model AvailabilityDay -f --no-interaction
php artisan make:test --pest Meetings/AvailabilityDataTest --no-interaction
```

Si `make:enum` écrit dans `app/` au lieu de `app/Enums/`, déplacer le fichier (namespace `App\Enums`).

- [ ] **Step 2 : Écrire le test qui échoue**

`tests/Feature/Meetings/AvailabilityDataTest.php` :

```php
<?php

use App\Enums\AvailabilityStatus;
use App\Enums\MeetingStatus;
use App\Models\AvailabilityDay;
use App\Models\Group;
use App\Models\Meeting;
use App\Models\MeetingSlot;
use App\Models\SlotVote;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

test('a new meeting collects availabilities over its range', function () {
    $meeting = Meeting::factory()->create([
        'range_start' => '2026-11-02',
        'range_end' => '2026-11-04',
    ]);

    expect($meeting->status)->toBe(MeetingStatus::Collecting)
        ->and($meeting->rangeDays())->toBe(['2026-11-02', '2026-11-03', '2026-11-04']);
});

test('cells map to half hours from 8:00 and local times convert to UTC around the clock change', function () {
    expect(AvailabilityDay::cellTime(0))->toBe('08:00')
        ->and(AvailabilityDay::cellTime(21))->toBe('18:30')
        ->and(AvailabilityDay::cellTime(28))->toBe('22:00')
        ->and(AvailabilityDay::localDateTime('2026-10-24', 20)->utc()->format('Y-m-d H:i'))->toBe('2026-10-24 16:00')
        ->and(AvailabilityDay::localDateTime('2026-10-25', 20)->utc()->format('Y-m-d H:i'))->toBe('2026-10-25 17:00');
});

test('a stretch of cells reads as on site, remote or unavailable', function () {
    $cells = str_repeat('0', 20).'ppppdd00';

    expect(AvailabilityDay::statusFor($cells, 20, 4))->toBe(AvailabilityStatus::OnSite)
        ->and(AvailabilityDay::statusFor($cells, 20, 6))->toBe(AvailabilityStatus::Remote)
        ->and(AvailabilityDay::statusFor($cells, 20, 7))->toBe(AvailabilityStatus::Unavailable)
        ->and(AvailabilityDay::statusFor($cells, 0, 2))->toBe(AvailabilityStatus::Unavailable);
});

test('cells are grouped per current member and day', function () {
    $group = Group::factory()->create();
    $member = User::factory()->create();
    $group->addMember($member);
    $former = User::factory()->create();
    $meeting = Meeting::factory()->for($group)->create(['range_start' => '2026-11-02', 'range_end' => '2026-11-03']);
    AvailabilityDay::factory()->for($meeting)->for($member)->cells(str_repeat('p', 28))->create(['day' => '2026-11-02']);
    AvailabilityDay::factory()->for($meeting)->for($former)->cells(str_repeat('d', 28))->create(['day' => '2026-11-02']);

    expect($meeting->cellsByMember())->toBe([$member->id => ['2026-11-02' => str_repeat('p', 28)]])
        ->and($meeting->respondentIds()->all())->toBe([$member->id]);
});

test('removing a member deletes their grid and votes in the group', function () {
    $group = Group::factory()->create();
    $member = User::factory()->create();
    $group->addMember($member);
    $meeting = Meeting::factory()->for($group)->create();
    AvailabilityDay::factory()->for($meeting)->for($member)->cells(str_repeat('p', 28))->create();
    $slot = MeetingSlot::factory()->for($meeting)->create();
    SlotVote::factory()->for($slot, 'slot')->for($member)->create();

    $group->removeMember($member);

    expect(AvailabilityDay::count())->toBe(0)->and(SlotVote::count())->toBe(0);
});

test('upcoming meetings are those not confirmed yet or confirmed in the future', function () {
    $collecting = Meeting::factory()->create();
    $future = Meeting::factory()->confirmed(now()->addWeek())->create();
    $past = Meeting::factory()->confirmed(now()->subWeek())->create();

    expect(Meeting::upcoming()->pluck('id')->sort()->values()->all())->toBe([$collecting->id, $future->id])
        ->and(Meeting::past()->pluck('id')->all())->toBe([$past->id]);
});

test('members fill their grid only while collecting and vote only while voting', function () {
    $group = Group::factory()->create();
    $member = User::factory()->create();
    $group->addMember($member);
    $outsider = User::factory()->create();
    $collecting = Meeting::factory()->for($group)->create();
    $voting = Meeting::factory()->for($group)->voting()->create();

    expect(Gate::forUser($member)->allows('editAvailability', $collecting))->toBeTrue()
        ->and(Gate::forUser($member)->allows('editAvailability', $voting))->toBeFalse()
        ->and(Gate::forUser($member)->allows('vote', $voting))->toBeTrue()
        ->and(Gate::forUser($member)->allows('vote', $collecting))->toBeFalse()
        ->and(Gate::forUser($outsider)->allows('editAvailability', $collecting))->toBeFalse();
});
```

- [ ] **Step 3 : Lancer, vérifier l'échec**

Run: `php artisan test --compact tests/Feature/Meetings/AvailabilityDataTest.php`
Expected: FAIL (classes et colonnes absentes).

- [ ] **Step 4 : Enum**

```php
<?php

namespace App\Enums;

enum MeetingStatus: string
{
    case Collecting = 'collecting';
    case Voting = 'voting';
    case Confirmed = 'confirmed';

    public function label(): string
    {
        return match ($this) {
            self::Collecting => __('Collecting availabilities'),
            self::Voting => __('Vote in progress'),
            self::Confirmed => __('Confirmed'),
        };
    }
}
```

- [ ] **Step 5 : Migration** (nouvelle migration, **pas** de `migrate:fresh`)

```php
public function up(): void
{
    Schema::rename('availabilities', 'slot_votes');

    Schema::table('meetings', function (Blueprint $table) {
        $table->date('range_start')->nullable();
        $table->date('range_end')->nullable();
        $table->date('deadline')->nullable();
        $table->string('status')->default('collecting');
        $table->dateTime('confirmed_starts_at')->nullable();
        $table->dateTime('confirmed_ends_at')->nullable();
        $table->timestamp('reminder_sent_at')->nullable();
    });

    Schema::table('meeting_slots', function (Blueprint $table) {
        $table->dateTime('ends_at')->nullable();
    });

    Schema::create('availability_days', function (Blueprint $table) {
        $table->id();
        $table->foreignId('meeting_id')->constrained()->cascadeOnDelete();
        $table->foreignId('user_id')->constrained()->cascadeOnDelete();
        $table->date('day');
        $table->char('cells', 28);
        $table->timestamps();
        $table->unique(['meeting_id', 'user_id', 'day']);
    });
}

public function down(): void
{
    Schema::dropIfExists('availability_days');

    Schema::table('meeting_slots', function (Blueprint $table) {
        $table->dropColumn('ends_at');
    });

    Schema::table('meetings', function (Blueprint $table) {
        $table->dropColumn(['range_start', 'range_end', 'deadline', 'status', 'confirmed_starts_at', 'confirmed_ends_at', 'reminder_sent_at']);
    });

    Schema::rename('slot_votes', 'availabilities');
}
```

Les colonnes de plage sont nullables en base (ajout sur une table existante) ; l'application les rend obligatoires (tâche 4).

- [ ] **Step 6 : Modèles**

Supprimer `app/Models/Availability.php` et `database/factories/AvailabilityFactory.php`.

`app/Models/SlotVote.php` :

```php
<?php

namespace App\Models;

use App\Enums\AvailabilityStatus;
use Database\Factories\SlotVoteFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A member's answer to one of the slots put to a vote.
 *
 * @property int $id
 * @property int $meeting_slot_id
 * @property int $user_id
 * @property AvailabilityStatus $status
 */
#[Fillable(['status'])]
class SlotVote extends Model
{
    /** @use HasFactory<SlotVoteFactory> */
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

`app/Models/AvailabilityDay.php` :

```php
<?php

namespace App\Models;

use App\Enums\AvailabilityStatus;
use Carbon\CarbonImmutable;
use Database\Factories\AvailabilityDayFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One member's availability for one day of a meeting request: 28 half-hour cells from 8:00 to 22:00,
 * each "0" (unavailable), "p" (on site) or "d" (remote).
 *
 * @property int $id
 * @property int $meeting_id
 * @property int $user_id
 * @property string $day
 * @property string $cells
 */
#[Fillable(['cells'])]
class AvailabilityDay extends Model
{
    /** @use HasFactory<AvailabilityDayFactory> */
    use HasFactory;

    public const int CellCount = 28;

    public const int FirstHour = 8;

    public const string Empty = '0000000000000000000000000000';

    /** @return BelongsTo<Meeting, $this> */
    public function meeting(): BelongsTo
    {
        return $this->belongsTo(Meeting::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Local time at which a cell starts ("08:00" for 0, "22:00" for 28 — the end of the last cell).
     */
    public static function cellTime(int $cell): string
    {
        return sprintf('%02d:%02d', self::FirstHour + intdiv($cell, 2), ($cell % 2) * 30);
    }

    /**
     * The local date and time (display timezone) at which a cell starts on a given day.
     */
    public static function localDateTime(string $day, int $cell): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('Y-m-d H:i', $day.' '.self::cellTime($cell), config('app.display_timezone'));
    }

    /**
     * How a member can attend a stretch of cells: on site only if every cell is on site.
     */
    public static function statusFor(string $cells, int $start, int $length): AvailabilityStatus
    {
        $stretch = substr($cells, $start, $length);

        if (strlen($stretch) < $length || str_contains($stretch, '0')) {
            return AvailabilityStatus::Unavailable;
        }

        return $stretch === str_repeat('p', $length) ? AvailabilityStatus::OnSite : AvailabilityStatus::Remote;
    }
}
```

`app/Models/Meeting.php` — remplacer le contenu par :

```php
<?php

namespace App\Models;

use App\Enums\MeetingStatus;
use Carbon\CarbonPeriod;
use Database\Factories\MeetingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * A meeting request: members fill in their availabilities over a date range, then a date is confirmed
 * directly or after a vote.
 *
 * @property int $id
 * @property int $group_id
 * @property string $title
 * @property string|null $description
 * @property string|null $location
 * @property int $created_by
 * @property Carbon $range_start
 * @property Carbon $range_end
 * @property Carbon $deadline
 * @property MeetingStatus $status
 * @property Carbon|null $confirmed_starts_at
 * @property Carbon|null $confirmed_ends_at
 * @property Carbon|null $reminder_sent_at
 */
#[Fillable(['title', 'description', 'location', 'range_start', 'range_end', 'deadline'])]
class Meeting extends Model
{
    /** @use HasFactory<MeetingFactory> */
    use HasFactory;

    protected $attributes = [
        'status' => 'collecting',
    ];

    protected function casts(): array
    {
        return [
            'range_start' => 'date',
            'range_end' => 'date',
            'deadline' => 'date',
            'status' => MeetingStatus::class,
            'confirmed_starts_at' => 'datetime',
            'confirmed_ends_at' => 'datetime',
            'reminder_sent_at' => 'datetime',
        ];
    }

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

    /** @return HasMany<AvailabilityDay, $this> */
    public function availabilityDays(): HasMany
    {
        return $this->hasMany(AvailabilityDay::class);
    }

    /**
     * Requests still in progress, or confirmed for a date ahead.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function upcoming(Builder $query): void
    {
        $query->where(fn (Builder $query) => $query
            ->where('status', '!=', MeetingStatus::Confirmed->value)
            ->orWhere('confirmed_starts_at', '>=', now()));
    }

    /**
     * Confirmed meetings that already took place.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function past(Builder $query): void
    {
        $query->where('status', MeetingStatus::Confirmed->value)->where('confirmed_starts_at', '<', now());
    }

    /**
     * Every day of the range, as "Y-m-d" strings.
     *
     * @return list<string>
     */
    public function rangeDays(): array
    {
        return collect(CarbonPeriod::create($this->range_start->toDateString(), $this->range_end->toDateString()))
            ->map(fn (Carbon $day): string => $day->toDateString())
            ->values()
            ->all();
    }

    /**
     * Cells of the group's current members, keyed by member id then day.
     *
     * @return array<int, array<string, string>>
     */
    public function cellsByMember(): array
    {
        $memberIds = $this->group->members()->pluck('users.id');

        return $this->availabilityDays()
            ->whereIn('user_id', $memberIds)
            ->orderBy('day')
            ->get()
            ->groupBy('user_id')
            ->map(fn (Collection $days): array => $days->mapWithKeys(fn (AvailabilityDay $day): array => [$day->day => $day->cells])->all())
            ->all();
    }

    /**
     * Current members who filled at least one day.
     *
     * @return Collection<int, int>
     */
    public function respondentIds(): Collection
    {
        return collect(array_keys($this->cellsByMember()));
    }

    public function isPast(): bool
    {
        return $this->status === MeetingStatus::Confirmed && $this->confirmed_starts_at?->isPast();
    }
}
```

Note : `day` est stocké tel quel (`'Y-m-d'`), sans cast, pour que les requêtes `where('day', '2026-11-02')` restent exactes sous SQLite.

`app/Models/MeetingSlot.php` — ajouter `ends_at` (cast + `#[Fillable(['starts_at', 'ends_at'])]`), remplacer `availabilities()` par :

```php
    /** @return HasMany<SlotVote, $this> */
    public function votes(): HasMany
    {
        return $this->hasMany(SlotVote::class);
    }

    public function endsAtLocal(): CarbonInterface
    {
        return $this->ends_at->copy()->setTimezone(config('app.display_timezone'));
    }
```

(et `@property Carbon $ends_at`).

`app/Models/Group.php` — remplacer le corps de `removeMember()` par :

```php
    public function removeMember(User $user): void
    {
        $meetingIds = $this->meetings()->pluck('id');

        SlotVote::query()
            ->where('user_id', $user->id)
            ->whereIn('meeting_slot_id', MeetingSlot::query()->select('id')->whereIn('meeting_id', $meetingIds))
            ->delete();

        AvailabilityDay::query()->where('user_id', $user->id)->whereIn('meeting_id', $meetingIds)->delete();

        $this->members()->detach($user->id);
    }
```

- [ ] **Step 7 : Factories**

`MeetingFactory` :

```php
    public function definition(): array
    {
        return [
            'group_id' => Group::factory(),
            'title' => fake()->sentence(3),
            'created_by' => fn (array $attributes) => Group::find($attributes['group_id'])->owner_id,
            'range_start' => today()->addDay()->toDateString(),
            'range_end' => today()->addDays(14)->toDateString(),
            'deadline' => today()->addDays(7)->toDateString(),
            'status' => MeetingStatus::Collecting,
        ];
    }

    public function voting(): static
    {
        return $this->state(fn (array $attributes) => ['status' => MeetingStatus::Voting]);
    }

    public function confirmed(?CarbonInterface $startsAt = null): static
    {
        $startsAt ??= now()->addWeek()->setTime(18, 0);

        return $this->state(fn (array $attributes) => [
            'status' => MeetingStatus::Confirmed,
            'confirmed_starts_at' => $startsAt,
            'confirmed_ends_at' => $startsAt->copy()->addHours(2),
        ]);
    }
```

`MeetingSlotFactory::definition()` : ajouter `'ends_at' => fn (array $attributes) => Carbon::parse($attributes['starts_at'])->addHours(2),`.

`SlotVoteFactory` :

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

`AvailabilityDayFactory` :

```php
    public function definition(): array
    {
        return [
            'meeting_id' => Meeting::factory(),
            'user_id' => User::factory(),
            'day' => fn (array $attributes) => Meeting::find($attributes['meeting_id'])->range_start->toDateString(),
            'cells' => AvailabilityDay::Empty,
        ];
    }

    public function cells(string $cells): static
    {
        return $this->state(fn (array $attributes) => ['cells' => $cells]);
    }
```

- [ ] **Step 8 : Policy**

Dans `app/Policies/MeetingPolicy.php`, remplacer `respond()` par :

```php
    /**
     * Fill in one's own availability grid, while the request is collecting.
     */
    public function editAvailability(User $user, Meeting $meeting): bool
    {
        return $meeting->status === MeetingStatus::Collecting && $meeting->group->hasMember($user);
    }

    /**
     * Answer the slots put to a vote, while the vote is open.
     */
    public function vote(User $user, Meeting $meeting): bool
    {
        return $meeting->status === MeetingStatus::Voting && $meeting->group->hasMember($user);
    }
```

Dans `tests/Feature/Groups/PoliciesTest.php`, remplacer le test `members respond, outsiders cannot` par :

```php
test('only members can fill a grid or vote', function () {
    $voting = Meeting::factory()->for($this->group)->voting()->create();

    expect(Gate::forUser($this->member)->allows('editAvailability', $this->meeting))->toBeTrue()
        ->and(Gate::forUser($this->member)->allows('vote', $voting))->toBeTrue()
        ->and(Gate::forUser($this->outsider)->allows('editAvailability', $this->meeting))->toBeFalse()
        ->and(Gate::forUser($this->outsider)->allows('vote', $voting))->toBeFalse();
});
```

- [ ] **Step 9 : Adapter le code existant au renommage**

- `app/Livewire/Dashboard.php` : dans `pendingMeetings()`, remplacer `->whereDoesntHave('slots.availabilities', …)` par `->whereDoesntHave('availabilityDays', fn (Builder $days) => $days->where('user_id', $userId))->where('status', MeetingStatus::Collecting->value)` (la tâche 8 réécrit ce composant) ; dans `groups()`, `->with(['meetings' => fn ($query) => $query->upcoming()])`.
- `resources/views/livewire/dashboard.blade.php` : remplacer `trans_choice(':count date|:count dates', $meeting->slots->count())` par `__('Answer before :date', ['date' => $meeting->deadline->translatedFormat('j F')])` ; remplacer la ligne `@php($nextMeeting = …)` par `@php($nextMeeting = $group->meetings->first())`.
- `resources/views/livewire/groups/show.blade.php` : remplacer `$meeting->slots->flatMap->availabilities->pluck('user_id')->unique()->count()` par `$meeting->respondentIds()->count()` (la tâche 4 réécrit cette liste) ; `app/Livewire/Groups/Show.php` : `upcomingMeetings()` → `$this->group->meetings()->upcoming()->latest()->get()`, `pastMeetings()` → `$this->group->meetings()->past()->latest('confirmed_starts_at')->get()`.
- `tests/Feature/Groups/GroupModelTest.php` : `Availability` → `SlotVote` (import et usages), `MeetingSlot::factory()->for(...)` inchangé ; remplacer le test « meetings are upcoming while one of their dates is ahead » par le test d'échéance de `AvailabilityDataTest` (le supprimer ici) ; dans « members can be added and removed with their answers » et « deleting a group… », `Availability::factory()` → `SlotVote::factory()`.
- `tests/Feature/DashboardTest.php` : dans le test de liste, remplacer les réunions par : une demande en collecte sans grille de l'utilisateur (« Assemblée à répondre »), une demande en collecte avec une ligne `AvailabilityDay` de l'utilisateur (« Réunion déjà répondue »), une demande `confirmed(now()->subWeek())` (« Réunion passée »), une demande d'un autre groupe (« Réunion d'un autre groupe ») ; mêmes assertions.

- [ ] **Step 10 : Traductions**

```json
"Answer before :date": "Répondre avant le :date",
"Collecting availabilities": "Collecte des disponibilités",
"Confirmed": "Confirmée",
"Vote in progress": "Vote en cours"
```

- [ ] **Step 11 : Migrer, lancer les tests**

Run: `php artisan migrate --no-interaction && php artisan test --compact tests/Feature/Meetings/AvailabilityDataTest.php tests/Feature/Groups tests/Feature/DashboardTest.php`
Expected: PASS. Puis `php artisan test --compact` : tout passe.

- [ ] **Step 12 : Commit**

```bash
vendor/bin/pint --dirty --format agent
git add -A app database lang tests resources/views
git commit -m "Rework meetings data model around availabilities"
```

---

### Task 2 : Calcul du résumé

**Files:**
- Create: `app/Actions/Meetings/FindBestWindows.php`
- Test: `tests/Feature/Meetings/FindBestWindowsTest.php`

**Interfaces:**
- Consumes: `AvailabilityDay::statusFor()`, `AvailabilityDay::CellCount`, `AvailabilityStatus` (tâche 1).
- Produces: `FindBestWindows::__invoke(array $cellsByMember, array $days, int $durationMinutes, int $minParticipants, int $minOnSite, int $limit = 15): list<array{day: string, firstStart: int, lastStart: int, length: int, onSite: list<int>, remote: list<int>}>` — `firstStart`/`lastStart` = cases de début possibles (incluses), `length` = nombre de cases de la réunion.

- [ ] **Step 1 : Écrire les tests qui échouent**

`php artisan make:test --pest Meetings/FindBestWindowsTest --no-interaction` :

```php
<?php

use App\Actions\Meetings\FindBestWindows;

/**
 * Build a 28-cell day: $ranges maps "start-end" (cells, end exclusive) to "p" or "d".
 *
 * @param  array<string, string>  $ranges
 */
function day(array $ranges = []): string
{
    $cells = str_repeat('0', 28);

    foreach ($ranges as $range => $value) {
        [$start, $end] = array_map('intval', explode('-', $range));
        $cells = substr_replace($cells, str_repeat($value, $end - $start), $start, $end - $start);
    }

    return $cells;
}

function findWindows(array $cells, array $days, int $duration = 120, int $minParticipants = 1, int $minOnSite = 0, int $limit = 15): array
{
    return (new FindBestWindows)($cells, $days, $duration, $minParticipants, $minOnSite, $limit);
}

test('a member is present only if available for the whole duration', function () {
    $windows = findWindows([1 => ['2026-11-02' => day(['20-24' => 'p'])]], ['2026-11-02'], 120);

    expect($windows)->toHaveCount(1)
        ->and($windows[0])->toMatchArray(['day' => '2026-11-02', 'firstStart' => 20, 'lastStart' => 20, 'length' => 4, 'onSite' => [1], 'remote' => []]);
});

test('a member mixing on site and remote counts as remote', function () {
    $windows = findWindows([1 => ['2026-11-02' => day(['20-22' => 'p', '22-24' => 'd'])]], ['2026-11-02'], 120);

    expect($windows[0]['onSite'])->toBe([])->and($windows[0]['remote'])->toBe([1]);
});

test('consecutive starts with the same people are grouped, a change splits them', function () {
    $cells = [
        1 => ['2026-11-02' => day(['18-26' => 'p'])],
        2 => ['2026-11-02' => day(['20-26' => 'd'])],
    ];

    $windows = findWindows($cells, ['2026-11-02'], 120);

    expect($windows[0])->toMatchArray(['firstStart' => 20, 'lastStart' => 22, 'onSite' => [1], 'remote' => [2]])
        ->and($windows[1])->toMatchArray(['firstStart' => 18, 'lastStart' => 19, 'onSite' => [1], 'remote' => []]);
});

test('windows never run past 22:00', function () {
    $windows = findWindows([1 => ['2026-11-02' => day(['24-28' => 'p'])]], ['2026-11-02'], 120);

    expect($windows)->toHaveCount(1)->and($windows[0]['lastStart'])->toBe(24);
    expect(findWindows([1 => ['2026-11-02' => day(['24-28' => 'p'])]], ['2026-11-02'], 150))->toBe([]);
});

test('minimums filter windows', function () {
    $cells = [
        1 => ['2026-11-02' => day(['20-24' => 'p'])],
        2 => ['2026-11-02' => day(['20-24' => 'd'])],
        3 => ['2026-11-03' => day(['20-24' => 'p'])],
    ];

    expect(findWindows($cells, ['2026-11-02', '2026-11-03'], 120, 2))->toHaveCount(1)
        ->and(findWindows($cells, ['2026-11-02', '2026-11-03'], 120, 1, 2))->toBe([]);
});

test('windows are ranked by attendees, then on site, then earliest', function () {
    $cells = [
        1 => ['2026-11-02' => day(['10-14' => 'd']), '2026-11-03' => day(['10-14' => 'p']), '2026-11-04' => day(['10-14' => 'p'])],
        2 => ['2026-11-04' => day(['10-14' => 'd'])],
    ];

    $windows = findWindows($cells, ['2026-11-02', '2026-11-03', '2026-11-04'], 120);

    expect(array_column($windows, 'day'))->toBe(['2026-11-04', '2026-11-03', '2026-11-02']);
});

test('only days of the range are considered and the result is limited', function () {
    $cells = [1 => ['2026-11-01' => day(['0-28' => 'p']), '2026-11-02' => day(['0-4' => 'p', '6-10' => 'p'])]];

    expect(array_column(findWindows($cells, ['2026-11-02'], 60), 'day'))->toBe(['2026-11-02', '2026-11-02'])
        ->and(findWindows($cells, ['2026-11-02'], 60, limit: 1))->toHaveCount(1);
});

test('nobody available gives no window', function () {
    expect(findWindows([], ['2026-11-02']))->toBe([]);
});
```

- [ ] **Step 2 : Lancer, vérifier l'échec**

Run: `php artisan test --compact tests/Feature/Meetings/FindBestWindowsTest.php`
Expected: FAIL (classe absente).

- [ ] **Step 3 : Implémenter**

`php artisan make:class Actions/Meetings/FindBestWindows --no-interaction` :

```php
<?php

namespace App\Actions\Meetings;

use App\Enums\AvailabilityStatus;
use App\Models\AvailabilityDay;

/**
 * Find the best meeting windows from members' availability grids.
 *
 * A member counts as present if available for the whole duration, and on site only if on site for the
 * whole duration. Consecutive starts of a day with exactly the same people are grouped into one window.
 */
class FindBestWindows
{
    /**
     * @param  array<int, array<string, string>>  $cellsByMember  member id => [day => 28 cells]
     * @param  list<string>  $days
     * @return list<array{day: string, firstStart: int, lastStart: int, length: int, onSite: list<int>, remote: list<int>}>
     */
    public function __invoke(array $cellsByMember, array $days, int $durationMinutes, int $minParticipants, int $minOnSite, int $limit = 15): array
    {
        $length = intdiv($durationMinutes, 30);
        $windows = [];

        foreach ($days as $day) {
            $current = null;

            for ($start = 0; $start + $length <= AvailabilityDay::CellCount; $start++) {
                [$onSite, $remote] = $this->attendees($cellsByMember, $day, $start, $length);

                if (count($onSite) + count($remote) < max(1, $minParticipants) || count($onSite) < $minOnSite) {
                    $current = $this->flush($windows, $current);

                    continue;
                }

                if ($current !== null && $current['onSite'] === $onSite && $current['remote'] === $remote && $current['lastStart'] === $start - 1) {
                    $current['lastStart'] = $start;

                    continue;
                }

                $this->flush($windows, $current);
                $current = ['day' => $day, 'firstStart' => $start, 'lastStart' => $start, 'length' => $length, 'onSite' => $onSite, 'remote' => $remote];
            }

            $this->flush($windows, $current);
        }

        usort($windows, fn (array $a, array $b): int => [count($b['onSite']) + count($b['remote']), count($b['onSite']), $a['day'], $a['firstStart']]
            <=> [count($a['onSite']) + count($a['remote']), count($a['onSite']), $b['day'], $b['firstStart']]);

        return array_slice($windows, 0, $limit);
    }

    /**
     * @param  array<int, array<string, string>>  $cellsByMember
     * @return array{0: list<int>, 1: list<int>}
     */
    private function attendees(array $cellsByMember, string $day, int $start, int $length): array
    {
        $onSite = [];
        $remote = [];

        foreach ($cellsByMember as $memberId => $days) {
            match (AvailabilityDay::statusFor($days[$day] ?? AvailabilityDay::Empty, $start, $length)) {
                AvailabilityStatus::OnSite => $onSite[] = $memberId,
                AvailabilityStatus::Remote => $remote[] = $memberId,
                AvailabilityStatus::Unavailable => null,
            };
        }

        return [$onSite, $remote];
    }

    /**
     * @param  list<array<string, mixed>>  $windows
     * @param  array<string, mixed>|null  $current
     */
    private function flush(array &$windows, ?array $current): null
    {
        if ($current !== null) {
            $windows[] = $current;
        }

        return null;
    }
}
```

- [ ] **Step 4 : Lancer les tests**

Run: `php artisan test --compact tests/Feature/Meetings/FindBestWindowsTest.php`
Expected: PASS (8 tests).

- [ ] **Step 5 : Commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Actions/Meetings/FindBestWindows.php tests/Feature/Meetings/FindBestWindowsTest.php
git commit -m "Find the best meeting windows from availability grids"
```

---

### Task 3 : Transitions et e-mails

**Files:**
- Create: `app/Exceptions/InvalidMeetingTransition.php`, `app/Actions/Meetings/{OpenVote,ConfirmMeeting,CancelVote,BuildIcsCalendar}.php`, `app/Notifications/{MeetingRequested,VoteOpened,MeetingConfirmed,AvailabilityReminder}Notification.php`, `app/Console/Commands/SendAvailabilityReminders.php`
- Modify: `routes/console.php`, `lang/fr.json`
- Test: `tests/Feature/Meetings/TransitionsTest.php`, `tests/Feature/Meetings/RemindersTest.php`

**Interfaces:**
- Consumes: `Meeting`, `MeetingStatus`, `MeetingSlot` (avec `ends_at`), `Group::members()`.
- Produces:
  - `OpenVote::__invoke(Meeting $meeting, array $slots): void` — `$slots` = `list<array{0: CarbonInterface, 1: CarbonInterface}>` (début, fin UTC) ; ≥ 2 créneaux distincts ; état `Collecting` requis ; envoie `VoteOpenedNotification` aux membres sauf le créateur.
  - `ConfirmMeeting::__invoke(Meeting $meeting, CarbonInterface $startsAt, CarbonInterface $endsAt): void` — état non `Confirmed` requis ; envoie `MeetingConfirmedNotification` à tous les membres.
  - `CancelVote::__invoke(Meeting $meeting): void` — état `Voting` requis ; supprime créneaux et votes ; retour à `Collecting`.
  - `BuildIcsCalendar::__invoke(Meeting $meeting): string`.
  - `InvalidMeetingTransition` (exception, `RuntimeException`).
  - Notifications `MeetingRequestedNotification(Meeting)`, `VoteOpenedNotification(Meeting)`, `MeetingConfirmedNotification(Meeting)`, `AvailabilityReminderNotification(Meeting)`.
  - Commande `meetings:send-reminders`, planifiée chaque jour à 09:00 `Europe/Paris`.

- [ ] **Step 1 : Écrire les tests qui échouent**

`php artisan make:test --pest Meetings/TransitionsTest --no-interaction` :

```php
<?php

use App\Actions\Meetings\BuildIcsCalendar;
use App\Actions\Meetings\CancelVote;
use App\Actions\Meetings\ConfirmMeeting;
use App\Actions\Meetings\OpenVote;
use App\Enums\MeetingStatus;
use App\Exceptions\InvalidMeetingTransition;
use App\Models\Group;
use App\Models\Meeting;
use App\Models\MeetingSlot;
use App\Models\SlotVote;
use App\Models\User;
use App\Notifications\MeetingConfirmedNotification;
use App\Notifications\VoteOpenedNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    Notification::fake();
    $this->group = Group::factory()->create();
    $this->member = User::factory()->create();
    $this->group->addMember($this->member);
    $this->meeting = Meeting::factory()->for($this->group)->create(['title' => 'AG; rentrée', 'location' => 'Salle, du fond']);
});

function utc(string $time): Carbon
{
    return Carbon::parse($time, 'UTC');
}

test('opening a vote creates the slots and notifies members except the creator', function () {
    (new OpenVote)($this->meeting, [
        [utc('2026-11-02 17:00'), utc('2026-11-02 19:00')],
        [utc('2026-11-03 17:00'), utc('2026-11-03 19:00')],
    ]);

    expect($this->meeting->fresh()->status)->toBe(MeetingStatus::Voting)
        ->and($this->meeting->slots()->count())->toBe(2);
    Notification::assertSentTo($this->member, VoteOpenedNotification::class);
    Notification::assertNotSentTo($this->group->owner, VoteOpenedNotification::class);
});

test('a vote needs two distinct slots and an open collection', function () {
    expect(fn () => (new OpenVote)($this->meeting, [
        [utc('2026-11-02 17:00'), utc('2026-11-02 19:00')],
        [utc('2026-11-02 17:00'), utc('2026-11-02 19:00')],
    ]))->toThrow(InvalidMeetingTransition::class);

    $voting = Meeting::factory()->for($this->group)->voting()->create();

    expect(fn () => (new OpenVote)($voting, [
        [utc('2026-11-02 17:00'), utc('2026-11-02 19:00')],
        [utc('2026-11-03 17:00'), utc('2026-11-03 19:00')],
    ]))->toThrow(InvalidMeetingTransition::class);
});

test('confirming stores the date and emails everyone with a calendar file', function () {
    (new ConfirmMeeting)($this->meeting, utc('2026-11-02 17:00'), utc('2026-11-02 19:00'));

    $meeting = $this->meeting->fresh();
    expect($meeting->status)->toBe(MeetingStatus::Confirmed)
        ->and($meeting->confirmed_starts_at->format('Y-m-d H:i'))->toBe('2026-11-02 17:00');
    Notification::assertSentTo([$this->member, $this->group->owner], MeetingConfirmedNotification::class);

    expect(fn () => (new ConfirmMeeting)($meeting, utc('2026-11-03 17:00'), utc('2026-11-03 19:00')))
        ->toThrow(InvalidMeetingTransition::class);
});

test('the confirmation email carries an ics attachment', function () {
    (new ConfirmMeeting)($this->meeting, utc('2026-11-02 17:00'), utc('2026-11-02 19:00'));

    Notification::assertSentTo($this->member, MeetingConfirmedNotification::class, function (MeetingConfirmedNotification $notification) {
        $mail = $notification->toMail($this->member);

        return count($mail->rawAttachments) === 1
            && str_contains($mail->rawAttachments[0]['data'], 'DTSTART:20261102T170000Z');
    });
});

test('cancelling a vote deletes slots and votes and reopens the collection', function () {
    $voting = Meeting::factory()->for($this->group)->voting()->create();
    $slot = MeetingSlot::factory()->for($voting)->create();
    SlotVote::factory()->for($slot, 'slot')->for($this->member)->create();

    (new CancelVote)($voting);

    expect($voting->fresh()->status)->toBe(MeetingStatus::Collecting)
        ->and(MeetingSlot::count())->toBe(0)
        ->and(SlotVote::count())->toBe(0);
    expect(fn () => (new CancelVote)($this->meeting))->toThrow(InvalidMeetingTransition::class);
});

test('the calendar file is valid and escaped', function () {
    $this->meeting->forceFill([
        'status' => MeetingStatus::Confirmed,
        'confirmed_starts_at' => utc('2026-11-02 17:00'),
        'confirmed_ends_at' => utc('2026-11-02 19:00'),
    ])->save();

    $ics = (new BuildIcsCalendar)($this->meeting);

    expect($ics)->toStartWith("BEGIN:VCALENDAR\r\n")
        ->toContain("DTSTART:20261102T170000Z\r\n")
        ->toContain("DTEND:20261102T190000Z\r\n")
        ->toContain('SUMMARY:AG\; rentrée')
        ->toContain('LOCATION:Salle\, du fond')
        ->toContain('UID:meeting-'.$this->meeting->id.'@')
        ->toEndWith("END:VCALENDAR\r\n");
});
```

`php artisan make:test --pest Meetings/RemindersTest --no-interaction` :

```php
<?php

use App\Models\AvailabilityDay;
use App\Models\Group;
use App\Models\Meeting;
use App\Models\User;
use App\Notifications\AvailabilityReminderNotification;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    Notification::fake();
    $this->travelTo(now('Europe/Paris')->setDate(2026, 11, 2)->setTime(9, 0));
    $this->group = Group::factory()->create();
    $this->answered = User::factory()->create();
    $this->silent = User::factory()->create();
    $this->group->addMember($this->answered);
    $this->group->addMember($this->silent);
});

test('members who have not answered are reminded the day before the deadline, once', function () {
    $meeting = Meeting::factory()->for($this->group)->create(['range_start' => '2026-11-02', 'range_end' => '2026-11-20', 'deadline' => '2026-11-03']);
    AvailabilityDay::factory()->for($meeting)->for($this->answered)->cells(str_repeat('p', 28))->create(['day' => '2026-11-05']);

    $this->artisan('meetings:send-reminders')->assertSuccessful();
    $this->artisan('meetings:send-reminders')->assertSuccessful();

    Notification::assertSentToTimes($this->silent, AvailabilityReminderNotification::class, 1);
    Notification::assertNotSentTo($this->answered, AvailabilityReminderNotification::class);
    expect($meeting->fresh()->reminder_sent_at)->not->toBeNull();
});

test('no reminder for other deadlines or for votes and confirmed meetings', function () {
    Meeting::factory()->for($this->group)->create(['range_start' => '2026-11-02', 'range_end' => '2026-11-20', 'deadline' => '2026-11-04']);
    Meeting::factory()->for($this->group)->voting()->create(['range_start' => '2026-11-02', 'range_end' => '2026-11-20', 'deadline' => '2026-11-03']);

    $this->artisan('meetings:send-reminders')->assertSuccessful();

    Notification::assertNothingSent();
});

test('the reminder runs every morning at 9 in Paris', function () {
    $this->artisan('schedule:list')->assertSuccessful();

    $event = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())
        ->first(fn ($event) => str_contains($event->command, 'meetings:send-reminders'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('0 9 * * *')
        ->and($event->timezone)->toBe('Europe/Paris');
});
```

- [ ] **Step 2 : Lancer, vérifier l'échec**

Run: `php artisan test --compact tests/Feature/Meetings/TransitionsTest.php tests/Feature/Meetings/RemindersTest.php`
Expected: FAIL (classes absentes).

- [ ] **Step 3 : Exception et actions**

`php artisan make:exception InvalidMeetingTransition --no-interaction` :

```php
<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Raised when a meeting request is asked to move to a state it cannot reach from its current one.
 */
class InvalidMeetingTransition extends RuntimeException {}
```

`app/Actions/Meetings/OpenVote.php` (`make:class`) :

```php
<?php

namespace App\Actions\Meetings;

use App\Enums\MeetingStatus;
use App\Exceptions\InvalidMeetingTransition;
use App\Models\Meeting;
use App\Notifications\VoteOpenedNotification;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

class OpenVote
{
    /**
     * @param  list<array{0: CarbonInterface, 1: CarbonInterface}>  $slots  start and end of each slot, in UTC
     */
    public function __invoke(Meeting $meeting, array $slots): void
    {
        $distinct = collect($slots)->unique(fn (array $slot): string => $slot[0]->getTimestamp().'-'.$slot[1]->getTimestamp());

        if ($meeting->status !== MeetingStatus::Collecting || $distinct->count() < 2) {
            throw new InvalidMeetingTransition(__('Choose at least two different slots to open a vote.'));
        }

        DB::transaction(function () use ($meeting, $distinct): void {
            $meeting->slots()->delete();

            foreach ($distinct as [$startsAt, $endsAt]) {
                $meeting->slots()->create(['starts_at' => $startsAt, 'ends_at' => $endsAt]);
            }

            $meeting->forceFill(['status' => MeetingStatus::Voting])->save();
        });

        Notification::send(
            $meeting->group->members()->whereKeyNot($meeting->created_by)->get(),
            new VoteOpenedNotification($meeting),
        );
    }
}
```

`app/Actions/Meetings/ConfirmMeeting.php` :

```php
<?php

namespace App\Actions\Meetings;

use App\Enums\MeetingStatus;
use App\Exceptions\InvalidMeetingTransition;
use App\Models\Meeting;
use App\Notifications\MeetingConfirmedNotification;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Notification;

class ConfirmMeeting
{
    public function __invoke(Meeting $meeting, CarbonInterface $startsAt, CarbonInterface $endsAt): void
    {
        if ($meeting->status === MeetingStatus::Confirmed) {
            throw new InvalidMeetingTransition(__('This meeting is already confirmed.'));
        }

        $meeting->forceFill([
            'status' => MeetingStatus::Confirmed,
            'confirmed_starts_at' => $startsAt->copy()->utc(),
            'confirmed_ends_at' => $endsAt->copy()->utc(),
        ])->save();

        Notification::send($meeting->group->members, new MeetingConfirmedNotification($meeting));
    }
}
```

`app/Actions/Meetings/CancelVote.php` :

```php
<?php

namespace App\Actions\Meetings;

use App\Enums\MeetingStatus;
use App\Exceptions\InvalidMeetingTransition;
use App\Models\Meeting;
use Illuminate\Support\Facades\DB;

class CancelVote
{
    public function __invoke(Meeting $meeting): void
    {
        if ($meeting->status !== MeetingStatus::Voting) {
            throw new InvalidMeetingTransition(__('There is no vote to cancel.'));
        }

        DB::transaction(function () use ($meeting): void {
            $meeting->slots()->delete();
            $meeting->forceFill(['status' => MeetingStatus::Collecting])->save();
        });
    }
}
```

`app/Actions/Meetings/BuildIcsCalendar.php` :

```php
<?php

namespace App\Actions\Meetings;

use App\Models\Meeting;

/**
 * Build an iCalendar file for a confirmed meeting, so members can add it to their calendar.
 */
class BuildIcsCalendar
{
    public function __invoke(Meeting $meeting): string
    {
        $lines = array_filter([
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//'.config('app.name').'//FR',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            'BEGIN:VEVENT',
            'UID:meeting-'.$meeting->id.'@'.(parse_url(config('app.url'), PHP_URL_HOST) ?: 'localhost'),
            'DTSTAMP:'.now()->utc()->format('Ymd\THis\Z'),
            'DTSTART:'.$meeting->confirmed_starts_at->copy()->utc()->format('Ymd\THis\Z'),
            'DTEND:'.$meeting->confirmed_ends_at->copy()->utc()->format('Ymd\THis\Z'),
            'SUMMARY:'.$this->escape($meeting->title),
            $meeting->location ? 'LOCATION:'.$this->escape($meeting->location) : null,
            'DESCRIPTION:'.$this->escape(route('meetings.show', $meeting)),
            'END:VEVENT',
            'END:VCALENDAR',
        ]);

        return implode("\r\n", $lines)."\r\n";
    }

    private function escape(string $text): string
    {
        return str_replace(['\\', ';', ',', "\r\n", "\n"], ['\\\\', '\;', '\,', '\n', '\n'], $text);
    }
}
```

- [ ] **Step 4 : Notifications**

Créer les quatre avec `php artisan make:notification <Nom> --no-interaction`, sans `ShouldQueue`, `via()` → `['mail']`. Formats de date : `$meeting->range_start->translatedFormat('j F')`, `$meeting->deadline->translatedFormat('l j F')`, et pour une date UTC `->copy()->setTimezone(config('app.display_timezone'))->translatedFormat('l j F à H\hi')`.

`MeetingRequestedNotification` :

```php
    public function __construct(public Meeting $meeting) {}

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('When are you available for :title?', ['title' => $this->meeting->title]))
            ->line(__(':group is organising :title between :start and :end.', [
                'group' => $this->meeting->group->name,
                'title' => $this->meeting->title,
                'start' => $this->meeting->range_start->translatedFormat('j F'),
                'end' => $this->meeting->range_end->translatedFormat('j F'),
            ]))
            ->line(__('Tell us when you are available, on site or remotely, before :date.', ['date' => $this->meeting->deadline->translatedFormat('l j F')]))
            ->action(__('Give my availability'), route('meetings.show', $this->meeting));
    }
```

`VoteOpenedNotification` :

```php
    public function __construct(public Meeting $meeting) {}

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject(__('Vote for the date of :title', ['title' => $this->meeting->title]))
            ->line(__('The organizer of :group proposes these slots:', ['group' => $this->meeting->group->name]));

        foreach ($this->meeting->slots as $slot) {
            $mail->line('• '.ucfirst($slot->startsAtLocal()->translatedFormat('l j F, H\hi')).' – '.$slot->endsAtLocal()->format('H\hi'));
        }

        return $mail->action(__('Vote'), route('meetings.show', $this->meeting));
    }
```

`MeetingConfirmedNotification` :

```php
    public function __construct(public Meeting $meeting) {}

    public function toMail(object $notifiable): MailMessage
    {
        $startsAt = $this->meeting->confirmed_starts_at->copy()->setTimezone(config('app.display_timezone'));
        $endsAt = $this->meeting->confirmed_ends_at->copy()->setTimezone(config('app.display_timezone'));

        $mail = (new MailMessage)
            ->subject(__(':title is confirmed', ['title' => $this->meeting->title]))
            ->line(__(':title will take place on :date, from :start to :end.', [
                'title' => $this->meeting->title,
                'date' => $startsAt->translatedFormat('l j F Y'),
                'start' => $startsAt->format('H\hi'),
                'end' => $endsAt->format('H\hi'),
            ]));

        if ($this->meeting->location) {
            $mail->line(__('Location: :location', ['location' => $this->meeting->location]));
        }

        return $mail
            ->action(__('See the meeting'), route('meetings.show', $this->meeting))
            ->attachData((new BuildIcsCalendar)($this->meeting), 'reunion.ics', ['mime' => 'text/calendar']);
    }
```

`AvailabilityReminderNotification` :

```php
    public function __construct(public Meeting $meeting) {}

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('Reminder: give your availability for :title', ['title' => $this->meeting->title]))
            ->line(__('Answers for :title are expected by tomorrow.', ['title' => $this->meeting->title]))
            ->action(__('Give my availability'), route('meetings.show', $this->meeting));
    }
```

- [ ] **Step 5 : Commande et planification**

`php artisan make:command SendAvailabilityReminders --no-interaction` :

```php
<?php

namespace App\Console\Commands;

use App\Enums\MeetingStatus;
use App\Models\Meeting;
use App\Notifications\AvailabilityReminderNotification;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification;

#[Signature('meetings:send-reminders')]
#[Description('Remind members who have not given their availability, the day before the deadline')]
class SendAvailabilityReminders extends Command
{
    public function handle(): int
    {
        $tomorrow = now(config('app.display_timezone'))->addDay()->toDateString();

        Meeting::query()
            ->where('status', MeetingStatus::Collecting->value)
            ->whereNull('reminder_sent_at')
            ->whereDate('deadline', $tomorrow)
            ->with('group.members')
            ->each(function (Meeting $meeting): void {
                $respondentIds = $meeting->respondentIds();

                Notification::send(
                    $meeting->group->members->reject(fn ($member) => $respondentIds->contains($member->id)),
                    new AvailabilityReminderNotification($meeting),
                );

                $meeting->forceFill(['reminder_sent_at' => now()])->save();
            });

        return self::SUCCESS;
    }
}
```

(Si les attributs `#[Signature]` / `#[Description]` ne sont pas disponibles dans la version installée, utiliser les propriétés `$signature` / `$description` générées par `make:command`.)

Dans `routes/console.php`, ajouter :

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('meetings:send-reminders')->dailyAt('09:00')->timezone('Europe/Paris');
```

- [ ] **Step 6 : Traductions**

```json
":group is organising :title between :start and :end.": ":group organise :title entre le :start et le :end.",
":title is confirmed": ":title est confirmée",
":title will take place on :date, from :start to :end.": ":title aura lieu le :date, de :start à :end.",
"Answers for :title are expected by tomorrow.": "Les réponses pour :title sont attendues d'ici demain.",
"Choose at least two different slots to open a vote.": "Choisissez au moins deux créneaux différents pour ouvrir un vote.",
"Give my availability": "Indiquer mes disponibilités",
"Location: :location": "Lieu : :location",
"Reminder: give your availability for :title": "Rappel : indiquez vos disponibilités pour :title",
"See the meeting": "Voir la réunion",
"Tell us when you are available, on site or remotely, before :date.": "Indiquez quand vous êtes disponible, sur place ou à distance, avant le :date.",
"The organizer of :group proposes these slots:": "L'organisateur de :group propose ces créneaux :",
"There is no vote to cancel.": "Il n'y a pas de vote à annuler.",
"This meeting is already confirmed.": "Cette réunion est déjà confirmée.",
"Vote": "Voter",
"Vote for the date of :title": "Votez pour la date de :title",
"When are you available for :title?": "Quand êtes-vous disponible pour :title ?"
```

- [ ] **Step 7 : Lancer les tests**

Run: `php artisan test --compact tests/Feature/Meetings/TransitionsTest.php tests/Feature/Meetings/RemindersTest.php`
Expected: PASS.

- [ ] **Step 8 : Commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Exceptions app/Actions/Meetings app/Notifications app/Console routes/console.php lang/fr.json tests/Feature/Meetings
git commit -m "Meeting transitions, emails, calendar file and reminders"
```

---

### Task 4 : Créer, modifier et lister les demandes

**Files:**
- Modify: `app/Livewire/Meetings/Form.php` + `resources/views/livewire/meetings/form.blade.php` (remplace le squelette), `routes/web.php`, `app/Livewire/Groups/Show.php`, `resources/views/livewire/groups/show.blade.php`, `lang/fr.json`
- Test: `tests/Feature/Meetings/MeetingFormTest.php`, `tests/Feature/Groups/GroupPageTest.php` (ajout)

**Interfaces:**
- Consumes: `Meeting` (tâche 1), `MeetingRequestedNotification` (tâche 3), `GroupPolicy::manage`, `MeetingPolicy::manage`.
- Produces: routes `meetings.create` (existe) et `meetings.edit` (GET `meetings/{meeting}/edit`) ; `Meetings\Form` : `title`, `description`, `location`, `rangeStart`, `rangeEnd`, `deadline` (chaînes `Y-m-d`) ; actions `save()`, `deleteMeeting()`.

- [ ] **Step 1 : Écrire les tests qui échouent**

`php artisan make:test --pest Meetings/MeetingFormTest --no-interaction` :

```php
<?php

use App\Enums\MeetingStatus;
use App\Livewire\Meetings\Form;
use App\Models\AvailabilityDay;
use App\Models\Group;
use App\Models\Meeting;
use App\Models\User;
use App\Notifications\MeetingRequestedNotification;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

beforeEach(function () {
    Notification::fake();
    $this->travelTo(now('Europe/Paris')->setDate(2026, 11, 2)->setTime(10, 0));
    $this->group = Group::factory()->create();
    $this->organizer = $this->group->owner;
    $this->member = User::factory()->create();
    $this->group->addMember($this->member);
});

function fillRequest($component, array $overrides = [])
{
    $values = array_merge([
        'title' => 'Assemblée de rentrée',
        'location' => 'Salle des fêtes',
        'rangeStart' => '2026-11-09',
        'rangeEnd' => '2026-11-20',
        'deadline' => '2026-11-06',
    ], $overrides);

    foreach ($values as $property => $value) {
        $component->set($property, $value);
    }

    return $component;
}

test('the organizer creates a request and members are emailed', function () {
    fillRequest(Livewire::actingAs($this->organizer)->test(Form::class, ['group' => $this->group]))
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect(route('meetings.show', Meeting::sole()));

    $meeting = Meeting::sole();
    expect($meeting->status)->toBe(MeetingStatus::Collecting)
        ->and($meeting->range_start->toDateString())->toBe('2026-11-09')
        ->and($meeting->deadline->toDateString())->toBe('2026-11-06');
    Notification::assertSentTo($this->member, MeetingRequestedNotification::class);
    Notification::assertNotSentTo($this->organizer, MeetingRequestedNotification::class);
});

test('range and deadline are validated', function (array $overrides, string $error) {
    fillRequest(Livewire::actingAs($this->organizer)->test(Form::class, ['group' => $this->group]), $overrides)
        ->call('save')
        ->assertHasErrors($error);

    expect(Meeting::count())->toBe(0);
})->with([
    'start in the past' => [['rangeStart' => '2026-11-01'], 'rangeStart'],
    'end before start' => [['rangeEnd' => '2026-11-08'], 'rangeEnd'],
    'more than 62 days' => [['rangeEnd' => '2027-01-12'], 'rangeEnd'],
    'deadline after the range' => [['deadline' => '2026-11-21'], 'deadline'],
    'deadline in the past' => [['deadline' => '2026-11-01'], 'deadline'],
    'missing title' => [['title' => ''], 'title'],
]);

test('shrinking the range deletes availabilities outside of it', function () {
    $meeting = Meeting::factory()->for($this->group)->create(['range_start' => '2026-11-09', 'range_end' => '2026-11-20', 'deadline' => '2026-11-06']);
    AvailabilityDay::factory()->for($meeting)->for($this->member)->cells(str_repeat('p', 28))->create(['day' => '2026-11-10']);
    AvailabilityDay::factory()->for($meeting)->for($this->member)->cells(str_repeat('p', 28))->create(['day' => '2026-11-19']);

    Livewire::actingAs($this->organizer)
        ->test(Form::class, ['meeting' => $meeting])
        ->assertSet('rangeEnd', '2026-11-20')
        ->set('rangeEnd', '2026-11-15')
        ->call('save')
        ->assertHasNoErrors();

    expect(AvailabilityDay::pluck('day')->all())->toBe(['2026-11-10']);
});

test('only requests still collecting can be edited', function () {
    $voting = Meeting::factory()->for($this->group)->voting()->create();

    $this->actingAs($this->organizer)->get(route('meetings.edit', $voting))->assertForbidden();
});

test('the organizer deletes a request', function () {
    $meeting = Meeting::factory()->for($this->group)->create();

    Livewire::actingAs($this->organizer)
        ->test(Form::class, ['meeting' => $meeting])
        ->call('deleteMeeting')
        ->assertRedirect(route('groups.show', $this->group));

    expect(Meeting::count())->toBe(0);
});

test('members cannot create or edit, outsiders get a 404', function () {
    $meeting = Meeting::factory()->for($this->group)->create();

    $this->actingAs($this->member)->get(route('meetings.create', $this->group))->assertForbidden();
    $this->actingAs($this->member)->get(route('meetings.edit', $meeting))->assertForbidden();
    $this->actingAs(User::factory()->create())->get(route('meetings.create', $this->group))->assertNotFound();
});
```

Ajouter à `tests/Feature/Groups/GroupPageTest.php` :

```php
test('the group page lists requests with their state', function () {
    Meeting::factory()->for($this->group)->create(['title' => 'Demande en collecte']);
    Meeting::factory()->for($this->group)->confirmed(now()->addWeek())->create(['title' => 'Réunion confirmée']);

    $this->actingAs($this->member)
        ->get(route('groups.show', $this->group))
        ->assertSee('Demande en collecte')
        ->assertSee(__('Collecting availabilities'))
        ->assertSee('Réunion confirmée')
        ->assertSee(__('Confirmed'));
});
```

- [ ] **Step 2 : Lancer, vérifier l'échec**

Run: `php artisan test --compact tests/Feature/Meetings/MeetingFormTest.php tests/Feature/Groups/GroupPageTest.php`
Expected: FAIL.

- [ ] **Step 3 : Composant**

`app/Livewire/Meetings/Form.php` :

```php
<?php

namespace App\Livewire\Meetings;

use App\Enums\MeetingStatus;
use App\Models\Group;
use App\Models\Meeting;
use App\Notifications\MeetingRequestedNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
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

        $this->validate([
            'title' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:2000'],
            'location' => ['nullable', 'string', 'max:255'],
            'rangeStart' => ['required', 'date_format:Y-m-d', 'after_or_equal:'.$today],
            'rangeEnd' => ['required', 'date_format:Y-m-d', 'after_or_equal:rangeStart'],
            'deadline' => ['required', 'date_format:Y-m-d', 'after_or_equal:'.$today, 'before_or_equal:rangeEnd'],
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
            Notification::send(
                $this->group->members()->whereKeyNot(Auth::id())->get(),
                new MeetingRequestedNotification($meeting),
            );
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
```

`resources/views/livewire/meetings/form.blade.php` :

```blade
<div class="mx-auto flex w-full max-w-2xl flex-col gap-8">
    <div>
        <flux:link :href="route('groups.show', $group)" wire:navigate class="text-sm">← {{ $group->name }}</flux:link>
        <flux:heading size="xl" level="1" class="mt-2 text-3xl! font-extrabold!">{{ $meeting ? __('Edit the request') : __('New availability request') }}</flux:heading>
        <flux:text class="mt-1">{{ __('Members will color their availability over this range, on site or remotely.') }}</flux:text>
    </div>

    <form wire:submit="save" class="flex flex-col gap-6">
        <flux:input wire:model="title" :label="__('Title')" :placeholder="__('e.g. Back-to-school general meeting')" required />
        <flux:input wire:model="location" :label="__('Location')" :description="__('Optional')" />
        <flux:textarea wire:model="description" :label="__('Description')" :description="__('Optional')" rows="3" />

        <flux:card class="flex flex-col gap-4">
            <flux:heading>{{ __('Dates') }}</flux:heading>
            <div class="grid gap-4 sm:grid-cols-2">
                <flux:date-picker wire:model="rangeStart" :label="__('From')" locale="fr" :min="today(config('app.display_timezone'))->toDateString()" with-today />
                <flux:date-picker wire:model="rangeEnd" :label="__('To')" locale="fr" :min="today(config('app.display_timezone'))->toDateString()" />
            </div>
            <flux:date-picker wire:model="deadline" :label="__('Answer before')" :description="__('Indicative: members can still change their availability until you confirm a date.')" locale="fr" :min="today(config('app.display_timezone'))->toDateString()" />
            <flux:text size="sm">{{ __('At most :days days. Times are in Paris time.', ['days' => \App\Livewire\Meetings\Form::MaxRangeDays]) }}</flux:text>
        </flux:card>

        <div class="flex flex-wrap justify-between gap-3">
            @if ($meeting)
                <flux:button variant="danger" wire:click="deleteMeeting" wire:confirm="{{ __('Delete this request and all its answers?') }}">{{ __('Delete the request') }}</flux:button>
            @endif
            <flux:button variant="primary" type="submit" class="ms-auto">{{ $meeting ? __('Save') : __('Send the request') }}</flux:button>
        </div>
    </form>
</div>
```

- [ ] **Step 4 : Route**

Dans le groupe `auth` + `verified` de `routes/web.php` : `Route::livewire('meetings/{meeting}/edit', MeetingsForm::class)->name('meetings.edit');`

- [ ] **Step 5 : Liste dans la page du groupe**

Dans `resources/views/livewire/groups/show.blade.php`, remplacer la carte de chaque demande à venir par :

```blade
            <a wire:key="meeting-{{ $meeting->id }}" href="{{ route('meetings.show', $meeting) }}" wire:navigate class="group/card block">
                <flux:card class="flex flex-wrap items-center justify-between gap-4 transition group-hover/card:border-forest! dark:group-hover/card:border-sun!">
                    <div>
                        <flux:heading>{{ $meeting->title }}</flux:heading>
                        <flux:text>
                            @if ($meeting->status === \App\Enums\MeetingStatus::Confirmed)
                                {{ ucfirst($meeting->confirmed_starts_at->copy()->setTimezone(config('app.display_timezone'))->translatedFormat('l j F · H\hi')) }}
                            @else
                                {{ __('Answer before :date', ['date' => $meeting->deadline->translatedFormat('j F')]) }} · {{ trans_choice(':count answer|:count answers', $meeting->respondentIds()->count()) }}
                            @endif
                        </flux:text>
                    </div>
                    <flux:badge size="sm" :color="match ($meeting->status) { \App\Enums\MeetingStatus::Confirmed => 'green', \App\Enums\MeetingStatus::Voting => 'blue', default => 'yellow' }">{{ $meeting->status->label() }}</flux:badge>
                </flux:card>
            </a>
```

Remplacer le libellé du bouton « Nouvelle réunion » par `__('New request')` et le titre de section par `__('Meetings')`.

- [ ] **Step 6 : Traductions**

```json
"Answer before": "Répondre avant le",
"At most :days days. Times are in Paris time.": "Au maximum :days jours. Les heures sont celles de Paris.",
"Dates": "Dates",
"Delete the request": "Supprimer la demande",
"Delete this request and all its answers?": "Supprimer cette demande et toutes ses réponses ?",
"Edit the request": "Modifier la demande",
"From": "Du",
"Indicative: members can still change their availability until you confirm a date.": "Indicative : les membres peuvent modifier leurs disponibilités tant que vous n'avez pas validé de date.",
"Meetings": "Réunions",
"Members will color their availability over this range, on site or remotely.": "Les membres colorieront leurs disponibilités sur cette période, sur place ou à distance.",
"New availability request": "Nouvelle demande de disponibilités",
"New request": "Nouvelle demande",
"Send the request": "Envoyer la demande",
"The range cannot exceed :days days.": "La période ne peut pas dépasser :days jours.",
"To": "Au"
```

(`Title`, `Location`, `Description`, `Optional`, `Save`, `e.g. Back-to-school general meeting` peuvent déjà exister : ne pas dupliquer, ajouter ceux qui manquent avec les traductions « Titre », « Lieu », « Description », « Facultatif », « Enregistrer », « ex. : Assemblée de rentrée ».)

- [ ] **Step 7 : Lancer les tests**

Run: `php artisan test --compact tests/Feature/Meetings/MeetingFormTest.php tests/Feature/Groups`
Expected: PASS.

- [ ] **Step 8 : Commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Livewire resources/views routes/web.php lang/fr.json tests/Feature
git commit -m "Create, edit and list availability requests"
```

---

### Task 5 : La grille de disponibilités

**Files:**
- Create: `app/Livewire/Meetings/AvailabilityGrid.php`, `resources/views/livewire/meetings/availability-grid.blade.php`, `resources/js/availability-grid.js`
- Modify: `resources/js/app.js`, `lang/fr.json`
- Test: `tests/Feature/Meetings/AvailabilityGridTest.php`

**Interfaces:**
- Consumes: `AvailabilityDay`, `Meeting::rangeDays()`, `MeetingPolicy::view` / `editAvailability`.
- Produces: composant `<livewire:meetings.availability-grid :meeting="$meeting" />` ; action `saveDays(array $days): void` (`day` → 28 cases) ; computed `weeks` (`list<list<array{date: string, label: string, inRange: bool}>>`), `cells` (`array<string, string>`), `respondentCount`, `memberCount`, `canEdit`.

- [ ] **Step 1 : Écrire les tests qui échouent**

`php artisan make:test --pest Meetings/AvailabilityGridTest --no-interaction` :

```php
<?php

use App\Livewire\Meetings\AvailabilityGrid;
use App\Models\AvailabilityDay;
use App\Models\Group;
use App\Models\Meeting;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->travelTo(now('Europe/Paris')->setDate(2026, 11, 2)->setTime(10, 0));
    $this->group = Group::factory()->create();
    $this->member = User::factory()->create();
    $this->group->addMember($this->member);
    $this->meeting = Meeting::factory()->for($this->group)->create(['range_start' => '2026-11-04', 'range_end' => '2026-11-10', 'deadline' => '2026-11-03']);
});

test('a member saves days and an empty day is removed', function () {
    $evening = str_repeat('0', 20).'pppp'.str_repeat('0', 4);

    Livewire::actingAs($this->member)
        ->test(AvailabilityGrid::class, ['meeting' => $this->meeting])
        ->call('saveDays', ['2026-11-04' => $evening, '2026-11-05' => $evening])
        ->assertHasNoErrors();

    expect(AvailabilityDay::where('user_id', $this->member->id)->orderBy('day')->pluck('cells', 'day')->all())
        ->toBe(['2026-11-04' => $evening, '2026-11-05' => $evening]);

    $component = Livewire::actingAs($this->member)->test(AvailabilityGrid::class, ['meeting' => $this->meeting]);

    expect($component->instance()->cells)->toBe(['2026-11-04' => $evening, '2026-11-05' => $evening]);

    $component->call('saveDays', ['2026-11-05' => AvailabilityDay::Empty]);

    expect(AvailabilityDay::pluck('day')->all())->toBe(['2026-11-04']);
});

test('days outside the range or malformed cells are refused', function (array $days) {
    Livewire::actingAs($this->member)
        ->test(AvailabilityGrid::class, ['meeting' => $this->meeting])
        ->call('saveDays', $days)
        ->assertHasErrors('days');

    expect(AvailabilityDay::count())->toBe(0);
})->with([
    'before the range' => [['2026-11-03' => str_repeat('p', 28)]],
    'after the range' => [['2026-11-11' => str_repeat('p', 28)]],
    'wrong length' => [['2026-11-04' => str_repeat('p', 27)]],
    'wrong letters' => [['2026-11-04' => str_repeat('x', 28)]],
]);

test('the deadline is indicative: saving after it is still allowed', function () {
    $this->travelTo(now('Europe/Paris')->setDate(2026, 11, 6));

    Livewire::actingAs($this->member)
        ->test(AvailabilityGrid::class, ['meeting' => $this->meeting])
        ->call('saveDays', ['2026-11-07' => str_repeat('d', 28)])
        ->assertHasNoErrors();

    expect(AvailabilityDay::count())->toBe(1);
});

test('the grid is read only once the vote has started', function () {
    $this->meeting->forceFill(['status' => 'voting'])->save();

    $component = Livewire::actingAs($this->member)->test(AvailabilityGrid::class, ['meeting' => $this->meeting]);

    expect($component->instance()->canEdit)->toBeFalse();

    $component->call('saveDays', ['2026-11-04' => str_repeat('p', 28)])->assertForbidden();
});

test('each member only writes their own grid, outsiders get a 404', function () {
    $other = User::factory()->create();
    $this->group->addMember($other);

    Livewire::actingAs($other)
        ->test(AvailabilityGrid::class, ['meeting' => $this->meeting])
        ->call('saveDays', ['2026-11-04' => str_repeat('p', 28)]);

    expect(AvailabilityDay::sole()->user_id)->toBe($other->id);

    Livewire::actingAs(User::factory()->create())
        ->test(AvailabilityGrid::class, ['meeting' => $this->meeting])
        ->assertNotFound();
});

test('weeks run from monday to sunday and mark days outside the range', function () {
    $weeks = Livewire::actingAs($this->member)
        ->test(AvailabilityGrid::class, ['meeting' => $this->meeting])
        ->instance()->weeks;

    expect($weeks)->toHaveCount(2)
        ->and($weeks[0][0])->toMatchArray(['date' => '2026-11-02', 'inRange' => false])
        ->and($weeks[0][2])->toMatchArray(['date' => '2026-11-04', 'inRange' => true])
        ->and($weeks[1][1])->toMatchArray(['date' => '2026-11-10', 'inRange' => true])
        ->and($weeks[1][2])->toMatchArray(['date' => '2026-11-11', 'inRange' => false]);
});
```

- [ ] **Step 2 : Lancer, vérifier l'échec**

Run: `php artisan test --compact tests/Feature/Meetings/AvailabilityGridTest.php`
Expected: FAIL.

- [ ] **Step 3 : Composant**

`php artisan make:livewire Meetings/AvailabilityGrid --class --no-interaction` (convertir au format classe sans `render()` si besoin), puis :

```php
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
```

- [ ] **Step 4 : Alpine**

`resources/js/availability-grid.js` :

```js
const EMPTY = '0'.repeat(28);
const NEXT = { 0: 'p', p: 'd', d: '0' };

document.addEventListener('alpine:init', () => {
    window.Alpine.data('availabilityGrid', ({ weeks, cells, canEdit }) => ({
        weeks,
        cells: { ...cells },
        canEdit,
        week: Math.max(0, weeks.findIndex((week) => week.some((day) => day.inRange))),
        mobileDay: 0,
        painting: false,
        paintValue: '0',
        dirty: new Set(),
        status: 'idle',

        get currentWeek() {
            return this.weeks[this.week];
        },

        value(date, index) {
            return (this.cells[date] ?? EMPTY)[index];
        },

        set(date, index, value) {
            const day = this.currentWeek.find((candidate) => candidate.date === date);

            if (!this.canEdit || !day?.inRange || this.value(date, index) === value) {
                return;
            }

            const current = this.cells[date] ?? EMPTY;
            this.cells[date] = current.slice(0, index) + value + current.slice(index + 1);
            this.dirty.add(date);
        },

        start(event) {
            const cell = event.target.closest('[data-cell]');

            if (!this.canEdit || !cell) {
                return;
            }

            event.preventDefault();
            this.painting = true;
            this.paintValue = NEXT[this.value(cell.dataset.date, Number(cell.dataset.index))];
            this.set(cell.dataset.date, Number(cell.dataset.index), this.paintValue);
        },

        move(event) {
            if (!this.painting) {
                return;
            }

            const cell = document.elementFromPoint(event.clientX, event.clientY)?.closest('[data-cell]');

            if (cell) {
                this.set(cell.dataset.date, Number(cell.dataset.index), this.paintValue);
            }
        },

        end() {
            if (this.painting) {
                this.painting = false;
                this.save();
            }
        },

        cycle(date, index) {
            this.set(date, index, NEXT[this.value(date, index)]);
            this.save();
        },

        copyToWeek(date) {
            this.currentWeek.filter((day) => day.inRange && day.date !== date).forEach((day) => {
                if ((this.cells[day.date] ?? EMPTY) !== (this.cells[date] ?? EMPTY)) {
                    this.cells[day.date] = this.cells[date] ?? EMPTY;
                    this.dirty.add(day.date);
                }
            });
            this.save();
        },

        save() {
            if (this.dirty.size === 0) {
                return;
            }

            const days = Object.fromEntries([...this.dirty].map((date) => [date, this.cells[date] ?? EMPTY]));
            this.dirty.clear();
            this.status = 'saving';

            this.$wire.saveDays(days)
                .then(() => { this.status = 'saved'; })
                .catch(() => {
                    Object.keys(days).forEach((date) => this.dirty.add(date));
                    this.status = 'error';
                });
        },
    }));
});
```

`resources/js/app.js` : ajouter `import './availability-grid';`.

- [ ] **Step 5 : Vue**

`resources/views/livewire/meetings/availability-grid.blade.php` :

```blade
<div
    x-data="availabilityGrid({ weeks: @js($this->weeks), cells: @js($this->cells), canEdit: @js($this->canEdit) })"
    x-on:pointerup.window="end()"
    x-on:pointercancel.window="end()"
    class="flex flex-col gap-4"
>
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="flex flex-wrap items-center gap-3 text-sm">
            <span class="inline-flex items-center gap-1.5"><span class="size-4 rounded bg-forest dark:bg-sun"></span>{{ __('On site') }}</span>
            <span class="inline-flex items-center gap-1.5"><span class="size-4 rounded bg-blush"></span>{{ __('Remote') }}</span>
            <span class="inline-flex items-center gap-1.5"><span class="size-4 rounded border border-zinc-300 dark:border-white/20"></span>{{ __('Unavailable') }}</span>
        </div>
        <flux:text size="sm">
            {{ trans_choice(':count person has answered|:count people have answered', $this->respondentCount) }} {{ __('out of :total', ['total' => $this->memberCount]) }}
            · <span x-show="status === 'saving'">{{ __('Saving…') }}</span><span x-show="status === 'saved'" x-cloak>{{ __('Saved') }}</span><span x-show="status === 'error'" x-cloak class="text-red-600">{{ __('Not saved, try again') }}</span>
        </flux:text>
    </div>

    @if ($this->canEdit)
        <flux:callout icon="cursor-arrow-rays" :heading="__('Tap or drag over the grid')" :text="__('Once for on site, twice for remote, three times to clear. Times are in Paris time.')" />
    @else
        <flux:callout icon="lock-closed" :heading="__('The grid is closed')" :text="__('The organizer is choosing the date from everyone\'s availability.')" />
    @endif

    <div class="flex items-center justify-between gap-2">
        <flux:button size="sm" icon="chevron-left" x-on:click="week = Math.max(0, week - 1); mobileDay = 0" x-bind:disabled="week === 0" :aria-label="__('Previous week')" />
        <flux:heading x-text="currentWeek[0].label + ' – ' + currentWeek[6].label"></flux:heading>
        <flux:button size="sm" icon="chevron-right" x-on:click="week = Math.min(weeks.length - 1, week + 1); mobileDay = 0" x-bind:disabled="week === weeks.length - 1" :aria-label="__('Next week')" />
    </div>

    {{-- Mobile: one day at a time --}}
    <div class="flex gap-1 overflow-x-auto sm:hidden">
        <template x-for="(day, index) in currentWeek" :key="day.date">
            <button type="button" x-on:click="mobileDay = index" x-text="day.label" x-bind:disabled="!day.inRange"
                class="shrink-0 rounded-full px-3 py-1 text-sm disabled:opacity-40"
                x-bind:class="mobileDay === index ? 'bg-forest text-sun dark:bg-sun dark:text-forest' : 'bg-zinc-100 dark:bg-white/10'"></button>
        </template>
    </div>

    <flux:card class="overflow-x-auto p-2!">
        <div class="grid select-none gap-px" style="grid-template-columns: 3.5rem repeat(7, minmax(2.75rem, 1fr));" x-on:pointerdown="start($event)" x-on:pointermove="move($event)">
            <div></div>
            <template x-for="(day, index) in currentWeek" :key="'head-' + day.date">
                <div class="px-1 pb-2 text-center text-xs font-semibold" x-bind:class="{ 'max-sm:hidden': index !== mobileDay, 'opacity-40': !day.inRange }">
                    <span x-text="day.label"></span>
                    <button type="button" x-show="canEdit && day.inRange" x-on:click="copyToWeek(day.date)" class="mt-1 block w-full text-[11px] font-normal underline opacity-70 hover:opacity-100">{{ __('Copy to week') }}</button>
                </div>
            </template>

            @foreach (range(0, \App\Models\AvailabilityDay::CellCount - 1) as $cell)
                <div class="pe-2 text-end text-xs tabular-nums text-zinc-500 dark:text-zinc-400" style="touch-action: pan-y;">
                    @if ($cell % 2 === 0) {{ \App\Models\AvailabilityDay::cellTime($cell) }} @endif
                </div>
                <template x-for="(day, index) in currentWeek" :key="day.date + '-{{ $cell }}'">
                    <button type="button" data-cell x-bind:data-date="day.date" data-index="{{ $cell }}"
                        x-on:keydown.space.prevent="cycle(day.date, {{ $cell }})"
                        x-on:keydown.enter.prevent="cycle(day.date, {{ $cell }})"
                        x-bind:disabled="!canEdit || !day.inRange"
                        x-bind:aria-label="day.label + ' {{ \App\Models\AvailabilityDay::cellTime($cell) }}'"
                        x-bind:aria-pressed="value(day.date, {{ $cell }}) !== '0'"
                        class="h-5 rounded-sm border border-zinc-200 transition-colors disabled:cursor-not-allowed dark:border-white/10 @if ($cell % 2 === 1) mb-0.5 @endif"
                        style="touch-action: none;"
                        x-bind:class="{
                            'max-sm:hidden': index !== mobileDay,
                            'bg-zinc-100 dark:bg-white/5': !day.inRange,
                            'bg-forest dark:bg-sun': day.inRange && value(day.date, {{ $cell }}) === 'p',
                            'bg-blush': day.inRange && value(day.date, {{ $cell }}) === 'd',
                            'bg-white dark:bg-night': day.inRange && value(day.date, {{ $cell }}) === '0',
                        }"></button>
                </template>
            @endforeach
        </div>
    </flux:card>
</div>
```

- [ ] **Step 6 : Traductions**

```json
":count person has answered|:count people have answered": ":count personne a répondu|:count personnes ont répondu",
"Copy to week": "Copier sur la semaine",
"Next week": "Semaine suivante",
"Not saved, try again": "Non enregistré, réessayez",
"Once for on site, twice for remote, three times to clear. Times are in Paris time.": "Une fois pour sur place, deux fois pour à distance, trois fois pour effacer. Les heures sont celles de Paris.",
"out of :total": "sur :total",
"Previous week": "Semaine précédente",
"Saved": "Enregistré",
"Saving…": "Enregistrement…",
"Tap or drag over the grid": "Touchez ou glissez sur la grille",
"The grid is closed": "La grille est fermée",
"The organizer is choosing the date from everyone's availability.": "L'organisateur choisit la date à partir des disponibilités de chacun.",
"This availability could not be saved.": "Ces disponibilités n'ont pas pu être enregistrées."
```

- [ ] **Step 7 : Lancer les tests et compiler**

Run: `php artisan test --compact tests/Feature/Meetings/AvailabilityGridTest.php && npm run build`
Expected: PASS ; build sans erreur.

- [ ] **Step 8 : Commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Livewire/Meetings/AvailabilityGrid.php resources/views/livewire/meetings/availability-grid.blade.php resources/js lang/fr.json tests/Feature/Meetings/AvailabilityGridTest.php
git commit -m "Availability grid for members"
```

---

### Task 6 : Résumé, validation directe et ouverture du vote

**Files:**
- Create: `app/Livewire/Meetings/Summary.php`, `resources/views/livewire/meetings/summary.blade.php`
- Modify: `lang/fr.json`
- Test: `tests/Feature/Meetings/SummaryTest.php`

**Interfaces:**
- Consumes: `FindBestWindows` (tâche 2), `ConfirmMeeting`, `OpenVote` (tâche 3), `AvailabilityDay::localDateTime()`, `Meeting::cellsByMember()`, `MeetingPolicy::manage`.
- Produces: `<livewire:meetings.summary :meeting="$meeting" />` ; propriétés URL `duration`, `minParticipants`, `minOnSite` ; `array $selected` (clés de lignes) ; `array $starts` (clé → case de début choisie) ; actions `confirmWindow(string $key)`, `openVote()` ; computed `windows` (lignes avec `key`), `members`, `nonRespondents`. Clé de ligne : `"{day}|{firstStart}"`.

- [ ] **Step 1 : Écrire les tests qui échouent**

`php artisan make:test --pest Meetings/SummaryTest --no-interaction` :

```php
<?php

use App\Enums\MeetingStatus;
use App\Livewire\Meetings\Summary;
use App\Models\AvailabilityDay;
use App\Models\Group;
use App\Models\Meeting;
use App\Models\User;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

beforeEach(function () {
    Notification::fake();
    $this->travelTo(now('Europe/Paris')->setDate(2026, 10, 20)->setTime(10, 0));
    $this->group = Group::factory()->create();
    $this->organizer = $this->group->owner;
    $this->amina = User::factory()->create(['name' => 'Amina']);
    $this->bastien = User::factory()->create(['name' => 'Bastien']);
    $this->silent = User::factory()->create(['name' => 'Chloé']);
    foreach ([$this->amina, $this->bastien, $this->silent] as $member) {
        $this->group->addMember($member);
    }
    $this->meeting = Meeting::factory()->for($this->group)->create(['range_start' => '2026-10-24', 'range_end' => '2026-10-26', 'deadline' => '2026-10-23']);
    $evening = str_repeat('0', 20).'pppppp00';
    AvailabilityDay::factory()->for($this->meeting)->for($this->amina)->cells($evening)->create(['day' => '2026-10-25']);
    AvailabilityDay::factory()->for($this->meeting)->for($this->bastien)->cells(str_repeat('0', 20).'dddddd00')->create(['day' => '2026-10-25']);
    AvailabilityDay::factory()->for($this->meeting)->for($this->amina)->cells($evening)->create(['day' => '2026-10-26']);
});

test('the organizer sees the best windows and who has not answered', function () {
    $component = Livewire::actingAs($this->organizer)->test(Summary::class, ['meeting' => $this->meeting]);
    $windows = $component->instance()->windows;

    expect($windows[0])->toMatchArray(['day' => '2026-10-25', 'firstStart' => 20, 'lastStart' => 22, 'key' => '2026-10-25|20'])
        ->and($component->instance()->nonRespondents->pluck('name')->all())->toEqualCanonicalizing(['Chloé', $this->organizer->name]);

    $component->assertSee('Amina')->assertSee('Bastien');
});

test('settings narrow the windows', function () {
    Livewire::actingAs($this->organizer)
        ->test(Summary::class, ['meeting' => $this->meeting])
        ->set('minParticipants', 2)
        ->set('minOnSite', 2)
        ->assertSee(__('No slot matches these criteria'));
});

test('confirming a window stores local times in UTC across the clock change', function () {
    Livewire::actingAs($this->organizer)
        ->test(Summary::class, ['meeting' => $this->meeting])
        ->set('starts.2026-10-25|20', 21)
        ->call('confirmWindow', '2026-10-25|20')
        ->assertRedirect(route('meetings.show', $this->meeting));

    $meeting = $this->meeting->fresh();
    expect($meeting->status)->toBe(MeetingStatus::Confirmed)
        ->and($meeting->confirmed_starts_at->format('Y-m-d H:i'))->toBe('2026-10-25 17:30')
        ->and($meeting->confirmed_ends_at->format('Y-m-d H:i'))->toBe('2026-10-25 19:30');
});

test('a start outside the window is refused', function () {
    Livewire::actingAs($this->organizer)
        ->test(Summary::class, ['meeting' => $this->meeting])
        ->set('starts.2026-10-25|20', 5)
        ->call('confirmWindow', '2026-10-25|20')
        ->assertHasErrors('starts');

    expect($this->meeting->fresh()->status)->toBe(MeetingStatus::Collecting);
});

test('several windows open a vote, duplicates are merged and one is not enough', function () {
    Livewire::actingAs($this->organizer)
        ->test(Summary::class, ['meeting' => $this->meeting])
        ->set('selected', ['2026-10-25|20'])
        ->call('openVote')
        ->assertHasErrors('selected');

    Livewire::actingAs($this->organizer)
        ->test(Summary::class, ['meeting' => $this->meeting])
        ->set('selected', ['2026-10-25|20', '2026-10-26|20'])
        ->call('openVote')
        ->assertRedirect(route('meetings.show', $this->meeting));

    expect($this->meeting->fresh()->status)->toBe(MeetingStatus::Voting)
        ->and($this->meeting->slots()->count())->toBe(2);
});

test('members cannot see the summary, outsiders get a 404', function () {
    Livewire::actingAs($this->amina)->test(Summary::class, ['meeting' => $this->meeting])->assertForbidden();
    Livewire::actingAs(User::factory()->create())->test(Summary::class, ['meeting' => $this->meeting])->assertNotFound();
});
```

- [ ] **Step 2 : Lancer, vérifier l'échec**

Run: `php artisan test --compact tests/Feature/Meetings/SummaryTest.php`
Expected: FAIL.

- [ ] **Step 3 : Composant**

`app/Livewire/Meetings/Summary.php` :

```php
<?php

namespace App\Livewire\Meetings;

use App\Actions\Meetings\ConfirmMeeting;
use App\Actions\Meetings\FindBestWindows;
use App\Actions\Meetings\OpenVote;
use App\Exceptions\InvalidMeetingTransition;
use App\Models\AvailabilityDay;
use App\Models\Meeting;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;

class Summary extends Component
{
    public Meeting $meeting;

    #[Url]
    public int $duration = 120;

    #[Url(as: 'min')]
    public int $minParticipants = 1;

    #[Url(as: 'on_site')]
    public int $minOnSite = 0;

    /** @var list<string> */
    public array $selected = [];

    /** @var array<string, int|string> */
    public array $starts = [];

    public function mount(Meeting $meeting): void
    {
        $this->authorize('manage', $meeting);

        $this->meeting = $meeting;
    }

    public function hydrate(): void
    {
        $this->authorize('manage', $this->meeting);
    }

    public function updated(): void
    {
        $this->duration = max(30, min(480, intdiv($this->duration, 30) * 30));
        $this->minParticipants = max(1, $this->minParticipants);
        $this->minOnSite = max(0, min($this->minOnSite, $this->minParticipants));
        $this->selected = [];
        unset($this->windows);
    }

    public function confirmWindow(string $key, ConfirmMeeting $confirmMeeting): void
    {
        $this->authorize('manage', $this->meeting);

        [$startsAt, $endsAt] = $this->chosenSlot($key);

        $this->transition(fn () => $confirmMeeting($this->meeting, $startsAt, $endsAt));
    }

    public function openVote(OpenVote $openVote): void
    {
        $this->authorize('manage', $this->meeting);

        $slots = array_map(fn (string $key): array => $this->chosenSlot($key), array_values(array_unique($this->selected)));

        $this->transition(fn () => $openVote($this->meeting, $slots), 'selected');
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
     * @return array{0: \Carbon\CarbonImmutable, 1: \Carbon\CarbonImmutable}
     */
    private function chosenSlot(string $key): array
    {
        $window = collect($this->windows)->firstWhere('key', $key);
        $start = (int) ($this->starts[$key] ?? $window['firstStart'] ?? -1);

        if ($window === null || $start < $window['firstStart'] || $start > $window['lastStart']) {
            throw ValidationException::withMessages(['starts' => __('Choose a start time within this slot.')]);
        }

        return [
            AvailabilityDay::localDateTime($window['day'], $start)->utc(),
            AvailabilityDay::localDateTime($window['day'], $start + $window['length'])->utc(),
        ];
    }

    private function transition(callable $action, string $errorKey = 'starts'): void
    {
        try {
            $action();
        } catch (InvalidMeetingTransition $exception) {
            throw ValidationException::withMessages([$errorKey => $exception->getMessage()]);
        }

        $this->redirectRoute('meetings.show', $this->meeting, navigate: true);
    }
}
```

- [ ] **Step 4 : Vue**

`resources/views/livewire/meetings/summary.blade.php` :

```blade
@php($membersById = $this->members->keyBy('id'))
<div class="flex flex-col gap-6">
    <flux:card class="flex flex-col gap-4">
        <div class="flex flex-wrap items-end gap-4">
            <flux:select wire:model.live="duration" :label="__('Duration')" class="w-36">
                @foreach (range(30, 480, 30) as $minutes)
                    <flux:select.option :value="$minutes">{{ sprintf('%dh%02d', intdiv($minutes, 60), $minutes % 60) }}</flux:select.option>
                @endforeach
            </flux:select>
            <flux:input type="number" min="1" wire:model.live.debounce.400ms="minParticipants" :label="__('At least … attendees')" class="w-40" />
            <flux:input type="number" min="0" wire:model.live.debounce.400ms="minOnSite" :label="__('Of which on site')" class="w-40" />
        </div>
        <flux:text>
            {{ trans_choice(':count answer|:count answers', $this->members->count() - $this->nonRespondents->count()) }} {{ __('out of :total', ['total' => $this->members->count()]) }}
            @if ($this->nonRespondents->isNotEmpty())
                · {{ __('Not answered yet') }} : {{ $this->nonRespondents->pluck('name')->join(', ') }}
            @endif
        </flux:text>
    </flux:card>

    <flux:error name="starts" />
    <flux:error name="selected" />

    @forelse ($this->windows as $window)
        <flux:card wire:key="window-{{ $window['key'] }}" class="flex flex-col gap-3">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="flex items-start gap-3">
                    <flux:checkbox wire:model="selected" :value="$window['key']" :aria-label="__('Add to the vote')" />
                    <div>
                        <flux:heading>{{ ucfirst(\Carbon\CarbonImmutable::parse($window['day'])->translatedFormat('l j F')) }}</flux:heading>
                        <flux:text>
                            @if ($window['firstStart'] === $window['lastStart'])
                                {{ \App\Models\AvailabilityDay::cellTime($window['firstStart']) }} – {{ \App\Models\AvailabilityDay::cellTime($window['firstStart'] + $window['length']) }}
                            @else
                                {{ __('Start between :from and :to, ends by :end', ['from' => \App\Models\AvailabilityDay::cellTime($window['firstStart']), 'to' => \App\Models\AvailabilityDay::cellTime($window['lastStart']), 'end' => \App\Models\AvailabilityDay::cellTime($window['lastStart'] + $window['length'])]) }}
                            @endif
                        </flux:text>
                    </div>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <flux:badge color="yellow">{{ trans_choice(':count present|:count present', count($window['onSite']) + count($window['remote'])) }}</flux:badge>
                    <flux:badge>{{ __(':count on site', ['count' => count($window['onSite'])]) }}</flux:badge>
                    <flux:badge>{{ __(':count remotely', ['count' => count($window['remote'])]) }}</flux:badge>
                </div>
            </div>

            <flux:text size="sm">
                @if ($window['onSite']) <span class="font-semibold">{{ __('On site') }} :</span> {{ collect($window['onSite'])->map(fn ($id) => $membersById[$id]->name)->join(', ') }}. @endif
                @if ($window['remote']) <span class="font-semibold">{{ __('Remote') }} :</span> {{ collect($window['remote'])->map(fn ($id) => $membersById[$id]->name)->join(', ') }}. @endif
            </flux:text>

            <div class="flex flex-wrap items-end gap-3">
                @if ($window['firstStart'] !== $window['lastStart'])
                    <flux:select wire:model="starts.{{ $window['key'] }}" :label="__('Start at')" class="w-32">
                        @foreach (range($window['firstStart'], $window['lastStart']) as $start)
                            <flux:select.option :value="$start">{{ \App\Models\AvailabilityDay::cellTime($start) }}</flux:select.option>
                        @endforeach
                    </flux:select>
                @endif
                <flux:button variant="primary" size="sm" wire:click="confirmWindow('{{ $window['key'] }}')" wire:confirm="{{ __('Confirm this date and notify every member?') }}">{{ __('Confirm this slot') }}</flux:button>
            </div>
        </flux:card>
    @empty
        <flux:callout icon="magnifying-glass" :heading="__('No slot matches these criteria')" :text="__('Try a shorter duration or lower minimums.')" />
    @endforelse

    @if (count($this->windows) > 1)
        <div class="flex justify-end">
            <flux:button icon="hand-raised" wire:click="openVote" wire:confirm="{{ __('Put the selected slots to a vote? The grid will close.') }}">{{ __('Put the selected slots to a vote') }}</flux:button>
        </div>
    @endif
</div>
```

- [ ] **Step 5 : Traductions**

```json
":count on site": ":count sur place",
":count present|:count present": ":count présent|:count présents",
":count remotely": ":count à distance",
"Add to the vote": "Ajouter au vote",
"At least … attendees": "Au moins … présents",
"Choose a start time within this slot.": "Choisissez une heure de début dans ce créneau.",
"Confirm this date and notify every member?": "Valider cette date et prévenir tous les membres ?",
"Confirm this slot": "Valider ce créneau",
"Duration": "Durée",
"No slot matches these criteria": "Aucun créneau ne correspond à ces critères",
"Of which on site": "Dont sur place",
"Put the selected slots to a vote": "Soumettre les créneaux cochés au vote",
"Put the selected slots to a vote? The grid will close.": "Soumettre les créneaux cochés au vote ? La grille sera fermée.",
"Start at": "Début à",
"Start between :from and :to, ends by :end": "Début entre :from et :to, fin au plus tard :end",
"Try a shorter duration or lower minimums.": "Essayez une durée plus courte ou des minimums plus bas."
```

(`Not answered yet`, `:count answer|:count answers`, `On site`, `Remote`, `out of :total` existent déjà.)

- [ ] **Step 6 : Lancer les tests**

Run: `php artisan test --compact tests/Feature/Meetings/SummaryTest.php`
Expected: PASS.

- [ ] **Step 7 : Commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Livewire/Meetings/Summary.php resources/views/livewire/meetings/summary.blade.php lang/fr.json tests/Feature/Meetings/SummaryTest.php
git commit -m "Summary of the best windows, confirm or open a vote"
```

---

### Task 7 : Vote et résultats

**Files:**
- Create: `app/Livewire/Meetings/Vote.php`, `resources/views/livewire/meetings/vote.blade.php`
- Modify: `lang/fr.json`
- Test: `tests/Feature/Meetings/VoteTest.php`

**Interfaces:**
- Consumes: `FindBestSlot` (existante : `__invoke(iterable $tallies): int|string|null`, tallies `array{onSite, remote, startsAt}`), `ConfirmMeeting`, `CancelVote`, `SlotVote`, `AvailabilityDay::statusFor()`, `MeetingPolicy::vote` / `manage`.
- Produces: `<livewire:meetings.vote :meeting="$meeting" />` ; `array $responses` (id de créneau → valeur de statut) ; actions `save()`, `confirmSlot(int $slotId)`, `cancelVote()` ; computed `slots`, `tallies`, `bestSlotId`, `members`, `nonVoters`, `isOrganizer`.

- [ ] **Step 1 : Écrire les tests qui échouent**

`php artisan make:test --pest Meetings/VoteTest --no-interaction` :

```php
<?php

use App\Enums\AvailabilityStatus;
use App\Enums\MeetingStatus;
use App\Livewire\Meetings\Vote;
use App\Models\AvailabilityDay;
use App\Models\Group;
use App\Models\Meeting;
use App\Models\MeetingSlot;
use App\Models\SlotVote;
use App\Models\User;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

beforeEach(function () {
    Notification::fake();
    $this->group = Group::factory()->create();
    $this->organizer = $this->group->owner;
    $this->member = User::factory()->create(['name' => 'Bastien']);
    $this->group->addMember($this->member);
    $this->meeting = Meeting::factory()->for($this->group)->voting()->create(['range_start' => '2026-11-02', 'range_end' => '2026-11-06']);
    $this->monday = MeetingSlot::factory()->for($this->meeting)->create(['starts_at' => '2026-11-02 17:00:00', 'ends_at' => '2026-11-02 19:00:00']);
    $this->tuesday = MeetingSlot::factory()->for($this->meeting)->create(['starts_at' => '2026-11-03 17:00:00', 'ends_at' => '2026-11-03 19:00:00']);
});

test('answers are prefilled from the grid', function () {
    AvailabilityDay::factory()->for($this->meeting)->for($this->member)->cells(str_repeat('0', 20).'pppp0000')->create(['day' => '2026-11-02']);
    AvailabilityDay::factory()->for($this->meeting)->for($this->member)->cells(str_repeat('0', 20).'ppdd0000')->create(['day' => '2026-11-03']);

    Livewire::actingAs($this->member)
        ->test(Vote::class, ['meeting' => $this->meeting])
        ->assertSet("responses.{$this->monday->id}", 'on_site')
        ->assertSet("responses.{$this->tuesday->id}", 'remote');
});

test('a member votes for every slot', function () {
    Livewire::actingAs($this->member)
        ->test(Vote::class, ['meeting' => $this->meeting])
        ->set("responses.{$this->monday->id}", 'on_site')
        ->set("responses.{$this->tuesday->id}", '')
        ->call('save')
        ->assertHasErrors("responses.{$this->tuesday->id}")
        ->set("responses.{$this->tuesday->id}", 'unavailable')
        ->call('save')
        ->assertHasNoErrors();

    expect(SlotVote::where('user_id', $this->member->id)->count())->toBe(2);
});

test('the organizer sees the best date and confirms it', function () {
    SlotVote::factory()->for($this->tuesday, 'slot')->for($this->member)->create(['status' => AvailabilityStatus::OnSite]);
    SlotVote::factory()->for($this->monday, 'slot')->for($this->member)->create(['status' => AvailabilityStatus::Unavailable]);

    $component = Livewire::actingAs($this->organizer)->test(Vote::class, ['meeting' => $this->meeting]);

    expect($component->instance()->bestSlotId)->toBe($this->tuesday->id)
        ->and($component->instance()->nonVoters->pluck('id')->all())->toBe([$this->organizer->id]);

    $component->call('confirmSlot', $this->tuesday->id)->assertRedirect(route('meetings.show', $this->meeting));

    expect($this->meeting->fresh()->status)->toBe(MeetingStatus::Confirmed)
        ->and($this->meeting->fresh()->confirmed_starts_at->format('Y-m-d H:i'))->toBe('2026-11-03 17:00');
});

test('a slot of another meeting cannot be confirmed', function () {
    $foreign = MeetingSlot::factory()->create();

    Livewire::actingAs($this->organizer)
        ->test(Vote::class, ['meeting' => $this->meeting])
        ->call('confirmSlot', $foreign->id)
        ->assertNotFound();
});

test('the organizer cancels the vote', function () {
    Livewire::actingAs($this->organizer)
        ->test(Vote::class, ['meeting' => $this->meeting])
        ->call('cancelVote')
        ->assertRedirect(route('meetings.show', $this->meeting));

    expect($this->meeting->fresh()->status)->toBe(MeetingStatus::Collecting);
});

test('members cannot confirm or cancel, nobody votes once confirmed', function () {
    Livewire::actingAs($this->member)->test(Vote::class, ['meeting' => $this->meeting])->call('confirmSlot', $this->monday->id)->assertForbidden();
    Livewire::actingAs($this->member)->test(Vote::class, ['meeting' => $this->meeting])->call('cancelVote')->assertForbidden();

    $this->meeting->forceFill(['status' => MeetingStatus::Confirmed, 'confirmed_starts_at' => now(), 'confirmed_ends_at' => now()->addHour()])->save();

    Livewire::actingAs($this->member)
        ->test(Vote::class, ['meeting' => $this->meeting])
        ->set("responses.{$this->monday->id}", 'on_site')
        ->set("responses.{$this->tuesday->id}", 'on_site')
        ->call('save')
        ->assertForbidden();
});
```

- [ ] **Step 2 : Lancer, vérifier l'échec**

Run: `php artisan test --compact tests/Feature/Meetings/VoteTest.php`
Expected: FAIL.

- [ ] **Step 3 : Composant**

`app/Livewire/Meetings/Vote.php` :

```php
<?php

namespace App\Livewire\Meetings;

use App\Actions\Meetings\CancelVote;
use App\Actions\Meetings\ConfirmMeeting;
use App\Actions\Meetings\FindBestSlot;
use App\Enums\AvailabilityStatus;
use App\Models\AvailabilityDay;
use App\Models\Meeting;
use App\Models\MeetingSlot;
use App\Models\SlotVote;
use App\Models\User;
use Carbon\CarbonInterface;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Component;

class Vote extends Component
{
    public Meeting $meeting;

    /** @var array<int, string> */
    public array $responses = [];

    public function mount(Meeting $meeting): void
    {
        $this->authorize('view', $meeting);

        $this->meeting = $meeting;
        $grid = $meeting->availabilityDays()->where('user_id', Auth::id())->pluck('cells', 'day');

        foreach ($this->slots as $slot) {
            $this->responses[$slot->id] = $slot->votes->firstWhere('user_id', Auth::id())?->status->value
                ?? $this->fromGrid($slot, $grid->all())->value;
        }
    }

    public function hydrate(): void
    {
        $this->authorize('view', $this->meeting);
    }

    public function save(): void
    {
        $this->authorize('vote', $this->meeting);

        $this->validate($this->slots->mapWithKeys(fn (MeetingSlot $slot): array => [
            "responses.{$slot->id}" => ['required', Rule::enum(AvailabilityStatus::class)],
        ])->all());

        foreach ($this->slots as $slot) {
            $vote = $slot->votes->firstWhere('user_id', Auth::id()) ?? tap(new SlotVote, function (SlotVote $vote) use ($slot): void {
                $vote->slot()->associate($slot);
                $vote->user()->associate(Auth::user());
            });

            $vote->status = AvailabilityStatus::from($this->responses[$slot->id]);
            $vote->save();
        }

        unset($this->slots, $this->tallies, $this->bestSlotId, $this->nonVoters);

        Flux::toast(variant: 'success', text: __('Your vote has been saved.'));
    }

    public function confirmSlot(int $slotId, ConfirmMeeting $confirmMeeting): void
    {
        $this->authorize('manage', $this->meeting);

        $slot = $this->meeting->slots()->findOrFail($slotId);

        $confirmMeeting($this->meeting, $slot->starts_at, $slot->ends_at);

        $this->redirectRoute('meetings.show', $this->meeting, navigate: true);
    }

    public function cancelVote(CancelVote $cancelVote): void
    {
        $this->authorize('manage', $this->meeting);

        $cancelVote($this->meeting);

        $this->redirectRoute('meetings.show', $this->meeting, navigate: true);
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
        return $this->meeting->slots()->with('votes')->get();
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
     * @return array<int, array{onSite: int, remote: int, startsAt: CarbonInterface}>
     */
    #[Computed]
    public function tallies(): array
    {
        return $this->slots->mapWithKeys(fn (MeetingSlot $slot): array => [$slot->id => [
            'onSite' => $slot->votes->where('status', AvailabilityStatus::OnSite)->count(),
            'remote' => $slot->votes->where('status', AvailabilityStatus::Remote)->count(),
            'startsAt' => $slot->starts_at,
        ]])->all();
    }

    #[Computed]
    public function bestSlotId(): ?int
    {
        return (new FindBestSlot)($this->tallies);
    }

    /**
     * Members who have not voted for every slot.
     *
     * @return Collection<int, User>
     */
    #[Computed]
    public function nonVoters(): Collection
    {
        return $this->members->reject(fn (User $member): bool => $this->slots->every(
            fn (MeetingSlot $slot): bool => $slot->votes->contains('user_id', $member->id)
        ))->values();
    }

    /**
     * @param  array<string, string>  $grid
     */
    private function fromGrid(MeetingSlot $slot, array $grid): AvailabilityStatus
    {
        $start = $slot->startsAtLocal();
        $firstCell = ($start->hour - AvailabilityDay::FirstHour) * 2 + intdiv($start->minute, 30);
        $length = intdiv((int) $slot->starts_at->diffInMinutes($slot->ends_at), 30);

        return AvailabilityDay::statusFor($grid[$start->toDateString()] ?? AvailabilityDay::Empty, $firstCell, $length);
    }
}
```

- [ ] **Step 4 : Vue**

`resources/views/livewire/meetings/vote.blade.php` :

```blade
<div class="flex flex-col gap-8">
    @can('vote', $meeting)
        <section class="flex flex-col gap-3">
            <flux:heading size="lg" level="2">{{ __('Your vote') }}</flux:heading>
            <flux:text>{{ __('We prefilled your answers from your grid. Check them and save.') }}</flux:text>
            <form wire:submit="save" class="flex flex-col gap-3">
                @foreach ($this->slots as $index => $slot)
                    <flux:card wire:key="vote-{{ $slot->id }}" class="flex flex-col gap-2 p-4! sm:flex-row sm:items-center sm:justify-between">
                        <flux:heading>{{ ucfirst($slot->startsAtLocal()->translatedFormat('l j F · H\hi')) }} – {{ $slot->endsAtLocal()->format('H\hi') }}</flux:heading>
                        <flux:radio.group wire:model="responses.{{ $slot->id }}" variant="segmented" size="sm" :aria-label="__('Slot :number', ['number' => $index + 1])">
                            @foreach (\App\Enums\AvailabilityStatus::cases() as $status)
                                <flux:radio :value="$status->value" :label="$status->label()" />
                            @endforeach
                        </flux:radio.group>
                        <flux:error name="responses.{{ $slot->id }}" />
                    </flux:card>
                @endforeach
                <div><flux:button variant="primary" type="submit">{{ __('Save my vote') }}</flux:button></div>
            </form>
        </section>
    @endcan

    @if ($this->isOrganizer())
        <section class="flex flex-col gap-3">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <flux:heading size="lg" level="2">{{ __('Vote results') }}</flux:heading>
                <flux:button size="sm" variant="ghost" icon="arrow-uturn-left" wire:click="cancelVote" wire:confirm="{{ __('Cancel the vote? Votes will be deleted and the grid reopened.') }}">{{ __('Cancel the vote') }}</flux:button>
            </div>
            <flux:card class="p-0!">
                <flux:table>
                    <flux:table.columns>
                        <flux:table.column>{{ __('Slot') }}</flux:table.column>
                        <flux:table.column align="center">{{ __('On site') }}</flux:table.column>
                        <flux:table.column align="center">{{ __('Remote') }}</flux:table.column>
                        <flux:table.column><span class="sr-only">{{ __('Actions') }}</span></flux:table.column>
                    </flux:table.columns>
                    <flux:table.rows>
                        @foreach ($this->slots as $slot)
                            <flux:table.row :key="'result-'.$slot->id">
                                <flux:table.cell variant="strong">
                                    {{ ucfirst($slot->startsAtLocal()->translatedFormat('l j F · H\hi')) }}
                                    @if ($slot->id === $this->bestSlotId) <flux:badge size="sm" color="yellow" class="ms-2">{{ __('Best date') }}</flux:badge> @endif
                                </flux:table.cell>
                                <flux:table.cell align="center" class="tabular-nums">{{ $this->tallies[$slot->id]['onSite'] }}</flux:table.cell>
                                <flux:table.cell align="center" class="tabular-nums">{{ $this->tallies[$slot->id]['remote'] }}</flux:table.cell>
                                <flux:table.cell align="end">
                                    <flux:button size="sm" :variant="$slot->id === $this->bestSlotId ? 'primary' : 'filled'" wire:click="confirmSlot({{ $slot->id }})" wire:confirm="{{ __('Confirm this date and notify every member?') }}">{{ __('Confirm this date') }}</flux:button>
                                </flux:table.cell>
                            </flux:table.row>
                        @endforeach
                    </flux:table.rows>
                </flux:table>
            </flux:card>
            @if ($this->nonVoters->isNotEmpty())
                <flux:callout icon="clock" :heading="__('Not voted yet')" :text="$this->nonVoters->pluck('name')->join(', ')" />
            @endif
        </section>
    @endif
</div>
```

- [ ] **Step 5 : Traductions**

```json
"Best date": "Meilleure date",
"Cancel the vote": "Annuler le vote",
"Cancel the vote? Votes will be deleted and the grid reopened.": "Annuler le vote ? Les votes seront supprimés et la grille rouverte.",
"Confirm this date": "Valider cette date",
"Not voted yet": "N'ont pas encore voté",
"Save my vote": "Enregistrer mon vote",
"Slot": "Créneau",
"Slot :number": "Créneau :number",
"Vote results": "Résultats du vote",
"We prefilled your answers from your grid. Check them and save.": "Vos réponses sont préremplies à partir de votre grille. Vérifiez-les puis enregistrez.",
"Your vote": "Votre vote",
"Your vote has been saved.": "Votre vote est enregistré."
```

- [ ] **Step 6 : Lancer les tests**

Run: `php artisan test --compact tests/Feature/Meetings/VoteTest.php`
Expected: PASS.

- [ ] **Step 7 : Commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Livewire/Meetings/Vote.php resources/views/livewire/meetings/vote.blade.php lang/fr.json tests/Feature/Meetings/VoteTest.php
git commit -m "Vote on proposed slots and confirm the result"
```

---

### Task 8 : Page d'une demande, agenda et tableau de bord

**Files:**
- Create: `app/Http/Controllers/MeetingCalendarController.php`, `resources/views/livewire/meetings/show.blade.php` (remplace le squelette)
- Modify: `app/Livewire/Meetings/Show.php`, `routes/web.php`, `app/Livewire/Dashboard.php`, `resources/views/livewire/dashboard.blade.php`, `lang/fr.json`
- Test: `tests/Feature/Meetings/MeetingPageTest.php`, `tests/Feature/DashboardTest.php` (ajouts)

**Interfaces:**
- Consumes: composants `meetings.availability-grid`, `meetings.summary`, `meetings.vote` ; `BuildIcsCalendar` ; `MeetingStatus`.
- Produces: route `meetings.calendar` (GET `meetings/{meeting}/calendar.ics`) ; `Dashboard::pendingMeetings` (collecte sans grille de l'utilisateur + votes incomplets), `Dashboard::confirmedMeetings`.

- [ ] **Step 1 : Écrire les tests qui échouent**

`php artisan make:test --pest Meetings/MeetingPageTest --no-interaction` :

```php
<?php

use App\Models\Group;
use App\Models\Meeting;
use App\Models\User;

beforeEach(function () {
    $this->group = Group::factory()->create();
    $this->organizer = $this->group->owner;
    $this->member = User::factory()->create();
    $this->group->addMember($this->member);
});

test('while collecting, members see their grid and the organizer sees their grid before the summary', function () {
    $meeting = Meeting::factory()->for($this->group)->create(['title' => 'AG']);

    $this->actingAs($this->member)->get(route('meetings.show', $meeting))
        ->assertOk()->assertSeeLivewire('meetings.availability-grid')->assertDontSeeLivewire('meetings.summary');

    $this->actingAs($this->organizer)->get(route('meetings.show', $meeting))
        ->assertSeeLivewire('meetings.summary')->assertSeeLivewire('meetings.availability-grid')
        ->assertSeeInOrder([__('My availability'), __('Best slots')]);
});

test('while voting, the vote is shown', function () {
    $meeting = Meeting::factory()->for($this->group)->voting()->create();

    $this->actingAs($this->member)->get(route('meetings.show', $meeting))->assertSeeLivewire('meetings.vote');
});

test('a confirmed meeting shows its date and offers the calendar file', function () {
    $meeting = Meeting::factory()->for($this->group)->confirmed(now('UTC')->setDate(2026, 11, 3)->setTime(17, 0))->create(['location' => 'Salle des fêtes']);

    $this->actingAs($this->member)->get(route('meetings.show', $meeting))
        ->assertSee('Salle des fêtes')
        ->assertSee(route('meetings.calendar', $meeting), escape: false);

    $this->actingAs($this->member)->get(route('meetings.calendar', $meeting))
        ->assertOk()
        ->assertHeader('Content-Type', 'text/calendar; charset=utf-8')
        ->assertSee('DTSTART:20261103T170000Z', escape: false);
});

test('the calendar file is only for confirmed meetings and members', function () {
    $collecting = Meeting::factory()->for($this->group)->create();
    $confirmed = Meeting::factory()->for($this->group)->confirmed()->create();

    $this->actingAs($this->member)->get(route('meetings.calendar', $collecting))->assertNotFound();
    $this->actingAs(User::factory()->create())->get(route('meetings.calendar', $confirmed))->assertNotFound();
    $this->actingAs(User::factory()->create())->get(route('meetings.show', $confirmed))->assertNotFound();
});
```

Ajouter à `tests/Feature/DashboardTest.php` (imports `App\Models\MeetingSlot`, `App\Models\SlotVote`) :

```php
test('pending answers include grids to fill and votes to complete, confirmed meetings are listed', function () {
    $user = User::factory()->create();
    $group = Group::factory()->create();
    $group->addMember($user);
    Meeting::factory()->for($group)->create(['title' => 'Grille à remplir']);
    $voting = Meeting::factory()->for($group)->voting()->create(['title' => 'Vote à faire']);
    MeetingSlot::factory()->for($voting)->count(2)->create();
    $voted = Meeting::factory()->for($group)->voting()->create(['title' => 'Vote déjà fait']);
    foreach (MeetingSlot::factory()->for($voted)->count(2)->create() as $slot) {
        SlotVote::factory()->for($slot, 'slot')->for($user)->create();
    }
    Meeting::factory()->for($group)->confirmed(now()->addWeek())->create(['title' => 'Réunion confirmée']);

    $this->actingAs($user)->get(route('dashboard'))
        ->assertSee('Grille à remplir')
        ->assertSee('Vote à faire')
        ->assertDontSee('Vote déjà fait')
        ->assertSee('Réunion confirmée');
});

test('a member who joined during the collection is asked for their availability', function () {
    $group = Group::factory()->create();
    Meeting::factory()->for($group)->create(['title' => 'Demande en cours']);
    $newcomer = User::factory()->create();
    $group->addMember($newcomer);

    $this->actingAs($newcomer)->get(route('dashboard'))->assertSee('Demande en cours');
});
```

- [ ] **Step 2 : Lancer, vérifier l'échec**

Run: `php artisan test --compact tests/Feature/Meetings/MeetingPageTest.php tests/Feature/DashboardTest.php`
Expected: FAIL.

- [ ] **Step 3 : Page d'une demande**

`app/Livewire/Meetings/Show.php` :

```php
<?php

namespace App\Livewire\Meetings;

use App\Models\Meeting;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class Show extends Component
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

    public function isOrganizer(): bool
    {
        return $this->meeting->group->isOrganizer(Auth::user());
    }
}
```

`resources/views/livewire/meetings/show.blade.php` :

```blade
@use('App\Enums\MeetingStatus')
<div class="mx-auto flex w-full max-w-5xl flex-col gap-8">
    <header class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <flux:link :href="route('groups.show', $meeting->group)" wire:navigate class="text-sm">← {{ $meeting->group->name }}</flux:link>
            <flux:heading size="xl" level="1" class="mt-2 text-3xl! font-extrabold!">{{ $meeting->title }}</flux:heading>
            <div class="mt-2 flex flex-wrap items-center gap-2">
                <flux:badge size="sm" :color="match ($meeting->status) { MeetingStatus::Confirmed => 'green', MeetingStatus::Voting => 'blue', default => 'yellow' }">{{ $meeting->status->label() }}</flux:badge>
                @if ($meeting->status === MeetingStatus::Collecting)
                    <flux:text>{{ __('From :start to :end · answer before :deadline', ['start' => $meeting->range_start->translatedFormat('j F'), 'end' => $meeting->range_end->translatedFormat('j F'), 'deadline' => $meeting->deadline->translatedFormat('j F')]) }}</flux:text>
                @endif
            </div>
            @if ($meeting->description)
                <flux:text class="mt-2 max-w-prose">{{ $meeting->description }}</flux:text>
            @endif
        </div>
        @if ($this->isOrganizer() && $meeting->status === MeetingStatus::Collecting)
            <flux:button icon="pencil" :href="route('meetings.edit', $meeting)" wire:navigate>{{ __('Edit') }}</flux:button>
        @endif
    </header>

    @if ($meeting->status === MeetingStatus::Confirmed)
        @php($startsAt = $meeting->confirmed_starts_at->copy()->setTimezone(config('app.display_timezone')))
        @php($endsAt = $meeting->confirmed_ends_at->copy()->setTimezone(config('app.display_timezone')))
        <flux:card class="flex flex-col gap-3 border-transparent! bg-sun! text-forest">
            <flux:text class="font-semibold uppercase tracking-wide text-forest/70!">{{ __('Confirmed date') }}</flux:text>
            <flux:heading size="xl" class="text-2xl! font-extrabold! text-forest!">{{ ucfirst($startsAt->translatedFormat('l j F Y')) }} · {{ $startsAt->format('H\hi') }} – {{ $endsAt->format('H\hi') }}</flux:heading>
            @if ($meeting->location)
                <flux:text class="flex items-center gap-1 text-forest!"><flux:icon.map-pin variant="micro" /> {{ $meeting->location }}</flux:text>
            @endif
            <div><flux:button icon="calendar-days" :href="route('meetings.calendar', $meeting)">{{ __('Add to my calendar') }}</flux:button></div>
        </flux:card>
    @elseif ($meeting->status === MeetingStatus::Voting)
        <livewire:meetings.vote :meeting="$meeting" :key="'vote-'.$meeting->id" />
    @else
        {{-- The organizer is a participant too: their own grid comes first, then the summary. --}}
        <section class="flex flex-col gap-4">
            <flux:heading size="lg" level="2">{{ __('My availability') }}</flux:heading>
            <livewire:meetings.availability-grid :meeting="$meeting" :key="'grid-'.$meeting->id" />
        </section>
        @if ($this->isOrganizer())
            <section class="flex flex-col gap-4">
                <flux:heading size="lg" level="2">{{ __('Best slots') }}</flux:heading>
                <livewire:meetings.summary :meeting="$meeting" :key="'summary-'.$meeting->id" />
            </section>
        @endif
    @endif
</div>
```

`php artisan make:controller MeetingCalendarController --invokable --no-interaction` :

```php
<?php

namespace App\Http\Controllers;

use App\Actions\Meetings\BuildIcsCalendar;
use App\Enums\MeetingStatus;
use App\Models\Meeting;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

class MeetingCalendarController extends Controller
{
    public function __invoke(Meeting $meeting, BuildIcsCalendar $buildIcsCalendar): Response
    {
        Gate::authorize('view', $meeting);

        abort_unless($meeting->status === MeetingStatus::Confirmed, 404);

        return response($buildIcsCalendar($meeting), 200, [
            'Content-Type' => 'text/calendar; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="reunion.ics"',
        ]);
    }
}
```

`routes/web.php` (groupe `auth` + `verified`) : `Route::get('meetings/{meeting}/calendar.ics', MeetingCalendarController::class)->name('meetings.calendar');` (import `App\Http\Controllers\MeetingCalendarController`).

- [ ] **Step 4 : Tableau de bord**

Dans `app/Livewire/Dashboard.php`, remplacer `pendingMeetings()` et ajouter `confirmedMeetings()` (imports `App\Enums\MeetingStatus`) :

```php
    /**
     * Requests of my groups waiting for my grid, and votes I have not completed.
     *
     * @return Collection<int, Meeting>
     */
    #[Computed]
    public function pendingMeetings(): Collection
    {
        $userId = Auth::id();

        return Meeting::query()
            ->whereHas('group.members', fn (Builder $members) => $members->whereKey($userId))
            ->where(fn (Builder $query) => $query
                ->where(fn (Builder $collecting) => $collecting
                    ->where('status', MeetingStatus::Collecting->value)
                    ->whereDoesntHave('availabilityDays', fn (Builder $days) => $days->where('user_id', $userId)))
                ->orWhere(fn (Builder $voting) => $voting
                    ->where('status', MeetingStatus::Voting->value)
                    ->whereHas('slots', fn (Builder $slots) => $slots->whereDoesntHave('votes', fn (Builder $votes) => $votes->where('user_id', $userId)))))
            ->with('group')
            ->orderBy('deadline')
            ->get();
    }

    /**
     * Confirmed meetings of my groups still ahead.
     *
     * @return Collection<int, Meeting>
     */
    #[Computed]
    public function confirmedMeetings(): Collection
    {
        return Meeting::query()
            ->whereHas('group.members', fn (Builder $members) => $members->whereKey(Auth::id()))
            ->where('status', MeetingStatus::Confirmed->value)
            ->where('confirmed_starts_at', '>=', now())
            ->with('group')
            ->orderBy('confirmed_starts_at')
            ->get();
    }
```

Dans `resources/views/livewire/dashboard.blade.php`, pour chaque demande en attente, afficher selon l'état :

```blade
                <flux:card wire:key="pending-{{ $meeting->id }}" class="flex flex-wrap items-center justify-between gap-4 bg-sun/40! dark:bg-sun/10!">
                    <div>
                        <flux:heading>{{ $meeting->title }}</flux:heading>
                        <flux:text>
                            {{ $meeting->group->name }} ·
                            {{ $meeting->status === \App\Enums\MeetingStatus::Voting ? __('Vote in progress') : __('Answer before :date', ['date' => $meeting->deadline->translatedFormat('j F')]) }}
                        </flux:text>
                    </div>
                    <flux:button variant="primary" :href="route('meetings.show', $meeting)" wire:navigate>
                        {{ $meeting->status === \App\Enums\MeetingStatus::Voting ? __('Vote') : __('Give my availability') }}
                    </flux:button>
                </flux:card>
```

et ajouter, entre « À vous de répondre » et « Mes groupes » :

```blade
    @if ($this->confirmedMeetings->isNotEmpty())
        <section class="flex flex-col gap-4">
            <flux:heading size="lg" level="2">{{ __('Confirmed meetings') }}</flux:heading>
            @foreach ($this->confirmedMeetings as $meeting)
                <a wire:key="confirmed-{{ $meeting->id }}" href="{{ route('meetings.show', $meeting) }}" wire:navigate class="group/card block">
                    <flux:card class="flex flex-wrap items-center justify-between gap-4 transition group-hover/card:border-forest! dark:group-hover/card:border-sun!">
                        <div>
                            <flux:heading>{{ $meeting->title }}</flux:heading>
                            <flux:text>{{ $meeting->group->name }}</flux:text>
                        </div>
                        <flux:badge color="green">{{ ucfirst($meeting->confirmed_starts_at->copy()->setTimezone(config('app.display_timezone'))->translatedFormat('D j F · H\hi')) }}</flux:badge>
                    </flux:card>
                </a>
            @endforeach
        </section>
    @endif
```

- [ ] **Step 5 : Traductions**

```json
"Add to my calendar": "Ajouter à mon agenda",
"Best slots": "Meilleurs créneaux",
"Confirmed date": "Date retenue",
"Confirmed meetings": "Réunions confirmées",
"From :start to :end · answer before :deadline": "Du :start au :end · réponses avant le :deadline",
"My availability": "Mes disponibilités"
```

- [ ] **Step 6 : Lancer les tests**

Run: `php artisan test --compact tests/Feature/Meetings tests/Feature/DashboardTest.php && php artisan test --compact`
Expected: PASS (suite complète).

- [ ] **Step 7 : Commit**

```bash
vendor/bin/pint --dirty --format agent
git add app resources/views routes/web.php lang/fr.json tests/Feature
git commit -m "Meeting request page, calendar file and dashboard"
```

---

### Task 9 : Accueil, données de démonstration et vérification

**Files:**
- Modify: `resources/views/components/how-it-works.blade.php`, `tests/Feature/HomePageTest.php`
- Create: `database/seeders/DemoSeeder.php`

- [ ] **Step 1 : Test qui échoue**

Dans `tests/Feature/HomePageTest.php`, remplacer les étapes attendues par le nouveau parcours :

```php
test('the home page explains how it works step by step before the closing call to action', function () {
    $this->get(route('home'))->assertOk()->assertSeeInOrder([
        'Comment ça marche, en 30 secondes',
        'Lancez une demande',
        'Chacun colorie ses disponibilités',
        'Le résumé trouve les meilleurs créneaux',
        'Validez, ou faites voter',
        'Tout le monde est prévenu',
        'Votre prochaine réunion commence ici.',
    ]);
});
```

Run: `php artisan test --compact tests/Feature/HomePageTest.php` → FAIL.

- [ ] **Step 2 : Mettre à jour l'animation**

Dans `resources/views/components/how-it-works.blade.php`, garder le composant (comportement, styles, accessibilité) et remplacer les 5 étapes et scènes :

1. **Lancez une demande** — Une période, une date butoir : les membres sont prévenus. *Scène :* carte « Nouvelle demande » avec « Du 3 au 21 nov. » et « Répondre avant le 31 oct. ».
2. **Chacun colorie ses disponibilités** — Sur place ou à distance, du bout du doigt. *Scène :* mini-grille 5 jours × 6 cases (18h–21h) dont les cases se colorent une à une (`bg-forest`/`dark:bg-sun` = sur place, `bg-blush` = à distance).
3. **Le résumé trouve les meilleurs créneaux** — Selon la durée et le nombre de présents. *Scène :* réglages « 2 h · au moins 4 présents · dont 2 sur place » puis trois lignes classées (« Mer. 12 nov. · 18h00–20h00 · 5 présents », etc.).
4. **Validez, ou faites voter** — Un créneau, ou plusieurs soumis au vote. *Scène :* deux boutons « Valider ce créneau » et « Soumettre au vote » avec la première ligne mise en valeur.
5. **Tout le monde est prévenu** — Un e-mail et un rappel dans l'agenda. *Scène :* carte « Date retenue · mercredi 12 novembre · 18h00–20h00 » avec « Ajouter à mon agenda ».

Mention « Exemple fictif. » conservée.

- [ ] **Step 3 : Seeder**

`php artisan make:seeder DemoSeeder --no-interaction` :

```php
<?php

namespace Database\Seeders;

use App\Actions\Groups\CreateGroup;
use App\Models\AvailabilityDay;
use App\Models\Meeting;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Demo group with an availability request, for local screenshots.
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

        $meeting = new Meeting([
            'title' => 'Assemblée de rentrée',
            'location' => 'Salle des fêtes',
            'range_start' => today()->addDays(3)->toDateString(),
            'range_end' => today()->addDays(16)->toDateString(),
            'deadline' => today()->addDays(2)->toDateString(),
        ]);
        $meeting->group()->associate($group);
        $meeting->creator()->associate($organizer);
        $meeting->save();

        $patterns = ['pppppp', 'dddddd', 'ppdddd', 'pppp00', '00pppp'];

        foreach ($members->take(5) as $index => $member) {
            foreach (array_slice($meeting->rangeDays(), $index % 2, 8) as $offset => $day) {
                $cells = str_repeat('0', 20).$patterns[($index + $offset) % count($patterns)].'00';

                $availability = new AvailabilityDay(['cells' => $cells]);
                $availability->meeting()->associate($meeting);
                $availability->user()->associate($member);
                $availability->day = $day;
                $availability->save();
            }
        }
    }
}
```

- [ ] **Step 4 : Suite complète et build**

Run: `npm run build && php artisan test --compact`
Expected: tout passe.

- [ ] **Step 5 : Vérification visuelle**

**Avec l'accord de l'utilisateur** : `php artisan db:seed --class=DemoSeeder`. Serveur `php artisan serve --port=8010` en arrière-plan ; captures clair/sombre, 1440 px et 390 px : accueil (section animée), page du groupe, formulaire de demande, page d'une demande côté membre (grille : colorier au clic et en glissant, souris et tactile émulé) et côté administrateur (résumé, réglages), vote, date confirmée. Vérifier l'e-mail « Nouvelle demande » et l'`.ics` dans Mailpit (`http://127.0.0.1:8025`). Arrêter le serveur.

- [ ] **Step 6 : Commit**

```bash
vendor/bin/pint --dirty --format agent
git add resources/views/components/how-it-works.blade.php tests/Feature/HomePageTest.php database/seeders/DemoSeeder.php
git commit -m "Tell the availability story on the home page and add demo data"
```
