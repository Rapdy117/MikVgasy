# Architecture Du Projet

## Vue D'Ensemble

L'application est une interface web PHP de gestion hotspot qui s'appuie sur :

- une interface web rendue cote serveur dans `pages/`
- des scripts JavaScript pour les interactions asynchrones dans `js/`
- des endpoints PHP dans `api/`
- une base MySQL pour les donnees applicatives et les tables FreeRADIUS
- des fichiers JSON de configuration locale pour OPNsense et FreeRADIUS

Le flux architectural principal est :

`Pages PHP -> JavaScript -> API PHP -> Base SQL / JSON / API externes`

Les integrations externes reelles observees dans le code sont :

- FreeRADIUS
- OPNsense

## Organisation Backend / Frontend

### Frontend

Le frontend est majoritairement un rendu PHP serveur.

Les ecrans se trouvent dans `pages/` :

- [pages/dashboard.php](/var/www/html/pages/dashboard.php)
- [pages/users_list.php](/var/www/html/pages/users_list.php)
- [pages/add_hotspot_user.php](/var/www/html/pages/add_hotspot_user.php)
- [pages/add_profile.php](/var/www/html/pages/add_profile.php)
- [pages/profile_list.php](/var/www/html/pages/profile_list.php)
- [pages/network_devices.php](/var/www/html/pages/network_devices.php)
- [pages/freeradius.php](/var/www/html/pages/freeradius.php)
- [pages/sessions_list.php](/var/www/html/pages/sessions_list.php)

Chaque page :

- demarre generalement une session PHP
- verifie `$_SESSION['logged_in']`
- inclut la navigation commune
- charge ses styles et ses scripts frontend

### Backend

Le backend est distribue entre :

- les endpoints API sous `api/`
- les includes reutilisables sous `includes/`
- la configuration sous `config/`

Endpoints backend structurants :

- [api/users/create_user.php](/var/www/html/api/users/create_user.php)
- [api/users/update_user.php](/var/www/html/api/users/update_user.php)
- [api/users/delete_user.php](/var/www/html/api/users/delete_user.php)
- [api/users/get_user_sessions.php](/var/www/html/api/users/get_user_sessions.php)
- [api/profiles/create_profile.php](/var/www/html/api/profiles/create_profile.php)
- [api/nas.php](/var/www/html/api/nas.php)
- [api/network_devices_api.php](/var/www/html/api/network_devices_api.php)
- [api/test_opnsense.php](/var/www/html/api/test_opnsense.php)
- [api/test_radius.php](/var/www/html/api/test_radius.php)
- [api/disconnect_session.php](/var/www/html/api/disconnect_session.php)
- [api/get_sessions.php](/var/www/html/api/get_sessions.php)
- [api/get_stats.php](/var/www/html/api/get_stats.php)

Composants backend partages :

- [includes/sidebar.php](/var/www/html/includes/sidebar.php)
- [includes/message.php](/var/www/html/includes/message.php)
- [includes/radius_sync.php](/var/www/html/includes/radius_sync.php)
- [config/db.php](/var/www/html/config/db.php)
- [config/config.php](/var/www/html/config/config.php)
- [config/radius.php](/var/www/html/config/radius.php)

## Modules Principaux

### 1. Authentification

Composants :

- [index.php](/var/www/html/index.php)
- [api_proxy.php](/var/www/html/api_proxy.php)

Fonctionnement :

- le formulaire de connexion est rendu dans `index.php`
- il envoie ses donnees a `api_proxy.php`
- `api_proxy.php` valide les identifiants contre des valeurs hardcodees
- si succes, la session PHP est initialisee avec `logged_in` et `username`

Observation :

- le module d'authentification n'est pas connecte a la base de donnees
- il n'utilise pas FreeRADIUS pour les administrateurs
- la redirection de succes pointe vers `dashboard/dashboard.php`, alors que le tableau de bord reel est dans `pages/dashboard.php`

### 2. Gestion des utilisateurs

Composants :

- [pages/add_hotspot_user.php](/var/www/html/pages/add_hotspot_user.php)
- [pages/users_list.php](/var/www/html/pages/users_list.php)
- [js/add_hotspot_user.js](/var/www/html/js/add_hotspot_user.js)
- [js/users_list.js](/var/www/html/js/users_list.js)
- [api/users/create_user.php](/var/www/html/api/users/create_user.php)
- [api/users/update_user.php](/var/www/html/api/users/update_user.php)
- [api/users/delete_user.php](/var/www/html/api/users/delete_user.php)
- [api/users/get_user_sessions.php](/var/www/html/api/users/get_user_sessions.php)

Structure :

- la table `users` sert de stockage applicatif principal
- la table `profiles` est referencee via `profile_id`
- les tables `radcheck`, `radreply`, `radusergroup` portent les attributs RADIUS
- la table `radacct` porte les sessions consommees

Observation :

- l'ecran d'ajout utilisateur existe
- le script frontend cible un endpoint inexistant : `../api/create_user.php`
- l'edition depuis la liste utilisateur n'est pas branchee a `update_user.php`

### 3. Gestion des profils

Composants :

- [pages/add_profile.php](/var/www/html/pages/add_profile.php)
- [pages/profile_list.php](/var/www/html/pages/profile_list.php)
- [js/select_nas.js](/var/www/html/js/select_nas.js)
- [api/profiles/create_profile.php](/var/www/html/api/profiles/create_profile.php)
- [includes/radius_sync.php](/var/www/html/includes/radius_sync.php)

