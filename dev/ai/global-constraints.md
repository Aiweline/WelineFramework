# WelineFramework AI global constraints

This is the only repository-wide rule body. Entry files route here; skills and module docs add domain detail without copying or weakening these boundaries.

## 1. Load only what the task needs

1. Follow `AI-ENTRY.md`.
2. Read this file and `dev/ai/skills/_index.md`.
3. Select the smallest useful skill set; there is no arbitrary numeric cap when independent domains are genuinely involved.
4. For module work, read the owning `doc/AI-INDEX.md`, then inspect the targeted source, configuration, existing tests, and verification surface. Source evidence is not a last resort.
5. Do not load task records, archives, migration reports, or historical plans unless the task is resuming or investigating them.

Use the project-intelligence contract supplied by the active runtime. If it is unavailable, fall back to bounded exact-path inspection. GitNexus and other repository-wide indexes are optional unless the user explicitly requests them or the primary path cannot satisfy a code task.

## 2. Scope and authorization

- The current agent owns requirement interpretation, integration, validation, and the final conclusion.
- Prefer evidence-backed, minimal changes. Preserve unrelated dirty-worktree content and do not perform opportunistic refactors.
- Make conservative, reversible assumptions when details are missing. Ask only when the answer changes public behavior, data ownership, security, architecture, irreversible actions, or acceptance scope.
- Read and run relevant existing tests whenever useful. Add or update focused tests when they are a proportionate implementation step for the requested behavior; do not create broad fixtures, test data, or E2E suites unrelated to the task.
- Never use fake data, hidden switches, silent fallbacks, weakened assertions, or provider/model/account switching merely to make a flow appear green.
- Commit, push, deploy, publish, message external systems, write another repository, or change production/external data only when the user explicitly authorizes that action or invokes its exact documented release passphrase.
- Local reversible writes that are a normal implementation or isolated-validation step are allowed within the requested scope; keep them bounded and clean them up. Destructive, irreversible, or broad data changes still require explicit authorization.
- Never store credentials, tokens, cookies, private keys, or reusable passwords in repository guidance. Use environment-provided or user-supplied local credentials and report a blocker when they are unavailable.

## 3. Work records and plans

- Create `dev/ai/codex/tasks/**` records for multi-step, high-risk, long-running, resumable, or handoff-worthy work. A trivial read-only answer or one-line documentation correction does not need a four-file task workspace.
- When a record is warranted, initialize it with `php dev/ai/codex/scripts/init-task.php`, keep the plan/progress/result current, and preserve it on completion.
- Use the `planning` skill when the user requests an implementation plan, task cards, acceptance design, or Plan-mode deliverable. The plan must be evidence-based, executable, and independently verifiable; do not duplicate its detailed schema here.
- A planned task becomes complete only after its own acceptance evidence passes. Preserve the original requirement/plan and write terminal status and deviations back to the same record.

## 4. Framework-wide engineering guardrails

### Source and generation

- Do not edit `generated/`, compiled templates, collected registries, or other generated artifacts. Change the source or generator and run the documented refresh path.
- Do not use `routes.xml`; synchronize controller routes through the framework route-upgrade flow.
- Model fields and indexes belong in model annotations. `Setup/Upgrade.php` may perform legitimate data migration, but must not be used as a parallel schema-definition system.
- Automated refactors and codemods are allowed only when they are syntax/structure-aware, task-scoped, reviewable, and followed by per-file diff validation. Blind cross-file text replacement is prohibited.

### Data and module boundaries

- `website_id = 0` / `code = default` is the valid system default site. Distinguish a missing value from explicit zero; never filter it with truthiness or `empty()`.
- PostgreSQL (`pgsql`) is the production-semantics database. SQLite is useful for isolated portability checks but cannot replace PostgreSQL evidence for schema, transaction, locking, JSON, migration, or persistence behavior.
- Within one module, use its internal services normally. Across modules, use existing published interfaces/contracts or framework extension mechanisms.
- When a new discoverable cross-module read contract is needed, publish a QueryProvider / `w_query()` operation and inspect it with `query:help`. Writes and side effects use the owning interface, Event, Hook, Queue, or other published command boundary.

### Browser, templates, and copy

- Browser-side business requests use bin-query / `weline-api` (`Weline.Api.resource()`, `graph()`, or `stream()`), never direct Ajax/XHR/fetch/axios or handwritten business endpoint URLs.
- User-visible copy is translatable; use the framework i18n forms and `%{name}` / `%{1}` placeholders. Do not use native `alert`, `confirm`, or `prompt`.
- Do not place PHP tags inside `<w:*>` attributes or add `declare(strict_types=1)` to `.phtml`.
- Theme inheritance, account-layout hooks, storefront section `weline-code`, layout boundaries, Taglib selection, and request-chain details belong to the matched frontend skill and owning Theme/Customer documentation.

### Long-running runtime

