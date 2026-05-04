# Plan Projet

## Objectif

Ce document transforme les documents de reference existants en planning d execution.

Il ne remplace pas :

- `docs/project_rules.md`
- `docs/ALIGNMENT_ROADMAP.md`
- `docs/BACKEND_STATUS.md`
- `docs/KNOWN_ISSUES.md`
- `docs/OPNSENSE_MIKHMON_ALIGNMENT.md`

Il sert a choisir le prochain chantier sans repartir page par page ni empiler une nouvelle logique sur des chemins partiels.

## Sources

- `docs/project_rules.md` : regles prioritaires, source unique, anti-fallback, frontiere reference/runtime.
- `docs/ALIGNMENT_ROADMAP.md` : axes d alignement et ordre recommande.
- `docs/BACKEND_STATUS.md` : maturite reelle de MikroTik, RADIUS et OPNsense.
- `docs/KNOWN_ISSUES.md` : problemes ouverts et risques documentes.
- `docs/OPNSENSE_MIKHMON_ALIGNMENT.md` : cible OPNsense sans nouveau cycle commercial.
- `docs/PROJECT_DEPENDENCIES.md` : dependances, fichiers proteges, orphelins et compatibilites.

## Statuts

- `TODO` : a traiter.
- `IN_PROGRESS` : chantier ouvert.
- `BLOCKED` : attente decision, preuve runtime ou fichier manquant.
- `DONE` : termine, ancien chemin retire ou compatibilite classee explicitement.
- `WATCH` : a surveiller, pas a modifier sans preuve.

Un chantier n est pas `DONE` si ancien chemin, nouveau chemin et compatibilite temporaire coexistent encore pour la meme responsabilite.

## Priorites

### P0 - Cartographie Et Orphelins

Statut : `TODO`

Objectif :

- confirmer les fichiers hors runtime
- classer les doublons, archives, backups et exports
- ne supprimer aucun fichier sans verifier les dependances dans `docs/PROJECT_DEPENDENCIES.md`

Critere de fin :

- chaque candidat orphelin est classe `supprimable`, `archive`, `compatibilite`, `reference` ou `blocked`
- aucune suppression ne casse une reference runtime

Docs a mettre a jour :

- `docs/PROJECT_DEPENDENCIES.md`
- `docs/KNOWN_ISSUES.md` si un risque reste ouvert

### P1 - Chantiers Incomplets Et Compatibilites Temporaires

Statut : `IN_PROGRESS`

Objectif :

- fermer les refactors partiels
- retirer les anciens chemins quand le nouveau chemin est confirme
- documenter les compatibilites encore actives

Critere de fin :

- chaque compatibilite a une raison, un consommateur connu et un critere de retrait

Docs a mettre a jour :

- `docs/BACKEND_STATUS.md`
- `docs/DECISIONS.md`
- `docs/PROJECT_DEPENDENCIES.md`

Avancement constate (2026-04-17) :

- la compatibilite technique `api/test_opnsense.php` -> `api/test_device.php` reste active (non fermee)
- la fermeture des compatibilites est en cours mais pas terminee

### P2 - Resolution NAS / Device / Backend

Statut : `IN_PROGRESS`

Objectif :

- appliquer la chaine canonique `nas_id -> nas.type -> backend logique -> device`
- eviter toute resolution metier depuis le seul device actif en session
- clarifier les cas MikroTik multiples

Critere de fin :

- aucune action metier ne choisit un backend par fallback de type ou device implicite

Docs a mettre a jour :

- `docs/ARCHITECTURE.md`
- `docs/NAS_ABSTRACTION.md`
- `docs/BACKEND_STATUS.md`

Avancement constate (2026-04-17) :

- les flux profils ont ete alignees sur une source commune par device via `includes/profile_catalog.php`
- `profile_options.php`, `recharge_options.php`, `profile_list.php`, `add_profile.php`, `generate.php`, `add_hotspot_user.php`, `user_provisioning.php` et `voucher_batch_service.php` consomment cette source
- `api/users/update_mikrotik_user.php`, `api/users/delete_mikrotik_user.php` et `api/users/get_user_profile_details.php` exigent maintenant `device_id` et resolvent explicitement le routeur MikroTik cible
- la fermeture complete des chemins historiques (routeur implicite / fallback actif) reste a terminer

