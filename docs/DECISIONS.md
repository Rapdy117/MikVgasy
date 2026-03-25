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

## 15. `nas_id` Comme Cle De Routage Cible

Decision documentaire retenue :

- le NAS selectionne doit definir le comportement du systeme
- `nas_id` devient la cle centrale de resolution
- `nas.type` devient la cle technique de dispatch

Implication :

- les endpoints metier devront cesser d'embarquer des decisions backend implicites
- les attributs utilisables devront dependre du NAS selectionne

## 16. FreeRADIUS Comme Backend Standard Multi-NAS

Decision documentaire retenue :

- FreeRADIUS reste le backend standard pour les NAS compatibles RADIUS

Implication :

- le projet ne doit pas opposer "multi-NAS" et "FreeRADIUS"
- FreeRADIUS devient la branche standard de cette architecture

## 17. OPNsense Comme Branche API Distincte

Decision documentaire retenue :

- OPNsense doit etre gere comme un device API explicite
- il ne doit plus etre confondu avec un NAS RADIUS generique

Implication :

- les pages live comme le dashboard doivent dependre des capacites du device
- les champs de configuration OPNsense doivent rester separes des devices RADIUS standards

## 18. Reduction Du Perimetre Device A Trois Types

Decision documentaire retenue :

- la gestion des devices doit etre limitee a trois types fonctionnels :
  - `opnsense`
  - `mikrotik`
  - `radius`

Implication :

- `opnsense` et `mikrotik` sont les deux branches API de management
- `radius` represente un NAS standard sans API de pilotage projet
- les formulaires, tests et pages disponibles doivent dependre strictement de ce triplet

## 19. Capacites UI Dependantes Du Type De Device

Decision documentaire retenue :

- toutes les pages ne sont pas universelles
- certaines vues doivent etre masquees ou desactivees selon le type du device courant

Implication :

- le dashboard ne doit pas etre propose pour un device `radius` standard
- les tests de connexion doivent etre API pour `opnsense` et `mikrotik`, et RADIUS pour `radius`
- la navigation doit s'appuyer sur des capacites documentees plutot que sur des conditions implicites dispersees

## 20. Preservation De La Base UI Existante

Decision documentaire retenue :

- lorsque la base visuelle d'une page est jugee propre, les evolutions doivent s'appuyer dessus au lieu de la reconstruire

Implication :

- l'ajout des types de devices dans `pages/network_devices.php` doit rester une evolution de la page existante
- la mise en page ne doit pas se degrader a cause d'un changement fonctionnel
- `theme.css` et les patterns UI existants doivent rester le socle de reference

## 21. Autorisation Requise Pour Les Fichiers UI Globaux

Decision documentaire retenue :

- les fichiers UI globaux partages ne doivent pas etre touches dans un chantier local sans autorisation explicite

Implication :

- [css/sidebar.css](/var/www/html/css/sidebar.css), [includes/sidebar.php](/var/www/html/includes/sidebar.php) et [css/theme.css](/var/www/html/css/theme.css) sont des fichiers sensibles a perimetre global
- une demande ciblee sur une page comme `pages/network_devices.php` n'autorise pas implicitement la modification de ces fichiers
- toute extension du chantier a ces fichiers doit etre precedee d'une validation explicite

- OPNsense doit etre traite comme un backend API distinct

Implication :

- l'API projet a venir doit porter cette integration
- la logique metier reste commune, seule la traduction technique change

## 18. Deux Domaines Logiques Dans Une Seule Base Physique

Decision formalisee :

- la base physique `radius_manager` contient deja deux domaines logiques :
  - donnees metier applicatives
  - donnees RADIUS / AAA

Implication :

- meme sans separation physique immediate, le refactoring doit raisonner avec ces deux couches

## Resume Des Choix Actuels

- monolithe PHP sans framework
- rendu serveur avec AJAX ponctuel
- authentification admin locale hardcodee
- coeur d'acces base sur FreeRADIUS
- integration reseau orientee OPNsense
- stockage mixte SQL + JSON
- transition MikroTik -> OPNsense/FreeRADIUS non terminee
- `nas_id` appele a devenir la cle centrale de routage backend
- une seule base physique actuelle, mais deux couches logiques : metier et RADIUS
