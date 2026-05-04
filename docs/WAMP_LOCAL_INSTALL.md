# Installation locale WAMP avec agent Windows

## Pre-requis

- Windows 10/11.
- WAMP installe avec Apache, PHP et MySQL actifs.
- Projet copie dans `c:\wamp64\www\radius-manager`.
- Executables client presents dans `bin\agent\` :
  - `activation-key.exe`
  - `backend-agent.exe`

`license-admin.exe` et `license-generator.exe` restent cote editeur/administrateur et ne doivent pas etre livres au client.

## Preparation base locale

1. Demarrer WAMP et verifier que les services Apache et MySQL sont verts.
2. Creer la base :

```sql
CREATE DATABASE IF NOT EXISTS radius_manager CHARACTER SET utf8 COLLATE utf8_general_ci;
```

3. Creer l utilisateur applicatif :

```sql
CREATE USER IF NOT EXISTS 'radius_app'@'localhost' IDENTIFIED BY 'CHANGE_ME_STRONG_PASSWORD';
ALTER USER 'radius_app'@'localhost' IDENTIFIED BY 'CHANGE_ME_STRONG_PASSWORD';
GRANT ALL PRIVILEGES ON radius_manager.* TO 'radius_app'@'localhost';
FLUSH PRIVILEGES;
```

4. Importer le schema projet dans `radius_manager`.
5. Configurer `config\db.json` avec `host`, `dbname`, `user`, `pass`.

## Build des executables

Sur la machine de build, installer Go puis generer une paire Ed25519 :

```powershell
cd c:\wamp64\www\radius-manager\tools\windows-agent
go run .\cmd\license-generator new-keypair
```

Conserver la cle privee uniquement cote editeur. Compiler les executables client avec la cle publique :

```powershell
$env:RM_AGENT_PUBLIC_KEY = "<PUBLIC_KEY_BASE64>"
.\build-windows.ps1
```

Les fichiers sont generes dans `bin\agent\`.

## Generation et activation licence

Cote editeur :

```powershell
$env:RM_LICENSE_PRIVATE_KEY = "<PRIVATE_KEY_BASE64>"
.\tools\editor-license\license-admin.exe
```

Alternative ligne de commande cote editeur :

```powershell
$env:RM_LICENSE_PRIVATE_KEY = "<PRIVATE_KEY_BASE64>"
.\tools\editor-license\license-generator.exe generate --customer CLIENT-001 --device-id MK-XXXX-XXXX-XXXX --nas-type mikrotik --edition standard --expires never --out client-license.json
```

Cote client WAMP :

```powershell
.\bin\agent\activation-key.exe activate --license .\client-license.json --app-dir c:\wamp64\www\radius-manager
.\bin\agent\activation-key.exe status --app-dir c:\wamp64\www\radius-manager
```

L etat local est ecrit dans `config\license\activation.json`.

## Verification agent

```powershell
.\bin\agent\backend-agent.exe check-license --device-id MK-XXXX-XXXX-XXXX --app-dir c:\wamp64\www\radius-manager
.\bin\agent\backend-agent.exe check-integrity --app-dir c:\wamp64\www\radius-manager
```

Pour activer le controle d integrite agent, creer `config\license\integrity.json` avec des empreintes SHA-256 :

```json
{
  "generated_at": "2026-05-04",
  "algorithm": "sha256",
  "files": {
    "includes/backend_agent.php": "sha256:<HASH>",
    "api/vouchers/apply_batch.php": "sha256:<HASH>"
  }
}
```

## Verification application

1. Ouvrir `http://localhost/radius-manager/`.
2. Se connecter a l administration.
3. Tester le device dans Network Devices pour obtenir le Device ID.
4. Activer la licence correspondant a ce Device ID.
5. Tester un lot voucher : l action doit passer par `backend-agent.exe`.

## Erreurs courantes

- `Backend agent indisponible` : `bin\agent\backend-agent.exe` absent ou non executable.
- `PUBLIC_KEY_MISSING` : executable compile sans cle publique et aucun `config\license\public_key.txt`.
- `LICENSE_NOT_ACTIVATED` : lancer `activation-key.exe activate`.
- `LICENSE_DEVICE_MISMATCH` : la licence ne correspond pas au Device ID du routeur.
- `LICENSE_EXPIRED` : generer une nouvelle licence.
- `INTEGRITY_MANIFEST_MISSING` : creer `config\license\integrity.json` avant d utiliser `check-integrity`.
- `Erreur DB 1045` : verifier `radius_app`@`localhost`, son mot de passe et les droits sur `radius_manager`.
