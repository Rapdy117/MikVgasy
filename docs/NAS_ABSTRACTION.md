# Architecture D'Abstraction NAS

## Objectif

Ce document propose une architecture cible pour supporter plusieurs types de NAS sans couplage direct entre l'UI, la logique metier et les backends techniques.

Types de NAS a supporter :

- OPNsense via API
- MikroTik via API
- RADIUS standard

Le but est de tendre vers l'architecture suivante :

`UI -> logique metier -> adaptateur NAS -> backend technique`

Ce document ne modifie pas le code existant. Il deduit un plan et une structure a partir du projet actuel.

## Modele implemente (alignement 2026)

Champs stabilises dans le code actif :

- `device.type` : uniquement `opnsense`, `mikrotik`, `radius` (plus de `other` / `generic` implicites).
- `business_source` : `radius` ou `mikrotik_local` ; OPNsense partage la base metier `radius` avec la branche FreeRADIUS et se distingue par le driver API.
- `backend` cote device JSON = driver technique : `opnsense_api`, `mikrotik_api`, `radius` (voir [includes/device_manager.php](/var/www/html/includes/device_manager.php)).
- [api/nas.php](/var/www/html/api/nas.php) : ne renvoie que des couples valides `device_id` + `nas_id` alignes sur la meme `business_source`, avec `label`, `device_type`, `nas_type`, `capabilities` ; acces reserve a une session authentifiee.

## Orientation Cible Retenue

Cette documentation retient maintenant les principes suivants :

- le projet doit rester multi-NAS
- `nas_id` devient la cle de routage centrale
- `nas.type` devient la source de verite metier et definit le backend logique a utiliser
- `device.type` devient la source d'execution technique
- le perimetre fonctionnel des devices est volontairement limite a `opnsense`, `mikrotik`, `radius`
- FreeRADIUS reste le backend standard pour la branche `radius`
- OPNsense doit etre consomme via une API projet dediee a venir
- MikroTik doit suivre une branche API dediee plutot qu'une simple etiquette vendor
- la logique metier doit rester commune quel que soit le backend
- les pages et fonctions UI doivent dependre des capacites du type de device
- le `device` actif seul ne doit pas choisir le backend d'une operation metier
- pour la branche `mikrotik`, le code source `docs/mikhmon` sert de reference de comportement, de format et de workflow
- la source reelle des donnees MikroTik reste toujours RouterOS API

Lecture cible :

```text
UI choisit nas_id
  ->
service metier charge nas.type
  ->
service selectionne l'adaptateur
  ->
service retrouve le device associe si necessaire
  ->
adaptateur traduit le modele interne vers le backend reel
```

Lecture complementaire pour MikroTik :

```text
RouterOS API = source reelle des donnees
Mikhmon = reference de format, de parsing et de workflow
Projet = adaptation de cette reference dans l'architecture actuelle
```

Modele fonctionnel deja retenu :

```text
user    = identite + overrides + etat
profile = offre heritee
state   = session / consommation / uptime / IP / MAC
```

Interpretation deja retenue :

- le `user` porte les limites effectives et l'etat courant
- le `profile` porte les regles d'offre
- la couche d'affichage doit reconstruire une valeur finale a partir des deux

## Etat D'Avancement Observe Au 27 Mars 2026

Estimation fonctionnelle :

- `mikrotik` : 85%
- `opnsense` : 70%
- `radius` : 78%

Lecture de cet etat :

- l'abstraction reste incomplete conceptuellement
- mais la branche `mikrotik` est deja fortement materialisee dans les ecrans et endpoints
- `opnsense` reste plus mature pour le monitoring que pour le provisioning
- `radius` reste la branche historique la plus stable, meme si elle n'est pas encore totalement nettoyee selon le nouveau modele

## Perimetre Observe Dans Le Code Actuel

Le projet contient aujourd'hui deux dependances techniques fortes :

- dependances directes aux tables FreeRADIUS
- dependances directes a l'API OPNsense

Il n'existe pas encore de couche d'abstraction centrale pour ces integrations.

## 1. Points De Dependance Directe

