# OPNsense Traffic Shaper Mapping

## Objectif

Definir une strategie de limitation de debit pour la branche `OPNsense + FreeRADIUS`
sans modifier le code OPNsense, en s appuyant uniquement sur les mecanismes natifs deja exposes.

## Constat

- le portail captif OPNsense authentifie correctement via `FreeRADIUS`
- apres authentification, OPNsense applique nativement la restriction de temps de session
- le portail captif ne semble pas consommer les attributs `WISPr-Bandwidth-Max-Up` / `WISPr-Bandwidth-Max-Down`
- le `rate_limit` ne peut donc pas etre considere comme applique reellement par le seul canal RADIUS

References utiles :

- [Radius.php](/var/www/html/docs/mvc/app/library/OPNsense/Auth/Radius.php)
- [AccessController.php](/var/www/html/docs/mvc/app/controllers/OPNsense/CaptivePortal/Api/AccessController.php)
- [SettingsController.php](/var/www/html/docs/mvc/app/controllers/OPNsense/TrafficShaper/Api/SettingsController.php)
- [ServiceController.php](/var/www/html/docs/mvc/app/controllers/OPNsense/TrafficShaper/Api/ServiceController.php)
- [TrafficShaper.xml](/var/www/html/docs/mvc/app/models/OPNsense/TrafficShaper/TrafficShaper.xml)

## Principe retenu

Le debit doit etre gere par profil, pas par IP libre.

Raisons :

- cohérence avec notre modele metier `profiles.rate_limit`
- economie de ressources
- maintenance plus simple
- moins de logique volatile par session
- meilleure compatibilite avec la strategie deja retenue : ne pas modifier OPNsense

## Capacites natives disponibles

### 1. Pipes

API :

- `GET /api/trafficshaper/settings/search_pipes`
- `GET /api/trafficshaper/settings/get_pipe/{uuid}`
- `POST /api/trafficshaper/settings/add_pipe`
- `POST /api/trafficshaper/settings/set_pipe/{uuid}`
- `POST /api/trafficshaper/settings/del_pipe/{uuid}`
- `POST /api/trafficshaper/settings/toggle_pipe/{uuid}`

Champs utiles :

- `number`
- `enabled`
- `bandwidth`
- `bandwidthMetric`
- `mask`
- `scheduler`
- `description`
- `origin`

Point important :

- `mask` peut rester a `none` si on veut des pipes fixes par profil
- les pipes sont les meilleurs candidats pour porter le debit de base d un profil

### 2. Queues

API :

- `GET /api/trafficshaper/settings/search_queues`
- `GET /api/trafficshaper/settings/get_queue/{uuid}`
- `POST /api/trafficshaper/settings/add_queue`
- `POST /api/trafficshaper/settings/set_queue/{uuid}`
- `POST /api/trafficshaper/settings/del_queue/{uuid}`
- `POST /api/trafficshaper/settings/toggle_queue/{uuid}`

Champs utiles :

- `number`
- `enabled`
- `pipe`
- `weight`
- `mask`
- `description`
- `origin`

Point important :

- une queue est rattachee a un pipe
- elle permet de prioriser ou d organiser la repartition, mais le debit principal est d abord porte par le pipe

### 3. Rules

API :

- `GET /api/trafficshaper/settings/search_rules`
- `GET /api/trafficshaper/settings/get_rule/{uuid}`
- `POST /api/trafficshaper/settings/add_rule`
- `POST /api/trafficshaper/settings/set_rule/{uuid}`
- `POST /api/trafficshaper/settings/del_rule/{uuid}`
- `POST /api/trafficshaper/settings/toggle_rule/{uuid}`

Champs utiles :

- `interface`
- `proto`
- `source`
- `destination`
- `direction`
- `target`
- `description`
- `origin`

Point important :

- une regle rattache le trafic a un `pipe` ou une `queue`
- c est la piece de liaison a trouver entre un client/session captive portal et un profil de shaping deja cree

