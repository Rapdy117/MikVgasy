# Etat Des 3 Types Et Chantiers D'Alignement

## Objectif

Ce document donne une vue transverse de l'etat reel des trois types supportes par le projet :

- `mikrotik`
- `radius`
- `opnsense`

Il sert de reference de pilotage pour :

- l'archivage
- le nettoyage global
- l'alignement des pages et des APIs

Date de reference :

- 30 mars 2026

**Avancement documente (recharge / avril 2026) :**

- Resolution profil : **profile_name** obligatoire (MikroTik), **profile_id** obligatoire (base locale) ; plus de champ `profile_value` dans le formulaire.
- **Preview** et **apply** partagent la meme logique metier (`buildRechargePreview` / `simulate`) ; historiques SQL et `operation_history` en **echec non bloquant** sur `apply_recharge` (logs serveur).
- UI recharge : **debounce** et **cache par signature** cote JS pour limiter les appels `recharge_preview` / `recharge_history`.

## Lecture Rapide

### MikroTik

- niveau actuel : avance
- statut : exploitable sur les flux principaux
- reserve : quelques restes de nommage, de structure et quelques chargements partiels

### RADIUS

- niveau actuel : intermediaire a solide
- statut : bon sur la base metier et la synchro
- reserve : moins abouti que MikroTik sur les flux hotspot "temps reel" et recharge appliquee
- note recente : le chemin `profil -> utilisateur -> attributs WISPr` est confirme, avec correction de l ordre `upload/download` dans la synchro de `rate_limit`

### OPNsense

- niveau actuel : intermediaire a solide
- statut : bon pour la supervision, les sessions, le shaping et plusieurs integrations natives
- reserve : encore hybride sur certains modules reseau et sur une partie du metier hotspot
- decision actuelle : rester sur `OPNsense + FreeRADIUS` plutot que forcer un decouplage premature

## Matrice Fonctionnelle

| Domaine | MikroTik | RADIUS | OPNsense |
| --- | --- | --- | --- |
| Gestion device | OK | Partiel | OK |
| Dashboard / stats | OK | Partiel | OK |
| Creation utilisateur | OK | OK | Partiel |
| Mise a jour utilisateur | OK | OK | Partiel |
| Suppression utilisateur | OK | OK | Partiel |
| Profils | OK | OK | Partiel |
| Recharge preview | OK | Partiel | Partiel |
| Recharge appliquee | OK | Non aligne | Non aligne |
| Vouchers / recouvrement | OK | OK via couche commerciale | OK si branche radius, pas natif |
| Sessions actives | OK | OK via `radacct` | OK |
| Actions live | OK | Limite | Intermediaire |

Lecture complementaire :

- pour `OPNsense`, la creation et la mise a jour utilisateur passent bien par la branche `radius`
- le schema local `profiles` a ete enrichi pour persister les champs avances deja utilises par le formulaire
- en revanche, la couche de consultation utilisateur reste encore trop marquee `MikroTik` sur plusieurs ecrans
- un test fonctionnel `rate limit` sur OPNsense ne doit etre considere comme fiable qu apres alignement du bloc `users`

## Etat Par Type

### 1. MikroTik

Points forts observes :

- provisioning utilisateur branche
- edition utilisateur branche
- suppression utilisateur branche
- sessions actives branchees
- recharge appliquee disponible
- vouchers et recouvrement utilises en reel
- objets live RouterOS deja exploites :
  - users hotspot
  - sessions actives
  - profils
  - DHCP leases
  - IP bindings
  - scheduler
  - cookies

Points de nettoyage restants :

- le plus gros du nommage trompeur visible a ete nettoye dans les pages et APIs actives
- le vrai point critique restant est la resolution exacte du routeur quand plusieurs MikroTik existent
- la branche MikroTik doit maintenant etre consideree comme 100% pilotee par le `device` selectionne :
  - `name` pour l identification UI
  - `type` pour la regle metier et l API a utiliser
  - `host/ip` pour la cible API et la correspondance NAS
  - `api_key` / `api_secret` pour la securite API
- le dashboard expose maintenant des cles neutres `device_*`, avec alias `opnsense_*` conserves temporairement pour compatibilite
- `Derniers Evenements` sur le dashboard MikroTik lit desormais le log principal brut `/log/print`
- l affichage volontairement brut sert de base de verification contre Winbox avant toute nouvelle interpretation metier
- la prochaine regle transverse a appliquer est la reduction des connexions API repetitives :
  - collecte mutualisee
  - cache court partage
  - limitation des connexions live par page
- l'endpoint actif de test mutualise est [test_device.php](/var/www/html/api/test_device.php), l'ancien nom n'etant plus qu'une passerelle de compatibilite
- certaines pages admettent encore un chargement partiel des options MikroTik avancees
- certaines pages ou helpers peuvent encore deriver vers un routeur implicite "par type" ; cette derive doit etre consideree comme interdite
- la couche MikroTik reste melangee par endroits avec l'heritage Mikhmon / RADIUS

Conclusion :

