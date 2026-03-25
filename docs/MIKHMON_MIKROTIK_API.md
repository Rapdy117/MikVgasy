# Référence API MikroTik Basée Sur `mikhmon`

## Objet

Ce document décrit les opérations MikroTik observées dans [docs/mikhmon](/var/www/html/docs/mikhmon/index.php), afin de fournir une base technique exploitable pour une future couche API MikroTik côté projet.

Le mot “API” désigne ici l’API RouterOS appelée via :

- [docs/mikhmon/lib/routeros_api.class.php](/var/www/html/docs/mikhmon/lib/routeros_api.class.php)

## 1. Base Technique

### Client RouterOS

Le client utilisé par `mikhmon` est la classe :

- [docs/mikhmon/lib/routeros_api.class.php](/var/www/html/docs/mikhmon/lib/routeros_api.class.php)

Méthodes structurantes :

- `connect($ip, $login, $password)`
- `write($command, $param2)`
- `read($parse)`
- `comm($command, array $params = [])`
- `parseResponse($response)`

### Paramètres de connexion

La configuration Mikhmon est stockée dans :

- [docs/mikhmon/include/config.php](/var/www/html/docs/mikhmon/include/config.php)

Exemple observé :

- nom logique de session
- hôte MikroTik
- login
- mot de passe chiffré

## 2. Convention D’Appel

Dans `mikhmon`, les opérations suivent ce modèle :

```php
$API->comm("/chemin/routeros/print");
$API->comm("/chemin/routeros/print", [
    "?champ" => "valeur",
    "count-only" => "",
]);
$API->comm("/chemin/routeros/add", [
    "champ" => "valeur",
]);
$API->comm("/chemin/routeros/set", [
    ".id" => $id,
    "champ" => "valeur",
]);
$API->comm("/chemin/routeros/remove", [
    ".id" => $id,
]);
```

Conventions observées :

- `print` : lecture
- `count-only` : renvoie un compteur
- `?champ` : filtre
- `.id` : identifiant RouterOS interne pour `set` / `remove`

## 3. Endpoints Hotspot Utilisateur

### 3.1 Lister les utilisateurs hotspot

Source :

- [docs/mikhmon/hotspot/users.php](/var/www/html/docs/mikhmon/hotspot/users.php)

Endpoint RouterOS :

- `/ip/hotspot/user/print`

Paramètres observés :

- aucun
- `count-only`
- `?profile`
- `?comment`
- `?limit-uptime`
- `?name`
- `?.id`

Usages observés :

- liste globale des comptes hotspot
- filtrage par profil
- filtrage par commentaire
- filtrage des comptes expirés
- récupération d’un utilisateur précis

Exemple :

```php
$API->comm("/ip/hotspot/user/print", [
    "?profile" => "default",
]);
```

### 3.2 Créer un utilisateur hotspot

Source :

- [docs/mikhmon/hotspot/adduser.php](/var/www/html/docs/mikhmon/hotspot/adduser.php)

Endpoint RouterOS :

- `/ip/hotspot/user/add`

Paramètres observés :

- `server`
- `name`
- `password`
- `profile`
- `disabled`
- `limit-uptime`
- `limit-bytes-total`
- `comment`

Usage :

- création d’un compte hotspot avec profil, quota temps, quota data et commentaire

Exemple :

```php
$API->comm("/ip/hotspot/user/add", [
    "server" => "all",
    "name" => "user01",
    "password" => "user01",
    "profile" => "default",
    "disabled" => "no",
    "limit-uptime" => "1h",
    "limit-bytes-total" => "104857600",
    "comment" => "vc-ticket",
]);
```

### 3.3 Activer un utilisateur hotspot

Source :

- [docs/mikhmon/process/enablehotspotuser.php](/var/www/html/docs/mikhmon/process/enablehotspotuser.php)

Endpoint RouterOS :

- `/ip/hotspot/user/set`

Paramètres observés :

- `.id`
- `disabled = no`

Usage :

- réactiver un compte existant

### 3.4 Désactiver un utilisateur hotspot

Source :

- [docs/mikhmon/process/disablehotspotuser.php](/var/www/html/docs/mikhmon/process/disablehotspotuser.php)

Endpoint RouterOS :

- `/ip/hotspot/user/set`

Paramètres observés :

- `.id`
- `disabled = yes`

Usage :

- bloquer temporairement un compte hotspot

### 3.5 Supprimer un utilisateur hotspot

Source :

- [docs/mikhmon/process/removehotspotuser.php](/var/www/html/docs/mikhmon/process/removehotspotuser.php)

Endpoints RouterOS :

- `/ip/hotspot/user/print`
- `/system/script/print`
- `/system/scheduler/print`
- `/system/script/remove`
- `/system/scheduler/remove`
- `/ip/hotspot/user/remove`

Paramètres observés :

- `?.id`
- `?name`
- `.id`

Usage :

- supprimer un utilisateur
- supprimer aussi les scripts et schedulers associés portant le même nom

Point important :

- la suppression utilisateur n’est pas isolée ; elle peut entraîner une suppression de tâches système liées au compte

## 4. Endpoints Hotspot Session / Host / Binding

