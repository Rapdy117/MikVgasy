# Runbook migration VPS — Radius Manager

Date : 2026-07-22
Statut : préparation locale terminée, validation VPS à exécuter

## 1. Concept du projet

Radius Manager est une application multi-tenant qui sépare les responsabilités :

```text
profil commercial
    ↓
utilisateur métier
    ↓
projection technique vers le backend
    ↓
observation des sessions et consommations
    ↓
historique, notifications et recouvrement
```

Les comptes d’administration sont hiérarchisés :

- `superadmin` : accès global ;
- `admin` : ses Devices, ses revendeurs et leurs données ;
- `reseller` : ses utilisateurs hotspot, vouchers, recharges, ventes et historiques.

Les Devices sont stockés en SQL (`network_devices`, `admin_device_assignments`). Un admin peut posséder plusieurs routeurs. Chaque opération technique reçoit un `device_id` explicite et vérifie l’autorisation avant toute connexion.

### Sources de vérité

- MikroTik : utilisateurs, profils, limites et état technique sur le routeur ciblé ;
- RADIUS/SQL : profils, utilisateurs, crédits et projections RADIUS ;
- `radacct` : observation et historique uniquement ;
- SQL local : propriétaires, opérateurs, vouchers, recharges, ventes, licences et journaux.

OPNsense reste en pause pendant cette migration ; il ne doit pas créer un second cycle commercial.

## 2. Paquet préparé

Le paquet local est généré avec :

```bash
php tools/prepare_vps_migration.php /var/backups/radius-manager/migrations
```

Il contient :

- export SQL complet ;
- code runtime et fichiers du portail ;
- configuration RADIUS ;
- configuration DB chiffrée ;
- migrations SQL ;
- `install_vps.sh` ;
- plan de tests et manifeste.

Ne jamais placer ce paquet dans une racine web publique.

## 3. Tâches à exécuter sur le VPS

### Phase A — Préparation serveur

- [ ] Installer Debian/Ubuntu supporté.
- [ ] Installer Apache, PHP 8.2, extensions PHP, ZIP, MariaDB client et FreeRADIUS.
- [ ] Configurer le nom d’hôte, le pare-feu, SSH et HTTPS.
- [ ] Créer la base et un compte SQL applicatif sans privilèges superflus.
- [ ] Exécuter `tools/migrations/20260726_radius_app_auth.sql` après remplacement de `CHANGE_ME` par le mot de passe de `config/db.json`.
- [ ] Définir les secrets dans l’environnement VPS.
- [ ] Ne pas copier les fichiers `.old`, backups ou archives inutiles.

Commande de base :

```bash
PHP_VERSION=8.2 APP_ROOT=/var/www/html sudo bash tools/install_vps.sh
```

### Phase B — Restauration contrôlée

- [ ] Copier le paquet hors de la racine web.
- [ ] Sauvegarder l’état initial du VPS.
- [ ] Restaurer le code et les fichiers `uploads`.
- [ ] Importer `radius_manager.sql`.
- [ ] Vérifier les migrations et les tables `network_devices`, `admin_device_assignments`, `device_licenses`.
- [ ] Vérifier `config/db.json`, `config/radius.json` et les permissions Apache.
- [ ] Créer ou promouvoir explicitement le compte superadmin.

### Phase C — RADIUS

- [ ] Vérifier le module SQL FreeRADIUS.
- [ ] Vérifier la connexion FreeRADIUS à la base.
- [ ] Tester authentification, profil, limite de temps et limite de données.
- [ ] Tester une recharge et sa projection RADIUS.
- [ ] Vérifier `radacct` après une session réelle.
- [ ] Vérifier que `radacct` n’écrase jamais les quotas métier SQL.

### Phase D — Devices et isolation

