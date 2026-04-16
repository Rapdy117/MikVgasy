# Modele De Donnees De Reference

## Objectif

Ce document definit un modele de donnees de reference deduit du code reel. Il ne correspond pas a un schema SQL complet existant dans le depot, mais a la structure effectivement attendue par l'application.

Le but est de :

- fixer les types attendus
- unifier les interpretations fonctionnelles
- reduire les ambiguities lors du refactoring

## Principes Generaux

- tous les identifiants techniques sont consideres comme des `int` cote application si issus de tables SQL auto-incrementees
- toutes les chaines vides saisies par formulaire doivent etre considerees comme differentes de `null`
- les valeurs de duree et quotas sont fonctionnellement numeriques, meme si certaines tables RADIUS les stockent sous forme de texte
- `0` doit etre reserve a la notion explicite de "illimite" lorsqu'un champ supporte cette interpretation

## Type De Reference

### Identifiants

- `id: int`
- `profile_id: int`
- `nas_id: int`

### Durees

- `session_timeout: int`
- `idle_timeout: int`
- `timeout: int`
- `validity_time: int`

Convention de reference recommandee a partir du code :

- `validity_time = duree commerciale de l offre`
- `session_timeout = 0` -> illimite
- `idle_timeout = 0` -> pas de coupure pour inactivite

Observation :

- le code actuel ne normalise pas strictement `0`, `null` et `''`
- les formulaires HTML envoient souvent `string` ou valeur vide

### Quotas / volumes

- `data_limit: int`
- `data_quota_mb: int`
- `balance: decimal`
- `simultaneous_use: int`

Convention de reference :

- `data_limit = 0` -> illimite
- `simultaneous_use = 0` -> interpretation metier a fixer si besoin, mais le code ne l'explicite pas clairement

### Etats

- `status: enum('active', 'disabled', 'expired')`
- `auto_renewal: bool`
- `verify_ssl: bool`
- `online: bool`

## Objet User

Structure deduite du code :

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
  created_at: datetime | null
  last_login: datetime | null
}
```

Champs effectivement lus ou ecrits dans le code :

- ecriture : [api/users/create_user.php](/var/www/html/api/users/create_user.php)
- mise a jour : [api/users/update_user.php](/var/www/html/api/users/update_user.php)
- lecture : [pages/users_list.php](/var/www/html/pages/users_list.php)

Contraintes deduites :

- `username` obligatoire
- `password` obligatoire a la creation
- `profile_id` attendu comme obligatoire meme si le code ne le valide pas strictement avant SQL
- `status` doit rester dans les trois valeurs observees cote UI

Observation au 27 mars 2026 :

- pour la branche `mikrotik`, la source de selection de profil dans l'UI peut maintenant etre RouterOS
- l'application resout ensuite ou cree un `profile_id` local minimal pour garder la coherence des tables applicatives

Interpretation fonctionnelle deja retenue :

- `User` porte en priorite :
  - `username`
  - `password`
  - `profile_id` ou `profile` courant
  - `session_timeout` / `limit-uptime`
  - `data_limit` / `limit-bytes-total`
  - `expiration_date` ou `comment` selon backend
- un user peut donc porter des overrides sur l'offre

## Objet Profile

Structure deduite du code :

```text
Profile {
  id: int
  name: string
  rate_limit: string | null
  session_timeout: int | null
  idle_timeout: int | null
  data_quota_mb: int | null
  simultaneous_use: int | null
  service_type: string | null
  ip_pool: string | null
  account_type: string | null
}
```

Observation :

- seule une partie de cet objet est ecrite dans [api/profiles/create_profile.php](/var/www/html/api/profiles/create_profile.php)
- une partie supplementaire est lue dans [pages/users_list.php](/var/www/html/pages/users_list.php), ce qui implique que la table `profiles` reelle contient probablement plus de colonnes que celles visibles dans le create actuel

Contraintes deduites :

- `name` obligatoire et fonctionnellement unique
- `rate_limit` suit maintenant le format texte `upload/download` pour la branche MikroTik, exemple `2M/10M`
- `session_timeout`, `idle_timeout`, `data_quota_mb`, `simultaneous_use` sont fonctionnellement entiers

Observation au 27 mars 2026 :

- le formulaire [add_profile.php](/var/www/html/pages/add_profile.php) manipule maintenant aussi des champs avances :
  - `expired_mode`
  - `grace_period`
  - `price`
  - `selling_price`
  - `address_pool`
  - `lock_user`
  - `parent_queue`
- tous ces champs ne sont pas encore persistés completement dans le schema SQL local
- une partie de leur effet est deja traduite directement vers RouterOS via `on-login` et scheduler

Interpretation fonctionnelle deja retenue :

- `Profile` porte en priorite :
  - `rate_limit`
  - `data_quota_mb`
  - `simultaneous_use`
  - `validity_time`
  - `expired_mode`
  - `grace_period`
  - `price`
  - `selling_price`
  - `ip_pool`
  - `lock_user`
  - `parent_queue`

Regle de lecture :

- le profil represente l'offre heritee
- le user represente la surcharge et l'etat
- l'UI finale doit calculer une valeur affichee a partir des deux

Regle semantique complementaire :

- `validity_time` = duree commerciale
- `session_timeout` = time limit technique
- `data_quota_mb` = data limit du profil
- `data_limit` = data limit effectivement appliquee a l utilisateur

## Regles Metier De Recharge Deja Definies

## Regle Desormais Fixee Pour Data Limit

- `Data Limit` est un champ metier du `Profile`
- il est stocke localement dans `profiles.data_quota_mb`
- l'unite de reference retenue est `MB`
- cote `mikrotik`, ce quota est traduit au niveau `User` via `limit-bytes-total`

Regle d'interpretation :

- `Profile` = offre theorique
- `User` = limite effectivement appliquee
- `reste` = limite user moins consommation observee

Consequences fonctionnelles :

- a la creation user :
  - `data_quota_mb` du profil devient `limit-bytes-total`
- en `Changement d'offre` :
  - la data du user est remplacee par celle du profil choisi
