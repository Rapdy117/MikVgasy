# Système de Licence & Intégrité — Radius Manager

## Architecture générale

```
Toi (développeur)                    Client (installation locale)
─────────────────────────────        ─────────────────────────────
Installation privée complète         Package distribué (sans tools/)
  ├── includes/license.php            ├── includes/license.php
  ├── includes/integrity_check.php    ├── includes/integrity_check.php
  ├── includes/nas_resolver.php       ├── includes/nas_resolver.php
  ├── tools/install.php (émission superadmin)  ├── config/integrity.json
  ├── tools/generate_signatures.php             └── ... (reste de l'app)
  └── config/integrity.json
```

---

## Fonctionnement de la licence

### Flux complet

```
1. Client installe le pack (Windows / Linux + Apache + MySQL)
2. Ouvre l'app dans le navigateur
3. Va dans Network Devices → Ajoute son routeur
4. Clique "Tester la connexion"
   → Affiche : Type / N° Série / Modèle / Device ID
5. Sauvegarde → routeur visible mais INACTIF
6. Panneau licence affiché avec 3 options de contact :
   - WhatsApp (pré-rempli avec Device ID + SN + Type)
   - Email (pré-rempli)
   - Copier le message
7. Client t'envoie le message
8. Le superadmin émet la clé depuis `tools/install.php`
9. La clé est conservée dans SQL (`device_licenses`)
10. Le client colle la clé dans le panneau → activation contrôlée ✅
```

### Ce qui est bloqué sans licence

| Action | Sans licence |
|--------|-------------|
| Voir les pages / navigation | ✅ Autorisé |
| Lister les utilisateurs | ✅ Autorisé |
| Tester la connexion | ✅ Autorisé |
| Sync / Créer / Modifier utilisateurs | ❌ Bloqué |
| Import / Export | ❌ Bloqué |
| Monitoring trafic | ❌ Bloqué |
| Sessions actives | ❌ Bloqué |
| Toute opération backend NAS | ❌ Bloqué |

---

## Générer une clé de licence

### Émission et activation

L’émission d’une licence se fait exclusivement depuis `tools/install.php`, section **Outils système → Licences devices**, réservée au superadmin. La clé et son statut sont enregistrés dans SQL (`device_licenses`), puis l’activation est contrôlée par `api/license/activate_license.php`.

Les scripts historiques `tools/generate_license.php` et `tools/license_manager.php` sont archivés en `.old` et ne sont plus chargés par le runtime.

---

## Sécurité — Points importants

### Secret HMAC
Défini dans `includes/license.php` (constantes `_RM_L1`, `_RM_L2`, `_RM_L3`).
Ce secret signe à la fois les **clés de licence** et les **signatures d'intégrité**.
Ne jamais le modifier après la première distribution — cela invaliderait toutes les licences existantes.

### Chaîne de protection
```
integrity.json (signatures HMAC)
      ↓ vérifié par
includes/integrity_check.php
      ↓ appelé par
includes/license.php → requireDeviceLicensed()
      ↓ appelé par
includes/nas_resolver.php → resolveNasContextFromInputs()
      ↓ point d'entrée de
Toutes les opérations backend
```

### Niveau de protection
| Scénario | Protection |
|----------|-----------|
| Client lambda | ✅ Totalement protégé |
| Technicien curieux | ✅ Protégé (effort dissuasif) |
| Développeur PHP motivé | ⚠️ Peut analyser le code source |
| Option renforcée | ionCube / Zend Guard (fichiers chiffrés) |

---

## Notifications — Demande de licence

### Canal Email (automatique)
Configuré dans **Administration > Notifications** :
- SMTP host / port / user / password
- Email expéditeur / destinataire

### Canal WhatsApp (manuel par le client)
Le client clique un bouton → WhatsApp s'ouvre avec le message pré-rempli :
```
Bonjour,

Je souhaite activer mon routeur.

Device ID : MK-825F-E5EE-93FE
N° Série  : HGB09VM5CAF
Type      : MikroTik
Modèle    : L009UiGS-2HaxD
Nom       : Jean Dupont

Merci.
```

> Aucune API WhatsApp côté serveur — fonctionne quel que soit
> le volume d'installations simultanées.

**Changer de numéro WhatsApp :**
→ Administration > Notifications > Votre numéro WhatsApp
→ Modifier le champ → Enregistrer
→ Rien d'autre à toucher.

---

## Futur prévu

- **whatsapp-web.js** : Notifications automatiques pour les rappels
  de renouvellement clients mensuels (à implémenter).
- **Obfuscation du secret HMAC** : Rendre le secret plus difficile
  à lire dans le source distribué.


## Etat SQL canonique (2026-07-22)

La table `device_licenses` est la source de verite du cycle generation -> activation -> expiration -> renouvellement. Chaque ligne est liee a un `device_id` et a son fingerprint.

Le secret HMAC doit etre configure par `RADIUS_MANAGER_LICENSE_SECRET` dans l environnement VPS. Toute rotation invalide les cles existantes ; il faut donc le conserver pendant toute la vie de l installation.


### Gestion depuis l’application

La page superadmin `/pages/device_licenses.php` propose uniquement deux durées : `lifetime` (à vie), `annual` (un an) et `test` (31 jours). Chaque licence conserve le nom, le contact et l’e-mail du détenteur dans SQL, affiche son historique et permet une suppression contrôlée.
