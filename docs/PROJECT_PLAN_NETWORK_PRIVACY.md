# Plan Projet - Reduction Des Empreintes Reseau

## Statut

`IN_PROGRESS` - objectif actif centre sur le MikroTik `10.10.10.1`. Phase 0 terminee ; phase 1 proche de la cloture, sous observation Hotspot/DHCP pendant 24 a 48 heures. Les mutations deja validees sont documentees et relues sur le routeur.

Baseline : `docs/NETWORK_PRIVACY_BASELINE_2026-09-03.md`.

Matrice de validation : `docs/NETWORK_PRIVACY_TEST_MATRIX.md`.

## Objectif

Reduire au maximum les signaux permettant a un acteur exterieur de caracteriser les appareils, les usages ou l architecture du reseau `10.10.10.0/23`.

Priorite d execution : securiser le routeur frontal MikroTik `10.10.10.1`. Le serveur applicatif `10.10.10.2` reste une dependance observee, mais son durcissement local est reporte et ne constitue pas l etape suivante du chantier actif.

Chaque risque du document initial doit recevoir un statut explicite : `observe`, `a confirmer`, `reduit`, `corrige`, `reporte` ou `non traitable au niveau reseau`.

Principes :

- analyser avant d agir ;
- notifier puis demander une validation manuelle ;
- relire la source technique apres chaque action ;
- conserver un retour arriere documente ; le tester ou consigner explicitement son classement `NOT_APPLICABLE` ;
- ne pas presenter une reduction partielle comme une invisibilite totale ;
- conserver MikroTik comme source unique et moteur d execution DNS/filtrage ; SQL ne porte que configuration applicative, mesures et historique.

## Cadrage Des Sept Risques Du Document Source

| Point | Capacite reelle du projet | Traitement retenu | Limite explicite |
|---|---|---|---|
| 1. User-Agent, publicite et empreinte navigateur | Reduction indirecte | Bloquer sur MikroTik les domaines de pistage confirmes et proposer des politiques clientes | RouterOS ne peut pas reecrire le User-Agent dans HTTPS sans interception TLS, exclue du projet |
| 2. DNS et WebRTC | DNS fortement reductible ; WebRTC partiellement observable | Centraliser DNS, chiffrer l amont, detecter les contournements et observer STUN/TURN | Le routeur ne peut pas supprimer toutes les donnees WebRTC exposees par le navigateur ; le controle final est aussi cote terminal |
| 3. Cloud et telemetrie IoT | Reductible apres attribution | Inventaire par appareil, blocage DNS confirme, filtrage IP/port et quarantaine | HTTPS masque le contenu ; une destination partagee ne doit pas etre bloquee sur simple supposition |
| 4. Signature de trafic et DPI | Reductible, jamais effacable | Mesure, QoS justifiee et futur pilote WireGuard | Le VPN deplace la confiance vers le VPS et laisse visibles volume, cadence, IP du tunnel et horaires |
| 5. NTP et HTTP en clair | Centralisable et filtrable | Synchroniser le MikroTik, servir NTP local, rediriger UDP/123 ; observer HTTP avant filtrage cible | NTP classique reste non chiffre en amont ; le blocage HTTP global casserait portails, redirections et anciens appareils |
| 6. ICMP, TTL et erreurs | Durcissable sans casser le reseau | Politique ICMP par types necessaires, limitation seulement sur preuve, retrait du TTL force si inutile | Bloquer tout ICMP casse notamment la decouverte MTU et complique le diagnostic |
| 7. VPN de sortie | Option future a fort impact | WireGuard vers VPS, pilote limite, DNS dans tunnel, kill switch et retour arriere | Ne garantit ni anonymat ni invisibilite et peut degrader debit, latence, MTU et disponibilite |

Les commandes du document source sont des exemples, jamais des commandes a appliquer directement. Toute regle est reconstruite selon l ordre reel du pare-feu, le `device_id`, les interfaces et la matrice de validation.

## Etat Existant Verifie

### Application

