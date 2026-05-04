# Migration Windows locale vers backend agent

## Objectif

Durcir le deploiement local type Mikhmon sous Windows, sans serveur global, en sortant progressivement les decisions sensibles et les ecritures routeur du code PHP.

L objectif initial est de livrer un socle Windows local compose de quatre elements :

1. `license-admin.exe` + `license-generator.exe` pour generer les licences hors poste client, cote editeur uniquement.
2. `activation-key.exe` pour introduire et valider la cle d activation sur le poste client.
3. `backend-agent.exe` pour gerer les operations backend sensibles.
4. Un guide d installation locale sur WAMP pour deployer Radius Manager proprement.

## Constat

- Un controle de licence en PHP protege l usage normal, mais reste contournable si le code PHP est modifiable.
- Un agent IA ou un utilisateur malveillant avec acces fichier peut modifier les API PHP pour eviter un controle local.
- La securite doit donc etre placee sur le chemin obligatoire des actions sensibles, pas seulement dans l UI.

## Cible

PHP devient principalement l interface locale.

Trois executables separent clairement les responsabilites sensibles :

- `license-admin.exe` et `license-generator.exe` restent cote editeur/administrateur et ne doivent jamais etre livres avec le poste client standard.
- `activation-key.exe` est livre au client pour enregistrer la cle d activation, verifier la signature et ecrire l etat local autorise.
- `backend-agent.exe` devient le point obligatoire pour les actions backend sensibles.

`backend-agent.exe` devient le point obligatoire pour :

- verifier la licence routeur ;
- verifier l integrite des fichiers critiques ;
- dechiffrer les secrets routeur ;
- executer les ecritures MikroTik ;
- autoriser ou refuser les actions sensibles.

## Regles de securite

- La cle privee de generation de licence ne doit jamais etre livree au client.
- Le client contient seulement une cle publique de verification.
- `license-admin.exe`, `license-generator.exe` et la cle privee editeur ne doivent pas etre presents dans l installation WAMP client.
- `activation-key.exe` ne doit pas pouvoir generer une licence ; il valide uniquement une cle signee.
- Les secrets routeur ne doivent plus etre exploitables directement par PHP.
- Toute ecriture MikroTik doit passer par `backend-agent.exe`.
- Toute voie PHP concurrente d ecriture routeur doit etre supprimee apres migration.
- Pas de fallback PHP si l agent refuse ou est indisponible.

## Phases de migration

### Phase 0 - Socle Windows local

- Definir le format de licence signe :
  - identifiant client ;
  - identifiant routeur/NAS autorise ;
  - date d expiration si applicable ;
  - edition/fonctionnalites autorisees ;
  - signature.
- Creer `license-admin.exe` et `license-generator.exe` pour generer une cle signee hors poste client.
- Creer `activation-key.exe` pour installer la cle sur le poste WAMP local.
- Creer `backend-agent.exe` comme executable de protection backend.
- Rediger le guide d installation locale WAMP.
- Ne pas connecter PHP aux nouveaux executables tant que le format de licence n est pas stabilise.

### Phase 1 - Activation locale et agent gardien

- Ajouter les commandes :
  - `activate-license`
  - `check-license`
  - `check-integrity`
  - `authorize-action`
- PHP lit uniquement l etat d activation valide, sans generer ni modifier la licence.
- PHP appelle l agent avant les actions sensibles.
- Corriger les contournements actuels connus, notamment le flux vouchers MikroTik.

### Phase 2 - Ecritures MikroTik dans l agent

- Deplacer progressivement dans l agent :
  - creation utilisateur ;
  - mise a jour utilisateur ;
  - suppression utilisateur ;
  - creation profil ;
  - mise a jour profil ;
  - suppression profil ;
  - generation/application vouchers ;
  - rechargement utilisateur.
- PHP ne doit plus appeler directement les fonctions d ecriture MikroTik.

### Phase 3 - Secrets routeur hors PHP

- Migrer les secrets routeur vers un stockage chiffre lisible par l agent.
- PHP conserve seulement les metadonnees d affichage.
- L agent dechiffre et utilise les identifiants routeur.

### Phase 4 - Integrite renforcee

- Ajouter aux fichiers proteges :
  - `includes/user_provisioning.php`
  - `includes/vouchers.php`
  - `api/vouchers/apply_batch.php`
  - `includes/mikrotik_backend.php`
  - `includes/nas_resolver.php`
  - `includes/license.php`
- Regenerer le manifeste uniquement apres stabilisation.
- L agent doit verifier les signatures avant toute action sensible.

### Phase 5 - Nettoyage PHP

- Supprimer les anciens chemins PHP d ecriture routeur.
- Supprimer les fallbacks de compatibilite.
- Garder une seule source de verite par flux.
- Documenter chaque suppression dans `docs/DECISIONS.md` ou `docs/PROJECT_PLAN.md` si necessaire.

## Commandes agent envisagees

