# Points Critiques Du Projet

## Objectif

Ce document recense les zones sensibles ou une modification locale peut provoquer des effets de bord importants.

## 1. [config/db.php](/var/www/html/config/db.php)

Pourquoi c'est critique :

- tous les endpoints SQL en dependent
- toute variation de connexion ou de mode PDO impacte l'ensemble du backend metier

Risque :

- panne globale des API utilisateurs, profils, NAS et sessions

## 2. [includes/sidebar.php](/var/www/html/includes/sidebar.php)

Pourquoi c'est critique :

- inclus dans presque toutes les pages
- centralise les liens de navigation et certaines informations de session

Risque :

- casser la sidebar casse l'acces a une large partie de l'application
- changer un lien affecte toutes les pages

## 3. [includes/radius_sync.php](/var/www/html/includes/radius_sync.php)

Pourquoi c'est critique :

- point central de la synchronisation profil -> FreeRADIUS
- contient la logique de conversion des debits et quotas

Risque :

- une erreur ici peut impacter tous les profils crees ou reprovisionnes
- toute modification du mapping peut casser la QoS ou les limites RADIUS

## 4. [api/users/create_user.php](/var/www/html/api/users/create_user.php)

Pourquoi c'est critique :

- cree l'utilisateur applicatif
- cree les entrees RADIUS associees

Risque :

- incoherence entre `users` et tables RADIUS si une partie de la logique change
- risque transactionnel et metier eleve

## 5. [api/users/update_user.php](/var/www/html/api/users/update_user.php)

Pourquoi c'est critique :

- met a jour `users`
- modifie `radcheck`, `radusergroup` et `radreply`

Risque :

- changement de `username` ou de profil peut desynchroniser les references
- logique fragile si l'ancien username doit etre traite differemment du nouveau

## 6. [api/users/delete_user.php](/var/www/html/api/users/delete_user.php)

Pourquoi c'est critique :

- supprime les entrees RADIUS d'un utilisateur
- supprime l'utilisateur applicatif

Risque :

- suppression incomplete si une table n'est pas nettoyee
- suppression excessive si `radacct` est active sans politique claire

## 7. [api/profiles/create_profile.php](/var/www/html/api/profiles/create_profile.php)

Pourquoi c'est critique :

- cree la structure fonctionnelle d'un profil
- declenche la synchronisation RADIUS

Risque :

- toute evolution du formulaire profil doit etre alignee ici
- les champs ignores en backend creent des incoherences silencieuses

## 8. [pages/users_list.php](/var/www/html/pages/users_list.php) + [js/users_list.js](/var/www/html/js/users_list.js)

Pourquoi c'est critique :

- c'est le principal ecran de consultation operable
- il combine SQL, rendu HTML, `data-*`, details dynamiques et sessions

Risque :

- toucher la structure HTML peut casser le JS
- toucher la requete SQL peut casser le panneau detail
- toucher les noms de colonnes peut casser les datasets

## 9. [pages/add_hotspot_user.php](/var/www/html/pages/add_hotspot_user.php) + [js/add_hotspot_user.js](/var/www/html/js/add_hotspot_user.js)

Pourquoi c'est critique :

- couplage fort entre noms de champs frontend et `$_POST` backend

Risque :

- renommer un champ HTML casse la creation utilisateur
- l'existence de doublons comme `data_limit` augmente le risque d'effets de bord

## 10. [pages/add_profile.php](/var/www/html/pages/add_profile.php)

Pourquoi c'est critique :

- c'est l'unique ecran reel de creation profil
- il alimente le mapping RADIUS des groupes

Risque :

- toute modification de la signification de `session_timeout`, `data_limit` ou `rate_limit` doit etre refletee dans `radius_sync.php`

## 11. [api/test_radius.php](/var/www/html/api/test_radius.php)

Pourquoi c'est critique :

- c'est un point de diagnostic infrastructurel
- il touche a `shell_exec` et `radclient`

Risque :

- erreurs de test trompeuses
- risque de mauvais diagnostic lors du support ou du debug

## 12. [api/network_devices_api.php](/var/www/html/api/network_devices_api.php)

Pourquoi c'est critique :

- gere le stockage des devices OPNsense en JSON

Risque :

- une corruption du JSON casse tout le module reseau
- la suppression et mise a jour reposent sur des identifiants de fichier, pas sur SQL

## 13. [config/config.php](/var/www/html/config/config.php)

Pourquoi c'est critique :

- contient les constantes globales OPNsense
- utilise par `api_proxy.php` et `api/disconnect_session.php`

Risque :

- toute erreur de secret ou d'URL casse l'integration OPNsense
- les secrets etant en clair, le fichier est aussi critique en securite

## 14. [config/schema.sql](/var/www/html/config/schema.sql)

Pourquoi c'est critique :

- c'est la seule base formelle de schema livree

Risque :

- il ne couvre pas toutes les tables attendues par le code
- toute tentative de reinstallation ou duplication d'environnement peut etre incomplete

## Zones A Risque De Couplage Fort

### Couplage frontend/backend

- noms des `input` HTML -> cles `$_POST`
- attributs `data-*` HTML -> ids DOM du JS
- chemins relatifs JS -> routes API

### Couplage applicatif/RADIUS

- `profiles.name` -> `radusergroup.groupname`
- `profiles.name` -> `radgroupreply.groupname`
- `users.username` -> `radcheck.username`
- `users.username` -> `radreply.username`
- `nas.type` -> choix du mapping de debit dans [includes/radius_sync.php](/var/www/html/includes/radius_sync.php)

### Couplage typage/unites

- `data_limit` versus `data_quota_mb`
- `Max-Data` versus `Max-Octets`
- `session_timeout` UI en minutes versus attribut RADIUS sans normalisation explicite

## Regle De Prudence

Avant toute modification de ces points critiques, verifier systematiquement :

- le formulaire source
- le JS eventuel
- l'endpoint PHP
- la table SQL ou le JSON cible
- le mapping FreeRADIUS ou OPNsense associe