### 4. Reconfigure / statistiques

API :

- `POST /api/trafficshaper/service/reconfigure`
- `POST /api/trafficshaper/service/flushreload`
- `GET /api/trafficshaper/service/statistics`

Usage :

- recharger la configuration apres creation/mise a jour
- verifier l activite des pipes/queues et des rules

## Strategie recommandee

### A. Source de verite

- `profiles.rate_limit` reste la source de verite metier

### B. Mapping OPNsense

Pour chaque profil local :

- un pipe `download`
- un pipe `upload`
- eventuellement une queue descriptive
- une convention de nommage stable, par exemple :
  - `PROFILE:<nom>:DOWN`
  - `PROFILE:<nom>:UP`

### C. Ce qu on ne fait pas

- pas de patch du code OPNsense
- pas d interpretation custom du portail captif
- pas de shaping libre par IP hors mecanisme natif

## Question technique restante

Le point a resoudre n est plus le debit lui-meme, mais le rattachement d une session captive portal a une regle de shaping native.

Il faut verifier quel mecanisme natif peut servir de pont :

- regle firewall/shaper ciblee sur l adresse du client autorise
- ou objet dynamique natif `mask` exploitable sans multiplication couteuse

## Lecture actuelle des mecanismes natifs

### Option 1. Pipes dynamiques avec `mask`

Le modele OPNsense permet :

- `pipe.mask = src-ip` ou `dst-ip`
- `queue.mask = src-ip` ou `dst-ip`

Effet attendu :

- un pipe ou une queue peut se dupliquer dynamiquement par adresse
- chaque adresse obtient alors sa propre instance logique sous le meme debit de base

Interet :

- pas besoin de creer manuellement un objet par utilisateur

Limite :

- cela reste un mecanisme dynamique par IP
- il faut quand meme une regle qui envoie le trafic dans ce pipe
- ce n est pas la lecture la plus "profil d abord" si l objectif est de garder un nombre d objets tres stable

### Option 2. Pipes fixes par profil + rules de ciblage

Le modele OPNsense permet aussi :

- des `pipes` fixes avec `mask = none`
- des `rules` qui ciblent un `pipe` ou une `queue`
- une regle avec :
  - `interface`
  - `source`
  - `destination`
  - `direction`
  - `target`

Interet :

- debit stable par profil
- inventaire d objets plus petit et plus lisible
- plus proche de la logique metier : un profil = un debit = un pipe

Limite :

- il faut trouver le bon ciblage de regle pour le trafic des clients captifs autorises
- si ce ciblage oblige a injecter une IP par client dans les rules, on retombe sur une logique plus volatile

## Recommandation actuelle

La voie la plus sobre a privilegier est :

### `profil local -> pipes fixes OPNsense`

Par exemple :

- un pipe `PROFILE:<nom>:DOWN`
- un pipe `PROFILE:<nom>:UP`
- `mask = none`

Puis :

- utiliser les `rules` natives seulement pour orienter le trafic vers ces pipes
- n utiliser `mask = src-ip/dst-ip` qu en second choix si la liaison captive portal -> rule fixe n est pas suffisante

## Pourquoi cette recommandation

- le debit est deja defini au niveau du profil
- cela economise davantage de ressources que des creations volatiles par utilisateur
- la maintenance est plus simple
- les objets `pipes` deviennent une projection directe de notre table `profiles`

## Point critique restant

Il faut encore verifier, dans une prochaine passe pratique, quel ciblage de `rule` native est le plus compatible avec le trafic captive portal :

- interface captive seule
- source client captive
- destination any

## POC valide

Le test manuel valide sur `test3` a montre que le bon ciblage natif est :

- `DOWN`
  - `interface = lan`
  - `direction = out`
  - `source = any`
  - `destination = IP client`
- `UP`
  - `interface = lan`
  - `direction = in`
  - `source = IP client`
  - `destination = any`

