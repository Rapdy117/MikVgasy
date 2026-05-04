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

- `api/test_opnsense.php` a ete archive: `api/test_device.php` est l endpoint de test device actif
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

## 13. Verrou Agent Windows Pour Ecritures Sensibles

Decision observee :

- les endpoints d'ecriture sensibles appellent `backend-agent.exe` avant modification runtime
- les imports standard qui creent/mettent a jour des profils et utilisateurs utilisent l'action `standard-import`
- en cas d'agent absent, licence invalide ou integrite refusee, l'action PHP est bloquee sans fallback
- V2 expose `backend-agent.exe serve` sur `127.0.0.1` et PHP appelle ce service local au lieu de relancer l'EXE a chaque action
- la recharge RADIUS/OPNsense est executee par `backend-agent.exe`; `api/users/apply_recharge.php` reste une facade session/CSRF et historique
- la recharge MikroTik est bloquee tant que son execution directe n est pas migree dans `backend-agent.exe`

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

## 16. Credit Courant Comme Verite Metier (RADIUS / OPNsense)

Decision actee :

- `users.current_credit_time` et `users.current_credit_data` sont la source de verite metier
- `users.session_timeout` / `users.data_limit` deviennent des projections techniques
- l affichage "Actuel" est calcule = credit courant - consommation `radacct`

## 17. Expiration Utilisateur Stockee Et Non Recalculee

Decision actee :

- `users.expiration_date` est la seule source de verite d expiration
- si `first_login` ou `validity_time` manquent, l expiration reste vide
- l UI ne doit pas deduire une expiration implicite

## 18. MikroTik = Routeur Prioritaire

Decision actee :

- pour MikroTik, les profils et utilisateurs sont lus directement sur le routeur
- la DB metier ne doit pas inventer de profil ni d attributs pour MikroTik
- la data quota MikroTik est lue d abord depuis `limit-bytes-total` du profil routeur ; si absent ou nul, repli sur le quota encode dans le script `on-login` (champ `data_quota_mb` via `parseMikrotikOnLoginMetadata()` dans le code)
- la DB metier ne sert qu a l historique commercial (vente / recharge)
- des qu il existe plusieurs routeurs MikroTik, la resolution par `type = mikrotik` n est plus suffisante
- le routage technique doit se faire par `device_id`, puis par `host/ip` du device selectionne
- les credentials API (`api_key`, `api_secret`) doivent toujours provenir du `device` cible et jamais d une valeur statique ou d un fallback global

Implication :

- les endpoints metier devront cesser d'embarquer des decisions backend implicites
- les attributs utilisables devront dependre du NAS selectionne

## 18.b MikroTik : Cumuls Metier Depuis `/ip/hotspot/user`, Session Live Depuis `/ip/hotspot/active`

Decision actee (2026-04-23) :

- pour MikroTik, le cumul data metier vient de `/ip/hotspot/user.bytes-in + bytes-out`
- pour MikroTik, la duree cumulee metier vient de `/ip/hotspot/user.uptime`
- la session active live vient de `/ip/hotspot/active` et reste un flux d observation distinct
- les colonnes locales `users.imported_*` et `user_counter_baselines` ne sont pas une source de verite metier pour MikroTik

Implication :

- `users_list` et le detail inline doivent lire les cumuls MikroTik directement depuis le routeur
- `sessions_list` et les vues live peuvent continuer a utiliser la session active comme observation instantanee
- un affichage MikroTik ne doit plus merger routeur + base locale pour une meme verite metier

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

## 19. Modele NAS / Device Normalise (2026)

Decision formalisee :

- les types d'equipement dans [config/opnsense.json](/var/www/html/config/opnsense.json) sont limites a `opnsense`, `mikrotik`, `radius`
- la source metier est exprimee par `business_source` : `radius` ou `mikrotik_local` ; le driver d'execution par `backend` / `backend_driver` : `opnsense_api`, `mikrotik_api`, `radius`
- `device_id` et `nas_id` restent distincts ; [api/nas.php](/var/www/html/api/nas.php) expose uniquement des appariements valides apres authentification session

Implication :

- toute valeur `other`, `generic` ou entree NAS synthetique est exclue du contrat courant
- les consommateurs JS doivent utiliser `business_source` / `backend_driver` plutot qu'un champ `backend` ambigu seul

## 22. Identite Visuelle Du Projet

Decision actee (2026-04-20) — validee par le proprietaire du projet :

Le theme visuel du projet repose sur deux piliers non negociables :

- **Transparence** : glassmorphism — fond sombre avec `backdrop-filter: blur()`, calques semi-transparents (`rgba`), bordures subtiles (`rgba(148, 163, 184, 0.12)`)
- **Bleu cyan** : couleur d'accent principale `#17a2b8` (alias CSS `--accent`)

Implication pour toute evolution UI :

- ne jamais introduire une couleur d'accent concurrente sans autorisation explicite
- les nouveaux composants doivent heriter de `--accent` et des patterns glassmorphism
- les fonds de page doivent rester sombres (`#060d18` ou proches)
- les boutons, focus, bordures actives et indicateurs utilisent `#17a2b8` et ses variantes
- les animations et effets visuels (vagues, particules, orbs) renforcent ce theme sans le surcharger

---

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
- identite visuelle : transparence (glassmorphism) + bleu cyan `#17a2b8` — non negociable

## 23. Agent Windows Local Pour Les Actions Sensibles

Decision formalisee :

- la V1 de l agent Windows est implementee en Go statique dans `tools/windows-agent`
- les executables runtime client attendus vivent dans `bin/agent`, hors `docs/`
- les outils editeur `license-admin.exe` et `license-generator.exe` vivent dans `tools/editor-license` et ne sont pas livrables au client
- l activation locale ecrit son etat dans `config/license/activation.json`
- PHP appelle `backend-agent.exe` via `includes/backend_agent.php` pour les actions sensibles
- le premier flux protege est l application des lots vouchers dans `api/vouchers/apply_batch.php`
- `api/license/activate_license.php` reste une facade session/CSRF, mais la validation est executee par `activation-key.exe`
- les anciens outils PHP de generation licence et signature sont archives dans `archive/replaced-by-agent-2026-05-04`
- `backend-agent.exe authorize-action` contient une allowlist et refuse toute action inconnue
- les ecritures utilisateurs, profils, recharge et vouchers passent par l autorisation agent avant execution

Implication :

- aucune cle privee de generation licence ne doit etre versionnee ni livree au client
- si `backend-agent.exe` est absent, invalide ou refuse, l action sensible est bloquee
- l execution technique PHP restante est transitoire et devra etre deplacee progressivement dans l agent