- `traffic_control.php` fournit deja observation Conntrack, stabilite, parametres, politiques et liste blanche.
- L API gere Adlist MikroTik, DNS force, blocages DNS cibles, liste blanche et historique des actions.
- La collecte reseau conserve 48 heures de mesures ; 5 075 echantillons existaient lors de l audit.
- NTP, VLAN, WebRTC, services exposes et VPN ne sont pas encore pilotes ; le premier socle de filtrage WAN et DNS est actif sur le MikroTik. Le pilote AdGuard est abandonne.
- La politique existante `windows_update` ne couvre que Windows et repose sur une courte liste statique de domaines.
- La relecture RouterOS du 4 septembre 2026 confirmait initialement l absence de blocage Apple ou Android. Des blocages DNS cibles ont ensuite ete appliques sur les endpoints observes : `mesu.apple.com`, `updates-http.cdn-apple.com` et `update.googleapis.com`. Le domaine Google Federated ML reste classe comme telemetrie, pas comme mise a jour Android.

### MikroTik `10.10.10.1`

- RouterOS `7.21.5`, WAN `ether1`, LAN `bridge1`, reseau `10.10.10.0/23`.
- 150 baux DHCP, dont 143 actifs lors de l audit ; environ 30 equipements MikroTik en aval et plusieurs CPE/routeurs tiers.
- DNS force vers le MikroTik, resolvers Google/Starlink en clair, aucun DoH.
- Adlist StevenBlack active avec 78 622 noms et 36 837 correspondances relevees.
- Serveur NTP actif mais client NTP arrete.
- Aucun VLAN, WireGuard ou routage VPN.
- Le pare-feu `input` accepte les connexions etablies, rejette les paquets invalides, preserve le client DHCP WAN puis rejette tout autre acces provenant du WAN.
- SSH, HTTP et WinBox sont autorises depuis `10.10.10.0/23` et bloques depuis le WAN.
- API et FTP sont limites au serveur applicatif `10.10.10.2/32` ; FTP reste requis par le deploiement du portail.
- Telnet et API-SSL sont desactives ; le serveur de bande passante RouterOS est desactive.
- MAC Server et MAC WinBox sont limites a la liste `LAN`, qui contient `bridge1`.

### Regles A Corriger Avant Extension

- Les regles manuelles `Starlink-Shield` ne sont pas gerees par l application.
- Elles bloquent presque tout nouveau trafic UDP et tout nouveau TCP hors `80/443/8080`.
- Elles peuvent casser QUIC, jeux, appels, WebRTC, NTP et VPN sans masquer la topologie.
- Elles seront observees, remplacees et retirees une par une, jamais supprimees globalement sans test.

### Serveur `10.10.10.2`

- Debian/LXC, 2 Gio de RAM, port DNS 53 libre ; Apache occupe 80/443.
- Pare-feu local actuellement permissif.
- SSH autorise `root` et les mots de passe.
- MariaDB reste correctement liee a `127.0.0.1`.
- Le serveur doit rester durci selon son propre perimetre ; il n est plus une cible de migration DNS.

## Architecture Cible

- MikroTik : source unique pour routage, NAT, Hotspot, queues, resolution DNS, Adlists, blocages, exceptions, reecritures DNS/NTP et filtrage reseau.
- AdGuard sur `10.10.10.2` : ancien pilote sans role dans le chemin DNS cible ; reference historique et reference d interface uniquement.
- SQL : configuration de service, mesures, notifications et historique uniquement.
- VPS futur : sortie WireGuard optionnelle apres pilote ; `10.10.10.2` ne peut pas etre une sortie externe puisqu il utilise le meme WAN.

## Phases

### Phase 0 - Baseline Et Sauvegardes

Etat : `DONE`.

- Exporter la configuration MikroTik et sauvegarder les regles existantes avec leur ordre et leurs compteurs.
- Mesurer pendant 48 heures DNS, ports, STUN/TURN, telemetrie, pics, concurrence, charge et erreurs.
- Corriger la classification qui assimile les drops normaux des queues a des incidents.
- Definir les tests de navigation, Hotspot, mises a jour, streaming, jeux, appels et administration.

### Phase 1 - Durcissement MikroTik Prioritaire

Etat : `IN_PROGRESS` - validations LAN/WAN et portail captif terminees.

