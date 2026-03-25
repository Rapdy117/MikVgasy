# Regles Metier Et Contraintes De Validation

## Objectif

Ce document formalise les regles metier deduites du code actuel, sans inventer de logique absente du projet.

## Regles D'Acces

- toute page interne demarre une session PHP et exige `$_SESSION['logged_in'] = true`
- la connexion administrateur actuelle passe par [api_proxy.php](/var/www/html/api_proxy.php)
- la deconnexion passe par `index.php?logout=true`

## Regles Utilisateur

### Creation

- `username` est obligatoire
- `password` est obligatoire
- `profile_id` est attendu fonctionnellement comme obligatoire
- si le profil n'existe pas, la creation echoue

Reference :

- [api/users/create_user.php](/var/www/html/api/users/create_user.php)

### Mise a jour

- `id` est obligatoire
- `username` est obligatoire
- le profil cible doit exister

Reference :

- [api/users/update_user.php](/var/www/html/api/users/update_user.php)

### Suppression

- `id` est obligatoire
- l'utilisateur doit exister en base
- la suppression efface :
  - `radcheck`
  - `radreply`
  - `radusergroup`
  - `users`
- l'historique `radacct` n'est pas supprime par defaut

Reference :

- [api/users/delete_user.php](/var/www/html/api/users/delete_user.php)

### Statut

Valeurs visibles dans l'UI :

- `active`
- `disabled`
- `expired`

Le code ne valide pas strictement cet enum cote backend, mais ces trois valeurs constituent la regle metier observee.

## Regles Profil

### Creation

- `profile_name` est obligatoire
- `nas_id` est obligatoire
- le NAS doit exister

Reference :

- [api/profiles/create_profile.php](/var/www/html/api/profiles/create_profile.php)

### Synchronisation RADIUS

Lorsqu'un profil est cree :

- les entrees `radgroupreply` du groupe sont d'abord supprimees
- les nouvelles limitations sont ensuite recreees

Reference :

- [includes/radius_sync.php](/var/www/html/includes/radius_sync.php)

### Couplage fonctionnel

- le nom du profil sert aussi de `groupname` RADIUS
- le renommage d'un profil a donc un impact direct sur `radusergroup` et `radgroupreply`

## Regles Sur Les Champs De Temps

### `session_timeout`

Formes observees :

- champ numerique dans l'UI profil
- champ numerique dans l'UI utilisateur

Regle metier cible deduite pour la documentation :

- `session_timeout: int`
- `session_timeout = 0` -> illimite

Etat reel du code :

- applique pour les profils
- non applique pour les utilisateurs
- pas de normalisation stricte `''`, `null`, `0`

### `idle_timeout`

Regle de reference :

- `idle_timeout: int`
- `idle_timeout = 0` -> pas de deconnexion pour inactivite

Etat du code :

- mappe dans les profils
- mappe dans les attributs utilisateur

### `expiration_date`

Regle observee :

- optionnelle
- stockee comme date/heure applicative
- poussee en attribut RADIUS `Expiration` pour les utilisateurs

## Regles Sur Les Quotas Et Debits

### `data_limit` / `data_quota_mb`

Regle de reference :

- unite fonctionnelle attendue : MB
- `0` -> illimite

Etat du code :

- profil : conversion explicite vers octets
- utilisateur : pas de conversion uniforme visible

### `rate_limit`

Format observe :

- texte libre, exemple `2M/2M`

Regle de reference :

- format attendu `download/upload`
- chaque partie doit etre parseable par la logique `K` ou `M`

Etat du code :

- aucune validation forte avant transformation

### `simultaneous_use`

Regle de reference :

- entier positif
- `1` est la valeur par defaut implicite observee pour les profils

Reference :

- [api/profiles/create_profile.php#L15](/var/www/html/api/profiles/create_profile.php#L15)

## Regles Sur Les Booleens

### `auto_renewal`

Format observe :

- formulaire envoie `0` ou `1`

Regle de reference :

- `0` = non
- `1` = oui

### `verify_ssl`

Format observe :

- formulaire envoie `true` ou `false`
- stockage JSON en bool

Regle de reference :

- `true` = verification SSL active
- `false` = verification desactivee

## Contraintes De Validation Observees Ou Necessaires

### Contraintes minimales deja presentes

- presence de `username` et `password` a la creation utilisateur
- presence de `profile_name` et `nas_id` a la creation profil
- presence des credentials OPNsense pour les tests

### Contraintes non appliquees mais necessaires pour la coherence

- normaliser tous les entiers de formulaire
- refuser les chaines vides pour les champs numeriques critiques
- verifier l'unicite fonctionnelle de `profiles.name`
- verifier la coherence du format `rate_limit`
- verifier que `balance` est un decimal valide
- verifier que `status` est dans l'enum attendu

## Regles Metier Transverses

- les donnees applicatives et les donnees FreeRADIUS doivent rester synchronisees
- toute creation utilisateur doit provisionner les tables RADIUS necessaires
- toute suppression utilisateur doit nettoyer les entrees RADIUS associees
- toute creation profil doit regenerer les attributs de groupe RADIUS

## Regles UI Et Mise En Page

- toute evolution de page doit preserver la base UI deja en place lorsqu'elle est consideree propre
- une refonte visuelle locale ne doit pas casser la coherence avec `theme.css` et les composants deja stabilises
- avant d'ajouter de nouveaux champs ou boutons, privilegier des ajustements sobres de la mise en page existante
- l'ajout d'une nouvelle logique fonctionnelle ne doit pas se traduire par une degradation visuelle de la page
- sur `pages/network_devices.php`, les evolutions doivent rester compatibles avec la structure actuelle plutot que reconstituer une interface a zero
- les fichiers globaux partages comme [css/sidebar.css](/var/www/html/css/sidebar.css), [includes/sidebar.php](/var/www/html/includes/sidebar.php) et [css/theme.css](/var/www/html/css/theme.css) ne doivent pas etre modifies sans autorisation explicite prealable
- si la demande utilisateur cible une page locale, le perimetre de modification doit rester local tant qu'une autorisation explicite n'etend pas ce perimetre

## Regle De Prudence Pour Les Modifications

- si un champ est affiche dans l'UI mais non persiste cote backend, il ne doit pas etre suppose fonctionnel tant que la chaine complete formulaire -> backend -> stockage n'est pas verifiee
