# Groupes, réunions et disponibilités — design

Date : 2026-10-02
Statut : validé en conversation, en attente de relecture de cette spec

## Objectif

Livrer la première tranche utile de Réunion : un organisateur crée un groupe, y invite des personnes par e-mail, crée une réunion avec plusieurs dates candidates ; chaque membre indique pour chaque date s'il sera présent sur place, à distance, ou pas disponible ; tout le monde voit le tableau des réponses et la meilleure date.

Critères de réussite :

- de « je crée mon groupe » à « je vois la meilleure date », sans aide et sans échange de mails à côté ;
- un membre invité rejoint le groupe et répond depuis son téléphone en moins d'une minute ;
- personne ne voit ni ne modifie ce qui ne le concerne pas.

## Décisions

| Sujet | Décision |
|---|---|
| Comptes | Obligatoires pour répondre (Google ou e-mail, déjà en place) |
| Accès | Seuls les membres du groupe |
| Organisation | Groupes réutilisables ; une réunion invite tous les membres du groupe |
| Entrée dans un groupe | Lien d'invitation personnel, unique, envoyé par e-mail, valable 7 jours, acceptable avec n'importe quel compte |
| Rôles | Un organisateur (le créateur) par groupe ; les autres sont membres |
| Visibilité des réponses | Tous les membres voient le tableau ; l'organisateur voit en plus qui n'a pas répondu |
| Meilleure date | Calculée, jamais stockée : plus de présents, puis plus de présents sur place, puis la date la plus proche |
| Fuseau horaire | Stockage en UTC ; saisie et affichage dans `Europe/Paris` (réglage `app.display_timezone`) |
| E-mails | Envoi immédiat (non mis en file) ; en local, visibles dans Mailpit (`http://127.0.0.1:8025`) |

## Hors périmètre (tranche suivante)

Fixer la date retenue et prévenir les membres, relances des non-répondants, notification « nouvelle réunion », co-organisateurs, archivage. Une réunion dont toutes les dates sont passées est seulement classée dans « Réunions passées ».

## Données

