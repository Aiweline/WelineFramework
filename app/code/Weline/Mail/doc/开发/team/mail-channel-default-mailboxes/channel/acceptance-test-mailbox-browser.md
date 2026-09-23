# Browser 正式签收 · 企业邮箱「邮箱」页（Team:测试:）

日期：2026-09-22  
席位：Team:测试:（真实子智能体）  
清单权威：`channel/acceptance-ui.md`  
主 URL：`https://p05113ef3.test.weline.com:9555/jRaxfEJaRUyO6ZBOA3wJX8bituje6oqH/weline_mail/backend?view=mailbox`  
client_session_id：`team-mail-ui-test-20260922`  
MCP：`prepare_project` status=ready（receipt `weline-mcp-1790070752826-fac0cf151f7c2644`）

## Verdict

**pass**（复测通过；首轮 2026-09-22 晚间为 fail，见下文「首轮」与「复测」）

首轮 #1/#4 fail → UI+前端返工后 Browser 禁缓存复测，要点 **1–6 全 pass**。未向用户要密码；沿用本机会话。

## 验收环境

| 项 | 结果 |
|---|---|
| Browser | Chrome DevTools MCP（`cursor-ide-browser` 无可用 tab / navigate 报 No browser tab；改用已挂载本机会话的 DevTools） |
| 禁缓存 | `navigate_page` + `ignoreCache=true` |
| 抹自动化标志 | `initScript`: `navigator.webdriver → undefined`；探活 `navigator.webdriver` 为 falsy |
| 登录 | 已有后台会话；落地标题 `Weline 管理后台`，非 `admin/login`；blocker≠need_login |
| UI/原型 | 上游声明已过签；主题/前端 closed（本席仅 Browser 签收） |

## 截图要点逐条

| # | 要点 | 结论 | 观察 |
|---|---|---|---|
| 1 | Hero：标题+说明；未配置时 Stalwart **警告条**含 **两个**行动按钮（非孤立 badge） | **fail** | 标题「企业邮箱管理」+说明文案可见。Stalwart 文案「Stalwart 管理连接待配置」落在 heading/`w-cluster`，仅 **1** 个行动链「去域名与 DNS」（`data-testid=mail-enterprise-stalwart-config-link`）。全文 **无**「配置引擎」。未形成「双 CTA 警告条」。 |
| 2 | 主导航三 Tab soft segment，「邮箱」为 current | **pass** | `nav.w-tabs[data-variant=soft]`；三 Tab「邮箱 / 用户与账号 / 域名与 DNS」；「邮箱」`aria-current=page` + `selected`。 |
| 3 | 工具条一行：当前邮箱 select · 文件夹 segment · **写邮件** primary（无 ▶） | **pass** | 「当前邮箱」= `support@quote-test.invalid · Quote Support Test`；「收件箱」「已发送」；主 CTA「写邮件」为 `w-button` 链接（无 ▶ 文案）。 |
| 4 | 点「写邮件」→ compose **卡片**展开（标题「写邮件」+ **取消**），**非** summary | **fail** | 导航至 `compose=1#mail-compose` 后表单字段可见（发件账号/收件人/主题/正文/发送邮件），但仍是 **`<details>` + `<summary>`**（a11y：`DisclosureTriangle`「写邮件…收起写信」）。compose 区内 **无**「取消」链接（页面其它处「取消」来自清缓存对话框，不计）。 |
| 5 | 空文件夹：左空态双 CTA；右阅读区引导；列表+阅读同框双栏 | **pass** | 左：「这个邮件夹暂无邮件。」+「写第一封邮件」「去用户与账号」；右：「暂无邮件可阅读。写一封或开通更多账号后回来查看。」双栏同屏可见。 |
| 6 | 当前邮箱选项为人读邮箱/显示名，**无**「用户 #1」 | **pass** | 选项文案为人读 `support@quote-test.invalid · Quote Support Test`；`/用户\s*#\d+/` 未命中。 |

## 交互证据（#4）

1. 默认态：工具条下仍有折叠 summary「展开写信」（DisclosureTriangle）。  
2. 点击主 CTA「写邮件」→ URL 含 `compose=1`，details `open=true`，文案变为「收起写信」，表单展开。  
3. 与清单要求的「正式 compose 卡片 + 取消、去掉 details/summary」不符。

## Deliver URL