- en `Rechargement` :
  - la data du profil s'ajoute a la limite existante
- en `Reabonnement` :
  - la data du profil s'ajoute aussi, mais seulement dans le cadre des regles de meme profil / compte non expire
### Remplacer l'offre

- applique les valeurs du profil choisi
- repart des valeurs d'offre

### Rajout d'offre

- garde le profil courant
- ajoute `Time Limit`
- ajoute `Data Limit`
- retient la plus grande date entre l'expiration actuelle et `aujourd hui + validite`

### Cumuler l'offre

- garde le profil courant
- ajoute `Time Limit`
- ajoute `Data Limit`
- ajoute la validite a l'expiration existante
- n'est valable que pour le meme profil et pour un compte non expire

## Ce Qui Reste Encore Ouvert

- source exacte du quota data d'offre cote MikroTik
- modele commercial complet :
  - client
  - abonnement
  - paiement
  - recharge
- equivalence finale de ces regles dans la branche `opnsense`

## Historique De Recharge

La base locale porte maintenant une trace minimale commune :

```text
recharge_history {
  id
  device_id
  device_type
  username
  profile_name
  mode
  operator_username
  effect_summary
  current_profile
  current_time_limit
  current_data_limit
  current_expiration
  projected_profile
  projected_time_limit
  projected_data_limit
  projected_expiration
  created_at
}
```

Role retenu :

- historique durable commun pour le reporting de recharge
- compatible avec une trace courte RouterOS cote `mikrotik`

## Objet Voucher

Structure locale actuellement observee :

```text
Voucher {
  id: int
  code: string
  profile_id: int
  used: bool
  used_by: string | null
  used_at: datetime | null
  created_at: datetime | null
}
```

Regle metier retenue :

- `created_at` = date de generation
- `used_at` = date du premier login du ticket
- la comptabilisation d'un voucher demarre a `used_at`
- un voucher non utilise n'entre pas encore dans le volume commercial realise

- historique commercial commun au projet
- support du tableau `Historique de recharge`
- base future pour les rapports

Pour `mikrotik`, cette trace SQL est completee par un historique court dans RouterOS, limite en taille.

## Objet NAS

Structure deduite de [api/nas.php](/var/www/html/api/nas.php) et du schema FreeRADIUS :

```text
NAS {
  id: int
  nasname: string
  shortname: string | null
  type: string
  ports: int | null
  secret: string
  server: string | null
  community: string | null
  description: string | null
}
```

Valeurs fonctionnelles importantes :

- `type = 'mikrotik'` active une branche speciale dans [includes/radius_sync.php](/var/www/html/includes/radius_sync.php)
- tout autre type suit la branche WISPr / OPNsense observee

## Objet NasContext

Structure cible recommandee pour le systeme multi-NAS :

```text
NasContext {
  nas_id: int
  nas_type: string
  backend: 'radius' | 'opnsense_api' | 'mikrotik_local'
  capabilities: string[]
}
```

Regle de reference :

- `nas_id` doit definir le contexte metier a resoudre
- `nas_type` doit definir le backend logique et le traducteur a utiliser
- `capabilities` doit definir les attributs et operations supportes
- le backend ne doit pas etre deduit du seul `device` actif

Exemples :

