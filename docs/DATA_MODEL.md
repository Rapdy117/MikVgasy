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

Convention de reference recommandee a partir du code :

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
- `rate_limit` suit le format texte `down/up`, exemple `2M/2M`
- `session_timeout`, `idle_timeout`, `data_quota_mb`, `simultaneous_use` sont fonctionnellement entiers

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

## Regles De Reference Recommandees Pour Le Refactoring

- `session_timeout: int`, `0 = illimite`
- `idle_timeout: int`, `0 = illimite`
- `data_limit: int`, unite explicite obligatoire
- `balance: decimal(10,2)` si stockage SQL
- `auto_renewal: bool`
- `verify_ssl: bool`
- `status` borne strictement a `active`, `disabled`, `expired`

Ce modele de reference sert de base documentaire. Il n'implique pas que le code actuel applique deja ces contraintes de maniere fiable.
