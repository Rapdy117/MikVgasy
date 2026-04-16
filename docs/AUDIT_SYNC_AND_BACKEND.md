
# Audit synchronisation (auto-refresh), backend et messages — pages PHP
## Version mise à jour avec plan d’action regroupé

**Date de l’audit initial :** 2026-04-02
**Date de mise à jour :** 2026-04-10
**Périmètre analysé :** `pages/*.php`, `includes/*.php`, `js/*.js`, scripts JavaScript inline dans les pages PHP.
**Méthode :** recherche ciblée dans le dépôt (`setInterval`, `setTimeout`, `EventSource`, `location.reload`, `fetch`, `$pdo`, `buildAppContext`, `*_backend.php`, `set_message`, `alert`, `confirm`).
**Important :** ce document est une **mise à jour de structuration et de planification**. Les **anomalies observées sont volontairement conservées dans chaque section** afin de garder la trace exacte des constats initiaux et de les rattacher au plan de traitement.

### État actuel du code (2026-04-10) — évolutions notables

- **`pages/sessions_list.php` + `js/pages/sessions_list.js`**
  - **Plus de polling automatique** (l’audit initial signalait un `setInterval` ~10 s + `fetch` partiel ; le rafraîchissement est **manuel** via le bouton « Actualiser »).
  - **`?_partial=sessions`** : réponse **HTML fragment** (lignes du tableau uniquement), servie après `GET` avec en-tête **`X-Requested-With: XMLHttpRequest`** ; le rendu est factorisé dans [`includes/sessions_list_table_body.php`](/var/www/html/includes/sessions_list_table_body.php). Le JS met à jour **`#sessionsTableBody`** avec **`innerHTML`** (sans parser une page complète).
- **`includes/mikrotik_backend.php` — `mikrotikProfileDataQuotaMb()`**
  - Quota data : d’abord **`limit-bytes-total`** du profil RouterOS ; si absent ou ≤ 0, **repli** sur le quota **`data_quota_mb`** extrait du script **`on-login`** via **`parseMikrotikOnLoginMetadata()`** (aligné Mikhmon).
- **UI recharge** ([`pages/user_recharge.php`](/var/www/html/pages/user_recharge.php)) : libellés prévisionnels **Time limite** / **Data limite** pour les projections (état actuel vs prévision reste distinct).
- **Recharge — backend et contrat**
  - **Prévision** : [`includes/recharge_preview_service.php`](/var/www/html/includes/recharge_preview_service.php) (`buildRechargePreview`) ; pas de dépendance aux champs « preview » en POST pour l’application (recalcul côté serveur).
  - **Façade** : [`includes/RechargeService.php`](/var/www/html/includes/RechargeService.php) — `simulate` → prévisualisation ; résolution profil **profile_name** (MikroTik) / **profile_id** (base) dans les endpoints ; utilisé par [`api/users/apply_recharge.php`](/var/www/html/api/users/apply_recharge.php).
  - **Historique** : persistance via [`includes/recharge_history_store.php`](/var/www/html/includes/recharge_history_store.php) ; enregistrements **historique local** et **journal d’opérations** en **non-bloquant** dans `apply_recharge.php` (échecs logués, recharge MikroTik / transaction RADIUS déjà validée).
  - **Options profils** : [`api/users/recharge_options.php`](/var/www/html/api/users/recharge_options.php) expose `profile_id` / `profile_name` par entrée ; le formulaire envoie `profile_id`, `profile_name` (champs cachés générés côté JS).
- **Recharge — JS** ([`js/user_recharge.js`](/var/www/html/js/user_recharge.js))
  - **Anti-spam réseau** : `schedulePreviewLoad` / `scheduleHistoryLoad` (debounce ~180 ms) ; signatures (`buildPreviewSignature` / `buildHistorySignature`) pour éviter les `fetch` redondants quand l’état n’a pas changé ; réinitialisation de l’historique après une recharge réussie.

---

## 1. Résumé global

### Auto-refresh / temps réel