- `nas_type = 'opnsense'` -> `backend = 'opnsense_api'`
- `nas_type = 'mikrotik'` -> `backend = 'mikrotik_local'`
- `nas_type = 'radius'` -> `backend = 'radius'`

## Objet NasCapability

Structure documentaire cible :

```text
NasCapability {
  nas_id: int
  attribute_code: string
  supported: bool
  unit: string | null
}
```

Exemples d'attributs :

- `Session-Timeout`
- `Idle-Timeout`
- `Simultaneous-Use`
- `Expiration`
- `WISPr-Bandwidth-Max-Down`
- `WISPr-Bandwidth-Max-Up`
- `Max-Octets`
- `Max-Data`

## Objet NetworkDevice

Structure cible documentaire retenue pour `pages/network_devices.php` :

```text
NetworkDevice {
  id: string
  name: string
  type: 'opnsense' | 'mikrotik' | 'radius'
  capabilities: string[]
}
```

Regle d'usage :

- `NetworkDevice.type` decrit le mode d'execution technique du device
- il ne constitue pas la source de verite metier du provisioning
- le lien attendu est `nas_id -> nas.type -> backend logique -> device associe -> execution technique`

Capacites UI de reference :

- `opnsense` -> dashboard possible, test API possible
- `mikrotik` -> test API possible, dashboard branche, profils/sessions/utilisateurs partiellement ou totalement branches
- `radius` -> pas de dashboard, pas de test API OPNsense/MikroTik

## Objet NetworkDevice (OPNsense JSON)

Structure deduite de [api/network_devices_api.php](/var/www/html/api/network_devices_api.php) :

```text
NetworkDevice {
  id: string
  name: string
  type: 'opnsense'
  host: string
  api_key: string
  api_secret: string
  verify_ssl: bool
  created_at: datetime | null
  updated_at: datetime | null
}
```

Observation :

- cet objet n'est pas en base SQL
- il est stocke dans [config/opnsense.json](/var/www/html/config/opnsense.json)

## Objet RadiusConfig

Structure deduite de [config/radius.php](/var/www/html/config/radius.php) :

```text
RadiusConfig {
  test_user: string
  test_pass: string
  host: string
  auth_port: int
  acct_port: int
  secret: string
  timeout: int
}
```

## Objet UserSession

Structure deduite de [api/users/get_user_sessions.php](/var/www/html/api/users/get_user_sessions.php) :

```text
UserSession {
  start: datetime | null
  stop: datetime | null
  duration: int | null
  data_mb: float
  ip: string | null
  mac: string | null
  nas: string | null
}
```

Observation :

- `duration` est renvoye tel quel depuis `acctsessiontime`
- `data_mb` est calcule a partir de `acctinputoctets + acctoutputoctets`

## Mapping De Type Entre UI Et Backend

### Depuis les formulaires HTML

Les `input type="number"` arrivent en PHP comme :

- `string numerique`
- ou `''` si vide

Cela concerne notamment :

- `session_timeout`
- `idle_timeout`
- `data_limit`
- `simultaneous_use`
- `auth_port`
- `acct_port`
- `timeout`

### Normalisation attendue pour un modele strict

- `''` -> `null`
- `'0'` -> `0`
- `'10'` -> `10`

Le code actuel ne fait pas cette normalisation de maniere coherente.

## Incoherences Observees

- `session_timeout` est numerique cote UI mais peut etre transporte comme `string` jusqu'a l'ecriture RADIUS
- `data_limit` cote profil est interprete en MB puis converti en octets, alors que cote utilisateur il est ecrit brut en `radreply`
- `auto_renewal` est saisi comme `0` ou `1`, mais affiche ensuite parfois comme texte "Oui/Non"
- `verify_ssl` est stocke comme bool dans le JSON mais transite par formulaire sous forme de string `true/false`
- le projet utilise une seule base physique, mais deux couches logiques :
  - `users`, `profiles`, `devices`, `vouchers` pour le metier
  - `radcheck`, `radreply`, `radusergroup`, `radgroupreply`, `radacct`, `nas` pour le backend RADIUS

## Regles De Reference Recommandees Pour Le Refactoring

- `session_timeout: int`, `0 = illimite`
- `idle_timeout: int`, `0 = illimite`
- `data_limit: int`, unite explicite obligatoire
- `balance: decimal(10,2)` si stockage SQL
- `auto_renewal: bool`
- `verify_ssl: bool`
- `status` borne strictement a `active`, `disabled`, `expired`
- `nas_id: int` comme cle centrale de routage backend
- `nas_type: string` comme discriminant de l'adaptateur technique

Ce modele de reference sert de base documentaire. Il n'implique pas que le code actuel applique deja ces contraintes de maniere fiable.
