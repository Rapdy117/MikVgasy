# Plan de tests multi-tenant et migration VPS

Document de validation avant déploiement VPS. Tous les tests doivent être exécutés sur une copie de la base puis sur l’environnement local courant.

## 1. Comptes et rôles

- [ ] Connexion superadmin valide.
- [ ] Connexion admin valide.
- [ ] Connexion reseller valide.
- [ ] Mot de passe invalide refusé.
- [ ] Session régénérée après connexion.
- [ ] Désactivation d’un compte bloque la connexion.
- [ ] Désactivation conserve les historiques.
- [ ] Un admin ne peut pas modifier un superadmin.
- [ ] Un reseller ne peut pas créer/modifier/supprimer un compte local.

## 2. Devices et attribution

- [ ] Tous les Devices SQL ont un `owner_admin_id` valide et actif.
- [ ] Aucun Device runtime ne provient du JSON archivé.
- [ ] Le superadmin voit tous les Devices.
- [ ] L’admin voit uniquement ses Devices et ceux attribués à son périmètre.
- [ ] Le reseller voit uniquement ses Devices attribués.
- [ ] Un reseller peut avoir plusieurs Devices.
- [ ] Un admin ne peut gérer que ses Devices et ses resellers.
- [ ] Un reseller peut avoir plusieurs Devices attribués.
- [ ] La Navbar est testée avec plusieurs sessions simultanées.
- [ ] Le changement de Device d’une session n’interfère pas avec une autre session.
- [ ] Ajout d’une attribution depuis Administration.
- [ ] Retrait d’une attribution depuis Administration.
- [ ] Suppression d’un Device désactive la ligne SQL et conserve les historiques.
- [ ] Un Device désactivé n’apparaît plus dans la Navbar.
- [ ] Un Device actif par défaut est défini en session si nécessaire.

## 3. Navbar et sessions

- [ ] La Navbar ne liste que les Devices autorisés.
- [ ] Changement de Device modifie uniquement la session courante.
- [ ] Deux comptes connectés simultanément peuvent sélectionner deux Devices différents.
- [ ] Le changement de Device d’un compte ne modifie pas l’autre compte.
- [ ] Un Device supprimé ou retiré invalide l’ancien Device actif de session.

## 4. Tests API `device_id`

Pour chaque endpoint dépendant d’un Device :

- [ ] Device autorisé → `200` ou réponse métier attendue.
- [ ] Un admin appelant un Device appartenant à un autre admin reçoit `403`.
- [ ] Un reseller appelant un Device d’un autre périmètre reçoit `403`.
- [ ] Un Device inexistant reçoit `403` ou `404` documenté.
- [ ] Un Device désactivé reçoit `403` et n’est plus utilisable.
- [ ] `owner_admin_id` de chaque Device référence un compte admin/superadmin actif existant.
- [ ] `device_id` forgé dans GET → refusé.
- [ ] `device_id` forgé dans POST → refusé.
- [ ] Absence de `device_id` → `422` si obligatoire.
- [ ] CSRF invalide → `403`.

Endpoints à couvrir :

- [ ] `/api/network_devices_api.php`
- [ ] `/api/users/create_user.php`
- [ ] `/api/users/update_user.php`
- [ ] `/api/users/delete_mikrotik_user.php`
- [ ] `/api/users/recharge_preview.php`
- [ ] `/api/users/apply_recharge.php`
- [ ] `/api/users/recharge_history.php`
- [ ] `/api/users/profile_options.php`
- [ ] `/api/profiles/create_profile.php`
- [ ] `/api/profiles/delete_profile.php`
- [ ] `/api/vouchers/prepare_batch.php`
- [ ] `/api/vouchers/apply_batch.php`
- [ ] `/api/admin/mikrotik_import_standard.php`
- [ ] `/api/admin/mikrotik_export_standard.php`
- [ ] `/api/admin/radius_import_standard.php`
- [ ] `/api/admin/radius_export_standard.php`
- [ ] `/api/admin/validate_mikrotik_target.php`
- [ ] `/api/license/activate_license.php`
- [ ] `/api/license/request_license.php`

## 5. MikroTik multi-routeurs

