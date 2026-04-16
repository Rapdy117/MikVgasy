# Cohérence Des Références Techniques

## Objet
## Contraintes D Architecture (Obligatoire)

Ce document doit etre lu conjointement avec `project_rules.md`.

Toute interpretation des references techniques doit respecter strictement :

- un attribut = une seule source de verite
- aucune lecture mixte entre :
  - profil
  - utilisateur
  - backend
- aucune projection ne doit redefinir une valeur metier

Avant toute utilisation d une reference :

1. identifier si elle est :
   - source de verite
   - projection
   - reference documentaire
2. verifier qu elle ne cree pas de duplication avec le code actif

Toute integration qui viole ces regles est interdite.
Ce document relie les sources techniques embarquées dans `docs/` au code applicatif actif dans `/var/www/html`.

Il couvre :

- `docs/radius_manager.sql`
- `docs/www/` : référence UI / front OPNsense
- `docs/mvc/` : référence routeur MVC / API OPNsense
- `docs/mikhmon/` : référence MikroTik / Mikhmon

Note :

- la demande mentionne `mvs`, mais le dossier présent dans le dépôt est `docs/mvc/`

## 1. `docs/radius_manager.sql`

### Ce que la référence décrit

Le fichier [docs/radius_manager.sql](/var/www/html/docs/radius_manager.sql) décrit une base mixte :

- tables applicatives :
  - `devices`
  - `logs`
  - `profiles`
  - `opnsense_profiles`
- tables FreeRADIUS :
  - `nas`
  - `radacct`
  - `radcheck`
  - `radgroupcheck`
  - `radgroupreply`
  - `radpostauth`
  - `radreply`
  - `radusergroup`

### Cohérence avec le code actif

Cohérent avec le code actif :

- `nas` est utilisé par [api/nas.php](/var/www/html/api/nas.php), [api/profiles/create_profile.php](/var/www/html/api/profiles/create_profile.php), [api/users/create_user.php](/var/www/html/api/users/create_user.php), [api/users/update_user.php](/var/www/html/api/users/update_user.php)
- `profiles` est utilisé par [pages/add_hotspot_user.php](/var/www/html/pages/add_hotspot_user.php), [pages/profile_list.php](/var/www/html/pages/profile_list.php), [api/profiles/create_profile.php](/var/www/html/api/profiles/create_profile.php)
- `radacct`, `radcheck`, `radreply`, `radgroupreply`, `radusergroup` sont alignées avec [includes/radius_sync.php](/var/www/html/includes/radius_sync.php) et les APIs utilisateurs

Écarts observés :

- le code actif n’utilise pas la table `devices` comme source des devices réseau ; il utilise `config/opnsense.json` via [api/network_devices_api.php](/var/www/html/api/network_devices_api.php)
- `opnsense_profiles` n’est pas utilisée dans le code actif
- `logs` n’est pas une source active du back-office courant

Conclusion :

Conclusion :

- `docs/radius_manager.sql` est une reference metier pour :
  - `nas`
  - `profiles`
  - tables FreeRADIUS

Contraintes :

- ne pas utiliser ce fichier comme source runtime
- ne pas supposer que toutes les tables sont actives
- ne pas introduire de nouvelles dependances basees sur `devices` ou `opnsense_profiles`

Regle :

- seule la base active doit etre consideree comme source de verite
- toute divergence doit etre resolue dans le code, pas dans la documentation
## 2. `docs/www/`

### Ce que la référence décrit

Le dossier [docs/www](/var/www/html/docs/www/index.php) représente la couche UI OPNsense.

Points d’entrée principaux :

- [docs/www/index.php](/var/www/html/docs/www/index.php) : routage UI sous préfixe `/ui/`
- [docs/www/api.php](/var/www/html/docs/www/api.php) : routage API sous préfixe `/api/`

Le dossier contient aussi les assets front OPNsense :

- `css/*`
- `js/*`
- `fonts/*`

### Cohérence avec le code actif

Cohérent avec le code actif :

- [pages/dashboard.php](/var/www/html/pages/dashboard.php) charge des assets depuis `docs/www/js/`
  - `chart.umd.min.js`
  - `moment-with-locales.min.js`
  - `chartjs-adapter-moment.min.js`
  - `chartjs-plugin-streaming.js`
  - `smoothie.js`

Cela montre que `docs/www` n’est pas seulement documentaire : une partie est utilisée comme dépôt local d’assets front.

Écarts observés :

- le projet actif n’utilise pas le routeur OPNsense PHP de `docs/www/index.php` ni `docs/www/api.php` comme runtime applicatif
- le projet actif reproduit ses appels OPNsense directement en PHP via cURL dans `api/*.php`

Conclusion :

- `docs/www/` est une référence utile pour comprendre la structure UI et les assets OPNsense
- ce n’est pas le moteur de routage utilisé par l’application active

Contraintes :

