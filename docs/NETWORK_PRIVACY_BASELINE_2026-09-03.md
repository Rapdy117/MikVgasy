# Baseline Confidentialite Reseau - 2026-09-03

## Statut

Phase 0 `DONE`. Cette baseline conserve les observations initiales et la chronologie des changements valides jusqu au 4 septembre 2026.

## Sauvegardes MikroTik

- Base : `privacy_phase0_20260903_191229`
- Copie protegee : `/var/backups/radius-manager/network/privacy-phase0/`
- Permissions : dossier `0700`, fichiers `0600`
- Backup binaire : 357271 octets, SHA-256 `54c28880a886242c00c5319653d1a1346b3edbf37cf0c7f64c6c863fff4a02db`
- Export sans secrets : 255853 octets, SHA-256 `b0d5f09aac748abecae35b4580fa2084c3c2e9baee4eebe8f3fcffe1ad8814e4`

Le backup binaire sert a restaurer le routeur source. L export `.rsc` sert a l audit et a une restauration controlee commande par commande.

## Perimetre Observe

- MikroTik CHR `10.10.10.1`, RouterOS `7.21.5`
- WAN `ether1`, LAN `bridge1`, reseau `10.10.10.0/23`
- Serveur Debian `10.10.10.2`
- 150 baux DHCP, dont 143 lies lors de l inventaire
- 56 sessions Hotspot actives lors du dernier releve
- Environ 30 equipements MikroTik en aval et plusieurs CPE/routeurs tiers

## Mesures Sur 48 Heures

Periode : `2026-09-01 19:13:31` a `2026-09-03 19:13:01`.

- Echantillons : 5075
- Temps API moyen : 140,1 ms
- CPU maximal : 28 %
- Conntrack maximal : 9944 sur 843776, soit moins de 1,2 %
- Pic WAN RX : 121609735 bit/s
- Pic WAN TX : 16819070 bit/s
- HTTPS disponible : 4943 echantillons sur 5075, soit environ 97,4 %
- Perte moyenne Cloudflare : 0,24 %
- Perte moyenne Google : 0,28 %
- Timeouts Hotspot : 1238
- Desattributions DHCP : 3878
- Drops de queues cumules : 49986001

## Interpretation

- Le CPU et Conntrack ne constituent pas un goulot d etranglement observe.
- Les drops de queues proviennent majoritairement du shaping et ne doivent pas etre classes automatiquement comme panne reseau.
- Les timeouts Hotspot et desattributions DHCP justifient une analyse distincte de la stabilite des acces en aval.
- Les 132 echecs HTTPS observes doivent etre correles aux pertes WAN, au DNS et aux changements DHCP avant conclusion.
- Le bail DHCP est de 30 minutes ; les desattributions de clients intermittents ne prouvent pas seules une panne.
- Le serveur Hotspot central utilise `keepalive-timeout=none`, mais les profils utilisateurs appliquent generalement encore un keepalive de 2 minutes.

## Mesure Courte Des Regles `Starlink-Shield`

Fenetre de 30 secondes realisee sans modification :

- blocage App Starlink : 0 paquet ;
- limitation TCP : 0 paquet ;
- blocage UDP global : 30 paquets, 21696 octets ;
- blocage mDNS/LLMNR WAN : 0 paquet ;
- normalisation TTL : 43345 paquets, 9462155 octets.

Le blocage UDP et la reecriture TTL sont donc actifs. Une observation par protocole est requise avant leur modification.

### Detail Du Trafic UDP Rejete

Une regle temporaire de journalisation limitee a ete placee avant le blocage pendant 30 secondes, puis supprimee et verifiee absente.

- Paquets observes : 288
- UDP/443 : 271 paquets, soit environ 94 % ; trafic QUIC/HTTPS moderne vers principalement Google, Meta, Cloudflare et Microsoft
- UDP/7649 : 13 paquets ; usage a identifier avant decision
- Ports UDP ephemeres : 3 paquets
- UDP/123 : 1 paquet NTP

Conclusion provisoire : le blocage UDP global rejette principalement du trafic web chiffre legitime. Il ne constitue pas une protection ciblee contre une fuite et peut forcer un repli vers TCP/TLS.

### Relecture Reelle Du 4 Septembre 2026

La configuration active de `10.10.10.1` a ete relue directement par l API RouterOS, sans mutation :

- quatre filtres `Starlink-Shield` sont actifs : acces App Starlink, limite TCP, limite UDP et mDNS/LLMNR WAN ;
- le filtre UDP totalise `2728` paquets et `2581500` octets rejetes ; le filtre mDNS/LLMNR totalise `1` paquet et `132` octets ; les deux autres filtres sont a zero ;
- la normalisation TTL WAN 64 est active et totalise `28328672` paquets et `4126302987` octets ;
- les redirections DNS locales UDP et TCP sont actives ; UDP totalise `3176` paquets et `186405` octets, TCP reste a zero ;
- quatre domaines Windows Update sont bloques par DNS `NXDOMAIN` ;
- `federatedml-pa.googleapis.com` est bloque comme telemetrie Google Federated ML, pas comme mise a jour Android ;
- aucun blocage Apple ou Android n est present dans les filtres, RAW, NAT, listes d adresses ou DNS statique.

Apres cette relecture, trois blocages DNS cibles ont ete actives et relus en `NXDOMAIN` : `mesu.apple.com` et `updates-http.cdn-apple.com` pour Apple, puis `update.googleapis.com` pour Android/Google Update. Les domaines partages Apple Push, iCloud, App Store, Google Play, `googleapis.com` et `gvt1.com` ne sont pas bloques globalement. La validation depuis des appareils clients reste a effectuer.