- [ ] Lecture des profils sur le Device sélectionné.
- [ ] Création utilisateur sur le bon routeur.
- [ ] Modification utilisateur sur le bon routeur.
- [ ] Suppression utilisateur sur le bon routeur.
- [ ] Recharge sur le bon routeur.
- [ ] Sessions et cumuls rattachés au bon Device.
- [ ] Aucun appel implicite à un routeur non sélectionné.
- [x] Audit statique : aucune référence runtime à `connectToActiveMikrotikApi()` ne subsiste.
- [x] Les opérations profil, utilisateur, recharge, session, suppression et import/export reçoivent un contexte MikroTik explicite.
- [ ] Test réel simultané sur deux routeurs MikroTik actifs — **réservé à l’environnement VPS** : lancer deux opérations concurrentes avec deux `device_id` différents et vérifier l’absence d’interférence.
- [ ] Vérifier que chaque réponse, journal et historique conserve le `device_id` réellement ciblé pendant le test concurrent.
- [ ] Vérifier qu’un changement de routeur dans une session ne modifie pas l’opération en cours dans l’autre session.

## 6. RADIUS et OPNsense

- [ ] Preview retourne un `preview_token` signé à durée courte.
- [ ] Apply exige le token, le `device_id`, l’utilisateur, le profil et le mode.
- [ ] Token expiré, falsifié ou issu d’un autre contexte : réponse `409`, aucune modification.
- [ ] Mutation entre preview et apply : réponse `409`, aucune modification.
- [ ] Deux validations simultanées ne mélangent pas les quotas métier.
- [ ] Preview et apply utilisent le même calcul.
- [ ] Utilisateurs SQL/RADIUS conservés après migration.
- [ ] Paramètres `config/radius.json` restaurables.
- [ ] Test d’authentification RADIUS.
- [ ] Ports 1812/1813 vérifiés.
- [ ] Secret partagé chiffré correctement lu.
- [ ] Sessions OPNsense synchronisées.
- [ ] Historique RADIUS/OPNsense correctement filtré.
- [ ] `radacct` est filtré par NAS/Device et reste une source d’observation/historique uniquement.

## 7. Licences

- [ ] Génération superadmin uniquement.
- [ ] Device ID valide accepté.
- [ ] Device ID étranger refusé.
- [ ] Expiration `never` valide.
- [ ] Date d’expiration valide.
- [ ] Licence expirée refusée à l’activation.
- [ ] Licence active autorise l’activation.
- [ ] Ancien générateur de licence inaccessible/archivé.

## 8. SQL, fichiers et installation

- [ ] Export SQL complet restaurable.
- [ ] Export SQL squelette sans utilisateurs/historiques/notifications.
- [ ] Export ZIP contient PHP, HTML, CSS, JS et configurations.
- [ ] Export ZIP ignore `.git` et `uploads`.
- [ ] Import ZIP bloque les chemins `..`.
- [ ] Import SQL réservé au superadmin.
- [ ] Installation crée le compte initial demandé.
- [ ] Script VPS installe Apache, PHP et FreeRADIUS.
- [ ] Extensions PHP requises disponibles.
- [ ] Services Apache et FreeRADIUS actifs.

## 9. Rapports et historique

- [ ] Les historiques restent visibles après désactivation d’un compte.
- [ ] Un reseller ne voit que ses créations.
- [ ] Un admin voit les données de ses resellers.
- [ ] Le superadmin voit l’ensemble.
- [ ] Les montants des rapports proviennent d’une source documentée.
- [ ] Les compteurs ne sont pas présentés comme chiffre d’affaires.

## 10. Compatibilités et nettoyage

- [ ] Aucun runtime ne lit `config/opnsense.json.old`.
- [ ] Les fichiers `.old` ne sont pas requis par l’application.
- [ ] `test_opnsense.php` est retiré ou documenté comme compatibilité.
- [ ] Les alias `opnsense_*` sont retirés ou documentés.
- [ ] `portal_profiles.php` est restaurée ou désactivée proprement.
- [ ] Les documents canoniques ne décrivent plus le JSON comme source runtime.

## 11. Pages métier finalisées

