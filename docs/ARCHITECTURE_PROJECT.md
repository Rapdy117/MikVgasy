# Architecture Du Projet

## Vue D'Ensemble

L'application est une interface web PHP pour administrer un environnement Hotspot multi-backend base sur FreeRADIUS et des equipements reseau de type `opnsense`, `mikrotik` et `radius`. Elle combine :

- un frontend PHP rendu serveur dans `pages/`
- des scripts JavaScript dans `js/` pour les appels AJAX
- des endpoints backend dans `api/`
- une base MySQL pour les donnees applicatives et FreeRADIUS
- des fichiers JSON pour certaines configurations locales

Le projet suit une architecture simple de type :

`UI PHP -> JS frontend -> API PHP -> DB / fichiers JSON / API externes`

Regle directrice retenue :

- `nas.type` = source de verite metier
- `device.type` = source d'execution technique

Ce que cela implique :

- les actions metier utilisateur, profil, session et provisioning partent de `nas_id`
- `nas_id` permet de charger `nas.type`, puis de resoudre le backend logique
- si ce backend a besoin d'un equipement physique ou d'une API, l'execution passe ensuite par le `device` associe
- le `device` actif en session ne doit pas choisir a lui seul le backend metier

## Couches Principales

### 1. Couche presentation

Les pages de `pages/` affichent les ecrans de l'application :

- dashboard
- gestion des utilisateurs
- creation de profils
- configuration FreeRADIUS
- configuration des equipements reseau
- consultation des sessions

La navigation commune est centralisee dans [includes/sidebar.php](/var/www/html/includes/sidebar.php).

La plupart des pages :

- demarrent une session PHP
- verifient `$_SESSION['logged_in']`
- incluent la sidebar
- chargent Bootstrap, Font Awesome et un ou plusieurs scripts JS

### 2. Couche logique frontend

Les scripts dans `js/` gerent :

- la soumission des formulaires
- le chargement dynamique de donnees
- le remplissage des tableaux et panneaux de details
- les appels aux API PHP

Scripts structurants :

- [js/users_list.js](/var/www/html/js/users_list.js) : affichage detail utilisateur + chargement des sessions
- [js/add_hotspot_user.js](/var/www/html/js/add_hotspot_user.js) : soumission du formulaire utilisateur
- [js/select_nas.js](/var/www/html/js/select_nas.js) : chargement des NAS dans le formulaire profil
- [js/network_device.js](/var/www/html/js/network_device.js) : CRUD des equipements OPNsense
- [js/freeradius.js](/var/www/html/js/freeradius.js) : sauvegarde et test de la configuration RADIUS
- [js/dashboard.js](/var/www/html/js/dashboard.js) : rafraichissement du dashboard oriente hotspot/commercial, avec chargement principal via [api/get_stats.php](/var/www/html/api/get_stats.php), trafic live via stream OPNsense et jauge concentrique CPU/RAM

### 3. Couche backend applicative

Les fichiers `api/` servent de controleurs backend.

Ils recoivent les requetes HTTP, lisent les entrees `GET` ou `POST`, appellent la base ou les fichiers de config, puis renvoient du JSON ou effectuent des redirections.

Sous-domaines principaux :

- `api/users/` : gestion des utilisateurs
- `api/profiles/` : creation des profils
- `api/` racine : NAS, sessions, tests OPNsense, deconnexion, statistiques

### 4. Couche acces aux donnees

Deux stockages sont utilises :

- MySQL via [config/db.php](/var/www/html/config/db.php)
- fichiers JSON via [config/opnsense.json](/var/www/html/config/opnsense.json) et [config/radius.json](/var/www/html/config/radius.json)

La base contient au minimum :

- tables FreeRADIUS standard : `radacct`, `radcheck`, `radreply`, `radgroupreply`, `radusergroup`, `nas`
- tables applicatives attendues par le code : `users`, `profiles`

Important : le schema fourni dans [config/schema.sql](/var/www/html/config/schema.sql) couvre surtout FreeRADIUS et ne decrit pas `users` ni `profiles`.

### 5. Couche integration externe

Le projet dialogue avec trois branches techniques externes :

- FreeRADIUS
- OPNsense
- MikroTik / Mikhmon

Nouvelle directive documentaire :

