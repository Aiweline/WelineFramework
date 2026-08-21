# WelineFramework AI global constraints

This is the only repository-wide rule body. Entry files route here; skills and module docs add domain detail without copying or weakening these boundaries.

## 1. Load only what the task needs

1. Follow `AI-ENTRY.md`.
2. Read this file and `dev/ai/skills/_index.md`.
3. Select the smallest useful skill set; there is no arbitrary numeric cap when independent domains are genuinely involved.
4. For module work, read the owning `doc/AI-INDEX.md`, **doc/需求.md**, and **doc/开发日志.md**, then inspect the targeted source, configuration, existing tests, and verification surface. If either fixed module document is missing, establish the honest baseline required by §6 before any code work. Source evidence is not a last resort.
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
- A task record captures execution evidence for one task; it never replaces the owning module's **doc/需求.md** or versioned **doc/开发日志.md**. Link the task record from the module development-log entry when one exists instead of copying raw command/chat history into durable module docs.

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

- **Framework core is an abstraction layer (hard · `app/code/Weline/Framework/**`)**：`Framework/` 是框架核心目录，只承载**平台抽象、运行时、通用 HTTP/Session/Event/DI 契约**。核心通过 **Event（及已发布 Interface / Hook）** 对外扩展；**禁止**把非框架语义（具体业务域、站点/商品/内容/建站等模块概念、模块专属命名与策略）写进 `Framework/`。业务模块若要接入框架行为：在 Framework 提供**语义中立**的事件/契约，由模块侧 Observer 或 `provides` 实现填充；**不要**在 Framework 内 `use`/硬编码其他模块的 Model/Service，也不要把 `Website*` / `Blog*` / `PageBuilder*` 一类业务语义类直接放进核心。正例：`CookieScope` + `Weline_Framework_Http::cookie_scope_resolve`（中立字段）← `Weline_Websites` Observer 贡献挂载隔离。反例：在 Framework 内实现 `WebsiteCookieScope` 并内嵌 website_id/挂载 path 业务规则。

- **Cross-module hard class coupling (hard · forbidden)**：模块之间**禁止**强制相互引用对方具体类（含跨模块 `use` 对方 Service/Model/Helper/Controller、构造注入具体类、`ObjectManager::getInstance` / `new` 对方模块实现）。模块间协作**优先且默认用 Event / Observer**（通知、副作用、生命周期、策略贡献）；跨模块读取用已发布 Interface 或 QueryProvider / `w_query()`；写入与命令边界用 owning Interface、Hook、Queue 等已发布扩展点。禁止为“方便调用”把 A 模块硬绑到 B 模块类图。例外仅限：框架明确提供的跨模块契约（如 `module.php` `provides` 绑定的 Interface）、以及用户明确授权的临时兼容（须在交付中标明并规划解耦）。
- When a new discoverable cross-module read contract is needed, publish a QueryProvider / `w_query()` operation and inspect it with `query:help`. Writes and side effects use the owning interface, Event, Hook, Queue, or other published command boundary — **prefer Event for notify/side-effect decoupling**; do not invent a hard class dependency instead.
- Schema-changing Model edits in a module always bump that module’s `etc/module.php` `"version"` (see §4 Source and generation). Do not rely on “someone will remember to upgrade” without a version delta.

### Browser, templates, and copy