- Do not introduce process-global mutable request state. Use the request/context/session abstractions; limit `$_SERVER` bridging to the runtime assembly layer and then materialize explicit context.
- In WLS-sensitive paths, do not block with `sleep`/`usleep`, terminate with `die`/`exit`, or perform unbounded synchronous loops/I/O.

## 5. Validation is proportional to the changed surface

| Change surface | Required evidence |
|---|---|
| Browser-visible page, interaction, template, JS/CSS, or visible error | Current host's built-in Browser on the changed runtime; exercise the relevant interaction and inspect visible result/console |
| Route, API, command, service, persistence, or runtime behavior | Closest real command/API/runtime/data evidence, plus focused tests where useful |
| Plain documentation, rule, index, or metadata | Targeted diff, format/frontmatter, link/path, and rendered-preview checks when presentation matters |
| Release/deployment | Local gates plus the explicitly authorized target's real status and reachable entry |

- Do not claim unexecuted checks. Evidence must distinguish the intended fix from a fallback or symptom disappearance.
- Run lower-cost deterministic checks before Browser or end-to-end work.
- Browser failure is a blocker only for a browser-visible acceptance surface. Do not turn a plain Markdown correction into a browser-infrastructure repair task.
- Browser automation must use an addressable in-app/tab interface and must not seize the user's desktop focus.

### WLS and live URLs

- Never test on the default/production WLS port `9501`.
- Use a unique `ai-test-*` instance and an available integer port `>=9502`. Use the WLS skill for exact lifecycle commands.
- Stop a dedicated instance after automated validation. If user acceptance needs it live, report URL, instance name, port, status, and exact stop command; stop it after acceptance.
- Deliver only real, complete URLs. For local backend routes, obtain the runtime `backendKey`; do not guess `/admin` or `/backend`.
- Probe a live acceptance URL and confirm its owning instance/status immediately before handoff. Do not present a stopped, borrowed, placeholder, or source-file-shaped URL as accessible.

## 6. Documentation and knowledge

- Update the current owning README, architecture/API document, skill, index, or rule when verified behavior or a public contract changes. If no documentation change is needed, say why.
- Keep durable behavior and usage guidance outcome-focused. Put task evidence in the task record and historical material in `dev/ai/archive/**`; do not create root-level fix diaries.
- Promote a lesson only when it is confirmed, reusable, and belongs to a clear owner. Merge with the narrowest existing rule/skill instead of adding another mirror.
- Entry files, indexes, adapters, and compatibility maps stay short. Skill trigger conditions belong in frontmatter `description`; detailed examples belong in one-level `references/`.

## 7. Git, external systems, and release

- `git commit` requires an explicit commit request. `git push` is a separate external action and requires an explicit push/release request; a commit request alone does not authorize network writes.
- Stage only task files, review the staged diff, and never include secrets. Do not force-push protected branches without explicit authorization.
- Do not add `Co-authored-by: Cursor`, `Made-with: Cursor`, or any Cursor/agent attribution trailer to commits or PRs. Commit Author stays the human account only.
- Deployment requires a confirmed repository, target, environment, account/SSH configuration, directory, branch, and target-owned procedure. Do not infer production targets from historical notes.
- Exact passphrases such as 「分仓」 and 「分项」 trigger only their owning skills. Similar wording does not expand scope.

### Local branch and worktree policy (hard)

- Allowed local branches in this repository: only `dev` and `master`.
- All code edits, commits, and implementation work happen on `dev` only. Do not modify code while checked out on `master` or any other branch.
- Do not create, check out, or keep any other local branch (feature, fix, merge, codex/*, agent/*, etc.).
- Do not create or use `git worktree` (including best-of-n / isolated worktree runners). Work only in the canonical working tree: `/Users/weline/Project/Official/框架`.
- If a stray branch or worktree exists, remove it before continuing implementation; do not build on top of it.
- `master` may be read or updated only through an explicitly requested merge/release workflow; it is not a coding branch.

### Canonical core and site repositories

- This repository is canonical for `app/code/Weline/**`.
- If a task performed temporary core edits inside a site/release repository, merge only that verified task delta back here after a two-sided diff; never overwrite either side wholesale.
- Distribution from this canonical repository to sites is not automatic. It requires an explicit 「分项」, deployment, or named cross-repository synchronization request.
- Business/vendor-specific directories outside `Weline/**` do not move into core unless the user explicitly scopes them.

## 8. Multi-agent work

- Delegate only independent, bounded subtasks when parallelism materially improves speed or confidence, and obey the runtime's available slots.
- The owner supplies boundaries, integrates findings, protects overlapping files, and performs final acceptance.
- Subagents provide evidence; they do not broaden authorization, replace owner judgment, or silently fix adjacent scope.

## 9. Delivery

The final report should state:

- what changed and where;
- what was actually validated and the decisive evidence;
- the relevant full URL, endpoint, command, or document path for each changed surface;
- any unverified item, blocker, live WLS handoff, or residual risk;
- any commit, push, PR, release, or deployment address only after that action really succeeded.

If there is no accessible URL for a documentation/rule-only task, say so plainly and provide the relevant file paths instead of inventing one.
