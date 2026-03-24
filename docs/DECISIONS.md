# Decisions Techniques Deduites Du Code

## Objectif

Ce document recense les choix techniques actuellement visibles dans le projet. Il ne s'agit pas de recommandations abstraites, mais de decisions deja materialisees dans le code.

## 1. Choix D'Une Application PHP Monolithique

Decision observee :

- le projet est construit en PHP classique, sans framework visible
- les pages, la logique backend et la configuration sont regroupees dans le meme depot

Implication :

- faible cout d'entree
- couplage fort entre rendu, logique et acces aux donnees

## 2. Rendu Serveur Avec AJAX Cible

Decision observee :

- les pages HTML sont rendues cote serveur
- certaines interactions passent ensuite par `fetch()` vers des API PHP

Exemples :

- chargement des sessions utilisateur
- chargement des NAS
- gestion des devices OPNsense
- sauvegarde/test FreeRADIUS

Implication :

- architecture hybride
- les changements doivent rester coherents dans les deux couches

## 3. Authentification Admin Locale Et Non Basee Sur SQL

Decision observee :

- l'authentification admin est codee en dur dans [api_proxy.php](/var/www/html/api_proxy.php)

Implication :

- pas de dependance a une table `admins`
- pas de lien avec FreeRADIUS
- systeme simple mais non evolutif et fragile

## 4. Utilisation De FreeRADIUS Comme Moteur De Politique D'Acces

Decision observee :

- les utilisateurs et profils applicatifs sont synchronises avec les tables FreeRADIUS

Implication :

- FreeRADIUS est un composant central
- la logique metier d'acces n'est pas stockee uniquement dans `users` ou `profiles`

## 5. Separation Entre Donnees Applicatives Et Donnees RADIUS

Decision observee :

- les informations metier utilisateur sont stockees dans `users`
- les attributs techniques d'authentification et d'autorisation sont stockes dans les tables RADIUS

Implication :

- necessite de synchronisation
- risque de derive si une seule des deux couches est modifiee

## 6. Utilisation De `profiles.name` Comme Cle Fonctionnelle RADIUS

Decision observee :

- le nom du profil est utilise comme `groupname` dans FreeRADIUS

Implication :

- le nom du profil n'est pas un simple label d'affichage
- il devient une cle technique partagee

## 7. Transition Vers OPNsense

Decision observee :

- presence de [api/test_opnsense.php](/var/www/html/api/test_opnsense.php)
- presence de [api/network_devices_api.php](/var/www/html/api/network_devices_api.php)
- presence de [api/disconnect_session.php](/var/www/html/api/disconnect_session.php)
- presence de [config/config.php](/var/www/html/config/config.php) avec constantes OPNsense

Implication :

- OPNsense est bien la cible reseau actuelle du projet

## 8. Suppression Partielle Seulement De MikroTik

Decision observee :

- la transition n'est pas terminee
- des traces MikroTik restent actives dans le mapping de debit

Indices :

- `Mikrotik-Rate-Limit` dans [includes/radius_sync.php](/var/www/html/includes/radius_sync.php)
- `Mikrotik-Rate-Limit` dans [api/users/create_user.php](/var/www/html/api/users/create_user.php)
- `Mikrotik-Rate-Limit` dans [api/users/update_user.php](/var/www/html/api/users/update_user.php)
- ancien code Mikhmon dans [includes/get_radius.php](/var/www/html/includes/get_radius.php)

Implication :

- la suppression MikroTik est une direction visible mais non finalisee

## 9. Stockage JSON Pour Les Configurations Systeme

Decision observee :

- OPNsense devices stockes dans [config/opnsense.json](/var/www/html/config/opnsense.json)
- configuration de test FreeRADIUS stockee dans [config/radius.json](/var/www/html/config/radius.json)

Implication :

- pas de table SQL dediee pour ces parametres
- lecture/ecriture simple mais validation et concurrence plus faibles

## 10. PDO Comme Standard D'Acces SQL

Decision observee :

- les endpoints applicatifs utilisent PDO via [config/db.php](/var/www/html/config/db.php)

Implication :

- style SQL prepare majoritairement coherent
- base de connexion centralisee

Exception historique :

- [includes/get_radius.php](/var/www/html/includes/get_radius.php) utilise encore `mysqli_connect`

## 11. Sessions PHP Comme Mecanisme Global D'Etat

Decision observee :

- l'etat de connexion repose sur `$_SESSION`
- certaines pages stockent aussi des `csrf_token`
- `includes/message.php` utilise aussi la session pour les flash messages

Implication :

- systeme simple
- couplage global aux variables de session

## 12. Strategie De Provisionnement Direct En Base RADIUS

Decision observee :

- au lieu d'un service externe, le projet ecrit directement dans les tables FreeRADIUS

Implication :

- rapidite et simplicite
- forte dependance au schema RADIUS et a ses attributs

## 13. Maintien De Pages Placeholder Ou Partiellement Branchees

Decision observee :

- plusieurs ecrans existent avant que leur backend soit complet

Exemples :

- [pages/profile_list.php](/var/www/html/pages/profile_list.php)
- [pages/sessions_list.php](/var/www/html/pages/sessions_list.php)
- [pages/hotspot_vouchers.php](/var/www/html/pages/hotspot_vouchers.php)

Implication :

- l'UI sert parfois de maquette fonctionnelle plus que de flux complet

## 14. Orientation Progressive Vers Un Refactoring Documente

Decision observee :

- coexistence de code historique et de nouveaux modules
- besoin explicite de documentation pour securiser les evolutions

Implication :

- le projet est dans une phase de transition
- la documentation doit servir de reference avant les changements structurels

## Resume Des Choix Actuels

- monolithe PHP sans framework
- rendu serveur avec AJAX ponctuel
- authentification admin locale hardcodee
- coeur d'acces base sur FreeRADIUS
- integration reseau orientee OPNsense
- stockage mixte SQL + JSON
- transition MikroTik -> OPNsense/FreeRADIUS non terminee
