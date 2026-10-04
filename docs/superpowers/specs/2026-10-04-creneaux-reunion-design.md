# Créneaux « Réunion » dans les disponibilités — design

Date : 2026-10-04 · Branche : `feature/creneaux-reunion` (depuis `main` bd7b933)
Complète `2026-10-03-disponibilites-personnelles-design.md` (point « hors périmètre : bloquer automatiquement un créneau confirmé »).

## En bref — ce que ça fait pour l'utilisateur

1. Dès qu'une date est validée, chaque membre du groupe voit les cases de ce créneau marquées « Réunion » dans sa grille (page « Mes disponibilités » et grilles des demandes), dans une couleur à part (jaune `sun`) avec une légende.
2. Survoler une case « Réunion » affiche le titre de la réunion et le nom du groupe (aussi annoncés par les lecteurs d'écran).
3. Ces cases ne sont pas modifiables : clic, glissé, copie de jour et duplication de semaine les ignorent.
4. Pour toute autre demande (de n'importe quel groupe), la personne compte comme indisponible sur ce créneau : le résumé ne propose plus cet horaire avec elle, et le vote se préremplit « Pas dispo ». Dans le résumé d'un autre groupe, on voit seulement « indisponible », jamais le titre.
5. Ces cases sont calculées à partir des réunions confirmées, pas enregistrées : réunion supprimée → elles disparaissent et les disponibilités saisies dessous réapparaissent ; nouveau membre → il voit les réunions confirmées du groupe ; départ du groupe → elles disparaissent.
6. « A répondu » (compteur, non-répondants, rappel) reste basé sur les cases saisies par la personne.
7. Pas de nouvel e-mail ni de nouveau réglage.

## Règles

- **Réunions prises en compte pour un utilisateur** : les demandes `confirmed` des groupes dont il est **membre actuel**, `confirmed_starts_at` / `confirmed_ends_at` non nuls.
- **Cases couvertes** : convertir début et fin en heure de Paris (`config('app.display_timezone')`) ; le jour local du début donne la date ; cases `[ (h−8)·2 + m/30 , même calcul pour la fin )` bornées à `[0, 28]`. Les créneaux confirmés sont alignés sur les demi-heures (issus de la grille) ; une heure non alignée couvre toute case qu'elle chevauche (début arrondi à la case inférieure, fin à la case supérieure). Une réunion qui finirait après 22h ou un autre jour est coupée à 22h du jour de début.
- **Une demande ne se bloque pas elle-même** : le calcul pour le résumé/vote de la demande X exclut X (elle n'est de toute façon pas confirmée tant qu'on vote ou collecte).
- Une case peut appartenir à plusieurs réunions (chevauchement) : on garde la première par heure de début.

## Composants

- **`App\Actions\Availability\BusyCells`** — `__invoke(array $userIds, string $from, string $to, ?int $exceptMeetingId = null): array<int, array<string, array<int, array{meetingId: int, title: string, group: string}>>>` : pour chaque utilisateur, par jour `Y-m-d` entre `$from` et `$to` inclus, les index de cases occupées et la réunion correspondante. Une seule requête : réunions confirmées dont le groupe compte au moins un des utilisateurs parmi ses membres, `confirmed_starts_at` dans la fenêtre (élargie d'un jour pour le fuseau), avec `group.members` (ids) chargés.
- **`AvailabilityDay::withoutBusy(string $cells, array $busyIndexes): string`** — remplace par `0` les cases occupées (utilisé par le résumé et le vote).
- **`Meeting::availableCellsByMember()`** — `cellsByMember()` (inchangé, sert à « a répondu ») où chaque jour passe par `withoutBusy` avec les cases occupées de ce membre (hors cette demande). Un membre n'ayant saisi aucune case n'apparaît pas. Le résumé (`Summary::windows`) utilise `availableCellsByMember()` au lieu de `cellsByMember()`.
- **`Vote::mount`** — le préremplissage depuis la grille passe la journée par `withoutBusy` (cases occupées de l'utilisateur, hors cette demande).
- **`Availability\Grid`** :
  - nouveau computed `busy(): array<string, array<int, array{title: string, group: string, url: string}>>` sur les jours affichés pour `Auth::id()` (hors la réunion affichée si elle est confirmée — inutile puisque la grille d'une réunion confirmée n'est pas affichée, mais sans effet) ;
  - transmis au navigateur dans un attribut `data-busy` (comme `data-cells`, hors de `x-data` pour ne pas réinitialiser la grille) ;
  - `saveDays` refuse (`ValidationException` sur `days`, message existant) toute journée où une case occupée a une valeur différente de celle déjà stockée (`0` si rien n'est stocké) → le serveur garantit qu'on ne modifie rien sous une réunion ; le navigateur conserve d'ailleurs ces cases telles quelles.
- **JS `availability-grid.js`** :
  - lit `data-busy` au démarrage ;
  - `set()` ignore une case occupée ; `copyToWeek` et `copyWeekToNext` conservent la valeur existante des cases occupées de la cible (et ne recopient pas l'état « Réunion ») ;
  - une case occupée : classe `bg-sun` + motif discret (`bg-[repeating-linear-gradient(...)]` facultatif), `disabled`, `title` = « Réunion : <titre> (<groupe>) », `aria-label` = « <jour> <heure> — Réunion : <titre> ».
- **Légende** : quatrième entrée « Réunion » (carré jaune).

## Confidentialité

`BusyCells` renvoie titre et groupe ; seuls `Availability\Grid` (pour soi) les affiche. `Summary` et `Vote` n'utilisent que les index (via `withoutBusy`), jamais les titres.

## Tests (Pest)

- `BusyCells` : réunion confirmée 18h–20h Paris → cases 20–23 du jour ; réunion en collecte ou en vote ignorée ; utilisateur non membre ignoré ; membre ayant quitté le groupe ignoré ; exclusion de `$exceptMeetingId` ; changement d'heure (réunion le 25 octobre 2026) bien placée en heure de Paris.
- Grille : `busy` expose la réunion pour un membre ; `saveDays` refuse de changer une case occupée, accepte le reste de la journée ; réunion supprimée → plus de case occupée.
- Résumé d'une autre demande (autre groupe) : un membre occupé n'est pas compté sur ce créneau ; le titre de la réunion n'apparaît pas dans le HTML du résumé.
- `respondentIds` inchangé : un membre dont toutes les cases saisies sont sous une réunion reste « a répondu ».
- Vote : préremplissage « Pas dispo » sur un créneau occupé.

## Risques

- Coût : `BusyCells` doit rester une requête par appel (pas de N+1) ; le résumé l'appelle une fois pour tous les membres.
- Le JS a déjà plusieurs chemins d'écriture (`set`, `copyToWeek`, `copyWeekToNext`) : tous doivent respecter les cases occupées, et le serveur le garantit de toute façon.