| Mécanisme | Fichiers concernés | Verdict | Anomalies observées |
|-----------|-------------------|---------|---------------------|
| **SSE (`EventSource`)** | `js/dashboard.js` → `../api/device_stream.php` ; `js/traffic_monitoring.js` → `../api/traffic_stream.php` | Attendu pour **dashboard** et **surveillance trafic** | Pas d’anomalie bloquante à ce stade, mais coexistence avec du polling dans certains cas. |
| **`setInterval` (polling)** | `js/dashboard.js` (stats 45 s, métriques live OPN 2 s) ; `js/traffic_monitoring.js` (domaines DNS OPN, fréquence configurable, **minimum 2 s**) ; `pages/user_logs.php` inline (reload **30 s**, optionnel) | Dashboard / traffic : cohérent avec du temps réel. **User logs** : conforme à une option ≥ 30 s si activée. **`sessions_list`** : voir § intro — **plus de polling** (constat initial archivé : fetch partiel ~10 s). | **Constat initial (corrigé) :** polling sur `sessions_list` — **retiré**. Anomalies secondaires : polling **2 s** sur dashboard OPN et traffic OPN potentiellement agressif. |
| **`location.reload`** | Nombreux fichiers **après action utilisateur** (succès formulaire, bouton actualiser, etc.) — **pas** du polling automatique | Hors périmètre « auto-refresh nuisible » sauf si confondu avec les cas ci-dessus | Pas d’anomalie prioritaire ici ; à surveiller uniquement si utilisé comme pseudo-refresh récurrent. |

**Constat principal :** les **rafraîchissements automatiques récurrents** (hors rechargements post-action) se concentrent sur **dashboard**, **traffic_monitoring** et **user_logs** (optionnel). **`sessions_list`** n’utilise plus d’intervalle automatique (voir § intro).

### Backend (unifié vs page)

- **`buildAppContext()`** est défini dans `includes/app_context.php` et appelé **systématiquement** dans `includes/sidebar.php` pour tout écran qui inclut la barre latérale.
- Seules **certaines pages** appellent en plus **`buildAppContext()` explicitement** dans leur propre fichier : `dashboard.php`, `users_list.php`, `add_profile.php`, `user_recharge.php`, `add_hotspot_user.php`, `profile_list.php`.
- Fichiers **`includes/*_backend.php` recensés dans le dépôt :** `user_logs_backend.php`, `mikrotik_backend.php` uniquement.
- Beaucoup de pages combinent **`mikrotik_backend.php`** et/ou **`device_manager`** avec **requêtes SQL ou logique métier dans le fichier page** → classification **hybride (B)** ou **indépendante (C)** selon le poids de la logique dans la vue.

**Anomalies observées conservées :**
- Trop peu de backends dédiés.
- Trop de logique métier dans `pages/*.php`.
- Usage de `buildAppContext()` non homogène entre pages.

### Messages / notifications (aperçu)

- **Côté PHP :** `includes/message.php` expose **`set_message()`** / **`display_message()`** (session → bloc **`alert` Bootstrap** avec `id="messageArea"`). Les pages qui affichent des retours serveur mélangent ce mécanisme avec des **`<div class="alert …">` inline** dans le HTML.
- **Côté JavaScript :** cohabitation de **`window.alert()`**, **`confirm()`**, zones DOM dédiées (**`#messageArea`**, fonctions du type **`showError()`** dans `dashboard.js` / `traffic_monitoring.js`), et **alertes Bootstrap** construites en JS.

**Anomalie globale conservée :**
- Pas de contrat unique : titres, durée d’affichage, niveaux, accessibilité et comportement varient selon les écrans.

---

## 2. Regroupement des tâches par axes

### Axe A — Synchronisation / auto-refresh
**Objectif :** conserver le temps réel uniquement là où il est justifié, et supprimer ou rendre optionnel le polling inutile.

