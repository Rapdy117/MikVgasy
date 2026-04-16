# AGENTS.md - Mikhmon Radius

## Project overview

This repository is an existing PHP application for Mikhmon / MikroTik / OPNsense / RADIUS management.

Agents must stabilize and simplify the current system. Do not recreate existing mechanisms, add parallel logic, or invent business rules that are not present in the real code or canonical documentation.

## Authority sources

- Canonical project rules: `docs/project_rules.md`
- Codex usage workflow: `CODEX_WORKFLOW.md`
- Current implementation: the actual PHP, JavaScript, CSS, config, and documentation files in this repository

`docs/project_rules.md` is the first source of authority for business rules, architecture constraints, data flow, backend resolution, access rules, CSS boundaries, and anti-fallback policy.

If `docs/project_rules.md` is missing, unclear, or conflicts with a requested change, stop and ask for clarification before modifying code.

## Project memory

- Treat `docs/project_rules.md` as the canonical memory for business rules and architecture constraints.
- Treat `docs/DECISIONS.md` as the memory for historical technical decisions.
- Treat `docs/KNOWN_ISSUES.md` as the memory for known bugs, risks, and limitations.
- Treat `docs/ARCHITECTURE.md` and `docs/DATA_MODEL.md` as reference memory for structure and data relationships.
- Treat `docs/PROJECT_PLAN.md` as the central planning memory for priorities, incomplete work, and next recommended chantier.
- Treat `docs/PROJECT_DEPENDENCIES.md` as the dependency memory before deleting, renaming, moving, or classifying files.
- Do not store runtime truth, fallback behavior, or duplicated business rules in `AGENTS.md`.
- If a durable rule is discovered, document it in the appropriate project document instead of leaving it only in chat.

## Mandatory reading and updates

- Before any code modification, read `docs/project_rules.md`.
- Do not read every documentation file by default; read only the memory files relevant to the task.
- Read and update `docs/DECISIONS.md` when a durable technical decision is added, changed, or invalidated.
- Read and update `docs/KNOWN_ISSUES.md` when a known bug, risk, limitation, or workaround is added, fixed, or invalidated.
- Read and update `docs/ARCHITECTURE.md` when structure, data flow, backend responsibility, or runtime boundaries change.
- Read and update `docs/DATA_MODEL.md` when tables, fields, relationships, or data ownership change.
- Read `docs/PROJECT_PLAN.md` before choosing or starting a new chantier.
- Read `docs/PROJECT_DEPENDENCIES.md` before deleting, renaming, moving, or classifying a file as orphaned.
- Treat `.codex/prompts/*` as manual prompt templates only; they are not auto-loaded memory unless the user copies one into chat or explicitly requests that workflow.

## Required workflow

- Read `docs/project_rules.md` before any code modification.
- Read only the files essential to the requested task.
- Analyze first, modify after.
- Before changing code, identify the precise files concerned, the exact problem, and the risk level.
- Apply minimal unified diffs; do not rewrite whole files when a focused patch is enough.
- Work from the real code present in the repository.
- If required information is missing or ambiguous, ask a precise question instead of guessing.

## Code rules

- Prefer removing obsolete code over adding compatibility layers.
- Reuse existing logic and local patterns.
- Keep one source of truth per flow.
- Do not add fallback data paths, merge competing sources, or duplicate business logic.
- Replace an old runtime path when introducing a corrected path; do not keep old and new paths as the final state.
- Do not modify UI, CSS, or layout unless explicitly requested or required by the targeted fix.
- Do not use `docs/` as runtime source for PHP, JavaScript, CSS, vendor libraries, or production templates.

## Testing and validation

- Test or lint the sensitive points touched by a change when the repository provides a relevant command.
- For PHP changes, prefer focused syntax checks such as `php -l <file>` on modified PHP files.
- For JavaScript or CSS changes, run the narrowest available relevant check; do not introduce new tooling.
- Summarize logs and command results; do not paste large raw outputs.

## Forbidden changes

- Do not introduce a new abstraction layer unless it removes real duplication or matches an existing project pattern.
- Do not invent business behavior, schemas, defaults, fallbacks, or source precedence.
- Do not modify unrelated files.
- Do not keep dead variables, duplicate logic, or inconsistent backend/device resolution when the task touches that area.
- Do not bypass access-control conventions documented in `docs/project_rules.md`.

## Response format

Always respond in this order:

1. ANALYSE
   - files concerned
   - precise problem
   - risk level
2. PLAN
   - what is removed
   - what is kept
   - what is modified
3. PATCH
   - clear diff summary by file
4. VALIDATION
   - confirm no duplicated logic remains
   - confirm behavior is unchanged or describe the intended change
   - confirm UI is intact when UI changes were not requested
