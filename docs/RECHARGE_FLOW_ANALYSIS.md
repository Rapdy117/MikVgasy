# Analyse du flux Recharge

## Objectif

Documenter le flux `recharge` sans logique inventee, en separant clairement les comportements `MikroTik` et `OPNsense / RADIUS`, puis en reliant ce flux a `users_list`.

---

## Regles metier appliquees

- un backend choisi = un metier choisi
- `mikrotik` => source de verite routeur
- `opnsense` / `radius` => source de verite SQL + RADIUS
- le mode `changement d offre` n a pas le meme effet que `rechargement` ou `reabonnement`
- le reset ne se traite pas globalement: il depend du backend et de la source qui alimente les compteurs affiches

---

## Resolution backend

Le `device` choisi determine le metier:

- `mikrotik` => `mikrotik_local`
- `opnsense` => `radius`
- `radius` => `radius`

References code:

- `includes/device_manager.php`
- `includes/nas_resolver.php`

---

## Entree du flux

### Champs communs

- `device_id`
- `username`
- `mode`

### Offre choisie

- `MikroTik` => `profile_name`
- `OPNsense / RADIUS` => `profile_id`

### Modes supportes

- `replace_offer` => changement d offre
- `extend_offer` => rechargement / rajout
- `accumulate_offer` => reabonnement / cumul sur le meme profil

---

## Flux MikroTik

### Source de verite

Tout est lu et ecrit sur le routeur cible:

- profil courant utilisateur = `/ip/hotspot/user.profile`
- temps restant = `limit-uptime - uptime`
- data restante = `limit-bytes-total - (bytes-in + bytes-out)`
- expiration = `comment`
- temps de l offre = `profile.session-timeout`
- validite commerciale = metadata `on-login.validity`

La base SQL n est pas source de verite pour ce flux.

### Preview

#### Changement d offre

- profil projete = nouveau profil
- temps projete = temps de l offre
- data projetee = quota de l offre
- expiration projetee = vide

#### Rechargement

- profil projete = profil courant conserve
- temps projete = temps restant courant + temps offre
- data projetee = data restante courante + data offre
- expiration projetee = expiration courante + validite offre si le compte n est pas expire

#### Reabonnement

- profil projete = profil courant
- condition = le profil choisi doit etre identique au profil courant
- temps projete = temps restant courant + temps offre
- data projetee = data restante courante + data offre
- expiration projetee = expiration courante + validite offre si l expiration existe encore

### Sortie reelle

#### Changement d offre

- ecrit `profile`
- ecrit `limit-uptime = temps offre`
- ecrit `limit-bytes-total = quota offre`
- vide `comment`
- execute `reset-counters`

#### Rechargement

- ecrit `limit-uptime = restant + offre`
- ecrit `limit-bytes-total = data restante + data offre`
- ecrit `comment = expiration + validite`
- execute `reset-counters`

#### Reabonnement

- meme logique additive que le rechargement
- execute `reset-counters`

### Impact `users_list`

Pour MikroTik:

- `data consommee` = baseline importee locale + consommation live routeur
- `duree cumulee` = baseline importee locale + temps utilisateur routeur

Le reset technique se fait sur le routeur cible, routeur par routeur.

---

## Flux OPNsense / RADIUS

### Source de verite

Le flux metier vient de SQL + RADIUS:

- credit temps courant = `users.current_credit_time`
- credit data courant = `users.current_credit_data`
- heritage utilisateur = `users.session_timeout`, `users.data_limit`
- heritage profil = `profiles.session_timeout`, `profiles.data_quota_mb`
- expiration = `users.expiration_date`
- observation consommation = `radacct`
- point de reset cycle = `user_counter_baselines`
- historique importe = `users.imported_session_total_seconds`, `users.imported_data_consumed_bytes`

### Regle d allocation retenue

Le credit courant est prioritaire. L heritage n est utilise que si le credit courant n est pas renseigne.

#### Temps alloue