- MikroTik est la branche la plus aboutie
- le vrai travail restant n'est plus la faisabilite fonctionnelle
- le vrai travail restant est le nettoyage, le renommage et l'harmonisation

### 2. RADIUS

Points forts observes :

- backend metier stable via `nas.type`
- synchro des profils vers `radgroupreply`
- synchro des users vers `radcheck`, `radreply`, `radusergroup`
- historique via `radacct`
- branche logique bien centralisee via :
  - [nas_resolver.php](/var/www/html/includes/nas_resolver.php)
  - [radius_sync.php](/var/www/html/includes/radius_sync.php)
  - [user_provisioning.php](/var/www/html/includes/user_provisioning.php)

Points de nettoyage restants :

- la recharge est moins avancee que sur MikroTik
- certains flux sont encore corrects en preview mais pas encore alignes en execution reelle
- la resolution `device -> nas` reste fragile si les correspondances ne sont pas propres
- la synchro des attributs WISPr est bonne cote application / FreeRADIUS
- mais le captive portal OPNsense actuellement branche ne semble consommer que `Session-Timeout` pour les restrictions de session
- en l etat, `rate_limit` ne peut pas etre considere comme applique reellement cote OPNsense sans integration explicite avec le traffic shaper

Conclusion :

- la branche RADIUS est structurellement bonne
- elle a besoin d'un alignement fonctionnel plus strict sur les flux commerciaux

Regle d architecture retenue :

- `MikroTik` reste une branche autonome de type `Mikhmon`
- `RADIUS` et `OPNsense` restent pilotes par la base metier locale
- les montants commerciaux des profils doivent entrer dans le cycle des operations des la creation et la mise a jour, meme si leur exploitation complete dans les bilans reste un chantier distinct
- regle metier sur les prix :
  - `price` = montant a comptabiliser
  - `selling_price` = usage ticketing / impression seulement

### 3. OPNsense

Points forts observes :

- supervision et stats live bien presentes
- integration API deja utilisee pour :
  - dashboard
  - trafic
  - CPU
  - certaines vues systeme
- sessions captive portal live branchees
- traffic shaper valide en production applicative
- synchronisation shaper par session active branchee
- `IP Bindings` branches en mode `bypass portail`
- `DHCP leases` lus sur l instance de reference via `Dnsmasq`

Points de reserve :

- cote metier hotspot, OPNsense n'est pas encore autonome
- il est encore souvent traite comme un cas de backend `radius`
- les flux creation / recharge / profils ne sont pas encore homogenes avec MikroTik
- les ecrans `users_list.php` et `get_user_profile_details.php` restent trop hybrides pour un pilotage clair cote OPNsense
- le `Idle Timeout` observe en production peut venir du reglage de zone captive OPNsense
  - il ne provient pas forcement du profil metier local
  - sur l instance de reference, la zone captive active expose actuellement `idletimeout = 5`
- `IP Bindings` cote OPNsense ne couvrent encore que `bypassed`
- le blocage MAC natif n'est pas encore branche
- `DHCP leases` dependent de la vraie source DHCP active :
  - `Dnsmasq` sur l instance de reference
  - pas `Kea`

Conclusion :

- OPNsense existe bien dans le projet
- il est nettement plus avance qu au debut du chantier
- mais il n'est pas encore au meme niveau de maturite metier que MikroTik
- le choix prudent actuel est de consolider `OPNsense + FreeRADIUS` au lieu d ouvrir un nouveau process

## Nettoyage Global A Mener

### 1. Nommage

- supprimer les noms trompeurs herites
- exemple :
  - endpoint de test mutualise mal nomme
  - aliases `opnsense_*` encore toleres temporairement dans le dashboard

### 2. Separation Metier / Compatibilite

- distinguer explicitement :
  - ce qui est "vraie logique metier"
  - ce qui est "compatibilite ancienne UI / payload"

### 3. Alignement Des Flows

Unifier partout les cycles :

- preparer
- previsualiser
- appliquer
- imprimer
- facturer
- payer

### 4. Reduction Des Etats Partiels

- tout champ visible doit avoir une chaine complete :
  - UI
  - backend
  - persistence
  - rereading dans l'UI

### 5. Clarification Des Branches

- `mikrotik` :
  - branche d'execution locale RouterOS
- `radius` :
  - branche metier basee FreeRADIUS
- `opnsense` :
  - branche supervision/API encore a clarifier sur le metier hotspot

## Priorites De Travail

### Priorite 1

- terminer les derniers restes de nommage transverse encore legitimes a revoir
- documenter officiellement la branche MikroTik comme branche de reference la plus avancee

### Priorite 2

- aligner RADIUS sur les flux commerciaux critiques
- expliciter ce qui est reellement applique et ce qui reste preview-only

### Priorite 3

- decider si OPNsense reste un support `radius`
- ou devient une vraie branche metier de meme niveau

## Decision De Travail Recommandee

Pour les prochaines passes :

- ne plus traiter le projet page par page
- travailler par flux transverses
- archiver chaque etat important
- garder la doc comme source de verite de pilotage
