# Bugs Et Problemes Connus

## Etat Courant

Ce fichier decrit les problemes encore ouverts au 25 mars 2026.

Les anciens points historiques sur :

- l'endpoint de creation utilisateur
- la redirection post-login
- le dashboard branche sur `phpinfo()`
- l'edition utilisateur non connectee
- `profile_list.php` et `sessions_list.php` statiques

ne sont plus des problemes actifs dans l'etat actuel du code.

## Historique Des Problemes Resolus

### 2026-04-23 - Recharge/lecture MikroTik : retrait du melange local + invalidation du cache users

Fichiers impactes :

- [pages/users_list.php](/var/www/html/pages/users_list.php)
- [api/users/get_user_sessions.php](/var/www/html/api/users/get_user_sessions.php)
- [includes/mikrotik_backend.php](/var/www/html/includes/mikrotik_backend.php)
- [js/users_list.js](/var/www/html/js/users_list.js)

Probleme rencontre :

- `users_list` et son detail inline pouvaient encore ajouter une baseline locale aux cumuls MikroTik
- cela contredisait la regle metier selon laquelle MikroTik lit ses cumuls depuis `/ip/hotspot/user`
- apres `changement d offre` / `recharge`, la liste pouvait aussi rester stale le temps du cache `mikrotik_hotspot_users`, donnant l impression qu il fallait appliquer l action deux fois

Cause racine :

- coexistence d une logique locale historique (`imported_*`, `user_counter_baselines`) dans un flux qui devait rester routeur-only
- invalidation incomplete des caches apres ecriture MikroTik : le cache recharge etait invalide, mais pas le cache principal des utilisateurs hotspot

Solution appliquee :

- `pages/users_list.php` lit maintenant la consommation totale depuis `user_bytes_total` et la duree cumulee depuis `user_session_time_seconds`, sans ajout local
- `api/users/get_user_sessions.php` renvoie les cumuls MikroTik depuis le routeur uniquement, et garde `/ip/hotspot/active` pour la session en cours
- `replaceUserOfferInMikrotik()`, `extendUserOfferInMikrotik()` et `accumulateUserOfferInMikrotik()` invalident maintenant aussi le cache `mikrotik_hotspot_users`
- le texte d aide du detail utilisateur a ete realigne sur cette regle

Etat :

- resolu pour `users_list` et son detail inline
- a poursuivre sur les autres chemins historiques MikroTik encore relies au device actif implicite

### 2026-04-17 - Ecriture profil MikroTik et metadata `on-login`

Fichiers impactes :

- [api/profiles/create_profile.php](/var/www/html/api/profiles/create_profile.php)
- [includes/mikrotik_backend.php](/var/www/html/includes/mikrotik_backend.php)
- [includes/profile_catalog.php](/var/www/html/includes/profile_catalog.php)
- [api/users/profile_options.php](/var/www/html/api/users/profile_options.php)
- [pages/profile_list.php](/var/www/html/pages/profile_list.php)
- [pages/add_profile.php](/var/www/html/pages/add_profile.php)
- [pages/generate.php](/var/www/html/pages/generate.php)
- [pages/add_hotspot_user.php](/var/www/html/pages/add_hotspot_user.php)
- [js/profile_options_loader.js](/var/www/html/js/profile_options_loader.js)
- [js/generate.js](/var/www/html/js/generate.js)
- [js/add_hotspot_user.js](/var/www/html/js/add_hotspot_user.js)

Probleme rencontre :

- les pages profils ne lisaient pas toutes la meme source selon le device selectionne
- `generate.php` et `add_hotspot_user.php` pouvaient charger des profils differents de `profile_list.php`
- `add_profile.php` pouvait viser le mauvais contexte entre `device_id`, `nas_id` et device actif
- la creation d'un profil MikroTik simple pouvait echouer car le payload envoyait des champs non necessaires ou invalides pour `/ip/hotspot/user/profile`
- en modification, un mode d'expiration MikroTik sans validite pouvait produire un `on-login` vide et effacer les colonnes derivees : validite, mode d'expiration, prix, prix de vente, verrouillage et quota metadata
- la creation d'un utilisateur MikroTik pouvait afficher un succes alors que RouterOS avait refuse l'ecriture, car le retour `!trap` de `/ip/hotspot/user/add` n'etait pas verifie
- apres creation d'un utilisateur MikroTik reussie, la liste pouvait rester stale pendant la duree du cache local `mikrotik_hotspot_users`
- la creation/modification utilisateur appelait une revalidation de profil qui pouvait reecrire le profil MikroTik alors que le profil avait deja ete resolu
- dans `add_hotspot_user.php`, les limites heritees du profil etaient calculees pour les champs caches mais n'accompagnaient plus les champs visibles lors de la selection du profil