### 1.1 Dependances directes a FreeRADIUS

Points du code qui ecrivent ou lisent directement les tables FreeRADIUS :

- [api/users/create_user.php](/var/www/html/api/users/create_user.php)
  - `radcheck`
  - `radusergroup`
  - `radreply`
- [api/users/update_user.php](/var/www/html/api/users/update_user.php)
  - `radcheck`
  - `radusergroup`
  - `radreply`
- [api/users/delete_user.php](/var/www/html/api/users/delete_user.php)
  - `radcheck`
  - `radreply`
  - `radusergroup`
  - `radacct` optionnel
- [api/users/get_user_sessions.php](/var/www/html/api/users/get_user_sessions.php)
  - lecture `radacct`
- [api/get_sessions.php](/var/www/html/api/get_sessions.php)
  - lecture `radacct`
- [api/profiles/create_profile.php](/var/www/html/api/profiles/create_profile.php)
  - creation `profiles`
  - synchronisation RADIUS via [includes/radius_sync.php](/var/www/html/includes/radius_sync.php)
- [includes/radius_sync.php](/var/www/html/includes/radius_sync.php)
  - `radgroupreply`
  - attributs `Session-Timeout`, `Idle-Timeout`, `Simultaneous-Use`, `Max-Octets`
  - attributs `WISPr-Bandwidth-Max-Down`, `WISPr-Bandwidth-Max-Up`
- [api/nas.php](/var/www/html/api/nas.php)
  - lecture de la table `nas`
- [config/schema.sql](/var/www/html/config/schema.sql)
  - schema des tables FreeRADIUS standard

Conclusion :

- la logique utilisateur et profil est actuellement fusionnee avec le backend FreeRADIUS
- l'application ne passe pas par une API de service intermediaire

### 1.2 Dependances directes a OPNsense

Points du code qui utilisent directement OPNsense :

- [api/test_device.php](/var/www/html/api/test_device.php)
  - appel cURL sur `/api/core/system/status`
- [api/disconnect_session.php](/var/www/html/api/disconnect_session.php)
  - appel cURL sur `/api/captiveportal/session/disconnect`
- [api/network_devices_api.php](/var/www/html/api/network_devices_api.php)
  - stockage local des devices OPNsense dans [config/opnsense.json](/var/www/html/config/opnsense.json)
- [config/config.php](/var/www/html/config/config.php)
  - constantes globales `OPN_SENSE_URL`, `OPN_SENSE_API_KEY`, `OPN_SENSE_API_SECRET`
- [js/network_device.js](/var/www/html/js/network_device.js)
  - appelle `network_devices_api.php`
  - appelle `test_device.php`

Conclusion :

- OPNsense est aujourd'hui utilise pour la gestion des devices et certaines actions reseau
- il n'existe pas encore de logique de provisionnement utilisateur OPNsense dans le code

### 1.3 Dependances directes a MikroTik

Points du code qui utilisent directement RouterOS API :

- [includes/mikrotik_backend.php](/var/www/html/includes/mikrotik_backend.php)
- [pages/profile_list.php](/var/www/html/pages/profile_list.php)
- [pages/add_profile.php](/var/www/html/pages/add_profile.php)
- [pages/add_hotspot_user.php](/var/www/html/pages/add_hotspot_user.php)
- [pages/users_list.php](/var/www/html/pages/users_list.php)
- [pages/sessions_list.php](/var/www/html/pages/sessions_list.php)
- [api/users/profile_options.php](/var/www/html/api/users/profile_options.php)
- [api/profiles/mikrotik_options.php](/var/www/html/api/profiles/mikrotik_options.php)

Conclusion :

- la branche `mikrotik` dispose deja de plusieurs points d'acces directs a RouterOS
- l'abstraction finale n'est pas encore centralisee derriere une interface unique, mais le comportement fonctionnel est bien present

Lecture metier deja deduite de Mikhmon :

- `comment` user = expiration effective
- `on-login` = traduction RouterOS des regles d'offre
- scheduler de profil = enforcement de l'expiration
- `limit-uptime` = limite temps effective
- `limit-bytes-total` = limite data effective

