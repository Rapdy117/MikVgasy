# Architecture Du Projet

## Perimetre

Cette documentation couvre uniquement le systeme applicatif.

Elements exclus :

- `phpmyadmin/` : symlink externe
- `ARCHIVE/` : contenu archive
- `debug_*.log`
- `*.zip`
- `test_*`

## Vue D'Ensemble

L'application est une interface web PHP de gestion hotspot qui s'appuie sur :

- une interface web rendue cote serveur dans `pages/`
- des scripts JavaScript pour les interactions asynchrones dans `js/`
- des endpoints PHP dans `api/`
- une base MySQL pour les donnees applicatives et les tables FreeRADIUS
- des fichiers JSON de configuration locale pour OPNsense et FreeRADIUS

Le flux architectural principal est :

`Pages PHP -> JavaScript -> API PHP -> Base SQL / JSON / API externes`

Orientation cible a retenir pour les evolutions :

`Pages PHP -> JavaScript -> services metier -> resolution par nas_id -> backend NAS`

Regle d'architecture a retenir :

- `nas.type` = source de verite metier
- `device.type` = source d'execution technique

Concretement :

- toute decision metier doit partir de `nas_id`, puis de `nas.type`
- le choix du backend logique ne doit pas dependre du `device` actif en session
- `device.type` sert ensuite a executer la connexion, le test API, la sonde ou l'appel technique concret

Les integrations externes reelles observees dans le code sont :

- FreeRADIUS
- OPNsense
- MikroTik / Mikhmon

Lecture cible :

- FreeRADIUS = backend standard pour les NAS compatibles RADIUS
- OPNsense = backend API distinct a terme
- MikroTik = backend API / local distinct, non aligne par defaut sur la base FreeRADIUS
- `nas_id` = cle qui determine le backend et les attributs utilisables
- `device` = support technique associe au NAS lorsqu'un appel API ou un monitoring sont necessaires

Modele fonctionnel deja retenu :

- `user` = identite + overrides + etat
- `profile` = offre heritee
- `state` = session / consommation / uptime / IP / MAC

Regle de lecture :

- l'affichage final d'une fiche ou d'un tableau ne doit pas partir d'une seule source
- il doit reconstruire une valeur finale a partir de `user + profile + calcul`

## Etat D'Avancement Au 27 Mars 2026

Estimation documentaire basee sur le code branche a cette date :

- `mikrotik` : 85%
- `opnsense` : 70%
- `radius` : 78%

Lecture de ces pourcentages :

- ils evaluent l'avancement fonctionnel observable dans l'application
- ils ne representent pas un taux de tests automatises
- ils combinent UI, backend metier, flux CRUD principaux et pages de consultation

Detail rapide :

- `mikrotik`
  - dashboard branche
  - `sessions_list.php` branche
  - `users_list.php` branche en lecture, modification simple et suppression
  - `add_hotspot_user.php` branche avec profils charges depuis RouterOS
  - `add_profile.php` branche avec options RouterOS, edition et suppression reelles
  - `hosts.php`, `ip_bindings.php`, `dhcp_leases.php`, `system_log.php` lisent maintenant de vraies donnees RouterOS
  - reste ouvert : edition avancee des users, stabilisation finale des flux locaux SQL
- `opnsense`
  - gestion des devices et tests API branches
  - dashboard/monitoring globalement branche
  - une partie du provisioning metier reste encore rattachee au flux `radius`
  - reste ouvert : vrai backend metier OPNsense autonome
- `radius`
  - creation/mise a jour/suppression utilisateur presentes
  - creation/mise a jour de profils presentes
  - sessions via `radacct`
  - reste ouvert : alignement complet avec le nouveau modele minimal et nettoyage final des flux historiques

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
- conserve volontairement le design existant du projet, meme quand le backend est remplace

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
- [api/test_device.php](/var/www/html/api/test_device.php)
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
- les tables `radcheck`, `radreply`, `radusergroup` portent les attributs RADIUS pour la branche `radius`
- la table `radacct` porte les sessions consommees cote RADIUS

Observation :

- l'ecran [add_hotspot_user.php](/var/www/html/pages/add_hotspot_user.php) est maintenant reduit a un formulaire minimal :
  - serveur
  - nom
  - mot de passe
  - profil
  - `Time Limit`
  - `Data Limit`
- le select `Serveur` affiche directement les devices venant de [config/opnsense.json](/var/www/html/config/opnsense.json) via [api/network_devices_api.php](/var/www/html/api/network_devices_api.php)
- le backend de creation resout ensuite le contexte NAS a partir du device ou du `nas_id`
- quand le serveur selectionne est `mikrotik`, le select `Profil` est charge depuis RouterOS via [api/users/profile_options.php](/var/www/html/api/users/profile_options.php)
- le serveur actif est maintenant selectionne par defaut dans le formulaire

