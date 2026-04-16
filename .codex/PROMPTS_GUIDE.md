# 📋 Comment utiliser les templates Codex

## Les vrais "Skills" : des templates à copier/coller

Codex n'a **pas** de système `@skill` officiel.
Ici, ce sont des **templates de prompts** qu'on copie et on adapte.

---

## 🚀 Workflow en 3 étapes

### 1️⃣ Ouvrir le template
```bash
# VS Code, Ctrl+K, taper: .codex/prompts/01-debug-api-error.txt
# Ou dans Explorer: .codex/prompts/ → click
```

### 2️⃣ Copier tout le contenu
```bash
Ctrl+A → Ctrl+C
```

### 3️⃣ Coller dans Chat Codex
```bash
Ctrl+I (ouvrir Chat)
Ctrl+V (coller)
# Adapter les [À remplir]
# Envoyer
```

---

## 📄 Templates disponibles

| # | Template | Quand | Coût |
|---|----------|-------|------|
| 01 | debug-api-error | API plante | 7 crédits |
| 02 | security-audit | Avant déploiement | 4 crédits |
| 03 | clean-php | Code brut | 2 crédits |
| 04 | optimize-query | Endpoint lent | 10 crédits |
| 05 | test-integration | Après fix MikroTik | 9 crédits |
| 06 | doc-api | Nouvelle API | 3 crédits |

---

## 💡 Astuce VS Code

### Ajouter .codex/prompts en favoris
```
Ctrl+K Ctrl+B (toggle sidebar)
→ Explorer → Right-click "prompts" folder
→ "Add to favorites"
```

Puis: click → open → copy → coller dans Chat.

### Raccourci clavier custom (optionnel)
Dans `.vscode/keybindings.json`:
```json
[
  {
    "key": "ctrl+alt+p",
    "command": "workbench.explorer.fileView.focus"
  }
]
```

---

## ⚠️ Important

- ❌ PAS de `@skill` - c'est du copy/paste
- ✅ Adapter chaque prompt avant d'envoyer
- ✅ Un thread = un template (fermer après)
- ✅ Si thread > 20 messages → nouveau thread

---

## 📊 Coût estimation

Utilisation typique par semaine:
- 3x debug-api-error: 21 crédits
- 2x security-audit: 8 crédits
- 1x optimize-query: 10 crédits
- Chat simple (5x/week): 10 crédits
- **Total/semaine: ~50 crédits**

ChatGPT Plus/Pro donne ~50-60 crédits/week.
→ Tout rentre sans dépassement.

---

## 🎯 Prêt à essayer?

1. Ouvrir: `.codex/prompts/01-debug-api-error.txt`
2. Copier tout
3. `Ctrl+I` dans VS Code (Chat)
4. Coller
5. Adapter le contexte
6. Envoyer

C'est ça, c'est tout! 🚀
