# WebUI 浏览器验收与交付地址门禁

> **硬规则**：凡触及页面 / 模板 / 后台 UI / 前台交互的任务，**开发完成 ≠ 代码写完**。必须由 AI **亲自用当前宿主可用的真实 Browser（操作员浏览器）按用例自测**，并在面向用户的交付汇报末尾列出「交付地址」。单测、curl、口头「看起来对」**一律不算** Web 完成。

权威流程：[AI工程交付流程.md](../../Ai/doc/AI工程交付流程.md) §6–§7。机器契约：`workflow_contract.v1.feature_delivery_urls`、`closeout_delivery_reminder`、`chapter_delivery.webui_*`。

## 何时必须执行

触发任一即强制：

- 改了 `.phtml` / layout / widget / partial / Taglib 渲染
- 改了后台或前台 Controller 页面输出
- 改了用户可见交互（表单、Toast、菜单、配置页、结账等）
- 用户要求「验收 / 交付 / 完成汇报」且表面含 Web

纯 Service / 无 UI 的 CLI：Browser 写 `N/A`，但交付地址小节仍须写 CLI 入口或 `N/A` 理由。

## Browser 工具（客户端无关）

**不绑定某一 IDE / 某一厂商。** 使用**当前 AI 宿主已提供的、能真实打开页面并交互**的 Browser 能力，例如：

| 宿主示例 | 可用能力（择一即可） |
|----------|----------------------|
| Cursor | 内置 / IDE Browser、Browser MCP |
| Codex / Claude Code / 其他 | 宿主自带 Browser、Playwright/Puppeteer 驱动的真实浏览器、等价操作员自动化 |

硬要求：

1. 必须是**真实页面交互**（导航、点击、填写、提交、观察 Toast/跳转/DOM），不是 HTTP 探活。
2. **禁止写死**「只能用 Cursor 内置 Browser」——规范只要求「宿主可用的真实 Browser」。
3. 当前宿主**没有任何**可交互 Browser 时：不得宣称 Web/UI 完成；只能报「代码已改，WebUI 验收未完成（宿主无 Browser）」并列出已探活 URL 供人工续测。

## 门禁 A：Browser 操作员自测（WB-OP）

1. **先有用例**：URL、步骤、期望结果（来自 `doc/需求.md` 或 TaskContract）；禁止先写代码后补「随便点一下」。
2. **起真实 WLS**（测试实例），确认 Worker/静态资源已加载本次改动。
3. **打开当前宿主可用的真实 Browser，且打开即禁用 HTTP 缓存（硬，`browser_cache_disabled_on_open`）**，再按用例逐步操作（点击、填写、提交、看 Toast/跳转）。
3b. **打开即抹掉自动化检测标志（硬，`browser_strip_automation_flags`）**：与禁缓存同序、在页面脚本跑人机验证之前执行。WB-OP / Playwright 必须像真人浏览器，否则云端 reCAPTCHA / 人机验证会按「自动化」拦截登录与提交。
   - **宿主 Browser（Cursor ide-browser 等）**：CDP `Page.addScriptToEvaluateOnNewDocument`（或等价 init script）注入：把 `navigator.webdriver` 置为 `undefined`/`false`；需要时再 `Page.reload` 后交互。
   - **正式 Playwright runner**：Chromium `launchOptions` 须含 `--disable-blink-features=AutomationControlled`，并用 `ignoreDefaultArgs` 去掉 `--enable-automation`；`context.addInitScript` 同样清掉 `navigator.webdriver`（仓库 `tests/e2e/playwright.config.js` 已默认开启）。
   - **禁止**：带着默认 `navigator.webdriver===true` / AutomationControlled 去点登录、提交、人机验证；禁止把「Human-machine verification failed / reCAPTCHA 拦自动化」写成 WB-OP pass 或甩测借口。
4. **禁止替代物**：
   - 禁止只用 `curl` / `http:request` 宣称页面可用
   - 禁止只用 PHPUnit / 契约测试宣称 UI 完成
   - 禁止「代码已改，请用户自己打开看」代替 AI 自测（宿主无 Browser 时除外，须明确标注未完成）
   - 禁止带着默认磁盘缓存验收本回合改过的 CSS/JS/HTML（易误判「没改到」）
   - 禁止未抹自动化标志就宣称登录/人机验证路径已验收
5. 未跑通用例时，汇报只能写：**「代码已改，WebUI 验收未完成」**，禁止写「已完成 / 已交付」。

## 门禁 A2：Playwright 端到端（E2E，硬，`ui_feature_requires_e2e` + `plan_full_pathway_e2e_suite` + `forbid_user_manual_test_handoff`）

凡 **`work_kind=feature`**（不限 participate / browser / UI 路径；后端修复同样适用；`non_feature` 除外）还必须：

