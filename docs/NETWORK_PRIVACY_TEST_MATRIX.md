# Matrice De Validation - Confidentialite Reseau

## Regle D Execution

Chaque ligne applicable doit etre testee avant et apres la politique concernee. Une phase ne passe pas en production tant que ses cas critiques applicables ne sont pas valides. Toute exception `NOT_APPLICABLE` doit etre justifiee explicitement.

Statuts : `PENDING`, `PASS`, `FAIL`, `NOT_APPLICABLE`.

Perimetre actif : MikroTik `10.10.10.1`. Les controles propres au serveur `10.10.10.2` restent reportes et ne bloquent pas la cloture des validations du routeur.

## Acces Critiques

| Cas | Source | Resultat attendu | Criticite | Statut |
|---|---|---|---|---|
| Administration web | Poste admin autorise | Application accessible | Critique | PENDING |
| SSH `10.10.10.2` | Poste admin autorise | Cle SSH acceptee | Critique | PENDING |
| WinBox/SSH/WebFig MikroTik | Poste du reseau interne | Acces maintenu sans dependance exclusive au Dell | Critique | PASS |
| WinBox/SSH/WebFig MikroTik | Source WAN | Acces refuse | Critique | PASS |
| WinBox/SSH/WebFig MikroTik | Client Hotspot | Aucun refus exige dans cette phase | Moyenne | NOT_APPLICABLE |
| MAC Server/MAC WinBox | WAN exclu, acces interne de secours conserve sur la liste `LAN` | Critique | PASS |
| Portail captif | Client neuf | Authentification fonctionnelle | Critique | PASS |
| Retour arriere | Poste admin autorise | Test abandonné : Safe Mode non simulable fidèlement par API | Critique | NOT_APPLICABLE |

## Securite DNS MikroTik

| Cas | Action | Resultat attendu | Criticite | Statut |
|---|---|---|---|---|
| DNS client UDP/TCP 53 | Requete client | Reponse fournie par le MikroTik selectionne | Critique | PENDING - chemin en clair, relecture requise |
| Ancien pilote DNS Dell `10.10.10.218` | Verification des regles UDP/TCP 53 | Aucune redirection active vers `10.10.10.2` | Critique | PENDING - pilote abandonne, etat RouterOS a relire |
| DNS externe direct | Requete vers un resolver public | Redirection ou refus journalise | Haute | PENDING |
| DNSSEC valide | Domaine signe via MikroTik | Resolution reussie | Haute | PENDING |
| DNSSEC invalide | Domaine de test invalide via MikroTik | Rejet attendu | Haute | PENDING |
| DNS amont chiffre | Relecture puis test DoH RouterOS avec certificat valide | Transport chiffre et stable confirme sur MikroTik | Haute | PENDING - ne pas deduire cet etat du DoT de l ancien pilote AdGuard |
| Domaine bloque | Domaine de liste | Reponse de blocage et compteur incremente | Haute | PENDING |
| Liste blanche | Domaine explicitement autorise | Resolution normale | Haute | PENDING |
| DoT/DoQ/DoH connu | Client pilote | Detection avant decision de blocage | Haute | PENDING |
| Panne de l amont DoH MikroTik | Amont indisponible pendant le pilote | Alerte et restauration explicite fonctionnelles | Critique | PENDING |
| Portail Hotspot | Avant et apres authentification | DNS MikroTik et redirection du portail fonctionnels | Critique | PENDING apres configuration DoH |

## Mises A Jour

| Famille | Cas | Resultat attendu | Criticite | Statut |
|---|---|---|---|---|
| Windows | Windows Update | Autorisation, planification ou blocage selon politique | Haute | PENDING |
| Windows | Microsoft Store | Politique independante de Windows Update | Moyenne | PENDING |
| Windows | Delivery Optimization | Detection des pairs et controle du trafic | Haute | PENDING |
| Linux | `apt` Debian/Ubuntu | Index et paquets telechargeables dans la plage autorisee | Haute | PENDING |
| Linux | Autres gestionnaires observes | Domaine classe avant filtrage | Moyenne | PENDING |
| Apple | Software Update | Controle independant de l App Store | Haute | PENDING |
| Apple | App Store | Autorisation ou blocage explicite | Moyenne | PENDING |
| Android | Google Play | Controle independant des mises a jour OTA | Haute | PENDING |
| Android | OTA constructeur | Domaine associe a l appareil avant action | Haute | PENDING |
| Toutes | Exception urgente | Deblocage immediat et historise | Critique | PENDING |

