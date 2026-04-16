# Feuille De Route D'Alignement

## Objectif

Ce document sert de plan court et actionnable pour :

- nettoyer le projet
- aligner les comportements
- reduire les restes historiques
- rendre les 3 types plus lisibles

Date de reference :

- 29 mars 2026

## Contraintes D Architecture (Obligatoire)

Ce document doit etre applique en coherence avec `docs/project_rules.md`.

Toute action d alignement doit respecter :

- un attribut = une seule source de verite
- aucune duplication entre :
  - DB
  - backend (MikroTik / RADIUS)
  - UI
- aucune logique ne doit etre corrigee localement sans verifier toute la chaine

Interdictions :

- ne pas corriger un ecran sans verifier :
  - source
  - projection
  - affichage
- ne pas aligner deux comportements si leurs sources sont differentes

Regle :

- toute action doit preserver la chaine complete :
  offre → utilisateur → backend → observation → historique

## Principe De Travail

Ne plus avancer page par page.

Travailler desormais par axes transverses :

1. nommage
2. flux d'action
3. separation des branches techniques
4. factorisation metier
5. nettoyage UI
6. archivage et doc

Contraintes :

- chaque axe doit etre traite avec verification de la source de verite
- aucune correction ne doit introduire une divergence avec `docs/project_rules.md`

Regle :

- une correction transversale prime toujours sur une correction locale

## Axe 1. Nommage

### A renommer

- endpoint de test mutualise a documenter et referencer sous son vrai nom `test_device.php`
- finir le retrait progressif des aliases `opnsense_*` au profit des cles neutres `device_*`
- labels ou variables encore trop lies a un ancien contexte alors que le flux est devenu multi-backend

### Resultat attendu

- un nom doit decrire la fonction reelle
- un nom ne doit plus induire en erreur sur le backend ou le type concerne

## Axe 2. Flux D'Action

### A uniformiser

Sur tout le projet, aligner les cycles utilisateur autour de ces etapes :

- preparer
- previsualiser
- appliquer
- imprimer
- facturer
- payer

### Regles cibles

- une action terminale ne doit pas rester visible si elle n'est plus pertinente
- une action intermediaire ne doit pas rester active si un etat suivant est deja ouvert
- un apercu devient invalide si le formulaire source change
- un lot prepare doit verrouiller les actions precedentes

Contraintes :

- chaque etape doit etre reliee a une source de verite identifiee
- aucune etape ne doit recalculer une valeur deja definie

Interdictions :

- ne pas deduire un etat a partir d un affichage
- ne pas reutiliser une valeur sans verifier sa provenance

Regle :

- un flux doit etre deterministe

## Axe 3. Branches Techniques

Contraintes globales :

- chaque backend doit avoir une source unique de verite
- aucune logique commune ne doit masquer les differences reelles

Interdictions :

- ne pas aligner artificiellement MikroTik et RADIUS
- ne pas utiliser DB comme verite en MikroTik

### MikroTik

Contraintes :

- source = routeur uniquement
- DB = cache uniquement

Interdictions :

- ne jamais lire `users.*` pour :
  - time limit
  - data limit
  - expiration

- conserver comme branche la plus avancee
- nettoyer les restes de compatibilite trompeurs
- documenter officiellement ses capacites reelles
- a faire ulterieurement :
  - verifier si un cumul exploitable pour quota existe reellement ou si la branche expose seulement l actif courant
  - ne pas aligner artificiellement les formules MikroTik sur RADIUS sans source equivalente verifiee

### RADIUS

Contraintes :

- source = DB + `rad*`
- UI = DB uniquement

Interdictions :

- ne jamais lire MikroTik

- aligner les flux commerciaux critiques sur le niveau de lisibilite de MikroTik
- clarifier ce qui est :
  - applique
  - synchronise
  - seulement previsualise
- a faire ulterieurement :
  - corriger `Consommation` pour partir du cumul comptable voulu et non seulement des sessions actives
  - corriger `Duree session` pour utiliser la formule metier retenue au lieu du `MAX(acctsessiontime)` actuel dans `users_list.php`
  - distinguer explicitement dans l UI :
    - actif courant
    - cumul comptabilise

