# Decisions Techniques Deduites Du Code

## Objectif

Ce document recense les choix techniques actuellement visibles dans le projet. Il ne s'agit pas de recommandations abstraites, mais de decisions deja materialisees dans le code.

## 2026-10-03 - N-WAN : hook de login limite a la session qui se connecte

- Le hook `on-login` de chaque profil relancait le moteur complet `RM-NWAN-ASSIGN`, qui reevaluait et reecrivait les listes WAN de toutes les sessions actives a chaque connexion (50 a 100 ecritures par login dans le log).
- Le hook `on-login` ne lance plus le moteur et ne supprime plus rien : il ajoute seulement l'IP qui se connecte, dans la liste WAN ayant le plus faible ratio compteur / poids. Les poids sont ecrits dans le hook a partir de la configuration WAN (source unique : `multiWanConfigWeights`), a chaque installation de hooks et a la creation / modification de profil. Les variables globales `rmNwanW1`/`rmNwanW2` ne sont plus utilisees.
- Le hook precedent supprimait toutes les entrees WAN a la connexion (66 suppressions observees le 2026-10-03 a 18:11:56). Cause exacte non identifiee ; la suppression a ete retiree du hook.
- `mikrotikEnsureNwanProfileHooks` remplace l'ancien hook d'un profil par la version courante (marqueur `RM-NWAN-LOGIN-HOOK`).
- Etat : hooks installes sur les 17 profils, un seul par profil. Validation reelle : a faire sur une connexion reelle (une seule ligne d'ajout attendue, aucune suppression).

## 2026-10-03 - Blocage DNS chiffre sortant 853 (politique `block_dot`)

- Le DNS force (`force_dns`) ne couvre que le port 53. Le DNS chiffre (DoT, TCP/UDP 853) sortait directement par le WAN du client sans passer par le DoH MikroTik.
- Ajout de deux regles `forward` drop TCP/UDP 853, commentees `Radius Manager - bloquer DNS chiffre 853 TCP/UDP`, placees avant les blocs DNS 53 existants.
- Politique `block_dot` exposee dans la section Securite de `traffic_control.php` ; lecture de l'etat par commentaire, comme `force_dns`.
- L'enregistrement des parametres DNS (`update_dns_settings`) resynchronise `force_dns` et `block_dot` lorsqu'elles sont actives. Aucune regle n'est creee si la politique est desactivee.

## 2026-10-03 - Nettoyage runtime (Priorite 7) : OPNsense confirme actif, pas legacy

- Le support OPNsense (`includes/opnsense_*.php`, `api/*opnsense*.php`, type de device `opnsense` dans `includes/sidebar.php`) est une pile active en parallele de MikroTik, pas du code mort herite : `modules/portal/PortalProfileController.php` genere le portail pour les deux types de device selon celui configure.
- `api/create_mikrotik_scheduler.php` / `update_mikrotik_scheduler.php` / `delete_mikrotik_scheduler.php` (CRUD generique de scheduler RouterOS, page admin manuelle) et `ensureMikrotikSharedProfileExpiryScheduler()` (`includes/mikrotik_backend.php`, scheduler automatique d'expiration de profil, chantier du 2026-10-02) sont deux systemes distincts et legitimes, sans doublon fonctionnel malgre la ressemblance de nom.
- `api/debug_recent_events.php` et `api/cpu_stream.php` : aucune reference dans pages/js/includes, renommes en `.old`.
- Les 17 fichiers `.old` deja presents dans le depot ne sont inclus par aucun chemin runtime PHP (verifie par grep cible, seules des mentions documentaires existent).

## 2026-09-24 - Nettoyage Traffic Control centralise dans les outils systeme

- `tools/install.php` expose deja la maintenance SQL via `tools/system_tools_page.php`, `tools/system_tools.js` et `api/admin/database_maintenance.php`.
- Le nettoyage Traffic Control est exclusivement manuel depuis cette interface, avec le seuil choisi (1 a 60 mois, valeur initiale 6 mois), les controles superadmin/CSRF et la confirmation existante.
- La liste commune couvre les analyses, la consommation utilisateur et les deux historiques de signatures DNS ; elle alimente etat, export, archivage et nettoyage. Les etats courants des compteurs sont exportes mais ne sont pas purges par retention.
- Les quatre purges du collecteur (48 heures, 14 jours, 400 jours) sont retirees. La collecte et les calculs de consommation restent identiques ; aucun nettoyage quotidien parallele ni index SQL n est ajoute.
- Sans action de maintenance, les historiques continuent de croitre. Les anciens seuils automatiques ne sont plus appliques. Cette modification ne supprime aucune donnee existante.

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

- historique : OPNsense devices stockes dans JSON ; etat actuel : Devices stockes dans SQL
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

- les types d'equipement SQL sont limites a `opnsense`, `mikrotik`, `radius`
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

## 23. Fondation SQL Multi-Tenant Et Initialisation

Decision actee (2026-07-21) :

- `local_admin_users` porte les roles `superadmin`, `admin`, `reseller`
- un reseller est rattache a son administrateur par `owner_admin_id`
- les routeurs et leurs attributions deviennent des donnees SQL canoniques
- les objets commerciaux portent `owner_admin_id` et `created_by_user_id`
- les ressources MikroTik conservent leurs attributs techniques sur le routeur ; SQL ne stocke que leur appartenance
- les tables FreeRADIUS `rad*` restent des projections techniques ou des observations et ne stockent aucun compte de gestion
- `profiles.radius_groupname` devient l identifiant technique RADIUS stable, distinct du nom commercial

Initialisation :

- la migration versionnee `001_multi_tenant_foundation` est la source unique du schema
- le premier compte est un `superadmin`, nomme `superadmin` par defaut
- le mot de passe doit etre fourni pendant l installation et n est jamais code dans le depot
- `system_installation` empeche une seconde initialisation

Implication :

- le runtime verifie la version SQL et ne cree plus silencieusement son propre schema
- la migration doit etre validee sur une copie avant toute execution en production


## 2026-07-21 — Devices entièrement SQL

Les Devices ne sont plus stockés dans un JSON runtime. La source canonique est `network_devices`, avec `owner_admin_id` et `admin_device_assignments`. Les Devices historiques ont été migrés et attribués au superadmin `admin`. Le Device actif est propre à la session. L’ancien JSON est archivé en `.old`.


## 24. Etat actuel faisant foi (2026-07-22)

Les sections historiques sur l authentification hardcodee, le stockage Devices JSON et la transition OPNsense doivent etre lues comme un instantane ancien. Le runtime actuel utilise les comptes SQL multi-tenant, les Devices SQL et des contextes `device_id` explicites. OPNsense est en pause.

Le jeton de preview de recharge est signe, expire rapidement et lie a l utilisateur, au profil, au mode et au Device ; toute divergence est rejetee par `409`. Les vouchers sont enregistres en SQL.


## 25. Licences, maintenance et expiration date-only (2026-07-22)

- `device_licenses` est le registre SQL canonique ; la generation reste dans `tools/install.php` et l activation passe par l API licence.
- Le secret HMAC est surchargeable par `RADIUS_MANAGER_LICENSE_SECRET` ; l installation VPS doit le definir avant toute emission.
- Les actions de maintenance sont superadmin + CSRF, journalisees dans `system_maintenance_runs`; l archivage precede tout nettoyage.
- Le recouvrement applique le meme perimetre que les Devices et les objets commerciaux, sans fuite entre admins ou resellers.
- L expiration utilisateur est un champ date seule `YYYY-MM-DD`; aucune heure implicite ne doit etre ajoutee.

## 26. Portail Hotspot MikroTik actif (2026-08-03)

- Le statut public resout uniquement `config/portal.json:captive_device_id` et refuse tout autre Device.
- L identite du client est validee par la session RouterOS active `username + IP + MAC` ; aucun fallback `router_host` n est autorise.
- Le template est transfere par FTP dans un dossier versionne, puis `html-directory` est bascule uniquement sur un profil utilise par un serveur Hotspot actif.
- Le portail utilise une liste canonique de 1 a 8 images, affichees toutes les 4,5 secondes sans points de navigation ; le cadre reste compact et l image le remplit (`object-fit: cover`), avec une legende translucide incrustee en bas du visuel.
- La selection par defaut illustre quatre activites (Hotspot Wi-Fi, reparation PC, installation de logiciels et maison connectee) avec une legende et un fondu/zoom discret ; `prefers-reduced-motion` supprime cette transition.
- Les images restent servies par l application et ne sont pas integrees au ZIP MikroTik ; l URL applicative doit etre autorisee dans le Walled Garden pour l affichage avant authentification.
- Les limites `limit-uptime` et `limit-bytes-total` restent appliquees nativement par RouterOS et sont relues apres chaque ecriture applicative.

## 27. Diagnostic reseau correle et borne (2026-08-19)

- La stabilite n est jamais conclue depuis un ping court unique : deux cibles Internet et un test HTTPS sont correles avec le WAN, le LAN, RouterOS et les journaux clients.
- La passerelle Starlink peut limiter ICMP et ne constitue donc pas seule une preuve de panne.
- Les drops des Simple Queues sont classes comme limitation Hotspot et conserves avec la file, la cible et le pourcentage ; ils ne sont pas confondus avec une perte physique.
- La retention est fixee a 48 heures et l interface privilegie cause probable, preuves et action de depannage.
- Le seuil de saturation upload utilise la capacite explicite de 65 Mbit/s communiquee pour ce lien.

## 28. AdGuard Home En Pilote DNS (2026-09-04)

- La cible DNS est AdGuard Home officiel, gratuit et open source, sur `10.10.10.2` ; ce serveur reste un service DNS et d observation, jamais un pont reseau.
- La distribution retenue est le binaire stable Linux AMD64 officiel, epingle en `v0.107.79` et verifie par le manifeste SHA-256 de la publication.
- Le service DNS est actif sur `10.10.10.2:53` avec DNSSEC, Quad9 DoH, AdGuard DNS Filter et StevenBlack ; `androndra.wifi.mg` est reecrit vers `10.10.10.1`.
- L amont chiffre retenu est Quad9 DoT IPv4 `tls://9.9.9.10` : DNS classique IPv4 a subi 15/15 timeouts, DoH IPv4 a conserve des `unexpected EOF`, tandis que DoT a passe 15/15 requetes lentes sans erreur amont. Le certificat Quad9 couvre cette IPv4.
- Le pilote Dell `10.10.10.218` par DNAT est suspendu : une redirection vers AdGuard `10.10.10.2` situe sur le meme LAN cree un retour asymetrique si aucune traduction source ne force la reponse via MikroTik. Les deux regles pilote restent desactivees.
- Les huit blocages DNS cibles MikroTik sont reproduits dans AdGuard. Le service et le portail repondent en acces DNS direct, mais la migration cliente n est pas validee tant qu un chemin symetrique conservant le comportement attendu n est pas defini.
- Apres migration, AdGuard devient la source unique des politiques DNS ; les politiques MikroTik correspondantes sont retirees apres validation, sans fallback silencieux ni double filtrage durable.

Cette decision est conservee comme historique du pilote et est remplacee par la decision 29 ci-dessous.

## 29. MikroTik Source Unique DNS Et Filtrage (2026-09-04)

- MikroTik est l unique source de verite et l unique moteur d execution pour la resolution, les Adlists, les blocages, les exceptions et les reecritures DNS du reseau gere.
- La migration des clients et des politiques vers AdGuard Home est abandonnee. AdGuard ne doit plus recevoir de nouvelle politique ni etre utilise comme fallback ou moteur concurrent ; son pilote reste seulement une reference historique et son interface une reference d experience utilisateur pour le module PHP.
- Les clients interrogent actuellement le MikroTik en DNS classique TCP/UDP 53 : ce segment n est pas chiffre et aucune interface ne doit le presenter comme protege par TLS.
- L amont MikroTik utilise Cloudflare DoH `https://cloudflare-dns.com/dns-query` avec validation du certificat active. La racine officielle `SSL.com Root Certification Authority ECC` est importee et fiable ; 15 resolutions reelles sur 15 ont reussi apres activation. Les DNS classiques restent configures pour l amorcage du nom DoH.
- Le Quad9 DoT configure et teste sur AdGuard concernait uniquement le pilote abandonne ; il ne prouve aucun chiffrement du chemin DNS utilise par les clients via MikroTik.

## 30. Observation DNS Avant Blocage Et Perimetre Reseau (2026-09-04)

- Les regles `Starlink-Shield` existantes ne sont pas remplacees sans plainte client ciblee ou preuve correlee. Aucun historique applicatif Starlink/Shield n est actuellement disponible ; les compteurs RouterOS deviennent la preuve d observation de depart.
- Les contournements DNS directs, DoT et DoQ sont d abord detectes et historises avec client, protocole, destination, compteur et volume. Le blocage ne vient qu apres une periode de suivi et validation de l impact.
- Un flux TCP 443 ne suffit jamais a identifier DoH. L interface doit distinguer preuve, destination connue et simple suspicion afin de ne pas classer le HTTPS ordinaire comme contournement DNS.
- L historique DNS doit rester borne, agrege et collecte par un chemin mutualise afin de proteger la charge RouterOS. Il sera ajoute a l interface Securite DNS seulement apres validation de cette charge.
- Le serveur `10.10.10.2` et WireGuard sortent du plan actif. Les fonctions de reecriture, planification, services et rollback visibles restent facultatives.
- Les VLAN sortent du plan actif : le risque prioritaire n est pas un client interne mais les scans et restrictions opaques percus sur le chemin Starlink. L interface doit privilegier une chronologie probante des evenements WAN (source, destination, port, compteurs, effet et contexte) et distinguer strictement une observation d une attribution a l operateur.

## 31. Acces Web LAN Et Debits CHR (2026-09-13)

- Sur le CHR `dev_1775288316` (`10.10.10.1`), les 17 profils Hotspot, y compris `default`, ont ete regles et relus a `1M/1M` sur RouterOS, source canonique.
- Deux exceptions IP walled garden autorisent TCP 80 et 443 vers `10.10.10.2` pour tous les clients Hotspot, sans restriction de source IP, MAC ou serveur Hotspot. Les regles dynamiques filter/NAT correspondantes ont ete verifiees. Cela concerne l acces web, sans changement de politique DNS.
- Le serveur ecoute sur toutes les interfaces en HTTP/HTTPS et son pare-feu actif accepte les connexions. Le test HTTP local renvoie 200 ; le test depuis un autre poste LAN reste a effectuer.

## 32. Inventaire LAN Par Instantané SQL (2026-10-02)

- L'inventaire LAN MikroTik n'est plus lu à l'ouverture de `dhcp_leases.php` : un bouton enregistre un instantané RouterOS brut en SQL (10 derniers conservés par `device_id`), la page reconstruit la vue depuis SQL.
- Les liens topologiques ne sont jamais déduits du nom ou de `/ip/neighbor` seul (réseau L2 plat, MAC de voisin écrasée par la traduction MAC des CPE) ; seule la MAC partagée par plusieurs IP ou un rattachement manuel explicite crée un lien.
- `api/dhcp_conformity_matrix.php` et `api/dhcp_network_inventory.php` sont supprimés ; leur logique vit dans `includes/lan_inventory.php` (source unique).


## 2026-10-04 - Rétention des échantillons WAN : 120 jours (option A)

- `includes/mikrotik_wan_usage_collector.php` purge `mikrotik_wan_usage_samples` au-delà de 120 jours (auparavant 400 jours). Choix validé par l'administrateur.
- Cette purge automatique est une exception explicite à la décision du 2026-09-24 : elle ne concerne que cette table de consommation WAN. Les autres historiques restent sans purge automatique et relèvent de la maintenance manuelle.
- Aucune donnée n'est concernée aujourd'hui : les échantillons commencent au 2026-09-21.
- `gap_reset` signale une interruption de collecte de plus de 300 secondes, pas une remise à zéro des compteurs du routeur. Les compteurs sont cumulatifs et conservés en SQL, les redémarrages du routeur ne sont pas une cause de trou.
- Suppression du trou dans l'historique WAN (2026-10-04) : la branche `gap_reset` qui mettait le trafic d'une interruption à 0 est retirée. Les compteurs étant cumulatifs, le delta est calculé sur la période réelle. Si un compteur diminue (remise à zéro), le delta repart de la valeur courante.
- Verrou du collecteur WAN : le script attend jusqu'à 45 s au lieu de sortir silencieusement, et journalise via `error_log` s'il n'a pas pu s'exécuter.
- Affichage : le cumul mensuel de la barre sous la courbe est exprimé en To.

## 2026-10-04 - Règle de connexion N-WAN : exclusion des WAN HS

- `mikrotikBuildNwanLoginHook` n'affecte une nouvelle IP qu'à un WAN actif (`running`, non désactivé, donc aussi non coupé par quota) dont le client DHCP est `bound`. Un WAN HS reçoit un poids effectif de 0.
- Sans aucun WAN disponible, la session Hotspot est retirée et un message est journalisé.
- Les sessions déjà affectées gardent leur WAN jusqu'à déconnexion.
- Les interfaces viennent de la configuration WAN (`multiWanConfigWeights`, clés `wan1_interface`, `wan2_interface`).
- Installé sur les 17 profils le 2026-10-04 (17 mis à jour, vérifiés). Validation réelle à faire : une connexion dans un WAN HS simulé n'est pas encore testée.

## 2026-10-04 - Débit dégradé par profil (stockage et saisie)

- Le débit dégradé est saisi dans l'écran de profil (« Débit dégradé », format `3M/3M`) et stocké dans la métadonnée `on-login` du profil RouterOS, en 9ᵉ champ, après le quota. Format Mikhmon préservé : les champs existants ne changent pas.
- Valeur absente : `-` à la lecture, aucun repli. Valeur mal formée : refusée avant écriture.
- Cette étape ne change pas le débit appliqué. La bascule vers le débit dégradé dépend de la machine d'états WAN (P2), non implémentée.
- Saisie du débit dégradé : mêmes champs UPLOAD/DOWNLOAD (valeur + unité K/M) que le débit normal. Le débit est composé côté serveur par `buildRateLimitFromParts`. Une saisie partielle ou non positive est refusée.
- Débit dégradé 3M/3M écrit dans la métadonnée `on-login` de 15 profils Hotspot (tous sauf TV et default) le 2026-10-04 : 15 mis à jour, relecture vérifiée. Débit normal et reste du script inchangés. TV et default ne sont pas modifiés.

## 2026-10-04 - OPNsense reste en pause (remplace la mention « actif » du 2026-10-03)

- Décision de l'administrateur : OPNsense reste en pause. Son code n'est pas retiré (les fichiers restent en place), et aucune page ne doit le choisir comme backend ou fallback (voir décision 24).
- Les fichiers qui ne sont plus appelés par le runtime sont renommés en `.old` pour contrôle, pas supprimés.
- Quatre fichiers renommés le 2026-10-04 (zéro référence trouvée dans le dépôt et dans la configuration serveur) : `api/debug_mikrotik_log_raw.php.old`, `api/get_cpu_stats.php.old`, `api/sync_vouchers.php.old`, `api/users/sync_opnsense_shaper.php.old`.

## 2026-10-05 - Machine d'états WAN (P2) : code pur, non activé

- `includes/wan_state.php` : états NORMAL, DEGRADED, DOWN, RECOVERING, avec retour à NORMAL après 3 cycles stables. Les entrées viennent de l'état natif RouterOS (interface, DHCP bound, route active) ; PHP ne fait que calculer la transition.
- Aucune écriture sur le routeur à ce stade. L'application (débit, réaffectation, alerte) reste à faire et à valider avec l'administrateur.

- Procédure de retour arrière P2 (2026-10-05) : sauvegardes en lecture seule (`router_snapshot_*.json`, `wan_lists_*.json`) et étapes de retour dans `ROLLBACK_P2.md`, dossier de travail. Une écriture à la fois, relecture après chaque écriture.

## 2026-10-05 - Première écriture P2 : journal seul

- Script RouterOS `RM-WAN-STATE-JOURNAL` (commentaire « aucune action ») ajouté et exécuté une fois. Il écrit une ligne `RM-WAN-STATE` dans le journal, et rien d'autre : ni débit, ni affectation, ni interface.
- Vérifié : la ligne apparaît dans `/log`.
- Retour arrière : supprimer le script `RM-WAN-STATE-JOURNAL` (`/system/script/remove`). La ligne déjà écrite reste dans le journal, sans effet.
- Prochaine étape : journaliser uniquement les transitions réelles, pas à chaque cycle.

## 2026-10-05 - Journal des transitions WAN branché (P2, journal seul)

- `scripts/monitor_network_quality.php` (cycle existant, chaque minute) appelle `wanStateJournalCycle` après chaque collecte. L'état est lu sur le routeur (interface, DHCP, route par défaut), comparé à la dernière transition du journal, et une ligne `RM-WAN-STATE` n'est écrite que si l'état change.
- Aucune action sur le trafic : pas de débit, pas d'affectation, pas d'interface. Une erreur du journal n'interrompt jamais la collecte.
- Stabilité du retour à NORMAL : 3 minutes, mesurées depuis la dernière transition du journal.
- Vérifié : WAN lus depuis la configuration (WAN1=ether1, WAN2=ether2), état actuel NORMAL 2/2, aucune écriture.
- Non vérifié : une transition réelle (un WAN HS), qui n'a pas encore eu lieu.
- Coût : une connexion API supplémentaire par minute pour le journal.

## 2026-10-05 - Alerte d'état WAN (P2)

- Chaque transition d'état écrit une notification dans `admin_notifications` (catégorie `wan_state`) : critique pour DOWN, avertissement pour DEGRADED, information pour le retour. Une seule notification par transition.
- Aucune notification sans transition. Vérifié : aucune notification créée au cycle courant (état NORMAL). Non testée sur une transition réelle.

## 2026-10-06 - Test login par MAC sur hsprof1 : réussi

- `hsprof1` accepte `mac` dans `login-by` (en plus de `http-chap` et `http-pap`), réglage `mac-auth-mode = mac-as-username`.
- Le compte de test porte comme nom la MAC de l'appareil (`04:B4:29:44:6D:7D`), sans mot de passe, profil `default`. Le nom « test-mac-gaga » avait été refusé : en mode mac-as-username, RouterOS cherche le compte par la MAC.
- Confirmé : à 00h02, connexion par MAC réussie (`login-by: mac`), sans mot de passe. Le compte de Gaga n'a pas été modifié.
- Reste à faire : supprimer le compte de test et retirer `mac` de `login-by` si on ne convertit pas d'autres appareils.

## 2026-10-06 - Comptes Hotspot en login par MAC : création (backend + formulaire)

- Mode « MAC (sans mot de passe) » dans le formulaire d'ajout : le nom et la `mac-address` du compte RouterOS sont la MAC (format AA:BB:CC:DD:EE:FF, majuscules), aucun mot de passe. Le serveur `hsprof1` doit garder `mac` dans `login-by`.
- Le libellé (ex. « Gaga ») est enregistré dans la table `hotspot_mac_labels` (SQL) : affichage uniquement. Il n'est pas écrit dans le commentaire RouterOS, qui reste réservé à l'expiration.
- Un échec d'enregistrement du libellé n'annule pas la création sur le routeur (journal d'erreur seulement).
- Pas de compte SQL `users` pour un device MikroTik (inchangé).
- Affichage du libellé à côté du nom dans `sessions_list.php` et `users_list.php` : fait.
- Vérifié dans le navigateur : les trois pages s'affichent sans erreur, et le bascule du mode MAC fonctionne.
- Non encore fait : création réelle d'un compte MAC depuis le formulaire (écriture sur le routeur, à valider avec l'administrateur).

## 2026-10-06 - Fiche de détail et modification des comptes MAC

- Affichage : le type de compte (« Identifiant + mot de passe » ou « MAC (sans mot de passe) ») et le libellé sont chargés depuis chaque ligne. Pour un compte MAC, le nom affiché est le libellé, et le champ MAC est en lecture seule dessous.
- Règle de modification : un compte MAC garde la MAC comme nom RouterOS (jamais le libellé). Le client envoie `mac`, aucun mot de passe. L'endpoint enregistre `mac-address` et n'écrit pas de mot de passe.
- Le mot de passe est désactivé pour un compte MAC, y compris en mode édition.
- Cache : les scripts sont versionnés (`users_list.js?v=20261006a`, `add_hotspot_user.js?v=20261006a`) pour forcer le rechargement après modification.
- Non testé : l'enregistrement réel d'une modification sur un compte MAC (écriture sur le routeur, à valider).

## 2026-10-06 - Types de compte Hotspot et champs standardisés

- Deux types de compte, choisis dans le formulaire d'ajout et lus sur la fiche :
  1. Identifiant + mot de passe : nom, mot de passe. Une MAC peut être ajoutée en option pour restreindre le compte à un seul appareil (champ « MAC (restriction) », enregistré en `mac-address`).
  2. MAC (sans mot de passe) : le nom RouterOS est la MAC (mac-as-username), libellé obligatoire, aucun mot de passe. Le serveur `hsprof1` doit garder `mac` dans `login-by`.
- Les champs non utilisés par le type choisi sont masqués (et non envoyés), dans le formulaire d'ajout comme dans la fiche de détail.
- Enregistrement d'un compte MAC : le nom reste la MAC (jamais le libellé), aucun mot de passe. Enregistrement d'un compte avec mot de passe : le mot de passe reste en place si vide ; la restriction MAC est mise à jour si renseignée. Vider une restriction MAC n'est pas encore géré (RouterOS refuse une valeur vide).
- Non testé sur le routeur : création d'un compte avec restriction MAC, modification d'un compte existant.

## 2026-10-06 - Tableau de bord : événements Hotspot

- Les lignes d'une adresse MAC sont reconnues avant le découpage « utilisateur : action » (une MAC contient des deux-points). Avant, la MAC était coupée et apparaissait comme « 00 » ou « 11:22:33:44:55 ».
- Les tentatives de login par MAC qui échouent (« trying to log in by mac », « login failed ») sont masquées : elles ne traduisent pas une connexion réelle.
- Une MAC connue (table des libellés) est affichée avec son libellé dans les événements.
- Vérifié : les 12 derniers événements affichés ne contiennent plus de tentative MAC. Le libellé d'une connexion MAC réussie n'est pas encore observé (pas de connexion MAC réussie dans la fenêtre).
- Source unique des événements du tableau de bord : `includes/hotspot_log_events.php` (`hotspotLogEventsFromRows`). La page `get_stats.php` et le flux `dashboard_stream.php` l'utilisent tous les deux ; leurs copies locales ont été supprimées. Vérifié : aucune ligne MAC dans les deux sources.

## 2026-10-06 - Portail captif : messages en français (modèles Build)

- Titres et libellés traduits dans les modèles de référence (`uploads/portal_captive/templates/Mikrotik Templace Build`) : « Hotspot Internet - Connexion / Erreur / Déconnexion », « Statut », « Identifiant », « Adresse MAC ».
- Messages d'erreur renvoyés par RouterOS (anglais) traduits par une petite table dans `login.html` et `error.html` : identifiant ou mot de passe incorrect, nombre de connexions maximum, connexion déjà en cours, session expirée. Un message inconnu reste inchangé.
- Non encore en service sur le routeur : les modèles doivent être redéployés (injection), puis la page `/status` vérifiée sur un appareil.
- `portal_profiles.php` ne montre aucun message anglais ; ses messages du contrôleur sont déjà en français.

## 2026-10-06 - Modèles portail : traduction du modèle actif et du modèle par défaut

- Modèle actif `captive_dev_1775288316_20260914_000631.zip` et modèle par défaut `default_mikrotik.zip` : titres, libellés, « MAC address », messages internes de la page de statut traduits en français ; table des messages RouterOS ajoutée aux pages de connexion et d'erreur.
- Sauvegardes des originaux : `*.zip.old` (même dossier).
- Archives rebuilt et vérifiées : valides, aucun texte anglais visible restant.
- Non encore déployé sur le routeur : le modèle actif installé sur le routeur reste l'ancien tant qu'il n'est pas redéployé depuis `portal_profiles.php`.
- Déploiement du modèle actif le 2026-10-06 via `portal_profiles.php` (formulaire « Préparer et déployer le template ») : profil `hsprof1` → répertoire `hotspot_rm_20261005_224408_983f35`. Vérifié sur le routeur : le profil utilise ce répertoire et la page de connexion est présente (6488 octets). Ancien répertoire non supprimé.

## 2026-10-06 - Portail : message « déjà en cours » trompeur

- Cause : deux demandes de connexion envoyées presque en même temps (double envoi du formulaire). La seconde reçoit « already authorizing, retry later » alors que le mot de passe est faux. Dans le journal, les mots de passe faux donnent très majoritairement « invalid username or password ».
- Correction (modèles de référence et archives actif et par défaut) : message neutre « Vérification en cours, patientez quelques secondes puis réessayez. » et garde contre le double envoi du formulaire de connexion.
- Non encore déployé sur le routeur : redéploiement nécessaire depuis `portal_profiles.php`.
- Redéploiement du 2026-10-06 (message neutre et garde contre le double envoi) : `hsprof1` → `hotspot_rm_20261005_224717_dab448`, vérifié sur le routeur. Les répertoires précédents sont conservés pour retour arrière.
- Page de connexion : le bouton « Se connecter » est grisé dès le premier envoi (libellé « Connexion en cours… »). Vérifié sur une copie locale. Archives actif et par défaut mises à jour ; non encore redéployées. Incident : le fichier de référence `login.html` a été vidé par erreur pendant la mise à jour, puis restauré depuis l'archive active (même contenu, vérifié).
- Messages de limite (page de connexion et d'erreur) : « your uptime limit is reached » → « Limite de temps de connexion atteinte. » ; « your traffic limit is reached » → « Limite de données atteinte. » ; validité expirée (mot-clé « expired », texte RouterOS non observé dans le journal) → « La validité de ce compte est expirée. ». L'ancien message générique « user limit reached » est retiré. Non redéployé.
- Table des messages d'erreur reconstruite à partir du fichier d'origine du routeur `hotspot/errors.txt` (lu en lecture seule). Les messages de limite étaient incorrects dans la version précédente (le texte réel est « user X has reached uptime limit »). Validité : pas de message d'origine ; un compte expiré est supprimé et affiche « invalid username or password ». Messages de déconnexion retirés de la table. Ajoutés : restriction MAC (« is not allowed to log in from this MAC address », « this MAC address is not yours »). Testé sur les textes exacts. Non redéployé.
- Table validée (16 messages) à partir des 17 clés de `hotspot/errors.txt` ; `radius-reply` n'est pas traduit (message RADIUS variable). Appliquée aux modèles de référence et aux deux archives. Non redéployée.
- Redéploiement du 2026-10-06 avec la table validée : `hsprof1` → `hotspot_rm_20261005_230249_91576a`.
- Nettoyage du test du 2026-10-06 : compte `02:00:00:00:00:01` (identifiant `*36F`) supprimé du routeur, et sa ligne de libellé supprimée de `hotspot_mac_labels`. Vérifié : compte absent après suppression.

## 2026-10-06 - Bascule des débits WAN (P2) activée

- Règle validée : plafond global de téléchargement = 25 Mb/s × nombre de WAN actifs. En NORMAL (2 WAN), le plafond reste à 100 Mb/s.
- En DEGRADED : file « RM interface bridge1 » à 20M/25M ; profils avec débit dégradé (métadonnée on-login) passés à leur valeur dégradée (3M/3M). Les valeurs d'origine sont sauvegardées (table `wan_rate_backup`).
- En NORMAL : restauration complète depuis la sauvegarde.
- Les profils ne changent que pour les nouvelles connexions ; les clients connectés gardent leur débit jusqu'à reconnexion, sauf le plafond global qui s'applique immédiatement.
- Testé : aller-retour DEGRADED puis NORMAL sur le routeur, état identique à l'origine.
- DOWN (aucun WAN actif) : aucun changement de débit (non prévu dans la règle).
- Cas DOWN (aucun WAN actif) : alerte critique seulement, aucun changement de débit (décision du 2026-10-06).
- Etat WAN affiché sur la page monitoring (badge à côté de « Total par heure ») : lu sur le routeur à l'ouverture (`api/wan_state_status.php`, lecture seule). Vérifié : « Normal (2/2) ».
- P2 (mode dégradé) : critères de fin couverts sauf validation d'une panne réelle avec clients connectés.
- P2 clôturé le 2026-10-06. Les tests en conditions réelles sont regroupés en fin de `PROJECT_PLAN.md` pour être lancés ensemble, avec l'accord de l'administrateur.
- AC2-AndryS2N (2026-10-06) : `ether1` retirée du bridge1 (lien montant routé, masquage appliqué). Pare-feu ajouté : connexions établies acceptées ; gestion autorisée depuis le réseau CHR (10.10.10.0/23) sur ether1 ; le reste du lien montant est bloqué. Retour arrière : supprimer les 3 règles RM et remettre ether1 dans bridge1.

## 2026-10-08 - AC2-AndryS2N : sessions multiples sous une même MAC (cause et correctif)

- Cause trouvée : le profil `MAC-10M` avait un `address-pool` (`dhcp`), qui attribue une adresse de secours à chaque nouvelle session MAC. Le NAT de l'AC2 fonctionne correctement (confirmé : 130/130 connexions suivies traduites vers `10.10.11.218`) ; les adresses multiples venaient du pool, pas d'une fuite du routeur client.
- Corrigé : `address-pool = none` sur `MAC-10M` (valeur d'origine `dhcp` sauvegardée). Purge des sessions/hôtes fantômes d'AndryS2N. Vérifié : une seule session réelle après reconnexion.
- Anomalie associée trouvée et corrigée : `parent-queue` de `MAC-10M` était à `none`, contrairement à tous les autres profils (`RM interface bridge1`). Remis en cohérence avec le reste du parc (valeur d'origine `none` sauvegardée, `scratchpad` de session).
- `shared-users` reste à 5 sur `MAC-10M` (décision de l'administrateur), car le risque de blocage par une session fantôme existait tant que l'anomalie du pool n'était pas corrigée. À réévaluer maintenant que le correctif est en place.

## 2026-10-08 - File d'attente hotspot : tampon `default-small` trop petit, puis passage en RED

- Cause d'une instabilité de débit observée (test Nperf sur AndryS2N, 10 Mb/s puis chute vers 1 Mb/s) : le type de file `default-small` (PFIFO, utilisé par défaut par toutes les files dynamiques créées par le hotspot) n'a qu'un tampon de 10 paquets. À débit limité (10 Mb/s), ce tampon se remplit et perd des paquets beaucoup plus vite qu'à débit élevé (20 Mb/s), ce qui déclenche l'effondrement classique du débit TCP après perte.
- Corrigé en deux temps, sauvegarde des valeurs d'origine à chaque étape :
  1. `pfifo-limit` de `default-small` : 10 → 50.
  2. `kind` de `default-small` : `pfifo` → `red`, avec les seuils d'usine de RouterOS (`red-limit=60`, `red-min-threshold=10`, `red-max-threshold=50`, `red-burst=20`, `red-avg-packet=1000`), pour éviter les rejets brutaux en fin de tampon plutôt que de simplement l'agrandir.
- Ce réglage est global : il s'applique à toutes les files dynamiques du hotspot, pas seulement à AndryS2N.
- Suivi en cours : relevé de référence pris après reconnexion propre d'AndryS2N sous RED (08/10 20:27:32). Comparaison avant/après non encore concluante au moment de la rédaction (faible trafic lors des relevés). La revue sur l'ensemble du parc (48 comptes actifs) montre que les compteurs de pertes cumulés mélangent l'ancien PFIFO et le nouveau RED pour la plupart des comptes non reconnectés ; pas de reconnexion générale forcée (trop disruptif sans accord explicite).
- Alternative envisagée et écartée pour l'instant : PCQ (file unique par interface avec répartition automatique par client), qui remplacerait l'architecture actuelle d'une file par compte. Jugé comme un chantier séparé, pas une correction rapide.

## 2026-10-09 - DNS du CHR : retrait du DoH Cloudflare, passage en DNS classiques

- Constat : `servers` était vide et `use-doh-server` pointait sur Cloudflare (`https://cloudflare-dns.com/dns-query`) depuis toujours (jamais modifié depuis l'interface `traffic_control.php`). Confirmé par la documentation RouterOS : tant que `use-doh-server` est renseigné, il est utilisé exclusivement pour toutes les résolutions, sans repli automatique sur `servers` en cas d'échec (SERVFAIL direct).
- Hypothèse à l'origine du changement : des utilisateurs rapportent des difficultés à charger Facebook (photos/vidéos) et parfois d'autres sites. Un résolveur DNS tiers (Cloudflare) peut faire répondre les CDN (dont Meta) avec un serveur de périphérie mal placé, faute d'information de localisation transmise à la requête.
- Changement appliqué (sauvegarde de l'état précédent conservée en session) : `use-doh-server` vidé, `servers = 8.8.8.8,1.1.1.1,1.0.0.1` (8.8.8.8 prioritaire, décision de l'administrateur).
- **Ce changement reste un test.** Le chantier éventuel décrit ci-dessous (résolution DNS séparée par WAN) dépend entièrement de son résultat : si le passage en DNS classiques ne change rien au problème signalé, l'hypothèse DNS/CDN est à écarter avant d'investir dans une refonte.
- Limite connue, non corrigée : le marquage de routage WAN1/WAN2 (`mangle`, chaîne `prerouting`) ne s'applique qu'au trafic transitant par le routeur, jamais au trafic que le routeur génère lui-même. La résolution DNS (requêtes redirigées vers le service DNS local) suit donc toujours la table de routage `main` (WAN1 prioritaire, WAN2 en secours), quel que soit le WAN réellement affecté au client. Conséquence possible : pour un client sur WAN2 (80 % des comptes), le serveur CDN choisi peut être optimisé pour le chemin WAN1. Aucune correction rapide n'existe (RouterOS n'a qu'un service DNS global, non routable par client) ; une vraie correction demanderait deux chemins de résolution distincts, un chantier séparé à n'engager que si le test DNS classique ci-dessus ne suffit pas.

## 2026-10-11 - Base SQL saturée (99% du disque) : cause, nettoyage et correctifs structurels

- Incident : disque du conteneur LXC plein (365 Mo libres sur 20 Go), provoquant des erreurs SQL sur `traffic_monitoring.php`. Cause reelle : pas un bug de page, le disque ne permettait plus a MySQL d'ecrire.
- Nettoyage non applicatif (caches d'outils de dev, VS Code Server, Codex, Playwright, journal systeme) : ~8 Go liberes.
- Cause applicative identifiee : 4 tables de collecte trafic (`mikrotik_traffic_dns_client_signature_samples`, `mikrotik_traffic_dns_signature_samples`, `mikrotik_traffic_samples`, `mikrotik_traffic_user_usage_samples`) sans retention, alimentees chaque minute par `scripts/monitor_mikrotik_traffic.php` depuis le 14-15 septembre.
- Verifie par grep exhaustif : les deux tables `*_dns_*_signature_samples` (4,1 Go + 647 Mo) n'etaient lues par **aucune page**. L'onglet "Signatures DNS" de `traffic_control.php` est calcule en direct depuis l'etat du routeur, pas depuis ces tables. Ecriture desactivee (`api/mikrotik_traffic_listener.php`), tables videes (`TRUNCATE`).
- `mikrotik_traffic_samples` : la colonne `details_json` (duplication de `top_users`/`top_domains`/`top_rules`, jamais relue non plus) representait 78% du poids de la table (313 Mo sur 400 Mo). Ecriture desactivee, contenu vide, table reduite a 8 Mo.
- Retention 120 jours ajoutee sur `mikrotik_traffic_samples` et `mikrotik_traffic_user_usage_samples`, meme principe que `mikrotik_wan_usage_samples` (`includes/mikrotik_wan_usage_collector.php`).
- Export complet (`api/admin/export_database.php`) : exclut desormais les donnees de ces 4 tables (`--ignore-table`), garde leur structure (`--no-data`) pour rester restaurable a l'identique.
- Resultat : disque a 11 Go libres (45%) apres purge, DB en regime stabilise ~3,5-4 Go projetes sur 120 jours (au lieu d'une croissance illimitee).

## 2026-10-11 - Classement utilisateurs mensuel (traffic_control.php) : tableau vide et page lente, corrige

- Cause 1 (lenteur) : le mode "Mois" recalculait le classement par client en scannant ~1 million de lignes de la table brute `mikrotik_traffic_user_usage_samples` a chaque chargement (verifie par `EXPLAIN`).
- Cause 2 (tableau vide malgre tout) : le mode mois etait meme **volontairement exclu** du calcul cote serveur (commentaire dans le code), et le frontend (`js/traffic_listener.js`) lisait de toute façon un champ different (`cycle_users`) de celui du mode jour (`all_users`) — jamais alimente.
- Correctif : nouvelle table `mikrotik_traffic_user_usage_daily` (une ligne par client et par jour), remplie une seule fois par jour (fenetre de 10 min apres minuit, idempotent), pas en continu. Le mode mois lit desormais cette synthese (quelques milliers de lignes) au lieu de la table brute. Gain verifie par `EXPLAIN` : 908 lignes scannees au lieu de 1 061 278.
- Le champ `cycle_users` cote frontend a ete retire (doublon avec `all_users`, qui sert maintenant aux deux modes).
- Rattrapage ponctuel execute : synthese reconstruite depuis l'historique brut existant (14 sept. -> 11 oct.), donc le mois en cours n'a pas eu besoin d'attendre pour etre complet.
- Verification demandee par l'administrateur : les volumes eleves observes (ex. Tsiltam, ~180 Go sur le mois) sont coherents avec le profil du compte (`500G-Kit`, 6 Mb/s, plafond theorique ~63 Go/jour) : pas d'erreur de delta detectee.
- Nettoyage associe : 3 fonctions mortes supprimees (`trafficListenerRouterClockOffsetSeconds`, `trafficControlMaybeReapplyNwanEngine`, `forceTrafficChartPalette`), aucune n'avait d'appelant dans le projet.

## 2026-10-15 - user_logs.php : historique perdu en ~2h, limite de 100 profils, nettoyage operation_history/recharge_history

- Cause (historique court) : la page lisait `/log/print` en direct sur RouterOS a chaque chargement. Ce buffer est volatile (`memory-lines=1000`), toutes rubriques confondues (pas que hotspot) ; verifie en direct : il se reecrit entierement en ~2h16 sur ce reseau (volume de connexions eleve).
- Correctif : nouvelle table locale `mikrotik_hotspot_log_events` (archive, pas de doublon avec une table existante), alimentee par un cron dedie toutes les 2h (`scripts/monitor_mikrotik_hotspot_logs.php`, verrou fichier comme les autres collecteurs), retention 48h (purge a chaque passage). Taille estimee ~6,5 Mo sur 48h (mesure : ~521 lignes/heure, ~64 caracteres/message). `user_logs.php` lit desormais cette table au lieu du routeur en direct.
- Limite de 100 profils (`buildMikrotikProfileMap()` -> `getMikrotikHotspotUsers(100, ...)`) : fausse limite. La fonction lisait deja TOUS les comptes depuis RouterOS (aucun parametre de limite envoye a l'API), elle ne faisait qu'un `array_slice` en sortie. Change en `getMikrotikHotspotUsers(0, ...)` (0 = pas de troncature) : aucun cout supplementaire.
- `operation_history` et `recharge_history` retires de `user_logs.php` (decision : ces donnees relevent de la notification admin, pas de ce journal) : `loadOperationHistoryRows()`, `normalizeOperationRows()`, `loadRechargeHistoryRows()`, `normalizeRechargeRows()` et leurs helpers orphelins (`formatAmountLabel`, `rechargeModeLabel`) supprimes de `includes/user_logs_backend.php` (aucun autre appelant verifie par grep).
- Effet de bord positif : la page ne fait plus qu'une seule connexion RouterOS par chargement (profils) au lieu de deux (logs + profils) - chargement mesure a ~0,3s.
- Point de vigilance : cron a 2h fixe, alors que le buffer source observe se reecrit en ~2h16 - marge faible si le volume de connexions augmente. A surveiller (verifier `SELECT COUNT(*) FROM mikrotik_hotspot_log_events` reste coherent avec le taux horaire attendu).