- [ ] Recharge DATA seule, temps seul, puis DATA + temps.
- [ ] Mode Rechargement : le profil courant reste inchangé.
- [ ] Mode Rechargement : l expiration existante reste inchangée, sans prolongation.
- [ ] Mode Rechargement : les compteurs ne sont pas réinitialisés.
- [ ] Mode Rechargement MikroTik : aucune commande `reset-counters` n est envoyée.
- [ ] Mode Rechargement RADIUS/OPNsense : la baseline de compteurs n est pas recréée.
- [ ] Profil RADIUS/SQL résolu par `profile_id`; profil MikroTik par `profile_name`.
- [ ] Recharge concurrente : aperçu périmé refusé avec `409`.
- [ ] Déconnexion de session transmet `device_id` et refuse un Device étranger avec `403`.
- [ ] Dashboard : liens jour/mois et filtres de rapport corrects.
- [ ] Sessions : nom ouvre le détail avec le bon `device_id`.
- [ ] Profil/expiration modifiables uniquement par admin/superadmin.
- [ ] Vouchers : génération SQL, filtres, pagination, suppression autorisée et purge au-delà de 5000.
- [ ] Vouchers utilisés : `used_at`, utilisateur, Device et source renseignés.
- [ ] Profils MikroTik écrits uniquement dans le routeur sélectionné.
- [ ] Utilisateur MikroTik expiré marqué selon Mikhmon sur RouterOS 6 et 7.
- [ ] Modification utilisateur MikroTik : le profil est choisi dans une liste issue du Device cible, pas saisi en texte libre.
- [ ] Modification utilisateur MikroTik : profil inexistant refusé côté API.
- [ ] Modification utilisateur MikroTik : réactivation efface l ancien commentaire d expiration si le champ expiration est vide.
- [ ] Modification utilisateur MikroTik : réactivation avec une date/heure déjà expirée est refusée.
- [ ] Mode `ntf` / `ntfc` : le compte expiré reçoit `limit-uptime=1s`, sans `disabled=yes`.
- [ ] Mode `rem` / `remc` : le compte expiré est supprimé par le scheduler.
- [ ] Compte déjà marqué `limit-uptime=1s` : aucune seconde commande inutile n est envoyée.
- [ ] Commentaire RouterOS 7 `YYYY-MM-DD` : désactivation automatique si date passée.
- [ ] Commentaire RouterOS 6 `jul/23/2026` ou `jul/23/2026 HH:mm:ss` : désactivation automatique si date passée.
- [ ] Commentaire sans date reconnue : aucune désactivation automatique.
- [ ] Date future : compte conservé actif.
- [ ] Date du jour ou passée en mode Notice : commande `/ip/hotspot/user/set` avec le `.id` du Device ciblé et `limit-uptime=1s`.
- [ ] Échec du marquage d expiration : erreur journalisée, lecture de la liste non bloquée.
- [ ] Voucher : bouton supprimer désactivé sans sélection et pagination numérotée.

## Rapport d’exécution

- Date :
- Environnement :
- Base utilisée :
- Compte test :
- Tests réussis :
- Tests échoués :
- Anomalies :
- Correctifs associés :


## 12. Licences SQL et secret VPS

- [ ] Migration `009_device_licenses.sql` rejouee sans doublon sur une copie.
- [ ] Generation reservee au superadmin et enregistrement dans `device_licenses`.
- [ ] Activation met a jour `status`, `expiry` et `activated_at`.
- [ ] Expiration invalide une licence et bloque le Device non licence.
- [ ] Renouvellement conserve le bon `device_id` et remplace l expiration attendue.
- [ ] Deux Devices ne peuvent pas utiliser la meme licence.
- [ ] `RADIUS_MANAGER_LICENSE_SECRET` est lu depuis l environnement VPS et reste stable.

## 13. Maintenance SQL et sauvegarde sensible

- [ ] API maintenance sans session superadmin : `403`.
- [ ] CSRF invalide : `403`.
- [ ] `status` retourne uniquement les tables autorisees.
- [ ] `archive` cree un dump avant suppression et ne supprime aucune ligne.
- [ ] `clean` exige une confirmation explicite et journalise `affected_rows`.
- [ ] `backup_sensitive` cree une archive hors racine web et journalise son chemin.
- [ ] Cron VPS execute `radius-manager-backup-sensitive.php` quotidiennement.
- [ ] Migration `009_system_maintenance.sql` est idempotente.