Resultat mesure :

- `down ~= 4.85 Mbps` pour un profil `5 Mbps`
- `up ~= 0.89 Mbps` pour un profil `1 Mbps`

## Etat applique dans le projet

L automatisation retenue est maintenant la suivante :

- pipes stables par profil
- regles volatiles par session active
- resynchronisation via cron applicatif

Objets applicatifs utilises :

- [opnsense_shaper.php](/var/www/html/includes/opnsense_shaper.php)
- [sync_opnsense_shaper.php](/var/www/html/api/users/sync_opnsense_shaper.php)
- [sync_opnsense_sessions.php](/var/www/html/scripts/sync_opnsense_sessions.php)
- [run_opnsense_session_sync.sh](/var/www/html/scripts/run_opnsense_session_sync.sh)

## IP Bindings OPNsense

Le module `IP Bindings` cote OPNsense n implemente pas un clone complet du binding MikroTik.

Ce qui est valide actuellement :

- mode `bypassed` uniquement
- stockage dans la zone captive native
- prise en charge des deux listes OPNsense :
  - `allowedAddresses`
  - `allowedMACAddresses`

Comportement retenu :

- si la saisie est une IP ou un reseau CIDR -> `allowedAddresses`
- si la saisie est une MAC -> `allowedMACAddresses`

References :

- [opnsense_ip_bindings.php](/var/www/html/includes/opnsense_ip_bindings.php)
- [ip_bindings.php](/var/www/html/pages/ip_bindings.php)
- [add_ip_binding.php](/var/www/html/pages/add_ip_binding.php)

Important :

- `blocked` n est pas encore branche cote OPNsense
- `regular` n est pas encore branche cote OPNsense
- aucun patch du code source OPNsense n a ete applique

## DHCP Leases OPNsense

Le constat initial sur `Kea` etait faux pour l instance de reference :

- `Kea DHCPv4` est desactive
- les baux visibles proviennent de `Dnsmasq`

La page [dhcp_leases.php](/var/www/html/pages/dhcp_leases.php) lit donc maintenant :

- `GET /api/dnsmasq/leases/search`

Adaptateur applique :

- [opnsense_dhcp_leases.php](/var/www/html/includes/opnsense_dhcp_leases.php)

Strategie UI retenue :

- meme table visuelle que MikroTik
- colonnes non fournies par OPNsense remplies avec `N/A`
- aucune action destructive exposee cote OPNsense sur cette page

Conclusion :

- le `rate_limit` est bien applicable sur OPNsense sans modifier son code
- il faut passer par `Traffic Shaper`, pas par les attributs `WISPr` seuls

## Automatisation retenue

Le mecanisme automatique retenu est hybride :

### 1. Sync immediate apres action metier

Apres une action qui change l etat d un utilisateur OPNsense, notre application tente une sync immediate du shaper :

- creation utilisateur
- mise a jour utilisateur
- changement de profil

Cette sync :

- lit la session captive active si elle existe
- cree ou met a jour les pipes `PROFILE:<profil>:DOWN/UP`
- cree ou met a jour les rules `SESSION:<user>:DOWN/UP`
- supprime les anciennes rules de session qui pointent encore sur la meme IP avec un autre utilisateur

Important :

- si l utilisateur n a pas encore de session captive active, la creation/mise a jour ne doit pas echouer
- la sync shaper devient alors un `best effort`

### 2. Reconcileur periodique

Un script de rattrapage peut etre lance en cron :

- [sync_opnsense_sessions.php](/var/www/html/scripts/sync_opnsense_sessions.php)

Role :

- relire les sessions captives actives
- resynchroniser leurs rules `SESSION:*`
- supprimer les rules de sessions devenues orphelines

Exemple :

```bash
php /var/www/html/scripts/sync_opnsense_sessions.php --interface=lan
```

## Fichiers de reference

