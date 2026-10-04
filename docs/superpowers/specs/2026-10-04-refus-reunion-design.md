# Refuser une réunion confirmée — design

Date : 2026-10-04 · Branche : `feature/refus-reunion` (depuis `main` e88dbc1)
Complète `2026-10-04-creneaux-reunion-design.md` (cases « Réunion » bloquées).

## En bref — ce que ça fait pour l'utilisateur

1. Sur la page d'une réunion confirmée, chaque membre voit « Je ne viendrai pas » (avec confirmation), à côté des boutons d'agenda.
2. Après un refus, les cases « Réunion » de ce créneau disparaissent de **sa** grille ; ses disponibilités saisies dessous réapparaissent et comptent à nouveau pour les autres demandes (résumé, préremplissage du vote).
3. Le bouton devient « Finalement, je viendrai » : le créneau redevient bloqué pour lui.
4. La page de la réunion affiche « Ne viendront pas : Amina, Bastien » (visible par tous les membres) dès qu'il y a au moins un refus. Aucun e-mail.
5. Tableau de bord, « Réunions confirmées » : une réunion refusée reste listée avec un badge « Vous ne venez pas ».
6. Seules les réunions confirmées se refusent ; tout membre peut refuser (y compris créateur du groupe et auteur) ; la réunion reste confirmée pour les autres.
7. Le refus disparaît si la réunion est supprimée ou si le membre quitte le groupe.

## Données

Migration `create_meeting_declines_table` : `id`, `meeting_id` (FK cascade on delete), `user_id` (FK cascade on delete), `timestamps`, unique (`meeting_id`, `user_id`).

- `Meeting::declines(): HasMany<MeetingDecline>`, `Meeting::decliners(): BelongsToMany<User>` (via `meeting_declines`), `Meeting::isDeclinedBy(User $user): bool`.
- Modèle `MeetingDecline` (+ factory) avec `meeting()`, `user()`.
- `Group::removeMember` supprime aussi les refus du membre sur les réunions du groupe (dans sa transaction existante).

## Autorisations

`MeetingPolicy::decline(User $user, Meeting $meeting): Response` — non-membre → 404 ; membre et réunion `confirmed` → allow ; sinon 403. Utilisé pour refuser et pour annuler son refus.

## Actions

`App\Livewire\Meetings\Show` :
- `decline(): void` — `authorize('decline')`, `firstOrCreate` du refus pour `Auth::id()`.
- `undoDecline(): void` — `authorize('decline')`, suppression du refus de `Auth::id()`.
- computed `hasDeclined: bool`, `decliners: Collection<User>` (triés par nom).

## Cases bloquées

`App\Actions\Availability\BusyCells` : une réunion ne bloque pas un utilisateur qui l'a refusée. Charger les ids des refus avec les réunions (`with('declines:id,meeting_id,user_id')` ou une sous-requête) ; dans la boucle des membres, ignorer ceux présents dans les refus de la réunion. Toujours un nombre de requêtes constant.

## Écrans

- **Page de la réunion confirmée** (carte « Date retenue ») : bouton `Je ne viendrai pas` (`wire:confirm` « Vous ne viendrez pas à cette réunion ? Le créneau sera libéré dans vos disponibilités. ») ou, si déjà refusé, texte « Vous avez indiqué que vous ne viendrez pas. » + bouton `Finalement, je viendrai`. Sous la carte : « Ne viendront pas : … » si au moins un refus.
- **Tableau de bord** : `Dashboard::confirmedMeetings` charge si l'utilisateur a refusé (ex. `withExists(['declines as declined_by_me' => fn ($q) => $q->where('user_id', Auth::id())])`) ; badge `Vous ne venez pas` (zinc) à côté de la date.

## Tests (Pest)

- Refuser puis annuler : refus créé puis supprimé ; un seul refus même après deux clics.
- Droits : non-membre 404 ; demande en collecte ou en vote → 403.
- `BusyCells` : un membre ayant refusé n'est plus bloqué ; les autres membres le restent ; annuler le refus rebloque.
- Résumé d'une autre demande : le membre ayant refusé redevient disponible sur ce créneau.
- Page : boutons selon l'état ; liste « Ne viendront pas » ; tableau de bord avec badge.
- `removeMember` et suppression de la réunion effacent les refus.

## Risques

- Oublier le filtre des refus dans `BusyCells` laisserait le créneau bloqué : test dédié.
- Les e-mails et agendas déjà envoyés ne changent pas (pas d'annulation côté agenda) — accepté.
