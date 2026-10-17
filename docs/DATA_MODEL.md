# Modele de donnees actuel

Mise a jour : 2026-09-04

## Principes

La chaine canonique est : `profiles -> users -> projection RADIUS/MikroTik -> observation -> historique`.
Une donnee metier possede une seule source de verite.

## Comptes et perimetre

- `local_admin_users` contient `superadmin`, `admin` et `reseller`.
- Un reseller porte `owner_admin_id`, identifiant de son admin proprietaire.
- La desactivation conserve les references historiques.
- Les objets commerciaux doivent porter `owner_admin_id` et `created_by_user_id`.

## Devices

- `network_devices` est la source SQL des routeurs et secrets chiffres.
- `admin_device_assignments` porte les attributions supplementaires.
- Chaque Device a un `owner_admin_id` valide.
- `active_device_id` n existe que dans la session du compte connecte.
- `config/opnsense.json.old` est une archive et ne doit pas etre lu par le runtime.

## RADIUS

- `profiles` : offres commerciales.
- `users` : etat metier et `current_credit_time/current_credit_data`.
- `radcheck`, `radreply`, `radusergroup` : projections techniques.
- `radacct` : sessions observees et historique.

## MikroTik

Les profils, utilisateurs et limites techniques restent sur le Device RouterOS cible. SQL conserve l appartenance locale, les recharges, vouchers et historiques ; il ne redefinit pas les limites routeur.

### Inventaire LAN (2026-10-02)

- `lan_inventory_snapshots` : `device_id`, `captured_at`, `captured_by`, `payload_json` (tables RouterOS brutes : baux, ARP, Hotspot host/active, voisins, bridge host, adresses, pools, DNS statique, ports HTTP ouverts). Observation horodatée, jamais source de configuration.
- `lan_inventory_links` : `device_id`, `child_key`, `parent_key` (clé `mikrotik:<identity>` ou `mac:<MAC>`), `created_by`, `created_at`. Rattachement manuel uniquement.

## Vouchers et recharges

Les vouchers generes sont enregistres en SQL avec Device, operateur, profil, prix et statut. Les recharges utilisent `preview_token` temporaire pour eviter une validation sur un apercu obsolète.


## Licences et maintenance

- `device_licenses` est la source SQL du cycle de licence par Device : cle, fingerprint, expiration, statut, auteur et dates d emission/activation.
- `system_maintenance_runs` trace les etats, archivages, nettoyages et sauvegardes sensibles.
- Les sauvegardes sensibles contiennent SQL metier/RADIUS et configuration RADIUS, et sont stockees hors de la racine web.
- `recouvrement_invoices` porte `owner_admin_id`, `device_id` et `created_by_user_id` pour appliquer l isolation admin/reseller.
- `users.expiration_date` est une date calendaire sans heure (`YYYY-MM-DD`), distincte de `session_timeout` et de `validity_time`.


## Portail captif

- `config/portal.json` conserve le Device cible, l URL API, le template actif et le logo actif.
- Les templates et logos sont stockes sous `uploads/portal_captive/`, jamais dans `docs/`.
- Les informations personnelles du portail proviennent exclusivement de l API du Device cible ; `radacct` sert uniquement à l observation.
- Les tarifs affichables doivent provenir de `profiles.price` et `profiles.selling_price` pour RADIUS/SQL, ou du catalogue du profil RouterOS pour MikroTik.

## Observation de qualite reseau

- `network_quality_samples` conserve les mesures et diagnostics par `device_id` pendant 48 heures au maximum.
- `network_quality_monitor_state` conserve uniquement les compteurs precedents necessaires aux deltas d interfaces, de debit et de journaux.
- `network_quality_queue_state` conserve les compteurs precedents par Simple Queue afin de calculer les drops reels de chaque intervalle.
- `network_security_samples` conserve pendant 48 heures les observations agregees DoT, DoQ, DoH suspecte, DNS redirige et rejets WAN/Starlink-Shield.
- `network_security_client_samples` conserve pendant 48 heures les observations par IP cliente et type de signal ; l identite Hotspot est une observation au moment de la collecte.
- `network_security_monitor_state` conserve seulement les compteurs pare-feu precedents necessaires au calcul des deltas.
- Ces tables sont des observations techniques et ne redefinissent aucune offre, limite ou identite utilisateur.
