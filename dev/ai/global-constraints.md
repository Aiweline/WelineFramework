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
- **Model schema ↔ module version (hard)**：凡改动 Model 表结构声明（含 `#[Col]` / `#[Table]` / 索引 / 字段增删改类型或约束、以及会驱动 Schema 同步的同类 Model 注解变更），**必须**同步上调该模块 `etc/module.php` 的 `"version"`（按 semver 至少 patch+1，例如 `1.0.6` → `1.0.7`）。禁止只改 Model/注解却不升版本：框架用 `module.php` 的 `version` 对比已安装 `setup_version` 决定是否执行 Schema/Upgrade；不升版则线上/预发 `setup:upgrade` 可能跳过结构同步。交付时须列出：模块名、旧版本 → 新版本、以及已执行/待执行的 `php bin/w setup:upgrade`（可带 `-m Module_Name`）。纯业务逻辑、非 Schema 的 Model 方法改动不要求因此升版。
- Automated refactors and codemods are allowed only when they are syntax/structure-aware, task-scoped, reviewable, and followed by per-file diff validation. Blind cross-file text replacement is prohibited.

### Data and module boundaries

- `website_id = 0` / `code = default` is the valid system default site. Distinguish a missing value from explicit zero; never filter it with truthiness or `empty()`.
- PostgreSQL (`pgsql`) is the production-semantics database. SQLite is useful for isolated portability checks but cannot replace PostgreSQL evidence for schema, transaction, locking, JSON, migration, or persistence behavior.
- Within one module, use its internal services normally. Across modules, use existing published interfaces/contracts or framework extension mechanisms.
- **Cross-module hard class coupling (hard · forbidden)**：模块之间**禁止**强制相互引用对方具体类（含跨模块 `use` 对方 Service/Model/Helper/Controller、构造注入具体类、`ObjectManager::getInstance` / `new` 对方模块实现）。正确做法是 **Event / Observer 解耦**（通知、副作用、生命周期协作）；跨模块读取用已发布 Interface 或 QueryProvider / `w_query()`；写入与命令边界用 owning Interface、Hook、Queue 等已发布扩展点。禁止为“方便调用”把 A 模块硬绑到 B 模块类图。例外仅限：框架明确提供的跨模块契约（如 `module.php` `provides` 绑定的 Interface）、以及用户明确授权的临时兼容（须在交付中标明并规划解耦）。
- When a new discoverable cross-module read contract is needed, publish a QueryProvider / `w_query()` operation and inspect it with `query:help`. Writes and side effects use the owning interface, Event, Hook, Queue, or other published command boundary — **prefer Event for notify/side-effect decoupling**; do not invent a hard class dependency instead.
- Schema-changing Model edits in a module always bump that module’s `etc/module.php` `"version"` (see §4 Source and generation). Do not rely on “someone will remember to upgrade” without a version delta.

### Browser, templates, and copy

- Browser-side business requests use bin-query / `weline-api` (`Weline.Api.resource()`, `graph()`, or `stream()`), never direct Ajax/XHR/fetch/axios or handwritten business endpoint URLs.
- User-visible copy is translatable; use the framework i18n forms and `%{name}` / `%{1}` placeholders. Do not use native `alert`, `confirm`, or `prompt`.
- Do not place PHP tags inside `<w:*>` attributes or add `declare(strict_types=1)` to `.phtml`.
- Theme inheritance, account-layout hooks, storefront section `weline-code`, layout boundaries, Taglib selection, and request-chain details belong to the matched frontend skill and owning Theme/Customer documentation.

### Long-running runtime

- Do not introduce process-global mutable request state. Use the request/context/session abstractions; limit `$_SERVER` bridging to the runtime assembly layer and then materialize explicit context.
- In WLS-sensitive paths, do not block with `sleep`/`usleep`, terminate with `die`/`exit`, or perform unbounded synchronous loops/I/O.

## 5. Validation is proportional to the changed surface

### No untested “done” (hard)

**禁止未测试就报告完成。** 每次修改都必须有对应该层的测试证据后，才可写「已完成 / 已修好 / 可用」；禁止仅凭 diff、口头推理或“应该可以”结案。