**Anomalies rattachées à cet axe :**
- ~~`sessions_list.php` : polling fixe **10 s**~~ — **traité** : actualisation manuelle uniquement
- `traffic_monitoring.js` : minimum **2 s**
- `dashboard.js` : métriques live OPN **2 s**
- `user_logs.php` : acceptable seulement si l’option reste désactivée par défaut

**Pages / fichiers concernés :**
- `pages/sessions_list.php`
- `js/traffic_monitoring.js`
- `js/dashboard.js`
- `pages/user_logs.php`

---

### Axe B — Backend / séparation vue-métier
**Objectif :** réduire puis supprimer la logique métier et SQL dans `pages/*.php`.

**Anomalies rattachées à cet axe :**
- Pages **type C** fortement couplées
- Pages **type B** hybrides avec SQL encore dans la vue
- Seulement deux backends recensés (`user_logs_backend.php`, `mikrotik_backend.php`)

**Pages prioritaires :**
- `pages/reports.php`
- `pages/recouvrement.php`
- `pages/administration.php`
- `pages/generate.php`
- `pages/hotspot_vouchers.php`

**Pages secondaires :**
- `pages/users_list.php`
- `pages/profile_list.php`
- `pages/add_profile.php`
- `pages/system_info.php`
- `pages/sessions_list.php`

---

### Axe C — Messages / notifications
**Objectif :** unifier tous les messages utilisateur dans un seul contrat PHP + JS.

**Anomalies rattachées à cet axe :**
- `alert()` natif encore présent
- `confirm()` natif encore présent
- coexistence entre `set_message()`, HTML inline, `messageArea`, `showError()`
- mapping des types non garanti sur tous les écrans

**Cibles transversales :**
- `includes/message.php`
- tous les JS utilisant `alert()` / `confirm()`
- pages injectant directement des alertes Bootstrap inline

---

## 3. Pages et mécanismes d’auto-refresh (détail + plan)

Légende **type de refresh :** `interval` = `setInterval` ; `sse` = Server-Sent Events ; `ajax` = `fetch` récurrent ; `reload` = rechargement page complet.

| Fichier | Type actuel | Fréquence / mécanisme | Verdict audit | Anomalies observées | Action planifiée | Priorité |
|---------|-------------|-----------------------|---------------|---------------------|------------------|----------|
| `js/dashboard.js` | `sse` + `interval` | SSE continu ; stats **45 000 ms** ; live OPN **2 000 ms** | OK pour dashboard | polling live **2 s** potentiellement agressif ; possible duplication avec SSE selon donnée | conserver le temps réel, vérifier charge, supprimer les redondances, garder une seule source par donnée | Moyenne |
| `js/traffic_monitoring.js` | `sse` + `interval` | SSE trafic ; domaines : **min 2 000 ms** | OK pour trafic temps réel | minimum **2 s** potentiellement trop bas | relever le minimum, documenter le besoin réel, éviter le polling redondant avec SSE | Haute |
| `pages/user_logs.php` | `interval` → `reload` | **30 000 ms** si activé | OK si optionnel | risque seulement si activé par défaut | conserver, vérifier OFF par défaut | Basse |
| `pages/sessions_list.php` | `ajax` (manuel) | clic « Actualiser » → `fetch` `?_partial=sessions` | OK aligné politique | *(historique : polling 10 s)* | maintenir l’absence de `setInterval` sur cette page | — |

### Règle cible SYNC
- **Autorisé automatiquement :**
  - `dashboard.php`
  - `traffic_monitoring.php`
- **Autorisé seulement en option :**
  - `user_logs.php` avec **≥ 30 s**
- **Interdit par défaut :**
  - toutes les autres pages — **`sessions_list.php` respecte cette règle** (pas d’auto-refresh récurrent)

---

## 4. Classification des pages (`pages/*.php`) — statut + regroupement

### 4.1 Pages de type A — déjà les plus proches de la cible
| Page | Statut | Anomalies observées | Action |
|------|--------|---------------------|--------|
| `dashboard.php` | **A** | temps réel à surveiller côté fréquence OPN | tuning uniquement |
| `user_logs.php` | **A** | vérifier OFF par défaut pour l’auto-refresh | contrôle léger |