- Browser-side business requests use bin-query / `weline-api` (`Weline.Api.resource()`, `graph()`, or `stream()`), never direct Ajax/XHR/fetch/axios or handwritten business endpoint URLs.
- User-visible copy is translatable; use the framework i18n forms and `%{name}` / `%{1}` placeholders. Do not use native `alert`, `confirm`, or `prompt`.
- **`.phtml` is compiled, not plain PHP (hard).** Weline compiles `view/templates/**` to `view/tpl/**/com_*.phtml`. Violations cause ParseError/500 or silently broken HTML/JS.
- **No PHP inside HTML tags (hard).** Never put `<?php`, `<?=`, or `<?` inside a tag name or attribute list. Precompute strings in a top `<?php ... ?>` block; HTML lines may only echo already-prepared scalars (`<?= $safeVar ?>`).
- **No PHP logic interleaved in markup (hard).** Do not use `<?php if (...) : ?>` / `elseif` / `endif` between HTML blocks in `.phtml`. Build HTML fragments in the top PHP block, split partials, or pre-render in the Controller.
- **No `$this->fetch()` inside partial templates (hard).** Pre-render sub-partials in the Controller and `assign` HTML strings. In templates use `<?php echo $assignedHtml; ?>` — never `<?= $this->fetch(...) ?>`.
- **Short-echo restrictions (hard).** Inside HTML, do not use `<?= ($x ?? '') ?>`, ternaries, or inline `htmlspecialchars(..., ENT_QUOTES, 'UTF-8')`. Assign escaped values first.
- **Taglib / HTML pitfalls (hard).** Do not place PHP inside `<w:*>` attributes. Do not add `declare(strict_types=1)` to `.phtml`. Avoid `<dd>`/`<dt>` (compiler misparsing). Avoid `data-test-*` attribute names; prefer neutral prefixes (e.g. `data-mcs-*`) or classes. Do not nest large HTML trees inside `<template>` in a partial that Weline re-parses — use a separate partial or controller-built store.
- After `.phtml` edits, run `php bin/w theme:compile` and verify the page is not HTTP 500 before claiming done.
- Theme inheritance, account-layout hooks, storefront section `weline-code`, layout boundaries, Taglib selection, and request-chain details belong to the matched frontend skill and owning Theme/Customer documentation.

### HTTP 输入：禁止超全局（hard）

- **禁止**在业务模块、产品模块、Observer、Controller、Service、`.phtml` 中直接读写 PHP 超全局：`$_GET`、`$_POST`、`$_REQUEST`、`$_COOKIE`、`$_FILES`、`$_SERVER`。
- WLS 常驻进程里超全局只是兼容投影：请求结束 `GlobalsEmulator::reset()` 会清空；`WelineEnv::replaceGet()` / SEO 解码只更新 Context，**不回写** `$_GET`。直接读经常得到空数组，这不是「框架丢了参数」，是用法错误。
- **正确获取方式**：`$request->getParam()` / `getGet()` / `getPost()` / `getServer()` / `getHeader()`；无 Request 时用 `WelineEnv::getGet()` / `getPost()` / `getCookie()` / `server()`。禁止 `getGet()` 为空再回退 `$_GET`。
- **例外仅限运行时装配层**：`GlobalsEmulator`、`WlsRequest`、`WlsRuntime`、`WelineEnv`、`Context`、ParameterBag/ServerBag 初始化，以及单测夹具与 CLI `$_SERVER['argv']`。以 `ProjectSuperglobalUsageGuardTest` 白名单为准，禁止扩大。Cursor 常驻摘要：`.cursor/rules/no-php-superglobals.mdc`。

### Long-running runtime

- Do not introduce process-global mutable request state. Use request/context/session abstractions. PHP superglobals are forbidden outside the runtime assembly layer (see **HTTP 输入：禁止超全局** above).
- In WLS-sensitive paths, do not block with `sleep`/`usleep`, terminate with `die`/`exit`, or perform unbounded synchronous loops/I/O.

## 5. Validation is proportional to the changed surface

### No untested “done” (hard)

**禁止未测试就报告完成。** 每次修改都必须有对应该层的测试证据后，才可写「已完成 / 已修好 / 可用」；禁止仅凭 diff、口头推理或“应该可以”结案。

