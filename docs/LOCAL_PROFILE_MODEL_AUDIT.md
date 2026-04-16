# Audit Du Modele Local `profiles`

## Objet

Ce document verifie si le stockage local des profils peut devenir la source de verite metier principale si le projet se detache de `RADIUS`.

Date de reference :

- 29 mars 2026

## Conclusion Rapide

Le projet va deja dans la bonne direction :

- la table locale `profiles` existe
- le code metier l'utilise deja largement
- les recharges, vouchers, recouvrement et historique s'appuient deja sur le modele local

Mais le constat principal est le suivant :

- le code manipule deja un `Profile` plus riche que ce que le schema local persiste vraiment

Donc, pour un mode `sans RADIUS`, la table `profiles` est une bonne base, mais elle n'est pas encore complete.

## 1. Ce Que Le Stockage Local Porte Deja

Dans le schema de reference, `profiles` contient deja :

- `id`
- `name`
- `service_type`
- `rate_limit`
- `session_timeout`
- `idle_timeout`
- `validity_time`
- `data_quota_mb`
- `simultaneous_use`
- `ip_pool`
- `created_at`
- `account_type`

References :

- [radius_manager.sql](/var/www/html/docs/radius_manager.sql)
- [DATA_MODEL.md](/var/www/html/docs/DATA_MODEL.md)

## 2. Ce Que Le Code Metier Utilise Deja

Le code metier manipule deja les notions suivantes pour un profil :

- `rate_limit`
- `validity_time`
- `data_quota_mb`
- `simultaneous_use`
- `expired_mode`
- `grace_period`
- `price`
- `selling_price`
- `address_pool`
- `lock_user`
- `parent_queue`
- `validity_routeros`
- `grace_period_routeros`
- `price_raw`
- `selling_price_raw`

References :

- [create_profile.php](/var/www/html/api/profiles/create_profile.php)
- [add_profile.php](/var/www/html/pages/add_profile.php)
- [mikrotik_backend.php](/var/www/html/includes/mikrotik_backend.php)
- [profile_list.php](/var/www/html/pages/profile_list.php)

## 3. Ecart Observe

L'ecart initial etait simple :

- le formulaire et la logique metier savaient deja lire ou construire ces champs
- mais le schema local `profiles` ne les persistait pas tous

Etat actuel :

- le schema local `profiles` a ete etendu pour persister :
  - `expired_mode`
  - `grace_period`
  - `price`
  - `selling_price`
  - `lock_user`
  - `parent_queue`
  - `validity_routeros`
  - `grace_period_routeros`

Le point qui reste maintenant n'est plus l'absence de colonnes, mais l'alignement complet :

- des formulaires
- des flux de lecture/edition
- des usages backend-specifiques

## 4. Pourquoi Cet Ecart Est Important

Si le projet veut sortir de `RADIUS`, alors :

- `profiles` doit devenir la vraie source de verite metier

Sinon :

- on continuera a avoir un modele distribue
- une partie locale
- une partie backend
- une partie volatile

Ce serait exactement le type de complexite que le projet essaie maintenant d'eviter.

## 5. Ce Que `profiles` Doit Pouvoir Porter En Mode Local Complet

Le modele local cible devrait au minimum porter :

- `name`
- `service_type`
- `rate_limit`
- `validity_time`
- `data_quota_mb`
- `simultaneous_use`
- `expired_mode`
- `grace_period`
- `price`
- `selling_price`
- `ip_pool`
- `lock_user`
- `parent_queue`

Et idealement, distinguer :

- champs metier communs
- champs techniques backend-specifiques

## 6. Separation Recommandee

### 6.1 Champs metier communs

Ces champs doivent rester dans `profiles` :

- `name`
- `service_type`
- `rate_limit`
- `validity_time`
- `data_quota_mb`
- `simultaneous_use`
- `expired_mode`
- `grace_period`
- `price`
- `selling_price`

### 6.2 Champs techniques backend

Ces champs ne sont pas forcement universels :

- `ip_pool`
- `lock_user`
- `parent_queue`
- traductions techniques type `validity_routeros`

Pour eux, deux modeles sont possibles :

1. les garder dans `profiles` si on accepte quelques colonnes techniques
2. les sortir dans des tables de mapping backend-specifiques

## 7. Lecture Recommandee Pour Le Projet

Si la priorite est :

- simplicite
- moins de synchronisations
- moins de sources concurrentes

Alors il faut privilegier :

- un `profiles` local riche
- et des backends qui ne font qu'appliquer ce modele

## 8. Decision Technique Conseillee

La base structurelle est maintenant posee.

La bonne prochaine etape est :

1. officialiser `profiles` comme source de verite metier
2. finir d'aligner les formulaires et les APIs sur ce schema enrichi
3. separer ensuite ce qui est :
   - metier commun
   - technique MikroTik
   - technique OPNsense

## 9. Impact Sur OPNsense

Si `profiles` devient complet localement :

- OPNsense n'a plus besoin d'inventer son propre modele d'offre
- il lui suffit d'appliquer le meme profil metier
- seul le mecanisme d'execution change

Donc :

- le chantier `sans RADIUS` passe d'abord par la consolidation locale de `profiles`
- pas d'abord par un backend OPNsense complexe
