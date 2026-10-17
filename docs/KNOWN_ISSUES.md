# Bugs Et Problemes Connus

## 2026-10-02 - Planificateurs d expiration dupliques (Priorite 8)
Migration realisee le 2026-10-02 sur le CHR `dev_1775288316` :
- Sauvegarde RouterOS prealable : `pre-scheduler-merge-20261002-210511.backup` (483 Ko), presente sur le routeur.
- Planificateur unique `RM-PROFILE-EXPIRY` cree (intervalle 2m30s), couvrant les 16 profils avec leur mode exact (6 en `remove`, 10 en `set limit-uptime=1s`), liste tenue dans son commentaire RouterOS (lisible, pas de parsing de script).
- Valide avant suppression : premiere execution reelle confirmee (`run-count` 0 -> 1), aucune entree de log d erreur, aucun nouvel utilisateur bloque en expiration par rapport a l etat precedent.
- Les 16 anciens planificateurs individuels supprimes ; `RM-NWAN-ASSIGN-BOOT` conserve intact (hors perimetre).
- Revalide apres suppression : deuxieme execution reelle confirmee (`run-count` 1 -> 2), comportement identique.
- Code : `ensureMikrotikProfileScheduler()` delegue desormais a `ensureMikrotikSharedProfileExpiryScheduler()` (`includes/mikrotik_backend.php`) ; les 3 points d appel (creation/modification/import de profil) n ont pas eu besoin d etre modifies, seule l implementation interne a change.
- Constat annexe, non lie a la migration (present a l identique avant et apres) : 2 utilisateurs (`B4GYTC` profil `2G`, `ZQKE` profil `Tix-1-heure`) restaient presents malgre une date d expiration largement depassee. Supprimes manuellement le 2026-10-02 sur demande (compte + session active le cas echeant), suppression confirmee par relecture. Cause racine non identifiee (pourquoi le planificateur, ancien ou nouveau, ne les a pas nettoyes) : a surveiller si de nouveaux cas similaires apparaissent.

Etat : fusion terminee et validee en conditions reelles.
- Les 17 planificateurs (un par profil) ne generent pas les scripts d historique : chacun supprime les utilisateurs Hotspot expires de son profil et leur session active, code quasi identique repete 17 fois.
- Fusionnable en un seul planificateur bouclant sur tous les profils ; aucune sauvegarde planifiee (`/system backup`) trouvee sur le routeur, a mettre en place avant tout nettoyage des scripts d historique.

## 2026-10-02 - Fausse alerte doublon rate-limit / queue simple, corrigee (Priorite 8)

- Hypothese initiale invalidee par verification directe : 88 des 90 queues simples `<hotspot-user-N>` sont marquees `dynamic=true` et `parent-queue` du profil pointe vers `RM interface bridge1`. Ce sont des queues creees et detruites automatiquement par RouterOS a chaque connexion/deconnexion, consequence du `rate-limit` deja configure sur le profil — pas un second mecanisme independant.
- Verifie sur le profil `TV` (`rate-limit=3M/3M`) : ses 2 utilisateurs actifs n ont aucune queue nommee correspondante, donc pas d ecart de debit possible par ce biais. Aucune action necessaire sur ce point.

## 2026-10-02 - Correction keepalive-timeout 2m -> 5m (cause des deconnexions clients)

Preuve initiale : sur 215 deconnexions relevees dans les journaux (fenetre ~5h), 207 (96%) avaient pour raison `keepalive timeout`, avec `keepalive-timeout=2m` identique sur les 17 profils Hotspot (valeur par defaut RouterOS, jamais ajustee). Topologie reseau multi-sauts radio (capteur/CPE/routeur client/Wi-Fi) rendant ce delai trop court (micro-coupures, veille Wi-Fi des telephones).

Action realisee le 2026-10-02 sur le CHR `dev_1775288316`, a la demande de l administrateur :
- Sauvegarde des valeurs avant modification (`.id`, nom, keepalive-timeout) conservee.
- `keepalive-timeout` passe de `2m` a `5m` sur les 17 profils (y compris `default`), applique directement sur les 17 a la fois (pas de test prealable sur un seul, decision de l administrateur).
- Verifie par relecture : les 17 profils confirmes a `5m`.

A faire : mesurer le taux de deconnexions `keepalive timeout` sur une fenetre representative apres ce changement et comparer aux 96% de reference, pour valider l amelioration reelle (voir `docs/PROJECT_PLAN.md` si suivi necessaire).

## 2026-10-02 - Adlists DNS reduites a une seule source, sur demande

A la demande de l administrateur, lors de la revue de l onglet Filtrage DNS (`pages/traffic_control.php`) : les 5 Adlists ont ete reduites a une seule, la plus fiable par preuve d activite reelle (429 676 correspondances contre 0 pour les 3 jamais actives, et 1 472 pour celle retiree).

- Conservee : StevenBlack pub/traqueurs (`*5`), seule desormais active.
- Supprimees : StevenBlack contenu adulte (`*9`, 1472 correspondances), AdGuard DNS Filter (`*6`, 0), OISD Full List (`*7`, 0), Dan Pollock List (`*8`, 0).
- Effet concret assume : le blocage des contenus pour adultes est retire, ces sites redeviennent accessibles.
- Sauvegarde de l etat precedent conservee avant suppression.

## 2026-10-02 - Reponse locale de detection de connectivite activee (Android/Windows/Apple)