Lecture metier retenue :

- le user porte principalement :
  - `username`
  - `password`
  - `profile`
  - `Time Limit` effectif
  - `Data Limit` effective
  - `Expiration` effective
- le profil complete ensuite la fiche avec les attributs herites

Cas particulier de [pages/users_list.php](/var/www/html/pages/users_list.php) :

- `Modifier` est maintenant recentre sur `Nom` et `Mot de passe`
- `Temps restant` et `Data restante` sont affiches comme valeurs d'etat / de limite
- `Recharger` renvoie vers [pages/user_recharge.php](/var/www/html/pages/user_recharge.php)
- `Supprimer` est maintenant branche selon le backend, y compris cote MikroTik

### 3. Gestion des profils

Composants :

- [pages/add_profile.php](/var/www/html/pages/add_profile.php)
- [pages/profile_list.php](/var/www/html/pages/profile_list.php)
- [js/select_nas.js](/var/www/html/js/select_nas.js)
- [includes/profile_catalog.php](/var/www/html/includes/profile_catalog.php)
- [api/profiles/create_profile.php](/var/www/html/api/profiles/create_profile.php)
- [includes/radius_sync.php](/var/www/html/includes/radius_sync.php)

Structure :

- le formulaire capture les limites de profil
- le backend insere dans `profiles`
- le backend derive les attributs RADIUS de groupe dans `radgroupreply`
- le type de NAS conditionne le format des limitations de debit

Observation :

- [includes/profile_catalog.php](/var/www/html/includes/profile_catalog.php) centralise la lecture des profils par device reel :
  - source directe RouterOS pour `mikrotik`
  - source SQL `profiles` pour `opnsense` / `radius`
- [pages/profile_list.php](/var/www/html/pages/profile_list.php) est branche :
  - source commune de profils par device
  - edition via retour vers [pages/add_profile.php](/var/www/html/pages/add_profile.php)
  - suppression reelle de profil cote MikroTik

### 3.b. Recharge utilisateur

Composants :

- [pages/user_recharge.php](/var/www/html/pages/user_recharge.php)
- [js/user_recharge.js](/var/www/html/js/user_recharge.js)
- [api/users/recharge_options.php](/var/www/html/api/users/recharge_options.php)
- [api/users/recharge_preview.php](/var/www/html/api/users/recharge_preview.php)
- [api/users/apply_recharge.php](/var/www/html/api/users/apply_recharge.php)
- [api/users/recharge_history.php](/var/www/html/api/users/recharge_history.php)
- [includes/recharge_preview_service.php](/var/www/html/includes/recharge_preview_service.php) (`buildRechargePreview`)
- [includes/RechargeService.php](/var/www/html/includes/RechargeService.php) (`simulate`)
- [includes/recharge_history_store.php](/var/www/html/includes/recharge_history_store.php) (table / insert historique recharge)

Structure :

- la page garde le design standard du projet
- elle prepare une recharge a partir de :
  - serveur
  - utilisateur
  - mode
  - profil actuel / nouveau profil
- elle affiche un apercu avant application
- elle maintient un historique local de recharge

Observation :

- `mikrotik` : application reelle sur le routeur (API) + traces locales
- peripherique **non MikroTik** pilote par la base (profils / utilisateurs / NAS) : **apercu et application** via le meme pipeline (`recharge_preview` / `apply_recharge`, branche transactionnelle RADIUS-like)
- la trace durable recharge est locale SQL ; MikroTik conserve en plus une trace courte RouterOS compatible Mikhmon

Lecture metier retenue :

- le profil porte principalement :
  - `Rate Limit`
  - `Shared Users`
  - `Validity`
  - `Expired Mode`
  - `Address Pool`
  - `Parent Queue`
  - `Price`
  - `Selling Price`
- pour `mikrotik`, ces regles sont traduites via `on-login` et scheduler

### 3.b. Recharge utilisateur (suite — modes et detail)

Composants (meme liste que ci-dessus, plus includes) :

- [includes/recharge_preview_service.php](/var/www/html/includes/recharge_preview_service.php), [includes/RechargeService.php](/var/www/html/includes/RechargeService.php), [includes/recharge_history_store.php](/var/www/html/includes/recharge_history_store.php)

Lecture metier retenue :

- `Remplacer l offre`
  - applique le profil choisi
  - repart de ses valeurs
- `Rajout d offre`
  - garde le profil courant
  - ajoute `Time Limit` et `Data Limit`
  - retient la plus grande date entre l'ancienne expiration et `aujourd hui + validite`
