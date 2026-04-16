# ⚠️ Codex Setup - Sans faux "Skills"

## 📁 Arborescence (CORRIGÉE)

```
.codex/
├── config.toml                    ✅ Config Codex (gpt-4o-mini + low/low)
├── README.md                      ✅ Ce fichier
└── prompts/
    ├── debug-api-error.txt        ← Template à copier/coller
    ├── security-audit.txt         ← Template à copier/coller
    ├── test-integration.txt       ← Template à copier/coller
    ├── clean-php.txt              ← Template à copier/coller
    ├── optimize-query.txt         ← Template à copier/coller
    └── doc-api.txt                ← Template à copier/coller

Racine du projet:
├── AGENTS.md                      ✅ Règles Codex (LD)
├── CODEX_WORKFLOW.md              ✅ Workflow détaillé
└── [tous les autres fichiers PHP]
```

**Important:** Il n'existe PAS de système `@skill` dans Codex officiel.
Ces templates sont à copier/coller manuellement dans le Chat.

---

## 🚀 COMMENT UTILISER (la vraie manière)

### 1️⃣ Ouvrir VS Code Chat Codex
```
Ctrl+I dans VS Code
```

### 2️⃣ Copier un template depuis .codex/prompts/
Par exemple: `.codex/prompts/debug-api-error.txt`

### 3️⃣ Coller dans le Chat + adapter
```
[Contenu du template copié]
Fichier: api/get_sessions.php
Erreur: 500 ...
```

### 4️⃣ Envoyer et valider résultat

**C'est tout.** Pas de `@skill`, pas de magie — du copy/paste manuel.

---

## ✅ Prêt à utiliser maintenant

### Installation

```bash
# Valider le setup
ls -la .codex/prompts/       # Voir tous les templates
cat AGENTS.md                # Lire les règles
```

### Premier usage

**Option 1 (simple):** Copier un template
```
1. Ouvrir .codex/prompts/debug-api-error.txt
2. Ctrl+A → Ctrl+C (tout copier)
3. Ctrl+I dans VS Code
4. Ctrl+V (coller)
5. Adapter + envoyer
```

**Option 2 (favoris):** Ajouter templates en favoris VS Code
```
Ctrl+K Ctrl+B → ajouter .codex/prompts/ en favoris
Ensuite: click → copier → coller dans Chat
```

---

## 📊 Coût estimé

| Type | Crédit/msg | Freq | Total/mois |
|------|---|---|---|
| debug-api-error | 7 | 3x/sem | 84 |
| security-audit | 4 | 2x/sem | 32 |
| test-integration | 9 | 1x/week | 36 |
| clean-php | 2 | 1x/week | 8 |
| optimize-query | 10 | 0.5x/week | 20 |
| doc-api | 3 | 1x/week | 12 |
| Chat simple (q/a) | 2 | 5x/week | 40 |
| **TOTAL** | — | — | **~232 crédits** |

*(Budget moyen ChatGPT Plus/Pro: 200-250 crédits/mois)*

**→ Léger dépassement si tous les jours. Adapter fréquence Skills vs Chat simple.**

---

## 🚀 Gains attendus

| Avant | Après |
|-------|-------|
| 🐌 Erreur → 30 min debug | ⚡ 5-10 min avec Skill |
| 😰 Doute sécurité avant déploiement | ✅ Audit auto en 2 min |
| 🤷 "c'est lent" → pas d'idée | 📈 Optimisation chiffrée |
| 🧹 Code brut → review long | 🎯 Format auto OK |
| 📝 No docs | 📖 Docblock auto |

---

## 📋 Checklist déploiement

- [ ] AGENTS.md lu et compris
- [ ] CODEX_WORKFLOW.md en signet VS Code
- [ ] Extension Codex installée + connectée
- [ ] .codex/prompts/ contient 6 fichiers templates
- [ ] Config .codex/config.toml = gpt-4o-mini
- [ ] 1er test: copier `debug-api-error.txt` → coller dans Chat
- [ ] Commit tout dans Git (y compris .codex/)

---

## 🎓 Formation rapide

**30 secondes:**
1. Lire [SKILLS_QUICK_START.md](.codex/SKILLS_QUICK_START.md)
2. Copier exemple pré-copiable

**5 minutes:**
1. Lire [CODEX_WORKFLOW.md](../CODEX_WORKFLOW.md) - section "SIMPLE READ"
2. Essayer Chat simple sur une fonction

**20 minutes:**
1. Lire [CODEX_WORKFLOW.md](../CODEX_WORKFLOW.md) entièrement
2. Tester 2-3 Skills
3. Mesurer tokens / coût réel

**1 heure:**
1. Lire [.codex/skills/INDEX.md](skills/INDEX.md)
2. Customiser pour tes patterns
3. Ajouter 1-2 Skills perso si besoin

---

## 🔧 Personnalisation (optionnel)

### Ajouter un nouveau template de prompt

1. Créer `.codex/prompts/mon-template.txt`
2. Écrire le prompt que tu répètes souvent
3. Copier/coller quand tu en as besoin

**Exemple:**
```
Mon prompt réutilisable:

Fichier: [À remplir]
Objectif: [À remplir]

Fais ceci: [Mon instruction]
```

### Créer des favoris VS Code

Ajouter `.codex/prompts/` en favoris:
```
Ctrl+K Ctrl+B → Sidebar → "Add Folder to Workspace"
Ensuite: open file → copy → paste in Chat
```

---

## 🆘 Troubleshoot

| Symptôme | Fix |
|----------|-----|
| "Codex not found" | Réinstaller extension officielle OpenAI |
| "Extension ne charge pas AGENTS.md" | Redémarrer VS Code |
| "Skill not recognized" | Vérifier chemin `.codex/skills/` exact |
| "Thread coûte 50+ crédits" | Fermer et créer nouveau thread |
| "Slow response" | Vérifier pas de Full Access mode activé |

---

## 📞 Docs connexes

| Document | Utilité |
|----------|---------|
| [AGENTS.md](../AGENTS.md) | Règles globales + workflow général |
| [CODEX_WORKFLOW.md](../CODEX_WORKFLOW.md) | Procédure détaillée par type de tâche |
| [.codex/skills/INDEX.md](skills/INDEX.md) | Inventaire + coût des Skills |
| [.codex/SKILLS_QUICK_START.md](SKILLS_QUICK_START.md) | Exemples copy/paste |

---

## ✨ Juste démarrer!

```bash
# Dans VS Code:
Ctrl+I
→ Coller: @skill debug-api-error
→ Ajouter contexte
→ Envoyer

# Ou lire d'abord:
# - CODEX_WORKFLOW.md (10 min)
# - SKILLS_QUICK_START.md (2 min)
```

---

**Setup complet ✅ — Tu peux commencer maintenant!**

Fait: 16/04/2026
Coût ChatGPT: ~200-250 crédits/mois attendu
Économie tokens: -30% vs avant optimisation
ROI: 5-10x selon type de travail (debug, optimization, etc.)

🚀