| 变更范围 | 最低完成门槛 |
|---|---|
| **局部功能函数**（纯 helper、Model/Service 单方法、可隔离单元逻辑，无产品 UI 路径） | **单测**（或同级聚焦测试）跑通，并在交付中写明用例名与结果 |
| **整体功能 / 产品能力**（页面、交互、工作台、后台操作、重建/发布、多语言、SSE、用户可见结果） | **WebUI** 在真实 WLS + 内置 Browser 按操作路径测通；单测可作前置，**不能**单独作为整体完成依据 |
| 文档 / 纯规则 / 元数据 | 按下方表面表做针对性校验；不得假装成产品功能已 WebUI 验收 |

- **每轮改完先审再测**：新增功能必须遵循下方串行质量门禁；测试中发现问题并修改代码后，必须退回代码审查门禁，复审通过后才可重测。非新增功能仍须按变更表面及时验证，不得把验证攒到最后。
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

### Feature closed-loop quality gate (hard · review before test · 顺藤摸瓜)

**Implement → code review → fix → re-review pass → layered tests → real-path E2E acceptance → E2E regression hardening.** This serial gate applies whenever a task adds or expands executable product behavior (UI, API, command, service, persistence, or runtime capability). Documentation/rule-only changes continue to use the proportional evidence table above. Stages may not be skipped, substituted, or crossed in parallel.

1. **Implementation gate.** Complete the bounded implementation and identify the changed call chain, public behavior, primary path, branches, and expected test surface. Tests and E2E cases may be designed or authored with the implementation, but **must not be executed before the code-review gate passes**.
2. **Code-review gate — before testing.** Review the complete task diff and affected call chain from all three perspectives: (a) **framework architecture** — core/module ownership, abstraction and Event/Interface/Hook boundaries, dependency direction, DI/lifecycle/runtime compatibility; (b) **defects** — logic, state, boundary/error paths, concurrency/idempotency/transaction behavior, compatibility, rollback, and hidden fallback risk; (c) **security** — authentication/authorization/ACL, trust boundaries and input validation, injection/XSS/CSRF/SSRF/path risks, session/sensitive-data handling, and unsafe external side effects as applicable.
3. **Fix and re-review gate.** Record findings with severity and evidence, fix every in-scope finding, then re-review the complete resulting diff. A review is not “passed” merely because it was performed: every in-scope finding must be closed; a named deferral is allowed only with explicit user approval and otherwise remains a blocker. **No test execution may start until re-review concludes that the gate passed.**
4. **Layered-test gate.** Only after review passes, run the lowest-cost deterministic checks first, followed by the relevant unit, integration, API/command/runtime, and WebUI layers. A test-discovered defect that changes code returns the task to step 2; re-review must pass before the affected tests are rerun.
5. **Real-path E2E gate.** For product/UI capability, use a unique real WLS plus the built-in Browser and exercise the operator-equivalent primary path **and every owned branch** (success, empty, error, retry, locale/permission variants as applicable). CLI/API/DB/log evidence cannot replace this WebUI gate. For an executable capability with no UI path, use its closest real external entry through the full stack and state why Browser E2E is not applicable.
6. **顺藤摸瓜.** When a path or branch fails, pause the main flow, record the blocker and last passing step, fix it, return through code review, and retest it to green before resuming. Keep an explicit open list; do not skip ahead or leave broken branches silent.
7. **E2E hardening and closure.** After the real path passes, add or update a **repeatable E2E regression case** for the accepted primary path and the highest-risk branches, then execute that case successfully. A new feature is not complete without the review verdict, layered-test evidence, real-path acceptance evidence, and the passing E2E asset; if any gate is unavailable, report the exact blocker and do not claim completion.

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

### Module requirements, version progress, and handoff (hard)

