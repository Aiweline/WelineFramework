# AGENTS.md

⚠️ **IMPORTANT:** This is a quick reference. Full rules in `AI-ENTRY.md` (read it first if you haven't).

**For other AIs (GPT/Gemini/Cursor):** Start with `AI-README.md` → `AI-ENTRY.md`

## Framework Core Canonical Repository

- This repository (`/Users/weline/Project/Official/框架`) is the **canonical framework core** for macOS development in the Official workspace family.
- All durable changes under `app/code/Weline/**` belong here first: implement, validate, and commit in this repo.
- Site projects (`WeShop`, `Official-Site`, `App`, etc.) receive core updates via `php bin/w core:update` or the 「分项」 workflow (`dev/ai/skills/CI发布工程师-分项更新/SKILL.md`).
- If core code was changed in a site project for联调, **merge the same change back here at the identical relative path in the same task** before considering the work complete.

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
php bin/w server:stop -n ai-test-{unique-id}  # Stop and cleanup test instance (REQUIRED after testing)
```

## Core Patterns (see AI-ENTRY.md for details)
- ORM: chains end with `.fetch()`/`.fetchArray()` | Pagination: `.pagination($p,$s)`
- Schema: `#[Col]` + `setup:upgrade` (NEVER edit `generated/` or `Setup/Upgrade.php`)
- Websites: `website_id=0`/`code=default` is auto-installed system default site; NEVER treat 0 as empty/invalid/no site.
- Frontend API: browser frontend-backend business interfaces **MUST** use bin-query / `weline-api` (`Weline.Api.*`) | NEVER native Ajax/XHR/fetch/axios
- WLS Testing: **ALWAYS** start dedicated test instance with unique name (`-p 9502+ -n ai-test-{timestamp|session-id}`) | Default port 9501 is PRODUCTION (DO NOT TOUCH) | **MUST stop test instance after testing**
- WLS Lifecycle: code→`reload` | master→`restart -r` | NO `sleep/die/exit`
- I18n: `__('text')` or `<lang>text</lang>` | Placeholders: `%{1}` or `%{name}`

## Critical Constraints
**NEVER:** Edit `generated/` | Use `routes.xml` | Native Ajax/XHR/fetch/axios for frontend-backend business APIs | JS `alert/confirm` | Hardcode text | `<?=?>` in `<w:*>` attrs | `declare(strict_types=1)` in `.phtml` | **Test on default port 9501 or reuse instance names** | **Leave test instances running after session ends**

**ALWAYS:** Use bin-query / `weline-api` (`Weline.Api.resource()/graph()/stream()`) for browser business requests | Start dedicated test instance with unique name (`-p 9502+ -n ai-test-{unique-id}`) | **Stop test instance after testing (`server:stop -n {instance-name}`)**

## Delivery
- After every development/fix/deploy delivery, always give the user the related addresses in the final response: runnable page URLs, backend/admin URLs, API endpoints, doc paths/URLs, PR/commit/release URLs, and the test instance URL when one was started.
- If no live URL is available, still list relevant route/path addresses and clearly state what is required to access them, such as starting WLS or logging into the backend. If there is truly no accessible address, explicitly say `无可访问链接`.

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

<!-- gitnexus:start -->
# GitNexus — Code Intelligence

This project is indexed by GitNexus as **WelineFramework** (119551 symbols, 311386 relationships, 300 execution flows). Use the GitNexus MCP tools to understand code, assess impact, and navigate safely.

> Index stale? Run `node .gitnexus/run.cjs analyze` from the project root — it auto-selects an available runner. No `.gitnexus/run.cjs` yet? `npx gitnexus analyze` (npm 11 crash → `npm i -g gitnexus`; #1939).

## Always Do

- **MUST run impact analysis before editing any symbol.** Before modifying a function, class, or method, run `impact({target: "symbolName", direction: "upstream"})` and report the blast radius (direct callers, affected processes, risk level) to the user.
- **MUST run `detect_changes()` before committing** to verify your changes only affect expected symbols and execution flows. For regression review, compare against the default branch: `detect_changes({scope: "compare", base_ref: "master"})`.
- **MUST warn the user** if impact analysis returns HIGH or CRITICAL risk before proceeding with edits.
- When exploring unfamiliar code, use `query({search_query: "concept"})` to find execution flows instead of grepping. It returns process-grouped results ranked by relevance.
- When you need full context on a specific symbol — callers, callees, which execution flows it participates in — use `context({name: "symbolName"})`.
- For security review, `explain({target: "fileOrSymbol"})` lists taint findings (source→sink flows; needs `analyze --pdg`).

## Never Do

- NEVER edit a function, class, or method without first running `impact` on it.
- NEVER ignore HIGH or CRITICAL risk warnings from impact analysis.
- NEVER rename symbols with find-and-replace — use `rename` which understands the call graph.
- NEVER commit changes without running `detect_changes()` to check affected scope.

## Resources

| Resource | Use for |
|----------|---------|
| `gitnexus://repo/WelineFramework/context` | Codebase overview, check index freshness |
| `gitnexus://repo/WelineFramework/clusters` | All functional areas |
| `gitnexus://repo/WelineFramework/processes` | All execution flows |
| `gitnexus://repo/WelineFramework/process/{name}` | Step-by-step execution trace |

## CLI

| Task | Read this skill file |
|------|---------------------|
| Understand architecture / "How does X work?" | `.claude/skills/gitnexus/gitnexus-exploring/SKILL.md` |
| Blast radius / "What breaks if I change X?" | `.claude/skills/gitnexus/gitnexus-impact-analysis/SKILL.md` |
| Trace bugs / "Why is X failing?" | `.claude/skills/gitnexus/gitnexus-debugging/SKILL.md` |
| Rename / extract / split / refactor | `.claude/skills/gitnexus/gitnexus-refactoring/SKILL.md` |
| Tools, resources, schema reference | `.claude/skills/gitnexus/gitnexus-guide/SKILL.md` |
| Index, status, clean, wiki CLI commands | `.claude/skills/gitnexus/gitnexus-cli/SKILL.md` |

<!-- gitnexus:end -->