## 2. Probleme Architectural Actuel

Le projet fonctionne aujourd'hui avec un couplage direct :

- UI -> endpoint PHP concret
- endpoint PHP -> SQL FreeRADIUS ou API OPNsense

Cela pose plusieurs limites :

- impossible de changer de backend NAS sans reouvrir les endpoints metier
- duplication de logique selon les contextes utilisateur/profil/session
- difficulte pour supporter plusieurs backends simultanement
- dependance forte aux details SQL RADIUS
- dependance forte aux details d'API OPNsense

## 3. Architecture Cible

## Principe General

La logique metier ne doit plus connaitre :

- ni `radcheck`
- ni `radreply`
- ni `radgroupreply`
- ni les URLs OPNsense

Elle doit manipuler seulement :

- des objets metier normalises
- des fonctions generiques d'acces NAS

Architecture cible :

```text
UI / Controllers
    ->
Application Services
    ->
NAS Resolver (via nas_id -> nas.type)
    ->
NAS Adapter Interface
    ->
Specific Adapter
    ->
FreeRADIUS SQL / OPNsense API / Other RADIUS backend
```

## 4. Structure Proposee

## 4.1 Couche UI

Responsabilites :

- collecter les donnees formulaire
- afficher les resultats
- ne jamais connaitre la structure RADIUS ou OPNsense

Exemples de pages/frontend concernes :

- `pages/add_hotspot_user.php`
- `pages/add_profile.php`
- `pages/users_list.php`
- `pages/network_devices.php`

## 4.2 Couche logique metier

Responsabilites :

- valider et normaliser les objets selon [docs/DATA_MODEL.md](/var/www/html/docs/DATA_MODEL.md)
- orchestrer les cas d'usage metier
- charger le NAS selectionne
- appeler l'adaptateur NAS approprie

Fonctions generiques ciblees :

- `createUser(user, profile, nasContext)`
- `updateUser(user, profile, nasContext)`
- `deleteUser(userId, nasContext)`
- `createProfile(profile, nasContext)`
- `updateProfile(profile, nasContext)`
- `deleteProfile(profileId, nasContext)`
- `getSessions(userRef, nasContext)`
- `disconnectSession(sessionRef, nasContext)`
- `testNasConnection(nasContext)`
- `applyRecharge(userRef, profileRef, mode, nasContext)`

Contexte cible minimal :

```text
NasContext {
  nas_id: int
  nas_type: string
  backend: 'radius' | 'opnsense_api' | 'mikrotik_local'
  capabilities: string[]
}
```

Regles metier deja retenues pour `applyRecharge(...)` :

- `replace_offer`
  - applique les valeurs du profil choisi
- `extend_offer`
  - garde le profil courant
  - ajoute `Time Limit`
  - ajoute `Data Limit`
  - retient la plus grande date entre l'ancienne expiration et `aujourd hui + validite`
- `accumulate_offer`
  - garde le profil courant
  - ajoute `Time Limit`
  - ajoute `Data Limit`
  - ajoute la validite a l'expiration existante
  - exige le meme profil et un compte non expire

## 4.3 Couche adaptateur NAS

Responsabilites :

- exposer une interface commune
- convertir les objets metier en operations techniques specifiques

Interface cible :

```text
NasAdapterInterface {
  createUser(user, profile, nasContext)
  updateUser(user, profile, nasContext)
  deleteUser(userRef, nasContext)
  createProfile(profile, nasContext)
  updateProfile(profile, nasContext)
  deleteProfile(profileRef, nasContext)
  getSessions(userRef, nasContext)
  disconnectSession(sessionRef, nasContext)
  testConnection(nasContext)
}
```

## 4.4 Backends techniques

Adaptateurs proposes :

- `RadiusStandardAdapter`
- `OpnSenseApiAdapter`
- `MikroTikApiAdapter`

## 4.5 Capacites UI Par Type De Device

Le type du device doit aussi piloter les capacites visibles cote interface.

Reference cible :

```text
DeviceUiCapabilities {
  has_dashboard: bool
  has_api_test: bool
  has_live_sessions: bool
  has_disconnect: bool
}
```