1. **Two fixed documents per module.** Every module owns exactly `app/code/{Vendor}/{Module}/doc/需求.md` and `app/code/{Vendor}/{Module}/doc/开发日志.md`, linked from its `doc/AI-INDEX.md`. Create both with a new module. For an existing module missing either file, create an evidence-backed baseline before the next module code change; never fabricate historical versions, decisions, progress, or acceptance. Put unverifiable legacy behavior under「待确认」.
2. **Requirement source of truth.** **需求.md** contains the current confirmed requirements and business logic, stable requirement IDs, scope/non-goals, acceptance criteria, security/data/permission implications, introduced/changed versions, decision rationale, and a version change ledger. It is not a raw chat transcript or implementation diary. Code behavior is evidence to reconcile, not permission to silently rewrite the requirement.
3. **User-request reconciliation gate.** Before planning implementation or dispatching a code-edit subagent, compare the semantic meaning of the current request with **需求.md**. If it adds, removes, contradicts, or changes any requirement, business rule, acceptance condition, priority, target version, data/security boundary, or operator behavior—or the document is silent—show the user the exact difference and impact and ask whether to supplement/change the requirement. Equivalent wording is not a change. **Do not start code work until the user explicitly decides.**
4. **Write requirement before code.** After confirmation, update **需求.md** first with the approved requirement, logic, rationale, target version, acceptance/E2E paths, and affected requirement IDs. Then open or update the matching target-version entry in **开发日志.md**. A chat-only decision, task plan, or code diff does not satisfy this gate.
5. **Versioned development ledger.** **开发日志.md** is the durable locator for development progress and adjustments, organized by target module version and linked requirement IDs. Record only evidence-backed stage transitions: requirement confirmed, implementation, architecture/defect/security review and fixes, review pass, layered tests, real-path E2E, repeatable E2E asset, documentation/operations handoff, acceptance, release/defer/block. Keep dated adjustment/decision entries and blockers; never claim unexecuted checks. A documentation target version does not by itself authorize or substitute for the module-version rules in §4 or a release action.
6. **Keep the ledger current at gates.** Update the version entry when a gate changes state, scope changes, a review/test finding causes rework, the user approves a deviation, or work blocks/resumes. Do not turn it into terminal output: link concise evidence, task records, paths, cases, URLs, and screenshots. Redact evidence and never store credentials, tokens, cookies, private URLs with secrets, personal/customer/payment data, or reusable session material. Every code change caused by a finding returns through the documented implementation-owner/model choice and review-before-test rules in §5/§8 before the log may advance.
7. **Functional and operations documentation before closure.** After the implementation has passed its technical/real-path acceptance and before final user/product acceptance, locate and update the current owning README, architecture/API/usage document, and operator-facing document for the feature. If an operator/support workflow has no owner, create `app/code/{Vendor}/{Module}/doc/运营/{功能名}.md` and link it from `doc/AI-INDEX.md`; include version, purpose, prerequisites/permissions, operator path, states, errors/recovery, limitations, and rollback/support notes. If a change truly has no operator impact, record `N/A` with evidence in **开发日志.md** rather than creating filler.
8. **Definition of done.** A module feature is incomplete until **需求.md** reflects the accepted behavior, the target-version **开发日志.md** records every applicable gate with real evidence, the functional/operator docs match the accepted result, and open deviations are either resolved or explicitly deferred by the user. Follow `通用工程师-开发规范与代码质量` for the detailed document contract and closure checklist.

## 7. Git, external systems, and release

- `git commit` requires an explicit commit request. `git push` is a separate external action and requires an explicit push/release request; a commit request alone does not authorize network writes.
- Stage only task files, review the staged diff, and never include secrets. Do not force-push protected branches without explicit authorization.
- Do not add `Co-authored-by: Cursor`, `Made-with: Cursor`, or any Cursor/agent attribution trailer to commits or PRs. Commit Author stays the human account only.
- Deployment requires a confirmed repository, target, environment, account/SSH configuration, directory, branch, and target-owned procedure. Do not infer production targets from historical notes.
- Exact passphrases such as 「分仓」「分项」「回灌」 trigger only their owning skills. Similar wording does not expand scope.

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
- Distribution from this canonical repository to sites is not automatic. It requires an explicit 「分项」, 「回灌」, deployment, or named cross-repository synchronization request.
- Business/vendor-specific directories outside `Weline/**` (e.g. `GuoLaiRen/**`) do not move into core unless the user explicitly scopes them.