| 变更范围 | 最低完成门槛 |
|---|---|
| **局部功能函数**（纯 helper、Model/Service 单方法、可隔离单元逻辑，无产品 UI 路径） | **单测**（或同级聚焦测试）跑通，并在交付中写明用例名与结果 |
| **整体功能 / 产品能力**（页面、交互、工作台、后台操作、重建/发布、多语言、SSE、用户可见结果） | **WebUI** 在真实 WLS + 内置 Browser 按操作路径测通；单测可作前置，**不能**单独作为整体完成依据 |
| 文档 / 纯规则 / 元数据 | 按下方表面表做针对性校验；不得假装成产品功能已 WebUI 验收 |

- **每次改完就测**：改一块测一块，不攒到最后；同一任务内多次修改，每次改动后都要补测受影响面。
- 若尚未测完：只能写「代码已改，测试未完成」或「代码已改，WebUI 验收未完成」，**禁止**写成已完成。

| Change surface | Required evidence |
|---|---|
| Browser-visible page, interaction, template, JS/CSS, or visible error | Current host's built-in Browser on the changed runtime; exercise the relevant interaction and inspect visible result/console |
| **WebUI product flows** (workbench, admin panels, intake, rebuild, publish, language switcher, blog UX, SSE progress, etc.) | **WebUI only** — same buttons/forms/menus the operator uses; screenshots of visible results. See §10. |
| Route, API, command, service, persistence, or runtime behavior (non-UI) | Closest real command/API/runtime/data evidence, plus focused unit/tests for local functions where useful |
| Plain documentation, rule, index, or metadata | Targeted diff, format/frontmatter, link/path, and rendered-preview checks when presentation matters |
| Release/deployment | Local gates plus the explicitly authorized target's real status and reachable entry |

- Do not claim unexecuted checks. Evidence must distinguish the intended fix from a fallback or symptom disappearance.
- Run lower-cost deterministic checks (including unit tests for local functions) before Browser or end-to-end work; they do **not** replace WebUI for overall product features.
- Browser failure is a blocker only for a browser-visible acceptance surface. Do not turn a plain Markdown correction into a browser-infrastructure repair task.
- Browser automation must use an addressable in-app/tab interface and must not seize the user's desktop focus.

### WebUI acceptance (hard · product flows)

**Final purpose of acceptance for product/UI work is WebUI functional operation**, not CLI/API simulation.

1. If the user must click, fill, select, confirm, switch language, rebuild, publish, or view a page in the product UI to complete a requirement, the agent **must** perform that same path in the built-in Browser WebUI.
2. **Forbidden as acceptance / “done” evidence** for those flows: `php bin/w …`, `queue:run`, `w_query`, Orchestrator/Facade CLI scripts, direct DB writes, `curl` of HTML/API, log greps, or “queue status=done” alone.
3. CLI/API/DB may be used only for **diagnosis, scaffolding, or non-UI infrastructure** (start WLS, clear stuck worker, read env). They must never substitute for WebUI acceptance, and delivery must still show WebUI steps + screenshots.
4. Multi-locale / language-switcher / blog list-detail / workbench rebuild: accept only after WebUI shows the switcher and locale-correct page content (screenshot required when the user asks for visual acceptance).

### Feature closed-loop development (hard · 顺藤摸瓜)

**Develop → WebUI-test each branch → note blockers → fix → retest → resume main path.** Do not declare a feature done after code-only or bulk happy-path checks.

1. **One feature at a time.** After implementing or changing a product capability, exercise it in WebUI immediately along the operator path for that capability.
2. **Main path + every branch.** For the owning requirement, walk the primary flow **and** each branch (e.g. list / detail / category; en / zh / ja switch; rebuild / retry / publish; empty / error / success). Every branch must be transparent (通透) or explicitly parked.
3. **顺藤摸瓜.** When a branch fails, **pause the main flow**, record the blocker (where, symptom, last WebUI step), fix that branch, WebUI-retest until it passes, **then** continue the main flow. Do not skip ahead and leave broken branches silent.
4. **Temporary notes, not silent debt.** Keep an explicit open list of unpassed branches while working; clear each item only after WebUI pass (screenshot when visual/locale/layout matters).
5. **Closure.** Requirement complete only when all listed main+branch WebUI checks for that feature pass (or user explicitly defers a named item). After a closed loop, **suggest** the user harden coverage with E2E cases; do not invent a large E2E suite unless asked.

