# Disponibilités personnelles — design

Date : 2026-10-03 · Branche : `feature/disponibilites-personnelles` (depuis `main` 408a6ab)
Remplace, dans la spec `2026-10-03-disponibilites-design.md`, tout ce qui lie la grille à une demande. Le reste de cette spec (états, résumé, vote, e-mails, .ics) reste valable.

## En bref — ce que ça fait pour l'utilisateur

1. Chaque inscrit a une page « Mes disponibilités » dans le menu, même sans groupe ni demande : la même grille (8h–22h, demi-heures, sur place / à distance / pas dispo, clic, glisser, tactile, « Copier sur la semaine »).
2. C'est un calendrier daté, parcouru semaine par semaine, de la semaine en cours jusqu'à aujourd'hui + 3 mois. Les jours passés ne sont pas modifiables.
3. Sur une demande en collecte, la grille affichée est ce même calendrier, limité aux dates de la demande : la modifier met à jour les disponibilités partout.
4. Le résumé des meilleurs créneaux lit directement les calendriers personnels des membres actuels du groupe sur la période.
5. Un membre « a répondu » s'il a au moins une case non vide sur la période ; compteur, non-répondants et rappel en dépendent.
6. Vote, validation, e-mails et .ics inchangés ; le vote est prérempli depuis le calendrier personnel.
7. Quitter un groupe ou en être retiré ne supprime plus les disponibilités.
8. Personne ne consulte le calendrier d'un autre ; seul l'organisateur voit, via le résumé, les disponibilités des membres de son groupe sur la période de sa demande.
9. Les grilles déjà saisies sur des demandes sont reprises dans les calendriers personnels ; en cas de doublon sur un jour, la plus récente gagne.

## Décisions

