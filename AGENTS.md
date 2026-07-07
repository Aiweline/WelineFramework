# AGENTS.md

⚠️ **IMPORTANT:** This is a quick reference. Full rules in `AI-ENTRY.md` (read it first if you haven't).

**For other AIs (GPT/Gemini/Cursor):** Start with `AI-README.md` → `AI-ENTRY.md`

## Codex Initialization

- Codex persistent repo guidance lives here and in `AI-ENTRY.md`; keep this file short because Codex loads `AGENTS.md` automatically.
- For WelineFramework project identity and high-signal context, read `dev/ai/codex/SOUL.md`, `dev/ai/codex/USER.md`, and `dev/ai/codex/MEMORY.md` when the task needs architecture, runtime, or workflow context. These are context maps, not higher-priority rules.
- Canonical repository rules remain `AI-ENTRY.md` → `dev/ai/global-constraints.md`; if a context map conflicts with them or with Codex system/developer instructions, follow the higher-priority instruction and update the stale context map.
- Test-writing guidance lives in `dev/ai/skills/testing/SKILL.md`. Do not create or update tests, fixtures, or E2E specs unless the current user request explicitly asks for test work.
- Repo-scoped Codex plugin marketplace metadata lives at `.agents/plugins/marketplace.json`; direct Codex skill adapters live under `.codex/skills`.

## Quick Commands
```bash
php bin/w setup:upgrade [--route]  # Schema/route sync
php bin/w http:request / [-b|-api] # Route test
php bin/w server:start -p 9502 -n ai-test-{unique-id}  # Start test instance (REQUIRED)
php bin/w server:reload|restart -r # WLS lifecycle (test instance only)
php bin/w server:stop -n ai-test-{unique-id}  # Stop after automated validation, or after user acceptance
```

## Core Patterns (see AI-ENTRY.md for details)
- ORM: chains end with `.fetch()`/`.fetchArray()` | Pagination: `.pagination($p,$s)`
- Schema: `#[Col]` + `setup:upgrade` (NEVER edit `generated/` or `Setup/Upgrade.php`)
- Websites: `website_id=0`/`code=default` is auto-installed system default site; NEVER treat 0 as empty/invalid/no site.
- Frontend API: browser frontend-backend business interfaces **MUST** use bin-query / `weline-api` (`Weline.Api.*`) | NEVER native Ajax/XHR/fetch/axios
- WLS Testing: **ALWAYS** start dedicated test instance with unique name (`-p 9502+ -n ai-test-{timestamp|session-id}`) | Default port 9501 is PRODUCTION (DO NOT TOUCH) | **Stop after automated validation; if user manual acceptance is required, keep the dedicated instance running and report URL/name/port/stop command until the user confirms acceptance**
- WLS Lifecycle: code→`reload` | master→`restart -r` | NO `sleep/die/exit`
- I18n: `__('text')` or `<lang>text</lang>` | Placeholders: `%{1}` or `%{name}`

## Critical Constraints
**NEVER:** Edit `generated/` | Use `routes.xml` | Native Ajax/XHR/fetch/axios for frontend-backend business APIs | JS `alert/confirm` | Hardcode text | `<?=?>` in `<w:*>` attrs | `declare(strict_types=1)` in `.phtml` | **Test on default port 9501 or reuse instance names** | **Leave unmanaged test instances running; manual-acceptance WLS handoff must include URL/name/port/stop command**

**ALWAYS:** Use bin-query / `weline-api` (`Weline.Api.resource()/graph()/stream()`) for browser business requests | Start dedicated test instance with unique name (`-p 9502+ -n ai-test-{unique-id}`) | **Stop after automated validation, or keep only for user acceptance with explicit handoff and stop after acceptance (`server:stop -n {instance-name}`)**

## Delivery
- After every development/fix/deploy delivery, always give the user the related addresses in the final response: runnable page URLs, backend/admin URLs, API endpoints, doc paths/URLs, PR/commit/release URLs, and the test instance URL when one was started.
- If no live URL is available, still list relevant route/path addresses and clearly state what is required to access them, such as starting WLS or logging into the backend. If there is truly no accessible address, explicitly say `无可访问链接`.
- If a WLS instance is started for user manual acceptance, do not close it before the user confirms acceptance. The final response must list the test URL, instance name, port, current status, and exact stop command; after the user confirms acceptance, stop the instance and report cleanup.

## Project Self-Learning
- Treat user corrections, repeated preferences, accepted fixes, and "avoid this next time" requests as learning signals.
- Global Codex behavior rules belong in global memory or the owning shared skill; DEV-workspace-specific lessons belong only in this project's `AGENTS.md`, matching module docs, or matching `dev/ai/skills/*` file.
- Do not copy DEV-workspace-specific rules into sibling projects. Do not write sibling-project lessons here.
- Before adding a learned rule, extract the trigger, root cause, Do, Avoid, and verification path; merge with an existing rule when the root cause already exists.
- Keep learned rules short and durable. Do not store raw chat transcripts, credentials, temporary paths, or one-off environment accidents as project rules.

## Resources
- Compressed rules pack: `dev/ai/AI-RULES-PACK.md`
- Diagrams: `dev/ai/diagrams/00-INDEX.txt`
- Skills: `dev/ai/skills/_index.md`
- Testing skill: `dev/ai/skills/testing/SKILL.md`
- Codex context: `dev/ai/codex/SOUL.md`, `dev/ai/codex/USER.md`, `dev/ai/codex/MEMORY.md`
- Full guide: `AI-ENTRY.md`