- `Cumuler l offre`
  - garde le profil courant
  - ajoute `Time Limit` et `Data Limit`
  - ajoute la validite a l'expiration existante
  - n'est valable que pour le meme profil et pour un compte non expire

Etat courant :

- `mikrotik` : application reelle disponible
- `radius` (et peripheriques NAS associes en base) : apercu + **application** sur le modele utilisateur local et synchro NAS lorsque le device n'est pas de type `mikrotik`

Details d'implementation deja en place :

- libelles de l apercu **projete** : **Time limite** / **Data limite** ; l etat **actuel** reste **Temps restant** / **Data restante**
- la page garde un flux d'aperçu distinct de l'application reelle
- `Serveur` est charge depuis `network_devices_api.php`
- `Utilisateur` utilise maintenant une recherche rapide avec liste de resultats, tout en conservant un `select` technique cache pour le backend
- `Profil actuel` suit automatiquement l'utilisateur selectionne
- `Nouveau profil` reste pilote par le mode choisi ; les listes profils exposent **id** et **nom** ; le formulaire envoie **profile_id** et **profile_name** (champs caches) ; l API exige **profile_name** (MikroTik) ou **profile_id** (base locale)
- cote JS : **debounce** (~180 ms) sur chargement apercu / historique, avec **signatures** pour eviter les requetes identiques consecutives
- l'historique de recharge est maintenant alimente depuis une trace SQL locale
- la branche `mikrotik` enregistre aussi un historique court dans RouterOS avec purge automatique
- echecs d'ecriture historique SQL / journal d'operations sur `apply_recharge` : **non bloquants** pour la reponse succes (logs serveur)

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

Positionnement cible :

- FreeRADIUS doit rester le backend standard pour les NAS RADIUS
- la table `nas` devient la source de verite du dispatch par `nas_id`
- `nas.type` doit rester le discriminant principal des flux metier
- la branche `mikrotik` ne doit plus etre geree comme un simple sous-cas FreeRADIUS lorsque la communication RADIUS est instable
- la gestion MikroTik doit suivre une branche locale / Mikhmon distincte

Observation :

- il y a coexistence entre configuration fichier et configuration base
- `api/test_radius.php` contient des incoherences internes

### 5. OPNsense / equipements reseau

Composants :

- [pages/network_devices.php](/var/www/html/pages/network_devices.php)
- [js/network_device.js](/var/www/html/js/network_device.js)
- [api/network_devices_api.php](/var/www/html/api/network_devices_api.php)
- [api/test_device.php](/var/www/html/api/test_device.php)
- [api/disconnect_session.php](/var/www/html/api/disconnect_session.php)
- [config/opnsense.json](/var/www/html/config/opnsense.json)
- [config/config.php](/var/www/html/config/config.php)

Structure :

- les equipements OPNsense sont enregistres dans un JSON local
- les tests de connectivite sont faits par cURL
- certaines actions reseau comme la deconnexion de session passent par l'API OPNsense

Positionnement cible :

- OPNsense doit etre traite comme une branche backend API
- son comportement futur doit etre pilote par `nas_id`
- le `device.type = opnsense` ne doit servir qu'a l'execution technique des appels

### 5.b MikroTik / Mikhmon

Positionnement cible :

- MikroTik doit rester un type de device du projet
- il doit etre traite comme une branche technique distincte de `radius`
- sa gestion hotspot ne doit pas reposer par defaut sur les tables FreeRADIUS
- `docs/mikhmon/` sert de reference locale pour cette branche
- `device.type = mikrotik` ne doit pas decider seul d'une operation metier

Observation :

- il y a deux strategies de configuration OPNsense dans le projet :
  - via `config/config.php` pour les constantes globales
  - via `config/opnsense.json` pour les devices enregistres
- `config/opnsense.json` contient encore une entree orientee MikroTik, ce qui confirme une transition non terminee dans les donnees de configuration
- l'etat courant de la branche `mikrotik` n'est plus experimental :
  - dashboard branche
  - gestion des profils branchee
  - ajout utilisateur branche
  - sessions branchees
  - liste utilisateurs branchee en lecture

### 6. Sessions et suivi

Composants :

- [pages/users_list.php](/var/www/html/pages/users_list.php)
- [pages/sessions_list.php](/var/www/html/pages/sessions_list.php)
- [api/users/get_user_sessions.php](/var/www/html/api/users/get_user_sessions.php)
- [api/get_sessions.php](/var/www/html/api/get_sessions.php)
- [api/disconnect_session.php](/var/www/html/api/disconnect_session.php)

Structure :

