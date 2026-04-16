# Cartographie Des Dependances Projet

## Methode

Cette cartographie est basee sur :

- `require`, `require_once`, `include`, `include_once`
- liens `<script>` et `<link>`
- appels `fetch` et `EventSource`
- navigation, sidebar et index de recherche
- documents de pilotage existants

Un fichier non reference ici n est pas automatiquement supprimable. Il doit d abord etre classe selon son role reel.

## Entrees Principales

### Login

- `index.php`
  - envoie le login vers `api_proxy.php`
  - charge `style.css`
  - redirige les utilisateurs connectes vers `pages/dashboard.php`

### Navigation Applicative

- `includes/navigation_index.php`
  - index de recherche navbar
  - liste les pages accessibles par recherche
- `includes/sidebar.php`
  - menu principal
  - charge le contexte applicatif et les notifications
  - fichier global protege

### Scripts Systeme

- `scripts/sync_opnsense_sessions.php`
  - depend de `config/db.php`
  - depend de `includes/device_manager.php`
  - depend de `includes/opnsense_shaper.php`
  - depend de `includes/admin_notifications.php`

## Flux Runtime

### Pages Vers JavaScript Puis APIs

- `pages/dashboard.php`
  - charge `js/dashboard.js`
  - `js/dashboard.js` utilise `api/device_stream.php`, `api/get_live_metrics.php`, `api/get_stats.php`
- `pages/traffic_monitoring.php`
  - charge `js/traffic_monitoring.js`
  - utilise `api/traffic_stream.php`
- `pages/network_devices.php`
  - charge `js/network_device.js`
  - utilise `api/network_devices_api.php` et `api/test_device.php`
- `pages/freeradius.php`
  - charge `js/freeradius.js`
  - utilise `config/radius.php` et `api/test_radius.php`
- `pages/add_hotspot_user.php`
  - charge `js/add_hotspot_user.js`
  - utilise `api/nas.php` et `api/users/create_user.php`
- `pages/add_profile.php`
  - charge `js/select_nas.js`
  - utilise `api/network_devices_api.php`, `api/nas.php`, `api/profiles/create_profile.php`, `api/profiles/mikrotik_options.php`
- `pages/users_list.php`
  - charge `js/users_list.js`
  - utilise `api/users/get_user_sessions.php` et `api/nas.php`
- `pages/generate.php`
  - charge `js/generate.js`
  - utilise `api/users/profile_options.php`, `api/vouchers/prepare_batch.php`, `api/vouchers/apply_batch.php`, `api/vouchers/cancel_batch.php`
- `pages/user_recharge.php`
  - charge `js/user_recharge.js`
  - utilise `api/network_devices_api.php` et les APIs recharge
- `pages/sessions_list.php`
  - charge `js/pages/sessions_list.js`
  - utilise `api/disconnect_session.php` et le partial HTML `?_partial=sessions`

### Pages Vers Includes

- Les pages internes passent par `includes/auth.php` ou une verification de session.
- Les pages applicatives partagent `includes/sidebar.php`.
- Les pages metier utilisent selon le cas :
  - `config/db.php`
  - `includes/device_manager.php`
  - `includes/nas_resolver.php`
  - `includes/mikrotik_backend.php`
  - `includes/radius_sync.php`
  - `includes/user_provisioning.php`
  - `includes/profile_schema.php`
  - `includes/user_schema.php`
  - `includes/recharge_preview_service.php`
  - `includes/recharge_history_store.php`
  - `includes/operation_history.php`
  - `includes/vouchers.php`
  - `includes/recouvrement_invoices.php`

### APIs Vers Includes Et Config

- `api/network_devices_api.php`
  - gere les devices et configs JSON associees
- `api/test_device.php`
  - endpoint de test mutualise
- `api/test_opnsense.php`
  - compatibilite active, inclut `api/test_device.php`
- `api/test_radius.php`
  - utilise par `js/freeradius.js`
- `api/users/*`
  - gere creation, mise a jour, suppression, recharge et consultations utilisateur
- `api/profiles/*`
  - gere profils et options MikroTik
- `api/vouchers/*`
  - gere preparation, application et annulation de lots vouchers
- `api/recouvrement/*`
  - gere creation de factures recouvrement

## Fichiers Proteges

Ne pas modifier sans autorisation explicite :

- `css/theme.css`
- `css/sidebar.css`
- `includes/sidebar.php`

Ces fichiers ont un impact global sur plusieurs pages.

## Orphelins Confirmes

Ces fichiers sont hors runtime probable et doivent etre classes avant suppression :

- `api/get_stats.php.pre-restore-20260401-083410`
- `css/generate.css.bak`
- `js/generate.js.bak`
- `pages/print_vouchers.php.bak`
- `project-backup-20260330-165851.zip`
- `.cursor/debug-423b8d.log`

Statut recommande : `TODO`, classer puis supprimer ou archiver apres validation.

## Orphelins Ou References A Verifier

- `about.php`
  - doublon probable de `pages/about.php`
  - `pages/about.php` est reference par navigation, sidebar et auth
- `docs/template_default.zip`
  - reference documentaire ou archive
- `docs/template_optimized.zip`
  - reference documentaire ou archive
- `docs/*.xlsx`
  - documents d analyse, hors runtime
- `exports/*.sql`
  - exports ponctuels, hors runtime
- `docs/archive/*`
  - reference uniquement
  - ne doit jamais etre charge en runtime

## Dependance Cassee

### `pages/portal_profiles.php`

Statut : `BLOCKED`

Dependance manquante :

- `../modules/portal/PortalProfileController.php`

Constat :

- le dossier `modules/` n existe pas a la racine
- une version existe dans `docs/archive/modules/portal/`
- `docs/archive` ne doit pas etre utilise en runtime

Decision requise :

- restaurer le module dans un emplacement runtime stable
- ou retirer/desactiver proprement la page

## Compatibilites Actives

### `api/test_opnsense.php`

- inclut `api/test_device.php`
- ne pas supprimer tant que consommateurs et docs ne sont pas migres vers `test_device.php`

### `api/test_radius.php`

- utilise par `js/freeradius.js`
- reste actif comme test FreeRADIUS

## Archives Et References

- `docs/archive/*` : reference uniquement.
- `exports/*` : exports SQL ponctuels.
- `docs/PROJECT_TREE_*.json` : inventaires documentaires.
- `.codex/prompts/*` : templates manuels, non auto-charges.

## Regles De Suppression

Avant de supprimer, renommer ou deplacer un fichier :

1. lire `docs/project_rules.md`
2. verifier cette cartographie
3. chercher les references directes dans pages, APIs, JS, includes, docs et scripts
4. verifier que le fichier n est pas une compatibilite active
5. mettre a jour `docs/PROJECT_PLAN.md` si le fichier appartient a un chantier incomplet
