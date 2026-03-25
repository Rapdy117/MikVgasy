# Refactorisation De La Logique API

## Objectif

Ce document identifie les logiques dupliquees dans les endpoints API autour de :

- la creation utilisateur
- la mise a jour utilisateur
- la suppression utilisateur
- la gestion de profil

Le but est de proposer une centralisation en fonctions communes, sans modifier le code existant pour l'instant.

## Perimetre Analyse

Fichiers principalement concernes :

- [api/users/create_user.php](/var/www/html/api/users/create_user.php)
- [api/users/update_user.php](/var/www/html/api/users/update_user.php)
- [api/users/delete_user.php](/var/www/html/api/users/delete_user.php)
- [api/profiles/create_profile.php](/var/www/html/api/profiles/create_profile.php)
- [includes/radius_sync.php](/var/www/html/includes/radius_sync.php)

## 1. Logiques Dupliquees Observees

## 1.1 Helpers d'entree HTTP dupliques

Code duplique dans :

- [api/users/create_user.php](/var/www/html/api/users/create_user.php)
- [api/users/update_user.php](/var/www/html/api/users/update_user.php)
- [api/users/delete_user.php](/var/www/html/api/users/delete_user.php)
- [api/profiles/create_profile.php](/var/www/html/api/profiles/create_profile.php)

Fonctions repetees :

- `post_string_or_null()`
- `post_int_or_default()`

Probleme :

- meme logique recopies dans plusieurs endpoints
- risque de divergence future dans les regles de normalisation

Centralisation proposee :

- `request_string_or_null($source, $key)`
- `request_int_or_default($source, $key, $default = 0)`
- `request_required_string($source, $key)`

Lieu cible possible :

- `includes/request_helpers.php`

## 1.2 Controle d'acces duplique

Code duplique dans :

- [api/users/create_user.php](/var/www/html/api/users/create_user.php)
- [api/users/update_user.php](/var/www/html/api/users/update_user.php)
- [api/users/delete_user.php](/var/www/html/api/users/delete_user.php)

Logique repetee :

- `session_start()`
- verification de `$_SESSION['logged_in']`
- `403 Unauthorized`

Centralisation proposee :

- `require_logged_in()`

Lieu cible possible :

- `includes/auth_guard.php`

## 1.3 Chargement du profil par `profile_id`

Code duplique dans :

- [api/users/create_user.php](/var/www/html/api/users/create_user.php)
- [api/users/update_user.php](/var/www/html/api/users/update_user.php)

Logique repetee :

- `SELECT name FROM profiles WHERE id = ?`
- verification du profil
- extraction de `groupname`

Centralisation proposee :

- `getProfileById(PDO $pdo, int $profileId): array`
- `getProfileGroupName(PDO $pdo, int $profileId): string`

Lieu cible possible :

- `includes/profile_repository.php`

## 1.4 Provisionnement RADIUS utilisateur

Code duplique dans :

- [api/users/create_user.php](/var/www/html/api/users/create_user.php)
- [api/users/update_user.php](/var/www/html/api/users/update_user.php)

Logiques communes :

- preparation de `radreply`
- application de `rate_limit`
- application de `simultaneous_use`
- application de `idle_timeout`
- application de `data_limit`
- application de `expiration_date`

Differences :

- `create_user.php` fait `INSERT` dans `radcheck` et `radusergroup`
- `update_user.php` fait `UPDATE` dans `radcheck` et `radusergroup`
- `update_user.php` supprime `radreply` avant de le reconstruire

Centralisation proposee :

- `buildUserRadiusReplies($userData): array`
- `replaceUserRadiusReplies(PDO $pdo, string $username, array $replies): void`
- `insertUserRadiusReplies(PDO $pdo, string $username, array $replies): void`
- `saveUserRadiusCredentials(PDO $pdo, string $username, string $password, string $groupname, string $mode): void`

Ou mieux, une API de plus haut niveau :

- `provisionUserRadius(PDO $pdo, array $userData, string $groupname, string $mode): void`

Avec :

- `$mode = 'create'`
- `$mode = 'update'`

Lieu cible possible :

- `includes/user_radius_sync.php`

## 1.5 Ecriture SQL utilisateur applicatif

Code duplique conceptuellement dans :

