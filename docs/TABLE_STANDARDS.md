# Table Standards (Profiles & Users)

Ce document decrit le standard d affichage pour les tableaux (liste utilisateurs, liste profils, et tous tableaux a aligner).

## Structure HTML
1. Table standard : `table.table.table-striped.table-hover.table-dark.table-standard` (ajouter `align-middle small text-nowrap` au besoin).
2. Le tableau est toujours dans un wrapper scrollable (ex: `.users-table-scroll`, `.profiles-table-wrap`).
3. Le header d une carte qui porte un tableau utilise un `card-header` avec actions alignees (bouton Colonnes, etc.).

## Scroll + En-tete figee
1. Le scroll est interne au wrapper du tableau (pas de scroll page).
2. `thead th` en `position: sticky; top: 0;` avec fond semi opaque + blur (applique si la page a un wrapper scrollable).
3. Scrollbar fine et stable (gutter stable) pour eviter les sauts de layout.

## Menu Colonnes
1. Bouton `Colonnes` avec menu `dropdown-menu` limite en hauteur et scrollable.
2. Options de colonnes en format `label` + checkbox, alignement horizontal.

## Formats Numeriques (Standard)
### Prix (Standard de reference)
- Format : separateur de milliers par espace (ex: `12 500.50`).
- Decimales : 2, mais trim des zeros inutiles (ex: `12 500` au lieu de `12 500.00`).
- Si vide ou null : `-`.

### Data (Mo/Go)
- Valeurs avec separateurs de milliers.
- Exemple : `1 024 Mo`, `2.5 Go`, `12 500 Mo`.
- Si 0 ou vide : `-`.

## Notes
- Le format Prix sert de reference pour tout champ numerique financier.
- Les labels formates sont utilises pour l affichage, les valeurs brutes sont conservees pour les traitements.
