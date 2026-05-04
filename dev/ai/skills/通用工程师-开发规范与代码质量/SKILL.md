---
name: 通用工程师-开发规范与代码质量
description: Shared engineering skill for Weline development standards, safe change boundaries, code quality, documentation duties, and validation evidence.
version: 1.0.0
---

# Role

This shared skill owns baseline development standards for all WelineFramework engineering work. It keeps changes small, isolated, framework-compliant, documented in the right location, and backed by relevant validation evidence before specialist skills complete their work.

# When To Use

- Use for development standards, code quality, implementation boundaries, safe refactoring, generated-code rules, documentation duties, and validation expectations.
- Use for keywords such as coding standard, development rules, generated code, module boundary, small change, validation evidence, docs update, fix report, WLS test instance, and repository hygiene.
- Use when a task crosses multiple roles or when the request is mainly about how work should be implemented rather than one specific subsystem.
- Use before specialist implementation skills when the task may affect framework stability, business modules, public interfaces, runtime behavior, or user-visible behavior.

# Source Material

- `AI-ENTRY.md`
- `AI-README.md`
- `CLAUDE.md`
- `dev/ai/skills/_index.md`
- `dev/ai/skills/README.md`
- `dev/ai/skills/MIGRATION_REPORT.md`
- `dev/ai/skills/CI发布工程师-环境兼容与命令安全/SKILL.md`
- Original migration sources referenced by `MIGRATION_REPORT.md`: `code-generation-standards`, `documentation-standards`, `debug-logging`, `windows-command-quoting`, `php84-performance`, `testing`, `module-development`, and `weline-framework-core`.

# Responsibilities

- Enforce the repository reading order before deep source-code inspection.
- Keep changes within the correct module or framework boundary.
- Prefer small, isolated, testable changes over broad rewrites.
- Protect generated code, schema conventions, routing conventions, and template constraints.
- Ensure user-facing text, documentation updates, and validation evidence are handled by the correct role or skill.
- Decide which specialist skill should own implementation after the shared standards are clear.

# Workflow

1. Read `AI-ENTRY.md` first, then check diagrams and module docs before reading source code.
2. Identify whether the target is framework-level code, a business module, runtime behavior, frontend/theme work, documentation, tests, or automation.
3. Define the smallest safe change boundary and avoid crossing module ownership unless the task requires it.
4. Check Weline constraints that apply to the target files, such as generated-code, route, i18n, template, WLS, schema, and documentation rules.
5. Choose the responsible specialist skill for implementation, testing, documentation, or acceptance.
6. After implementation, require evidence that matches the affected surface: unit tests, E2E, HTTP validation, WLS validation, command output, or documentation checks.
7. Report changed behavior, validation evidence, documentation updates, and any remaining risks or skipped checks.

# Weline Rules

- Read `AI-ENTRY.md` first.
- Prefer diagrams and module docs before reading source code.
- Do not edit `generated/` directly.
- Do not use `routes.xml`.
- Do not alter schema through generated files or direct `Setup/Upgrade.php` field edits; use model attributes such as `#[Col]` and run `setup:upgrade` where relevant.
- Do not use JavaScript `alert`, `confirm`, or `prompt`.
- Do not hardcode user-facing text; use i18n such as `__('text')`, `<lang>text</lang>`, or the correct framework-safe form.
- Do not add `declare(strict_types=1)` inside `.phtml` files.
- Do not use `sleep`, `die`, or `exit` inside WLS runtime-sensitive code.
- Do not write detailed fix reports to the repository root.
- Write fix reports inside the related module `doc/` directory.
- Update module README after fixing bugs.
- Update architecture docs if the design changes.
- Update API docs if interfaces change.
- Do not use default WLS port `9501` for AI testing.
- Always start a dedicated WLS test instance with port `9502+` and a unique name such as `ai-test-{timestamp}` when WLS validation is required.
- Always stop the dedicated WLS test instance after validation.
- Do not pollute global state.
- Keep module boundaries intact.
- Provide unit test and E2E or HTTP validation evidence where relevant.

# Inputs Required

- The task goal, affected module or framework area, and expected behavior.
- Any relevant diagrams, module docs, README entries, or previous migration notes.
- The files or commands likely to be touched.
- The validation surface: unit test, HTTP route, WLS instance, browser/E2E flow, CLI command, or documentation review.
- Constraints from the Technical Director or Technical Lead if the work is part of a larger plan.

# Expected Output

- A standards-compliant implementation plan or completed change boundary.
- Clear ownership handoff to the appropriate specialist role when implementation is delegated or split.
- Code changes that avoid generated-code edits, global-state pollution, and unnecessary broad rewrites.
- Documentation updates in module docs, architecture docs, API docs, or README files where required.
- Validation evidence tied to the affected behavior, not generic claims.
- A concise report of changed behavior, tests run, skipped checks, and remaining risk.

# Validation

- Confirm the change follows the required reading order and module boundary.
- Confirm no forbidden files or patterns were introduced, including direct `generated/` edits, `routes.xml`, browser-native dialogs, hardcoded visible text, `.phtml` strict types, or WLS `sleep`/`die`/`exit`.
- Run targeted unit tests when logic changes.
- Run HTTP or route validation when routes, controllers, APIs, or UI entry points change.
- Run E2E or browser validation when user flows, forms, interactions, or visible feedback change.
- Run WLS validation on a dedicated `9502+` instance when runtime behavior is affected, and stop the instance afterward.
- Check documentation updates when bugs, interfaces, architecture, or operational behavior changed.

# Constraints

- Do not replace specialist role skills; this skill sets shared standards and routes work to specialists.
- Do not expand the task scope beyond the requested behavior without explicit technical reason.
- Do not use broad rewrites where a narrow patch can solve the issue.
- Do not treat validation as optional when behavior changes.
- Do not leave repository-root fix reports or unmanaged temporary artifacts.
- Do not override Technical Director decisions or second-level acceptance responsibilities.
