# Rapport D'Analyse Du Projet

J'ai analyse le projet sans modifier de fichier. J'ignore ici `phpmyadmin/` qui ressemble a un composant externe et je me concentre sur l'application sous `/var/www/html`.

## 1. Fichiers Importants

### Backend principal

- Entree login : [index.php](/var/www/html/index.php), [api_proxy.php](/var/www/html/api_proxy.php)
- Connexions/config : [config/db.php](/var/www/html/config/db.php), [config/config.php](/var/www/html/config/config.php), [config/radius.php](/var/www/html/config/radius.php), [config/schema.sql](/var/www/html/config/schema.sql), [config/opnsense.json](/var/www/html/config/opnsense.json), [config/radius.json](/var/www/html/config/radius.json)
- Includes metier : [includes/sidebar.php](/var/www/html/includes/sidebar.php), [includes/message.php](/var/www/html/includes/message.php), [includes/radius_sync.php](/var/www/html/includes/radius_sync.php)
- API utilisateurs : [api/users/create_user.php](/var/www/html/api/users/create_user.php), [api/users/update_user.php](/var/www/html/api/users/update_user.php), [api/users/delete_user.php](/var/www/html/api/users/delete_user.php), [api/users/get_user_sessions.php](/var/www/html/api/users/get_user_sessions.php)
- API profils/NAS : [api/profiles/create_profile.php](/var/www/html/api/profiles/create_profile.php), [api/nas.php](/var/www/html/api/nas.php)
- API OPNsense/RADIUS : [api/network_devices_api.php](/var/www/html/api/network_devices_api.php), [api/test_opnsense.php](/var/www/html/api/test_opnsense.php), [api/test_radius.php](/var/www/html/api/test_radius.php), [api/disconnect_session.php](/var/www/html/api/disconnect_session.php), [api/get_sessions.php](/var/www/html/api/get_sessions.php), [api/get_stats.php](/var/www/html/api/get_stats.php)

### Frontend principal

- Pages metier : [pages/dashboard.php](/var/www/html/pages/dashboard.php), [pages/users_list.php](/var/www/html/pages/users_list.php), [pages/add_hotspot_user.php](/var/www/html/pages/add_hotspot_user.php), [pages/add_profile.php](/var/www/html/pages/add_profile.php), [pages/profile_list.php](/var/www/html/pages/profile_list.php), [pages/network_devices.php](/var/www/html/pages/network_devices.php), [pages/freeradius.php](/var/www/html/pages/freeradius.php), [pages/sessions_list.php](/var/www/html/pages/sessions_list.php)
- JS : [js/users_list.js](/var/www/html/js/users_list.js), [js/add_hotspot_user.js](/var/www/html/js/add_hotspot_user.js), [js/select_nas.js](/var/www/html/js/select_nas.js), [js/network_device.js](/var/www/html/js/network_device.js), [js/freeradius.js](/var/www/html/js/freeradius.js), [js/dashboard.js](/var/www/html/js/dashboard.js), [js/sidebar.js](/var/www/html/js/sidebar.js)
- Styles : [pages/dashboard.css](/var/www/html/pages/dashboard.css), [css/users_list.css](/var/www/html/css/users_list.css), [css/add_hostpot_user.css](/var/www/html/css/add_hostpot_user.css), [css/add_profile.css](/var/www/html/css/add_profile.css), [css/network_devices.css](/var/www/html/css/network_devices.css)

## 2. Relations Entre Fichiers

- [index.php](/var/www/html/index.php) poste vers [api_proxy.php](/var/www/html/api_proxy.php).
- [api_proxy.php](/var/www/html/api_proxy.php) inclut [config/config.php](/var/www/html/config/config.php), cree `$_SESSION['logged_in']`, puis redirige vers `dashboard/dashboard.php`, alors que le vrai dashboard semble etre [pages/dashboard.php](/var/www/html/pages/dashboard.php). C'est une incoherence de routage.
- Presque toutes les pages `pages/*.php` font `session_start()` puis incluent [includes/sidebar.php](/var/www/html/includes/sidebar.php). Certaines incluent aussi [includes/message.php](/var/www/html/includes/message.php).
- [pages/add_profile.php](/var/www/html/pages/add_profile.php) soumet directement vers [api/profiles/create_profile.php](/var/www/html/api/profiles/create_profile.php) et charge [js/select_nas.js](/var/www/html/js/select_nas.js), qui appelle [api/nas.php](/var/www/html/api/nas.php).
- [api/profiles/create_profile.php](/var/www/html/api/profiles/create_profile.php) inclut [config/db.php](/var/www/html/config/db.php) et [includes/radius_sync.php](/var/www/html/includes/radius_sync.php).
- [pages/add_hotspot_user.php](/var/www/html/pages/add_hotspot_user.php) charge [js/add_hotspot_user.js](/var/www/html/js/add_hotspot_user.js), qui appelle `../api/create_user.php`. Ce fichier n'existe pas; le vrai endpoint est [api/users/create_user.php](/var/www/html/api/users/create_user.php). Le formulaire ne peut donc pas fonctionner tel quel.
- [pages/users_list.php](/var/www/html/pages/users_list.php) charge les utilisateurs depuis SQL via [config/db.php](/var/www/html/config/db.php), puis [js/users_list.js](/var/www/html/js/users_list.js) appelle [api/users/get_user_sessions.php](/var/www/html/api/users/get_user_sessions.php).
- [pages/network_devices.php](/var/www/html/pages/network_devices.php) charge [js/network_device.js](/var/www/html/js/network_device.js), qui lit/ecrit [api/network_devices_api.php](/var/www/html/api/network_devices_api.php) et teste les equipements via [api/test_opnsense.php](/var/www/html/api/test_opnsense.php).
- [pages/freeradius.php](/var/www/html/pages/freeradius.php) charge [js/freeradius.js](/var/www/html/js/freeradius.js), qui sauvegarde via [config/radius.php](/var/www/html/config/radius.php) et teste via [api/test_radius.php](/var/www/html/api/test_radius.php).
- [pages/dashboard.php](/var/www/html/pages/dashboard.php) charge [js/dashboard.js](/var/www/html/js/dashboard.js), qui appelle `api/get_stats.php`. Or depuis `/pages/`, ce chemin relatif pointe vers `/pages/api/get_stats.php`, pas vers [api/get_stats.php](/var/www/html/api/get_stats.php). Appel probablement casse.