Convention retenue :

- `opnsense` :
  - `has_dashboard = true`
  - `has_api_test = true`
- `mikrotik` :
  - `has_dashboard = false` tant qu'aucun dashboard dedie n'existe
  - `has_api_test = true`
- `radius` :
  - `has_dashboard = false`
  - `has_api_test = false`
  - branche standard sans pilotage live projet

## 5. Implementations Specifiques Proposees

## 5.1 FreeRADIUS SQL

Nom propose :

- `createUserRadius`
- `updateUserRadius`
- `deleteUserRadius`
- `createProfileRadius`
- `getSessionsRadius`

Responsabilites :

- ecrire dans `radcheck`, `radreply`, `radusergroup`, `radgroupreply`
- lire `radacct`
- traduire les objets metier vers les attributs RADIUS

Positionnement cible :

- FreeRADIUS devient la branche standard du systeme multi-NAS
- il sert de backend commun aux NAS compatibles RADIUS
- les attributs disponibles doivent dependre des capacites du NAS selectionne

Attributs potentiels a supporter selon le NAS :

- `Session-Timeout`
- `Idle-Timeout`
- `Simultaneous-Use`
- `Expiration`
- `WISPr-Bandwidth-Max-Down`
- `WISPr-Bandwidth-Max-Up`
- `Max-Octets`
- `Max-Data`

Sources actuelles a refactoriser ensuite vers cet adaptateur :

- [api/users/create_user.php](/var/www/html/api/users/create_user.php)
- [api/users/update_user.php](/var/www/html/api/users/update_user.php)
- [api/users/delete_user.php](/var/www/html/api/users/delete_user.php)
- [api/users/get_user_sessions.php](/var/www/html/api/users/get_user_sessions.php)
- [includes/radius_sync.php](/var/www/html/includes/radius_sync.php)

## 5.2 OPNsense API

Nom propose :

- `createUserOPNsense`
- `updateUserOPNsense`
- `deleteUserOPNsense`
- `getSessionsOPNsense`
- `disconnectSessionOPNsense`
- `testConnectionOPNsense`

Etat reel du code :

- `testConnectionOPNsense` existe deja partiellement via [api/test_device.php](/var/www/html/api/test_device.php)
- `disconnectSessionOPNsense` existe deja partiellement via [api/disconnect_session.php](/var/www/html/api/disconnect_session.php)
- `createUserOPNsense` n'existe pas aujourd'hui dans le code reel

Conclusion :

- l'adaptateur OPNsense devra probablement commencer par couvrir :
  - tests de connectivite
  - deconnexion de session
  - eventuellement consultation d'etat/session
- le provisionnement utilisateur OPNsense devra passer par l'API projet a venir
- cette branche ne doit pas imposer artificiellement l'ecriture dans les tables `rad*` si le backend retenu est purement API

## 5.3 Autres NAS compatibles RADIUS

Nom propose :

- `createUserGenericRadius`
- `updateUserGenericRadius`
- `createProfileGenericRadius`
- `getSessionsGenericRadius`

Principe cible :

- tant qu'un NAS reste compatible RADIUS, il doit pouvoir passer par la branche standard
- seul le sous-ensemble d'attributs supportes change selon le NAS

## 6. Deux Couches De Donnees A Distinguer

Le code actuel utilise une seule base physique `radius_manager`, mais la documentation doit distinguer deux couches logiques :

- couche metier applicative
- couche RADIUS / AAA

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
- `radgroupcheck`
- `radpostauth`

## 7. Capacites NAS A Introduire

Pour que `nas_id` define reellement le comportement global, il faut documenter les capacites du NAS.

Structure cible minimale :

```text
NasCapability {
  nas_id: int
  attribute_code: string
  supported: bool
  unit: string | null
}
```

Exemples :

- un NAS RADIUS standard peut supporter `Session-Timeout`, `Idle-Timeout`, `WISPr-*`
- un NAS MikroTik local peut exposer ses propres parametres via sa branche technique dediee
- un NAS OPNsense API peut exposer d'autres champs via API projet

