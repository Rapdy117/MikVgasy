# Regles Metier, Architecture Et Contraintes (Canonique)

## Objectif

Ce document est la reference unique (canonique). Il formalise :

- les regles metier deduites du code actuel, sans inventer de logique absente du projet
- les contraintes d architecture et de chaine de donnees
- les regles strictes de modification (pour eviter les duplications de verite)

---

## Principe Fondamental

### Chaine Unique De Donnees

Toute donnee doit suivre strictement cette chaine :

OFFRE (profiles)
↓
ETAT UTILISATEUR (users)
↓
PROJECTION TECHNIQUE (MikroTik / RADIUS)
↓
OBSERVATION (sessions / usage)
↓
HISTORIQUE / FACTURATION

Aucun element ne doit court-circuiter cette chaine.

### Regle Absolue

Un attribut = une seule source de verite.

- toute autre presence = projection, cache ou historique
- aucune logique ne doit redefinir un attribut a partir d une source secondaire
- aucun fallback metier n est autorise
- aucun merge entre deux sources pour "completer" une verite n est autorise
- si une source est la verite, toute autre source devient non autoritaire pour ce flux

---

## Regles D Architecture Transverses

### Separation Des Concepts

- profiles = definition commerciale (offre)
- users = etat metier utilisateur (et overrides documentes)
- backend = projection technique (application d un etat final)
- sessions = observation reelle (lecture seule)
- history = traçabilite / facturation

### Interdictions Critiques

- ne jamais melanger observation (acctsessiontime, octets consommes, etc.) et configuration (limites)
- ne jamais deduire une verite metier depuis une source d observation sans regle explicite documentee
- ne jamais confondre `session_timeout` (Time Limit) et `validity_time` (Validite commerciale)
- ne jamais introduire un fallback fonctionnel entre deux sources de donnees pour "securiser" un affichage ou une action
- ne jamais fusionner une source technique et une source metier dans une meme decision
- ne jamais "completer" une source de verite par une autre source secondaire
- ne jamais utiliser `docs/` comme source runtime pour du code PHP, du JavaScript, du CSS, une librairie vendor ou un template applique en production
- ne jamais laisser coexister un ancien chemin runtime et un nouveau chemin runtime comme solution finale

### Frontiere Reference / Runtime

`docs/` est un espace de reference documentaire uniquement.

Cela implique :

- tout fichier charge en execution doit vivre hors de `docs/`
- toute librairie vendor utilisee par le projet doit vivre dans un emplacement applicatif stable dedie
- toute page, API ou include qui depend encore de `docs/` en runtime est consideree comme non alignee
- un fichier archive, template extrait ou copie de reference ne doit jamais devenir une dependance applicative implicite

### Resolution Backend Canonique

La resolution backend suit obligatoirement cette chaine :

`nas_id`
↓
`nas.type`
↓
backend logique
↓
`device` associe si une execution technique est necessaire

Contraintes :

- une action metier ne doit jamais partir du seul `device` actif en session
- `device.type` ne choisit pas le backend metier ; il execute seulement la connexion technique correspondante
- les anciens types NAS `ubiquiti`, `tplink`, `tenda` sont normalises vers le backend canonique `radius`
- aucun flux API metier ne doit renvoyer `ubiquiti`, `tplink` ou `tenda` comme backend final
- le `device` actif en session peut servir d affichage, de monitoring ou de support technique, jamais de source metier unique
- toute logique qui choisit entre `mikrotik`, `opnsense` et `radius` sans passer par `nas.type` est consideree comme une divergence d architecture
- aucun fallback de resolution n est autorise apres choix de la source canonique
- aucune page, API ou include ne doit resoudre un backend en mergeant plusieurs sources concurrentes

### Regle De Resilience Runtime

Une telemetrie, une sonde, un widget live ou un panneau de monitoring ne doit jamais casser toute la page si sa source externe est indisponible.

Cela implique :

