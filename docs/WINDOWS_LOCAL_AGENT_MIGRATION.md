# Migration Windows locale vers backend agent

## Objectif

Durcir le deploiement local type Mikhmon sous Windows, sans serveur global, en sortant progressivement les decisions sensibles et les ecritures routeur du code PHP.

## Constat

- Un controle de licence en PHP protege l usage normal, mais reste contournable si le code PHP est modifiable.
- Un agent IA ou un utilisateur malveillant avec acces fichier peut modifier les API PHP pour eviter un controle local.
- La securite doit donc etre placee sur le chemin obligatoire des actions sensibles, pas seulement dans l UI.

## Cible

PHP devient principalement l interface locale.

Un `backend-agent.exe` devient le point obligatoire pour :

- verifier la licence routeur ;
- verifier l integrite des fichiers critiques ;
- dechiffrer les secrets routeur ;
- executer les ecritures MikroTik ;
- autoriser ou refuser les actions sensibles.

## Regles de securite

- La cle privee de generation de licence ne doit jamais etre livree au client.
- Le client contient seulement une cle publique de verification.
- Les secrets routeur ne doivent plus etre exploitables directement par PHP.
- Toute ecriture MikroTik doit passer par `backend-agent.exe`.
- Toute voie PHP concurrente d ecriture routeur doit etre supprimee apres migration.
- Pas de fallback PHP si l agent refuse ou est indisponible.

## Phases de migration

### Phase 1 - Agent gardien

- Creer `backend-agent.exe`.
- Ajouter les commandes :
  - `check-license`
  - `check-integrity`
  - `authorize-action`
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
backend-agent.exe check-license --device-id <id>
backend-agent.exe check-integrity
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

## Priorite immediate

1. Bloquer le contournement voucher MikroTik.
2. Forcer `generate.php` a passer par la resolution licence canonique.
3. Identifier tous les appels directs a `connectToMikrotikApiByDevice`.
4. Classer les appels en lecture, monitoring, ecriture.
5. Migrer d abord les ecritures.

## Limites acceptees

- Sans serveur global, un administrateur Windows avec controle total de la machine peut toujours tenter de remplacer l agent.
- Le but est de bloquer les contournements simples par modification PHP.
- La robustesse vient de la combinaison : agent compile, permissions Windows, secrets hors PHP, signatures et absence de fallback.