- Maintenir le socle `input` WAN deja applique et controler ses compteurs.
- Maintenir SSH, HTTP et WinBox autorises depuis `10.10.10.0/23` et bloques depuis le WAN. Conserver API/FTP limites a `10.10.10.2/32` tant que l application en depend.
- Conserver FTP uniquement tant que le deploiement du portail en depend ; supprimer cette dependance avant desactivation.
- Conserver Telnet, API-SSL et le serveur de bande passante desactives.
- Maintenir MAC Server et MAC WinBox sur la liste `LAN`, qui contient `bridge1`, et hors du WAN. Ces services de couche 2 constituent un acces interne de secours et ne sont pas destines a traverser le WAN route.
- Tester WinBox, SSH et WebFig depuis au moins un poste interne, puis verifier depuis une source WAN qu aucun de ces services n est accessible. Le refus depuis un client Hotspot n est pas un critere de cette phase.
- Piloter depuis `traffic_monitoring.php` une Simple Queue uniquement sur le bridge portant le Hotspot actif du routeur selectionne ; identifier ce bridge depuis RouterOS, le presselectionner, gerer les debits et la priorite `1..8`, puis relire le parent et les files invalides.
- Conserver la regle lors d une desactivation : l interface utilise un bouton standard `Activer`/`Desactiver`, jamais une suppression pour ce controle global.
- Distinguer plafond global, maximum individuel et equite instantanee : un client lourd peut utiliser le debit libre, mais doit ceder sous congestion a un autre client actif ; aucune penalite fondee sur l historique de consommation ne sera appliquee implicitement.
- Etat du controle de debit : parent actif `RM interface bridge1`, cible `bridge1`, plafond RouterOS `15000000/50000000`, priorite `1/1`, 16 profils limites rattaches et 43/43 files dynamiques valides lors de la derniere verification.
- Validations terminees : administration WinBox/SSH/WebFig depuis le LAN et refus depuis le WAN ; portail captif avec un nouveau client.
- Le test de retour arriere est classe `NOT_APPLICABLE` : un Safe Mode lie a une session ne peut pas etre simule fidelement par l API et le test manuel est abandonne par decision operateur.
- Restant avant cloture : tester l equite avec au moins deux clients concurrents, puis observer Hotspot/DHCP pendant 24 a 48 heures.

### Phase 2 - Durcissement Differe De `10.10.10.2` - Hors Priorite Active

Etat : `DEFERRED`. Cette phase ne doit pas interrompre le traitement de `10.10.10.1` et ne devient pas automatiquement la prochaine etape apres la phase 1.

- Le pare-feu local teste a ete restaure dans son etat permissif ; aucune restriction serveur n est actuellement active.
- Appliquer ensuite un pare-feu local en refus entrant par defaut.
- Autoriser seulement administration, application, RADIUS et futur DNS depuis les sources necessaires.
- Creer un acces SSH nominatif par cle avant de desactiver `root` et l authentification par mot de passe.
- Verifier Apache, FreeRADIUS et MariaDB apres chaque modification.

### Phase 3 - Remplacement De `Starlink-Shield`

Etat : `NEXT` apres cloture de l observation de phase 1 sur `10.10.10.1`.

- Mesurer chaque regle existante avant changement.
- La page `traffic_control.php` classe les appareils par volume conntrack, distingue les connexions sortantes sans debit instantane et recoupe leurs destinations avec le cache DNS RouterOS.
- Les familles Windows, Apple et Android/Google restent des signaux probables deduits des domaines correles, jamais une identification certaine du terminal ni un motif de blocage automatique.
- Un domaine observe peut etre prepare dans le formulaire de blocage DNS cible ; l administrateur doit encore le verifier et confirmer explicitement son confinement en `NXDOMAIN`.
- Remplacer les blocages TCP/UDP globaux par des politiques ciblees par zone, appareil ou service.
- Evaluer la normalisation TTL sur preuves ; la retirer si aucun benefice mesurable n est etabli.
- Conserver une restauration individuelle de chaque ancienne regle.

### Phase 4 - Pilote AdGuard Home Abandonne

Etat : `ABANDONED` - historique conserve ; aucune migration cliente ou politique DNS vers AdGuard ne doit reprendre.

- Lors du pilote, AdGuard Home officiel `v0.107.79` Linux AMD64 a ete extrait dans `/opt/AdGuardHome`.
- Archive officielle verifiee avec SHA-256 `c48f4a43000665484c5ec28177de11a004759b620dae8f77b2aabefc9ef3687f`.
- Lors de la derniere verification du pilote, le service `systemd` etait installe, actif au demarrage, avec administration sur `10.10.10.2:3001` et DNS sur TCP/UDP 53 ; cet etat n est pas une dependance du runtime cible.
- Le pilote avait active DNSSEC, Quad9 DoT `tls://9.9.9.10`, AdGuard DNS Filter, StevenBlack et la reecriture `androndra.wifi.mg -> 10.10.10.1`. Ces faits historiques ne decrivent pas le chemin DNS actuel des clients.
- L interface AdGuard peut inspirer l ergonomie de la page PHP, sans appel runtime, synchronisation de politiques, stockage d identifiants ni seconde source de verite.