## 8. Regle Directrice

Le comportement du systeme doit converger vers cette regle :

- le choix de `nas_id` definit le backend
- le backend definit les attributs possibles
- les objets metier restent communs
- seul l'adaptateur change

Principe :

- reutiliser le modele commun
- choisir les attributs RADIUS selon les capacites du NAS cible
- ne pas melanger les attributs RADIUS standards avec la branche locale MikroTik

## 6. Mapping De Donnees Unique Base Sur DATA_MODEL.md

## 6.1 Objet User cible

Base de reference :

- [docs/DATA_MODEL.md](/var/www/html/docs/DATA_MODEL.md)

Objet metier a exposer a tous les adaptateurs :

```text
User {
  id: int
  username: string
  password: string
  profile_id: int
  status: 'active' | 'disabled' | 'expired'
  fullname: string | null
  phone: string | null
  email: string | null
  address: string | null
  balance: decimal
  expiration_date: datetime | null
  auto_renewal: bool
}
```

## 6.2 Objet Profile cible

```text
Profile {
  id: int
  name: string
  rate_limit: string | null
  session_timeout: int | null
  idle_timeout: int | null
  data_quota_mb: int | null
  simultaneous_use: int | null
}
```

## 6.3 Objet NasContext cible

Objet propose pour piloter l'adaptateur :

```text
NasContext {
  id: int | string
  backend_type: 'radius_sql' | 'opnsense_api' | 'generic_radius'
  nas_type: string | null
  host: string | null
  auth: {
    username: string | null
    password: string | null
    api_key: string | null
    api_secret: string | null
    secret: string | null
  }
  options: {
    verify_ssl: bool | null
    auth_port: int | null
    acct_port: int | null
    timeout: int | null
  }
}
```

## 6.4 Objet UserSession cible

```text
UserSession {
  start: datetime | null
  stop: datetime | null
  duration: int | null
  data_mb: float
  ip: string | null
  mac: string | null
  nas: string | null
  status: 'online' | 'offline' | null
}
```

## 6.5 Regles communes de normalisation

Pour tous les adaptateurs :

- `session_timeout: int | null`, `0 = illimite`
- `idle_timeout: int | null`, `0 = illimite`
- `data_quota_mb: int | null`, `0 = illimite`
- `simultaneous_use: int | null`
- `verify_ssl: bool`
- `auto_renewal: bool`

## 7. Mapping Par Backend

## 7.1 RadiusSqlNasAdapter

Mapping propose :

- `User.password` -> `radcheck` / `Cleartext-Password`
- `Profile.name` -> `radusergroup.groupname`
- `Profile.session_timeout` -> `Session-Timeout`
- `Profile.idle_timeout` -> `Idle-Timeout`
- `Profile.simultaneous_use` -> `Simultaneous-Use`
- `Profile.data_quota_mb` -> `Max-Octets`
- `Profile.rate_limit` :
  - `WISPr-Bandwidth-Max-Down` / `WISPr-Bandwidth-Max-Up` pour la branche RADIUS
  - logique locale dediee pour la branche MikroTik

Strategie recommandee :

- isoler la logique MikroTik locale dans sa propre branche
- ne pas la laisser dans le coeur metier commun RADIUS

## 7.2 OpnSenseNasAdapter

Mapping propose :

- `NasContext.host` -> URL API OPNsense
- `NasContext.auth.api_key/api_secret` -> auth HTTP Basic
- `UserSession` <- reponse OPNsense sur les endpoints reseau disponibles
- `disconnectSession(sessionRef)` -> endpoint captive portal

Important :

- le code actuel ne prouve pas encore l'existence d'un flux de creation utilisateur OPNsense
- `createUserOPNsense` doit donc etre defini comme extension cible, pas comme fonctionnalite deja supportee

## 7.3 GenericRadiusNasAdapter

Mapping propose :

- meme contrat metier que `RadiusSqlNasAdapter`
- mapping d'attributs configurable par fournisseur
- adaptation des attributs de debit selon les capacites du NAS