Cause racine :

- logique metier dupliquee entre pages/API pour charger les profils
- confusion entre source SQL RADIUS et source RouterOS MikroTik
- payload MikroTik trop large par rapport au fonctionnement Mikhmon
- absence de garde-fou avant l'ecriture d'un `on-login` vide
- absence de verification `!trap` sur l'ecriture utilisateur MikroTik
- absence d'invalidation du cache utilisateur MikroTik apres creation/modification/suppression
- separation incomplete entre la valeur heritee postee et les champs visibles `Time Limit` / `Data Limit` de `add_hotspot_user.php`

Solution appliquee :

- introduction d'une source commune de lecture profils par device via `includes/profile_catalog.php`
- MikroTik lit directement `/ip/hotspot/user/profile` sur le routeur cible par `device_id`
- RADIUS/OPNsense continuent de lire SQL `profiles`
- `profile_options.php`, `profile_list.php`, `add_profile.php`, `generate.php` et `add_hotspot_user.php` consomment la meme source de profils
- le JS commun `profile_options_loader.js` charge les profils avec une valeur non vide pour MikroTik et synchronise `profile_name`
- l'ecriture MikroTik n'envoie plus `limit-bytes-total` au profil RouterOS et n'envoie plus `session-timeout=0s`
- les modes d'expiration MikroTik sont normalises avant generation du script `on-login`
- une validite est maintenant requise lorsqu'un mode d'expiration MikroTik actif est demande
- le backend bloque l'ecriture plutot que d'envoyer un `on-login` vide
- la creation/modification utilisateur MikroTik n'appelle plus `ensureMikrotikProfile` et ne modifie plus le profil lors d'une action utilisateur
- les retours RouterOS `!trap` sont verifies sur creation, modification et suppression utilisateur MikroTik
- le cache `mikrotik_hotspot_users` est invalide apres creation, modification, renommage credentials ou suppression utilisateur
- `add_hotspot_user.php` remplit maintenant les champs visibles `Time Limit` et `Data Limit` avec les valeurs heritees du profil selectionne, puis synchronise les champs caches POST correspondants

Etat :

- resolu dans le code courant
- a surveiller uniquement si une nouvelle option UI ajoute des champs dans `on-login`
- ne pas reintroduire de fallback SQL pour les profils MikroTik

### 2026-04-17 - Ciblage explicite `device_id` sur endpoints utilisateurs MikroTik

Fichiers impactes :

- [api/users/update_mikrotik_user.php](/var/www/html/api/users/update_mikrotik_user.php)
- [api/users/delete_mikrotik_user.php](/var/www/html/api/users/delete_mikrotik_user.php)
- [api/users/get_user_profile_details.php](/var/www/html/api/users/get_user_profile_details.php)

Probleme rencontre :

- certaines actions utilisateurs MikroTik pouvaient encore reposer sur une connexion implicite au device actif, au lieu d un ciblage routeur explicite

Cause racine :

- absence de `device_id` obligatoire sur ces flux
- utilisation d une connexion active generique au lieu d un contexte NAS/device cible

Solution appliquee :

- `device_id` est maintenant obligatoire sur ces endpoints utilisateurs MikroTik
- le routeur cible est resolu via `loadDeviceStore()` + `findDeviceById()`, puis valide en type `mikrotik`
- la connexion MikroTik utilise un contexte explicite (`buildMikrotikContextFromDevice` + `connectToMikrotikApiForNasContext`)
- l historique d update utilisateur enregistre aussi `device_id` dans `details_json`
- suppression de la dependance au mapping NAS pour ces endpoints users MikroTik, ce qui elimine le message `Aucun NAS correspondant au device selectionne` sur ce flux

Etat :

- resolu pour ces endpoints
- a poursuivre sur les autres chemins historiques encore relies au device actif

### 2026-04-17 - `admin_notifications.php` : entetes figes et hauteur visible stabilisee

Fichier impacte :

- [pages/admin_notifications.php](/var/www/html/pages/admin_notifications.php)

Probleme rencontre :