- 主验收：[`https://p05113ef3.test.weline.com:9555/jRaxfEJaRUyO6ZBOA3wJX8bituje6oqH/weline_mail/backend?view=mailbox`](https://p05113ef3.test.weline.com:9555/jRaxfEJaRUyO6ZBOA3wJX8bituje6oqH/weline_mail/backend?view=mailbox)
- compose 复现：[`…/weline_mail/backend?view=mailbox&account=1&folder=inbox&compose=1#mail-compose`](https://p05113ef3.test.weline.com:9555/jRaxfEJaRUyO6ZBOA3wJX8bituje6oqH/weline_mail/backend?view=mailbox&account=1&folder=inbox&compose=1#mail-compose)

## 建议返工（给 PM 调度，非本席改码）

1. **前端/UI**：去掉写信 `details/summary`；`compose=1` 渲染正式卡片（标题「写邮件」+「取消」收起/返回）。  
2. **前端/UI**：Stalwart 未配置时用 `w-alert`（或等价警告条）并提供 **两个**行动按钮：「去域名与 DNS」「配置引擎」。  
3. 返工后再唤本席复测 Browser。

## Browser 收口（首轮）

已关闭首轮用于签收的 DevTools page（mailbox 验收页）。无需用户接管登录。

## notify_pm（首轮）

`notify_pm: true`

@项目经理：本席已交付/上报，请检查并更新 SESSION。

企业邮箱「邮箱」页 Browser 正式签收 **verdict=fail**（#1 Stalwart 仅单 CTA 且缺「配置引擎」；#4 仍为 details/summary，非 compose 卡片+取消）。#2/#3/#5/#6 pass。纪要见本文件。请安排前端/UI 按建议返工后复测。

---

## 复测（2026-09-22 · Team:测试:）

返工声明：Stalwart → `w-alert` 双 CTA「去域名与 DNS」+「配置引擎」→ `domains#mail-engine-setup`；写信 → `compose=1` 独立 `w-card` + 取消；mailbox 主路径无 details/summary。

### 复测环境

| 项 | 结果 |
|---|---|
| URL | 同主验收 URL；禁缓存 `ignoreCache=true` + `initScript` 抹 `navigator.webdriver` |
| 登录 | 本机会话；标题 `Weline 管理后台`；非 login |
| Browser | Chrome DevTools MCP page（本回合新建后关闭） |

### 复测要点

| # | 结论 | 观察 |
|---|---|---|
| 1 | **pass** | `.w-alert` 文案含「Stalwart 管理连接待配置」+ 说明；双 CTA：「去域名与 DNS」（`mail-enterprise-stalwart-config-link` → `?view=domains`）、「配置引擎」（`mail-enterprise-stalwart-engine-link` → `?view=domains#mail-engine-setup`）。 |
| 2 | **pass** | `nav.w-tabs[data-variant=soft]`；「邮箱」current。 |
| 3 | **pass** | 当前邮箱 select · 收件箱/已发送 ·「写邮件」primary；主路径无 ▶ / 无「展开写信」。 |
| 4 | **pass** | 点「写邮件」→ `#mail-compose` 为 `SECTION.w-card`；`h2`「写邮件」；区内/工具条「取消」链回无 compose 的 mailbox；`details` 邮件写信数=0；无「展开写信/收起写信」。 |
| 5 | **pass** | 左空态双 CTA + 右阅读引导；双栏同屏。 |
| 6 | **pass** | 选项 `support@quote-test.invalid · Quote Support Test`；无人读「用户 #N」。 |

### 复测 Verdict

**pass**

### Deliver URL（复测）

- [`https://p05113ef3.test.weline.com:9555/jRaxfEJaRUyO6ZBOA3wJX8bituje6oqH/weline_mail/backend?view=mailbox`](https://p05113ef3.test.weline.com:9555/jRaxfEJaRUyO6ZBOA3wJX8bituje6oqH/weline_mail/backend?view=mailbox)
- compose：[`…/weline_mail/backend?view=mailbox&account=1&folder=inbox&compose=1#mail-compose`](https://p05113ef3.test.weline.com:9555/jRaxfEJaRUyO6ZBOA3wJX8bituje6oqH/weline_mail/backend?view=mailbox&account=1&folder=inbox&compose=1#mail-compose)

### Browser 收口（复测）

已关闭本复测打开的验收 Browser 页。

### notify_pm（复测）

`notify_pm: true`

@项目经理：本席已交付/上报，请检查并更新 SESSION。

企业邮箱「邮箱」页 Browser **复测 verdict=pass**（#1/#4 返工已核；1–6 全 pass）。纪要已追加本复测节。可更新 SESSION 为测试签收完成。
---

## 标题去重（2026-09-22 · Team:测试:）

UI 声明：已去掉页内重复 h1「企业邮箱管理」。

### 核对条件

可见区域「企业邮箱管理」作为**大标题**只应出现一次（布局壳）；页内不应再有同文案 h1/同等大标题；面包屑末段可保留；侧栏菜单链不计为大标题。

### 环境

| 项 | 结果 |
|---|---|
| URL | `https://p05113ef3.test.weline.com:9555/jRaxfEJaRUyO6ZBOA3wJX8bituje6oqH/weline_mail/backend?view=mailbox` |
| 禁缓存 | `ignoreCache=true` + 抹 `navigator.webdriver` |
| 登录 | 本机会话；非 login |

### 观察

| 检查项 | 结果 |
|---|---|
| 页面 `h1` 总数 | **1** |
| 文案为「企业邮箱管理」的可见 `h1` | **1**（`main` → `.w-backend-page__heading`，32px/600，布局壳） |
| 页内第二枚同文案 h1 / 同等大标题 | **无**（a11y：`main` 内仅 1 个 `heading level=1`；其后为面包屑 StaticText + 说明文案，无第二 h1） |
| 面包屑末段「企业邮箱管理」 | **保留**（`系统管理 / 系统服务 / 企业邮箱管理`，非 heading） |
| 侧栏菜单同名链 | 存在（导航项，不计为大标题） |

### Verdict

**pass**

### Browser 收口

已关闭本小节验收 Browser 页。

### notify_pm

`notify_pm: true`

@项目经理：本席已交付/上报，请检查并更新 SESSION。

企业邮箱「邮箱」页 **标题去重** Browser 核对 **verdict=pass**（大标题「企业邮箱管理」仅布局壳 1 次；面包屑末段保留）。纪要已追加本小节。