- [opnsense_shaper.php](/var/www/html/includes/opnsense_shaper.php)
- [sync_opnsense_shaper.php](/var/www/html/api/users/sync_opnsense_shaper.php)
- [create_user.php](/var/www/html/api/users/create_user.php)
- [update_user.php](/var/www/html/api/users/update_user.php)
- [sync_opnsense_sessions.php](/var/www/html/scripts/sync_opnsense_sessions.php)
- [run_opnsense_session_sync.sh](/var/www/html/scripts/run_opnsense_session_sync.sh)

## Cron recommande

Le point sensible restant est le changement rapide de compte sur un meme appareil avec la meme IP captive.

Comme nous ne modifions pas OPNsense, le rattrapage doit etre fait par cron frequent.

### Option recommandee

Deux passages par minute :

```cron
* * * * * /var/www/html/scripts/run_opnsense_session_sync.sh >/dev/null 2>&1
* * * * * sleep 30; /var/www/html/scripts/run_opnsense_session_sync.sh >/dev/null 2>&1
```

### Option plus legere

Un passage par minute :

```cron
* * * * * /var/www/html/scripts/run_opnsense_session_sync.sh >/dev/null 2>&1
```

### Lecture pratique

- `create_user` et `update_user` poussent deja une sync immediate quand c est possible
- le cron sert de rattrapage pour :
  - changement de compte sur le portail captif
  - changement d IP de session
  - nettoyage des rules `SESSION:*` devenues obsoletes
- direction in/out ou both

La decision finale doit minimiser :

- le nombre de rules
- la volatilite des modifications
- les reconfigurations frequentes du shaper

## POC valide du 29 mars 2026

### Utilisateur de test

- `username` : `test3`
- `profile` : `01 heure`
- `rate_limit` profil : `1M/5M`
- session captive observee :
  - `ipAddress` : `10.10.11.25`
  - `authenticated_via` : `CT-101-FreeRadius`

### Pipes crees

- `TEST3-DOWN`
  - `bandwidth = 5`
  - `bandwidthMetric = Mbit`
  - `mask = none`
- `TEST3-UP`
  - `bandwidth = 1`
  - `bandwidthMetric = Mbit`
  - `mask = none`

### Rules validees

#### Download

- `interface = lan`
- `proto = ip`
- `source = any`
- `destination = 10.10.11.25`
- `direction = out`
- `target = TEST3-DOWN`

#### Upload

- `interface = lan`
- `proto = ip`
- `source = 10.10.11.25`
- `destination = any`
- `direction = in`
- `target = TEST3-UP`

### Resultat mesure

- `download` : `4.85 Mbps`
- `upload` : `0.89 Mbps`

Conclusion :

- le shaping OPNsense est valide dans ce schema
- la limitation de debit ne doit pas passer uniquement par les attributs `WISPr`
- la combinaison native qui fonctionne est :
  - `download = LAN / out / destination = IP client`
  - `upload = LAN / in / source = IP client`

## Consequence architecturale

Le chemin cible devient maintenant concret :

1. auth via `FreeRADIUS`
2. recuperation de l `ipAddress` via les sessions captive portal
3. projection du `profile.rate_limit` en `pipes` OPNsense
4. creation / mise a jour des `rules` associees a la session

Cette approche permet de rester :

- sans patch OPNsense
- basee sur les mecanismes natifs
- coherente avec un pilotage par profil

## Ordre de travail recommande

1. normaliser le mapping `profil local -> objets shaper OPNsense`
2. creer les pipes/queues necessaires via les APIs natives
3. verifier comment associer une session autorisee a ces objets sans modifier OPNsense
4. seulement apres, automatiser depuis notre projet

## Conclusion

La limitation de debit cote OPNsense doit etre pensee comme :

`profil metier local -> traffic shaper natif OPNsense`

et non comme :

`profil metier local -> attributs WISPr RADIUS -> shaping automatique`

Cette deuxieme voie n est pas suffisante dans l etat actuel du portail captif OPNsense.