- en scroll, les entetes de table n etaient pas figes
- la zone visible en bas de page pouvait se recalculer de facon instable selon la hauteur viewport

Cause racine :

- conteneurs de page et de carte non contraints en flex hauteur pleine
- absence de sticky header sur le tableau

Solution appliquee :

- ajout d un layout flex hauteur pleine scope sur la page notifications
- table responsive convertie en zone scrollable unique
- `thead` / `th` passes en sticky avec fond explicite pour garder la lisibilite

Etat :

- resolu sur la page notifications
- UI globale non modifiee hors scope de cette page

## 0. Compatibilite runtime recente a clarifier sur certaines pages SQL

Fichiers impactes :

- [pages/reports.php](/var/www/html/pages/reports.php)
- [docs/RUNTIME_COMPATIBILITY.md](/var/www/html/docs/RUNTIME_COMPATIBILITY.md)

Probleme :

- certaines pages historiques ont ete ecrites avec des hypotheses implicites sur PDO et MySQL
- ces hypotheses deviennent fragiles avec :
  - PHP 8.3
  - MySQL 9.1
- les sujets principaux identifies sont :
  - `execute([])` sur requete sans placeholder
  - parsing date multiple dans une meme page
  - dependance a `strftime()`

Effet :

- une page peut fonctionner sur un environnement et casser sur un autre
- les correctifs opportunistes risquent d ajouter plusieurs strategies concurrentes

Correction a envisager :

- appliquer les regles de [docs/RUNTIME_COMPATIBILITY.md](/var/www/html/docs/RUNTIME_COMPATIBILITY.md)
- reduire chaque page a une seule strategie SQL/PDO/date

## 1. Hauteur et scroll du bloc `Derniers Evenements` encore fragiles

Fichiers impactes :

- [pages/dashboard.php](/var/www/html/pages/dashboard.php)
- [css/theme.css](/var/www/html/css/theme.css)
- [js/dashboard.js](/var/www/html/js/dashboard.js)

Probleme :

- le bloc a ete plusieurs fois reajuste
- la colonne droite melange encore structure Bootstrap `row/col` et contraintes CSS de hauteur
- le tableau reste sensible aux changements de nombre de lignes visibles

Effet :

- risque de debordement visuel
- scroll pas toujours coherent selon la hauteur du viewport

Correction a envisager :

- simplifier completement la structure du panneau droit
- fixer une hauteur de carte explicite ou une grille parent stricte
- garder le scroll uniquement sur un wrapper unique

## 2. `Derniers Evenements` reste a valider sur MikroTik en conditions reelles

Fichiers impactes :

- [api/get_stats.php](/var/www/html/api/get_stats.php)
- [api/dashboard_stream.php](/var/www/html/api/dashboard_stream.php)
- [pages/dashboard.php](/var/www/html/pages/dashboard.php)

Probleme :

- la source MikroTik a ete réalignee sur le log principal `/log/print`
- l affichage est maintenant volontairement brut, sans parseur metier
- il faut encore confirmer sur routeur reel que les evenements attendus remontent tous :
  - `logged in`
  - `logged out: keepalive timeout`
  - `login failed`
  - evenements `account` type `user admin ... via api`

Effet :

- si la source MikroTik reelle diverge encore de Winbox, le dashboard peut afficher moins d evenements que prevu
- un ecart entre Winbox et l UI doit maintenant etre traite comme ecart de source ou de lecture, pas comme ecart de parseur

Correction a envisager :

- valider sur routeur reel les lignes visibles dans Winbox contre celles du dashboard
- si besoin, documenter un filtrage d affichage, mais garder le log principal comme source

## 3. Le bloc `Bilan` repose encore sur des compteurs, pas sur un vrai chiffre d'affaires

Fichiers impactes :

- [api/get_stats.php](/var/www/html/api/get_stats.php)
- [pages/dashboard.php](/var/www/html/pages/dashboard.php)

Probleme :

- `Ventes du jour` et `Vente mensuel` sont actuellement calcules a partir du nombre de vouchers utilises
- il n'existe pas encore de source prix/montant fiable dans le schema branche au dashboard

Effet :

- le bloc commercial represente un volume de ventes, pas un revenu monetise

Correction a envisager :

- brancher une table de prix/transactions
- ou mapper les montants depuis les profils/vouchers si le modele metier le permet

## 4. La page dashboard reste dependante de plusieurs appels OPNsense live

Fichiers impactes :