---

### 4.2 Pages de type B — hybrides à nettoyer
| Page | Statut | Anomalies observées | Action prioritaire |
|------|--------|---------------------|--------------------|
| `traffic_monitoring.php` | **B** | logique device dans la vue ; fréquence côté JS à encadrer | nettoyage JS puis backend léger |
| `users_list.php` | **B** | SQL + backend dans la page | extraction backend |
| `add_profile.php` | **B** | logique formulaire + SQL dans la page | extraction backend formulaire |
| `profile_list.php` | **B** | SQL + rendu mêlés | extraction backend |
| `add_hotspot_user.php` | **B** | dépendance API/JS, structure à confirmer | revue secondaire |
| `user_recharge.php` | **B** | dépendance API/JS | revue secondaire |
| `sessions_list.php` | **B** | SQL + logique dans la page (backend partiellement extrait) ; ~~polling 10 s~~ **retiré** | poursuivre extraction backend si besoin |
| `scheduler.php` | **B** | chargement de données encore côté page | nettoyage léger |
| `dhcp_leases.php` | **B** | architecture mixte, mais pas critique | maintien puis revue |
| `add_dhcp_lease.php` | **B** | formulaire couplé au flux existant | revue secondaire |
| `system_log.php` | **B/C** | frontière vue/métier encore floue | clarifier avant refactor |
| `ip_bindings.php` | **B** | backend mixte | revue secondaire |
| `hosts.php` | **B** | logique actions + reloads post-action | standardiser messages |
| `cookies.php` | **B** | même famille que hosts | standardiser messages |
| `add_scheduler.php` | **B** | post-action UX ad hoc | standardiser |
| `add_ip_binding.php` | **B** | logique formulaire à isoler | revue secondaire |
| `system_info.php` | **B** | compteurs SQL dans la page | extraction légère |
| `network_devices.php` | **B** | dépend fortement du JS/API | stabilisation |
| `freeradius.php` | **B** | structure proche B, sans gros bloc SQL vu ici | revue secondaire |
| `recouvrement_invoices.php` | **B** | proche A/B, à consolider | faible priorité |
| `print_recouvrement_invoice.php` | **B** | mode impression autonome à documenter | faible priorité |

---

### 4.3 Pages de type C — migration backend prioritaire
| Page | Statut | Anomalies observées | Action prioritaire |
|------|--------|---------------------|--------------------|
| `reports.php` | **C** | SQL + logique métier lourde dans la page | extraire backend dédié |
| `recouvrement.php` | **C** | SQL, agrégations, logique métier volumineuse | extraire backend dédié |
| `hotspot_vouchers.php` | **C** | SQL + synchronisation dans la page | extraire backend dédié |
| `generate.php` | **C** | transactions / génération dans la page | extraire backend dédié |
| `admin_notifications.php` | **C** | SQL + actions dans la page | extraire backend dédié |
| `administration.php` | **C** | SQL + POST + logique admin dans la page | extraire backend dédié |
| `print_vouchers.php` | **C** | logique impression + SQL dans la page | isoler la logique |
| `about.php` | **C** | SQL / migrations `exec` dans la page | déplacer la logique hors vue |

---

## 5. Messages et notifications — état observé + cible

### 5.1 Mécanismes observés
| Canal | Où | Anomalies observées |
|-------|-----|---------------------|
| **Session PHP** | `includes/message.php` | base utile, mais non unique dans l’application |
| **HTML inline** | `pages/*.php` | duplication avec d’autres mécanismes |
| **`alert()` navigateur** | divers `js/*.js` | bloque l’UI, non harmonisé |
| **`confirm()` navigateur** | divers `js/*.js` | pas de modal unifiée |
| **Zones dynamiques** | `dashboard.js`, `traffic_monitoring.js`, etc. | comportements hétérogènes |

