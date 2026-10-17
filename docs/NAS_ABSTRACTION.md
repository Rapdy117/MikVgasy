# Abstraction NAS / Device

Mise a jour : 2026-07-22

La resolution suit obligatoirement : `nas_id -> nas.type -> backend logique -> device`.

Types fonctionnels supportes :

- `mikrotik` : execution RouterOS par `device_id` ;
- `radius` : NAS standard et projection FreeRADIUS ;
- `opnsense` : API OPNsense, actuellement en pause.

`device_id` identifie la cible technique ; `nas_id` identifie le backend logique. Le Device actif en session est un contexte d affichage et ne remplace jamais un `device_id` explicite dans une API metier.

Les resellers utilisent uniquement les Devices attribues par leur admin proprietaire.