- ne pas utiliser `docs/www/index.php` comme routeur applicatif
- ne pas reproduire la logique UI OPNsense dans le projet actif

Regle :

- `docs/www` = reference UI + assets uniquement
- toute logique metier doit rester dans `pages/` et `api/`

## 3. `docs/mvc/`

### Ce que la référence décrit

Le dossier `docs/mvc/` documente l’architecture backend MVC OPNsense.

Fichiers structurants :

- [docs/mvc/app/config/config.php](/var/www/html/docs/mvc/app/config/config.php)
- [docs/mvc/app/config/loader.php](/var/www/html/docs/mvc/app/config/loader.php)
- [docs/mvc/app/config/AppConfig.php](/var/www/html/docs/mvc/app/config/AppConfig.php)

Ils montrent :

- un `baseUri` OPNsense de type `/opnsense_gui/`
- un autoload MVC
- une organisation `controllers / models / views / library`

### Cohérence avec le code actif

Cohérent avec le code actif :

- [docs/www/api.php](/var/www/html/docs/www/api.php) charge précisément cette config MVC pour router `/api/`
- [docs/www/index.php](/var/www/html/docs/www/index.php) route l’UI OPNsense via ce socle MVC

Écarts observés :

- le projet actif ne monte pas ce MVC OPNsense dans son propre runtime
- il consomme l’API OPNsense en externe, côté client PHP, par appels HTTP

Conclusion :

- `docs/mvc/` est une référence d’architecture API OPNsense
- utile pour comprendre la forme des endpoints et le modèle de contrôleurs
- non intégré comme framework de l’application active



## 4. `docs/mikhmon/`

### Ce que la référence décrit

Le dossier `docs/mikhmon/` est une base applicative orientée MikroTik / Hotspot.

Composants importants :

- [docs/mikhmon/lib/routeros_api.class.php](/var/www/html/docs/mikhmon/lib/routeros_api.class.php) : client RouterOS API
- [docs/mikhmon/include/config.php](/var/www/html/docs/mikhmon/include/config.php) : stockage des cibles et secrets
- `hotspot/*.php` : écrans de lecture / création / gestion
- `process/*.php` : actions d’écriture ou d’administration

### Cohérence avec le code actif

Cohérent partiellement :

- le projet actif conserve une logique `mikrotik` au niveau métier
  - [includes/nas_resolver.php](/var/www/html/includes/nas_resolver.php)
  - [includes/radius_sync.php](/var/www/html/includes/radius_sync.php)
- `docs/mikhmon/` documente bien les opérations RouterOS qui manquent encore côté API projet actif

Écarts observés :

- le projet actif ne réutilise pas directement `RouterosAPI`
- il ne pilote pas encore MikroTik en API métier comme il pilote OPNsense
- la persistance active des devices n’est pas basée sur la config Mikhmon

Conclusion :

- `docs/mikhmon/` est la meilleure référence locale pour documenter une future couche API MikroTik
- il sert surtout de base documentaire et d’inspiration d’implémentation



## 5. Synthèse De Cohérence Avec Le Code Actif

### Références directement exploitables

- `docs/radius_manager.sql` pour :
  - `nas`
  - `profiles`
  - tables FreeRADIUS
- `docs/mikhmon/` pour :
  - opérations RouterOS / MikroTik
  - paramètres attendus
  - usages hotspot
- `docs/www/js/*` pour :
  - librairies front réellement chargées par le dashboard

### Références surtout architecturales

- `docs/www/index.php`
- `docs/www/api.php`
- `docs/mvc/*`

### Références non alignées comme source active

- table `devices` de `docs/radius_manager.sql`
- `opnsense_profiles`
- logique Mikhmon de session/config interne

## 6. Décision Documentaire Recommandée

Pour la suite du projet :

- garder `docs/radius_manager.sql` comme référence SQL métier
- garder `docs/www/` et `docs/mvc/` comme référence OPNsense UI/API
- documenter MikroTik à partir de `docs/mikhmon/` comme référence API locale

Le document complémentaire recommandé pour cette dernière partie est :

- [docs/MIKHMON_MIKROTIK_API.md](/var/www/html/docs/MIKHMON_MIKROTIK_API.md)

## 7. Regles Transverses Critiques

### 7.1 Priorite Des Sources

- MikroTik :
  - source = routeur uniquement
  - DB = cache uniquement

- RADIUS / OPNsense :
  - source = DB + RADIUS
  - projection = rad*

### 7.2 Interdictions

- ne jamais melanger :
  - reference documentaire
  - code actif
  - projection technique

- ne jamais utiliser une reference pour deduire une valeur runtime

### 7.3 Regle De Validation

Avant toute utilisation d une reference :

- verifier son role exact
- verifier sa coherence avec project_rules.md
- verifier qu elle ne cree pas de duplication

Si un doute existe :

- la reference doit etre ignoree