- les APIs de telemetry doivent preferer un etat degrade documente a un `HTTP 500` global
- une erreur de metrique live ne doit pas faire tomber tout le dashboard ou tout l ecran
- les erreurs critiques bloquantes restent reservees aux operations metier d ecriture, de suppression ou d incoherence de donnees
- un message de degradation ne devient pas une nouvelle source de verite ; il ne remplace pas les donnees metier

### Regle De Refactor Final

Un refactor n est considere comme termine que si l ancienne voie concurrente a ete retiree.

Concretement :

- un fallback temporaire est autorise seulement comme etape de transition explicite
- un fallback temporaire doit etre supprime dans le refactor final
- garder `ancien + nouveau + compatibilite` n est pas une correction durable
- si deux chemins runtime existent encore pour la meme responsabilite, le refactor est incomplet
- supprimer la duplication prime sur la compatibilite historique interne quand les deux entrent en conflit
- un refactor qui remplace une source de verite par un fallback, un merge ou une voie secondaire est invalide
- une regression "toleree" par fallback n est pas une correction ; c est une duplication

---

## Regles Par Backend

### MikroTik

Source de verite :

- routeur cible : `device_id` actif/selectionne dans `network_devices.php`
- identite routeur : `name`, `type`, `host/ip`, `api_key`, `api_secret`, `verify_ssl` du `device`
- utilisateur : `/ip hotspot user`
- profil : `/ip hotspot user profile`
- usage : `/ip hotspot active` (observation)
- evenements : `/log/print` (log principal)

Resolution du routeur :

- toute lecture ou ecriture MikroTik doit d abord resoudre le routeur exact par `device_id`
- `host/ip` du `device` est la cible de connexion API et la reference de correspondance avec `nas`
- `type = mikrotik` ne suffit jamais a designer un routeur lorsqu il existe plusieurs routeurs MikroTik
- il est interdit de basculer ensuite vers un "MikroTik quelconque", un fallback par type, ou un routeur implicite
- un filtre ou une action MikroTik doit etre rattache a un routeur precis, donc a `device_id` ou a son `host/ip`
- `name` sert uniquement a l identification humaine dans l interface ; il n est pas une cle technique de routage
- `api_key` et `api_secret` du `device` sont la seule source de verite pour la securite API du routeur cible

DB :

- uniquement cache et metier (historique, facturation)
- ne doit jamais redefinir ces attributs (ils viennent du routeur) :
  - time limit (limit-uptime)
  - data limit (limit-bytes-total)
  - expiration (comment / champ routeur)
  - rate limit (profil MikroTik)
- ne doit pas creer de profil MikroTik local en DB si le profil n existe pas sur le routeur
- ne pas inventer des scripts pour injecter des attributs additionnels : utiliser uniquement ce que le routeur fournit
- il est interdit de completer un profil ou un utilisateur MikroTik avec une source locale "de secours"
- il est interdit d afficher des donnees MikroTik en mergeant routeur + DB pour une meme verite metier

UI :

- doit lire uniquement MikroTik pour les attributs ci-dessus (pas de `users.*` comme source de verite en mode MikroTik)
- doit afficher et utiliser le routeur exact selectionne, pas seulement le type `MikroTik`
- si une information n existe pas sur MikroTik, l interface doit l assumer explicitement ; elle ne doit pas inventer une valeur depuis une autre source

Telemetrie live :

- ne pas ouvrir une nouvelle connexion API MikroTik par widget, bloc ou appel AJAX concurrent
- privilegier une collecte mutualisee cote serveur
- les pages doivent consommer un cache local partage a duree courte pour la telemetrie live
- limiter les connexions API directes au routeur aux actions d ecriture ou aux lectures non couvertes par le collecteur
- objectif explicite : reduire la charge CPU routeur et eviter les logs repetes `user admin logged in ... via api` / `logged out ... via api`
- le collecteur ou le cache doit rester rattache au routeur exact (`device_id` / `host/ip`) et ne jamais fusionner plusieurs MikroTik sous une meme cle de type

### OPNsense / RADIUS

Source de verite :