### 回灌（硬 · 精确口令）

**简称「回灌」。** 用户当前请求出现精确口令 **「回灌」** 时，视为明确授权：修复本任务所需核心代码，并按完整验证环路合入、推送、站点 `update:core`、本地/线上取证。权威步骤：`dev/ai/skills/CI发布工程师-回灌验证/SKILL.md`；Cursor：`.cursor/rules/huiguan-core-update.mdc`。

#### 何时可以做

1. 会话/工作区**已提供框架仓**（可写路径，如本仓 `/Users/weline/Project/Official/框架`）。
2. 用户出现精确口令 **「回灌」**（或明确延续上一轮回灌授权完成本环路）。
3. 问题确需改 `app/code/Weline/**`（或框架仓内必要核心路径如 `pub/errors`）；应用侧 Event / Interface / Hook 无法解决。

#### 何时禁止

1. **未给框架仓** → 禁止回灌流程；在消费站点仓只汇报，**不得**改站点内 `Weline/**` 顶替。
2. **未说「回灌」** → 禁止自行改核、禁止主动 `update:core -f`、禁止把「改核心 / 同步 / 合并到框架 / 分项」当成回灌授权。
3. 能在业务/应用模块解决 → 不要升级为回灌。

#### 标准环路（口令已给出且框架仓可用时）

1. **修**：仅在框架仓 `dev` 上改本任务核心文件 + 必要文档/单测。
2. **合**：`dev` commit → merge `master` → push 已配置 remote（通常 Gitee + GitHub）→ tip 可核对。
3. **灌**：在当前消费站点执行 `php bin/w update:core -b master`；冲突检测挡住且目标即对齐官方 tip 时可用 `-f`（保护文件仍按命令跳过）。
4. **验**：本地证据 + 任务范围内线上证据；交付写明 tip、命令、结果。未测不得称完成。

#### 与「分项」/跨仓对齐

| 口令/规则 | 含义 |
|---|---|
| **回灌** | 授权修核 + 推送 + 回灌**当前**消费站并验证 |
| **分项** | 已有 tip 分发到脚本配置的多站点；不隐含「可以改核心」 |
| §7 Prompt merge | 改过 `Weline/**` 结案前必须提示对齐；**不等于**已授权回灌 |

#### Prompt merge on every core change (hard)

Whenever a task **creates or modifies** `app/code/Weline/**` in either repository (including defects, features, runtime behavior, configuration, core documentation, `etc/module.php` version bumps, Model schema, Framework, Websites, etc.):

1. **Must prompt the user to merge/align** before claiming the task done — do not silently finish with only one side updated.
2. The prompt **must** include a **decision table**: file → suggested decision code → **reason** (why that direction; what each side currently has).
3. Ask the user to **confirm / 改判** before writing the peer repo. Small diffs are not exempt.
4. After confirmation, apply only the confirmed decisions; if the user declines, record `SKIP` with their reason.
5. Delivery that touched `Weline/**` without this merge prompt + reasons is incomplete.

#### Session-scoped merge only (hard · current task files)

Each core↔site merge covers **only files created or modified in the current conversation / task session**.