### Phase 5 - Centralisation Et Chiffrement DNS MikroTik

- Conserver DHCP et les redirections DNS TCP/UDP 53 vers le MikroTik ; supprimer ou laisser desactivees les regles du pilote AdGuard apres verification.
- Administrer les listes, blocages, exceptions et reecritures exclusivement sur le routeur exact selectionne.
- Verifier l amont DNS actuel du MikroTik. La baseline indique des resolvers en clair et aucun DoH : le chiffrement TLS n est donc pas acquis.
- Tester puis configurer le DoH RouterOS avec validation du certificat seulement si la latence, les erreurs, le portail et le retour arriere restent conformes.
- Detecter DoT, DoQ et les resolvers DoH connus ; proposer leur filtrage avec impact et confirmation.
- Ne pas introduire AdGuard ou un DNS classique silencieux comme fallback : toute restauration doit etre explicite et relue sur MikroTik.

### Phase 6 - Gestion Des Mises A Jour

Les mises a jour ne doivent plus etre traitees par une unique regle Windows statique. Le module doit separer :

- Windows Update, Microsoft Store et Delivery Optimization ;
- distributions Linux et gestionnaires de paquets ;
- Apple Software Update, App Store et services systeme Apple ;
- Android, Google Play, constructeurs et mises a jour OTA.

Pour chaque famille :

- etat `autorise`, `surveille`, `planifie` ou `bloque` ;
- politique globale, par zone ou par appareil ;
- plages horaires de blocage ou d autorisation ;
- domaines observes depuis les donnees MikroTik, sans inventer une liste universelle ;
- estimation du volume et derniere activite ;
- avertissement sur les effets de securite d un blocage prolonge ;
- exception urgente et retour arriere immediat.

Les listes de domaines sont executees sur MikroTik. Le module PHP les administre sur le routeur exact selectionne et chaque changement est historise.

### Phase 7 - NTP Et Protocoles Non Securises

- Activer et verifier le client NTP MikroTik avant d utiliser son serveur local.
- Distribuer le MikroTik comme NTP et rediriger UDP/123 des clients.
- Traiter NTP comme une centralisation de la fuite et non comme un chiffrement : documenter l unique flux NTP amont restant.
- Observer HTTP sortant et bloquer uniquement les services ou appareils confirmes.
- Ne jamais bloquer globalement HTTP, NTP ou ICMP sans preuve et pilote.

### Phase 8 - Services Exposes Par Les Appareils

- Inventorier les services accessibles sur chaque appareil depuis les zones autorisees.
- Distinguer service local expose, connexion sortante vers un service cloud et simple domaine consulte.
- Correlier IP/MAC, identite DHCP/Hotspot, ports et domaines observes par MikroTik.
- Permettre un blocage DNS des services cloud exposes par un appareil lorsque le domaine est confirme.
- Permettre un filtrage reseau par IP/port lorsque DNS ne peut pas traiter le service local.
- Ne jamais presenter un blocage DNS comme une fermeture de port local.
- Fournir une action rapide : `autoriser`, `surveiller`, `bloquer DNS`, `bloquer port`, `quarantaine`.

### Phase 9 - Filtrage Manuel Simplifie

Ajouter un atelier de filtrage avec :

- recherche par appareil, utilisateur, IP, domaine, port, protocole ou categorie ;
- filtres `publicite`, `tracking`, `telemetrie`, `mise a jour`, `cloud IoT`, `WebRTC`, `inconnu` ;
- selection d une ou plusieurs observations ;
- choix de portee : appareil, zone ou reseau ;
- choix d action : surveiller, autoriser, bloquer DNS, bloquer IP/port ou mettre en quarantaine ;
- apercu de la regle exacte et des services potentiellement affectes ;
- confirmation manuelle ;
- lecture de l etat reel apres application ;
- bouton de restauration depuis l historique.

Une observation ne devient jamais automatiquement une regle. Les blocages DNS et les filtres IP/port sont ecrits uniquement sur le MikroTik selectionne.

### Phase 10 - Isolation Progressive

