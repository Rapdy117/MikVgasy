# Alignement OPNsense Sur Le Mecanisme Metier Existant

## Objet

Ce document fixe ce qui doit etre considere comme :

- mecanisme metier commun
- specificite MikroTik
- cible d'evolution OPNsense

Le but est d'eviter de creer un nouveau process commercial si le modele deja en place couvre deja le besoin.

Date de reference :

- 29 mars 2026

## Principe Directeur

Pour OPNsense, il ne faut pas inventer un second cycle metier.

Le bon modele est :

- meme logique commerciale que le flux inspire de Mikhmon
- meme vocabulaire metier
- meme historique commercial
- meme recouvrement
- execution backend differente seulement quand c'est necessaire

En pratique :

- `MikroTik` execute localement via RouterOS API
- `OPNsense` doit reutiliser le meme cycle, mais via la couche `radius` + supervision OPNsense

## 1. Ce Qui Est Deja Du Mecanisme Metier Commun

Ces elements ne doivent pas etre re-crees pour OPNsense.

### 1.1 Profils / Offres

Le projet a deja un vrai modele d'offre local :

- table `profiles`
- nom d'offre
- validite
- quota data
- debit
- utilisateurs simultanes
- prix / prix de vente

Points de code :

- [create_profile.php](/var/www/html/api/profiles/create_profile.php)
- [add_profile.php](/var/www/html/pages/add_profile.php)
- [profile_list.php](/var/www/html/pages/profile_list.php)

### 1.2 Cycle De Recharge

Les trois modes commerciaux existent deja :

- `replace_offer` = `Changement d'offre`
- `extend_offer` = `Rechargement`
- `accumulate_offer` = `Reabonnement`

Points de code :

- [user_recharge.php](/var/www/html/pages/user_recharge.php)
- [recharge_preview.php](/var/www/html/api/users/recharge_preview.php)
- [apply_recharge.php](/var/www/html/api/users/apply_recharge.php)

### 1.3 Historique Commercial

Le projet stocke deja les operations utiles au suivi commercial :

- `recharge_history`
- `voucher_history`
- `operation_history`

Points de code :

- [apply_recharge.php](/var/www/html/api/users/apply_recharge.php)
- [vouchers.php](/var/www/html/includes/vouchers.php)
- [operation_history.php](/var/www/html/includes/operation_history.php)

### 1.4 Recouvrement / Facturation

Le cycle de recouvrement existe deja au-dessus du backend :

- regroupement des mouvements
- creation de facture
- suivi des factures
- exclusion des items deja factures

Points de code :

- [recouvrement.php](/var/www/html/pages/recouvrement.php)
- [recouvrement_invoices.php](/var/www/html/pages/recouvrement_invoices.php)
- [recouvrement_invoices.php](/var/www/html/includes/recouvrement_invoices.php)

## 2. Ce Qui Est Encore Specifique A MikroTik

Ces elements ne doivent pas etre copies tels quels vers OPNsense.

### 2.1 Execution Reelle De La Recharge

Aujourd'hui, seule la branche MikroTik applique reellement :

- remplacement d'offre
- rajout
- cumul

Point de code :

- [apply_recharge.php](/var/www/html/api/users/apply_recharge.php)

### 2.2 Lecture / Ecriture Des Profils RouterOS

Le mecanisme actuel s'appuie sur :

- `on-login`
- scheduler
- metadata de profil
- scripts de suivi type Mikhmon

Points de code :

- [mikrotik_backend.php](/var/www/html/includes/mikrotik_backend.php)

### 2.3 Journal Technique Mikhmon-Like

Des marqueurs historiques existent encore pour MikroTik :

- scripts commentes `mikhmon`
- construction `on-login`
- routines de suivi RouterOS

Cela fait partie de la couche d'execution MikroTik, pas du metier commun.

## 3. Ce Que OPNsense Fait Aujourd'hui

### 3.1 Ce Qui Est Deja Present

OPNsense dispose deja de :

- device management
- test de connexion
- stats / trafic / CPU
- couche de supervision
- presence dans la resolution NAS

Points de code :

- [network_devices.php](/var/www/html/pages/network_devices.php)
- [test_device.php](/var/www/html/api/test_device.php)
- [get_stats.php](/var/www/html/api/get_stats.php)
- [get_traffic_stats.php](/var/www/html/api/get_traffic_stats.php)
- [get_cpu_stats.php](/var/www/html/api/get_cpu_stats.php)

### 3.2 Ce Qui Est Encore Hybride

Dans le metier, OPNsense est encore surtout route vers `radius` :

- [nas_resolver.php](/var/www/html/includes/nas_resolver.php)

Aujourd'hui :

- creation user = logique locale + synchro `radius`
- mise a jour user = logique locale + synchro `radius`
- recharge preview = possible
- recharge appliquee = non
- profils OPNsense = profil local puis synchro `FreeRADIUS`
- consultation utilisateur = encore partiellement marquee par les vues MikroTik

## 4. Cible Recommandee Pour OPNsense

La cible recommandee n'est pas :

- un nouveau process commercial

La cible recommandee est :

- reutiliser le mecanisme metier commun
- faire d'OPNsense un backend commercial base sur `radius`
- garder OPNsense API pour la supervision et les vues live

En clair :

- metier commun = inchange
- execution OPNsense = adaptation backend

## 5. Premier Chantier A Lancer

Le premier chantier utile n'est pas la recharge directe.

Le premier chantier utile est :

- clarifier explicitement la branche `OPNsense + FreeRADIUS` sur les profils et les utilisateurs

Pourquoi :

- les profils sont la base du reste
- creation user depend des profils
- recharge depend des profils
- historique et recouvrement resteront les memes
- tant que la couche `users` reste trop hybride, les tests OPNsense restent trompeurs

## 6. Ordre D'Implementation Recommande

### Etape 1

- expliciter la correspondance entre profil local et execution OPNsense/RADIUS
- nettoyer l UI `profiles` pour ne pas afficher des options MikroTik comme si elles etaient valables partout

### Etape 2

- rendre explicite la creation user OPNsense via la branche `radius`
- ne plus laisser cette logique implicite
- realigner `users_list.php` et `get_user_profile_details.php` pour OPNsense / RADIUS

### Etape 3

- brancher la recharge appliquee OPNsense sur la mise a jour RADIUS
- reutiliser les trois modes existants sans changer le vocabulaire metier

### Etape 4

- verifier que l'historique commercial, le recouvrement et la facture ne dependent plus du backend

## 7. Regle A Respecter

Avant de creer un nouveau flux OPNsense, verifier si le besoin n'est pas deja couvert par :

- `profiles`
- `users`
- `recharge_preview`
- `apply_recharge`
- `operation_history`
- `recouvrement`

Si oui :

- garder le meme process
- changer seulement la couche d'execution

Si non :

- documenter precisement pourquoi une divergence est necessaire