### WLS and live URLs

- Never test on the default/production WLS port `9501`.
- Use a unique `ai-test-*` instance and an available integer port `>=9502`. Use the WLS skill for exact lifecycle commands.
- Stop a dedicated instance after automated validation. If user acceptance needs it live, report URL, instance name, port, status, and exact stop command; stop it after acceptance.
- Deliver only real, complete URLs. For local backend routes, obtain the runtime `backendKey`; do not guess `/admin` or `/backend`.
- Probe a live acceptance URL and confirm its owning instance/status immediately before handoff. Do not present a stopped, borrowed, placeholder, or source-file-shaped URL as accessible.

## 6. Documentation and knowledge

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

- Canonical framework repo (macOS): `/Users/weline/Project/Official/框架`. SaaS/site release repo: `/Users/weline/Project/QiPai`. Relative path `app/code/Weline/...` maps to the same path on the peer.
- The framework repository is canonical for `app/code/Weline/**`.
- If a task performed temporary core edits inside a site/release repository, merge only that verified task delta back here after a two-sided diff; never overwrite either side wholesale.
- Distribution from this canonical repository to sites is not automatic. It requires an explicit 「分项」, deployment, or named cross-repository synchronization request.
- Business/vendor-specific directories outside `Weline/**` (e.g. `GuoLaiRen/**`) do not move into core unless the user explicitly scopes them.

#### Prompt merge on every core change (hard)

Whenever a task **creates or modifies** `app/code/Weline/**` in either repository (including `etc/module.php` version bumps, Model schema, Framework, Websites, etc.):

1. **Must prompt the user to merge/align** before claiming the task done — do not silently finish with only one side updated.
2. The prompt **must** include a **decision table**: file → suggested decision code → **reason** (why that direction; what each side currently has).
3. Ask the user to **confirm / 改判** before writing the peer repo. Small diffs are not exempt.
4. After confirmation, apply only the confirmed decisions; if the user declines, record `SKIP` with their reason.
5. Delivery that touched `Weline/**` without this merge prompt + reasons is incomplete.

#### Merge-back baseline (hard · do not lose live edits)

When merging site/release `app/code/Weline/**` changes into the canonical framework repo (or mutual align):

1. **Merge peer = both sides' working-tree files on disk** (including every uncommitted dirty edit). That live file is the only merge peer — **not** `git show` / `git show origin/dev:<path>` / HEAD / any clean committed blob.
2. **Mandatory decision workflow (compare → propose → user confirm → act):** for every candidate file, run a real `diff` of site/release on-disk file ↔ framework working-tree on-disk file, then **list the dirty deltas and a recommended decision with reason** before writing anything. Do not merge until the user confirms the recommendations (or gives alternate decisions). Decision codes:
   - `SAME` — already aligned; no write.
   - `KEEP_FW` — framework dirty side already has the task fix and/or leads; do not overwrite with site.
   - `KEEP_SITE→FW` — site has the verified task delta missing from framework dirty; merge that delta only into the framework working tree.
   - `KEEP_FW→SITE` — framework dirty leads; merge that delta into the site working tree (only when mutual align is in scope).
   - `MERGE_HUNKS` — both sides lead on different hunks; hand-merge without wholesale replace.
   - `SKIP` — out of scope with reason.