Avancement constate (2026-04-23) :

- `pages/users_list.php` et `api/users/get_user_sessions.php` ne melangent plus routeur + base locale pour les cumuls MikroTik
- les cumuls metier MikroTik sont explicitement réalignes sur `/ip/hotspot/user`, la session courante restant sur `/ip/hotspot/active`
- les recharges MikroTik invalident maintenant aussi le cache principal `mikrotik_hotspot_users`
- le chantier reste `IN_PROGRESS` car des chemins historiques `connectToActiveMikrotikApi()` existent encore dans `includes/mikrotik_backend.php`

### P3 - Alignement RADIUS / OPNsense Sur Cycle Metier

Statut : `IN_PROGRESS`

Objectif :

- garder le cycle existant `profiles -> users -> projection RADIUS -> observation -> historique`
- ne pas creer un deuxieme process commercial OPNsense
- clarifier preview/apply sur recharge RADIUS et OPNsense

Critere de fin :

- ce qui est applique, synchronise, previsualise ou observe est explicite pour chaque backend

Avancement constate (2026-04-23) :

- `preview`, `apply`, `users_list` et `get_user_sessions` partagent maintenant un calcul aligne pour le flux `RADIUS / OPNsense`
- la baseline de reset du cycle courant est unifiee cote `RADIUS / OPNsense`
- la documentation du flux a ete posee dans `docs/RECHARGE_FLOW_ANALYSIS.md`
- reste a clarifier et valider le perimetre `portal` : cumul total metier vs session active selon la page et le backend

Docs a mettre a jour :

- `docs/OPNSENSE_MIKHMON_ALIGNMENT.md`
- `docs/BACKEND_STATUS.md`
- `docs/DATA_MODEL.md` si les champs ou tables changent

### P4 - Bilan Commercial, Recouvrement Et Rapports

Statut : `TODO`

Objectif :

- aligner `Bilan`, rapports, recouvrement et historique sur une source commerciale fiable
- eviter d afficher des compteurs comme chiffre d affaires

Critere de fin :

- chaque montant affiche provient d une source documentee
- chaque compteur est libelle comme compteur, pas comme revenu

Docs a mettre a jour :

- `docs/DATA_MODEL.md`
- `docs/BACKEND_STATUS.md`
- `docs/KNOWN_ISSUES.md`

### P5 - Coherence UI Protegee

Statut : `WATCH`

Objectif :

- preserver les pages propres
- proteger les fichiers globaux
- eviter qu un chantier local modifie `theme.css`, `sidebar.css` ou `includes/sidebar.php` sans autorisation explicite

Critere de fin :

- toute modification UI globale est justifiee par un besoin transverse

Docs a mettre a jour :

- `docs/project_rules.md`
- `docs/DECISIONS.md`

## Registre Des Chantiers Incomplets

### INC-001 - Alias dashboard `opnsense_*` vers `device_*`

Statut : `TODO`

Type : compatibilite temporaire

Probleme :

- le payload dashboard est aligne vers `device_*`
- des aliases `opnsense_*` restent toleres pour compatibilite

Critere de fin :

- tous les consommateurs actifs utilisent `device_*`
- les aliases `opnsense_*` sont retires ou classes compatibilite active avec justification

### INC-002 - `test_opnsense.php` passerelle vers `test_device.php`

Statut : `TODO`

Type : compatibilite active

Probleme :

- `api/test_opnsense.php` inclut `api/test_device.php`
- `api/test_device.php` est l endpoint mutualise cible

Critere de fin :

- les consommateurs et docs utilisent `test_device.php`
- `test_opnsense.php` est retire ou conserve avec raison documentee

### INC-003 - Resolution routeur MikroTik multiple

Statut : `IN_PROGRESS`

Type : risque architecture

Probleme :

- certains flux peuvent encore deriver vers un routeur implicite par type
- MikroTik doit etre pilote par routeur exact

Critere de fin :

- lecture et ecriture MikroTik resolvent le routeur cible par `device_id` ou cible technique documentee

Avancement constate (2026-04-17) :

