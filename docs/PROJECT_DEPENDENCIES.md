# Dependances du projet

Mise a jour : 2026-07-22

## Sources runtime

- PHP : pages, API et includes hors `docs/`.
- SQL : `network_devices`, `admin_device_assignments`, comptes locaux et objets commerciaux.
- RouterOS : profils, utilisateurs et sessions MikroTik.
- FreeRADIUS : projections `radcheck`, `radreply`, `radusergroup` et observations `radacct`.
- OPNsense : branche API en pause.

## Fichiers sensibles

- `includes/device_manager.php` : chargement SQL des Devices et contrôle de périmètre.
- `includes/tenancy.php` : autorisations multi-tenant.
- `includes/recharge_preview_service.php` : contrat preview/apply.
- `includes/mikrotik_backend.php` : connexion explicite par Device.
- `includes/vouchers.php` et `includes/voucher_batch_service.php` : registre SQL des vouchers.

## Archives hors runtime

- `config/opnsense.json.old`
- `api/test_opnsense.php.old`
- `includes/get_radius.php.old`
- fichiers `*.old` dans `tools/` et `includes/`

Aucun fichier archive ne doit etre inclus ou execute.

## Points a verifier avant VPS

- [x] fonction morte `deviceConfigFilePath()` supprimée après vérification des callers ;
- documenter ou retirer les alias `opnsense_*` ;
- verifier les backups et doublons ;
- maintenir une seule migration SQL par responsabilite.


## Licences et maintenance

- `tools/migrations/009_device_licenses.sql` et `includes/license.php` : registre SQL des licences.
- `tools/migrations/009_system_maintenance.sql`, `api/admin/database_maintenance.php` et `tools/backup_sensitive.php` : maintenance et sauvegarde hors web.
- `tools/install_vps.sh` installe `/etc/cron.d/radius-manager-sensitive-backup`.
- `includes/recouvrement_invoices.php` centralise les scopes owner/Device/operator.
- `pages/users_list.php` et `api/users/update_user.php` portent le contrat d expiration date-only.


## Inventaire P0 des archives et doublons — 2026-07-22

### Archives `.old` hors runtime

Les fichiers suivants sont conserves uniquement pour contrôle/nettoyage final et ne doivent jamais être inclus :

- `api/test_opnsense.php.old` ;
- `config/opnsense.json.old` ;
- `includes/get_radius.php.old`, `local_admins.php.old`, `radius_standard_io.php.old`, `radius_sync.php.old`, `tenancy.php.old` ;
- `pages/administration.php.old` ;
- `scripts/sync_mikrotik_user_snapshot.php.old` ;
- `tools/encrypt_configs.php.old`, `generate_license.php.old`, `install.php.old`, `license_manager.php.old`, `migrate_devices.php.old` ;
- `tools/migrations/001_multi_tenant_foundation.sql.old`.

### Archives à ne pas renommer

- `uploads/portal_captive/templates/default_mikrotik.zip.bak` est un modèle portail référencé par le runtime ; il reste une dépendance fonctionnelle et doit être remplacé par un ZIP valide avant suppression.
- `project-backup-20260330-165851.zip` et `Backup 14/07.rar` sont des sauvegardes globales hors runtime ; elles doivent être déplacées hors de la racine web lors du nettoyage VPS, après vérification de restauration.
- `docs/archive/` est une référence historique et ne doit jamais être chargée par PHP.

### Normalisation effectuée

- `docs/DECISIONS.md.orig` a été renommé `docs/DECISIONS.md.old`; aucune référence runtime n a été trouvée.

### Règle de décision

Aucun fichier n est supprimé dans ce chantier. Un fichier encore appelé par le runtime ne peut pas être renommé ; il est seulement marqué `runtime dependency` jusqu à remplacement validé.
