# Réunions à partir des disponibilités — design

Date : 2026-10-03
Statut : validé en conversation, en attente de relecture de cette spec
Remplace, pour la partie réunions, `2026-10-02-groupes-reunions-design.md` (groupes, membres, invitations, droits et tableau de bord de cette spec restent valables et sont déjà construits).

## En bref — ce que ça fait pour l'utilisateur

1. L'administrateur d'un groupe crée une **demande** : titre, lieu facultatif, **plage de dates** et **date butoir**. Les membres reçoivent un e-mail.
2. Chaque membre **colorie une grille** jours × heures (8h–22h, cases de 30 min) : 1 clic = sur place, 2 = à distance, 3 = effacer ; on peut glisser, au doigt sur mobile.
3. La **date butoir est indicative** : on peut modifier jusqu'à la validation. La veille de la date butoir, les non-répondants reçoivent une **relance**.
4. L'administrateur voit à tout moment un **résumé** réglé par : **durée** (30 min–8 h), **minimum de participants**, **minimum sur place**.
5. Les créneaux sont classés : plus de présents → plus de présents sur place → plus proche. Un membre ne compte « sur place » que s'il l'est **sur toute la durée**.
6. Les débuts consécutifs avec les mêmes présents sont **regroupés** (« début entre 18h00 et 18h30 »).
7. L'administrateur **valide** un créneau ou en **soumet plusieurs au vote**.
8. **Vote** : chacun répond sur place / à distance / pas dispo par créneau (prérempli depuis sa grille) ; l'administrateur valide la date.
9. **Date validée** : e-mail avec fichier `.ics` à tous les membres ; bouton « Ajouter à mon agenda » sur la page.
10. **Tableau de bord** : « À vous de répondre » (grilles et votes en attente) et réunions confirmées à venir.

## Décisions

| Sujet | Décision |
|---|---|
| Saisie | Grille à colorier, 8h–22h fixe, 28 cases de 30 min par jour |
| Stockage | Une ligne par membre et par jour, cases encodées dans un texte de 28 caractères (option A) |
| Date butoir | Indicative ; grilles modifiables tant que la demande est en collecte |
| Visibilité | Chaque membre ne voit que sa grille et le nombre de répondants ; le résumé détaillé est réservé à l'administrateur |
| Vote | Réponses sur place / à distance / pas dispo par créneau ; meilleure date par `FindBestSlot` ; l'administrateur valide |
| E-mails | Nouvelle demande, vote ouvert, date validée (+ `.ics`), relance veille du butoir ; envoi immédiat (pas de file) |
| Fuseau | Cases et dates en heure de `Europe/Paris` (`app.display_timezone`) ; instants stockés en UTC |

Hors périmètre : co-organisateurs, plusieurs demandes fusionnées, plage horaire configurable, réunions récurrentes, rappel avant la réunion confirmée.

## États d'une demande

`collecting` (collecte) → `voting` (vote) → `confirmed` (confirmée).

- `collecting` → `confirmed` : validation directe d'un créneau du résumé.
- `collecting` → `voting` : au moins deux créneaux soumis au vote.
- `voting` → `confirmed` : validation d'un des créneaux votés.
- `voting` → `collecting` : annulation du vote par l'administrateur ; les votes sont supprimés, les grilles conservées.
- Une demande confirmée n'est plus modifiable (grilles et votes en lecture seule). Suppression possible par l'administrateur à tout moment.

Enum PHP `MeetingStatus` (`Collecting`, `Voting`, `Confirmed`).

## Données

Modification des tables existantes (branche non livrée : migrations de création amendées ou nouvelle migration, au choix du plan) :

- `meetings` : ajout `range_start` (date), `range_end` (date), `deadline` (date), `status` (string, enum), `confirmed_starts_at` (datetime UTC, nullable), `confirmed_ends_at` (datetime UTC, nullable), `reminder_sent_at` (datetime, nullable).
- `meeting_slots` : sert uniquement aux créneaux soumis au vote ; ajout `ends_at` (datetime UTC).
- `availabilities` est **renommée** `slot_votes` (modèle `SlotVote`, statut `AvailabilityStatus` inchangé) : votes par créneau.
- Nouvelle table `availability_days` : `id`, `meeting_id`, `user_id`, `day` (date, heure de Paris), `cells` (char 28), horodatages ; unique (`meeting_id`, `user_id`, `day`). Caractère *i* = case de 8h00 + 30 min × *i* : `0` indisponible, `p` sur place, `d` à distance. Une ligne entièrement à `0` est supprimée plutôt que stockée.
- Un membre « a répondu » à une demande en collecte s'il possède au moins une ligne `availability_days` pour elle ; à un vote, s'il a un vote pour chaque créneau.