- [api/get_stats.php](/var/www/html/api/get_stats.php)
- [api/get_traffic_stats.php](/var/www/html/api/get_traffic_stats.php)
- [api/traffic_stream.php](/var/www/html/api/traffic_stream.php)
- [api/cpu_stream.php](/var/www/html/api/cpu_stream.php)

Probleme :

- le dashboard combine :
  - un chargement principal JSON
  - un stream trafic
  - un stream CPU
- le demarrage a ete sequence pour alleger la charge, mais l'ecran reste sensible a la latence OPNsense

Effet :

- risque de `Failed to fetch` ou de chargement percu comme lent si OPNsense repond mal

Correction a envisager :

- mettre un cache court local
- reduire encore les champs demandes au chargement principal
- ajouter une strategie de fallback plus douce cote frontend

## 5. L'abstraction NAS reste incomplete

Fichier impacte :

- [includes/nas_resolver.php](/var/www/html/includes/nas_resolver.php)

Probleme :

- le type `opnsense` reste encore route vers le backend `radius`
- il n'existe pas encore d'adaptateur OPNsense metier complet pour les operations NAS

Effet :

- architecture multi-NAS engagee mais non terminee

Correction a envisager :

- introduire un vrai backend/adaptateur OPNsense
- clarifier les responsabilites `radius` vs `opnsense`

## 6. Le schema SQL fourni reste incomplet par rapport au code

Fichier impacte :

- [config/schema.sql](/var/www/html/config/schema.sql)

Probleme :

- le code utilise toujours des tables applicatives comme `users`, `profiles`, `vouchers`, `logs`
- le schema de reference du projet reste focalise sur FreeRADIUS

Effet :

- installation incomplete si on se base uniquement sur le schema fourni

Correction a envisager :

- livrer un schema complet aligne sur l'application reelle

## 7. La gestion des devices n'est pas encore alignee sur la directive a trois types

Fichiers impactes :

- [pages/network_devices.php](/var/www/html/pages/network_devices.php)
- [js/network_device.js](/var/www/html/js/network_device.js)
- [api/network_devices_api.php](/var/www/html/api/network_devices_api.php)
- [includes/nas_resolver.php](/var/www/html/includes/nas_resolver.php)

Probleme :

- l'UI actuelle reste principalement centree sur OPNsense
- la distinction cible `opnsense` / `mikrotik` / `radius` n'est pas encore materialisee proprement
- les capacites UI comme l'acces au dashboard ne sont pas encore derivees du type de device

Effet :

- confusion entre device API et NAS RADIUS standard
- risque d'afficher des pages ou tests incoherents pour certains types

Correction a envisager :

- faire porter au type de device la definition des champs, tests et pages actives
- reserver le dashboard aux devices API qui exposent des donnees live
- considerer `radius` comme une branche standard sans dashboard

## 8. Les evolutions de `network_devices.php` doivent rester visuellement stables

Fichiers impactes :

- [pages/network_devices.php](/var/www/html/pages/network_devices.php)
- [css/network_devices.css](/var/www/html/css/network_devices.css)
- [css/theme.css](/var/www/html/css/theme.css)

Probleme :

- la page sert maintenant de point d'entree pour la nouvelle gestion des types de devices
- chaque changement fonctionnel risque de detruire l'equilibre visuel deja en place si la mise en page est modifiee trop brutalement

Effet :

- dette UI supplementaire
- incoherence avec la base visuelle existante du projet

Correction a envisager :

- faire des modifications incrementales
- conserver la structure de page et les composants deja propres
- ne pas sacrifier la qualite visuelle pour introduire la logique `opnsense` / `mikrotik` / `radius`

## 9. Les fichiers UI globaux ne doivent pas etre embarques par erreur dans un chantier local

Fichiers impactes :

- [css/sidebar.css](/var/www/html/css/sidebar.css)
- [includes/sidebar.php](/var/www/html/includes/sidebar.php)
- [css/theme.css](/var/www/html/css/theme.css)

Probleme :

- ces fichiers sont partages par plusieurs pages
- une modification non autorisee dans un chantier local peut casser l'ensemble de l'interface

Effet :

- regressions visuelles transverses
- perte de confiance dans les refactors pourtant cibles

Correction a envisager :

- traiter ces fichiers comme perimetre protege
- exiger une autorisation explicite avant toute modification
- limiter par defaut un chantier local aux fichiers strictement necessaires
