# PROJECT_INDEX_QUICK

Objectif: eviter de relire toute l arborescence a chaque ticket.

## Workflow court (obligatoire)

1. Lire `docs/project_rules.md`.
2. Lire le delta Git:
   - `git status --short`
   - `git diff --name-only`
3. Ouvrir seulement 3-5 fichiers cibles.
4. Elargir seulement si blocage.

## Fichiers de reference actifs (6 fichiers — tout le reste est archive)

| Fichier | Role | Etat |
|---|---|---|
| `docs/project_rules.md` | Regles canoniques, source unique, anti-fallback | ✅ ACTIF — prioritaire |
| `docs/PROJECT_INDEX_QUICK.md` | Ce fichier — point d'entree rapide | ✅ ACTIF |
| `docs/PROJECT_PLAN.md` | Roadmap, statuts chantiers (TODO/IN_PROGRESS/DONE) | ✅ ACTIF |
| `docs/KNOWN_ISSUES.md` | Bugs connus, problemes ouverts | ✅ ACTIF |
| `docs/DECISIONS.md` | Decisions techniques deduites du code | ✅ ACTIF |
| `docs/ARCHITECTURE.md` | Vue d'ensemble architecture et composants | ✅ ACTIF |

Tout autre fichier dans `docs/archive/` = reference historique uniquement, ne pas utiliser en runtime.

## Entrees rapides par domaine

- Regles canonique: `docs/project_rules.md`
- Plan courant: `docs/PROJECT_PLAN.md`
- Problemes connus: `docs/KNOWN_ISSUES.md`
- Decisions techniques: `docs/DECISIONS.md`
- Architecture: `docs/ARCHITECTURE.md`

## Zones code (raccourcis)

- API: `api/`
- Includes backend: `includes/`
- Pages UI: `pages/`
- JS: `js/`
- CSS: `css/`
- Config/schema: `config/`
- Scripts batch/sync: `scripts/`

## Regle anti-rescan

- Ne pas relancer une exploration globale si le delta Git et cet index suffisent.
- Ne pas relire un fichier deja analyse et non modifie dans la meme session.
- Ne jamais lire `docs/archive/` sauf si le contexte l'exige explicitement.