3. **Never** skip the dirty-file diff and “assume already synced.” Marker greps alone are not a substitute for the per-file decision table when the files still differ. The delivery must show the decision table **with reasons** **and** wait for user confirmation before applying writes.
4. **Never** treat a clean committed tree as the sole framework-side compare/overwrite source and then write back over the working tree — that drops in-progress dirty edits.
5. **Git is forbidden as a merge/overwrite tool for this sync.** Do **not** use `git checkout`, `git restore`, `git reset`, `git clean`, `git checkout -- <path>`, or `git show … > file` to “apply” peer content — those wipe or replace the live dirty working tree. `git status` / `git diff` may be used only as a **path inventory** aid; the merge itself must be hand edits or targeted file copies of **already-diffed dirty disk content**, never a clean-tree blob.
6. **Never** wholesale `cp` / rsync a site file over a framework working-tree file that already has **unrelated** live dirty hunks without a `MERGE_HUNKS` / confirmed decision. **Never** rsync/copy the entire `Weline/` tree.
7. After merge, re-check that unrelated dirty edits that were present in either working tree before the merge still exist (spot-check via disk `diff` / content markers — not by discarding and re-checking out).
8. `origin/dev` may be used only as optional **read-only context**. Delivery must include the decision table (file → decision → reason), merge direction, and any skipped path.

## 8. Multi-agent work

- Delegate only independent, bounded subtasks when parallelism materially improves speed or confidence, and obey the runtime's available slots.
- The owner supplies boundaries, integrates findings, protects overlapping files, and performs final acceptance.
- Subagents provide evidence; they do not broaden authorization, replace owner judgment, or silently fix adjacent scope.

## 9. Delivery

The final report should state:

- what changed and where;
- what was actually validated and the decisive evidence（单测用例名/结果，和/或 WebUI 步骤与可见结果）；未测不得写成完成;
- the relevant full URL, endpoint, command, or document path for each changed surface;
- any unverified item, blocker, live WLS handoff, or residual risk;
- any commit, push, PR, release, or deployment address only after that action really succeeded.

If there is no accessible URL for a documentation/rule-only task, say so plainly and provide the relevant file paths instead of inventing one.

## 10. Real-device and WebUI acceptance (hard)

Authority for Cursor mirrors: `.cursor/rules/real-device-acceptance.mdc` and `.cursor/rules/local-browser-urls.mdc`. If they conflict with this file, **this file wins**.

### 10.1 Real device + WebUI path

1. Before claiming「已完成 / 已修好 / 可用 / 功能完整」, for **overall / product features** run the **operator-equivalent WebUI path** on a real WLS (or user-named instance) in Cursor's built-in Browser, and verify visible results (screenshots when visual/locale/layout is in scope).
2. **禁止未测试就报告完成**（restates §5 No untested “done”）：每次修改都必须测试；局部功能函数可用单测结该单元；整体功能必须以 WebUI 测通才算完成。
3. **禁止**仅用单测、契约测试、CLI、`curl`、DB、日志、静态 diff 或“看代码应该可以”把**整体功能**结案。单测只覆盖局部函数，不能冒充产品验收。
4. After code changes, confirm the runtime loaded new workers/static assets; stale Worker/cache is invalid acceptance. Resource `?v=` checks alone ≠ acceptance.
5. Delivery must list: what was tested (unit case names and/or probed acceptance URL + WebUI steps), visible results (and screenshots when required), console issues, unverified items. Without the matching tier pass, only write「代码已改，测试未完成」或「代码已改，WebUI 验收未完成」.
6. HTTP entry URLs follow §11 / `local-browser-urls.mdc`.
7. SSE/progress: click the same UI controls; watching queue PID or API status alone is not acceptance.
8. **WebUI-first for product flows** (restates §5): do not drive intake/rebuild/publish/language/blog acceptance via CLI orchestrators; use the workbench and visitor UI.
9. **Feature closed-loop** (restates §5 Feature closed-loop): walk main path + every branch in WebUI; on failure pause, note, fix, retest that branch, then resume; after full pass, suggest E2E solidification to the user.

## 11. Local Browser acceptance URLs

See `.cursor/rules/local-browser-urls.mdc`. Prefer `http://127.0.0.1:{port}/...` for clickable handoff when Host is not required; multi-tenant Host sites may use the live Host URL that the Browser can open. Probe the exact handoff URL before delivery. Do not double-encode `?`/`=`/`&`.