Contraintes de saisie : `range_end` ≥ `range_start`, plage de 62 jours au plus, `range_start` ≥ aujourd'hui ; `deadline` entre aujourd'hui et `range_end` ; titre requis (120 car. max), lieu facultatif (255), description facultative (2000).

Cascades : suppression d'une demande → jours, créneaux, votes. Un membre qui quitte ou est retiré du groupe → ses lignes `availability_days` et `slot_votes` des demandes du groupe sont supprimées.

## La grille (membre)

- Desktop : une semaine (lundi–dimanche) à la fois, jours en colonnes, 28 lignes 8h00–21h30 ; flèches semaine précédente / suivante bornées par la plage ; jours hors plage grisés et non modifiables.
- Mobile (< 640 px) : un jour à la fois, barre de jours défilante.
- Coloriage : au premier appui, la valeur cible = état suivant de la case touchée (`0`→`p`→`d`→`0`) ; tout ce qui est survolé pendant le glisser prend cette valeur ; souris, tactile et stylet (Pointer Events). Clavier : cases focusables, Espace/Entrée pour faire tourner l'état.
- Enregistrement automatique à la fin du geste : seuls les jours modifiés sont envoyés ; indicateur « Enregistrement… / Enregistré » ; en cas d'échec, message et nouvel essai au geste suivant.
- « Copier ce jour sur la semaine » : recopie le jour sélectionné sur les autres jours de la même semaine compris dans la plage.
- Légende des couleurs, nombre de répondants (« 12 personnes ont répondu sur 15 »), date butoir, et rappel que les heures sont celles de Paris.
- En lecture seule (avec message) quand la demande est en vote ou confirmée.
- Composants Flux pour tout le reste de l'écran (boutons, cartes, badges, callouts) ; la grille elle-même est du HTML/Alpine (aucun composant Flux équivalent).

## Le résumé (administrateur)

