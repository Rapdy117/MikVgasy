# Etat des backends

Mise a jour : 2026-07-22

## MikroTik

- Backend technique actif pour les operations hotspot.
- Chaque lecture/ecriture exige un `device_id` autorise.
- Les profils et attributs techniques restent sur RouterOS.
- Les sessions actives sont des observations ; les cumuls sont lus depuis `/ip/hotspot/user`.
- Les appels implicites a `connectToActiveMikrotikApi()` ont ete retires du runtime.
- Validation simultanee sur deux routeurs actifs encore requise avant VPS.

## RADIUS / SQL

- `profiles` et `users` portent la verite metier.
- `radcheck`, `radreply` et `radusergroup` sont des projections.
- `radacct` est reserve a l observation et a l historique, jamais au calcul du credit courant.
- Le contrat recharge `preview/apply` utilise un apercu signe et refuse toute incoherence par `409`.

## OPNsense

- Integration mise en pause. Aucun nouveau flux OPNsense ne doit etre introduit avant decision de reprise.
- Les donnees RADIUS existantes restent utilisables independamment de la pause API OPNsense.

## Multi-tenant

- Devices : tables SQL `network_devices` et `admin_device_assignments`.
- Roles : `superadmin`, `admin`, `reseller`.
- `owner_admin_id` est obligatoire pour les Devices et les objets commerciaux.
- Le Device actif est propre a la session PHP.

## Avant VPS

- fermer le filtrage reseller sur les modules commerciaux ;
- executer les tests d isolation et de concurrence ;
- repeter installation/migration sur une copie de base ;
- verifier licences SQL, rapports et archivage des compatibilites.


## Licences, maintenance et recouvrement — 2026-07-22

- Les licences sont registrees dans `device_licenses`, rattachees a `device_id`, avec statut `generated`, `active`, `expired` ou `invalid`.
- `RADIUS_MANAGER_LICENSE_SECRET` doit etre fourni par l environnement VPS ; il ne doit jamais etre modifie apres emission des cles.
- La maintenance SQL est reservee au superadmin via `api/admin/database_maintenance.php`, avec journalisation dans `system_maintenance_runs`.
- `tools/backup_sensitive.php` cree une archive SQL/configuration RADIUS hors web ; `tools/install_vps.sh` installe son cron quotidien.
- Le recouvrement filtre les donnees par `owner_admin_id`, `device_id` et operateur ; le reseller ne voit que ses propres operations.
- `users.expiration_date` est saisie et persiste au format date seule `YYYY-MM-DD`.


## Portail et INC-004

Le contrat preview/apply est actif. Le portail possède les APIs de statut MikroTik et OPNsense ; le statut MikroTik résout le Device captif configuré même pour les anciens templates et l injection du template/logo. L édition réellement publiée de la publicité et des tarifs reste le prochain chantier ; aucune donnée personnelle ne doit être injectée autrement que par l API du Device ciblé.