- `groups` : `id`, `name`, `owner_id` (utilisateur organisateur), horodatages.
- `group_user` : `group_id`, `user_id`, `role` (`organizer` | `member`), horodatages ; unique (`group_id`, `user_id`). Le créateur y figure avec le rôle `organizer`.
- `group_invitations` : `id`, `group_id`, `email`, `token_hash` (le jeton en clair n'est jamais stocké), `invited_by`, `expires_at`, `accepted_at` nullable, `accepted_by` nullable, horodatages. Une invitation annulée est supprimée.
- `meetings` : `id`, `group_id`, `title`, `description` nullable, `location` nullable, `created_by`, horodatages.
- `meeting_slots` : `id`, `meeting_id`, `starts_at` (UTC), horodatages ; au moins deux par réunion ; pas de doublon de date dans une réunion.
- `availabilities` : `id`, `meeting_slot_id`, `user_id`, `status` (`on_site` | `remote` | `unavailable`), horodatages ; unique (`meeting_slot_id`, `user_id`). Un membre sans aucune ligne pour une réunion « n'a pas encore répondu ».
- Suppression en cascade : groupe → membres, invitations, réunions → dates → réponses. Un membre qui quitte ou est retiré : ses réponses aux réunions du groupe sont supprimées.

Les statuts `on_site` / `remote` / `unavailable` et les rôles sont des enums PHP.

## Calcul de la meilleure date

Une classe dédiée reçoit les dates d'une réunion avec leurs réponses et renvoie, pour chaque date, le nombre de présents sur place et à distance, ainsi que la meilleure date :

1. le plus grand total (sur place + à distance) ;
2. à égalité, le plus de présents sur place ;
3. à égalité, la date la plus proche ;
4. si personne n'a répondu « présent » à aucune date : pas de meilleure date (« En attente de réponses »).

C'est la même règle que le sondage d'exemple de l'accueil (`DemoPoll`), qui est réaligné sur cette classe pour qu'il n'existe qu'une implémentation.

## Écrans

Composants Livewire pleine page (comme les réglages existants), composants Flux, style B de l'espace connecté, mobile d'abord. Tous les textes en français via `lang/fr.json`.

1. **Tableau de bord** : « À vous de répondre » (réunions à venir sans réponse de l'utilisateur, accès direct) puis « Mes groupes » (nom, nombre de membres, prochaine réunion). État vide : invitation à créer son premier groupe. La barre latérale liste les groupes de l'utilisateur.
2. **Créer un groupe** : fenêtre modale (nom), puis redirection vers le groupe.
3. **Page du groupe** : réunions à venir (meilleure date actuelle, nombre de réponses) puis « Réunions passées » ; membres et invitations en attente.
   - Organisateur : inviter plusieurs adresses d'un coup (séparées par virgules, espaces ou retours à la ligne), renvoyer ou annuler une invitation, retirer un membre, renommer ou supprimer le groupe, créer une réunion.
   - Membre : quitter le groupe.
4. **Créer / modifier une réunion** (organisateur) : titre, description et lieu facultatifs, dates candidates (date + heure, ajout et retrait, minimum deux). Retirer une date existante supprime ses réponses, après confirmation. L'organisateur peut supprimer la réunion.
5. **Page d'une réunion** :
   - « Votre réponse » : une ligne par date avec trois choix (Sur place / À distance / Pas dispo), bouton Enregistrer ; modifiable ensuite ; toutes les dates doivent être renseignées pour enregistrer.
   - Tableau des réponses de tous les membres, meilleure date mise en avant, décompte sur place / à distance par date (rendu inspiré du sondage de l'accueil).
   - Organisateur : « N'ont pas encore répondu : … ».
6. **Accepter une invitation** (`/invitations/{token}`) :
   - non connecté : page « X vous invite à rejoindre *Groupe* » avec Continuer avec Google, Se connecter, Créer un compte ; après connexion ou inscription, retour sur cette page ;
   - connecté : bouton « Rejoindre le groupe » (requête POST), puis page du groupe ;
   - déjà membre : redirection vers le groupe, l'invitation est marquée acceptée ;
   - lien expiré, déjà utilisé, annulé ou inconnu : message clair invitant à demander une nouvelle invitation.
7. **E-mail d'invitation** : notification Laravel en français : nom du groupe, nom de l'invitant, bouton « Rejoindre le groupe », date d'expiration.

## Règles d'accès

Policies Laravel, testées une à une :

- groupe et réunions visibles uniquement par les membres ; un non-membre reçoit une 404 (on ne révèle pas l'existence du groupe) ;
- gestion du groupe (renommer, supprimer, inviter, annuler/renvoyer une invitation, retirer un membre) et des réunions (créer, modifier, supprimer) : organisateur uniquement, sinon 403 ;
- un membre ne crée et ne modifie que sa propre réponse, et seulement pour une réunion de son groupe ;
- l'organisateur ne peut pas quitter son propre groupe (il peut le supprimer) ;
- invitations : on n'invite pas une adresse déjà membre du groupe ni une adresse ayant déjà une invitation valide en attente (elle est signalée, pas dupliquée) ; adresses invalides refusées avec un message.

## Tests (Pest)

- Calcul de la meilleure date : total, égalité départagée par le sur place, puis par la date la plus proche, aucune réponse.
- Groupes et invitations : création (créateur organisateur) ; invitation de plusieurs adresses (e-mails simulés) ; pas de doublon membre ni invitation en attente ; acceptation d'un lien valide (rejoint, lien consommé) ; refus des liens expirés, utilisés, annulés, inconnus ; visiteur non connecté renvoyé à la connexion puis ramené à l'invitation ; renvoyer, annuler, retirer un membre, quitter.
- Réunions et réponses : création avec au moins deux dates (une seule refusée) ; enregistrement puis modification d'une réponse ; impossible de modifier la réponse d'un autre ; retirer une date supprime ses réponses ; liste des non-répondants exacte.
- Accès : 404 pour un non-membre sur chaque écran ; 403 pour un membre sur chaque action d'organisateur.
- Tableau de bord : « À vous de répondre » ne liste que les réunions à venir sans réponse de l'utilisateur.
- Non-régression : toute la suite existante passe.

## Vérification à l'écran

Captures clair/sombre, desktop/mobile : tableau de bord vide et rempli, page de groupe, page de réunion, page d'invitation. Données de démonstration via un seeder, lancé sur la base locale uniquement avec l'accord de l'utilisateur. E-mail d'invitation vérifié dans Mailpit.

## Risques

- **Jetons d'invitation** : stockés hachés, comparés en temps constant, à usage unique et limités dans le temps ; un lien fuité après acceptation ne sert plus.
- **Fuseau horaire** : toute conversion passe par `app.display_timezone` ; tests sur une date proche d'un changement d'heure.
- **Volume** : groupes associatifs de quelques dizaines de membres ; le tableau charge réponses et membres en une requête groupée par réunion (pas de N+1).