1. **Candidate set = this session's change set** intersecting `app/code/Weline/**` (plus AI rule/docs files only when this session actually edited them). Build the list from the session transcript + on-disk edits of this task — not from a full-tree `Weline/` diff, not from historical commits, not from “while we're here” sibling drift.
2. **Prohibit** merging, deciding, or even proposing files **outside** that session set. Pre-existing repo drift (hundreds of unrelated `Weline/**` diffs, compiled `view/tpl`, other modules' WIP) must be marked `SKIP` with reason「会话外 / 非本任务」— or omitted from the decision table entirely.
3. Expanding scope (full `Weline/` align, whole module tree, older commits) requires an **explicit new user request**; never infer it from「合并到框架」alone.
4. Delivery must state the session candidate list (or “no `Weline/**` edits this session → nothing to merge”).

#### Merge-back baseline (hard · do not lose live edits)

When merging site/release `app/code/Weline/**` changes into the canonical framework repo (or mutual align):

1. **Merge peer = both sides' working-tree files on disk** (including every uncommitted dirty edit). That live file is the only merge peer — **not** `git show` / `git show origin/dev:<path>` / HEAD / any clean committed blob.
2. **Mandatory decision workflow (compare → propose → user confirm → act):** for every **session candidate** file only, run a real `diff` of site/release on-disk file ↔ framework working-tree on-disk file, then **list the dirty deltas and a recommended decision with reason** before writing anything. Do not merge until the user confirms the recommendations (or gives alternate decisions). Decision codes:
   - `SAME` — already aligned; no write.
   - `KEEP_FW` — framework dirty side already has the task fix and/or leads; do not overwrite with site.
   - `KEEP_SITE→FW` — site has the verified task delta missing from framework dirty; merge that delta only into the framework working tree.
   - `KEEP_FW→SITE` — framework dirty leads; merge that delta into the site working tree (only when mutual align is in scope).
   - `MERGE_HUNKS` — both sides lead on different hunks; hand-merge without wholesale replace.
   - `SKIP` — out of scope with reason (including session-outside files).
3. **Never** skip the dirty-file diff and “assume already synced.” Marker greps alone are not a substitute for the per-file decision table when the files still differ. The delivery must show the decision table **with reasons** **and** wait for user confirmation before applying writes.
4. **Never** treat a clean committed tree as the sole framework-side compare/overwrite source and then write back over the working tree — that drops in-progress dirty edits.
5. **Git is forbidden as a merge/overwrite tool for this sync.** Do **not** use `git checkout`, `git restore`, `git reset`, `git clean`, `git checkout -- <path>`, or `git show … > file` to “apply” peer content — those wipe or replace the live dirty working tree. `git status` / `git diff` may be used only as a **path inventory** aid; the merge itself must be hand edits or targeted file copies of **already-diffed dirty disk content**, never a clean-tree blob.
6. **Never** wholesale `cp` / rsync a site file over a framework working-tree file that already has **unrelated** live dirty hunks without a `MERGE_HUNKS` / confirmed decision. **Never** rsync/copy the entire `Weline/` tree.
7. After merge, re-check that unrelated dirty edits that were present in either working tree before the merge still exist (spot-check via disk `diff` / content markers — not by discarding and re-checking out).
8. `origin/dev` may be used only as optional **read-only context**. Delivery must include the decision table (file → decision → reason), merge direction, and any skipped path.
9. Obey **Session-scoped merge only** above: non-session files are never merge candidates unless the user explicitly expands scope.
10. Preserve a before/after `git diff -- <path>` evidence pair for every dirty merge target. The after diff must contain both the current task's semantic hunk and every unrelated hunk present in the before diff; path-level “modified” status alone is not proof of preservation.

## 8. Multi-agent work

- **Implementation ownership and model choice (hard).** For executable source, templates/styles, executable configuration or migration logic, generators, tests, and E2E code, the current task owner must explicitly choose the execution arrangement that fits the task's scope, complexity, and risk. The owner may make a bounded change directly or delegate it to a bounded subagent. **Any delegated code edit must use `gpt-5.6-terra`.** An unavailable legacy or user-copied model name must never block authorized work by itself.
- **Delegated edit requirements.** Before launching a code-edit subagent, confirm that the active interface supports explicit `gpt-5.6-terra` selection, record that model in the launch receipt, and give the agent concrete requirements, allowed paths/symbols, forbidden scope, acceptance evidence, and stop/escalation conditions. If Terra is unavailable, do not silently substitute another subagent model; keep the bounded edit with the current owner or report a genuine non-model blocker.
- **Repair ownership.** Code repair required by review or failed tests returns to the owner or to a newly selected bounded implementation agent. The owner re-reviews the resulting diff before tests resume, following §5.
- For non-code work, delegate only independent, bounded subtasks when parallelism materially improves speed or confidence, and obey the runtime's available slots.
- The owner supplies boundaries, integrates findings, protects overlapping files, and performs final acceptance.
- Subagents provide evidence; they do not broaden authorization, replace owner judgment, or silently fix adjacent scope.

## 9. Delivery

The final report should state:

- what changed and where;
- for every code-editing task, the implementation owner, selected model when delegated, owned paths/symbols, and returned implementation evidence;
- for every new feature, the architecture/defect/security review findings, fixes, re-review verdict, and any explicitly approved deferral;
- what was actually validated and the decisive evidence（单测用例名/结果，和/或 WebUI 步骤与可见结果）；未测不得写成完成;
- the hardened E2E case/path and its actual execution result when the new-feature gate applies;
- for module work, the requirement IDs and target version, **需求.md** reconciliation decision, **开发日志.md** final gate status, and updated/created functional or operator documents;
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
9. **New-feature serial gate** (restates §5 Feature closed-loop quality gate): implementation → architecture/defect/security code review → fix and re-review pass → layered tests → real-path WebUI E2E → passing repeatable E2E regression. Walk the main path plus every owned branch; any code change made after a test failure returns to review before retest. E2E hardening is mandatory, not a post-completion suggestion.

## 11. Local Browser acceptance URLs

See `.cursor/rules/local-browser-urls.mdc`. Prefer `http://127.0.0.1:{port}/...` for clickable handoff when Host is not required; multi-tenant Host sites may use the live Host URL that the Browser can open. Probe the exact handoff URL before delivery. Do not double-encode `?`/`=`/`&`.

## 12. SAAS remote ops and deploy target (hard)

Authority for Cursor mirrors: `.cursor/rules/ssh-mcp-deploy.mdc`. If they conflict with this file, **this file wins**. Connection parameters: `dev/ai/config/ssh-mcp-weline-saas.json`.

### 12.1 SSH MCP only

1. SAAS host `43.205.103.113` operations **must** go through Cursor **SSH MCP** (`ssh-mcp`), reusing persistent connection **`weline-saas`**.
2. **Prohibit** Shell loops of `ssh` / `scp` / `rsync-over-ssh` for routine remote ops; use MCP `exec` / `sudo-exec` (and file upload tools when available).
3. Connect once when missing/disconnected per `ssh-mcp-weline-saas.json`; do not paste private key material into the repo or chat.

### 12.2 Deploy default = pre（硬）

When the user says **「部署」** / **deploy** / **提交推送部署** (or equivalent) **without** naming production:

1. **Default target = pre（预发）**：`/home/weline-test` on the SAAS host（`preDeployDir`）.
2. **Do not** deploy to production `/home/weline`（`prodDeployDir`） unless the user **explicitly** says so, e.g. 「部署生产」「部署正式」「deploy prod」「生产环境」.
3. Ambiguous 「两边都部署 / 全量发布」 still needs a one-line confirm before touching production.
4. Delivery must state which target was deployed（`pre` and/or `prod`）and the path used.
5. Pre is often on a dirty working tree / different branch than local `dev`; prefer **session commit file checkout** from the pushed ref over wholesale `git reset --hard`, unless the user authorizes a hard reset.

### 12.3 Paths

| 环境 | 目录 | 触发用语（示例） |
|------|------|------------------|
| pre（默认） | `/home/weline-test` | 部署、deploy、推送部署 |
| prod（须明示） | `/home/weline` | 部署生产、部署正式、deploy prod |