- [ ] Vérifier que chaque Device possède un `owner_admin_id` valide.
- [ ] Vérifier les noms MikroTik Cloud DDNS utilisés dans `network_devices.host`.
- [ ] Tester la résolution DNS DDNS depuis `10.10.10.2` et depuis le VPS via WireGuard.
- [ ] Vérifier que chaque routeur DDNS utilise le bon port API et que le firewall/NAT l’autorise.
- [ ] Préférer l’adresse LAN lorsqu’un routeur est local afin d’éviter un problème de hairpin NAT ; conserver le DDNS pour les routeurs distants.
- [ ] Ne jamais remplacer automatiquement un hostname DDNS par une autre adresse : le Device SQL reste la source de vérité.
- [ ] Attribuer les Devices initiaux au superadmin.
- [ ] Attribuer ensuite les Devices aux admins concernés.
- [ ] Tester changement de Device dans la Navbar.
- [ ] Tester admin vers Device étranger : `403`.
- [ ] Tester reseller vers périmètre étranger : `403` ou aucune ligne.
- [ ] Tester Device inexistant ou désactivé.

### Phase E — Tests métier

- [ ] Créer, modifier et supprimer un utilisateur.
- [ ] Tester expiration automatique RouterOS 6 et 7.
- [ ] Tester recharge DATA seule.
- [ ] Tester recharge temps seule.
- [ ] Tester recharge DATA + temps.
- [ ] Tester profil MikroTik écrit uniquement sur le Device ciblé.
- [ ] Générer, utiliser, filtrer et supprimer des vouchers.
- [ ] Vérifier purge au-delà de 5000 vouchers.
- [ ] Créer, activer, expirer et renouveler une licence.
- [ ] Tester rapports, dashboard et recouvrement par admin/reseller.

### Phase F — Concurrence VPS

- [ ] Connecter deux comptes simultanément.
- [ ] Sélectionner deux Devices MikroTik différents.
- [ ] Effectuer deux opérations simultanées.
- [ ] Vérifier absence d’interférence de session, cache, journal et historique.
- [ ] Vérifier le `device_id` dans chaque action.

Cette phase ne peut pas être validée localement avec un seul routeur.

### Phase G — Sauvegarde et exploitation

- [ ] Installer le cron de sauvegarde sensible.
- [ ] Vérifier SQL sensible et configuration RADIUS sauvegardés.
- [ ] Tester une restauration dans un répertoire isolé.
- [ ] Vérifier logs Apache, PHP, FreeRADIUS et cron.
- [ ] Configurer rotation et rétention des sauvegardes.
- [ ] Effectuer la bascule DNS ou IP uniquement après validation complète.

## 4. Critères de mise en production

La migration est acceptée uniquement si :

- aucune API métier ne choisit un backend par Device actif implicite ;
- les réponses `403`, `409` et `422` sont correctes ;
- les données de deux admins restent isolées ;
- les historiques sont conservés après désactivation ;
- RADIUS authentifie et comptabilise correctement ;
- deux routeurs peuvent fonctionner simultanément sans interférence ;
- une sauvegarde et une restauration ont été testées ;
- le plan `docs/TEST_PLAN_MULTI_TENANT.md` est entièrement renseigné.

## 5. Retour arrière

En cas d’échec :

1. arrêter les écritures métier ;
2. conserver les logs et la sauvegarde VPS ;
3. restaurer la dernière base validée ;
4. remettre l’ancien service local si nécessaire ;
5. documenter la cause avant une nouvelle tentative.

## Contrainte VPS partagé

Le VPS `gamecoza` est partagé avec d’autres utilisateurs. Les règles suivantes sont obligatoires :

- ne pas modifier le mot de passe root ou les comptes existants ;
- ne pas désactiver SSH, OpenVPN ou une fonction système existante ;
- ne pas remplacer les règles firewall existantes ;
- ne pas réutiliser le réseau OpenVPN `10.8.0.0/24` ;
- utiliser une interface WireGuard dédiée (`wg-radius`) et un port propre ;
- conserver une procédure d’arrêt/suppression réversible ;
- ne stocker aucune donnée SQL, clé applicative ou secret MikroTik sur ce VPS partagé ;
- obtenir l’accord du propriétaire du VPS avant toute modification réseau.

Ce VPS peut servir de relais VPN de test, mais il ne doit pas héberger la production Radius Manager tant que l’isolation et la propriété des secrets ne sont pas garanties.