- Integrer progressivement les acces administratifs des MikroTik/CPE en aval.
- Classer administration, infrastructure, utilisateurs, IoT/TV et quarantaine.
- Deployer les VLAN site par site selon les capacites reelles des equipements.
- Interdire l acces IoT aux zones sensibles et n autoriser que les services necessaires.
- Ne pas activer globalement `use-ip-firewall` sur le bridge sans tests CPU et debit.

### Phase 11 - WebRTC, Telemetrie Et Empreintes

- Observer STUN/TURN par appareil avant toute action.
- Proposer observation, blocage cible ou blocage strict par zone avec avertissement fonctionnel.
- Ne pas utiliser de proxy transparent pour modifier les User-Agent HTTPS.
- Prevoir des recommandations gerees par famille de terminal pour limiter WebRTC et le pistage dans les navigateurs, sans pretendre les imposer au niveau routeur.
- Identifier les domaines recurrents de telemetrie depuis les observations MikroTik et proposer chaque blocage avec preuves.

### Phase 12 - Signatures De Trafic Et VPN Futur

- Exploiter la queue globale Hotspot deja corrigee et mesurer volume, pics, UDP et saturation.
- Appliquer seulement un lissage compatible avec la qualite de service.
- Preparer l interface WireGuard sans tunnel actif.
- Avec un futur VPS : pilote limite, DNS dans le tunnel, kill switch, mesure MTU/debit/latence, puis validation avant extension.
- Comparer avant/apres ce que voient Starlink et le VPS ; ne jamais presenter le tunnel comme une suppression totale de l empreinte.

### Phase 13 - ICMP, TTL Et Validation De L Exposition WAN

- Mesurer les types ICMP recus, emis et rejetes avant modification.
- Conserver les messages indispensables au fonctionnement IP, notamment les erreurs liees au chemin et a la fragmentation.
- Tester l exposition depuis un point reellement externe au reseau Starlink, sans confondre le NAT amont avec le pare-feu MikroTik.
- Evaluer la regle de TTL force a `64` sur preuve comparative puis la retirer si elle ne reduit aucun signal mesurable.
- Ne jamais utiliser `reject` lorsqu une politique silencieuse `drop` est requise, et documenter ce choix par regle.

## Module De Gestion Unique

Etendre la page existante avec :

1. Vue d ensemble
2. Empreintes observees
3. Securite DNS MikroTik
4. Mises a jour
5. NTP et protocoles
6. Services exposes
7. Pare-feu
8. Equipements et isolation
9. WebRTC et telemetrie
10. Signatures de trafic
11. VPN
12. Historique
13. ICMP, TTL et exposition WAN

Chaque action affiche etat reel, preuve, risque, impact, portee, apercu, confirmation, resultat et restauration.

## Validation

- Aucun changement massif sans pilote.
- Observation de 24 a 48 heures entre les phases sensibles.
- Tests DNS TCP/UDP 53 client, DNSSEC, DoH amont MikroTik, NTP, Hotspot, queues, mises a jour des quatre familles, jeux, appels et streaming.
- Verification de l exposition depuis le LAN utilisateur et le WAN.
- Verification CSRF, role administrateur, `device_id`, chiffrement des secrets et historique.
- Chaque retour arriere est teste ou classe explicitement `NOT_APPLICABLE` avec sa justification.
- Rapport PDF final comparant les mesures initiales et finales.

## References Techniques De Cadrage

- [MikroTik RouterOS DNS](https://help.mikrotik.com/docs/spaces/ROS/pages/37748767/DNS) : prise en charge DoH, validation de certificat et contraintes du resolveur.
- [MikroTik RouterOS NTP](https://help.mikrotik.com/docs/spaces/ROS/pages/40992869/NTP) : client et serveur fondes sur NTP classique ; aucune promesse de chiffrement implicite.
- [MikroTik Advanced Firewall](https://help.mikrotik.com/docs/spaces/ROS/pages/328513/Building%2BAdvanced%2BFirewall) : conservation des types ICMP utiles et prudence sur les limitations excessives.
- [MDN WebRTC ICE](https://developer.mozilla.org/en-US/docs/Web/API/RTCIceCandidate/address) : une adresse candidate peut contribuer a la topologie et au fingerprinting ; la mitigation complete depend aussi de l application ou du navigateur.

## Plugins

- Aucun plugin supplementaire n est obligatoire pour la premiere version.
- MikroTik est integre par son API directe ; aucune integration runtime AdGuard n est requise.
- Cloudflare reste optionnel si un compte, un domaine, des certificats ou Zero Trust doivent etre administres plus tard.