| Sujet | Décision |
|---|---|
| Forme | Calendrier daté (pas de semaine type) |
| Horizon de saisie | Du jour même à aujourd'hui + 3 mois (`addMonthsNoOverflow(3)`), en Europe/Paris |
| Page demande | Le calendrier personnel restreint à la période (une seule source de vérité) |
| « A répondu » | ≥ 1 case non vide sur la période (pas de bouton de confirmation) |
| Période d'une demande | Doit finir au plus tard à aujourd'hui + 3 mois (validé à la création, et à l'édition si la fin change) ; la limite de 62 jours reste |
| Stockage | `availability_days` devient personnel : `user_id`, `day`, `cells`, unique (`user_id`, `day`) ; plus de `meeting_id` |
| Hors périmètre | Bloquer automatiquement un créneau confirmé ; semaine type récurrente |

## Données

- Migration `make_availability_days_personal` :
  1. pour chaque (`user_id`, `day`) en doublon, ne garder que la ligne au `updated_at` le plus récent (à égalité, `id` le plus grand) ;
  2. supprimer la contrainte unique (`meeting_id`, `user_id`, `day`), la clé étrangère et la colonne `meeting_id` ;
  3. ajouter l'unique (`user_id`, `day`).
  `down()` : recrée `meeting_id` nullable et l'ancien index (données de réunion perdues, acceptable).
  Compatible SQLite (tests) et MySQL (local/Forge) ; vérifier la base locale avant/après par une requête en lecture.
- `AvailabilityDay` : `user()` seulement ; constantes et helpers (`CellCount`, `FirstHour`, `Empty`, `localDateTime`, `statusFor`, `cellTime`) inchangés. Nouveau `AvailabilityDay::lastEditableDay(): CarbonImmutable` (aujourd'hui Paris + 3 mois).
- `User::availabilityDays(): HasMany`.
- `Meeting` : suppression de `availabilityDays()` ; `cellsByMember()` et `respondentIds()` lisent `availability_days` des membres actuels (`whereIn user_id`) entre `range_start` et `range_end`, lignes non vides seulement (les lignes tout à '0' ne sont de toute façon pas stockées).
- `Group::removeMember` ne touche plus aux disponibilités (garde la suppression des `slot_votes`).
- `Meetings\Form` : le rétrécissement de période ne supprime plus de disponibilités.

## Composant grille

`App\Livewire\Meetings\AvailabilityGrid` devient `App\Livewire\Availability\Grid` (vue `livewire/availability/grid.blade.php`, JS `availability-grid.js` réutilisé tel quel) :
- props : `?Meeting $meeting = null` (verrouillée) ; sans réunion, jours = de la semaine en cours (lundi) à `lastEditableDay`, avec jours passés grisés ; avec réunion, jours = `rangeDays()` ∩ [aujourd'hui, `lastEditableDay`] éditables, le reste de la période affiché grisé.
- `saveDays(array $days)` : chaque jour doit être éditable (dans la fenêtre ci-dessus, et pour une réunion dans sa période) et `^[0pd]{28}$` ; `updateOrCreate` sur (`user_id`, `day`) dans une transaction ; tout à '0' → suppression ; renvoie `true` ; `dispatch('availability-saved')`.
- Autorisation : sans réunion, utilisateur connecté et e-mail vérifié (comme le reste de l'espace) ; avec réunion, `editAvailability` (membre + collecte) en `mount`, `hydrate` et `saveDays`.
- Compteur « N personnes ont répondu sur M » : seulement avec réunion.

## Écrans

- Nouvelle route `availability` (GET `/disponibilites`, nom `availability.edit`), composant pleine page `App\Livewire\Availability\Edit` qui affiche le titre « Mes disponibilités », un court texte (« Vos disponibilités servent à toutes les demandes de vos groupes. »), puis `<livewire:availability.grid />`.
- Menu latéral : entrée « Mes disponibilités » sous « Tableau de bord ».
- Tableau de bord : une carte « Mes disponibilités » avec un lien, et le nombre de jours renseignés sur les 3 prochains mois (« Aucune disponibilité renseignée » sinon).
- Page d'une demande : inchangée en apparence (grille avant le résumé), mais avec `<livewire:availability.grid :meeting="$meeting" />` et un lien « Voir tout mon calendrier ».
- Accueil (animation étape 2) et aside de connexion : textes ajustés si besoin (« Chacun tient ses disponibilités à jour »).

## Résumé, vote, rappel, tableau de bord

- `Summary` : inchangé hors lecture des données (via `cellsByMember()`) ; écoute toujours `availability-saved`.
- `Vote` : préremplissage depuis `availability_days` de l'utilisateur pour le jour du créneau.
- Rappel (`meetings:send-reminders`) et `Dashboard::pendingMeetings` : « sans réponse » = aucun `availability_days` non vide de l'utilisateur dans la période.

## Droits

- `/disponibilites` : utilisateur connecté (middleware `auth`, `verified` comme le tableau de bord). Chacun ne lit et n'écrit que ses propres lignes (requêtes toujours scoppées sur `Auth::id()`).
- Le résumé ne lit que les membres actuels du groupe et que la période ; aucune autre vue n'expose le calendrier d'autrui.

## Tests (Pest)

- Migration : doublons (même user, même jour, deux réunions) → une seule ligne, la plus récente ; lignes d'utilisateurs différents conservées.
- Page `/disponibilites` : accessible sans groupe ; invité redirigé vers login ; enregistre un jour ; refuse un jour passé, un jour au-delà de +3 mois, un format invalide ; tout '0' supprime la ligne ; n'écrit jamais pour un autre utilisateur.
- Grille sur une demande : la saisie apparaît dans `/disponibilites` et dans une autre demande d'un autre groupe sur la même date ; jour hors période refusé ; non-membre 404 ; demande en vote → 403.
- Résumé : utilise les calendriers des membres actuels seulement ; un ancien membre n'est plus compté mais garde ses lignes.
- `respondentIds` / rappel / tableau de bord : ≥ 1 case dans la période = répondu ; des cases hors période ne comptent pas.
- Vote : préremplissage depuis le calendrier personnel.
- Formulaire : fin de période > aujourd'hui + 3 mois refusée ; rétrécir la période ne supprime plus rien.
- Les tests existants touchés sont adaptés (plus de `meeting_id` sur `AvailabilityDay`).

## Risques

- Migration sur MySQL : supprimer une clé étrangère et un index composite dans le bon ordre ; tester sur SQLite et vérifier sur la base locale (avec l'accord de l'utilisateur pour `migrate`).
- Confidentialité : un organisateur voit la disponibilité de ses membres sur toute la période même si le membre l'a saisie pour un autre groupe — c'est voulu (point 8) mais à mentionner dans le texte de la page.
