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

## 2. `Derniers Evenements` ne montre encore que les sessions en cours OPNsense

Fichiers impactes :

- [api/get_stats.php](/var/www/html/api/get_stats.php)
- [pages/dashboard.php](/var/www/html/pages/dashboard.php)

Probleme :

- le tableau est maintenant centre sur les logs utilisateur OPNsense
- il utilise les donnees de `/api/captiveportal/session/search`
- cela donne une vue "utilisateurs connectes" plus qu'un vrai journal historise d'evenements

Effet :

- pas d'historique complet de connexions/deconnexions
- les evenements disparaissent quand les sessions ne sont plus presentes dans la source live

Correction a envisager :

- brancher un vrai journal utilisateur historise
- ou stocker localement les evenements hotspot/OPNsense dans une table dediee

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