### OPNsense

Contraintes :

- ne pas considerer OPNsense comme source metier tant que non defini
- ne pas presenter une fonctionnalite comme active sans verification complete

Regle :

- OPNsense doit rester coherent avec RADIUS

- decider officiellement son role :
  - branche metier autonome
  - ou branche supervision + support `radius`

## Axe 4. Factorisation Metier

Contraintes :

- ne pas factoriser une logique incorrecte
- ne pas centraliser une duplication sans correction prealable

Regle :

- corriger → stabiliser → factoriser

### A sortir des pages

- calculs commerciaux repetes
- logique facture / recouvrement
- resolution des statuts
- gestion des transitions de cycle

### Resultat attendu

- moins de logique inline dans `pages/`
- plus de logique centralisee dans `includes/`
- moins de divergence entre ecrans proches

## Axe 5. Nettoyage UI

Contraintes :

- une colonne = une source
- aucun fallback implicite

Interdictions :

- ne pas afficher une valeur provenant d une source incorrecte
- ne pas masquer une incoherence par un affichage vide

### A verifier

- boutons visibles mais non pertinents selon l'etat
- libelles incoherents d'une page a l'autre
- champs presents mais non persistants
- ecrans qui melangent consultation et action

### Resultat attendu

- une action visible doit etre reelle
- un champ visible doit avoir un sens metier verifie
- une page doit avoir un role clair

## Axe 6. Archivage Et Documentation

### Regle

Chaque etat charniere doit produire :

- une mise a jour de doc transverse
- une archive courte si l'etat change vraiment la structure du projet

### Dossier de reference

- [archive/2026-03-28_alignment_snapshot.md](/var/www/html/docs/archive/2026-03-28_alignment_snapshot.md)
- [archive/2026-03-29_naming_cleanup_snapshot.md](/var/www/html/docs/archive/2026-03-29_naming_cleanup_snapshot.md)
- [BACKEND_STATUS.md](/var/www/html/docs/BACKEND_STATUS.md)
- [KNOWN_ISSUES.md](/var/www/html/docs/KNOWN_ISSUES.md)

## Axe 7. Verification De Cohérence

Avant validation d un axe :

- verifier la source de chaque attribut
- verifier l absence de duplication
- verifier la coherence entre :
  - UI
  - backend
  - DB

Un axe est considere valide seulement si :

- aucune divergence n existe
- aucune valeur n est derivee sans regle
- aucune source secondaire n est utilisee

---

## Principe Final

L alignement ne consiste pas a rendre les valeurs similaires.

Il consiste a garantir que :

- chaque valeur provient de la bonne source
- chaque affichage est cohérent avec la chaine metier
- aucune logique ne duplique ou deforme une verite existante

## Ordre Recommande

### Phase 1

- nettoyer les noms trompeurs
- fixer la terminologie commune
- statut actuel :
  - endpoint de test mutualise aligne sur `test_device.php`
  - payload dashboard aligne sur `device_*` avec compatibilite temporaire
  - labels et blocs UI multi-device nettoyes sur le dashboard, les devices reseau et la navigation

### Phase 2

- harmoniser les cycles d'action
- verrouiller les etats intermediaires

### Phase 3

- factoriser les logiques commerciales et de recapitulatif
- a faire ulterieurement :
  - realigner `user_logs.php` pour que les libelles de coupure ne confondent plus cause technique et cause metier
  - realigner `api/get_stats.php` et le bloc `Bilan` avec la meme source commerciale que `reports` / `recouvrement`
  - reinjecter le montant dans la boucle metier au bon moment, depuis l origine du cycle jusqu au dashboard

### Phase 4

- clarifier la place d'OPNsense dans le modele cible

### Phase 5

- faire une passe de coherence UI globale

## Definition De Fini

On pourra parler d'alignement reel quand :

- les noms techniques correspondent a la realite
- les 3 types ont un statut documentaire clair
- les cycles d'action sont uniformes
- les pages n'exposent plus de faux etats
- les fonctions communes sont centralisees
- la doc suit l'etat reel du code