- la gestion des devices doit maintenant etre bornee a trois types :
  - `opnsense`
  - `mikrotik`
  - `radius`
- seuls `opnsense` et `mikrotik` sont des devices API de management
- `radius` designe un NAS standard sans dashboard ni pilotage live projet

FreeRADIUS est utilise via :

- ecriture directe en base SQL (`radcheck`, `radreply`, `radusergroup`, `radgroupreply`)
- test de connexion avec `radclient` dans [api/test_radius.php](/var/www/html/api/test_radius.php)

Portee documentaire retenue :

- `radius` designe la branche standard basee sur FreeRADIUS
- cette branche ne doit pas etre confondue avec la branche `mikrotik`
- un device `mikrotik` ne doit pas etre gere comme un simple NAS RADIUS si la communication FreeRADIUS est instable

OPNsense est utilise via :

- appels cURL vers l'API REST
- configuration stockee localement dans `config/opnsense.json`
- test d'etat et de connectivite depuis [api/test_device.php](/var/www/html/api/test_device.php)
- metriques trafic du dashboard via [api/get_traffic_stats.php](/var/www/html/api/get_traffic_stats.php) puis stream live via [api/traffic_stream.php](/var/www/html/api/traffic_stream.php)
- metriques CPU live du dashboard via [api/cpu_stream.php](/var/www/html/api/cpu_stream.php)
- type CPU via [api/get_cpu_type.php](/var/www/html/api/get_cpu_type.php)

MikroTik est utilise via :

- une branche API de management distincte
- la reference fonctionnelle locale [docs/mikhmon](/var/www/html/docs/mikhmon/index.php)
- une logique de gestion hotspot locale au routeur, au lieu d'un provisioning via les tables FreeRADIUS

Regle de travail retenue pour la suite :

- pour les ecrans et APIs MikroTik, on se base d'abord sur le comportement deja present dans `docs/mikhmon`
- on reprend autant que possible le format et les workflows existants de Mikhmon
- la source de verite des donnees reste le routeur MikroTik interroge via RouterOS API
- Mikhmon sert donc de reference de presentation et de logique, pas de substitut a la lecture reelle du routeur

## Modules Fonctionnels

### Authentification

Flux :

- [index.php](/var/www/html/index.php) affiche le formulaire
- POST vers [api_proxy.php](/var/www/html/api_proxy.php)
- creation de session PHP si login valide

Etat actuel :

- authentification locale hardcodee
- pas de base utilisateurs admin
- pas d'integration FreeRADIUS pour les comptes d'administration

### Gestion des utilisateurs

Composants :

- [pages/add_hotspot_user.php](/var/www/html/pages/add_hotspot_user.php)
- [pages/users_list.php](/var/www/html/pages/users_list.php)
- [api/users/create_user.php](/var/www/html/api/users/create_user.php)
- [api/users/update_user.php](/var/www/html/api/users/update_user.php)
- [api/users/delete_user.php](/var/www/html/api/users/delete_user.php)
- [api/users/get_user_sessions.php](/var/www/html/api/users/get_user_sessions.php)

Architecture metier :

- la table `users` stocke les informations applicatives
- la persistance technique depend du backend resolu par `nas_id`
- pour `radius`, les attributs d'authentification et de service sont stockes dans les tables FreeRADIUS
- pour `mikrotik`, la gestion hotspot doit suivre la logique locale MikroTik / Mikhmon
- `radacct` reste une source d'historique pour la branche RADIUS
- le type de `device` n'intervient qu'au moment d'executer un appel technique concret

### Gestion des profils

Composants :

- [pages/add_profile.php](/var/www/html/pages/add_profile.php)
- [pages/profile_list.php](/var/www/html/pages/profile_list.php)
- [api/profiles/create_profile.php](/var/www/html/api/profiles/create_profile.php)
- [includes/radius_sync.php](/var/www/html/includes/radius_sync.php)

Architecture metier :

- un profil applicatif est cree en base
- sa traduction technique depend du backend resolu par `nas_id`
- pour `radius`, il est converti en attributs RADIUS de groupe
- pour `mikrotik`, il doit suivre une branche de gestion locale distincte de FreeRADIUS
- le `device` associe n'est pas la source de verite du backend, seulement son support d'execution si necessaire

### Gestion des NAS et equipements reseau