```text
license-admin.exe --listen 127.0.0.1:8780
license-generator.exe generate --customer <id> --device-id <id> --nas-type <type> --edition <name> --expires <yyyy-mm-dd|never> --out <file>

activation-key.exe activate --license <file-or-key> --app-dir <path>
activation-key.exe status --app-dir <path>

backend-agent.exe activate-license --key <license-key> --app-dir <path>
backend-agent.exe check-license --device-id <id> --app-dir <path>
backend-agent.exe check-integrity --app-dir <path>
backend-agent.exe authorize-action --action <name> --device-id <id> --payload <json> --app-dir <path>
backend-agent.exe mikrotik-test-device --device-id <id>
backend-agent.exe mikrotik-create-user --payload <json>
backend-agent.exe mikrotik-update-user --payload <json>
backend-agent.exe mikrotik-delete-user --payload <json>
backend-agent.exe mikrotik-create-profile --payload <json>
backend-agent.exe mikrotik-update-profile --payload <json>
backend-agent.exe mikrotik-delete-profile --payload <json>
backend-agent.exe voucher-apply-batch --payload <json>
backend-agent.exe recharge-apply --payload <json>
```

## Implementation V1 retenue

- Sources Go dans `tools/windows-agent`.
- Executables client generes dans `bin/agent` : `activation-key.exe`, `backend-agent.exe`.
- Executables editeur non livrables generes dans `tools/editor-license` : `license-admin.exe`, `license-generator.exe`.
- Etat d activation dans `config/license/activation.json`.
- Manifeste d integrite agent dans `config/license/integrity.json`.
- Wrapper PHP unique dans `includes/backend_agent.php`.
- Premier flux sensible branche : `api/vouchers/apply_batch.php`.
- Controle licence runtime branche via `requireDeviceLicensed()` et `backend-agent.exe`.
- Activation UI conservee comme facade PHP, mais validation executee par `activation-key.exe`.
- Autorisation agent branchee sur les ecritures sensibles :
  - creation / mise a jour / suppression utilisateur ;
  - mise a jour / suppression utilisateur MikroTik ;
  - creation / mise a jour / suppression profil ;
  - recharge ;
  - application de lots vouchers.
- `backend-agent.exe authorize-action` refuse les actions hors allowlist.
- Anciens generateurs/signatures PHP archives dans `archive/replaced-by-agent-2026-05-04`.
- Guide WAMP detaille : `docs/WAMP_LOCAL_INSTALL.md`.
- Reponse JSON commune : `{ "success": bool, "code": string, "message": string, "data": object }`.

## Etat de migration backend

V1 actuelle :

- PHP garde les facades HTTP, session, CSRF, formulaires et lectures UI.
- `backend-agent.exe` devient le garde obligatoire avant les ecritures sensibles.
- Les endpoints proteges sont inscrits dans `config/license/integrity.json`.
- Si un endpoint protege est modifie, `backend-agent.exe check-integrity` bloque l action.

Etape suivante :

- Deplacer progressivement l execution technique depuis PHP vers `backend-agent.exe` :
  - ecritures MikroTik utilisateur ;
  - ecritures MikroTik profil ;
  - recharge MikroTik ;
  - secrets device hors PHP.

## Guide WAMP attendu

Le guide d installation locale doit couvrir :

1. Installer WAMP et verifier Apache, PHP, MySQL.
2. Copier le projet dans `c:\wamp64\www\radius-manager`.
3. Creer la base `radius_manager` et importer le schema.
4. Creer l utilisateur MySQL applicatif et ses droits locaux.
5. Configurer `config/db.json` sans exposer le mot de passe en clair si le chiffrement est disponible.
6. Placer `activation-key.exe` et `backend-agent.exe` hors `docs/`, dans un repertoire applicatif stable.
7. Activer la licence avec `activation-key.exe`.
8. Verifier l acces local via WAMP.
9. Verifier les backends MikroTik, OPNsense et RADIUS selon les equipements installes.
10. Documenter les erreurs courantes : port Apache occupe, service MySQL arrete, utilisateur DB manquant, licence invalide.

Le guide operationnel est documente dans `docs/WAMP_LOCAL_INSTALL.md`.

## Priorite immediate

1. Stabiliser le format de licence signe.
2. Produire `license-admin.exe` et `license-generator.exe` hors package client.
3. Produire `activation-key.exe`.
4. Produire `backend-agent.exe` avec `check-license`, `check-integrity` et `authorize-action`.
5. Rediger et valider le guide d installation locale WAMP.
6. Bloquer le contournement voucher MikroTik.
7. Forcer `generate.php` a passer par la resolution licence canonique.
8. Identifier tous les appels directs a `connectToMikrotikApiByDevice`.
9. Classer les appels en lecture, monitoring, ecriture.
10. Migrer d abord les ecritures.

## Limites acceptees

- Sans serveur global, un administrateur Windows avec controle total de la machine peut toujours tenter de remplacer l agent.
- Le but est de bloquer les contournements simples par modification PHP.
- La robustesse vient de la combinaison : agent compile, permissions Windows, secrets hors PHP, signatures et absence de fallback.