- les sessions historiques viennent de `radacct` pour la branche `radius`
- la page `users_list.php` exploite `radacct` pour `radius`
- [pages/sessions_list.php](/var/www/html/pages/sessions_list.php) est maintenant branchee sur `/ip/hotspot/active/print` quand le device actif est `mikrotik`
- chargement et format : [`includes/sessions_backend.php`](/var/www/html/includes/sessions_backend.php), [`includes/session_formatters.php`](/var/www/html/includes/session_formatters.php) ; rendu des lignes partage entre la page et le rechargement AJAX : [`includes/sessions_list_table_body.php`](/var/www/html/includes/sessions_list_table_body.php)
- actualisation liste : [`js/pages/sessions_list.js`](/var/www/html/js/pages/sessions_list.js) — `fetch` manuel (`?_partial=sessions` + `X-Requested-With: XMLHttpRequest`) qui recoit un **fragment HTML** (lignes uniquement) injecté dans `#sessionsTableBody` via `innerHTML` ; **pas** de `setInterval` pour cette page

Lecture cible :

- `radacct` reste la source de sessions pour la branche `radius`
- les vues session de la branche `mikrotik` devront s'appuyer sur une source locale MikroTik / Mikhmon

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

### Dependances NAS / Devices

- [api/nas.php](/var/www/html/api/nas.php) depend de la table `nas`
- [api/network_devices_api.php](/var/www/html/api/network_devices_api.php) depend du JSON `config/opnsense.json`
- [api/test_device.php](/var/www/html/api/test_device.php) depend des credentials fournis par formulaire
- [pages/add_hotspot_user.php](/var/www/html/pages/add_hotspot_user.php) depend visuellement de `network_devices_api.php`, puis resout un contexte NAS via [includes/nas_resolver.php](/var/www/html/includes/nas_resolver.php)

Dependance cible a introduire :

- toute operation metier sensible au NAS doit d'abord dependre de `nas_id`
- `nas_id` doit ensuite determiner :
  - le backend
  - les attributs disponibles
  - les transformations a appliquer
- le `device` associe intervient ensuite seulement pour l'execution technique

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
- `devices`
- `vouchers`
- `user_overrides`
- `logs`

Lecture architecturale recommandee :

- une seule base physique est utilisee aujourd'hui : `radius_manager`
- mais elle porte deux couches logiques distinctes :
  - donnees metier applicatives
  - donnees AAA / FreeRADIUS

Couche metier :

- `users`
- `profiles`
- `devices`
- `vouchers`
- `user_overrides`
- `logs`

Couche RADIUS :

- `nas`
- `radcheck`
- `radreply`
- `radusergroup`
- `radgroupreply`
- `radacct`

## Architecture Cible Multi-NAS

Principe cible :

```text
UI
  ->
services applicatifs
  ->
resolution par nas_id
  ->
adaptateur backend
  ->
FreeRADIUS SQL standard / API OPNsense / autre backend
```

Regle directrice :

- `nas_id` doit definir le fonctionnement backend
- les objets metier `User`, `Profile`, `Session` restent communs
- seul l'adaptateur change selon `nas.type`
- le `device.type` ne doit pas redefinir seul le backend metier

- `users`
- `profiles`

Observation critique :

- [config/schema.sql](/var/www/html/config/schema.sql) couvre principalement FreeRADIUS
- les definitions SQL de `users` et `profiles` ne sont pas fournies dans le projet

### Fichiers JSON

- [config/opnsense.json](/var/www/html/config/opnsense.json) : liste d'equipements reseau du projet (`opnsense`, `mikrotik`, `radius`)
- [config/radius.json](/var/www/html/config/radius.json) : configuration FreeRADIUS de test
- [config/db.json](/var/www/html/config/db.json) : present mais non central dans les flux principaux observes

## Contraintes Architecturales Observees

- architecture monolithique PHP sans couche service formelle
- front et back fortement couples par les noms de champs HTML
- mapping direct entre modele interne et tables FreeRADIUS
- presence d'une branche MikroTik active pour dashboard, sessions, users et creation hotspot
- coexistence de donnees en SQL et en JSON local

## Resume

Le projet est structure comme une application PHP monolithique avec rendu serveur, enrichie par des appels AJAX ciblant des endpoints internes. Le coeur metier repose sur des objets applicatifs communs (`users`, `profiles`) dont la traduction technique doit dependre du backend resolu par `nas_id` : tables FreeRADIUS pour la branche `radius`, API OPNsense pour `opnsense`, et logique locale MikroTik / Mikhmon pour `mikrotik`. L'architecture est lisible, mais heterogene et partiellement inachevee, ce qui impose une forte prudence lors des modifications.