La cible documentaire retenue est maintenant la suivante :

- `opnsense` : device gere via API
- `mikrotik` : device gere via API
- `radius` : NAS standard sans API projet

Contrainte operationnelle retenue :

- a la suite des tests terrain, les devices `mikrotik` ne doivent plus etre consideres comme une simple branche FreeRADIUS
- leur gestion hotspot doit s'appuyer sur leur logique locale / Mikhmon
- la base FreeRADIUS reste reservee a la branche `radius`

Dans l'etat actuel du code, deux blocs coexistent encore :

- NAS FreeRADIUS en base via table `nas`
- equipements OPNsense en JSON via `config/opnsense.json`

Composants :

- [api/nas.php](/var/www/html/api/nas.php)
- [pages/network_devices.php](/var/www/html/pages/network_devices.php)
- [api/network_devices_api.php](/var/www/html/api/network_devices_api.php)
- [api/test_device.php](/var/www/html/api/test_device.php)

Direction de convergence :

- `pages/network_devices.php` doit devenir le point d'entree de configuration des trois types de devices
- les champs affiches, les tests proposes et les pages accessibles doivent dependre du type de device
- le dashboard doit etre reserve aux devices qui exposent reellement des metriques live, donc pas au `radius` standard
- cette couche `device` ne doit pas porter la decision metier principale, qui reste sur `nas.type`

### Sessions et suivi

Composants :

- [pages/users_list.php](/var/www/html/pages/users_list.php)
- [api/users/get_user_sessions.php](/var/www/html/api/users/get_user_sessions.php)
- [api/get_sessions.php](/var/www/html/api/get_sessions.php)
- [api/disconnect_session.php](/var/www/html/api/disconnect_session.php)

Source des sessions :

- table `radacct` pour l'historique RADIUS
- API OPNsense pour certaines actions de deconnexion
- logique locale MikroTik / Mikhmon pour la branche `mikrotik` lorsqu'une vue session depend du routeur

### Dashboard hotspot/commercial

Le dashboard courant n'est plus un simple panneau systeme.

Il est maintenant organise autour de :

- un bloc `Hotspot` avec KPI et raccourcis
- un bloc `Bande Passante Live` avec `Download` et `Upload`
- un bloc `Bilan` pour les indicateurs commerciaux du mois courant
- un bloc `OPNsense` pour l'etat du firewall et la jauge concentrique `CPU/RAM`
- un tableau `Derniers Evenements` centre sur les sessions utilisateur OPNsense

Flux techniques utilises par le dashboard :

- [api/get_stats.php](/var/www/html/api/get_stats.php) : charge les KPI hotspot, le bilan commercial, l'etat OPNsense et les evenements recents
- [api/get_traffic_stats.php](/var/www/html/api/get_traffic_stats.php) : initialise le widget trafic
- [api/traffic_stream.php](/var/www/html/api/traffic_stream.php) : alimente les courbes trafic en live
- [api/cpu_stream.php](/var/www/html/api/cpu_stream.php) : alimente la jauge CPU
- [api/get_cpu_type.php](/var/www/html/api/get_cpu_type.php) : renseigne le type de CPU OPNsense

Limites connues du dashboard :

- le bloc `Derniers Evenements` affiche surtout les utilisateurs actuellement vus par OPNsense
- le `Bilan` repose encore sur des compteurs de vouchers utilises, pas sur un revenu monetise
- la qualite du chargement depend toujours fortement de la reactivite OPNsense

## Flux Architecturaux Principaux

### Flux 1 : connexion administrateur

1. L'utilisateur ouvre [index.php](/var/www/html/index.php)
2. Le formulaire envoie login/mot de passe a [api_proxy.php](/var/www/html/api_proxy.php)
3. `api_proxy.php` cree la session PHP
4. Les pages internes verifient `$_SESSION['logged_in']`

### Flux 2 : creation de profil

1. [pages/add_profile.php](/var/www/html/pages/add_profile.php) affiche le formulaire
2. [js/select_nas.js](/var/www/html/js/select_nas.js) charge les NAS via [api/nas.php](/var/www/html/api/nas.php)
3. Le formulaire poste vers [api/profiles/create_profile.php](/var/www/html/api/profiles/create_profile.php)
4. Le backend insere le profil dans `profiles`
5. [includes/radius_sync.php](/var/www/html/includes/radius_sync.php) alimente `radgroupreply`