### 5.2 Contrat cible
#### Backend PHP
- garder `set_message()` / `display_message()` comme base de transition
- normaliser strictement les types :
  - `success`
  - `danger`
  - `warning`
  - `info`

#### Frontend JS
Créer un module partagé, par exemple `js/notifications.js`, exposant :
- `notifySuccess(text)`
- `notifyError(text)`
- `notifyWarning(text)`
- `notifyInfo(text)`
- `confirmAction(...)` via modal Bootstrap

### 5.3 Actions planifiées
| Action | Ce qui est gardé | Ce qui doit disparaître | Priorité |
|--------|------------------|--------------------------|----------|
| Standardiser les messages PHP | `set_message()` / `display_message()` | alertes inline doublons | Moyenne |
| Standardiser les messages JS | zones Bootstrap / composant unique | `alert()` | Haute |
| Standardiser les confirmations | modale unique | `confirm()` | Haute |
| Harmoniser l’accessibilité | `role="alert"` et focus clavier | comportements dispersés | Moyenne |

---

## 6. Plan d’exécution regroupé

### Lot 1 — immédiat
| Fichier | Problème principal | Anomalie conservée | Action |
|---------|--------------------|--------------------|--------|
| `pages/sessions_list.php` | page hybride (sessions via `sessions_backend.php`) | SQL/vue encore mêlés côté page | ~~polling : retiré~~ ; poursuivre consolidation backend |
| `js/traffic_monitoring.js` | fréquence mini 2 s | polling potentiellement agressif | relever minimum ; supprimer redondances |
| `js/dashboard.js` | fréquence live OPN 2 s | charge potentielle | valider/tuner sans casser le temps réel légitime |
| `pages/user_logs.php` | option auto-refresh | acceptable si OFF par défaut | vérifier et verrouiller le comportement |

### Lot 2 — backend lourd à extraire
| Fichier | Anomalie conservée | Action |
|---------|--------------------|--------|
| `pages/reports.php` | logique métier + SQL dans la page | créer backend dédié |
| `pages/recouvrement.php` | agrégations et SQL lourds | créer backend dédié |
| `pages/administration.php` | logique admin dans la page | créer backend dédié |
| `pages/generate.php` | transactions / génération dans la page | créer backend dédié |
| `pages/hotspot_vouchers.php` | SQL + logique dans la page | créer backend dédié |

### Lot 3 — hybrides à nettoyer
| Fichier | Anomalie conservée | Action |
|---------|--------------------|--------|
| `pages/users_list.php` | SQL dans la page | extraction backend |
| `pages/profile_list.php` | SQL dans la page | extraction backend |
| `pages/add_profile.php` | logique formulaire + SQL | extraction backend |
| `pages/system_info.php` | compteurs SQL dans la page | extraction légère |

### Lot 4 — messages / notifications
| Zone | Anomalie conservée | Action |
|------|--------------------|--------|
| `includes/message.php` + JS/pages | plusieurs systèmes parallèles | définir et appliquer un contrat unique |

---

## 7. Plan d’actions (checklist)

### 7.0 Mise à jour après travaux préparatoires (état au dépôt — 2026-04)

**Statut de cette section :**

- Une partie des items ci-dessous est **déjà intégrée** (sessions : JS extrait, backend `sessions_backend.php`, partial HTML, **pas de polling**).
- D’autres restent des **chantiers ouverts** (notifications globales, migration backend des pages lourdes).
- Le périmètre `sessions_list` a été **traité avec priorité MikroTik** pour le chargement ; OPNsense reste partiellement factorisé.

### 7.0.1 Ce qui a été préparé

- [x] **Backend unifié préparé pour `sessions_list.php`**
  - **Fait dans les diffs Cursor :**
    - extraction des helpers de formatage hors de la page vers **`includes/session_formatters.php`**
    - extraction du chargement des sessions vers **`includes/sessions_backend.php`**
    - conservation de la source de vérité device via **`getActiveDeviceContext()`**
    - réutilisation de l’existant **MikroTik** via **`getMikrotikHotspotActiveUsers()`**
  - **Important :**
    - le périmètre réellement visé est **MikroTik en priorité**
    - la branche OPNsense n’a pas été unifiée métier, elle a seulement été **déplacée hors de la page** dans le diff préparé

