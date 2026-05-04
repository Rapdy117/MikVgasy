# AGENTS.md

Read `docs/project_rules.md` before any code change.

Priority: `project_rules.md` > user instructions > best practices.

If rules are missing/unclear/conflicting: stop and ask.

Rules:
- Work on existing code only; read only needed files.
- Start with Git delta: `git status --short` then `git diff --name-only`; do not rescan full tree by default.
- Initial read budget: 3-5 files max; expand scope only if blocked.
- Analyze first, then minimal unified diff.
- Reuse existing logic; remove obsolete code.
- One source of truth; no fallback, no duplicate logic, no invented business rules.
- No UI/CSS changes unless explicitly requested.
- Never use `docs/` as runtime source.
- Run the narrowest relevant validation.

Update docs only when relevant:
`DECISIONS.md`, `KNOWN_ISSUES.md`, `ARCHITECTURE.md`, `DATA_MODEL.md`,
`PROJECT_PLAN.md`, `PROJECT_DEPENDENCIES.md`.
Use `docs/PROJECT_INDEX_QUICK.md` as first entry point before extra file discovery.

For code changes, answer with:
ANALYSE → PLAN → PATCH → VALIDATION
Keep responses short: essential info only (ideally one line per section).