Structure :

- le formulaire capture les limites de profil
- le backend insere dans `profiles`
- le backend derive les attributs RADIUS de groupe dans `radgroupreply`
- le type de NAS conditionne le format des limitations de debit

Observation :

- `profile_list.php` n'est pas branche a la base et reste statique

### 4. FreeRADIUS

Composants :

- [pages/freeradius.php](/var/www/html/pages/freeradius.php)
- [js/freeradius.js](/var/www/html/js/freeradius.js)
- [config/radius.php](/var/www/html/config/radius.php)
- [config/radius.json](/var/www/html/config/radius.json)
- [api/test_radius.php](/var/www/html/api/test_radius.php)

Structure :

- la configuration est stockee en JSON
- le test se fait via `radclient`
- la logique de provisionnement des profils et utilisateurs ecrit directement dans les tables FreeRADIUS

Observation :

- il y a coexistence entre configuration fichier et configuration base
- `api/test_radius.php` contient des incoherences internes

### 5. OPNsense / equipements reseau

Composants :

- [pages/network_devices.php](/var/www/html/pages/network_devices.php)
- [js/network_device.js](/var/www/html/js/network_device.js)
- [api/network_devices_api.php](/var/www/html/api/network_devices_api.php)
- [api/test_opnsense.php](/var/www/html/api/test_opnsense.php)
- [api/disconnect_session.php](/var/www/html/api/disconnect_session.php)
- [config/opnsense.json](/var/www/html/config/opnsense.json)
- [config/config.php](/var/www/html/config/config.php)

Structure :

- les equipements OPNsense sont enregistres dans un JSON local
- les tests de connectivite sont faits par cURL
- certaines actions reseau comme la deconnexion de session passent par l'API OPNsense

Observation :

- il y a deux strategies de configuration OPNsense dans le projet :
  - via `config/config.php` pour les constantes globales
  - via `config/opnsense.json` pour les devices enregistres

### 6. Sessions et suivi

Composants :

- [pages/users_list.php](/var/www/html/pages/users_list.php)
- [pages/sessions_list.php](/var/www/html/pages/sessions_list.php)
- [api/users/get_user_sessions.php](/var/www/html/api/users/get_user_sessions.php)
- [api/get_sessions.php](/var/www/html/api/get_sessions.php)
- [api/disconnect_session.php](/var/www/html/api/disconnect_session.php)

Structure :

- les sessions historiques viennent de `radacct`
- la page `users_list.php` exploite cet historique
- `sessions_list.php` reste aujourd'hui essentiellement statique

## Dependances Entre Composants

### Dependances transverses

- presque toutes les pages dependent de [includes/sidebar.php](/var/www/html/includes/sidebar.php)
- plusieurs pages de dashboard et systeme dependent de [includes/message.php](/var/www/html/includes/message.php)
- toute la couche SQL depend de [config/db.php](/var/www/html/config/db.php)

### Dependances utilisateur

- `users` depend de `profiles` via `profile_id`
- la creation utilisateur depend d'un profil existant pour alimenter `radusergroup`
- l'affichage des sessions depend de `radacct`

### Dependances profil

- un profil depend du `nas` choisi pour determiner le type de limitation a generer
- [includes/radius_sync.php](/var/www/html/includes/radius_sync.php) depend de la structure attendue de `radgroupreply`

### Dependances NAS / OPNsense

- [api/nas.php](/var/www/html/api/nas.php) depend de la table `nas`
- [api/network_devices_api.php](/var/www/html/api/network_devices_api.php) depend du JSON `config/opnsense.json`
- [api/test_opnsense.php](/var/www/html/api/test_opnsense.php) depend des credentials fournis par formulaire

## Couches De Stockage

### Base MySQL

Tables FreeRADIUS observees dans le code :

- `radacct`
- `radcheck`
- `radreply`
- `radgroupreply`
- `radusergroup`
- `nas`

Tables applicatives attendues par le code :

- `users`
- `profiles`

Observation critique :

- [config/schema.sql](/var/www/html/config/schema.sql) couvre principalement FreeRADIUS
- les definitions SQL de `users` et `profiles` ne sont pas fournies dans le projet

### Fichiers JSON

- [config/opnsense.json](/var/www/html/config/opnsense.json) : liste d'equipements OPNsense
- [config/radius.json](/var/www/html/config/radius.json) : configuration FreeRADIUS de test
- [config/db.json](/var/www/html/config/db.json) : present mais non central dans les flux principaux observes

## Contraintes Architecturales Observees

- architecture monolithique PHP sans couche service formelle
- front et back fortement couples par les noms de champs HTML
- mapping direct entre modele interne et tables FreeRADIUS
- presence de traces historiques MikroTik / Mikhmon dans une architecture maintenant orientee OPNsense + FreeRADIUS
- coexistence de donnees en SQL et en JSON local

## Resume

Le projet est structure comme une application PHP monolithique avec rendu serveur, enrichie par des appels AJAX ciblant des endpoints internes. Le coeur metier repose sur la synchronisation entre objets applicatifs (`users`, `profiles`) et les tables FreeRADIUS, avec une integration OPNsense parallele pour la partie reseau. L'architecture est lisible, mais heterogene et partiellement inachevee, ce qui impose une forte prudence lors des modifications.
