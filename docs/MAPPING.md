# Mapping Entre Le Modele Interne Et FreeRADIUS

## Objectif

Ce document relie les objets fonctionnels du projet aux tables FreeRADIUS effectivement utilisees dans le code.

Tables FreeRADIUS observees :

- `radcheck`
- `radreply`
- `radgroupreply`
- `radusergroup`
- `radacct`
- `nas`

## Vue Generale

Le projet utilise un modele interne compose principalement de :

- `User`
- `Profile`
- `NAS`
- `UserSession`

Puis il transforme ces objets vers les tables FreeRADIUS de la maniere suivante :

- utilisateur -> `radcheck`, `radreply`, `radusergroup`
- profil -> `radgroupreply`
- historique de session -> `radacct`
- equipement NAS -> `nas`

## Mapping User -> FreeRADIUS

### 1. Authentification utilisateur

Source interne :

- `User.username`
- `User.password`

Destination :

- table `radcheck`

Transformation observee :

```text
username  -> radcheck.username
password  -> radcheck.value
attribute -> 'Cleartext-Password'
op        -> ':='
```

Reference :

- [api/users/create_user.php#L82](/var/www/html/api/users/create_user.php#L82)
- [api/users/update_user.php#L88](/var/www/html/api/users/update_user.php#L88)

### 2. Appartenance a un profil

Source interne :

- `User.username`
- `User.profile_id`
- `Profile.name`

Destination :

- table `radusergroup`

Transformation observee :

```text
username           -> radusergroup.username
profiles.name      -> radusergroup.groupname
priority           -> 1
```

Reference :

- [api/users/create_user.php#L91](/var/www/html/api/users/create_user.php#L91)
- [api/users/update_user.php#L98](/var/www/html/api/users/update_user.php#L98)

Observation :

- le couplage se fait sur `profiles.name`, pas sur `profiles.id`
- un renommage de profil impacte donc le mapping RADIUS

### 3. Attributs RADIUS individuels

Source interne potentielle :

- `rate_limit`
- `simultaneous_use`
- `idle_timeout`
- `data_limit`
- `expiration_date`

Destination :

- table `radreply`

Mapping observe :

```text
rate_limit        -> attribute = 'Mikrotik-Rate-Limit'
simultaneous_use  -> attribute = 'Simultaneous-Use'
idle_timeout      -> attribute = 'Idle-Timeout'
data_limit        -> attribute = 'Max-Data'
expiration_date   -> attribute = 'Expiration'
op                -> ':='
```

Reference :

- [api/users/create_user.php#L100](/var/www/html/api/users/create_user.php#L100)
- [api/users/update_user.php#L108](/var/www/html/api/users/update_user.php#L108)

Observation critique :

- le champ `session_timeout` utilisateur existe dans l'UI mais n'est pas mappe vers `radreply`
- `Mikrotik-Rate-Limit` reste utilise meme hors contexte MikroTik

## Mapping Profile -> FreeRADIUS

### 1. Profil applicatif vers groupe RADIUS

Source interne :

- `Profile.name`
- `Profile.rate_limit`
- `Profile.session_timeout`
- `Profile.idle_timeout`
- `Profile.data_quota_mb`
- `Profile.simultaneous_use`
- `NAS.type`

Destination :

- table `radgroupreply`

Le profil est represente par `groupname = Profile.name`.

Reference :

- [api/profiles/create_profile.php#L49](/var/www/html/api/profiles/create_profile.php#L49)
- [includes/radius_sync.php#L36](/var/www/html/includes/radius_sync.php#L36)

### 2. Attributs de base

Mapping observe :

```text
Profile.session_timeout  -> 'Session-Timeout'
Profile.idle_timeout     -> 'Idle-Timeout'
Profile.simultaneous_use -> 'Simultaneous-Use'
Profile.data_quota_mb    -> 'Max-Octets'
```

Transformation particuliere :

- `data_quota_mb` est converti en octets avec `* 1024 * 1024`

Reference :

- [includes/radius_sync.php#L44](/var/www/html/includes/radius_sync.php#L44)

### 3. Limitation de debit

Le mapping depend du type de NAS.

#### Cas `nas.type = 'mikrotik'`

```text
Profile.rate_limit -> 'Mikrotik-Rate-Limit'
```

#### Cas autre

Le `rate_limit` doit etre fourni sous la forme `download/upload`.

Exemple :

```text
2M/2M
```

Transformation observee :

- `2M` -> `2000000`
- `2M` -> `2000000`

Mapping destination :

```text
download -> 'WISPr-Bandwidth-Max-Down'
upload   -> 'WISPr-Bandwidth-Max-Up'
```

Reference :

- [includes/radius_sync.php#L22](/var/www/html/includes/radius_sync.php#L22)

Observation critique :

- `explode('/', $rateLimit)` ne verifie pas la forme reelle de la chaine
- un format invalide peut provoquer des erreurs ou un mapping incomplet

## Mapping Session -> FreeRADIUS Accounting

Source :

- table `radacct`

Transformation effectuee par [api/users/get_user_sessions.php](/var/www/html/api/users/get_user_sessions.php) :

```text
acctstarttime                -> start
acctstoptime                 -> stop
acctsessiontime              -> duration
framedipaddress              -> ip
callingstationid             -> mac
nasipaddress                 -> nas
acctinputoctets + output     -> data_mb
```

Calcul :

```text
data_mb = round((acctinputoctets + acctoutputoctets) / 1024 / 1024, 2)
```

Detection online :

- si `acctstoptime` est vide, la session est consideree comme active

## Mapping NAS -> FreeRADIUS

Source :

- table `nas`

Usage observe :

- chargement de la liste des NAS dans [api/nas.php](/var/www/html/api/nas.php)
- lecture du champ `type` dans [api/profiles/create_profile.php](/var/www/html/api/profiles/create_profile.php)

Champs utilises dans le code :

```text
id
nasname
shortname
type
```

## Transformations De Donnees

### Conversion de debit

Fonction :

- [includes/radius_sync.php#L12](/var/www/html/includes/radius_sync.php#L12)

Regles observees :

- `M` -> multiplier par `1000000`
- `K` -> multiplier par `1000`
- sinon cast `int`

Exemples :

- `2M` -> `2000000`
- `512K` -> `512000`

Limite :

- les valeurs `G`, `bps`, espaces ou formats atypiques ne sont pas gerees explicitement

### Conversion de quota data

Profil :

- `data_quota_mb` -> `Max-Octets`
- conversion MB -> octets

Utilisateur :

- `data_limit` -> `Max-Data`
- pas de conversion visible dans le code

Incoherence :

- le projet a deux strategies differentes pour deux objets proches

## Incoherences A Connaitre

- le modele profil pousse plutot vers `radgroupreply`
- le modele utilisateur pousse plutot vers `radreply`
- certaines contraintes identiques n'utilisent pas le meme attribut RADIUS selon contexte
- `session_timeout` est present pour les profils mais absent du mapping utilisateur
- des traces MikroTik restent actives dans le mapping utilisateur
- une trace MikroTik reste aussi presente dans les donnees de configuration de devices via [config/opnsense.json](/var/www/html/config/opnsense.json)

## Conclusion

Le projet repose sur un mapping direct entre les objets applicatifs et FreeRADIUS, sans couche d'abstraction intermediaire. Ce choix simplifie les ecritures SQL mais augmente fortement le couplage et les risques de regression. Toute modification future doit conserver la coherence entre `users`, `profiles`, `radcheck`, `radreply`, `radusergroup`, `radgroupreply` et `radacct`.