- profils : `profiles`
- utilisateurs : `users` (etat metier)
- credit courant : `users.current_credit_time`, `users.current_credit_data`
- attributs techniques : `radcheck`, `radreply`, `radusergroup` (projection)

Projection :

- DB → RADIUS (sync)

UI :

- doit lire DB (et `radacct` en observation lecture seule)
- "Actuel" = `current_credit_*` moins consommation `radacct`
- il est interdit de completer une verite SQL par un fallback device si la DB est la source canonique du flux

---

## Regle Transverse Anti-Fallback

Cette regle est absolue et vaut pour tout le projet :

- une page, une API, un include, un service = une seule source de verite par flux
- aucun fallback de donnees
- aucun merge de donnees
- aucune logique parallele
- aucun remplissage "par defaut" pour masquer l absence de la vraie source

Concretement :

- si le flux est `MikroTik`, la verite vient du routeur MikroTik vise
- si le flux est `OPNsense / RADIUS`, la verite vient de la base SQL/RADIUS definie pour ce flux
- si la source canonique ne fournit pas une information, il faut corriger le flux ou afficher clairement son absence
- il est interdit de "recoller" l affichage ou le comportement avec une autre source

---

## Matrice Des Attributs Critiques

| Attribut             | Role                          | Source MikroTik       | Source RADIUS                    | Interdit                                 |
| ------------------- | ----------------------------- | --------------------- | -------------------------------- | ---------------------------------------- |
| rate_limit           | debit                         | profil MikroTik       | profiles (ou radreply)           | users (comme "verite")                   |
| current_credit_time  | credit temps restant          | N/A                   | users.current_credit_time        | session_timeout, radacct (comme "verite") |
| current_credit_data  | credit data restant           | N/A                   | users.current_credit_data        | data_limit, radacct (comme "verite")      |
| session_timeout      | limite session (projection)   | user MikroTik         | users / radreply (projection)    | verite metier                            |
| validity_time        | duree commerciale             | N/A (profil local)    | profiles                         | user (comme "verite commerciale")        |
| expiration_date      | date expiration               | comment MikroTik      | users                            | calcul implicite non trace               |
| data_limit           | quota utilisateur (projection)| user MikroTik         | users / radreply (projection)    | verite metier                            |
| data_quota_mb        | quota offre                   | profil MikroTik       | profiles                         | user                                    |
| status               | etat                          | derive (disabled/etc) | users                            | libre                                   |

Notes :

- "users" peut porter des champs d override (ex: `users.session_timeout`, `users.data_limit`) uniquement si c est documente comme override.
- une valeur d override a `NULL` signifie "heritage du profil" (autorise). Ce n est pas une valeur metier par defaut.
- en mode RADIUS/OPNsense, l "Actuel" ne lit plus `session_timeout` / `data_limit` : il utilise le credit courant moins la consommation.

---

## Regles D'Acces

- toute page interne demarre une session PHP et exige `$_SESSION['logged_in'] = true`
- la connexion administrateur actuelle passe par [api_proxy.php](/var/www/html/api_proxy.php)
- la deconnexion passe par `index.php?logout=true`

## CSS — theme et feuilles par page

- `css/theme.css` : feuille **globale** (variables, cartes, boutons, tableaux, et styles **partages** des formulaires : `.network-device-form` avec `#userContent` / `.radius-form` lorsque les memes motifs s appliquent a plusieurs ecrans).
- `css/add_hostpot_user.css` : styles **specifiques** a la page Ajouter utilisateur Hotspot (`pages/add_hotspot_user.php`) : guides `.guide-content`, segments temps `.time-segment-input`, select quota `.data-limit-unit`, complements `.network-device-form` propres a cet ecran, etc.
- **Ordre de chargement** sur cette page : framework (ex. Bootstrap), puis `theme.css`, puis eventuelle CSS tierce (ex. icones), puis **`add_hostpot_user.css` en dernier** pour que les surcharges page l emportent.
- Ne pas retirer de `theme.css` le bloc de base `.network-device-form` au profit de `add_hostpot_user.css` : la classe est utilisee ailleurs (profils, peripheriques, administration, etc.). Seuls les raffinements **uniquement** utiles a `add_hotspot_user` y sont deplaces ou ajoutes.

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