## 3. Flux De Donnees

- Login : formulaire HTML dans [index.php](/var/www/html/index.php) -> POST vers [api_proxy.php](/var/www/html/api_proxy.php) -> verification contre identifiants hardcodes -> creation session PHP -> redirection.
- Creation profil : formulaire dans [pages/add_profile.php](/var/www/html/pages/add_profile.php) -> `profile_name`, `nas_id`, `session_timeout`, `idle_timeout`, `data_limit`, `rate_limit`, `simultaneous_use` -> [api/profiles/create_profile.php](/var/www/html/api/profiles/create_profile.php) -> lecture type du NAS dans table `nas` -> insertion table `profiles` -> transformation RADIUS dans [includes/radius_sync.php](/var/www/html/includes/radius_sync.php) -> insertion `radgroupreply`.
- Creation utilisateur : formulaire dans [pages/add_hotspot_user.php](/var/www/html/pages/add_hotspot_user.php) -> JS -> endpoint attendu `api/create_user.php` inexistant. Si on suppose le bon endpoint, [api/users/create_user.php](/var/www/html/api/users/create_user.php) ecrit dans `users`, lit `profiles.name`, puis ecrit dans `radcheck`, `radusergroup`, `radreply`.
- Consultation utilisateur : [pages/users_list.php](/var/www/html/pages/users_list.php) fait un `SELECT users LEFT JOIN profiles`, hydrate les `data-*` HTML, puis [js/users_list.js](/var/www/html/js/users_list.js) remplit le panneau de detail et appelle [api/users/get_user_sessions.php](/var/www/html/api/users/get_user_sessions.php), qui lit `radacct`, calcule `data_mb`, detecte `online`, et renvoie JSON pour l'UI.
- Config equipements OPNsense : [pages/network_devices.php](/var/www/html/pages/network_devices.php) -> [js/network_device.js](/var/www/html/js/network_device.js) -> [api/network_devices_api.php](/var/www/html/api/network_devices_api.php) -> stockage fichier JSON [config/opnsense.json](/var/www/html/config/opnsense.json). Les tests passent par [api/test_opnsense.php](/var/www/html/api/test_opnsense.php) et cURL vers l'API OPNsense.
- Config FreeRADIUS : [pages/freeradius.php](/var/www/html/pages/freeradius.php) -> [js/freeradius.js](/var/www/html/js/freeradius.js) -> [config/radius.php](/var/www/html/config/radius.php) -> stockage [config/radius.json](/var/www/html/config/radius.json). Test d'auth via [api/test_radius.php](/var/www/html/api/test_radius.php) -> `shell_exec(radclient)`.

## 4. Fonctions Critiques