## Protocoles Et Applications

| Cas | Resultat attendu | Criticite | Statut |
|---|---|---|---|
| Navigation HTTPS | Sites usuels accessibles | Critique | PENDING |
| HTTP explicite | Observation et politique par appareil, pas de blocage global | Moyenne | PENDING |
| QUIC/HTTP3 | Fonctionnement mesure avant retrait du blocage UDP | Haute | PENDING |
| WebRTC/STUN | Flux detecte et attribue au client pilote | Haute | PENDING |
| Appel audio/video | Fonctionnel sauf politique ciblee confirmee | Haute | PENDING |
| Streaming | Lecture stable et debit conforme | Haute | PENDING |
| Jeux UDP | Connexion et latence mesurees | Moyenne | PENDING |
| NTP client | Synchronisation via le service local | Haute | PENDING |
| VPN pilote futur | Tunnel, DNS, kill switch et MTU verifies | Haute | PENDING |
| User-Agent HTTPS | Aucune promesse de reecriture par le MikroTik ; politique terminal documentee | Haute | PENDING |
| ICMP fragmentation/path MTU | Gros flux et tunnel fonctionnels sans trou noir MTU | Critique | PENDING |
| ICMP WAN non sollicite | Reponse conforme a la politique par type, sans exposition de service | Haute | PENDING |
| TTL force a 64 | Benefice mesure ou regle retiree | Moyenne | PENDING |

## Isolation Et Services Exposes

| Cas | Resultat attendu | Criticite | Statut |
|---|---|---|---|
| Client vers administration | Refus hors poste admin | Critique | PENDING |
| IoT vers infrastructure | Refus par defaut | Critique | PENDING |
| IoT vers Internet | Services explicitement autorises seulement | Haute | PENDING |
| Appareil vers service cloud | Domaine visible, classable et filtrable | Haute | PENDING |
| Service local expose | Detecte comme port local, jamais confondu avec DNS | Haute | PENDING |
| Quarantaine | DNS/administration de remediation seulement | Haute | PENDING |
| Communication entre clients | Conforme a la politique du site pilote | Haute | PENDING |
| Test externe reel | Aucun service RouterOS non autorise visible depuis Internet | Critique | PENDING |
| Cloud IoT partage | Aucun blocage sans attribution domaine/appareil confirmee | Haute | PENDING |

## Stabilite Et Capacite

| Mesure | Seuil de validation | Statut |
|---|---|---|
| CPU MikroTik | Pas de hausse durable superieure a 15 points | PENDING |
| Conntrack | Inferieur a 80 % de capacite | PENDING |
| CPU/RAM MikroTik avec DoH | Marge stable apres activation pilote | PENDING |
| DNS | Latence et erreurs comparees a la baseline | PENDING |
| HTTPS | Disponibilite au moins egale a la baseline | PENDING |
| Hotspot | Pas de hausse des timeouts | PENDING |
| DHCP | Pas de hausse des desattributions anormales | PENDING |
| Queues | Drops interpretes comme shaping, pas automatiquement comme panne | PENDING |
| Simple Queue globale | Les 16 profils Hotspot limites utilisent `RM interface bridge1` comme parent | PASS |
| Controle du bridge Hotspot | `bridge1` detecte depuis `hotspot1`, presselectionne et seul bridge autorise pour la regle | PASS |
| Parent bridge avec enfants IP | 43/43 files dynamiques valides sous `RM interface bridge1`, aucune file invalide | PASS |
| Reconstruction apres changement de parent | 52 sessions deconnectees puis files recreees avec le nouveau parent et `invalid=false` | PASS |
| Interface de gestion | Champs standardises ; boutons de meme largeur `Appliquer` et `Activer`/`Desactiver` ; aucun retrait de la regle | PASS |
| Equite sous congestion | Un client lourd cede de la capacite lorsqu un autre client devient actif, sans penalite historique | PENDING |
| WireGuard futur | Comparatif debit, latence, MTU et fuite DNS avec/sans tunnel | PENDING |
