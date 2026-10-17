# Radius Manager

Application web d'administration hotspot et réseau multi-backend, pour MikroTik RouterOS, FreeRADIUS et OPNsense.

## Fonctionnalités

- **Hotspot MikroTik** : utilisateurs, profils, vouchers, recharges (changement d'offre, rechargement, réabonnement), sessions actives.
- **Multi-WAN (N-WAN)** : répartition PCC entre plusieurs liens WAN, bascule en mode dégradé sur panne, cibles de consommation journalière/mensuelle par lien.
- **Supervision trafic** : bande passante live, consommation par utilisateur et par Kit WAN, filtrage DNS (adlists, blocklists), historique des connexions.
- **Journal utilisateurs** : archive locale des logs Hotspot (le buffer RouterOS natif est volatile et se réécrit en quelques heures), reconstruction de sessions pour les comptes sans RADIUS, recherche et pagination adaptées au mobile.
- **Recouvrement & facturation** : suivi des recharges et ventes de vouchers, génération de factures.
- **Multi-tenant** : rôles `superadmin` / `admin` / `reseller`, chaque admin gérant ses propres équipements et données.
- **OPNsense** : intégration en pause (voir `docs/DECISIONS.md`).
- **PWA** : installable sur mobile (manifest + service worker), pages lourdes allégées automatiquement sur mobile.

## Stack technique

- PHP 8.2, Apache (mod_php), MySQL/MariaDB.
- FreeRADIUS (authentification/comptabilité RADIUS).
- API RouterOS (connexion directe aux routeurs MikroTik).
- Frontend : Bootstrap 5, JavaScript natif (pas de framework SPA), Chart.js pour les graphes.

## Installation

Environnement cible : Linux (Debian/Ubuntu) avec Apache, PHP 8.2 et ses extensions, MariaDB, FreeRADIUS.

```bash
PHP_VERSION=8.2 APP_ROOT=/var/www/html sudo bash tools/install_vps.sh
```

Voir `docs/VPS_MIGRATION_RUNBOOK.md` pour le déroulé complet (préparation serveur, restauration, vérification RADIUS/devices, tests).

## Configuration sensible

Avant toute utilisation, renseigner les fichiers suivants (non fournis avec des valeurs réelles dans ce dépôt) :

- `config/db.json`, `config/db.php`
- `config/radius.json`, `config/radius.php`

## Documentation

Point d'entrée : `docs/PROJECT_INDEX_QUICK.md`, puis `docs/project_rules.md` (règles métier et architecture, prioritaires sur tout le reste), `docs/ARCHITECTURE.md`, `docs/DECISIONS.md`, `docs/KNOWN_ISSUES.md`, `docs/PROJECT_PLAN.md`.