- Gestion des utilisateurs : le coeur est dans [api/users/create_user.php#L43](/var/www/html/api/users/create_user.php#L43), [api/users/update_user.php#L38](/var/www/html/api/users/update_user.php#L38), [api/users/delete_user.php#L18](/var/www/html/api/users/delete_user.php#L18), [api/users/get_user_sessions.php#L19](/var/www/html/api/users/get_user_sessions.php#L19). La logique metier repose sur une double ecriture `users` + tables FreeRADIUS. Toute evolution doit garder cette synchronisation.
- Profils : le coeur est dans [api/profiles/create_profile.php#L22](/var/www/html/api/profiles/create_profile.php#L22) et [includes/radius_sync.php#L36](/var/www/html/includes/radius_sync.php#L36). Un profil cree un groupe RADIUS et ses attributs techniques.
- `session_timeout` : present cote formulaire profil [pages/add_profile.php#L85](/var/www/html/pages/add_profile.php#L85) et utilisateur [pages/add_hotspot_user.php#L81](/var/www/html/pages/add_hotspot_user.php#L81). Il est effectivement synchronise pour les profils via `Session-Timeout` dans [includes/radius_sync.php#L45](/var/www/html/includes/radius_sync.php#L45), mais il n'est pas pris en compte dans [api/users/create_user.php](/var/www/html/api/users/create_user.php) ni [api/users/update_user.php](/var/www/html/api/users/update_user.php). Donc le champ existe en UI utilisateur, mais n'est pas persistant cote backend.
- Authentification : totalement locale et hardcodee dans [api_proxy.php#L20](/var/www/html/api_proxy.php#L20). Elle n'utilise ni DB, ni FreeRADIUS, ni OPNsense. La session PHP est le seul garde-fou d'acces.

## 5. Dependances Restantes Et Traces Historiques

- Trace MikroTik explicite : [includes/radius_sync.php#L26](/var/www/html/includes/radius_sync.php#L26), [api/users/create_user.php#L109](/var/www/html/api/users/create_user.php#L109), [api/users/update_user.php#L119](/var/www/html/api/users/update_user.php#L119) utilisent encore `Mikrotik-Rate-Limit`.
- Ancien code Mikhmon/MikroTik encore present : [includes/get_radius.php](/var/www/html/includes/get_radius.php) contient `$_SESSION["mikhmon"]`, `admin.php?id=login`, variables de session anciennes et structure totalement differente du projet actuel.
- Heritage structurel casse : [pages/hotspot_vouchers.php](/var/www/html/pages/hotspot_vouchers.php) contient deux pages concatenees dans un seul fichier, dont une seconde moitie dupliquee de l'ecran FreeRADIUS.
- Liens morts dans la sidebar : [includes/sidebar.php](/var/www/html/includes/sidebar.php) pointe vers `sessions_liste.php`, `administration.php`, `reboot.php`, `shutdown.php` qui ne sont pas presents.
- Le schema livre [config/schema.sql](/var/www/html/config/schema.sql) decrit les tables FreeRADIUS standards (`radacct`, `radcheck`, `radreply`, `nas`...), mais ne definit pas `users` ni `profiles`, alors qu'elles sont indispensables partout.

## 6. Risques

- Couplage fort `profiles.name` <-> `radusergroup.groupname` <-> `radgroupreply.groupname`. Changer la cle logique d'un profil casse la correspondance entre UI, table `profiles` et politique RADIUS.
- Les types sont incoherents ou implicites : `session_timeout` est saisi en minutes cote UI [pages/add_profile.php#L87](/var/www/html/pages/add_profile.php#L87), mais injecte tel quel comme attribut RADIUS sans conversion explicite; selon l'attendu RADIUS, ca peut etre en secondes.
- `data_limit` est utilise en MB pour les profils, converti en octets via `* 1024 * 1024` dans [includes/radius_sync.php#L54](/var/www/html/includes/radius_sync.php#L54), mais cote utilisateur on ecrit `Max-Data` brut dans [api/users/create_user.php#L121](/var/www/html/api/users/create_user.php#L121) sans conversion. Meme concept, deux unites possibles.
- Les champs du formulaire profil `description`, `validity_value`, `validity_unit`, `auto_renewal` existent en UI [pages/add_profile.php#L69](/var/www/html/pages/add_profile.php#L69) mais sont ignores par [api/profiles/create_profile.php](/var/www/html/api/profiles/create_profile.php). Risque de faux sentiment de persistance.
- Le formulaire utilisateur a deux champs `data_limit` distincts [pages/add_hotspot_user.php#L86](/var/www/html/pages/add_hotspot_user.php#L86) et [pages/add_hotspot_user.php#L185](/var/www/html/pages/add_hotspot_user.php#L185). En POST, PHP prend une valeur selon l'ordre; c'est fragile.
- L'edition utilisateur n'est pas connectee : [js/users_list.js#L122](/var/www/html/js/users_list.js#L122) ne fait qu'un `alert`. Toute modification supposee depuis cette page ne persiste pas.
- Plusieurs endpoints sont casses ou douteux : [js/add_hotspot_user.js#L38](/var/www/html/js/add_hotspot_user.js#L38), [js/dashboard.js#L12](/var/www/html/js/dashboard.js#L12), [api_proxy.php#L30](/var/www/html/api_proxy.php#L30), [api/test_radius.php#L29](/var/www/html/api/test_radius.php#L29).
- Securite faible : secrets OPNsense en clair dans [config/config.php](/var/www/html/config/config.php), DB credentials en clair dans [config/db.php](/var/www/html/config/db.php), SSL desactive, authentification hardcodee, presence de logs de debug en racine, CSRF genere mais non verifie cote API.

## Conclusion

Le projet a une base UI assez complete, mais la logique backend est partiellement branchee, avec un coeur reellement fonctionnel surtout autour de `profiles`, `nas`, `network devices` et de la lecture `users/radacct`. Les plus gros points faibles sont l'authentification, le decalage entre formulaires et API reelles, le schema SQL incomplet par rapport au code, et les restes MikroTik/Mikhmon encore meles a la version OPNsense/FreeRADIUS.