### 4.1 Sessions actives hotspot

Source :

- [docs/mikhmon/hotspot/hotspotactive.php](/var/www/html/docs/mikhmon/hotspot/hotspotactive.php)

Endpoint RouterOS :

- `/ip/hotspot/active/print`

Paramètres observés :

- aucun
- `?server`
- `count-only`

Usage :

- lister les sessions actives
- filtrer par serveur hotspot

Champs exploités dans l’UI :

- `.id`
- `server`
- `user`
- `address`
- `mac-address`
- `uptime`
- `session-time-left`
- `bytes-in`
- `bytes-out`
- `login-by`
- `comment`

### 4.2 Hosts hotspot

Source :

- [docs/mikhmon/hotspot/hosts.php](/var/www/html/docs/mikhmon/hotspot/hosts.php)

Endpoint RouterOS :

- `/ip/hotspot/host/print`

Paramètres observés :

- aucun
- `?bypassed = yes`
- `?authorized = yes`
- `count-only`

Usage :

- lister tous les hosts
- lister uniquement les bypass
- lister uniquement les hosts autorisés

Champs exploités :

- `.id`
- `mac-address`
- `address`
- `to-address`
- `server`
- `comment`
- `authorized`
- `dynamic`
- `DHCP`
- `bypassed`

### 4.3 IP bindings hotspot

Source :

- [docs/mikhmon/hotspot/ipbinding.php](/var/www/html/docs/mikhmon/hotspot/ipbinding.php)

Endpoint RouterOS :

- `/ip/hotspot/ip-binding/print`

Paramètres observés :

- aucun
- `count-only`

Usage :

- lister les IP bindings

Champs exploités :

- `.id`
- `mac-address`
- `address`
- `to-address`
- `server`
- `comment`
- `disabled`
- `bypassed`

Opérations dérivées visibles dans l’UI :

- suppression binding
- enable / disable binding

## 5. Endpoints Système

### 5.1 Scheduler

Source :

- [docs/mikhmon/system/scheduler.php](/var/www/html/docs/mikhmon/system/scheduler.php)

Endpoint RouterOS :

- `/system/scheduler/print`

Paramètres observés :

- aucun
- `count-only`

Usage :

- lister les tâches planifiées

Champs exploités :

- `.id`
- `name`
- `start-date`
- `start-time`
- `interval`
- `next-run`
- `run-count`
- `comment`
- `disabled`

### 5.2 Reboot

Source :

- [docs/mikhmon/process/reboot.php](/var/www/html/docs/mikhmon/process/reboot.php)

Endpoint RouterOS :

- `/system/reboot`

Usage :

- redémarrer le routeur MikroTik

Particularité :

- le code utilise `write('/system/reboot')` puis `read()`, pas `comm()`

## 6. Paramètres Métier Réutilisables Côté Projet

À partir des scripts `mikhmon`, les paramètres les plus réutilisables pour une future API projet sont :

- `host`
- `username`
- `password`
- `server`
- `profile`
- `name`
- `comment`
- `limit-uptime`
- `limit-bytes-total`
- `.id`

## 7. Mapping Avec Le Projet Actuel

### Cohérent avec le projet actif

- la notion de type `mikrotik` existe déjà dans :
  - [includes/nas_resolver.php](/var/www/html/includes/nas_resolver.php)
  - [includes/radius_sync.php](/var/www/html/includes/radius_sync.php)
- la page devices gère déjà :
  - `opnsense`
  - `mikrotik`
  - `other`

### Non encore implémenté dans le projet actif

Le projet actif ne pilote pas encore MikroTik via l’API RouterOS pour :

- lister les utilisateurs hotspot MikroTik
- créer / désactiver / supprimer ces utilisateurs
- lister les sessions actives
- piloter les hosts, bindings ou schedulers

## 8. Recommandation D’Implémentation

Pour une future couche API MikroTik du projet :

### Endpoints projet prioritaires

- `GET /api/mikrotik/users`
- `POST /api/mikrotik/users`
- `PATCH /api/mikrotik/users/{id}`
- `DELETE /api/mikrotik/users/{id}`
- `GET /api/mikrotik/active`
- `GET /api/mikrotik/hosts`
- `GET /api/mikrotik/ip-bindings`
- `POST /api/mikrotik/system/reboot`

### Paramètres projet recommandés

- `device_id`
- `server`
- `username`
- `password`
- `profile`
- `comment`
- `session_timeout`
- `data_limit`

### Traduction RouterOS recommandée

- `session_timeout` -> `limit-uptime`
- `data_limit` -> `limit-bytes-total`
- `user id projet` -> `.id` RouterOS ou `name` selon le cas

## 9. Limites De La Référence

Cette documentation est construite à partir du code observé dans `docs/mikhmon/`.

Elle décrit :

- les endpoints RouterOS effectivement appelés
- les paramètres effectivement vus dans les scripts

Elle ne garantit pas :

- l’exhaustivité de toute l’API RouterOS MikroTik
- l’équivalence parfaite avec une future implémentation projet

Son rôle est de servir de base locale, cohérente et exploitable pour la documentation et l’intégration future.