1. **计划**：每个章节硬绑定独立 `acceptance.type=e2e`（description 须含完整功能通路/前后端/Playwright 等信号；Agent **自动编写并跑通**该章用例，自行闭环，禁止甩给人）。
2. **计划级组套件**：另含 `id=e2e-plan-suite`（或描述含「计划链路/功能链路/e2e组/完整功能通路」）；**全部章节通路 e2e PASS 之后**，统一再跑整条功能链路组测。
3. **真实执行** `php bin/w e2e:run <模块 test/e2e/...spec.js> --project=chromium`（或 `npx playwright test …` + 仓库 `tests/e2e/playwright.config.js`），**默认无头**（`e2e_playwright_headless_default`：不自动弹 Chromium；仅显式 `--headed`/`--ui` 或用户要求观看时才有界面），并把 **PASS evidence** 写入对应验收项；**status 必须为 `passed`**（禁止 `skipped`/`na` 冒充）。组套件 evidence 还须含 suite/组测/多 `.spec.js`/功能链路等信号。
4. **仅正式 runner（硬，`e2e_playwright_formal_runner_only`）**：Playwright 必须由 runner 管生命周期（结束自动收浏览器）。**禁止** Agent 用 `node -e` / 一次性 `chromium.launch` 探活、后台挂起不管，导致 `chrome-headless-shell` 残留（含音频/CPU 泄漏）。缺覆盖则补模块 `Test/e2e` / `test/e2e` 的 `.spec.js` 再走正式入口。宿主 WB-OP（IDE Browser）不受本条约束。
5. **禁止冒充 e2e**：仅 `curl`、仅 IDE Browser CDP `Runtime.evaluate`、口头「浏览器点过了」→ `e2e_evidence_weak` / `plan_suite_e2e_evidence_weak`，`closeout_allowed=false`。
6. **禁止半截汇报 / 甩测给用户**：不得写「请刷新后再试 / 请你测试 / 请自行验证」；只做部分章节、未跑章 e2e 或未跑组套件 → 只能报 **「代码已改，e2e 未通过」**，禁止宣称计划完成。
7. 与 WB-OP 关系：操作员 Browser（WB-OP）**不能替代**本门禁；两者都需要时都要做。

证据须含可机读信号之一：`e2e:run` / `playwright` / `.spec.js` / `passed(N)`。

### 打开即禁用缓存（WB-CACHE，硬）

每次**打开或导航**验收页之前必须禁用该会话的 HTTP 缓存；不要求清空整个浏览器用户配置缓存（宿主常禁止）。

| 宿主示例 | 推荐动作（按序） |
|----------|------------------|
| Cursor ide-browser | `Network.enable` → `Network.setCacheDisabled({cacheDisabled:true})` → `browser_navigate`；若 `setCacheDisabled` 被拒，对该次加载 `Page.reload({ignoreCache:true})` 并注明降级 |
| Playwright / Puppeteer / 其它 CDP | 等价 CDP `Network.setCacheDisabled`，或会话级禁用缓存后再导航 |
| 无 CDP 能力 | 至少对验收 URL 使用强制绕过缓存的刷新；仍须在日志注明限制 |

打开顺序（机器契约 `closeout_delivery_reminder.browser_open_order`）：

```text
disable_http_cache_for_session → navigate_or_reload_ignore_cache → run_wb_op_and_optional_wb_vis
```

## 门禁 B：视觉证据（WB-VIS）

适用：有视觉布局/前台或后台 UI，且**当前宿主能截图**。

- 至少覆盖断点：≈768 / ≥1024；表面面向手机时再加 375（相关时再加 1440）
- 截图存归属模块 `doc/evidence/`（分章则 `doc/evidence/ch{N}/`）
- 模块若有 `doc/原型设计.md` 则对照视觉清单；**无该文件时不虚构原型验收**
- 无截图能力或非视觉面：WB-VIS 记 `N/A`，但 **WB-OP 仍须完成**（有可交互 Browser 时）

## 门禁 C：交付地址汇报（每次功能完成必报）

### 本机默认 Host（硬）

| 项 | 规则 |
|---|---|
| **默认主 Host** | `{project_hash}.test.weline.com`（例：`p05113ef3.test.weline.com`） |
| **主链形态** | `http://{project_hash}.test.weline.com:{port}/path`（本机常为 http，勿伪造 https） |
| **禁止作主验收** | `*.weline.test`（例：`p05113ef3.weline.test`）——即使 `/etc/hosts` 也解析，也不得作为交付主链 |
| **回退** | 仅当不存在可用的 `*.test.weline.com` 时，才用 `127.0.0.1` / `localhost` |
| **MCP 契约** | `agent_guidance.feature_delivery_urls`（含 `default_local_host` / `forbidden_primary_hosts`）、`closeout_delivery_reminder`、`hard_constraints.feature_delivery_urls` |
| **技能 / 规则** | Cursor 技能 `local-browser-urls`；仓库 `.cursor/rules/local-browser-urls.mdc` |