## 14. Recouvrement et expiration

- [ ] Un reseller ne voit que ses factures, mouvements, vouchers et recharges.
- [ ] Un reseller ne peut pas lire ou modifier une facture d un autre admin ou Device.
- [ ] Un admin voit les donnees de ses Devices et resellers.
- [ ] Le superadmin voit l ensemble.
- [ ] Toute facture conserve `owner_admin_id`, `device_id` et `created_by_user_id`.
- [ ] `users_list` affiche et enregistre une expiration date seule `YYYY-MM-DD`.
- [ ] Une date avec heure, invalide ou hors format est refusee ou normalisee explicitement.
- [ ] La desactivation d un compte ne supprime aucun historique de recouvrement.


## 15. Validation finale hors VPS

Ces contrôles doivent être terminés sur l environnement local et une copie SQL avant migration :

- [ ] Expiration MikroTik : utilisateur expiré désactivé automatiquement sur RouterOS 6.
- [ ] Expiration MikroTik : utilisateur expiré désactivé automatiquement sur RouterOS 7.
- [ ] Vouchers : génération SQL, filtres, pagination, suppression admin/superadmin, purge au-delà de 5000 et rapprochement on-login.
- [ ] Licences : migration `device_licenses`, génération, activation, expiration, renouvellement et Device non licencié.
- [ ] Recouvrement : isolation reseller par `owner_admin_id`, `device_id` et opérateur ; historiques conservés après désactivation.
- [ ] Portail : module runtime charge correctement ; le contrat cumul métier/session active/historique est documenté ; OPNsense reste explicitement en pause.
- [ ] Rapports : montants du jour/mois vérifiés contre la source commerciale ; aucun compteur présenté comme revenu.
- [ ] Installation : répétition de `tools/install.php`, migrations 009, restauration export SQL et présence des extensions/services prévus.

## 16. Validation réservée au VPS

- [ ] Test réel simultané de deux comptes connectés à deux routeurs MikroTik actifs.
- [ ] Vérification de l absence d interférence de session, Device, cache, journal et historique entre les deux routeurs.
- [ ] Exécution du cron de sauvegarde sensible et restauration contrôlée hors web.

Le test concurrent MikroTik ne doit pas être marqué réussi sur le poste local si deux routeurs actifs ne sont pas réellement disponibles.

## Vouchers et licences (audit 2026-07-22)

- Générer un lot de vouchers : chaque ligne reçoit `device_id`, `owner_admin_id` et `created_by_user_id` en SQL.
- Vérifier qu’un reseller ne voit et ne supprime que ses vouchers ; un autre périmètre retourne zéro ligne ou `403`.
- Vérifier le filtre Device et l’absence de fuite entre deux routeurs du même admin.
- Dépasser 5000 vouchers et confirmer la purge des plus anciens, avec pagination limitée.
- Générer une licence : accès superadmin uniquement, enregistrement dans `device_licenses`.
- Activer une licence sur le bon Device ; tester une clé d’un autre Device et un Device inexistant (`403`/erreur sans modification).
- Vérifier expiration, statut `expired`, Device non licencié et renouvellement par nouvelle clé.
- Supprimer une licence puis confirmer que le Device repasse `unlicensed`.

## 17. Audit transversal boutons et endpoints — 2026-07-22

- [x] Vérifier les URLs appelées par les scripts JavaScript : aucun endpoint métier manquant détecté.
- [x] Vérifier les actions utilisateurs, profils, vouchers, recharges, sessions, licences et recouvrement : `device_id` et CSRF présents selon le contrat.
- [x] Vérifier les réponses JSON et les permissions principales.
- [x] Corriger la déconnexion MikroTik : le contexte NAS explicite est désormais transmis à `disconnectMikrotikHotspotActiveSession()`.
- [ ] Rejouer ces actions sur le VPS avec un Device réellement connecté et contrôler les réponses HTTP (`200`, `403`, `409`).
