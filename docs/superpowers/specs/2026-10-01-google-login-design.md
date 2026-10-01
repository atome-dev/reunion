# Connexion avec Google — design

Date : 2026-10-01
Statut : validé en conversation, en attente de relecture de cette spec

## Objectif

Permettre de se connecter ou de s'inscrire avec un compte Google, en un clic, à côté de la connexion par e-mail/mot de passe et par clé d'accès. Public visé : membres d'associations et de collectifs, pour qui créer un mot de passe de plus est un frein.

Critères de réussite :

- un visiteur sans compte clique « Continuer avec Google » et arrive sur son tableau de bord, compte créé ;
- un membre qui a déjà un compte avec le même e-mail (vérifié chez Google) retrouve son compte, désormais relié à Google ;
- la double authentification (2FA) d'un compte n'est jamais contournée ;
- un compte créé via Google peut gérer ses réglages (définir un mot de passe, supprimer son compte, accéder aux réglages de sécurité) sans jamais avoir eu de mot de passe.

## Décisions

| Sujet | Décision |
|---|---|
| Technique | Laravel Socialite, flux OAuth par redirection (pas de One Tap pour l'instant) |
| Comportement | Connexion **et** inscription via Google |
| Liaison | Automatique par e-mail, uniquement si Google déclare l'e-mail vérifié |
| Mot de passe | Facultatif pour les comptes créés via Google |
| 2FA | Si activée sur le compte, l'écran de code Fortify est imposé après Google |
| Hors périmètre | Délier Google, autres fournisseurs, Google One Tap |

## Dépendance et configuration

- Nouvelle dépendance Composer : `laravel/socialite` (accord donné en conversation).
- `config/services.php` : entrée `google` avec `client_id` = `env('GOOGLE_OAUTH_ID')`, `client_secret` = `env('GOOGLE_OAUTH_SECRET')`, `redirect` = URL de la route de retour (dérivée d'`APP_URL`, jamais codée en dur).
- `.env.example` : `GOOGLE_OAUTH_ID=` et `GOOGLE_OAUTH_SECRET=` vides.
- Adresse de retour à déclarer dans la Google Cloud Console : `{APP_URL}/auth/google/callback`. Google n'accepte que les domaines à extension publique ou `localhost` : en local, déclarer aussi `http://localhost:8000/auth/google/callback` et tester via `php artisan serve` ; `https://reunion.local` sera vraisemblablement refusé par la console.

## Données

Migration unique sur `users` :

- ajout `google_id` : chaîne, nullable, unique ;
- `password` devient nullable.

Modèle `User` : `google_id` ajouté aux attributs cachés ; méthode `hasPassword(): bool`. `google_id` n'est pas assignable en masse (renseigné explicitement par l'action).

## Parcours de connexion

Routes (middleware `guest`) :

- `GET /auth/google/redirect` → redirection Socialite vers Google ;
- `GET /auth/google/callback` → contrôleur de retour.

Action `ResolveGoogleUser` (une responsabilité : trouver, relier ou créer le compte à partir de l'utilisateur Google) :

1. compte avec ce `google_id` → le retourne ;
2. sinon compte avec le même e-mail :
   - e-mail vérifié chez Google → renseigne `google_id`, renseigne `email_verified_at` s'il était vide, retourne le compte ;
   - e-mail non vérifié → refus (exception métier), rien n'est modifié ;
3. sinon crée le compte : nom et e-mail venant de Google, `email_verified_at` = maintenant, `google_id`, pas de mot de passe.

Contrôleur de retour :

- erreur ou annulation chez Google (exception Socialite, état invalide, accès refusé) → redirection vers la connexion avec un message d'erreur ;
- refus de l'action (e-mail non vérifié) → redirection vers la connexion avec un message expliquant de se connecter par e-mail/mot de passe ;
- compte avec 2FA confirmée → stocke en session `login.id` et `login.remember` comme Fortify, redirige vers l'écran de code 2FA existant, **sans ouvrir de session** ;
- sinon → ouvre la session (« se souvenir de moi » activé), régénère la session, redirige vers le tableau de bord.

## Reconfirmation via Google

La page « Confirmer le mot de passe » (exigée avant les réglages de sécurité) propose « Confirmer avec Google » pour un compte relié à Google.

- `GET /auth/google/confirm` (middleware `auth`) : mémorise en session l'intention de confirmation, redirige vers Google ;
- au retour, si l'intention est présente : le `google_id` renvoyé doit correspondre à l'utilisateur connecté ; si oui, `auth.password_confirmed_at` = maintenant et retour à l'URL voulue ; sinon, retour à la page de confirmation avec une erreur. Aucune connexion ni liaison n'a lieu dans ce mode.

## Pages

- **Connexion et inscription** : bouton « Continuer avec Google » (logo Google officiel) en tête de formulaire, avant le bouton clé d'accès et le séparateur.
- **Réglages → Sécurité** :
  - compte sans mot de passe : le formulaire devient « Définir un mot de passe » (nouveau + confirmation, pas de mot de passe actuel) ;
  - compte avec mot de passe : inchangé ;
  - mention « Compte Google relié » quand `google_id` est renseigné.
- **Réglages → Supprimer le compte** : sans mot de passe, la confirmation se fait en retapant son adresse e-mail.
- **Confirmer le mot de passe** : bouton « Confirmer avec Google » pour les comptes reliés ; le formulaire mot de passe n'est affiché que si le compte en a un.
- Tous les nouveaux textes passent par `lang/fr.json`.

## Tests (Pest, Google simulé via Socialite)

Parcours de connexion :

- la redirection pointe vers Google ;
- nouvel utilisateur → compte créé (e-mail vérifié, sans mot de passe, `google_id`), session ouverte ;
- compte relié par `google_id` → connecté, pas de doublon ;
- compte existant, même e-mail vérifié → relié puis connecté ;
- e-mail non vérifié correspondant à un compte existant → refus, aucune liaison, message ;
- compte avec 2FA → redirigé vers l'écran de code, pas de session ouverte ;
- erreur ou annulation Google → retour à la connexion avec message ;
- utilisateur déjà connecté → routes inaccessibles.

Réglages :

- sans mot de passe : définir un mot de passe sans l'actuel ; avec mot de passe : l'actuel reste exigé ;
- suppression sans mot de passe : bon e-mail → supprimé ; mauvais e-mail → refusé ;
- confirmation via Google : bon compte Google → confirmé ; autre compte Google → refusé.

Non-régression : toute la suite existante passe.

## Vérification manuelle

- Avec les identifiants Google renseignés : parcours réel via `http://localhost:8000` (bouton, aller-retour Google, création du compte, réglages).
- Affichage des boutons en clair et en sombre ; réglages d'un compte sans mot de passe.

## Risques

- **Liaison par e-mail** : n'a lieu que si Google certifie l'e-mail ; un e-mail non vérifié ne prend jamais le contrôle d'un compte existant.
- **Mot de passe nullable** : tout code qui suppose un mot de passe (règles `current_password`, confirmation) est couvert par les cas ci-dessus et les tests.
- **Domaine local** : voir la contrainte Google sur l'adresse de retour (section configuration).