1. `users.current_credit_time` si > 0
2. sinon `users.session_timeout` si > 0
3. sinon `profiles.session_timeout` si > 0

#### Data allouee

1. `users.current_credit_data` si > 0
2. sinon `users.data_limit` si > 0
3. sinon `profiles.data_quota_mb` si > 0

Cette regle evite le merge concurrent `max(...)` entre variables qui n ont pas le meme role.

### Formule de consommation

#### Consommation brute

- temps brut = `SUM(radacct.acctsessiontime)`
- data brute = `SUM(radacct.acctinputoctets + acctoutputoctets)`

#### Reset de cycle

- baseline session = `user_counter_baselines.imported_session_total_seconds`
- baseline data = `user_counter_baselines.imported_data_consumed_bytes`

#### Consommation du cycle courant

- `cycle_session = max(0, brut_session - baseline_session)`
- `cycle_data = max(0, brut_data - baseline_data)`

#### Consommation affichee

- `display_session = users.imported_session_total_seconds + cycle_session`
- `display_data = users.imported_data_consumed_bytes + cycle_data`

#### Restant courant

- `remaining_session = max(0, allocation_session - display_session)`
- `remaining_data = max(0, allocation_data - display_data)`

### Preview

#### Changement d offre

- profil projete = nouveau profil
- temps projete = temps offre
- data projetee = data offre
- expiration projetee = vide

#### Rechargement

- profil projete = profil courant conserve
- temps projete = restant courant + temps offre
- data projetee = restant courant + data offre
- expiration projetee = expiration courante + validite offre si le compte n est pas expire

#### Reabonnement

- profil projete = profil courant
- condition = le profil choisi doit etre identique au profil courant
- temps projete = restant courant + temps offre
- data projetee = restant courant + data offre
- expiration projetee = expiration courante + validite offre

### Sortie reelle

Le flux applique:

- mise a jour `users.profile_id`
- mise a jour du credit courant projete dans `users.current_credit_time` et `users.current_credit_data`
- mise a jour des champs herites `users.session_timeout` et `users.data_limit`
- mise a jour `users.expiration_date`
- sync RADIUS via `updateUserToNasBackend(...)`
- reset du cycle courant en ecrivant la baseline avec les totaux `radacct` au moment de la recharge

### Cle de baseline

Pour `MikroTik`, la baseline reste par routeur cible.

Pour `OPNsense / RADIUS`, la baseline est partagee par backend SQL/RADIUS:

- scope id = `radius`

Cela evite de casser le calcul quand plusieurs devices pointent vers la meme chaine SQL/RADIUS.

---

## Lien avec `users_list`

Les colonnes `Data consommee` et `Cumule Duree` dans `users_list` sont alimentees par:

- `historique importe users.imported_*`
- plus la consommation `radacct` du cycle courant
- moins la baseline de reset du cycle courant

Formule actuelle alignee:

- `cumule_duree = users.imported_session_total_seconds + max(0, SUM(radacct_time) - baseline_session)`
- `data_consommee = users.imported_data_consumed_bytes + max(0, SUM(radacct_bytes) - baseline_data)`

Donc, pour faire repartir le cycle courant a zero apres recharge cote `opnsense/radius`, il faut rebaseliner `user_counter_baselines`.

Il ne faut pas effacer `users.imported_*` si leur role reste l historique importe.

---

## Sensibilites a surveiller

- ne pas melanger routeur MikroTik et SQL dans une meme decision
- ne pas faire diverger `preview`, `apply` et `users_list`
- ne pas utiliser plusieurs cles de baseline pour le meme backend
- ne pas convertir une variable d heritage en verite principale si le credit courant est deja renseigne
- ne pas appliquer une regle de reset identique aux deux backends

---

## Fichiers relies

- `api/users/apply_recharge.php`
- `includes/recharge_preview_service.php`
- `includes/mikrotik_backend.php`
- `includes/radius_credit_runtime.php`
- `api/users/get_user_sessions.php`
- `pages/users_list.php`
- `js/user_recharge.js`