## Poste D Administration Confirme

- Nom DHCP : `Dell-Vostro`
- Adresse : `10.10.10.110`
- MAC : `7C:B2:7D:5E:05:38`
- Le bail DHCP a ete converti de dynamique a statique le 3 septembre 2026.
- Commentaire RouterOS : `Radius Manager - poste administration principal`

Cette reservation reste le poste principal, mais elle n est plus l unique voie d administration. Le 4 septembre 2026, SSH, HTTP et WinBox ont ete ouverts a `10.10.10.0/23` ; le rejet global de la liste `WAN` demeure actif.

## Durcissement MikroTik Applique

- Priorite confirmee : le MikroTik `10.10.10.1`, porteur du WAN, est traite avant le serveur `10.10.10.2`.
- Chaine `input` : acceptation `established,related,untracked`, rejet `invalid`, autorisation DHCP WAN UDP `67 -> 68`, puis rejet de tout autre trafic entrant depuis la liste WAN.
- SSH, HTTP et WinBox : `10.10.10.0/23` ; acces WAN bloque par la chaine `input`.
- MAC Server et MAC WinBox : `allowed-interface-list=LAN` ; la liste `LAN` contient `bridge1` et la liste `WAN` contient `ether1`.
- API et FTP : `10.10.10.2/32` uniquement ; FTP est provisoirement conserve pour le deploiement du portail.
- Telnet, API-SSL et serveur de bande passante : desactives.
- Apres application : bail WAN toujours lie, ping Internet `3/3`, perte `0 %`, reconnexion API depuis `10.10.10.2` reussie.
- Le pare-feu local de `10.10.10.2` a ete restaure dans son etat permissif et reste hors de cette etape prioritaire.
- Le 4 septembre 2026, `Starlink-Global-Limit` a ete conservee avec sa cible `10.10.10.0/23`, ses limites `40000000/15000000` et sa priorite `1/1`, puis deplacee de la position 32 a la position 0. Une nouvelle connexion Hotspot peut ensuite inserer visuellement sa file avant elle ; la garantie durable constatee est que les 16 profils limites utilisent tous `Starlink-Global-Limit` comme `parent-queue`.
- Etat confirme le 4 septembre 2026 : `hotspot1` est porte par `bridge1` ; le parent actif est `RM interface bridge1`, cible `bridge1`, plafond RouterOS `15000000/50000000`, priorite `1/1`, actif et valide. Les 16 profils limites referencent ce parent. Les 52 sessions alors actives ont ete deconnectees pour reconstruire leurs files ; la derniere verification compte 43/43 files dynamiques valides, toutes rattachees a `RM interface bridge1`, et aucune file invalide.
- Le controle est expose dans `traffic_monitoring.php` uniquement sur le bridge Hotspot detecte. Il permet d appliquer les debits et la priorite, puis d activer ou de desactiver la regle sans la supprimer. L etat relu affiche le nombre de profils parents et de files invalides.
- Le plafond global est fonctionnel ; l equite instantanee entre un client lourd et un client leger reste a confirmer avec au moins deux clients actifs en concurrence.

## Configuration De Confidentialite Existante

- DNS client force vers `10.10.10.1` en TCP/UDP 53.
- DNS amont Google et Starlink en clair ; aucun DoH actif.
- Adlist StevenBlack active : 78622 noms et 36837 correspondances lors de l audit.
- Serveur NTP MikroTik actif mais client NTP arrete.
- Aucun VLAN, WireGuard ou routage VPN.
- FastTrack absent.
- UPnP, proxy web et SOCKS desactives.

## Preparation AdGuard Home Du 4 Septembre 2026

- Paquet officiel stable AdGuard Home `v0.107.79` pour Linux AMD64 telecharge depuis la publication GitHub officielle.
- SHA-256 verifie : `c48f4a43000665484c5ec28177de11a004759b620dae8f77b2aabefc9ef3687f`.
- Binaire et fichiers de licence extraits dans `/opt/AdGuardHome`.
- Service `systemd` installe, actif et active au demarrage ; assistant initial joignable sur `http://10.10.10.2:3000`.
- Le port DNS `53` reste libre : AdGuard Home n assure encore aucune resolution cliente.
- Le DNS client reste force vers `10.10.10.1` ; aucune regle MikroTik, DHCP ou redirection DNS n a ete modifiee.

## Risques Prioritaires Confirmes

1. Regles `Starlink-Shield` globales susceptibles de casser UDP, QUIC, appels, jeux et mises a jour.
2. DNS amont non chiffre et filtrage DNS encore porte par le MikroTik.
3. Reseau aval partageant le meme domaine de couche 2 sans VLAN.
4. Serveur `10.10.10.2` sans filtrage entrant local et SSH root par mot de passe, explicitement hors de la priorite active centree sur le MikroTik.

Les acces WinBox/SSH/WebFig depuis le LAN et leur refus depuis le WAN ont ete valides le 4 septembre 2026. Le portail captif avec un nouveau client est egalement valide.

## Prochaines Verifications Avant Mutation

- Etablir la matrice de tests temoin par service et famille de systeme.
- Mesurer les paquets rejetes par chaque regle `Starlink-Shield` sur une fenetre representative.
- Identifier les sources des timeouts Hotspot et desattributions DHCP.
- Le test de retour arriere Safe Mode est abandonne par decision operateur et classe `NOT_APPLICABLE`, car il ne peut pas etre simule fidelement par l API.
- Executer la matrice `docs/NETWORK_PRIVACY_TEST_MATRIX.md` sur un groupe pilote.
