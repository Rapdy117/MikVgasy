# Ce dossier est réservé à l'éditeur

**Ne jamais copier ce dossier dans une installation client.**

## Contenu

| Fichier | Rôle |
|---|---|
| `license-generator.exe` | Signe les licences (Ed25519) |
| `license-admin.exe` | Ancienne interface web locale (remplacée) |
| `private_key.txt` | **Clé privée Ed25519 — CONFIDENTIELLE** |

## Clé privée

Coller la clé privée dans `private_key.txt` (une seule ligne, base64).  
Ce fichier est dans `.gitignore` — il ne sera jamais commité.

La clé publique correspondante est dans `config/license/public_key.txt`.

## Déploiement client

Exclure ce dossier entier du package client :

```
tools/editor-license/
```