Troisieme regression signalee le 2026-10-02, plus serieuse : Windows detecte desormais "internet disponible" pour un client PAS authentifie (le portail ne s'ouvre plus du tout). Cause probable : `dns.msftncsi.com` fait partie des 7 domaines redirige ; Windows attend une reponse DNS exacte (131.107.255.255) pour ce domaine specifique, et combine plusieurs signaux (DNS + /connecttest.txt + /redirect) selon une heuristique interne non documentee et variable selon la version de Windows. Impossible a valider de maniere fiable sans un vrai poste Windows pour chaque version/combinaison.

Decision : FONCTIONNALITE DESACTIVEE (les 7 entrees DNS, `disabled=true`), ne pas reactiver sans test exhaustif prealable sur un vrai appareil Windows. Deux regressions reelles distinctes sur ce seul mecanisme (page admin au lieu du portail, puis faux-positif internet) montrent que la redirection DNS globale (tout le monde, authentifie ou non, compensee cote PHP) est trop fragile face aux heuristiques Windows non documentees pour rester activee sans validation terrain poussee. Le gain attendu (reduire les faux "pas d'internet" pour les clients authentifies) ne justifie pas le risque recurrent de casser la detection du portail pour les nouveaux clients.

Deuxieme regression signalee le 2026-10-02 : `http://www.msftconnecttest.com/redirect` affichait toujours la page de connexion admin. Cause : la detection reposait sur une liste figee de chemins par domaine (`/connecttest.txt`, `/ncsi.txt` pour Windows) ; `/redirect` est un troisieme chemin de sonde Windows non couvert, donc ni redirige vers le Hotspot ni traite. Corrige : la detection se fait desormais au niveau du domaine seul (hote connu = traitement applique, quel que soit le chemin) ; un chemin non reconnu sur un domaine de sonde connu recoit une reponse neutre (204) si le client est authentifie, ou la redirection vers 10.10.10.1 sinon, au lieu de tomber sur la page admin par defaut. Reteste : `/redirect` redirige desormais correctement vers 10.10.10.1 pour un client non authentifie.

Regression signalee par l administrateur le 2026-10-02 apres activation reelle : la page de connexion s ouvrait sur `10.10.10.2` au lieu de `10.10.10.1`. Cause trouvee : `10.10.10.2` est exempte du portail captif par IP (walled garden, necessaire pour charger la vraie page de connexion externe) ; les nouvelles entrees DNS y envoyaient directement les sondes de connectivite des appareils PAS ENCORE authentifies, leur faisant sauter entierement le mecanisme Hotspot RouterOS et atterrir sur la page de connexion admin. Corrige :
- `includes/local_connectivity_responder.php` redirige desormais (302) tout appareil non authentifie vers l adresse Hotspot reelle (`10.10.10.1`, lue en base) au lieu de laisser passer vers la page admin.
- Bug trouve en meme temps : `global $pdo;` etait declare apres les `require_once` dans la fonction d autorisation, rendant `$pdo` local a la fonction et cassant silencieusement la verification d autorisation (`Call to a member function query() on null`, avalee par le `catch`). Corrige en declarant `global $pdo;` avant les `require_once`, dans les deux fonctions concernees.
- Re-teste entierement en conditions reelles apres correction : non-authentifie -> redirection 302 vers 10.10.10.1 confirmee ; IP reellement active (10.10.11.5, parmi 44 sessions) -> reponse rapide confirmee.
- Entrees DNS reactivees sur le routeur apres validation complete.

Sur demande de l administrateur ("avant de l activer, il faut verifier si il fonctionne"), verification complete de la politique `connectivity_local_reply` avant activation :

- Couverture confirmee : Android (gstatic.com, clients3/4.google.com), Windows (msftconnecttest.com, msftncsi.com), Apple/macOS/iOS (captive.apple.com) — pas seulement Android.
- Code de reponse deja present (`includes/local_connectivity_responder.php`), mais jamais execute : Apache renvoyait 404 sur `/generate_204`, `/connecttest.txt`, `/hotspot-detect.html` car `AllowOverride` etait desactive par defaut (Debian), empechant le `.htaccess` existant de fonctionner.
- Corrige : ajout d un bloc `<Directory /var/www/html> AllowOverride All </Directory>` dans `/etc/apache2/sites-available/000-default.conf`, `apache2ctl configtest` valide, rechargement a chaud (`systemctl reload apache2`, sans coupure de connexion). Verifie en direct : les 3 chemins repondent desormais exactement ce qu attend chaque systeme (204 vide, "Microsoft Connect Test", page "Success").
- Risque identifie et corrige avant activation : repondre "Succes" a tout le monde, y compris un appareil pas encore connecte, supprimerait l ouverture automatique de la page de connexion du portail captif. Ajout dans `includes/local_connectivity_responder.php` d une verification que l IP source correspond a une session Hotspot deja active (`/ip/hotspot/active`), via un cache fichier de 30s pour ne pas solliciter l API MikroTik a chaque sonde de chaque client. Teste : IP inconnue -> refuse (204/reponses non servies, portail toujours detecte normalement) ; IP reellement active (verifiee parmi 44 sessions reelles) -> acceptee.
- Politique activee sur le routeur (`dev_1775288316`) : les 7 entrees `/ip/dns/static` pointant vers `10.10.10.2` sont actives (`disabled=false`), cache DNS vide pour prise en compte immediate.
- Objectif : reduire les faux signaux "pas d'internet" detectes par le systeme du client (cause plausible de deconnexions Wi-Fi au-dela du `keepalive-timeout` deja corrige) en repondant instantanement en local plutot que de dependre du DoH/WAN distant pour cette verification routiniere, uniquement pour les clients deja authentifies.

## 2026-10-02 - Fausse alerte WAN2, methode de test corrigee (pas de degradation reelle)

Diagnostic approfondi sur demande de l administrateur (inquietude initiale : DNS chiffre / detection Starlink) :

- DoH confirme fonctionnel en reel : 4 connexions TCP persistantes et actives vers 1.1.1.1:443 depuis l IP WAN1 du routeur, cache DNS grossissant de 10 entrees en 5s. Le test initial via `/tool/fetch` qui semblait indiquer une panne TCP totale du routeur etait un faux signal (outil de test defaillant, pas le vrai moteur DoH) ; corrige.
- Confirme par la configuration : le DNS de tous les clients (quel que soit leur WAN assigne pour le trafic general) depend exclusivement de WAN1, car les requetes DNS sont forcees vers le resolveur local du routeur (NAT), dont la destination `local` exclut ces paquets du marquage N-WAN (`dst-address-type=!local`). Risque de redondance identifie mais pas une panne actuelle.
- Scission du DNS par client/WAN ecartee a la demande de l administrateur : reintroduirait un profil de trafic (nombreuses requetes DNS individuelles par appareil) expose a la detection d usage commercial par Starlink, que le DNS chiffre centralise sert justement a eviter.
- Correction retenue pour plus tard (non prioritaire) : netwatch de bascule WAN1/WAN2 par verification reelle (pas juste ping passerelle), sans changer la centralisation du DNS cote client.
- WAN2 (ether2) teste independamment : lien local (1 saut, routeur Starlink) parfait, 30/30, sous la milliseconde. Au-dela (1.1.1.1 et 8.8.8.8), 7/30 reussis (77% de perte) de maniere repetee, peu importe la destination. Cable/port locaux ecartes comme cause : ports sans erreur ni drop (compteurs a 0). Degradation situee dans le reseau Starlink lui-meme (satellite/liaison), hors controle RouterOS. A signaler au support Starlink si persistant.

Etat : DNS/DoH sain actuellement sur WAN1. Le diagnostic « WAN2 degrade cote reseau Starlink » ci-dessus est RETIRE (voir entree 2026-10-03 « Diagnostic WAN2 retire ») ; ne pas le reutiliser comme cause.

## 2026-10-03 - Diagnostic WAN2 retire (correction de l entree 2026-10-02)

- Le diagnostic « WAN2 degrade cote Starlink (77 % de perte vers 1.1.1.1 / 8.8.8.8) » est declare FAUX par l administrateur. Il ne doit pas etre utilise pour expliquer une panne client.
- Cette entree n a pas ete mise a jour a l epoque : le titre disait « pas de degradation reelle » alors que le corps concluait a une degradation. Les deux ne sont plus valables ; seul ce paragraphe fait foi.
- Aucune nouvelle mesure n a ete faite pour cette correction : elle repose sur la declaration de l administrateur. La cause de la perte de connectivite signalee le 2026-10-03 (client AC2-Tnb, seul Facebook fonctionne) reste a etablir.
- Constat recent a conserver : le DNS du routeur (`10.10.10.1`) resout correctement, et le endpoint DoH Cloudflare repond (HTTP 200) depuis le serveur applicatif. Ce test ne prouve pas l absence de coupure passee.

## 2026-10-02 - Anomalies de distribution IP, clarifiees par l administrateur (Priorite 8 / Priorite 4)

Releve lecture seule, croise avec le pool DHCP `10.10.10.110-10.10.11.254` :

- 28 IP statiques hors plage : 16 legitimes (management CPE/AP). Parmi les 12 cotes clients, `10.10.10.103` (MAC `00:11:22:33:44:55`) est confirme par l administrateur comme un routeur a adresse fixe, possiblement eteint au moment du releve — pas une anomalie, juste absent du reseau a cet instant.
- `AC2-IsmL` expose 7 adresses `192.168.88.x` dans la table Hotspot du CHR (NAT interne au lieu d un pont transparent) : confirme par l administrateur comme un comportement normal pour ce routeur, pas une panne a corriger.
- `192.168.0.254` (IP de gestion par defaut TP-Link) et `192.168.11.118` (IP propre a un routeur voisin) restent de simples IP vues par diffusion locale, jamais routees.

Etat : point clos, aucune action requise.

## 2026-10-02 - Accumulation de scripts RouterOS d historique Mikhmon (Priorite 8, audit en cours)

Releve lecture seule sur le CHR `dev_1775288316` :

- `/system script` contient 812 objets nommes comme des journaux (`AAAA-MM-JJ-|-heure-|-user-|-...`), ecrits par les scripts `on-login`/recharge des profils Hotspot (technique Mikhmon de persistance sans base de donnees).
- Tous ont `run-count=0` : ce sont des traces, pas de l automatisation executee.
- Croissance continue observee : 61 en juillet 2026, 386 en aout, 348 en septembre, 16 debut octobre.
- Recherche dans le code applicatif : aucune page/API ne lit ces scripts par leur nom date ; seuls les scripts nommes (`RM-NWAN-ASSIGN`, scripts de traffic control, de session WAN) sont utilises.
- 17 planificateurs (un par profil commercial) tournent toutes les 2-3 minutes en continu ; cause probable de l ecriture repetee de ces scripts-journaux.
- 89 queues simples `<hotspot-user-N>` limitees a 4 Mbps, a rapprocher du `rate-limit` deja present sur les profils Hotspot correspondants avant de conclure a un doublon.

Etat : observation consignee, aucune modification RouterOS effectuee. Avant toute suppression : confirmer qu aucun outil externe (sauvegarde, export, support) ne lit cet historique, puis proposer un filtre de nettoyage précis (nom/age) a valider.

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

### Tableau DHCP compact et chargement progressif

- Les lignes observées utilisent la correspondance ARP IP/MAC exacte et l'identité du voisin de cette même IP/MAC ; elles n'affichent plus les MAC et noms regroupés de l'inventaire. Commentaire vide, badge S pour une IP hors pools, sinon type inconnu ; statut ARP court conservé, sans faux statut DHCP `bound`.
- L'API transmet les données du tableau puis les résultats des sondes TCP en deux messages NDJSON. Le navigateur affiche les lignes avant la fin des sondes et ajoute ensuite les liens HTTP.
- Validation : syntaxe PHP/JavaScript et test du flux fractionné confirmant la résolution des données avant les sondes puis l'ajout des liens. Vérification visuelle en session utilisateur restante.

Correction d'affichage demandée : seul le port 80 est désormais sondé. L'IP seule devient un lien HTTP si accessible ; les mentions de ports et le texte de chargement/compteur ajoutés au tableau sont retirés pour préserver sa mise en page. Les IP observées restent dans le tableau principal.

### Tableau principal DHCP — accès HTTP et HTTPS

- Les IP observées sans bail sont intégrées au tableau principal, à sa recherche et à son tri ; un compteur annonce leur chargement.
- Les liens HTTP (80) et HTTPS (443) sont affichés selon l'accessibilité TCP depuis le serveur, avec préférence HTTPS sur le lien principal et ouverture dans une nouvelle fenêtre. Sonde limitée à 32 connexions simultanées (16 IP).
- Validation : syntaxe PHP/JavaScript, sondes locales HTTP seul/HTTPS seul et génération des liens avec ports ouverts/fermés. Une connexion TCP ne valide pas le certificat TLS ni la nature du service. Validation visuelle en session utilisateur encore ouverte.

### 2026-09-30 - IP observées dans la liste DHCP et liens de gestion

- La liste affiche aussi les IP LAN de l'inventaire absentes des baux, marquées « Observé — sans bail », sans sélection ni action DHCP. Les baux RouterOS ne sont pas créés ou modifiés.
- Une lecture d'inventaire partagée alimente la liste et la fenêtre d'inventaire. Les adresses répondant en TCP sur le port 80 depuis le serveur applicatif deviennent des liens HTTP ouvrant une nouvelle fenêtre, avec `noopener noreferrer`.
- La sonde porte uniquement sur les IPv4 LAN issues du routeur actif, par groupes de 32 et délai de 800 ms ; aucune IP arbitraire n'est acceptée depuis le navigateur. Une réponse TCP ne garantit ni la nature de la page HTTP ni son accessibilité depuis le poste utilisateur.
- Validation : syntaxe PHP/JavaScript, tests d'inventaire, réponses positives réelles de `10.10.10.50` et `.51`. Vérification visuelle en session utilisateur encore nécessaire.

### 2026-09-30 - Inventaire des équipements LAN visibles seulement dans ARP

- L'inventaire inclut les MAC observées dans ARP sur le LAN DHCP/Hotspot, même sans annonce dans les voisins ; une MAC déjà annoncée ne crée pas une seconde ligne.
- La source de détection est affichée. Sans bail DHCP et avec une IP hors des pools configurés (y compris les pools chaînés), l'état est « Statique probable — hors pool DHCP ». ARP seul ne prouve pas la configuration du CPE ; un bail réservé reste distingué de cette observation.
- Les identités des voisins, noms DHCP et noms DNS statiques du routeur sont affichés avec leur source. ARP ne fournit pas de nom d'hôte ; aucun nom n'est inventé lorsqu'il n'est pas publié.
- Validation locale : syntaxe PHP/JavaScript et scénarios ARP seul, doublon voisin/ARP, exclusion WAN, noms DNS, pools actifs, inconnus et chaînés. Affichage sur le routeur réel restant à vérifier ; aucune configuration réseau modifiée.

### 2026-09-29 - Transport API des hooks N-WAN multilignes

- Cause : `RouterosAPI::write()` séparait les attributs multilignes en plusieurs mots API, empêchant la transmission intégrale des hooks.
- Correction : transmission intégrale, gestion des écritures partielles et relecture exacte des champs `on-login` et `on-logout` après installation.
- Contrôle réel : 17 profils avec les deux hooks, seconde installation sans modification ; après exécution du moteur, 45 sessions affectées une seule fois (9 WAN1, 36 WAN2), aucune sans affectation. Avant intervention : aucun hook et 5 sessions sans affectation sur 43.
- Source du moteur et poids 20/80 inchangés ; aucune boucle de 30 secondes, BOOT conservé. Syntaxe PHP, 21 cas de transport et tests ciblés de l'installateur validés.
- Restent à observer : événements réels login/logout, WAN emprunté et conservation après coupure ; voir `PROJECT_PLAN.md`.

### 2026-09-23 - Stabilisation API, reservations DHCP et moniteurs MikroTik

- Le parseur API conserve maintenant les valeurs completes contenant `=` (scripts, commentaires et messages). Le parseur Smarty reutilise ce meme traitement ; les retours scalaires `ret`, notamment les compteurs, sont conserves.
- La synchronisation des reservations DHCP refuse les correspondances IP/MAC partielles et multiples, tient compte du serveur demande et convertit le bail dynamique exact en statique au lieu d ajouter un doublon. Les refus API sont remontes.
- La suppression optionnelle du bail associe a un binding verifie la correspondance avant toute suppression, sur une seule connexion ; elle ne supprime pas un bail dynamique. Sans serveur DHCP explicite, plusieurs correspondances sont refusees.
- Les lectures dashboard/statistiques/logs ne creent plus de regle de journalisation sur le routeur. La regle disque existante est conservee.
- Les moniteurs d expiration ne reecrivent plus `limit-uptime=1s` pour un compte deja marque et ne retirent des sessions que si elles existent. Les dates, modes d expiration et intervalles sont conserves.
- Les 16 moniteurs de `dev_1775288316` (`10.10.10.1`) ont ete compiles via `:parse`, mis a jour et relus ; le repartiteur multi-WAN n a pas ete modifie.
- Validation : syntaxe des six fichiers PHP modifies, 51 controles cibles hors reseau et verification du contenu des 16 taches sur le routeur.

Limites restantes : la mutualisation complete de la telemetrie et les differences de MAC derriere les CPE restent a traiter. Ces corrections ne prouvent pas la disparition des conflits ARP/DHCP historiques. Les operations RouterOS successives ne sont pas transactionnelles : une conversion en statique peut reussir avant le refus de la mise a jour suivante, et une suppression de binding avant le refus de suppression du bail ; les erreurs ne sont plus presentees comme un succes.

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


## Points ouverts avant VPS — 2026-07-22

- Executer la concurrence reelle sur deux routeurs MikroTik actifs.
- Completer le filtrage reseller des modules commerciaux et des rapports.
- Finaliser la persistance/activation/expiration des licences SQL.
- Corriger les montants du dashboard commercial et documenter leurs sources.
- [x] fonction morte `deviceConfigFilePath()` retirée après vérification finale.
- Rejouer installation et migrations sur une copie de base.

Les anciens problemes resolus et les references JSON historiques ne doivent pas etre reutilises comme diagnostic du runtime courant.

## 2026-09-12 - Banniere du portail Hotspot : debordement et cache CSS

- La capture Firefox montrait les bannieres empilees a leur largeur HTML de 640 px, sans les styles publicitaires ; le template reutilisait une URL CSS avec une version fixe. Un cache obsolete est une cause probable, non confirmee par lecture HTTP du routeur depuis le serveur.
- L'injecteur versionne desormais le lien CSS des pages login/status/logout/error avec l'empreinte du contenu et conserve un cadre responsive 2:1 sans hauteur fixe. Seule la banniere information-service-v2.png est active.
- Validation Firefox et Chromium sur le template genere : image chargee, ratio 2:1 et aucun debordement a 320, 375, 390, 768 et 1280 px. Template transfere (25 fichiers) et profil hsprof1 bascule avec verification API. Safari/iOS non teste.

## 2026-08-03 - Portail MikroTik : validation reelle restante

- La resolution publique du statut, le quota restant, le carrousel responsive et le deploiement FTP sont corriges localement.
- Restent a valider sur un routeur reel : FTP passif, bascule `html-directory` du profil actif et deconnexion native aux limites temps/data.
- Aucun compte existant ne doit etre recalcule automatiquement pendant cette validation.

## 2026-09-05 - Collecteur reseau bloque sur une lecture RouterOS

- Corrige : une lecture API vide (coupure, EOF ou timeout), notamment au milieu d un mot, pouvait boucler sans fin et conserver le verrou du collecteur. Le processus observe avait consomme plus de six heures CPU ; dernier echantillon au 4 septembre 22:23:31 UTC.
- `includes/lib/routeros_api.class.php` centralise la lecture exacte et ferme la connexion avec une exception explicite si la lecture ne progresse plus. Le collecteur utilise son traitement d erreur existant.
- Validation : reponse complete, EOF avant entete, longueur tronquee, corps tronque et timeout testes sur sockets locales. Processus bloque termine ; nouvelle collecte reussie le 5 septembre a 04:38:41 UTC.
- QoS lue sur le routeur : parent `RM interface bridge1` a 15M/50M, files Hotspot de priorite 8/8, `limit-at=0/0`, `default-small`. Equite entre clients en concurrence non validee par un test de charge.
- Point a clarifier : `RM - Bindings 2M-10M` a un plafond global 2M/2M alors que son type PCQ download indique 10M. Ne pas deduire la limite voulue du nom ni modifier sans regle explicite.

## 2026-10-03 - Rate-limit Hotspot releve a 6M/6M + plafonds WAN sans application reelle (Priorite 6/8)

Demande administrateur suite aux diagnostics `hotspot_shaping` (plusieurs utilisateurs avec pertes de paquets significatives en file d attente sous 4M/4M : `Cynthia` jusqu a 31%, `Tahina2` ~13-15%, `Koloina-2` ~10-11%, `Tovo`, `Nardy-2`).

- `rate-limit` passe de `4M/4M` a `6M/6M` sur 15 profils Hotspot (burst/threshold/priorite inchanges). `TV` (`3M/3M`) et `default` (illimite) volontairement non modifies.
- Verifie par relecture des 17 profils apres ecriture.
- A faire : remesurer le taux de `hotspot_shaping`/pertes en file d attente sur ces memes utilisateurs pour confirmer l amelioration reelle.

Constat important decouvert en parallele : le champ `daily_target_gb` (config multi-WAN, `includes/multi_wan_config.php` / `config/multi_wan/radius_manager_multi_wan_*.json`) n a **aucune application dans le code** — ni alerte, ni coupure, simple valeur affichee, jamais comparee aux `day_bytes` reels deja calcules par `includes/mikrotik_wan_usage_collector.php` (confirme par `grep -rn "daily_target_gb"` : aucune lecture hors passage direct de la config).

- Valeurs mises a jour sur demande (plafonds cibles, pas encore appliques) : WAN1 (`Starlink 1- V4 STD`, kit signale/flagge) 200 -> 150 Go/jour ; WAN2 (`Starlink 2 - V4 Mini`, kit sain) 200 -> 400 Go/jour.
- Verifie par relecture API live (`api/mikrotik_traffic_control.php` -> `state.multi_wan_config.sources`) : `wan1.daily_target_gb=150`, `wan2.daily_target_gb=400`.
- Surveillance stricte demandee par l administrateur ("ne jamais depasser") : proposition en attente de confirmation — reutiliser `includes/admin_notifications.php` (`createAdminNotification`/`adminNotificationExists`, dedup par WAN+jour) declenche depuis le collecteur existant `includes/network_quality_monitor.php` (deja execute chaque minute, calcule deja `day_bytes` par WAN). Alerte uniquement, pas de coupure automatique, compte tenu des 3 regressions deja vecues aujourd hui avec des automatismes sur ce projet (voir reponse locale de detection de connectivite ci-dessus).
- A ne pas confondre avec le doublon `pages/traffic_monitoring.php` / `pages/traffic_control.php#usage` (Priorite 6, toujours non traite, mentionne par l administrateur comme lie a ce sujet sans action concrete confirmee).

## 2026-10-03 - Surveillance stricte WAN construite : alerte 90% + coupure interface a 100%

Confirme par l administrateur : coupure reelle de l interface (pas de bascule N-WAN), remise en service automatique au changement de jour calendaire. Risque assume et accepte explicitement : si WAN1 et WAN2 depassent leur plafond le meme jour, coupure totale jusqu a minuit.

- `includes/wan_cap_monitor.php` (nouveau) : `enforceWanDailyCaps()`, appele depuis `scripts/monitor_mikrotik_wan_usage.php` (deja execute chaque minute par cron systeme). Lit `day_bytes` reel par interface (`trafficControlCollectWanUsage`) et le compare a `daily_target_gb` de `includes/multi_wan_config.php` (150 Go WAN1/ether1, 400 Go WAN2/ether2).
- Table `wan_cap_state` (device_id, wan_slot, cap_date, alert_90_sent, cutoff_applied) : un etat par WAN et par jour, reset automatique a chaque nouveau jour calendaire (et remise en service de l interface si elle avait ete coupee).
- A 90% du plafond : notification admin (`admin_notifications`, severite warning, categorie `wan_cap`). A 100% : interface reellement desactivee (`mikrotikSetInterfaceDisabledByName()`, nouveau dans `includes/mikrotik_backend.php`) + notification critique. Dedup par `adminNotificationExists()` (une alerte/coupure par WAN par jour).
- Valide en conditions reelles le 2026-10-03 : execution reelle du script (`php scripts/monitor_mikrotik_wan_usage.php`), table `wan_cap_state` creee et peuplee (wan1/wan2, `cutoff_applied=0`, usage du jour tres en dessous des plafonds), aucune interface touchee (comportement attendu). Mapping wan_slot->interface->plafond relu et confirme (`wan1=ether1=150Go`, `wan2=ether2=400Go`).
- Non teste en conditions reelles : le declenchement effectif de la coupure a 100% (aucun WAN n a atteint son plafond aujourd hui) ni la remise en service automatique le lendemain — a surveiller au premier cas reel.

## 2026-10-03 - Cible quotidienne WAN rendue modifiable depuis l'interface

Suite remarque administrateur : les plafonds (150/400 Go) ne devaient pas rester fixes en dur, l administrateur doit pouvoir les changer depuis `pages/traffic_monitoring.php`.

- Nouveau champ "Cible quotidienne (Go)" dans la modale d edition de chaque carte WAN (`wanEditDailyTargetInput`), a cote du titre/interface/poids PCC deja modifiables.
- Nouvelle action API `save_wan_daily_target` (`api/mikrotik_traffic_control.php`) : ecrit directement `daily_target_gb` dans la config JSON persistee (`multiWanLoadConfig`/`multiWanSaveConfig`), sans toucher RouterOS (ce champ n a pas de representation cote routeur, contrairement au poids/label geres par le script `RM-NWAN-ASSIGN`).
- `includes/wan_cap_monitor.php` lit deja cette meme config a chaque execution (chaque minute) : toute valeur modifiee est appliquee des le prochain cycle, sans redeploiement.
- Valide : ecriture + relecture testees directement via `multiWanLoadConfig`/`multiWanSaveConfig` (151 Go ecrit puis relu, restaure a 150 Go).

## 2026-10-03 - Mise en forme Cible WAN corrigee, doublon supprime, courbe globale ajoutee

Retour administrateur sur `pages/traffic_monitoring.php` apres le premier ajout de la cible WAN :
- Carte parametre WAN (grille du haut) : "Cible" deplacee en 3e position (apres Interface et Poids PCC), transformee en veritable `<label>` suivant exactement la mise en forme des autres champs (icone + libelle + valeur), au lieu d un bloc separe ajoute apres la barre.
- Carte resume WAN (bas de page) : l indicateur texte "X Go / Y Go aujourd'hui" ajoute precedemment a ete retire (redondant avec la carte parametre, jamais une vraie barre de progression).
- Nouvelle barre de progression globale au-dessus de la courbe "Total par heure" (`#trafficGlobalCapBar`) : somme des `day_bytes` de tous les WAN actifs avec cible definie, divisee par la somme de leurs cibles. Seule barre de progression reelle de la page sur ce sujet, couleur verte/orange/rouge selon le seuil (90%/100%), masquee si aucune cible n est definie.

## 2026-10-03 - Barre globale dupliquee retiree, vraie barre de progression par WAN activee

Retour administrateur : il existait deja une barre de progression statique au bas de chaque carte WAN (`.traffic-wan-progress`, presente dans le HTML depuis l origine mais jamais pilotee par JS, toujours a 0%). La barre globale ajoutee precedemment au-dessus du graphique faisait doublon avec cette barre existante.

- Barre globale au-dessus du graphique "Total par heure" supprimee (HTML + JS + CSS).
- La barre existante de chaque carte WAN (`traffic-wan-progress`) est desormais pilotee en direct : largeur = `day_bytes` reel / `daily_target_gb` configure, couleur verte/orange/rouge selon 90%/100%, remise a 0% si pas de cible ou WAN inactif. Mise a jour a chaque cycle de rafraichissement existant (pas de nouvel intervalle).
- Cache navigateur : versions `traffic_monitoring.css`/`traffic_monitoring.js` bumpees a `20261003b`.

## 2026-10-03 - Progression WAN ajoutee aussi dans les cartes resume (a droite de la courbe)

Retour administrateur : la barre de progression par cible n etait visible que dans les cartes parametres (groupe de gauche), pas dans les cartes resume a droite de la courbe "Total par heure".

- Simulation temporaire utilisee pour validation visuelle (cibles reduites a 2 Go / 6 Go), confirmee par l administrateur, puis valeurs reelles restaurees (150/400 Go) immediatement apres.
- Meme barre `.traffic-wan-progress` (deja presente visuellement) ajoutee dans chaque carte resume (`data-wan-summary-cap-bar`), pilotee par la meme logique partagee (`applyWanCapBar()`) que les cartes parametres : largeur = day_bytes/cible, couleur verte/orange/rouge selon 90%/100%.
- Versions CSS/JS bumpees a `20261003c`.

## 2026-10-03 - Compactage vertical de pages/traffic_monitoring.php

Demande administrateur : page trop haute verticalement.

- Cartes WAN (grille parametres) : `min-height` 218px -> 158px, gap grille .45rem -> .4rem, marge basse grille .7rem -> .4rem (ajuste a l espace reellement necessaire par le contenu actuel : titre, Interface, Poids PCC, Cible, qualite reseau, barre).
- Marges verticales entre sections : `mb-4` -> `mb-2` sur les deux blocs de ligne (Controle du bridge Hotspot, grille WAN), `mb-3` -> `mb-2` sur la carte de consommation.
- Carte "Controle du bridge Hotspot" : ajout de la classe `traffic-workspace` sur sa ligne pour heriter du padding compact deja utilise ailleurs sur la page (`.traffic-workspace .card-header/.card-body`), au lieu du padding Bootstrap par defaut plus large.
- Aucune donnee ni fonctionnalite modifiee, uniquement l espacement/CSS. Versions bumpees a `20261003d`.

## 2026-10-03 - Compactage vertical traffic_monitoring.php : CLOS

Validé par l'administrateur. Pas de suite nécessaire sur ce point.

## 2026-10-03 - Priorite 6 (doublon traffic_control.php#usage / traffic_monitoring.php) : clarification, action reportee

Analyse du jour : l'onglet "Consommation" de `traffic_control.php` et `traffic_monitoring.php` partagent la meme structure de courbe ("Total par heure", id `trafficUsageDailyChart`) mais n'affichent pas le meme perimetre :
- `traffic_control.php#usage` : classement par utilisateur/appareil (Hotspot, IP binding, non associes), avec un indicateur de succes de connexion par utilisateur (ex. "5/20" mentionne par l administrateur).
- `traffic_monitoring.php` : vue par interface WAN (ether1/ether2), cibles quotidiennes, barres de progression par cible.

Decision administrateur (2026-10-03) : possible faux doublon (perimetres differents : WAN vs utilisateurs WAN), a analyser ulterieurement avec un exemple concret avant toute fusion/suppression. Aucune action sur le code pour ce point aujourd'hui. Priorite 6 reste `TODO` dans `docs/PROJECT_PLAN.md`.

## 2026-10-03 - Verification keepalive-timeout 5m : pas d'amelioration mesurable pour l'instant

Mesure reelle sur `/log/print` (fenetre disponible 2026-10-02 17:45:25 -> 2026-10-03 01:01:08, ~7h16, 252 deconnexions clients Hotspot hors sessions API admin) :
- `keepalive timeout` : 239/252 = **94,8%**, contre 96% (207/215) mesure avant le passage de `2m` a `5m` le 2026-10-02.
- Ecart (94,8% vs 96%) non significatif compte tenu de la taille d'echantillon : **le changement ne montre pour l'instant aucune amelioration mesurable**.

Limite de la mesure constatee aujourd'hui : le tampon de log RouterOS ne conserve que les ~2000 dernieres lignes toutes rubriques confondues ; mes propres connexions API de diagnostic durant cette session (975 lignes `system,info,account` "admin logged in/out via api") ont sature une grande partie du tampon et reduit la fenetre reellement exploitable a ~7h de trafic recent. A eviter : multiplier les connexions API courtes pour du diagnostic repete sur le meme routeur dans une meme session, cela degrade la fenetre de log disponible pour l'analyse reelle.

Hypothese non testee : RouterOS applique `keepalive-timeout` du profil aux sessions a leur connexion ; les sessions deja actives avant le changement du 2026-10-02 continuent d'utiliser l'ancienne valeur (2m) jusqu'a leur prochaine reconnexion. Si une partie des 252 deconnexions proviennent de sessions anciennes, le taux reel sur sessions post-changement pourrait etre different. A reverifier sur une fenetre plus longue et plus propre (eviter les scripts de diagnostic pendant la mesure).

A faire : remesurer sur une fenetre ulterieure sans pollution du tampon de log, et si le taux reste proche de 95%, reconsiderer la cause racine (le probleme pourrait ne pas etre uniquement la duree du timeout mais la frequence/duree reelle des micro-coupures radio, qui pourrait depasser 5 minutes dans certains cas).

## 2026-10-03 - Cible mensuelle WAN ajoutee + barre de progression cumulee sous la courbe

Demande administrateur : ajouter une cible mensuelle par WAN (en plus de la cible quotidienne existante) et une barre de progression cumulee (somme des consommations WAN / somme des cibles), sous la courbe "Total par heure", meme principe que celui deja en place pour le quotidien.

- `includes/multi_wan_config.php` : `monthly_target_gb` ajoute aux WAN par defaut (0 = pas de surveillance mensuelle tant que non configure).
- Modale d edition WAN (`pages/traffic_monitoring.php`) : nouveau champ "Cible mensuelle (Go)", a cote de la cible quotidienne.
- API `save_wan_daily_target` (`api/mikrotik_traffic_control.php`) etendue pour accepter `monthly_target_gb` en plus de `daily_target_gb`, un seul appel pour les deux cibles.
- Nouvelle barre `#trafficMonthlyCapBar` sous la courbe "Total par heure" : somme `month_bytes` des WAN actifs avec cible mensuelle definie / somme des cibles mensuelles, meme code couleur (vert/orange/rouge a 90%/100%) que les barres quotidiennes. Masquee si aucune cible mensuelle n est definie (evite de dupliquer la barre quotidienne tant que l administrateur n a pas configure de cible mensuelle).
- Valide : ecriture/relecture testees directement sur la config JSON (wan1=3000Go, wan2=8000Go), cibles quotidiennes non affectees. Versions CSS/JS bumpees a `20261003e`.

## 2026-10-03 - Sauvegarde WAN impossible : permissions root sur config/multi_wan/

Cause reelle : le dossier `config/multi_wan/` et son fichier JSON appartenaient a `root` (crees/modifies par mes propres scripts de diagnostic executes en CLI root durant cette session), alors qu Apache/PHP tourne en `www-data`. Toute ecriture depuis l interface web (modale WAN, cibles quotidiennes/mensuelles, poids PCC, etc.) echouait silencieusement avec "Sauvegarde de la configuration multi-WAN impossible."

- Corrige : `chown -R www-data:www-data config/multi_wan/`.
- Valide par une requete HTTP reelle (session + CSRF + confirm authentiques) reproduisant exactement l appel du navigateur : ecriture confirmee, fichier reecrit avec le bon horodatage et le bon owner.
- Point de vigilance pour la suite de cette session : **ne plus executer de script PHP en CLI root qui ecrit dans `config/` ou tout repertoire servi par Apache** ; toujours passer par une requete HTTP reelle, ou executer le script via `www-data` (`su www-data -s /bin/sh -c '...'`) si une ecriture CLI est necessaire pour un test.

## 2026-10-03 - Barre mensuelle retiree, legende textuelle conservee

Retour administrateur : la barre de progression mensuelle ajoutee sous la courbe faisait a nouveau doublon avec les barres `.traffic-wan-progress` deja presentes par WAN. Seule la legende textuelle ("Consommation WAN cumulee / cible mensuelle cumulee : X Go / Y Go") est conservee, sans barre, coloree vert/orange/rouge selon le seuil. Versions bumpees a `20261003f`.

## 2026-10-03 - Barre figee sous la courbe (400Go/j, 4To/mois) : cause reelle et correction + legende journaliere

Cause reelle trouvee : `js/traffic_listener.js` (`renderDailyUsage()`) fixait une cible globale codee en dur (`usageTarget = mois ? 4*1000**4 : 400*1000**3`) pour alimenter la barre CSS `--usage-progress` du cadre `#trafficUsageChartCard` (`.traffic-progress-frame::after`), independamment des cibles WAN reellement configurees. Ce fichier est partage entre `traffic_control.php` et `traffic_monitoring.php`.

- Corrige : `traffic_monitoring.js` expose desormais `window.trafficWanCapTargets = {dailyBytes, monthlyBytes}` (somme des cibles WAN actives configurees), recalcule a chaque cycle de rafraichissement.
- `traffic_listener.js` lit cette valeur si presente, sinon conserve l ancien repli 400Go/j - 4To/mois (cas de `traffic_control.php`, qui n a pas de cibles WAN).
- Legende `#trafficMonthlyCapBar` rendue sensible au bouton Jour/Mois deja present sur la courbe : bascule automatiquement entre "cible journaliere cumulee" (somme des `daily_target_gb` actifs vs `day_bytes`) et "cible mensuelle cumulee" (`monthly_target_gb` vs `month_bytes`), sans ajouter de nouvelle barre.
- Verifie : config reelle relue (wan1 daily=150/monthly=4500, wan2 daily=400/monthly=8000), total mensuel 12500 Go coherent avec la valeur rapportee par l administrateur (797,2 Go / 12500 Go).
- Versions bumpees : `traffic_listener.js` -> `20261003a` (chargee aussi par `traffic_control.php`), `traffic_monitoring.css/js` -> `20261003g`.

## 2026-10-03 - INCIDENT EN COURS - Portail captif inaccessible pour les nouveaux clients (androndra.wifi.mg)

Signale par l'administrateur : `http://androndra.wifi.mg/status` completement inaccessible. Investigation en cours, non resolue.

**Etabli (preuve directe, pas une hypothese)** :
- Observation cle : dans le tampon de log RouterOS (2000 lignes, couvrant jusqu'a l'heure actuelle), la **derniere tentative de login Hotspot (meme echouee) remonte a 2026-10-03 02:31:55 (Koloina, 10.10.11.33)**. Aucune tentative de login, reussie ou echouee, depuis - alors que des appareils continuent d'obtenir un bail DHCP (ex: 07:26:12, nouvel appareil "Redmi-A5"). Les nouveaux appareils rejoignent le Wi-Fi mais ne declenchent plus la redirection vers la page de login.
- Test reel depuis un PC Windows deja authentifie (session admin existante, IP 10.10.11.35) : port 80 sur `10.10.10.1` repond normalement (200 OK, redirection vers le statut), et `http://androndra.wifi.mg/status` affiche la page complete avec les vraies donnees de session **une fois la resolution DNS forcee manuellement** (`curl --resolve`). Donc le serveur HTTP du Hotspot et la page `/status` elle-meme fonctionnent pour un client deja authentifie.
- Meme PC : `nslookup androndra.wifi.mg 10.10.10.1` time out systematiquement (5 tentatives), alors que l'enregistrement DNS statique existe, est actif, et present dans le cache RouterOS (`androndra.wifi.mg -> 10.10.10.1`, ttl=0s/statique).
- Aucune action RouterOS (via l'application, `operation_history`) ne correspond a la fenetre 02:31-03:11 ; les derniers changements (parametres/poids WAN2) datent de 02:18-02:19, soit avant le dernier login reussi.
- Verifie sain (lecture seule) : firewall filter (Hotspot bien en premiere position, cf. entree precedente), mangle (exemption `dst-address-type=!local` active, compteurs de paquets en hausse continue), interfaces WAN1/WAN2 actives et non desactivees, `wan_cap_state` confirme qu'aucune coupure automatique (fonctionnalite du 2026-10-03) ne s'est declenchee (`cutoff_applied=0` sur les deux WAN), service Hotspot `proxy-status=running`.
- Fausse piste ecartee : `/ip/service www` desactive (trouve initialement) n'est pas la cause - confirme invalide par test reel depuis un client Hotspot, le port 80 fonctionne via le mecanisme de redirection NAT propre au Hotspot, independant de ce service.

**Hypothese non confirmee** : le DNS local RouterOS repond mal ou pas du tout a une sous-population de clients/requetes (reste a determiner si general ou specifique a ce nom), ce qui empecherait la resolution de `androndra.wifi.mg` pour les clients non encore authentifies, et donc le declenchement de la redirection/login visible dans les logs.

**A faire en urgence** : tester depuis un appareil non encore authentifie (reconnexion fraiche, oubli du reseau) pour determiner si le chemin "client non authentifie -> redirection -> login" fonctionne ou non - le test precedent portait sur un client deja authentifie et ne couvre donc pas le cas reellement en panne.

Lien avec Priorite 4 (`docs/PROJECT_PLAN.md`) : cet incident est un cas concret correspondant exactement au chantier ouvert "Diagnostic Des Pannes De Portail Captif".

## 2026-10-03 - Incident androndra.wifi.mg : confirme intermittent, pas une panne permanente

Suite aux tests reels menes en direct avec l administrateur sur le PC temoin (10.10.11.35) :
- Port 80 sur `10.10.10.1` a echoue puis reussi (`TcpTestSucceeded: True`) a quelques minutes d intervalle, sans aucune action corrective de notre part entre les deux tests.
- Ping vers ce meme PC confirme par le routeur (0% perte) pendant que l administrateur rapportait une panne "sur les 2 appareils" - incoherence qui pointe vers un probleme intermittent cote reseau radio/CPE plutot qu une panne de configuration permanente.
- Rejoint le probleme deja connu de micro-coupures radio (cause du changement `keepalive-timeout` 2m->5m le 2026-10-02) plutot qu une cause nouvelle liee a une modification de cette session.

**Anomalie annexe relevee (non liee a cet incident)** : table ARP du PC temoin contient `10.10.10.103 -> 00-11-22-33-44-55`, une adresse MAC generique/factice (sequence 00-11-22-33-44-55, non une adresse constructeur reelle). A verifier separement - possible appareil mal configure ou usurpation locale.

A faire : si l intermittence se reproduit, relever l heure exacte et corroborer avec `/log print` (deconnexions keepalive) et la qualite reseau (`network_quality_monitor`) au meme moment, plutot que de chercher une cause de configuration unique.

## 2026-10-03 - Incident androndra.wifi.mg : RESOLU par redemarrage RouterOS

Resolution confirmee par l administrateur : le redemarrage du routeur (CHR) a retabli l acces. Verifie juste apres : uptime `1m47s` (redemarrage confirme), `/ip/service www` toujours `disabled=true` exactement comme avant (configuration identique avant/apres) - confirme que ce n etait pas la cause et qu aucune configuration n a change.

**Conclusion** : blocage runtime transitoire de RouterOS (pas un probleme de configuration, pas le code PHP de l application - verifie architecturalement non implique car `10.10.10.2` n est pas dans le chemin reseau de `10.10.10.1:80`, et `.htaccess` exclut les vrais fichiers/API du routage qui declenche `localConnectivityResponderMaybeHandle()`). Cause racine exacte non identifiable (RouterOS ne journalise pas ce type de blocage interne) : hypotheses possibles non confirmees - service Hotspot bloque en memoire, table ARP/bridge degradee apres ~1j18h d uptime, ou particularite du CHR/QEMU.

A surveiller : si l incident se reproduit, noter l heure exacte et l uptime au moment du symptome avant tout redemarrage, pour determiner si une duree d uptime specifique est en cause (fuite de ressource progressive) plutot que de redemarrer immediatement sans preuve.

Incident clos.

## 2026-10-03 - Priorite 7 demarree : premier nettoyage runtime

Inventaire (agent Explore, verifie manuellement par grep cible avant toute action) :
- **17 fichiers `.old` deja presents** : confirme qu aucun n est inclus/require par du code runtime PHP (seules des mentions documentaires dans `docs/PROJECT_DEPENDENCIES.md`). Rien a faire, deja neutralises correctement.
- **`api/portal/opnsense_user_status.php`** : signale a tort "sans reference" par le premier passage ; verifie manuellement - il est bien appele conditionnellement par `modules/portal/PortalProfileController.php` (`pages/portal_profiles.php`) pour les deploiements OPNsense. Le support OPNsense est actif en parallele du MikroTik dans ce projet (double stack), pas du code mort. Conserve.
- **`api/debug_recent_events.php`** et **`api/cpu_stream.php`** : confirmes sans aucune reference (pages/js/includes), renommes en `.old` sur confirmation administrateur. `php -l` valide sur les deux, aucun runtime ne les ciblait avant renommage (verifie par grep avant action).

Reste a faire pour cloturer la Priorite 7 (TODO -> IN_PROGRESS) : inventaire plus large des autres doublons potentiels (ex. `api/create_mikrotik_scheduler.php`/`update_mikrotik_scheduler.php`/`delete_mikrotik_scheduler.php` vs logique equivalente dans `includes/mikrotik_backend.php`, a confirmer avant toute action - actifs et appeles par `js/add_scheduler.js`, pas de doublon fonctionnel detecte a ce stade), puis mise a jour finale de `DECISIONS.md`/`ARCHITECTURE.md`.

## 2026-10-04 - Suppression de device sans contrôle de périmètre ni CSRF (corrigé)

- `api/network_devices_api.php` : l'action `delete` désactivait n'importe quel `id` sans vérifier que le device est accessible au compte. Corrigé par `requireAccessibleDevice()` avant la désactivation.
- Toutes les requêtes POST de cette API exigent désormais le jeton `csrf_token` de la session (403 sinon). Le JS (`js/network_device.js`) envoie le jeton pour l'enregistrement, l'activation et la suppression.
- Validation : syntaxe PHP et JS, requête POST sans session refusée (403). Test navigateur avec session valide à faire.

## 2026-10-04 - Test ICMP depuis le routeur sur WAN2 : conclusion annulée

- Constat initial (à annuler) : ping depuis le routeur sur ether2 vers 1.1.1.1 et 8.8.8.8 sans réponse (0/10), passerelle OK. Conclu à tort comme panne en amont.
- Vérification : même résultat avec l'adresse source de WAN2 (192.168.1.185). Pourtant ether2 transporte du trafic réel au même moment (9,9 Mb/s en réception, 1,3 Mb/s en émission). L'administrateur confirme que WAN2 n'a aucun problème.
- Conclusion : l'échec concerne l'ICMP émis par le routeur, pas la santé du WAN. Un ping routeur ne mesure pas l'état du WAN pour les clients. Ne pas utiliser ce test comme preuve de panne.
- Aucune action à prendre sur le routage ni sur la détection à partir de ce constat.

## 2026-10-05 - Édition d'un IP binding RouterOS : enregistrement sans effet (corrigé)

- Cause : la page envoyait une MAC vide (binding sans MAC, ex. 10.10.10.15). RouterOS refusait avec « invalid value of mac-address », et l'interface affichait quand même un succès. Au rechargement, les anciennes valeurs revenaient.
- Correction (`includes/mikrotik_backend.php`, `updateMikrotikIpBinding`) : un champ vide n'est plus envoyé à RouterOS. La réponse RouterOS est contrôlée (refus remonté tel quel), et le binding est relu : le succès n'est affiché que si les valeurs sont enregistrées.
- Vérifié par l'administrateur : l'enregistrement est confirmé après actualisation.

## 2026-10-06 - Login par MAC : cause des échecs initiaux

- Le compte de test nommé « test-mac-gaga » était refusé (« login failed: invalid username or password »). En mode mac-as-username, RouterOS cherche le compte dont le nom est la MAC. Corrigé en renommant le compte sur la MAC.

## 2026-10-06 - Liste utilisateurs : identifiant du device vide sur les lignes (corrigé)

- `pages/users_list.php` lisait `$activeDevice` (variable non définie) pour `data-device_id` de chaque ligne : valeur vide. Corrigé avec `$context['device']['id']`. Le corps de page (`data-active-device-id`) était déjà correct pour les envois.

## 2026-10-08 - WAN2 injoignable au-delà de sa passerelle locale, non détecté par le système de bascule

- Constat (lecture seule, `ether2`) : la passerelle locale (le boîtier Starlink, `192.168.1.1`) répond en moins d'1 ms, mais toute destination Internet au-delà (`1.1.1.1`, `8.8.8.8`) est injoignable (timeout total, une réponse « host unreachable » explicite du boîtier Starlink observée une fois).
- Non détecté par `wan_state.php` / `check-gateway=ping` : ce mécanisme ne teste que la passerelle locale, jamais une destination réelle au-delà. La route reste donc considérée active, aucune alerte DEGRADED/DOWN n'est générée, et le répartiteur continue d'envoyer 80 % des nouveaux logins sur ce WAN.
- Pas de correctif appliqué au moment de la rédaction. Un vrai correctif demanderait un test de bout en bout par WAN (pas seulement la passerelle), à concevoir séparément.
- Non confirmé comme cause unique des difficultés de chargement rapportées par les utilisateurs (Facebook photos/vidéos), car le même symptôme a été signalé par un utilisateur sur WAN1, qui lui s'est révélé sain lors du test. Voir aussi l'entrée DNS du 2026-10-09 dans `DECISIONS.md`.

## 2026-10-09 - DNS : résolution toujours sortie par WAN1, indépendamment du WAN du client

- Le marquage de routage par liste d'adresses (WAN1_CLIENTS/WAN2_CLIENTS) ne s'applique qu'au trafic qui transite par le routeur, jamais à celui que le routeur génère pour son propre compte, dont la résolution DNS. Cette dernière suit donc toujours la table `main` (WAN1 prioritaire), même pour un client affecté à WAN2.
- Conséquence possible : un décalage entre le WAN utilisé pour résoudre un nom de domaine (toujours WAN1) et le WAN réellement emprunté par le trafic du client (WAN1 ou WAN2 selon son affectation), pouvant influencer le choix du serveur CDN renvoyé par des services comme Facebook/Meta.
- Pas de correctif rapide : RouterOS n'a qu'un service DNS global, non routable par client. Une vraie correction demanderait deux chemins de résolution distincts. Voir `DECISIONS.md` (2026-10-09) : ce chantier ne sera engagé que si le changement DNS classique déjà appliqué ne suffit pas à améliorer la situation rapportée.