## 8. Modifications Necessaires Sans Modifier Le Code

Ce qui devra etre fait plus tard pour atteindre cette architecture :

### 8.1 Extraire la logique metier des endpoints

Code actuellement concerne :

- [api/users/create_user.php](/var/www/html/api/users/create_user.php)
- [api/users/update_user.php](/var/www/html/api/users/update_user.php)
- [api/users/delete_user.php](/var/www/html/api/users/delete_user.php)
- [api/profiles/create_profile.php](/var/www/html/api/profiles/create_profile.php)
- [api/users/get_user_sessions.php](/var/www/html/api/users/get_user_sessions.php)
- [api/disconnect_session.php](/var/www/html/api/disconnect_session.php)

Evolution cible :

- les endpoints deviennent de simples controleurs
- ils appellent un service metier puis un adaptateur

### 8.2 Creer un service de selection d'adaptateur

Exemple conceptuel :

```text
getNasAdapter(nasContext) -> RadiusSqlNasAdapter | OpnSenseNasAdapter | GenericRadiusNasAdapter
```

La selection pourrait se faire via :

- `nas.type` en base
- `backend_type` dans un modele unifie
- metadonnees de config JSON

### 8.3 Centraliser la normalisation des donnees

Il faut un point unique pour :

- caster les entiers
- transformer `''` en `null`
- normaliser `0 = illimite`
- normaliser les booleens `0/1`, `true/false`

### 8.4 Deplacer le mapping d'attributs RADIUS dans des strategies dediees

Aujourd'hui :

- la logique est concentree dans [includes/radius_sync.php](/var/www/html/includes/radius_sync.php)

Architecture cible :

- `RadiusAttributeMapper`
- `MikrotikLegacyMapper`
- `WisprMapper`

### 8.5 Unifier la notion de session

Aujourd'hui :

- `radacct` alimente les sessions utilisateurs
- OPNsense gere certaines actions de session via API

Architecture cible :

- un contrat unique `getSessions()` et `disconnectSession()`
- chaque backend traduit vers son propre mecanisme technique

### 8.6 Clarifier le role de OPNsense dans le domaine metier

Question architecturale a fixer au moment du refactoring :

- OPNsense sert-il seulement pour :
  - la connectivite
  - la gestion de devices
  - la deconnexion de sessions
- ou doit-il aussi provisionner utilisateurs et profils

Le code actuel supporte seulement la premiere hypothese.

## 9. Plan De Migration Recommande

### Etape 1

- figer le modele metier de reference a partir de [docs/DATA_MODEL.md](/var/www/html/docs/DATA_MODEL.md)

### Etape 2

- identifier un `backend_type` unique pour chaque NAS

### Etape 3

- extraire les operations metier generiques :
  - `createUser`
  - `updateUser`
  - `deleteUser`
  - `createProfile`
  - `getSessions`
  - `disconnectSession`

### Etape 4

- encapsuler le SQL FreeRADIUS dans `RadiusSqlNasAdapter`

### Etape 5

- encapsuler OPNsense dans `OpnSenseNasAdapter`

### Etape 6

- isoler les traces MikroTik dans une compatibilite legacy explicite

### Etape 7

- faire pointer les endpoints existants vers la couche service/adaptateur

## 10. Benefices Attendus

- reduction du couplage UI/backend technique
- ajout plus simple de nouveaux NAS compatibles RADIUS
- meilleure testabilite
- clarification des responsabilites
- migration progressive possible sans reecriture brutale

## Conclusion

Le projet est aujourd'hui fortement couple a FreeRADIUS SQL et, dans une moindre mesure, a OPNsense API. L'architecture recommande consiste a introduire une couche d'abstraction NAS entre la logique metier et les integrations techniques. Cette couche doit exposer des fonctions generiques comme `createUser`, `createProfile`, `getSessions` et fournir des implementations specialisees comme `createUserRadius` et `createUserOPNsense`. Le modele unifie doit s'appuyer sur [docs/DATA_MODEL.md](/var/www/html/docs/DATA_MODEL.md), avec normalisation stricte des types et isolement des branches legacy MikroTik.