### Flux 3 : creation d'utilisateur

1. [pages/add_hotspot_user.php](/var/www/html/pages/add_hotspot_user.php) collecte les donnees
2. [js/add_hotspot_user.js](/var/www/html/js/add_hotspot_user.js) envoie la requete
3. Le backend cible attendu est [api/users/create_user.php](/var/www/html/api/users/create_user.php)
4. Le backend ecrit dans `users`
5. Le backend synchronise `radcheck`, `radusergroup`, `radreply`

### Flux 4 : consultation des sessions utilisateur

1. [pages/users_list.php](/var/www/html/pages/users_list.php) charge les utilisateurs
2. Un clic sur une ligne declenche [js/users_list.js](/var/www/html/js/users_list.js)
3. Le script appelle [api/users/get_user_sessions.php](/var/www/html/api/users/get_user_sessions.php)
4. Le backend lit `radacct`
5. L'UI affiche historique, volume de data, IP, MAC, NAS, statut online

### Flux 5 : configuration OPNsense

1. [pages/network_devices.php](/var/www/html/pages/network_devices.php) affiche le formulaire
2. [js/network_device.js](/var/www/html/js/network_device.js) lit/ecrit via [api/network_devices_api.php](/var/www/html/api/network_devices_api.php)
3. Les donnees sont stockees dans [config/opnsense.json](/var/www/html/config/opnsense.json)
4. Les tests de connectivite passent par [api/test_device.php](/var/www/html/api/test_device.php)

### Flux 6 : configuration FreeRADIUS

1. [pages/freeradius.php](/var/www/html/pages/freeradius.php) affiche la configuration
2. [js/freeradius.js](/var/www/html/js/freeradius.js) sauvegarde via [config/radius.php](/var/www/html/config/radius.php)
3. La configuration est stockee dans [config/radius.json](/var/www/html/config/radius.json)
4. Le test d'authentification passe par [api/test_radius.php](/var/www/html/api/test_radius.php)

## Composants Partages

### Sidebar

[includes/sidebar.php](/var/www/html/includes/sidebar.php) centralise :

- la navigation
- l'affichage du nom utilisateur connecte
- certains liens fonctionnels globaux

Cette sidebar est un point de couplage important : tout changement de routes ou de nommage de page impacte une grande partie de l'application.

### Messages de session

[includes/message.php](/var/www/html/includes/message.php) fournit :

- `set_message()`
- `display_message()`

Ce composant est utilise pour afficher des messages temporaires apres redirection.

### Synchronisation RADIUS

[includes/radius_sync.php](/var/www/html/includes/radius_sync.php) centralise :

- l'insertion d'attributs RADIUS de groupe
- la conversion de debit
- la logique differenciee selon type de NAS

C'est le point central pour les profils.

## Organisation Des Dossiers

- `pages/` : ecrans utilisateur
- `api/` : endpoints backend
- `api/users/` : sous-module utilisateurs
- `api/profiles/` : sous-module profils
- `includes/` : composants PHP reutilisables
- `config/` : connexion DB, secrets, JSON de configuration, schema SQL
- `js/` : scripts frontend
- `css/` : styles metier
- `assets/` : images et ressources statiques
- `docs/` : documentation projet

## Faiblesses Architecturales Observees

- Architecture hybride inachevee : une partie est en rendu serveur PHP, une autre en AJAX, mais les parcours ne sont pas tous relies jusqu'au bout.
- Couplage fort entre logique applicative et schema FreeRADIUS.
- Incoherence entre schema livre et tables reellement attendues.
- Presence de restes historiques MikroTik/Mikhmon dans une architecture maintenant orientee OPNsense.
- Une partie de la configuration est en base, une autre en JSON local, ce qui complique la coherence globale.
- Certaines routes et certains chemins frontend/backend sont incoherents ou casses.

## Resume

L'architecture repose sur un noyau PHP monolithique simple, organise par pages et endpoints, avec une forte dependance a FreeRADIUS pour la logique d'acces et a OPNsense pour la connectivite reseau. Le projet est lisible dans son decoupage general, mais il reste partiellement heterogene, avec des traces d'anciennes structures et plusieurs jonctions frontend/backend inachevees.