Réglages (persistés dans l'URL) : durée 30–480 min par pas de 30 (défaut 120), minimum de participants (défaut 1), minimum sur place (défaut 0, ≤ minimum de participants).

Calcul, par une action dédiée testable isolément :

- Pour chaque jour de la plage et chaque case de début *s* telle que la réunion se termine au plus tard à 22h00 :
  - présents = membres actuels du groupe dont toutes les cases de *s* à *s + n − 1* (n = durée / 30) sont `p` ou `d` ;
  - sur place = membres dont toutes ces cases sont `p` ; à distance = présents − sur place.
- Filtre : présents ≥ minimum de participants et sur place ≥ minimum sur place.
- Regroupement : débuts consécutifs d'un même jour avec exactement les mêmes ensembles sur place et à distance → une ligne (« début entre X et Y »).
- Classement : présents ↓, sur place ↓, premier début possible ↑ (plus proche).
- Affichage : 15 meilleures lignes ; par ligne : jour, plage de début, fin au plus tard, total, sur place, à distance, noms (sur place / à distance / absents).
- En-tête : « N réponses sur M » et liste des non-répondants.
- Aucun créneau : message invitant à réduire la durée ou les minimums.

## Valider, voter

- **Valider** depuis le résumé : bouton par ligne ; si la ligne couvre plusieurs débuts, choix de l'heure exacte. → `confirmed`, `confirmed_starts_at/ends_at` renseignés, e-mail « date validée ».
- **Soumettre au vote** : cases à cocher sur les lignes (heure exacte choisie par ligne), au moins 2 ; crée les `meeting_slots` (début, fin) → `voting`, e-mail « vote ouvert ».
- **Voter** (membre) : une ligne par créneau, choix sur place / à distance / pas dispo, prérempli depuis la grille (sur place si toutes les cases du créneau sont `p`, à distance si toutes sont `p` ou `d` avec au moins un `d`, sinon pas dispo) ; enregistrement explicite ; toutes les lignes requises.
- **Résultats du vote** (administrateur) : tableau des votes, meilleure date via `FindBestSlot`, non-votants ; bouton « Valider cette date » par créneau → `confirmed` + e-mail.
- **Annuler le vote** : → `collecting`, créneaux et votes supprimés.
- **Confirmée** : page affichant date, heures, lieu ; bouton « Ajouter à mon agenda » qui télécharge le `.ics`.

## E-mails (notifications Laravel, français, envoi immédiat)

| Moment | Destinataires | Contenu |
|---|---|---|
| Demande créée | membres du groupe sauf l'administrateur | titre, plage, date butoir, bouton « Indiquer mes disponibilités » |
| Vote ouvert | membres sauf l'administrateur | créneaux proposés, bouton « Voter » |
| Date validée | tous les membres | date, heures, lieu, `.ics` en pièce jointe |
| Relance | membres sans aucune ligne `availability_days` | la veille de `deadline`, à 9h00 Paris, une seule fois par demande (`reminder_sent_at`) |

- Relance : commande artisan planifiée chaque jour à 9h00 (`Europe/Paris`) ; ne concerne que les demandes en `collecting`.
- `.ics` généré sans dépendance : `VCALENDAR`/`VEVENT` avec `UID` stable (id de la demande + domaine), `DTSTART`/`DTEND` en UTC, `SUMMARY`, `LOCATION`, `DESCRIPTION` (lien vers la page).
- Production : activer le planificateur Laravel sur Forge (`php artisan schedule:run` chaque minute).

## Droits

- Demande : voir = membre du groupe (sinon 404) ; créer, voir le résumé, valider, soumettre / annuler un vote, supprimer = administrateur (sinon 403).
- Grille et vote : un membre ne modifie que les siens, seulement dans le bon état (`collecting` pour la grille, `voting` pour le vote).
- Toutes les actions Livewire ré-autorisent (y compris `hydrate()` pour la consultation, comme la page du groupe).

## Écrans touchés

- Page du groupe : liste des demandes avec leur état (badge collecte / vote / confirmée), réponses reçues, date confirmée le cas échéant ; bouton « Nouvelle demande » (administrateur).
- Formulaire de demande (administrateur) : titre, lieu, description, plage (`flux:date-picker` mode plage), date butoir (`flux:date-picker`).
- Page d'une demande : selon l'état et le rôle — grille (membre, collecte), résumé (administrateur, collecte), vote (membre, vote), résultats du vote (administrateur, vote), date confirmée (tous).
- Tableau de bord : « À vous de répondre » = demandes en collecte sans grille de l'utilisateur + votes sans vote complet ; « Réunions confirmées » à venir ; « Mes groupes ».
- Accueil : l'animation « Comment ça marche » est mise à jour pour raconter grille → résumé → validation (texte et scènes), même composant.

## Tests (Pest)

- Résumé : durée, minimums, « sur place sur toute la durée », limite de 22h00, regroupement des débuts consécutifs (et non-regroupement si les présents changent), classement, aucun résultat, membres ayant quitté ignorés.
- Grille : enregistrement d'un jour, effacement (ligne supprimée), jours hors plage refusés, cases invalides refusées, lecture seule hors collecte, impossible d'écrire pour un autre membre.
- États : chaque transition autorisée, transitions interdites (ex. voter en collecte, valider une demande confirmée), annulation du vote.
- Vote : préremplissage depuis la grille, enregistrement, toutes les lignes requises, meilleure date.
- E-mails : destinataires de chaque notification, `.ics` (dates UTC, titre, lieu), relance (veille du butoir uniquement, non-répondants uniquement, une seule fois, pas pour les demandes en vote ou confirmées).
- Droits : 404 non-membre, 403 membre sur chaque action d'administrateur, ré-autorisation à l'hydratation.
- Tableau de bord : « À vous de répondre » et réunions confirmées.
- Non-régression : toute la suite existante passe (avec le renommage `availabilities` → `slot_votes`).

## Risques

- **Performance du résumé** : 62 jours × 28 débuts × membres ; calcul en mémoire sur les lignes du groupe (une requête), acceptable pour quelques dizaines de membres.
- **Grille tactile** : le glisser doit empêcher le défilement de la page pendant le geste, sans bloquer le défilement hors de la grille.
- **Heure de Paris** : cases en heure locale ; conversion en UTC uniquement pour les créneaux de vote et la date confirmée ; tests autour du changement d'heure.
- **Planificateur** : sans `schedule:run` sur le serveur, la relance ne part pas ; à documenter au déploiement.