- les lectures de catalogue profil sont desormais unifiees par `device_id` cible
- les ecritures profil MikroTik (`syncProfileToMikrotik` / `updateProfileInMikrotik`) exigent un contexte NAS explicite MikroTik
- l edition, la suppression utilisateur MikroTik et la lecture des details de profil utilisateur passent desormais par un ciblage explicite `device_id` (plus de connexion implicite sur device actif dans ces endpoints)
- sous-lot `users` (update/delete/profile details) : `DONE`
- sous-lot `dashboard` (`api/get_stats.php`) : `DONE` (considere traite par decision de perimetre)
- des chemins historiques de connexion sur device actif existent encore dans `includes/mikrotik_backend.php` pour compatibilite

Detail d analyse ciblee `includes/mikrotik_backend.php` (2026-04-17) :

- fichier analyse de la ligne 1 a la ligne 2920
- 45 occurrences de `connectToActiveMikrotikApi()` restent presentes
- points de fallback centraux identifies autour de `connectToMikrotikApiForNasContext` et du helper actif
- fonctions avec `?array $nasContext = null` encore actives sur plusieurs operations (session, profil, user, recharge)
- conclusion chantier : sous-lots `users` et `dashboard` fermes, refactor final `INC-003` encore bloque par le legacy de `includes/mikrotik_backend.php`

### INC-008 - Catalogue profils unifie par device

Statut : `DONE`

Type : unification source de verite

Probleme :

- les pages profils (`profile_list.php`, `generate.php`, `add_hotspot_user.php`, `add_profile.php`) ne chargeaient pas toujours les memes profils selon le flux

Resultat :

- une source unique `includes/profile_catalog.php` est utilisee pour les lectures profils selon le device cible
- MikroTik lit RouterOS, RADIUS/OPNsense lit SQL `profiles`
- le JS de chargement profils est mutualise via `js/profile_options_loader.js`

### INC-004 - Recharge RADIUS / OPNsense preview/apply

Statut : `IN_PROGRESS`

Type : flux metier partiel

Probleme :

- la preview est plus avancee que l application reelle sur certains chemins RADIUS/OPNsense

Critere de fin :

- preview et apply partagent la meme logique metier
- l execution reelle et les limites connues sont documentees

Avancement constate (2026-04-23) :

- `preview`, `apply`, `users_list` et `get_user_sessions` ont ete realignes sur une logique commune
- reste a fermer le sous-sujet `portal` pour distinguer clairement :
  - cumul total metier
  - session active en cours
  - historique SQL/RADIUS quand il existe

### INC-005 - `portal_profiles.php` depend de `modules/portal`

Statut : `BLOCKED`

Type : dependance manquante

Probleme :

- `pages/portal_profiles.php` requiert `../modules/portal/PortalProfileController.php`
- le dossier `modules/` n existe pas a la racine
- une version existe dans `docs/archive/modules/portal/`

Critere de fin :

- soit le module runtime est restaure hors `docs/`
- soit la page est retiree/desactivee proprement
- aucune page runtime ne depend de `docs/archive`

### INC-006 - Bilan dashboard base sur compteurs

Statut : `TODO`

Type : coherence metier

Probleme :

- certains indicateurs commerciaux sont encore des volumes ou compteurs, pas un chiffre d affaires fiable

Critere de fin :

- chaque indicateur est relie a une source commerciale documentee
- les libelles ne sur-vendent pas la signification des donnees

### INC-007 - Gestion devices centree OPNsense

Statut : `TODO`

Type : alignement multi-backend

Probleme :

- la gestion devices doit distinguer proprement `mikrotik`, `radius`, `opnsense`
- certaines capacites UI et tests restent trop lies a OPNsense

Critere de fin :

- champs, tests et pages actives dependent du type reel de device/backend

## Prochain Chantier Recommande

1. Finaliser `INC-003` : retirer les derniers chemins MikroTik implicites encore presents dans `includes/mikrotik_backend.php`.
2. Finaliser `INC-004` : clarifier le contrat `portal` entre cumul total metier et session active pour MikroTik / OPNsense.
3. Fermer `INC-002` ou documenter explicitement pourquoi la compatibilite `test_opnsense.php` reste active.
4. Traiter `INC-005` comme blocage visible : `portal_profiles.php` depend d un module absent.

## Definition De Fini

- aucune source secondaire ne complete une source canonique
- aucun fallback non documente ne reste comme solution finale
- les fichiers orphelins sont classes avant suppression
- chaque chantier ferme met a jour les docs concernees
- le comportement runtime reste verifie sur les points touches
