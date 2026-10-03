# Réglages du groupe — design

Date : 2026-10-03 · Branche : `feature/reglages-groupe` (depuis `main` e8db823)

## En bref — ce que ça fait pour l'utilisateur

1. Sur la page du groupe, le créateur du groupe trouve une section « Réglages » avec trois choix, chacun « Créateur du groupe » / « Tous les membres » : faire une demande de réunion ; ajouter ou inviter des membres ; valider une date.
2. Par défaut, les trois réglages valent « Créateur du groupe » : les groupes existants gardent leur fonctionnement actuel.
3. Faire une demande : si c'est ouvert à tous, chaque membre voit « Nouvelle demande » et devient l'**auteur** de sa demande (nom affiché sur la demande ; e-mail d'annonce aux autres membres).
4. Modifier / supprimer une demande : son auteur et le créateur du groupe, quel que soit le réglage.
5. Valider une date = voir le résumé des meilleurs créneaux, valider directement, soumettre au vote, valider le résultat du vote, annuler le vote : réservé aux personnes autorisées par le réglage. Les autres membres voient leur grille, puis le vote, puis la date retenue, comme aujourd'hui.
6. Inviter : les personnes autorisées voient le formulaire d'invitation et les invitations en attente (renvoyer / annuler).
7. Restent au seul créateur du groupe : renommer, supprimer le groupe, retirer un membre, changer les réglages.
8. Toutes ces règles sont vérifiées côté serveur (pas seulement en masquant les boutons).

## Vocabulaire

- **Créateur du groupe** : `groups.owner_id` (`Group::isOrganizer()`, renommé `Group::isCreator()` dans le code ; libellé UI « Créateur du groupe »). Pas d'autre rôle d'administration.
- Règle à préserver : le créateur du groupe en est automatiquement membre (ajouté à la création par `CreateGroup`), ne peut pas le quitter ni en être retiré. Un test le vérifie déjà ; aucune tâche ne doit la casser.
- **Auteur d'une demande** : `meetings.created_by` (`Meeting::creator()`).

## Données

Migration `add_permission_settings_to_groups` : trois booléens non nuls, défaut `false` (= réservé au créateur) :
- `members_can_request_meetings`
- `members_can_invite`
- `members_can_validate`

`Group` : casts `boolean` pour les trois ; méthodes `allowsMeetingRequests(User)`, `allowsInvitations(User)`, `allowsValidation(User)` qui renvoient vrai si l'utilisateur est membre et (créateur du groupe ou réglage à vrai). Factory : états `openToMembers()` (les trois à vrai).

## Autorisations (policies)

`GroupPolicy` — toutes renvoient 404 aux non-membres (comme aujourd'hui), 403 aux membres non autorisés :
- `view` : membre (inchangé).
- `update` : créateur du groupe — renommer, supprimer, retirer un membre, modifier les réglages (remplace `manage` pour ces actions).
- `requestMeeting` : `allowsMeetingRequests`.
- `invite` : `allowsInvitations` — inviter, renvoyer, annuler une invitation.
- `leave` : inchangé (un membre qui n'est pas le créateur).

`MeetingPolicy` :
- `view`, `editAvailability`, `vote` : inchangés.
- `update` : auteur de la demande ou créateur du groupe (et membre du groupe) — modifier (formulaire, toujours seulement en collecte), supprimer (à tout moment).
- `validate` : `allowsValidation` sur le groupe de la demande — résumé (`Summary` mount/hydrate/actions), valider une fenêtre, ouvrir le vote, valider un créneau du vote, annuler le vote.
- `manage` est supprimé ; chaque appel existant passe à `update` ou `validate` selon l'action.

## Écrans

- **Page du groupe** (`Groups\Show`) :
  - bouton « Nouvelle demande » si `requestMeeting` ;
  - formulaire d'invitation et invitations en attente si `invite` ;
  - renommer / supprimer / retirer un membre / section « Réglages » si `update` ;
  - section « Réglages » : trois `flux:radio.group variant="segmented"` (« Créateur du groupe » / « Tous les membres ») avec une phrase d'aide chacune, enregistrés immédiatement (`wire:model.live` + action autorisée `update`), message « Réglages enregistrés ».
  - le badge actuel du créateur dans la liste des membres affiche « Créateur du groupe ».
- **Formulaire de demande** (`Meetings\Form`) : création autorisée par `requestMeeting` ; édition et suppression par `update`.
- **Page d'une demande** (`Meetings\Show`) : « Auteur : <nom> » sous le titre ; boutons Modifier / Supprimer si `update` ; section résumé si `validate`.
- **Vote** (`Meetings\Vote`) : résultats + « Valider cette date » + « Annuler le vote » si `validate` (remplace `isOrganizer`).
- **Tableau de bord** : inchangé.

## E-mails

Inchangés. « Nouvelle demande » part à tous les membres sauf l'auteur (déjà le cas : l'auteur = `created_by`). Aucun nouvel e-mail.

## Tests (Pest)

- Réglages : seul le créateur du groupe les modifie (membre → 403, non-membre → 404) ; valeurs par défaut à faux ; une modification est persistée.
- Pour chacune des trois actions, avec réglage faux puis vrai : un membre est refusé (403) puis autorisé ; le créateur toujours autorisé ; non-membre 404. Couvrir l'action serveur (pas seulement l'affichage) : création de demande, `invite` / `resendInvitation` / `cancelInvitation`, `Summary::confirmWindow` / `openVote`, `Vote::confirmSlot` / `cancelVote`.
- Modifier / supprimer : l'auteur membre et le créateur du groupe oui ; un autre membre 403, même si `members_can_validate` est vrai.
- Renommer / supprimer le groupe / retirer un membre : membre 403 même avec les trois réglages à vrai.
- Affichage : boutons et sections présents ou absents selon les droits ; « Auteur : » sur la page d'une demande.
- Ré-autorisation à l'hydratation : un réglage repassé à faux pendant la session → l'action suivante du membre est refusée.
- Tests existants adaptés (`manage` → `update` / `validate`).

## Risques

- Les appels à `manage` sont nombreux (Groups\Show, Meetings\Show/Form/Summary/Vote) : chaque appel doit être reclassé, sinon un droit est trop large ou trop étroit. Un test par action serveur le garde.
- Un membre qui perd le droit de valider pendant qu'il regarde le résumé : `hydrate` ré-autorise `validate` → 403 à l'action suivante (comportement attendu).