- [x] **Séparation JS / HTML préparée pour `sessions_list.php`**
  - **Fait dans les diffs Cursor :**
    - sortie du JavaScript inline de la page vers **`js/pages/sessions_list.js`**
    - injection minimale d’une config front via **`window.SESSIONS_LIST_CONFIG`**
    - conservation du comportement existant (filtres, colonnes, refresh, déconnexion)

- [x] **Unification minimale des commandes front préparée**
  - **Fait dans les diffs Cursor :**
    - regroupement des actions dans **`SessionsCommands`**
    - renommage logique du refresh vers **`refreshSessions()`**
    - centralisation des commandes utilisateur sans changement backend supplémentaire

- [x] **Remplacement préparé de `alert()` / `confirm()`**
  - **Fait dans les diffs Cursor :**
    - remplacement de `alert()` par **`showToast()`**
    - encapsulation de la confirmation dans **`showConfirm()`**
  - **Limite :**
    - il ne s’agit pas encore d’un vrai système global de notifications partagé par toute l’application

- [x] **Nettoyage CSS ciblé préparé pour `sessions_list.css`**
  - **Fait dans les diffs Cursor :**
    - suppression de doublons locaux déjà couverts par **`theme.css`**
    - conservation des règles réellement spécifiques à la page
  - **Important :**
    - **`theme.css` n’a pas encore été allégé structurellement**
    - le nettoyage global CSS reste un chantier séparé

### 7.0.2 Ce qui reste à suivre

- [ ] **Valider / appliquer réellement les diffs Cursor**
  - vérifier que les patchs ont bien été intégrés dans le dépôt
  - vérifier que l’ancien code a bien été supprimé, sans logique parallèle restante

- [ ] **Tester fonctionnellement `sessions_list.php` après application**
  - affichage des sessions MikroTik
  - filtres
  - masquage / affichage des colonnes
  - déconnexion de session
  - rafraîchissement automatique
  - rendu CSS

- [x] **Polling automatique sur `sessions_list`**
  - **retiré** ; actualisation **manuelle** uniquement

- [~] **Endpoint `?_partial=sessions`**
  - réponse **fragment HTML** (lignes du tableau) + **`includes/sessions_list_table_body.php`** — plus de parse DOM d’une page entière côté JS
  - une **API JSON** dédiée reste une évolution possible si le couplage URL de page doit disparaître

- [ ] **Étendre ou non le backend unifié au-delà de MikroTik**
  - OPNsense dispose d’une **base technique partiellement factorisée**
  - mais pas encore d’un **backend métier unifié**

- [ ] **Standardiser les messages à l’échelle de l’application**
  - le remplacement `showToast()` / `showConfirm()` n’est pour l’instant qu’un traitement local à `sessions_list`
  - la standardisation PHP + JS globale reste à faire

- [~] **`sessions_list.php`**
  - **Problème initial :** polling ~**10 s** — **corrigé** (refresh manuel).
  - **Fait :** `sessions_backend.php`, `session_formatters.php`, `sessions_list_table_body.php`, `js/pages/sessions_list.js`, partial `?_partial=sessions`.
  - **Reste :** consolidation backend / tests régression selon besoin.
  - **Priorité :** **moyenne** (hors polling)

- [ ] **`js/traffic_monitoring.js`**
  - **Anomalie conservée :** minimum **2 s**
  - **Action :** relever le minimum, conserver SSE, supprimer tout doublon inutile
  - **Priorité :** **haute**

- [ ] **`js/dashboard.js`**
  - **Anomalie conservée :** métriques live OPN en **2 s**
  - **Action :** conserver le temps réel mais vérifier la charge et supprimer les redondances
  - **Priorité :** **moyenne**

