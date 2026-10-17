# Alignement OPNsense / MikroTik

Mise a jour : 2026-07-22

## Etat

OPNsense est temporairement en pause. MikroTik reste le backend technique actif pour les operations hotspot.

## Contrat commun

Le cycle metier reste : `profiles -> users -> projection -> observation -> historique`.

- MikroTik : projection vers RouterOS du Device cible ; sessions via `/ip/hotspot/active` et cumuls via `/ip/hotspot/user`.
- RADIUS/OPNsense : projection vers les tables `rad*` ; observations via `radacct`.
- OPNsense ne doit pas etre utilise comme fallback de MikroTik ou de RADIUS.

## Recharge

`preview` retourne un `preview_token` signe de courte duree. `apply` exige le meme contexte (`device_id`, utilisateur, profil, mode) et retourne `409` si les donnees ont change.

## Reprise OPNsense

Avant de reactiver OPNsense, il faudra definir le contrat portail : cumul metier total, session active et historique SQL/RADIUS, puis tester les quotas apres recharge.
