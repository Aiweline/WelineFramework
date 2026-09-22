# 提示词优化 · 语义复审 — browser_strip_automation_flags

| 字段 | 值 |
|------|-----|
| role | 提示词优化工程师 |
| 波次 | 语义复审轨（用户明示新增硬规则，非压缩重复） |
| verdict | **pass** |
| result | closed |
| 日期 | 2026-09-22 |

权威对照：`提示词优化.md` 改写铁律（禁丢义 / 禁乱加；本波例外=用户明示新义务）；`HardConstraintsCatalog` id `browser_strip_automation_flags`；既有 `tester_tests_must_be_real` / `browser_operator_self_test`。  
本席**未**改业务 PHP/phtml；**未**改其它权威文件（未见路径笔误）。

---

## 抽检项（六处权威落点）

| # | 落点 | 同义判定 |
|---|------|----------|
| 1 | `HardConstraintsCatalog.php` → id `browser_strip_automation_flags` | **pass** — MANDATORY；CDP/addInitScript 清 `navigator.webdriver`；正式 runner `--disable-blink-features=AutomationControlled` + 去掉 `--enable-automation`；禁止把 reCAPTCHA/人机验证拦自动化当 WB-OP pass；Complements `tester_tests_must_be_real` / `browser_operator_self_test` / `browser_cache_disabled_on_open`；范围限定本仓本机验收 |
| 2 | `McpSkillCatalog.php` 测试席 `prompt_increment` | **pass** — HARD「抹掉自动化标志」与 Catalog 同义（开页与禁缓存同序 + 禁止拦自动化当 pass/甩测借口） |
| 3 | `dev/ai-command/ai/工程团队.md` 原则 **6c** | **pass** — 与 Catalog 同义；指针指向 WebUI 门禁 doc（相对路径正确） |
| 4 | `WebUI浏览器验收与交付地址门禁.md` 门禁 A **3b** + 禁止项 | **pass** — 宿主 Browser / 正式 Playwright 可执行动作齐全；禁止项含「未抹标志就宣称登录/人机验证已验收」 |
| 5 | `AI硬规则索引.md` 交付行 | **pass** — 触发列含「抹掉自动化标志」；MCP id 列含 `` `browser_strip_automation_flags` ``；禁止/正确做法列与上同义 |
| 6 | `GuidanceWorkflowCatalog` verify 步 notes | **pass** — HARD 句保留 webdriver / AutomationControlled / 禁 reCAPTCHA-block-as-pass；可执行门槛未空泛化 |

契约测试侧（旁证，非改写）：`guidance-workflow-contract.php` / `mcp-skills-catalog.php` 已断言 id + 关键语义信号。

---

## 是否削弱既有硬规则

| 规则 | 判定 |
|------|------|
| `tester_tests_must_be_real` | **未削弱** — 禁止自造假数据/桩 SUT/旁路 INSERT 自验通过的全文仍在；ALLOWED 仍要求真实通路可回查证据。Catalog 仅在 Complements 中**挂接**新 id，未删减原禁止/允许边界。原则 6b 全文保留；6c 为并列新增。 |
| `browser_operator_self_test` | **未削弱** — 仍强制宿主真实 Browser WB-OP、视觉+操作员逻辑、禁 unit/curl/CDP 冒充、无 Browser 只能报未完成。新规则是**前置抹标志义务**，不替代、不豁免 WB-OP。 |

乱加检查：本波为用户明示新义务 → **不构成**「借优化乱加」；六处未额外发明本波范围外的席位/流程（如未改一席一智能体、未改甩测禁令语义）。

---

## 可执行门槛是否仍在

仍可执行（抽检）：

- [x] 开验收 Browser：与禁缓存同序，CDP `Page.addScriptToEvaluateOnNewDocument` / `addInitScript` 清 `navigator.webdriver`
- [x] 正式 Playwright：`--disable-blink-features=AutomationControlled` + 去掉 `--enable-automation`
- [x] 禁止把「Human-machine verification failed / reCAPTCHA 拦自动化」写成 WB-OP pass 或甩测借口
- [x] `tester_tests_must_be_real` 自欺闭环禁止项仍可点名执行
- [x] `browser_operator_self_test` 真 Browser 自测义务仍可点名执行

非阻塞观察（**不**导致 fail；本席按任务范围不改其它文件）：

- 机器契约 `closeout_delivery_reminder.browser_open_order` 数组仍为 `disable_http_cache → navigate → WB`，**尚未**列入显式 strip 步；人读门禁 3b / 硬规则正文已写「与禁缓存同序」，语义可执行。若后续波次要把机器契约与散文完全对齐，可另开最小补丁（非本复审否决项）。

---

## 一句话结论

**pass** — 新 id 在六处权威落点同义且可执行；未削弱 `tester_tests_must_be_real` / `browser_operator_self_test`；用户明示新增，非乱加。

paths_changed（本席）：

- `app/code/Weline/Ai/doc/开发/team/browser-strip-automation-flags/meetings/提示词优化-review.md`（本文件）

无需 escalate 项目经理。