- [ ] **`pages/user_logs.php`**
  - **Anomalie conservée :** dépend du statut par défaut de l’option
  - **Action :** garder la structure actuelle ; confirmer OFF par défaut
  - **Priorité :** **basse**

- [ ] **`reports.php`**
  - **Anomalie conservée :** SQL + métier dans la page
  - **Action :** migrer vers `includes/reports_backend.php`
  - **Priorité :** **très haute**

- [ ] **`recouvrement.php`**
  - **Anomalie conservée :** SQL + agrégations lourdes dans la page
  - **Action :** migrer vers `includes/recouvrement_backend.php`
  - **Priorité :** **très haute**

- [ ] **`administration.php`**, **`generate.php`**, **`hotspot_vouchers.php`**, **`admin_notifications.php`**, **`about.php`**
  - **Anomalie conservée :** forte logique métier / SQL / traitements dans la page
  - **Action :** extraire vers backends dédiés
  - **Priorité :** **haute**

- [ ] **`users_list.php`**, **`profile_list.php`**, **`add_profile.php`**, **`system_info.php`**
  - **Anomalie conservée :** modèle hybride
  - **Action :** centraliser requêtes et règles dans des includes testables
  - **Priorité :** **moyenne**

- [~] **Standardisation des messages (transversal)**
  - **Problème :** **plusieurs canaux** (`set_message`, alertes inline, `alert()` / `confirm()`, `messageArea` / `showError`) sans contrat commun — expérience incohérente et maintenance difficile.
  - **Action :**
    - **préparé localement pour `sessions_list`** : remplacement de `alert()` par `showToast()` et encapsulation de la confirmation dans `showConfirm()`
    - **reste à faire** : définir l’**API unique** (PHP + JS) décrite en **§5.2** ; migrer progressivement les autres écrans ; vérifier la **correspondance des types** avec les classes Bootstrap / le thème.
  - **Backend unifié :** partiel (surtout **couche présentation** + helpers) ; les messages restent souvent des retours des API existantes.
  - **Priorité :** **moyenne** à **haute** (UX globale)

---

## 8. Synthèse exécutive

1. **Polling inutile ou discutable :** ~~**`sessions_list.php` (10 s)** — corrigé~~ ; reste **intervalles courts (2 s)** sur **dashboard OPN** et **domaines OPN** dans **traffic monitoring**.
2. **Structure backend :** seuls **`user_logs_backend.php`** et **`mikrotik_backend.php`** portent le suffixe `*_backend.php` ; de nombreuses pages restent **hybrides ou chargées en SQL** — **`reports.php`**, **`recouvrement.php`**, **`administration.php`** sont des **points sensibles**.
3. **Prochaines phases :** appliquer les **SYNC RULES** (désactiver ou optionnaliser le polling hors dashboard/traffic), puis migrer les pages **C** (et **B** lourds) vers des **includes/services** alignés sur **`buildAppContext()`** explicite où le métier l’exige.
4. **Messages et notifications :** traiter la **standardisation** (§5) comme un chantier **transversal** : réduire **`alert()` / `confirm()`** au profit d’un **composant unique** documenté, et aligner les **types** et **canaux** (session PHP vs AJAX).

### Mise à jour de synthèse — itération Cursor préparée

5. **`sessions_list.php` :** refactor **déployé** (helpers, `sessions_backend.php`, tbody partagé, JS dédié, partial HTML, **sans polling automatique**).
6. **Le chargement sessions côté MikroTik** reste la branche la plus structurée ; OPNsense est partiellement factorisé.
7. **Points ouverts documentés ailleurs :** notifications globales, JSON dédié pour remplacer un partial basé sur la page si souhaité, extension backend pour d’autres équipements.

---

## 9. Conclusion

Cette mise à jour conserve volontairement les **anomalies observées dans chaque section**, mais les rattache désormais à un **plan d’action regroupé**, exploitable comme feuille de route.

Le document peut maintenant servir à la fois :
- d’**audit de référence**
- de **backlog de refactor**
- de **support de priorisation**

---

*Fin du document d’audit mis à jour.*
