# Bugs Et Problemes Connus

## 1. Endpoint de creation utilisateur incorrect

Fichier impacte :

- [js/add_hotspot_user.js#L38](/var/www/html/js/add_hotspot_user.js#L38)

Probleme :

- le script appelle `../api/create_user.php`
- le fichier reel present dans le projet est [api/users/create_user.php](/var/www/html/api/users/create_user.php)

Effet :

- le formulaire d'ajout utilisateur ne peut pas fonctionner tel quel

Correction a envisager :

- aligner le chemin frontend sur l'endpoint reel

## 2. Redirection de login incoherente

Fichier impacte :

- [api_proxy.php#L30](/var/www/html/api_proxy.php#L30)

Probleme :

- la redirection cible `dashboard/dashboard.php`
- le dashboard reel est [pages/dashboard.php](/var/www/html/pages/dashboard.php)

Effet :

- apres connexion reussie, la redirection peut echouer

Correction a envisager :

- corriger la route de destination

## 3. Dashboard AJAX avec chemin relatif probablement faux

Fichier impacte :

- [js/dashboard.js#L12](/var/www/html/js/dashboard.js#L12)

Probleme :

- l'appel fait `fetch('api/get_stats.php')`
- depuis `pages/dashboard.php`, ce chemin relatif ne pointe pas vers `/api/get_stats.php`

Effet :

- les donnees du dashboard peuvent ne jamais se charger

Correction a envisager :

- rendre le chemin absolu ou corriger le chemin relatif

## 4. `api/get_stats.php` ne renvoie pas le format attendu

Fichier impacte :

- [api/get_stats.php](/var/www/html/api/get_stats.php)

Probleme :

- le fichier appelle `phpinfo()`
- [js/dashboard.js](/var/www/html/js/dashboard.js) attend un JSON structure

Effet :

- le frontend ne peut pas parser correctement la reponse

Correction a envisager :

- remplacer `phpinfo()` par une reponse JSON conforme aux champs attendus

## 5. Edition utilisateur non connectee

Fichier impacte :

- [js/users_list.js#L122](/var/www/html/js/users_list.js#L122)

Probleme :

- le bouton sauvegarde affiche seulement `alert("Save à connecter avec update_user.php")`

Effet :

- la page de details utilisateur est en lecture seule dans les faits

Correction a envisager :

- brancher le formulaire sur [api/users/update_user.php](/var/www/html/api/users/update_user.php)

## 6. Inputs editables inexistants dans la liste utilisateur

Fichier impacte :

- [js/users_list.js#L105](/var/www/html/js/users_list.js#L105)

Probleme :

- le script active/desactive `.editable`
- aucun champ `.editable` n'est present dans [pages/users_list.php](/var/www/html/pages/users_list.php)

Effet :

- le mode edition ne peut pas fonctionner

Correction a envisager :

- aligner le HTML et le JS

## 7. `session_timeout` utilisateur non pris en charge backend

Fichiers impactes :

- [pages/add_hotspot_user.php#L81](/var/www/html/pages/add_hotspot_user.php#L81)
- [api/users/create_user.php](/var/www/html/api/users/create_user.php)
- [api/users/update_user.php](/var/www/html/api/users/update_user.php)

Probleme :

- le champ existe dans l'UI
- il n'est ni lu ni mappe vers RADIUS cote backend

Effet :

- l'utilisateur peut croire configurer une limite de session qui n'est jamais appliquee

Correction a envisager :

- propager le champ jusqu'au stockage et au mapping RADIUS

## 8. Champs du formulaire profil ignores

Fichiers impactes :

- [pages/add_profile.php](/var/www/html/pages/add_profile.php)
- [api/profiles/create_profile.php](/var/www/html/api/profiles/create_profile.php)

Probleme :

- `description`, `validity_value`, `validity_unit`, `auto_renewal` existent dans l'UI
- ils ne sont pas traites par l'API

Effet :

- perte silencieuse de donnees saisies

Correction a envisager :

- soit supprimer ces champs de l'UI, soit les implermenter cote backend

## 9. Double champ `data_limit` dans le formulaire utilisateur

Fichier impacte :

- [pages/add_hotspot_user.php#L86](/var/www/html/pages/add_hotspot_user.php#L86)
- [pages/add_hotspot_user.php#L185](/var/www/html/pages/add_hotspot_user.php#L185)

Probleme :

- deux champs portent le meme nom `data_limit`

Effet :

- ambiguite sur la valeur effectivement recue en PHP

Correction a envisager :

- dissocier les usages ou renommer les champs

## 10. `api/test_radius.php` contient des variables incoherentes

Fichier impacte :

- [api/test_radius.php](/var/www/html/api/test_radius.php)

Problemes observes :

- reaffectation successive de `host`, `secret`, `port`
- utilisation de `$input` non defini
- utilisation de `$success` avant son initialisation
- melange entre `user/pass` et `test_user/test_pass`

Effet :

- comportement imprevisible ou faux negatif/faux positif lors du test

Correction a envisager :

- nettoyer la logique et unifier les sources d'entree

## 11. `profile_list.php` est statique

Fichier impacte :

- [pages/profile_list.php](/var/www/html/pages/profile_list.php)

Probleme :

- la liste des profils est un exemple hardcode

Effet :

- elle ne reflete pas la base reelle

Correction a envisager :

- brancher la page a la base SQL

## 12. `sessions_list.php` est statique

Fichier impacte :

- [pages/sessions_list.php](/var/www/html/pages/sessions_list.php)

Probleme :

- les sessions affichees sont en dur

Effet :

- la page ne reflete pas les sessions reelles

Correction a envisager :

- connecter la page a `radacct` ou a l'API reseau reelle

## 13. `hotspot_vouchers.php` contient deux pages concatenees

Fichier impacte :

- [pages/hotspot_vouchers.php](/var/www/html/pages/hotspot_vouchers.php)

Probleme :

- le fichier contient une page de vouchers puis une seconde structure de page FreeRADIUS

Effet :

- maintenance tres fragile
- risque d'erreurs de rendu ou de logique

Correction a envisager :

- separer les responsabilites dans des fichiers distincts

## 14. Schema SQL incomplet par rapport au code

Fichier impacte :

- [config/schema.sql](/var/www/html/config/schema.sql)

Probleme :

- `users` et `profiles` sont utilises partout
- ils ne sont pas definis dans le schema fourni

Effet :

- difficulte d'installation
- ambiguite sur la structure exacte attendue

Correction a envisager :

- completer le schema officiel du projet

## 15. Liens morts dans la sidebar

Fichier impacte :

- [includes/sidebar.php](/var/www/html/includes/sidebar.php)

Probleme :

- certains liens pointent vers des fichiers absents

Exemples :

- `sessions_liste.php`
- `administration.php`
- `reboot.php`
- `shutdown.php`

Effet :

- navigation partiellement cassée

Correction a envisager :

- corriger les routes ou ajouter les pages manquantes

## 16. Incoherence de mapping data quota

Fichiers impactes :

- [includes/radius_sync.php](/var/www/html/includes/radius_sync.php)
- [api/users/create_user.php](/var/www/html/api/users/create_user.php)

Probleme :

- profil : `data_quota_mb` converti en `Max-Octets`
- utilisateur : `data_limit` ecrit en `Max-Data` sans conversion

Effet :

- comportements differents pour des concepts proches

Correction a envisager :

- unifier unite, attribut cible et transformation

## 17. Dependances MikroTik encore actives

Fichiers impactes :

- [includes/radius_sync.php](/var/www/html/includes/radius_sync.php)
- [api/users/create_user.php](/var/www/html/api/users/create_user.php)
- [api/users/update_user.php](/var/www/html/api/users/update_user.php)

Probleme :

- `Mikrotik-Rate-Limit` est encore utilise dans plusieurs flux

Effet :

- la transition vers OPNsense / FreeRADIUS n'est pas complete

Correction a envisager :

- definir une strategie unique de limitation de debit
