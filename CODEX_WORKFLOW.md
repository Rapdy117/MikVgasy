# Workflow Codex optimisé tokens - Chat GPT uniquement

## 🎯 Principe clé
1 thread = 1 sous-problème. Fermer et créer nouveau après changement de contexte.

---

## 1️⃣ SIMPLE READ / QUESTION / ANALYSE
**Mode :** Chat
**Coût :** Très bas (~2-3 crédits)

```
→ Ouvrir chat
→ Sélectionner 30 lignes PRÉCISES du code ou @file un seul fichier
→ Question courte : "Explique ce bloc" ou "Quelle est la logique ici?"
→ Codex répond, pas de modifications
→ Fermer le thread
```

**Exemple bon :**
```
Sélection : les 10 lignes de create_mikrotik_dhcp_lease.php
Question : "Comment ça crée une lease DHCP?"
```

**Exemple mauvais :**
```
"Je dois comprendre l'API DHCP" + charger 5 fichiers = gaspil contexte
```

---

## 2️⃣ PETIT FIX / CORRECTIF / CSS
**Mode :** Agent
**Coût :** Bas (~5-7 crédits)

```
→ Nouveau thread
→ Sélectionner le bloc exact à fixer (~10-30 lignes)
→ Demande : "@file truc.php - ligne XX à YY
  Remplacer ça par ça, problème c'est ..."
→ Codex propose diff
→ Valider, modifier, tester
→ Fermer thread
```

**Exemple bon :**
```
"Fichier: api/get_sessions.php (20-35)
Bug: requête ne filtre pas par user_id
Fixer: ajouter WHERE clause, pas rewrite"
```

---

## 3️⃣ PLAN / ARCHITECTURE / REFACTORING LOURD
**Mode :** Chat puis new THREAD en Agent
**Coût :** Moyen (~8-12 crédits si bien séparé)

**Étape 1 - Chat (analyse)**
```
→ Nouveau Chat
→ Charger max 2-3 fichiers concernés
→ Demande : "Comment refactoriser X?
  Constraints: garder compatibilité API,
  Pas de ntes dépendance.
  Propose plan?"
→ Codex répond avec plan
→ FERMER CE THREAD
```

**Étape 2 - Agent (exécution)**
```
→ NOUVEAU THREAD en mode Agent
→ Copier-coller le plan approuvé dans le message
→ Demande : "Appliquer ce plan exactement"
→ Codex modifie les fichiers
→ Valider, tester
→ Fermer
```

**Important :** Pas de "plan + exécution" dans le même thread. Double le coût de contexte.

---

## 4️⃣ DEBUG / ERREUR / PROBLÈME COMPLEXE
**Mode :** Agent dans un NEW THREAD
**Coût :** Élevé, mais isolé (~15-25 crédits)

```
→ NOUVEAU THREAD
→ Mode Agent
→ Charger 1-2 fichiers centraux + logs pertinents (résumé seulement, pas tout)
→ Demande : "Debug: la requête échoue ici [log résumé].
  Stack: fichier X ligne Y.
  Proposer fix."
→ Laisser Codex exécuter, lire logs, tester
→ Valider fix
→ Fermer thread
```

**Anti-pattern :**
```
Ne pas attacher 10000 lignes de logs bruts.
Au contraire, d'abord demander en Chat:
"Résume ce log pour que je comprenne"
puis nouv thread Agent pour le fix.
```

---

## 5️⃣ EXÉCUTION DE TESTS / CI
**Mode :** Agent
**Coût :** Moyen-élevé selon taille tests

```
→ Demande : "Lance les tests de api/test_radius.php"
→ Codex exécute, montre résultat
→ Si erreur, nouveau chat pour "pourquoi" avant nouv Agent pour fix
```

---

## CHECKLIST AVANT CHAQUE THREAD

- [ ] **Sélection précise ?** (ou @file unique, max 2)
- [ ] **Question = 1 phrase max + context via code** (pas roman)
- [ ] **Fichier AGENTS.md lu par Codex ?** (affiche en startup)
- [ ] **Mode bon ?** (Chat pour question / Agent pour modifier)
- [ ] **Thread flou/long (>30 messages) ?** → FERMER, nouv thread

---

## RÉGLAGE MANUEL PER-THREAD

| Situation | reasoning | verbosity | notes |
|-----------|-----------|-----------|-------|
| Question simple | low | low | default, très rapide |
| Petit CSS/JS | low | low | default |
| Logic complexe | medium | low | une fois, pas à chaque msg |
| Gros refactor | medium | medium | que pour la planif |
| Vrai debug | medium | medium | isolé dans nouv thread |

Ne JAMAIS passer à "high" en mode Agent repetitif.

---

## COÛT ESTIMÉ PAR TÂCHE (ChatGPT credits)

- Question seule : 2-3
- Petit fix (10-30 lignes) : 5-7
- Plan (sans exec) : 4-6
- Plan + exec séparés : 8-12 total
- Debug simple : 10-15
- Refactor moyen (3-5 fichiers) : 15-25

**Astuce :** Si budget limité ce jour, faire questions + plans, exécution demain.

---

## GIT + Codex

Avant chaque Agent mode modification :
```bash
git status  # voir ce qui change
git add -A
git commit -m "avant Codex fix/feature"
```

Après modification Codex :
```bash
git diff HEAD  # voir exactement le changement
git test (ou npm test / php -l)
git commit -m "after Codex: [description rapide]"
```

---

## 🚫 INTERDITS pour économiser

- Pas d'Extension Continue / Cline / Roo Code (hors ChatGPT)
- Pas de Codespaces (facturation GitHub séparée)
- Pas de Full Access mode (trop permissif, coûteux)
- Pas d'Ollama / LM Studio locaux (plus complexe, même budget)
- Pas de Cloud Agents ou Automations Codex app sauf vraiment nécessaire

---

## ✅ CHECKLIST SETUP

- [ ] VS Code + extension Codex officielle OpenAI installée
- [ ] Connecté avec compte ChatGPT
- [ ] AGENTS.md créé à racine du repo
- [ ] .codex/config.toml vérifiée (gpt-4o-mini + low/low)
- [ ] Raccourci clavier VS Code : "Add file to thread" bindé (gain temps)
- [ ] Cette doc lue et affichée dans VS Code en signet

---

## 📊 MESURE DE SUCCÈS

Si la majorité de tes tâches tiennent dans 1 thread de <20 messages, c'est bon.
Si tu fais des threads de 50+ messages, tu gagnes à en ouvrir plus.
Si un thread coûte > 30 crédits, demande-toi si plan/exec étaient séparables.

**Objectif :** Rester dans les limites de crédit du plan ChatGPT sur 1 mois.