### `validity_time`

Regle metier de reference :

- `validity_time: int`
- represente la duree commerciale de l offre
- sert a calculer l expiration commerciale de l abonnement
- ne doit pas etre confondu avec `session_timeout`
- `validity_time = 0` ou vide n est pas un etat normal pour une offre standard
- `validity_time` ne doit jamais etre vide sauf pour le profil `default`

### `session_timeout`

Formes observees :

- champ numerique dans l'UI profil
- champ numerique dans l'UI utilisateur

Regle metier de reference :

- `session_timeout: int`
- represente la duree maximale technique d une session
- `session_timeout = 0` -> illimite
- `session_timeout = ''` ou `null` doit etre normalise en `0`

Etat reel du code :

- applique pour les profils
- non applique pour les utilisateurs
- pas de normalisation stricte `''`, `null`, `0`

### `expiration_date`

Regle metier :

- `users.expiration_date` est la seule source de verite d expiration
- elle est calculee lors du premier login (`first_login + validity_time`)
- si `first_login` ou `validity_time` manquent, `expiration_date` reste vide
- l UI ne doit pas recalculer une expiration implicite si le champ est vide

---

## Regles Recharge (RADIUS / OPNsense)

### Changement d offre

- projeté = offre (profil, time limit, data limit)
- expiration reste vide (attente du premier login)

### Rechargement

- profil courant conserve
- time limit et data limit s ajoutent
- expiration ne change que si elle existe deja et que le compte est encore valide

### Reabonnement

- profil identique obligatoire
- time limit et data limit s ajoutent
- expiration reste vide si elle est vide ou expiree, sinon expiration actuelle + validite


### `idle_timeout`

Regle de reference :

- `idle_timeout: int`
- `idle_timeout = 0` -> pas de deconnexion pour inactivite

Etat du code :

- mappe dans les profils
- mappe dans les attributs utilisateur

### `expired_mode`

Regle metier de reference :

- `none` = aucune action automatique speciale
- `remove` = supprimer le compte a l expiration ou au depassement du quota
- `notice` = conserver le compte, mais le bloquer a l expiration
- `remove_record` = supprimer le compte et comptabiliser l evenement
- `notice_record` = conserver le compte, le bloquer et comptabiliser l evenement
- `expired_mode` ne doit jamais etre vide sauf pour le profil `default`
- `expired_mode = none` est une valeur acceptable uniquement comme valeur canonique explicite
- l alignement fonctionnel final du profil `default` reste a traiter a part, sans fallback implicite

Etat reel du code :

- `MikroTik` : mapping natif Mikhmon conserve cote RouterOS (`rem`, `ntf`, `remc`, `ntfc`)
- `OPNsense + RADIUS` : moteur automatique disponible via [sync_opnsense_sessions.php](/var/www/html/scripts/sync_opnsense_sessions.php)
- `RADIUS` pur : pas encore de reconcileur transverse dedie

### `expiration_date`

Regle observee :

- optionnelle
- stockee comme date/heure applicative
- poussee en attribut RADIUS `Expiration` pour les utilisateurs

## Regles Sur Les Quotas Et Debits

### `data_limit` / `data_quota_mb`

Regle de reference :

- unite fonctionnelle attendue : MB
- `data_quota_mb` = quota par defaut de l offre
- `data_limit` = quota effectivement applique a l utilisateur
- `0` -> illimite
- `data_quota_mb = ''` ou `null` doit etre normalise en `0`
- `data_limit = ''` ou `null` doit etre normalise en `0`

Etat du code :

- profil : conversion explicite vers octets
- utilisateur : pas de conversion uniforme visible
- branche MikroTik (`mikrotikProfileDataQuotaMb`) : quota lu depuis `limit-bytes-total` sur le profil RouterOS ; si vide ou nul, repli sur `data_quota_mb` parse depuis `on-login` (metadata Mikhmon)

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