面向用户的**最终/阶段性交付回复末尾**必须有独立小节：

```markdown
## 交付地址

- [后台系统配置](http://p05113ef3.test.weline.com:9555/后台前缀/system-config/...)
- [前台某某页](http://p05113ef3.test.weline.com:9555/path)
- API / Query：`w_query ...` 或 N/A
```

硬要求：

| 项 | 要求 |
|----|------|
| 小节标题 | `交付地址`（或 `Delivery URLs`） |
| 主验收链接 | 可点击 Markdown `[名称](http(s)://完整URL)`（本机 WLS 常为 `http://`） |
| 默认 Host | `{project_hash}.test.weline.com`（例：`p05113ef3.test.weline.com`）；**禁止**把 `*.weline.test` 当作主验收 Host |
| 探活 | 交付前对字面 URL `curl` 探活；失败不得交死链 |
| 覆盖面 | 本功能涉及的全部前台页、后台页；有 API 一并列出 |
| 无 UI | 写 `N/A` + CLI/接口入口，**禁止省略整节** |

禁止：

- 省略「交付地址」小节
- 臆造路由 / Host
- 主链用仅某客户端可点的伪协议（如 `command:simpleBrowser.api.open`）——主链用标准 `http(s)://…` Markdown 链接；宿主 opener 仅可作辅链
- 有可用 `*.test.weline.com` Host 时强行改成 `127.0.0.1`
- 有可用 `*.test.weline.com` 时把主验收写成 `*.weline.test`（即使 `/etc/hosts` 也解析后者）
- 仅变色「打开」文字、无 Markdown 链接语法
- 把源码路径拼成假 URL（如 `…/app/code/.../*.php`）

技能细节：`local-browser-urls`（探活、Host 优先、query 勿二次编码）。

## 标准收口顺序（固定）

```text
1. 用例已定义（URL + 步骤 + 期望）
2. 代码 / Schema / i18n:collect / setup:upgrade 等前置完成
3. AI 用当前宿主真实 Browser：**先禁用 HTTP 缓存**，再跑完 WB-OP（必要时 WB-VIS 截图）
4. curl 探活交付 URL
5. 用户可见回复：结论 + 证据摘要 + 末尾「交付地址」
6. **立即关闭**本回合打开的全部验收 Browser 标签/webview（硬：`browser_release_after_delivery`）
7. 开发日志写入：用例结果、截图路径、URL 清单、所用 Browser 工具名、缓存禁用方式、已关闭验收 Browser
```

## 门禁 D：汇报交付地址后关闭 Browser（WB-REL，硬）

顺序固定：**先写「交付地址」，再关 Browser**。禁止测完不关、禁止把空转标签留给用户手动清。

1. 凡本回合为验收打开过宿主真实 Browser（含 Cursor Glass / Simple Browser / ide-browser、Playwright 会话等），在面向用户的「交付地址」小节写出之后，**必须立即关闭**这些标签/会话。
2. Cursor：先 `browser_lock` unlock（若已锁），再对验收标签逐个 `browser_tabs` `action=close`；不得只 unlock 不关。
3. 其它宿主：结束/关闭等价操作员浏览器会话，不留后台空转进程。
4. **例外**：仅当用户**明确**要求保留标签时可不关，并在汇报中注明「按用户要求保留 Browser」。
5. 本回合从未打开过 Browser（纯逻辑 / N/A）：本门禁记 `N/A`。
6. 违规形态：交付后仍挂着 Browser 标签导致 Renderer 空转占 CPU——视为收口未完成。

## 会话纠正清单

| 违规 | 正确做法 |
|------|----------|
| 「代码改完了」无 Browser | 补跑 WB-OP；未跑则改口为验收未完成 |
| 只 curl 200 就交 UI | curl 只探活；交互必须真实 Browser |
| 带着默认缓存验本回合 CSS/JS | 打开前 `setCacheDisabled` 或 `ignoreCache` 重载 |
| 交付不写地址 | 末尾补「交付地址」小节 |
| 让用户自己找路由 | AI 列出探活过的完整 http(s) 链接 |
| 主 Host 写成 `*.weline.test` | 改用 `{project_hash}.test.weline.com` |
| 规范写死某一 IDE Browser | 改用「宿主可用真实 Browser」表述 |
| 写完交付地址仍不关 Browser | 立即 unlock + close 本回合验收标签；用户未要求保留则不得留下 |

## 相关

- [AI工程交付流程.md](../../Ai/doc/AI工程交付流程.md)
- [AI硬规则索引.md](../../Ai/doc/AI硬规则索引.md)
- [开发标准与验收.md](./开发标准与验收.md)