- [api/users/create_user.php](/var/www/html/api/users/create_user.php)
- [api/users/update_user.php](/var/www/html/api/users/update_user.php)

Le bloc de donnees applicatives est le meme :

- `username`
- `password`
- `profile_id`
- `status`
- `fullname`
- `phone`
- `email`
- `address`
- `balance`
- `expiration_date`
- `auto_renewal`

Seule l'operation SQL change :

- `INSERT INTO users`
- `UPDATE users SET ... WHERE id = ?`

Centralisation proposee :

- `buildUserPersistencePayload(array $input): array`
- `createUserRecord(PDO $pdo, array $payload): int`
- `updateUserRecord(PDO $pdo, int $id, array $payload): void`

Lieu cible possible :

- `includes/user_repository.php`

## 1.6 Transaction globale create/update/delete utilisateur

Code duplique conceptuellement dans :

- [api/users/create_user.php](/var/www/html/api/users/create_user.php)
- [api/users/update_user.php](/var/www/html/api/users/update_user.php)
- [api/users/delete_user.php](/var/www/html/api/users/delete_user.php)

Structure repetee :

- `beginTransaction()`
- operation sur `users`
- operation sur tables RADIUS
- `commit()`
- `rollBack()` en cas d'erreur

Centralisation proposee :

- `runInTransaction(PDO $pdo, callable $callback)`

Lieu cible possible :

- `includes/db_transaction.php`

## 1.7 Gestion de suppression utilisateur / cleanup RADIUS

Code concentre dans :

- [api/users/delete_user.php](/var/www/html/api/users/delete_user.php)

La logique n'est pas encore dupliquee, mais elle est un bon candidat a extraction pour symetrie avec create/update.

Centralisation proposee :

- `getUsernameByUserId(PDO $pdo, int $id): string`
- `deleteUserRadiusState(PDO $pdo, string $username, bool $deleteAccounting = false): void`
- `deleteUserRecord(PDO $pdo, int $id): void`
- `deleteUser(PDO $pdo, int $id): void`

## 1.8 Gestion profil / sync RADIUS

Le projet a deja un debut de centralisation ici :

- [includes/radius_sync.php](/var/www/html/includes/radius_sync.php)

Fonctions deja extraites :

- `insertReply()`
- `convertToBits()`
- `applyRateLimit()`
- `syncProfileToRadius()`

Duplication restante :

- [api/profiles/create_profile.php](/var/www/html/api/profiles/create_profile.php) gere encore directement :
  - la lecture des champs
  - la validation
  - la resolution du NAS
  - l'insertion SQL `profiles`
  - l'appel de synchronisation

Centralisation proposee :

- `getNasTypeById(PDO $pdo, int $nasId): string`
- `buildProfilePayload(array $input): array`
- `createProfileRecord(PDO $pdo, array $payload): int`
- `createProfile(PDO $pdo, array $profileData, int $nasId): void`

Lieu cible possible :

- `includes/profile_service.php`

## 2. Fonctions A Creer

## 2.1 Fonctions transverses

- `require_logged_in(): void`
- `request_string_or_null(array $source, string $key): ?string`
- `request_int_or_default(array $source, string $key, int $default = 0): ?int`
- `runInTransaction(PDO $pdo, callable $callback): mixed`

## 2.2 Fonctions utilisateur

- `buildUserInputPayload(array $source): array`
- `validateUserInput(array $payload, string $mode): void`
- `createUserRecord(PDO $pdo, array $payload): int`
- `updateUserRecord(PDO $pdo, int $id, array $payload): void`
- `deleteUserRecord(PDO $pdo, int $id): void`
- `getUsernameByUserId(PDO $pdo, int $id): string`

## 2.3 Fonctions profil

- `buildProfileInputPayload(array $source): array`
- `validateProfileInput(array $payload): void`
- `createProfileRecord(PDO $pdo, array $payload): int`
- `getProfileById(PDO $pdo, int $profileId): array`
- `getProfileGroupName(PDO $pdo, int $profileId): string`

## 2.4 Fonctions NAS

- `getNasTypeById(PDO $pdo, int $nasId): string`

## 2.5 Fonctions de sync RADIUS utilisateur

