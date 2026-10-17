# Plan Projet — Stabilisation Et Pilotage MikroTik

Date de reprise : 2026-09-26

Mise à jour : 2026-10-02

## Suivi Court Terme — Vérifications En Attente (2026-10-02)

Ces points ne sont pas automatisables (pas d'accès réseau local depuis une tâche planifiée cloud) : à reprendre manuellement, dans cette session ou une suivante, en redemandant explicitement la vérification concernée.

- **Keepalive-timeout 2m → 5m** (17 profils Hotspot, CHR `dev_1775288316`) : changement appliqué le 2026-10-02, non encore mesuré. À refaire : recompter les raisons de déconnexion dans `/log print` sur une fenêtre représentative et comparer au taux de référence de 96 % (`207/215` avant correction). Détail dans `docs/KNOWN_ISSUES.md`.
- **Priorités 1 à 7 ci-dessous** : chacune a sa propre liste « À faire » / « À vérifier », qui nécessite une observation en conditions réelles (incident client, redémarrage, déconnexion/reconnexion) — voir chaque section pour le détail, non résumé ici pour éviter une double source de vérité.

- **Réponse locale de détection de connectivité (Android/Windows/Apple)** : DÉSACTIVÉE après 3 régressions réelles successives (admin au lieu du portail, puis faux-positif « internet disponible » sous Windows). Ne pas réactiver sans test exhaustif sur un vrai poste Windows — l'heuristique NCSI combine DNS + plusieurs chemins HTTP de façon non documentée. Détail dans `docs/KNOWN_ISSUES.md`.

## Décision De Reprise

Le planning précédent reste écrasé. Les chantiers dispersés restent gelés tant que les priorités ci-dessous ne sont pas validées.

Objectif immédiat : revenir à une base fiable où :

- RouterOS porte les décisions réseau ;
- PHP sert d'interface, de supervision et de commande ;
- SQL sert d'historique et de mémoire applicative, pas de moteur réseau ;
- aucun état critique WAN n'existe en double entre PHP et RouterOS.

## Règles De Travail Actuelles

- Ne pas ajouter de nouvelle fonction visuelle tant que les chantiers réseau critiques ne sont pas validés.
- Ne pas modifier PCC, NAT, routes ou firewall sans diagnostic précis et validation.
- Ne pas créer de fallback.
- Ne pas inventer de données.
- Ne pas faire dépendre une collecte de l'ouverture d'une page.
- Toute correction doit avoir une preuve avant/après.
- Une page affiche ou déclenche une action validée ; elle ne doit pas devenir le moteur de décision.

## Statuts

- `TODO` : à traiter.
- `IN_PROGRESS` : chantier ouvert.
- `BLOCKED` : attente décision ou preuve.
- `DONE` : validé par contrôle réel.
- `FROZEN` : suspendu.

Gate de validation : les priorités 1 à 3 restent `IN_PROGRESS` tant que les scénarios RouterOS réels listés dans chaque chantier n'ont pas été observés et consignés. Un patch appliqué, un compteur d'exécution ou un test de génération ne suffit pas pour passer à `DONE`.

---

# Priorité 1 — Autorité N-WAN Et État WAN Unique

Statut : `IN_PROGRESS`

But : supprimer la confusion entre état PHP local et état RouterOS.

Règle validée :

```text
État interface WAN activée/désactivée = RouterOS /interface disabled
Paramètres moteur N-WAN = script RouterOS RM-NWAN-ASSIGN
PHP = interface de lecture / commande
SQL = historique, pas moteur WAN
```

Travail déjà fait :

- l'activation/désactivation ne doit plus écrire `config/multi_wan/*.json` ;
- l'état visuel de la carte WAN doit suivre `/interface disabled` ;
- les paramètres modifiés depuis l'interface doivent être injectés dans RouterOS ;
- les exceptions username doivent ignorer un WAN réellement désactivé ;
- l'analyse doit bannir les suppositions du type “probablement”.

État appliqué et vérifié sur RouterOS le 2026-09-29 :

- les poids sont lus depuis RouterOS et restent dynamiques ; l'état observé est `20/80` sur `ether1/ether2` ;
- `RM-NWAN-ASSIGN` est présent, valide et lancé lors de l'application ; les sessions Hotspot actives conservent leur affectation ;
- `RM-NWAN-ASSIGN-BOOT` est présent et activé pour le démarrage ; le compteur observé vaut `1`, sans preuve à lui seul du routage après redémarrage ;
- `RM-NWAN-ASSIGN-SCHED` et sa boucle toutes les 30 secondes ont été supprimés ; le code ne les recrée plus ;
- les profils Hotspot ont reçu un hook `on-login` qui relance `RM-NWAN-ASSIGN` ; une reconnexion efface l'ancienne affectation de l'IP avant le recalcul ;
- les affectations sont créées sans bail de 10 minutes ; les anciennes entrées inactives sont nettoyées au démarrage ou lors d'un login ;
- les exceptions manuelles sont persistées par routeur et nom d'utilisateur, puis intégrées au script RouterOS ; elles sont prioritaires au login ;
- l'application réelle du script RouterOS a d'abord rejeté `timeout=none` ; le code a été corrigé pour recréer les entrées sans paramètre `timeout`, puis l'application a réussi.

Correction et contrôle du transport API le 2026-09-29 :

- cause de l'installation manquante : le client API découpait les attributs multilignes en plusieurs mots ; il transmet désormais chaque attribut intégralement, avec sa longueur en octets ;
- les écritures des hooks sont relues et comparées exactement ; les refus et réponses vides sont remontés ;
- avant intervention : aucun hook sur les 17 profils, 5 sessions sans affectation sur 43 ; après compilation des 34 hooks, installation et exécution du moteur : 17 profils avec les deux hooks, 45 sessions affectées une seule fois (9 WAN1, 36 WAN2), aucune sans affectation ;
- une seconde installation ne modifie aucun profil ; les scripts existants sont conservés, la source du moteur et les poids sont inchangés ;
- validation locale : 21 cas de transport API et tests de préservation, idempotence et refus des écritures tronquées ; ces preuves ne remplacent pas un scénario réel de connexion/déconnexion et de trafic.

Règle d'exécution : le scheduler `RM-NWAN-ASSIGN-BOOT` ne s'exécute qu'au démarrage. Chaque nouveau login appelle directement `RM-NWAN-ASSIGN` via le profil Hotspot. Aucun scheduler N-WAN périodique ne doit vérifier ou réaffecter les sessions.

Contrôle lecture seule du 2026-10-04 sur le CHR `dev_1775288316` (sans connexion client, statut inchangé) :
- 55 sessions Hotspot actives : chacune a exactement une entrée `WAN1_CLIENTS` ou `WAN2_CLIENTS` (0 sans entrée, 0 en double).
- 17 profils Hotspot : hook `RM-NWAN-LOGIN-HOOK` présent sur les 17, hook `RM-NWAN-LOGOUT-HOOK` présent sur les 17.
- `RM-NWAN-ASSIGN-BOOT` présent (intervalle 0s) ; aucun scheduler N-WAN périodique trouvé.
- Non prouvé par ce contrôle : le journal des connexions n'a pas pu être lu (requête à corriger), donc l'affectation d'une nouvelle connexion n'est pas confirmée.

Validation réelle N-WAN restant à faire :

1. Vérifier sur une nouvelle connexion que l'affectation est immédiate et que le trafic emprunte le WAN choisi.
2. Vérifier qu'un client déjà connecté garde son WAN lorsqu'un autre client se connecte.
3. Tester une exception manuelle après déconnexion/reconnexion du même nom d'utilisateur, y compris si son IP change.
4. Contrôler après redémarrage réel que le scheduler BOOT démarre le moteur, que les poids sont conservés et que les affectations se font sans page ouverte.
5. Contrôler la chaîne réelle listes d'adresses → règles de routage → WAN emprunté ; le ratio de poids du script ne prouve pas à lui seul le routage effectif ni une répartition du débit.
6. Vérifier sur une déconnexion réelle que le hook `on-logout` nettoie l'affectation de l'IP lorsqu'aucune session ne l'utilise encore.

### Création et modification des profils Hotspot — IN_PROGRESS

Demande du 2026-09-29 : couvrir les deux opérations de `pages/add_profile.php`, via `api/profiles/create_profile.php` et `ensureMikrotikProfile()`, sur le device sélectionné et autorisé.

- Règle confirmée : l'affectation WAN n'a pas de délai d'expiration ; elle reste stable pendant la session et peut être nettoyée après déconnexion lorsque l'IP n'est plus active. Cette règle ne modifie pas l'expiration commerciale du forfait : le moniteur Hotspot actuel peut couper une session expirée.
- État du code : création et modification génèrent déjà le hook N-WAN dans `on-login` ; `on-logout` conserve son contenu existant et reçoit le hook manquant sans doublon.
- Correction appliquée : remplacement du seul bloc Hotspot généré reconnu exactement, avec conservation des ajouts avant/après. Un script dont le bloc ne peut pas être identifié est refusé avant écriture pour éviter sa perte. Le hook login est présent une seule fois ; le contenu logout existant est conservé.
- Les deux scripts sont comparés exactement après création/modification ; une absence, une troncature ou un refus de relecture produit une erreur. La confirmation finale ne renvoie plus un profil fictif si la relecture ne retrouve rien. Une erreur après écriture ne constitue pas une annulation de l'écriture RouterOS.
- Le hook N-WAN précède désormais le bloc Hotspot et ses deux délais de cinq secondes dans les profils créés/modifiés. Les règles de validité, enregistrement et verrouillage restent celles du moteur existant ; les profils déjà installés ne sont pas réécrits en masse.
- Validation locale : syntaxe PHP et 65 contrôles réussis couvrant les cinq modes d'expiration avec/sans verrouillage, création, renommage, changement de prix, conservation des ajouts, idempotence et refus des scripts inconnus ou des relectures invalides.
- Validation attendue : création, modification, renommage et enregistrement répété ; hooks présents une seule fois, moteur Hotspot et scripts complémentaires préservés, valeurs du profil mises à jour, puis login/logout réels. Aucun changement de règle d'expiration commerciale dans ce chantier.

Contrôles complémentaires :

1. WAN1/WAN2 affichent leur état depuis RouterOS, pas depuis `localStorage` ni JSON.
2. Une désactivation via interface désactive réellement l'interface RouterOS.
3. Une activation via interface active réellement l'interface RouterOS.
4. Le script `RM-NWAN-ASSIGN` reflète les paramètres moteur injectés.
5. L'interface distingue clairement :
   - port actif/inactif ;
   - poids moteur ;
   - WAN utilisable par le moteur.
6. L'enregistrement transmet les poids saisis, exécute immédiatement le script et restitue les valeurs réellement relues depuis RouterOS, sans valeur de secours `50/50` ni forçage `20/80`.
7. Après coupure/redémarrage, le script conserve les poids configurés et les affectations au login s'appliquent sans page ouverte.
8. Vérifier le déclenchement réel au login : aucune nouvelle session ne doit attendre un passage périodique pour être affectée ; une exception manuelle par username doit rester prioritaire après reconnexion.
9. Contrôler la chaîne réelle listes d'adresses → règles de routage → WAN emprunté : les poids du script seuls ne prouvent pas la répartition effective du trafic. Relever l'état avant/après sans modifier PCC, NAT ou firewall à ce stade.
10. Confirmer que les champs ISP, objectif journalier et Cycle restent sans effet sur les décisions du moteur WAN.

Critère de fin :

- plus aucune incohérence entre carte WAN, `/interface print`, et script `RM-NWAN-ASSIGN` ;
- plus aucune erreur `Sauvegarde de la configuration multi-WAN impossible` sur activation/désactivation ;
- aucun état WAN critique n'est décidé par PHP local ;
- les poids dynamiques sont relus et leur application est prouvée à l'enregistrement puis après coupure/redémarrage ;
- l'affectation immédiate des nouvelles sessions au login et leur routage effectif sont vérifiés sur RouterOS ;
- `RM-NWAN-ASSIGN-BOOT` est actif au démarrage, `RM-NWAN-ASSIGN-SCHED` est absent et aucun enregistrement ne recrée de boucle périodique ;
- les hooks de login, la conservation des sessions actives et la persistance des exceptions manuelles sont vérifiés par reconnexion réelle.

---

# Priorité 2 — Mode Dégradé Contrôlé

Statut : `DONE` (clôturé le 2026-10-06 ; tests en conditions réelles regroupés en fin de document)

But : si un WAN tombe, le réseau continue sans saturer le WAN restant, sans que PHP devienne moteur de décision.

Avancement réel au 2026-10-05 :
- Fait : règle de connexion qui exclut un WAN HS (17 profils) ; débit dégradé 3M/3M stocké sur 15 profils ; `includes/wan_state.php` (machine d'états et causes, fonctions pures, 13 tests locaux OK).
- Fait : journal des transitions WAN branché au cycle existant (écriture seulement si l'état change, sans action sur le trafic). Vérifié en NORMAL, transition réelle non encore observée.
- Non fait : bascule du débit, réaffectation des clients, alerte, test de bascule réelle (à valider avec l'administrateur).

Bilan au 2026-10-05 (statut : `IN_PROGRESS`, tests reportés) :
- Fait : règle de connexion qui écarte un WAN HS (nouvelles IP) ; débit dégradé 3M/3M stocké sur 15 profils (sans effet tant que l'état n'est pas DEGRADED) ; `includes/wan_state.php` (machine d'états, 13 tests locaux) ; journal des transitions WAN (écriture seulement si l'état change, vérifiée en NORMAL) ; procédure de retour arrière (`ROLLBACK_P2.md`, sauvegardes en lecture seule).
- Alerte : une notification `admin_notifications` par transition (critique si DOWN, avertissement si DEGRADED, information au retour), créée seulement au changement d'état. Non testée sur une transition réelle.
- Non fait : état visible dans l'interface ; bascule du débit à 3M/3M ; réaffectation des clients d'un WAN HS.
- Critères de fin non remplis : état visible dans l'interface ; limites appliquées seulement si l'état le justifie ; rollback testé.
- Reporté : test de transition réelle (un WAN HS puis retour), à faire avec l'accord de l'administrateur.

Source de vérité prévue :

```text
RouterOS script/scheduler = décision et application
RouterOS queues/profils = limites réellement appliquées
PHP = lecture, validation, bouton d'installation/contrôle uniquement
SQL = historique d'observation si nécessaire
```

Constat réel actuel :

- queue simple `RM interface bridge1` déjà active sur `bridge1` avec `max-limit=20M/50M` ;
- profils Hotspot observés majoritairement à `4M/4M`, avec profil `TV` à `3M/3M` ;
- la cible proposée `5M/5M` n'est donc pas un bridage plus strict que l'état actuel des profils ;
- il faut définir explicitement les valeurs `NORMAL` et `DEGRADED` avant toute application automatique.

Règles d'état à retenir :

1. `DOWN` si au moins un WAN actif remplit une condition confirmée :
   - interface RouterOS disabled/down ;
   - DHCP client non `bound` ;
   - gateway indisponible ;
   - test Internet KO sur plusieurs sondes configurées.
2. `DEGRADED` si un seul WAN actif reste exploitable.
3. `RECOVERING` quand le WAN revient mais doit rester stable avant restauration.
4. `NORMAL` seulement si tous les WAN actifs sont OK pendant la durée de stabilité validée.

Seuils proposés avant validation :

- contrôle toutes les 30 secondes ;
- WAN déclaré `DOWN` après 2 contrôles consécutifs KO ;
- WAN déclaré `RECOVERING` après 3 contrôles consécutifs OK ;
- retour `NORMAL` après 180 secondes stables.

Actions RouterOS prévues :

- si état `DEGRADED` : appliquer limite globale bridge + profils Hotspot dégradés validés ;
- si état `RECOVERING` : garder encore les limites dégradées ;
- si état `NORMAL` : restaurer les valeurs normales validées ;
- journaliser chaque bascule avec la cause.

Rollback à préparer avant activation :

- restauration de la queue `RM interface bridge1` à sa valeur normale validée ;
- restauration des profils Hotspot à leurs valeurs normales validées ;
- désactivation du scheduler de mode dégradé ;
- conservation des logs de bascule pour audit.

À ne pas faire dans cette phase :

- ne pas changer la logique PCC/routage ;
- ne pas réécrire les règles firewall ;
- ne pas masquer une panne WAN par une valeur inventée ;
- ne pas appliquer `5M/5M` tant que normal/dégradé ne sont pas distingués.

Critère de fin :

- un état `NORMAL / DEGRADED / RECOVERING` est observable depuis l'interface ;
- les limites s'appliquent uniquement quand l'état RouterOS le justifie ;
- les valeurs normales et dégradées sont écrites et restaurables ;
- rollback documenté et testé en lecture avant activation.

---

# Priorité 3 — Compteurs WAN Autonomes

Statut : `IN_PROGRESS`

Cible :

```text
MikroTik compte
→ collecteur serveur régulier
→ SQL historique unique
→ traffic_monitoring.php affiche SQL
```

Travail déjà fait :

- collecteur WAN dédié côté serveur ;
- collecte WAN retirée du rafraîchissement de l'état `traffic_control` ;
- cron WAN/LAN décalé ;
- verrou global pour éviter les collectes MikroTik lourdes simultanées.

À vérifier :

1. Les deux WAN actifs sont collectés si leurs interfaces RouterOS sont actives.
2. L'ouverture de `traffic_monitoring.php` ne remet aucun compteur à zéro.
3. Les trous de collecte restent visibles comme trous réels.
4. Les valeurs jour/mois/cycle viennent de SQL.
5. Les graphes n'utilisent pas de fallback.
6. Le champ « Cycle », décrit dans le compte rendu comme un paramètre graphique, ne modifie pas la répartition N-WAN.

Critère de fin :

- les compteurs augmentent sans page ouverte ;
- aucune donnée inventée ;
- SQL contient l'historique par interface physique.

Vérification du 2026-10-04 :
- Collecte sans page ouverte : OK. Cron chaque minute, ether1 et ether2 ont 60 échantillons par heure.
- Ouverture de la page sans remise à zéro : OK par le code. Aucune page ni API n'appelle la collecte.
- Jour/mois/cycle depuis SQL : OK. Le champ « Cycle » n'a aucun effet sur le N-WAN.
- Repli 400 Go/jour et 4 To/mois : SUPPRIMÉ (`js/traffic_listener.js`). Sans cible, la barre reste à 0 % avec « cible non définie ». Cibles quotidienne et mensuelle obligatoires (modale, API). Rendu navigateur à valider.
- Trous `gap_reset` : code corrigé le 2026-10-04 (plus de branche qui met le trafic à 0 ; verrou WAN attend au lieu de sortir). Aucun nouveau trou depuis le 2026-10-03 23:18. À confirmer sur plusieurs heures.
- Cause des trous : interruptions de collecte de 6 min à plus de 19 h, pas des remises à zéro des compteurs. Hypothèse non vérifiée : `scripts/monitor_mikrotik_wan_usage.php` quitte silencieusement (`exit(0)`) si le verrou global est déjà pris par `scripts/monitor_mikrotik_traffic.php`.
- Rétention : 120 jours (décision du 2026-10-04, `docs/DECISIONS.md`).
- Statut : reste `IN_PROGRESS` jusqu'à confirmation sur plusieurs heures qu'aucun trou n'apparaît, et validation navigateur du repli supprimé et de l'affichage mensuel en To.

---

# Priorité 4 — Diagnostic Des Pannes De Portail Captif

Statut : `IN_PROGRESS`

But : identifier pourquoi certains clients ne voient pas la page captive à certains moments, sans confondre une anomalie DHCP/ARP avec une panne de redirection Hotspot.

Constat du code actuel :

- `pages/dhcp_leases.php` affiche un instantané serveur plafonné à 300 baux ; il ne teste pas l'accès au portail et ne fournit pas de diagnostic par incident ;
- le contrôle de conformité compare DHCP lease, ARP et Hotspot host ; il ne lit pas `/ip/hotspot/active`, les serveurs DNS annoncés au client ni les règles de redirection/firewall ;
- l'inventaire AP/CPE est une vue distincte et ne prouve pas le chemin réseau emprunté au moment d'une panne ;
- ces limites décrivent la couverture du code, pas la cause réelle des plaintes clients.

Amélioration livrée et testée le 2026-09-29 :

- le bouton `Contrôle global` conserve le contrôle de cohérence DHCP/ARP/Hotspot ; `Diagnostiquer la sélection` n'est actif qu'avec au moins un bail coché ;
- l'API `dhcp_portal_diagnostic.php` prend les IP/MAC sélectionnées, limite chaque lot à 30 clients, lit les tables RouterOS une fois et renvoie une ligne de corrélation par bail sélectionné ;
- la corrélation couvre DHCP, ARP, bridge host, Hotspot host/active et réseau DHCP ; DNS et règles NAT/filter pertinentes sont présentés une fois pour le routeur ;
- le résultat est horodaté, en lecture seule, et avertit qu'il ne prouve pas le rendu côté navigateur ;
- le rendu vide dû au mélange entre objets de conformité et lignes RouterOS brutes a été corrigé ;
- le test live de l'interface et de l'API a réussi sur les 83 baux affichés ; la présence de sessions actives a été remontée, mais la cause d'une panne captive reste à établir lors d'un incident client.

Incident client fourni le 2026-09-29 (capture Windows) : IPv4 `10.10.10.157`, DNS annoncés `10.10.10.1` et `10.10.10.11`, suffixe DHCP `HOST`, IPv6 limité aux adresses locales de lien. Ces valeurs sont des observations de la capture, pas encore une corrélation RouterOS ni une cause établie.

À corréler sur RouterOS : bail DHCP, ARP, Hotspot host/active, réseau DHCP/gateway/DNS et règles NAT/filter au moment du relevé. La session administrateur du navigateur a expiré pendant la tentative de diagnostic ; refaire cette lecture après réauthentification par l'utilisateur. Ne pas partager de mot de passe.

À faire :

1. Pour chaque incident réel, relever heure, SSID/AP/CPE, IP, MAC, serveur Hotspot, appareil/OS, résultat DNS et URL captive tentée ; comparer au même moment avec un client fonctionnel du même segment.
2. Utiliser le diagnostic global au moment du symptôme, puis comparer la ligne du client concerné aux journaux client/AP et au comportement navigateur (HTTP, HTTPS, détection captive, IPv6).
3. Classer les résultats par couche : attribution DHCP, résolution DNS, présence Hotspot, autorisation/authentification, redirection HTTP/HTTPS, chemin L2/AP/CPE.
4. Compléter l'outil si les incidents montrent un manque de données ; garder l'état courant distinct de toute observation historique.
5. Séparer les recommandations des corrections ; ne jamais supprimer automatiquement un bail, une entrée ARP ou un Hotspot host sur la seule base d'une absence ponctuelle.

Critère de fin :

- les incidents reproductibles sont associés à une couche et à une preuve comparable entre client fonctionnel et client en panne ;
- l'outil distingue état courant, observation historique et hypothèse ;
- aucune correction destructive n'est proposée sans preuve et confirmation.

Incident réel en cours le 2026-10-03 (voir `docs/KNOWN_ISSUES.md` du même jour) : plus aucune tentative de login Hotspot (réussie ou échouée) depuis 02:31:55 alors que des appareils continuent d'obtenir un bail DHCP — redirection vers la page de login en panne pour les clients non authentifiés. Couche suspectée : résolution DNS locale (`androndra.wifi.mg`), non confirmée. Portail et page `/status` eux-mêmes fonctionnent pour un client déjà authentifié (testé en conditions réelles). Reste à tester : un appareil non authentifié, fraîchement reconnecté.

---

# Priorité 5 — Topologie Réseau Et Identification Des Équipements

Statut : `IN_PROGRESS`

But : présenter l'instantané LAN sous forme d'arborescence navigable, pour retrouver rapidement un équipement ou un client dans son chemin réseau :

```text
Routeur CHR
├── interface / port
│   ├── AP 1
│   │   └── CPE 1
│   │       └── routeur client AC2xx
│   │           ├── client A
│   │           └── client B
│   └── AP 2
└── interface / port
   └── clients directement observés
```

La représentation doit refléter les relations connues à l'heure du relevé, pas imposer cette structure si RouterOS n'en apporte pas la preuve.

État actuel : l'inventaire lecture seule testé le 2026-09-29 retourne une liste plate de 24 voisins LAN sur `bridge1` / `ether3`, réseau `10.10.10.1/23` ; 6 ont un bail dynamique et 18 restent inconnus, aucun bail statique n'est confirmé. Il ne construit pas encore l'arborescence.

Livraison du 2026-10-02 (étapes 1 à 3, à valider en réel) :

- `dhcp_leases.php` lit uniquement le dernier instantané SQL `lan_inventory_snapshots` du `device_id` actif ; RouterOS n'est lu que par le bouton « Mettre à jour » (`api/lan_inventory.php`, action `refresh`, lecture seule, ~10 s).
- Preuve de lien retenue : une MAC portée par plusieurs IP (Hotspot host / ARP) = pont de traduction MAC ; le CPE non MikroTik du pont est le porteur, les routeurs MikroTik du pont sont derrière lui. Exemple relevé : `CPE610 (10.10.10.66) › AC2-Tovo (10.10.10.232) › clients`. Le lien routeur → client dans un même pont reste affiché « non confirmé ».
- Pont sans CPE identifié (ex. MAC `88:25:93:80:1C:26` portant AC2-Odon, AC2-Mika, AC2-MmHery) : affiché « Porteur non identifié ». Rattachement manuel possible par équipement (`lan_inventory_links`, refus des boucles et des équipements absents de l'instantané).
- Les popups « Contrôle global » et « Inventaire AP/CPE » sont supprimés ; leurs anomalies (DHCP/ARP/Hotspot, IP hors LAN, MAC partagée sans équipement) et actions sont dans le tableau principal. Les actions revérifient IP/MAC sur RouterOS avant suppression puis relancent la mise à jour.
- Reste ouvert : interrogation optionnelle des routeurs clients (registration table / bridge host) pour confirmer routeur → client ; CPE TP-Link (phase 2) ; AP0 `10.10.10.20` refuse l'API.

Révision du 2026-10-02 (retour utilisateur « tableau plat illisible ») : le rendu passe d'un tableau à plat à un arbre replié par équipement (`<details>` imbriqués, `lanInventoryBuildTree()`), groupé par port LAN puis par équipement porteur du pont MAC. Les équipements/clients sans preuve de rattachement restent visibles mais repliés sous « sans rattachement prouvé » par port, pour ne pas les mélanger aux groupes prouvés. Contexte réseau confirmé par l'utilisateur : CHR avec 2 WAN Starlink sur ETH1/ETH2, LAN bridgé Proxmox sorti sur ETH3 ; chaîne observée capteur L23 / CPE émetteur → récepteur → AP WiFi6 → AP suivants → clients, cohérente avec les chaînes CPE→routeur→clients retrouvées dans l'instantané.

Nouvelle révision du 2026-10-02 (retour utilisateur sur l'arbre : illisible, veut une liste plate) :
- Retour à une liste plate triable (table_sort.js), sans notion de rattachement/parent. Ordre par défaut : capteur/CPE (non MikroTik) puis routeur MikroTik puis clients, trié par IP dans chaque groupe (`order_rank` calculé dans `lanInventoryBuild()`).
- Le rattachement manuel (`lan_inventory_links`, `lanInventoryBuildTree()`) est retiré : code mort supprimé d'`includes/lan_inventory.php`, `api/lan_inventory.php` (action `link`) et `js/dhcp_leases.js`.
- Correction d'anomalie optimisée : `api/dhcp_conformity_action.php` filtre désormais côté RouterOS (`?.id=`) au lieu de rapatrier toute la table ARP/Hotspot host pour la filtrer en PHP — mesuré à 16 ms par lecture ciblée contre un print complet, sans changement de comportement fonctionnel.
- Le lien CPE→routeur→client prouvé par MAC partagée reste détecté comme anomalie (« MAC partagée par N IP ») mais n'est plus affiché en arbre ; il n'est plus visuellement exploité pour l'instant, faute d'adhésion de l'UI proposée.

Sources de liens topologiques :

Correction du 2026-09-30 : l'inventaire inclut désormais les équipements vus seulement dans ARP sur le LAN, avec détection affichée, noms publiés par les voisins/DHCP/DNS statique et état « Statique probable — hors pool DHCP » lorsque les pools lus l'étayent. Tests locaux réussis ; validation de l'affichage réel des CPE à IP manuelle encore ouverte. Aucun adressage ni bail modifié.

- `/ip/neighbor` : voisin découvert, identité, plateforme, version et interface RouterOS où il est vu ; preuve d'un voisin direct sur cette interface, pas de toute sa descendance ;
- `/interface/bridge/host` : MAC observée derrière un port/bridge ; utile pour grouper des clients derrière une liaison, mais ne révèle pas nécessairement chaque AP/CPE intermédiaire ;
- DHCP lease, ARP et Hotspot host/active : association courante d'une IP/MAC à un client, à corréler à l'instantané ;
- plusieurs équipements derrière le même port sans preuve d'un lien précis doivent rester dans un groupe « derrière ce port / parent inconnu », jamais être reliés par supposition.

À faire :

1. Construire les nœuds à partir de l'identité RouterOS, MAC, modèle/plateforme/version, IP de gestion candidate et interface observée.
2. Construire les liens parent-enfant uniquement depuis l'interface voisine et les correspondances MAC/bridge host ; représenter l'incertitude explicitement.
3. Ajouter les clients comme feuilles corrélées par IP/MAC avec leur bail, ARP, Hotspot host/active et horodatage de présence.
4. Classer AP, CPE, routeurs clients AC2xx, bridge transparent, pseudo-bridge et clients finaux à partir d'indices observés ; permettre une correction/confirmation humaine du rôle sans changer RouterOS.
5. Permettre de développer/replier chaque branche et de rechercher un équipement/client pour retrouver son chemin jusqu'au CHR.
6. Garder les IP statiques/réservations comme chantier séparé, uniquement pour les équipements de gestion identifiés et après validation du plan d'adressage.

Critère de fin :

- l'arbre situe les voisins directement observés sous le bon port/interface du CHR ;
- chaque relation incertaine est affichée comme groupe ou parent inconnu, jamais inventée ;
- une recherche client permet de retrouver sa branche AP/CPE/routeur lorsqu'elle est observable ;
- les clients finaux restent distincts des équipements d'infrastructure ; aucune IP ni configuration RouterOS n'est modifiée par l'inventaire.

---

# Priorité 6 — Allègement De `traffic_control.php`

Statut : `DONE` (clôturé par l'administrateur le 2026-10-03)

Note de clôture : seul le point 2 (doublon supposé avec `traffic_monitoring.php`) a été réexaminé — conclusion : probablement un faux doublon, les deux pages couvrant des périmètres différents (classement par utilisateur côté `traffic_control.php#usage` vs vue par interface WAN côté `traffic_monitoring.php`), voir `docs/KNOWN_ISSUES.md` (2026-10-03). Les points 1, 3 et 4 (inventaire complet des graphes/collectes, chargement à la demande, vérification des flux actifs sur onglet masqué) n'ont pas été vérifiés aujourd'hui ; clôturé sur décision explicite de l'administrateur, pas sur preuve des 4 critères.

Principe : `traffic_control.php` doit rester une page de contrôle, pas une page d'historique lourd.

Déjà fait : le doublon « Limite globale du bridge Hotspot » a été retiré de `traffic_control.php`; le contrôle reste dans `traffic_monitoring.php`.

À faire :

1. Inventorier les graphes, tableaux, requêtes et collectes encore présents dans `traffic_control.php` et désigner leur page propriétaire.
2. Retirer ou déplacer les historiques qui relèvent de `traffic_monitoring.php`, sans supprimer leurs APIs partagées.
3. Mesurer les appels backend lents et charger les sections réellement lourdes à la demande.
4. Vérifier qu'aucun flux/collecteur ne reste actif sur un onglet masqué ou après avoir quitté la page.

Critère de fin :

- ouverture rapide ;
- pas de collecte graphique cachée ;
- page réservée au contrôle.

---

# Priorité 7 — Nettoyage Runtime Et Documentation

Statut : `DONE` (clôturé par l'administrateur le 2026-10-03)

Fait le 2026-10-03 :

1. Usages établis pour les candidats legacy identifiés : support OPNsense confirmé actif (double stack avec MikroTik, pas hérité/mort), scheduler générique (`api/*_mikrotik_scheduler.php`) confirmé distinct et non dupliqué avec le scheduler d'expiration de profil (`ensureMikrotikSharedProfileExpiryScheduler()`), les 17 fichiers `.old` déjà présents confirmés non inclus par aucun runtime PHP.
2. Aucun chemin runtime actif trouvé à retirer au-delà des 2 ci-dessous (pas de second système concurrent actif détecté dans le périmètre audité).
3. `api/debug_recent_events.php` et `api/cpu_stream.php` renommés en `.old` (zéro référence confirmée par grep avant action).
4. `docs/DECISIONS.md` et `docs/KNOWN_ISSUES.md` mis à jour avec les preuves ci-dessus.

Note de clôture (honnêteté de portée) : l'inventaire a couvert les candidats signalés par une première passe d'exploration (api/*.php racine, fichiers "opnsense", fichiers "test_"/"debug_", schedulers) et vérifiés manuellement un par un ; ce n'est pas un audit exhaustif de tout `includes/`, `scripts/` et `tools/`. `docs/ARCHITECTURE.md` non modifié : rien dans les changements d'aujourd'hui n'a altéré le flux architectural qui y est décrit.

---

# Priorité 8 — Audit Global De La Configuration RouterOS

Statut : `DONE` (relevé exhaustif du périmètre identifié le 2026-10-02 ; voir `docs/KNOWN_ISSUES.md` pour le détail et les preuves)

Premier relevé du 2026-10-02 sur le CHR (`dev_1775288316`), lecture seule, preuves consignées dans `docs/KNOWN_ISSUES.md` :
- 812 objets `/system script` de type journal (Mikhmon on-login/recharge), `run-count=0`, croissance continue (61 en juillet, 386 en août, 348 en septembre, 16 début octobre), non lus par le code applicatif actuel.
- 17 planificateurs (un par profil) toutes les 2-3 minutes, source probable de cette accumulation.
- 89 queues simples `<hotspot-user-N>` à 4 Mbps, à confronter au `rate-limit` déjà présent sur les profils Hotspot concernés avant de conclure à un doublon.
- Pare-feu/NAT/listes d'adresses : rien d'anormal détecté à ce stade.
Clos le 2026-10-02 : confirmé par l'administrateur que l'interface Mikhmon consulte encore cet historique directement via ces scripts — ce n'est pas un déchet, aucune suppression à faire. Détail dans `docs/KNOWN_ISSUES.md`.

Relevé complémentaire du 2026-10-02 :
- Confirmé : les 17 planificateurs ne génèrent pas les scripts d'historique (ils suppriment les utilisateurs expirés) ; code dupliqué 17 fois, fusionnable.
- Infirmé après vérification : pas de doublon rate-limit/queue simple — 88/90 queues sont `dynamic=true`, générées par RouterOS lui-même via `parent-queue` du profil. Aucune action requise sur ce point.
- Décidé le 2026-10-02 : `ensureMikrotikProfileScheduler()` ne doit plus s'exécuter depuis `getMikrotikHotspotUsers()` (une simple lecture de la liste des utilisateurs Hotspot ne doit jamais réécrire le routeur). Retiré de `includes/mikrotik_backend.php` (bloc qui s'exécutait au mieux toutes les 60 s, au cache des métadonnées profils). Les 3 déclencheurs conservés, tous liés à une action explicite de l'administrateur : création de profil (`api/profiles/create_profile.php`), modification de profil (même page), import standard MikroTik (`api/admin/mikrotik_import_standard.php`). La fusion en planificateur unique (ci-dessus) devra être branchée sur ces 3 points uniquement.
- Fait et validé le 2026-10-02 sur le CHR réel : fusion des 16 planificateurs en un seul `RM-PROFILE-EXPIRY`, preuve avant/après en conditions réelles (2 exécutions confirmées, aucune erreur, aucune régression), détail dans `docs/KNOWN_ISSUES.md`.
- Confirmé : aucune sauvegarde RouterOS planifiée (`/system backup`) — à mettre en place avant tout nettoyage de script.
- Anomalies IP croisées avec le pool DHCP : voir `docs/KNOWN_ISSUES.md` du 2026-10-02 (MAC générique suspecte `10.10.10.103`, fuite NAT confirmée sur `AC2-IsmL`).

But : analyser la configuration MikroTik réelle et proposer une optimisation ordonnée du réseau, en ciblant les doublons, l'ordre des règles et les ressources CPU/mémoire.

Périmètre en lecture seule :

- Hotspot : servers, profiles, paramètres de login, cookies, timeouts et dépendances ;
- firewall : filter, NAT, mangle, raw, address-lists, ordre, recouvrements et règles masquées ;
- schedulers et scripts : fréquence, compteurs d'exécution, doublons, scripts invalides et opérations coûteuses ;
- DHCP, DNS, bridges, interfaces, routes, queues et conntrack ;
- état de ressources et charge observée : CPU, mémoire, connexions, files, débit et journaux pertinents.

Méthode :

1. Capturer l'identité/version RouterOS et un inventaire horodaté rattaché au `device_id` exact ; exclure secrets, mots de passe et clés des artefacts partagés.
2. Classer chaque entrée : active, inactive, référencée, redondante probable, contradictoire ou non déterminée ; démontrer l'ordre et les effets avant de qualifier un doublon.
3. Corréler compteurs d'exécution et ressources avant/après une période représentative ; ne pas déduire un coût CPU d'une simple présence de règle.
4. Produire une liste de recommandations priorisée par impact, preuve, risque, dépendances et plan de rollback ; ne rien modifier pendant l'audit.
5. Après revue, appliquer au plus un groupe cohérent à la fois, avec export de sauvegarde, confirmation, test fonctionnel et mesure comparative.

Critère de fin :

- inventaire exhaustif et sécurisé de la configuration ciblée ;
- doublons, ordre des règles et coûts de ressources documentés avec preuves ;
- plan d'optimisation et rollback validés avant tout changement RouterOS.


Dernier volet vérifié le 2026-10-02 (conntrack, ordre pare-feu/NAT) :
- Conntrack : 3984/843776 entrées (0,47%), aucune tension.
- CPU 8%, RAM libre 73% — marge confortable.
- Ordre des règles filter vérifié précisément : `*2E` (drop WAN input) et `*41` (allow LAN input) portent sur des listes d'interfaces différentes (`WAN` vs `LAN`), aucun chevauchement/conflit d'ordre.
- Quelques règles à hit quasi nul (`*3D`, `*64`) sont des garde-fous de sécurité rarement déclenchés par construction, pas des règles mortes.
Rien à corriger sur ce volet.
---

# Chantiers Gelés

Restent gelés jusqu'à validation des priorités 1 à 3 :

- nouveaux visuels ;
- refonte graphique ;
- extension WAN3/WAN4 ;
- modification PCC avancée ;
- règles firewall non liées à un diagnostic validé ;
- automatisation corrective ARP/Hotspot non prouvée.

---

# Ordre D'Exécution Recommandé

1. Terminer les validations terrain N-WAN ; conserver le statut ouvert jusque-là.
2. Définir puis valider le mode dégradé contrôlé.
3. Terminer la validation des compteurs WAN autonomes.
4. Instrumenter et diagnostiquer les pannes intermittentes du portail captif.
5. Stabiliser AP/CPE/IP statiques à partir de l'inventaire vérifié.
6. Finir l'allègement de `traffic_control.php`.
7. Nettoyer les anciens chemins runtime et finaliser la documentation après validation.
8. Réaliser l'audit RouterOS en lecture seule, puis appliquer séparément les seules optimisations approuvées.

---

## Avancement au 2026-10-06

**Fait depuis le 2026-10-02**
- N-WAN : règle de connexion qui écarte un WAN HS pour les nouvelles IP ; journal des transitions WAN (écriture seule si l'état change) ; procédure de retour arrière.
- Mode dégradé (P2) : débit 3M/3M stocké sur 15 profils ; machine d'états testée en local ; alerte par transition (`admin_notifications`) ; état natif lu sur le routeur.
- Comptes Hotspot : types « identifiant + mot de passe » (avec restriction MAC facultative) et « MAC sans mot de passe » ; formulaire et fiche de détail alignés ; libellés des comptes MAC ; login par MAC validé.
- Tableau de bord : événements MAC filtrés et traduits, source unique (`hotspot_log_events.php`).
- Portail captif : messages d'erreur traduits à partir du fichier d'origine du routeur (`errors.txt`), modèle actif et modèle par défaut redéployés et vérifiés, bouton de connexion grisé au premier envoi.
- Bugs corrigés : enregistrement d'un binding (MAC vide refusée par RouterOS, succès affiché à tort) ; identifiant de device vide sur les lignes de la liste utilisateurs.

**Reste à faire**
1. P2 : fait (état WAN affiché, bascule des débits 25 Mb/s / 3M/3M en DEGRADED et retour en NORMAL, cas DOWN = alerte seule). Reste : validation d'une panne réelle avec clients connectés (accord requis).
2. P1 : validation terrain (nouvelle connexion, reconnexion, redémarrage, déconnexion, routage effectif).
3. P3 : surveillance des trous sur plusieurs heures ; validation du rendu des barres dans le navigateur.
4. P4 : tester un appareil non authentifié fraîchement connecté (le cas n'a jamais été vérifié).
5. Comptes MAC : libellé « Gaga » à ajouter en table ; enregistrement réel d'une modification de compte MAC et d'une restriction MAC (écriture sur le routeur, accord requis).
6. Validité expirée : aucun message d'origine ; à vérifier sur un compte réellement expiré.
7. Restriction MAC : vider une valeur MAC n'est pas encore géré (RouterOS refuse une valeur vide).
8. Code mort : suppression de `includes/multi_wan_allocator.php` (8 fonctions) : accord requis (le classifieur bloque la suppression).
9. Sécurité : Winbox, SSH et API accessibles depuis tout le LAN ; binding `10.10.10.15` désactivé ; mot de passe root Proxmox trop faible à changer.
10. OPNsense : reste en pause (décision du 2026-10-06).
11. Dépôt git : nombreux changements non commités, à regrouper avant un commit.

Le plan est mis à jour dans `docs/PROJECT_PLAN.md`.

---

# Tests en conditions réelles (à planifier avec l'accord de l'administrateur)

Regroupés pour être lancés ensemble, car ils touchent aux clients connectés.

1. **Mode dégradé (P2)** : panne réelle d'un WAN avec clients connectés ; vérifier la bascule à 25 Mb/s et 3M/3M, puis le retour à 100 Mb/s et 6M/6M.
2. **N-WAN (P1)** : nouvelle connexion, reconnexion, redémarrage, déconnexion, routage effectif.
3. **Portail (P4)** : appareil non authentifié fraîchement connecté ; message de validité expirée sur un compte réellement expiré.
4. **Comptes MAC** : modification réelle d'un compte MAC, restriction MAC sur un compte avec mot de passe, connexion de Gaga après libellé.
5. **Compteurs (P3)** : surveillance des trous sur plusieurs heures ; rendu des barres dans le navigateur.
6. **Filtrage DNS 853** : effet réel sur les clients.

