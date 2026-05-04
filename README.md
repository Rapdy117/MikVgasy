# Radius Manager

Radius Manager est une application locale de gestion hotspot et réseau pour WAMP, orientée MikroTik, RADIUS et OPNsense.

Le projet vise une architecture locale plus sûre : l’interface PHP reste l’interface utilisateur, tandis que les actions sensibles sont progressivement déplacées vers un agent Windows compilé.

## Fonctionnalités

- Gestion des équipements réseau MikroTik, RADIUS et OPNsense.
- Gestion des profils, utilisateurs, vouchers et recharges.
- Tableaux de bord, sessions, trafic, logs et rapports commerciaux.
- Recouvrement, historiques et génération de factures.
- Activation par licence signée avec vérification locale.
- Agent Windows pour protéger les opérations backend sensibles.

## Architecture Sécurité

La migration locale Windows introduit trois rôles séparés :

- `activation-key.exe` : activation d’une licence signée côté client.
- `backend-agent.exe` : contrôle licence, intégrité et autorisation des actions sensibles.
- `license-admin.exe` / `license-generator.exe` : outils éditeur uniquement, non livrables au client.

Les fichiers de configuration réels, clés privées, secrets, états d’activation et historiques locaux ne sont pas versionnés.

## Installation Locale

Environnement cible :

- Windows + WAMP
- Apache / PHP
- MySQL ou MariaDB
- Go uniquement pour recompiler les agents Windows

Guide détaillé :

- `docs/WAMP_LOCAL_INSTALL.md`
- `docs/WINDOWS_LOCAL_AGENT_MIGRATION.md`

## Configuration Sensible

Avant utilisation locale, créer les fichiers privés nécessaires hors Git :

- `config/db.php`
- `config/db.json`
- `config/radius.json`
- `config/opnsense.json`
- `config/license/app_secret.txt`
- `config/license/public_key.txt`
- `config/license/activation.json`

Le secret applicatif peut aussi être fourni par la variable d’environnement `RM_APP_SECRET`.

## Agents Windows

Sources Go :

```text
tools/windows-agent/
```

Exécutables client attendus :

```text
bin/agent/activation-key.exe
bin/agent/backend-agent.exe
```

Les outils éditeur de génération de licence ne doivent pas être inclus dans une installation client.

## Statut

Projet en migration active vers une séparation stricte :

```text
UI PHP → wrapper agent → backend-agent.exe → action sensible
```

Sans agent disponible, licence valide et intégrité conforme, les actions protégées doivent être refusées.