- `buildUserRadiusReplyAttributes(array $payload): array`
- `insertUserRadiusReplies(PDO $pdo, string $username, array $attributes): void`
- `replaceUserRadiusReplies(PDO $pdo, string $username, array $attributes): void`
- `saveUserRadiusCredentials(PDO $pdo, string $username, string $password, string $groupname, string $mode): void`
- `deleteUserRadiusState(PDO $pdo, string $username, bool $deleteAccounting = false): void`

## 2.6 Fonctions de service metier

- `createUser(PDO $pdo, array $payload): void`
- `updateUser(PDO $pdo, int $id, array $payload): void`
- `deleteUser(PDO $pdo, int $id): void`
- `createProfile(PDO $pdo, array $payload, int $nasId): void`

## 3. Code Duplique Le Plus Important

## 3.1 `create_user.php` et `update_user.php`

Blocs presque identiques :

- parsing des entrees
- validation des numeriques
- chargement du profil
- construction des attributs RADIUS
- ecriture dans `radreply`

Refactor prioritaire :

- extraire un `UserService`
- extraire un `UserRadiusSynchronizer`

## 3.2 Helpers HTTP

Les fonctions `post_string_or_null()` et `post_int_or_default()` sont repeteees.

Refactor prioritaire :

- extraire un helper commun

## 3.3 Gestion transactionnelle

Create, update et delete utilisateur suivent tous le meme modele transactionnel.

Refactor prioritaire :

- encapsuler la transaction

## 4. Plan De Centralisation

## Etape 1. Extraire les helpers generiques

Créer des fichiers de support sans toucher a la logique metier :

- `includes/request_helpers.php`
- `includes/auth_guard.php`
- `includes/db_transaction.php`

Objectif :

- retirer la duplication la plus simple et la plus sure

## Etape 2. Extraire les repositories SQL

Creer :

- `includes/user_repository.php`
- `includes/profile_repository.php`
- `includes/nas_repository.php`

Objectif :

- sortir les requetes SQL des endpoints

## Etape 3. Extraire la synchronisation RADIUS utilisateur

Creer :

- `includes/user_radius_sync.php`

Objectif :

- centraliser :
  - `radcheck`
  - `radusergroup`
  - `radreply`
  - cleanup RADIUS

## Etape 4. Creer une couche service

Creer :

- `includes/user_service.php`
- `includes/profile_service.php`

Objectif :

- exposer des fonctions metier communes :
  - `createUser()`
  - `updateUser()`
  - `deleteUser()`
  - `createProfile()`

## Etape 5. Transformer les endpoints en controleurs minces

Objectif :

- chaque endpoint :
  - verifie l'acces
  - lit la requete
  - appelle le service
  - renvoie le JSON

Au lieu de :

- porter lui-meme la logique SQL
- porter lui-meme la logique RADIUS

## 5. Structure Cible Proposee

```text
api/users/create_user.php
api/users/update_user.php
api/users/delete_user.php
api/profiles/create_profile.php
    ->
UserService / ProfileService
    ->
Repositories SQL + Synchronizers RADIUS
    ->
DB / radcheck / radreply / radusergroup / radgroupreply
```

## 6. Ordre De Priorite Recommande

1. Helpers HTTP et auth
2. Synchronisation RADIUS utilisateur
3. UserService create/update/delete
4. ProfileService
5. Nettoyage final des endpoints

## 7. Benefices Attendus

- moins de duplication
- moins de divergence entre create et update
- validation uniforme des entrees
- meilleure testabilite
- evolution plus sure du mapping RADIUS
- reduction des regressions lors du refactoring

## Conclusion

La duplication la plus importante se situe entre [api/users/create_user.php](/var/www/html/api/users/create_user.php) et [api/users/update_user.php](/var/www/html/api/users/update_user.php), avec une forte repetition autour du parsing d'entree, de la resolution du profil et du provisionnement RADIUS. La gestion de profil est deja partiellement centralisee via [includes/radius_sync.php](/var/www/html/includes/radius_sync.php), mais reste incomplete. La refactorisation la plus utile consiste a introduire des fonctions communes comme `createUser`, `updateUser`, `deleteUser`, `createProfile`, ainsi qu'un module de synchronisation RADIUS utilisateur partage.
