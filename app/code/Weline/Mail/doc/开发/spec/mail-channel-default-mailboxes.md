# 规格：邮局开启后为 Smtp 渠道默认开通域名邮箱

---
status: clarifying
work_kind: feature
feature_slug: mail-channel-default-mailboxes
module: Weline_Mail
related_modules: [Weline_Smtp, Weline_SiteSetupAssistant]
fe_be_scope: backend
plan_complexity: complex
updated: 2026-09-22
session_path: ../session/mail-channel-default-mailboxes.md
team_path: ../team/mail-channel-default-mailboxes/
clarify_status: partial
align_freeze_eligible: false
notify_pm_required: true
---

> 席位：`Team:需求分析:` · 只读探查后落盘。  
> **不写业务生产代码**；实现 how / 机制选型留给架构师与扩展点。  
> 生产站语境：长安汉服 / `www.aiweline.com`；本席**未** SSH 改生产。

---

## 0. 范围声明

| 项 | 值 |
|---|---|
| `work_kind` | `feature`（邮局 × Smtp 渠道默认邮箱闭环） |
| `plan_complexity` | **`complex`**（跨 Mail / Smtp / 建站 Setup；依赖真实域名 + 邮件引擎；生产迁移离开 QQ 外发）→ **team 模式** |
| `fe_be_scope` | **`backend` 为主**：后台邮局账号、Smtp 传输账户 `source_type=mail_account`、渠道绑定、Setup/一键开通。前台店面无新买家面（非目标）。若冻结后仅增加后台「一键开通」按钮，仍算 backend；若加可视化引导页再评估抬前端/UI。 |
| 归属模块 | **主责叙述归 `Weline_Mail`（邮局域名/账号开通）**；Smtp 侧拥有传输账户列表、`smtp_channel_bindings` 与 `smtp.send`。跨模块契约须在对齐冻结钉死。 |
| 生产 | 目标环境为线上主站；**本规格只澄清「要什么」**，不授权本席部署或改生产。 |

---

## 1. 背景 / 问题

1. 用户明确：线上**不要继续依赖 QQ 外发 SMTP**；要开启 **`Weline_Mail` 邮局模块**，并用**本站域名邮箱**发信（不是 `smtp.qq.com`）。
2. 仓内部署映射 `deploy.env-map.php` 仍把生产 Smtp 旧单键映射到 `smtp.qq.com` / `aiweline@qq.com`（legacy `smtp_host` 等），与「域名邮箱 + mail_account」目标冲突，须在方案中改迁或回退策略（**不在本席改配置**）。
3. 框架已具备「业务渠道注册 → 绑定传输账户 → `source_type=mail_account` 走邮局账号发信」的**手动配置面**，但**缺少**「邮局可用后，为全部已注册渠道自动（或一键/Setup）开通默认邮箱用户 + 配好传输账户名/绑定」的闭环。
4. 用户期望团队一起解决；问题涉及运维（DNS/Stalwart）、Mail 账号、Smtp 绑定与建站确认，属复杂 team 需求。

### 本机现状（只读证据）

| 面 | 证据摘要 |
|---|---|
| Smtp 传输 | `Helper\Data::getSenders` / `setSenders` 支持 `source_type` ∈ `external` \| `mail_account`；`mail_account` 须 `mail_account_id` |
| 渠道绑定 | `key_smtp_channel_bindings`：`Module::channel` → 传输账户 `code`（`t…` 类 ID） |
| 发信路径 | `SmtpQueryProvider`：有 channel 则解析绑定 → 若 `mail_account` 则 `sendWithMailAccountSender` |
| 邮局桥 | `Mail\Service\MailSmtpAccountService` 把 active `MailAccount` 暴露为可选发信配置；真实账号经 `StalwartEngineAdapter` |
| 账号开通 | `MailAccountManagementService::createAccount` / `provisionOrResetPassword`（非 fake 则调 `StalwartManagementAdapter`） |
| 渠道注册 | 多模块 Extends `MailChannelProviderInterface`；`MailChannelCollector` 收集（仓内可见十余个固定 code + Websites/Visitor 动态 notify 类） |
| 建站 Setup | `SmtpSetupTaskProvider` 仅：**传输已配置并测试确认**、**渠道模板就绪**、**多语言模板覆盖**；**无**「为渠道开邮局邮箱」任务 |
| Mail Setup | **未发现** `Weline_Mail` 的 SiteSetup SetupTaskProvider |
| 本机引擎 | `php bin/w mail:env:check`：Darwin 上 Stalwart 可执行/端口等多未满足（**代码能力 ≠ 本机服务已跑**） |
| 生产引擎/域名 | **本席未证实**线上是否已装 Stalwart、是否已有邮箱域名（SESSION 记「初读生产 domains=0」为并行探查信息，规格以 OQ 标出） |

---

## 2. 目标 / 非目标

### 目标

1. **发信源切换意图**：生产事务/通知邮件默认走 **本站域名邮箱**（经 `Weline_Mail`），不再以 QQ 外发 SMTP 为长期依赖。
2. **传输账户可读**：为 Smtp 各发信用途准备带**显示名称**的传输账户，且 `source_type=mail_account`，关联邮局账号。
3. **渠道可发**：已注册 `MailChannelProvider` 渠道在目标 scope 上具备 **channel → 传输账户** 绑定，使 `w_query('smtp','send',['channel'=>…])` 不再报「未绑定传输账户」。
4. **默认邮箱开通**：邮局模块在「域名与引擎可用」前提下，**自动或等价一键/Setup** 为全部（或策略选定的）已注册渠道开通对应默认邮箱用户（见 OQ-1 粒度）。
5. **可运营确认**：开通后可通过建站助手 / Smtp 测试确认路径证明可发（对齐既有 `smtp_setup_confirmed` 语义）。

### 非目标

- 本 feature **不**重写邮件模板体系 / 品牌壳 / 渠道注册模型（沿用 Extends + `SmtpMailTemplate`）。
- **不**把业务渠道 code 改成传输账户 ID；传输 ID 仍由系统生成，渠道仍 `Module::code`。
- **不**在本规格阶段改生产、改 `deploy.env-map` 密钥、或 SSH 写库（仅澄清；施工/运维另波）。
- **不**要求买家前台新增邮箱产品面（企业邮箱个人中心既有能力除外）。
- **不**默认强制本机 Darwin 装齐 systemd/Stalwart 作为验收前提；本机可用 fake 域名冒烟，生产用真实引擎（架构拍板）。
- **不**由需求分析席裁定 Stalwart 安装拓扑、DNS 具体值或「一邮箱多渠道 vs 每渠道一邮箱」的最终实现——见 OQ。

---

## 3. 角色

| 角色 | 诉求 |
|---|---|
| 店主/运营 | 开启邮局后少手工配置；渠道有可读发件名；域名邮箱发出 |
| 管理员 | 后台能看到邮局账号、Smtp 传输账户与绑定；能测试发信 |
| 系统 | 渠道发信解析到 `mail_account`；缺绑定/缺账号时有可理解失败与 Setup 提示 |
| 运维 | 生产 DNS/MX/SPF/DKIM + 引擎就绪后，应用层一键/自动开箱 |

---

## 4. 用户故事与 EARS（澄清稿 · 未冻结）

### US-1 离开 QQ 外发，改用域名邮箱

> As a 店主, I want 站点事务邮件从本站域名邮箱发出, so that 不再依赖 QQ SMTP 外发。

**EARS**

1. WHEN 目标 scope 上 Smtp 传输已按本 feature 迁到 `source_type=mail_account` 且邮局账号 active **THEN** 系统 SHALL 经该账号对应 SMTP/引擎路径发信，**SHALL NOT** 再要求 `smtp.qq.com` 作为该 scope 的默认长期传输。
2. IF 仍存在仅 `external`+QQ 的旧传输 **THEN** 系统 MAY 保留作回退/过渡（迁移策略见 OQ-3），但主路径验收 SHALL 以域名邮箱为准。
3. WHEN 发信成功 **THEN** From 所用邮箱 SHALL 属于已启用的 `weline_mail_domain` 下账号（非 `*@qq.com` 作为主路径证明）。

### US-2 渠道绑定与传输账户命名

> As a 管理员, I want 每个发信渠道绑定到有名字的传输账户, so that 配置页可读且发送可解析。

**EARS**

1. WHEN 业务模块经 `MailChannelProviderInterface` 注册了渠道 code **THEN** 系统 SHALL 能在 Smtp 配置页列出该渠道，并允许绑定到某传输账户 `code`。
2. WHEN 传输账户为 `mail_account` **THEN** 系统 SHALL 持久化 `mail_account_id`（及现有派生字段如 email/host），且显示名称非空（缺省可用邮箱 display_name/email）。
3. WHEN 调用 `smtp.send` 且传入已注册 channel **THEN** 系统 SHALL 按 `smtp_channel_bindings`（scope 就近）解析传输；IF 未绑定 **THEN** SHALL 返回明确错误（现有文案语义可保留）。
4. WHILE 本 feature「默认开通」成功 **THEN** 目标策略覆盖的渠道 SHALL 均具备有效绑定（除非运营显式排除，见 OQ）。

### US-3 邮局开启后的默认邮箱（自动 / 一键 / Setup）

> As a 管理员, I want 邮局可用后自动或一键为渠道开好默认邮箱, so that 不必逐渠道手建账号再手绑。

**EARS**

1. WHEN 邮箱域名已 active 且邮件引擎对真实域名可用（或 fake 域用于本机冒烟）**THEN** 系统 SHALL 提供**至少一种**等价行为：自动开通、后台一键、或建站 Setup 可完成项（具体形态对齐冻结定，见 OQ-2）。
2. WHEN 执行默认开通 **THEN** 系统 SHALL 为策略内每个渠道确保：邮局侧存在对应（或共享）邮箱用户 **且** Smtp 侧存在 `mail_account` 传输 **且** channel binding 指向该传输（幂等：已存在则跳过或更新策略由架构定）。
3. IF 域名/引擎不可用 **THEN** 系统 SHALL NOT 静默假装开通成功；SHALL 在 Setup/后台给出可操作提示（装引擎、配 DNS、选域名）。
4. IF 渠道集合随后新增（新模块注册 channel）**THEN** 系统 SHALL 支持再次开通/补齐未覆盖渠道（触发：再次一键、Upgrade、或 Setup 重检——实现留给架构）。

### US-4 建站可检

> As a 建站管理员, I want Setup 能反映「邮局发信就绪」, so that 上线前知道缺什么。

**EARS**

1. WHEN 检查发信就绪 **THEN** 系统 SHALL 能区分：无传输 / 仅有 external QQ / 已有 mail_account 但未绑定渠道 / 已绑定未测试确认 / 模板缺失（后两项可复用现有 Smtp Setup 任务）。
2. WHEN 默认邮箱开通能力落地 **THEN** Setup（或并列任务）SHALL 暴露「渠道默认邮箱」类检查项（新增 Provider 条目 vs 扩展现有 `smtp_transport`——架构定）。

---

## 5. 主路径用例（澄清级 · 待冻结钉死）

| UC | 名称 | 前置 | 步骤摘要 | 期望 |
|---|---|---|---|---|
| UC-1 | 本机 fake 冒烟 | 有 `.test`/`.invalid` fake 域名与账号能力 | 触发默认开通 → 查看 Smtp 传输与绑定 → 测发一渠道 | 日志/fake 收件可见；绑定非空 |
| UC-2 | 真实域名本机/预发 | 域名 active + 引擎可用 | 开通 → 测试邮件到外部收件箱 | From 为本域；非 qq.com |
| UC-3 | 生产切换 | 生产 DNS+引擎就绪（OQ） | 迁离 QQ → 默认开通 → 确认 Setup → 抽测订单/通知渠道 | 生产事务邮件走域名邮箱 |
| UC-4 | 新渠道补齐 | 已完成首轮开通后新增 Provider 渠道 | 再跑开通/Setup | 新渠道有邮箱+绑定，旧渠道幂等 |

---

## 6. 框架已有能力映射（供架构复用 · 非选型结论）

| 能力 | 位置 | 本需求关系 |
|---|---|---|
| 渠道注册 Extends | `Weline\Smtp\Api\MailChannelProviderInterface` + 各模块 `extends/MailChannelProvider.php` | **输入清单**：默认开通的对象集合 |
| 渠道收集 | `MailChannelCollector` | 遍历「全部已注册渠道」 |
| 传输账户 JSON | `smtp_senders`；`source_type` / `mail_account_id` | **写入面**：默认 `mail_account` 传输 |
| 渠道绑定 | `smtp_channel_bindings`；`resolveTransportIdForChannel` | **写入面**：渠道→传输 |
| 邮局账号 CRUD | `MailAccountManagementService` + 后台 `Mail\Controller\Backend\Index` | **开通面** |
| Smtp←Mail 桥 | `MailSmtpAccountService`；Config 页账号选择器 | 已有手动路径；缺自动批处理 |
| 发信 | `SmtpQueryProvider` + `sendWithMailAccountSender` | 消费面；宜少改 |
| 建站 Smtp 任务 | `SmtpSetupTaskProvider`（transport / template / i18n） | **缺口**：无默认邮箱开通检查 |
| 模板种子 | `MailTemplateSeeder` / Setup Upgrade syncAll | 正交：模板 ≠ 传输绑定 |
| 部署旧映射 | `deploy.env-map.php` → prod `smtp.qq.com` | **冲突源**：须迁移计划（OQ-3） |

**缺口（需求分析陈述，非架构结论）**：未发现「按 `MailChannelCollector` 批量 createAccount + setSenders + setChannelBindings」的现成服务或 Setup 任务；当前为后台手工配置闭环。

---

## 7. 澄清记录

| # | 问题 | 答案 | 来源 |
|---|---|---|---|
| Q1 | 是否继续用 QQ SMTP？ | **否**（长期）；要开邮局 + 域名邮箱 | 用户 |
| Q2 | 发信源类型？ | 目标 **`mail_account`**（本站域名邮箱），非 `smtp.qq.com` | 用户 + 代码已支持该类型 |
| Q3 | 渠道是否要有名/绑定？ | **要**：传输账户有显示名；渠道绑定传输；邮局侧有对应用户 | 用户 |
| Q4 | 开通方式期望？ | 邮局开启后**自动**为所有已注册渠道开默认邮箱，或**等价一键/Setup** | 用户 |
| Q5 | 本机是否已有邮局能力？ | **代码与模块能力已有**；本机 Darwin `mail:env:check` 显示引擎/端口未齐——「已支持」指产品能力，不等于本机 Stalwart 已运行 | 只读 CLI + 用户表述 |
| Q6 | 生产 Stalwart/域名？ | **未证实**（标 OQ） | 本席未 SSH 核验；SESSION 并行探查提示 |

---

## 8. 待拍板 OQ（最多 5 · 阻断对齐冻结）

| ID | 问题 | 建议拍板人 | 若不拍板的影响 |
|---|---|---|---|
| **OQ-1** | 默认邮箱粒度：**(A) 每渠道独立邮箱**（如 `order-paid@域`）vs **(B) 少量共享角色邮箱**（如 `noreply`/`orders`/`notify`）再多渠道绑定同一传输 vs **(C) 混合**（事务共享 + 营销分离）？本地 part / display_name 命名规则？ | 电商顾问 + 架构师 + 用户 | 无法定开通算法与 DNS/配额规模 |
| **OQ-2** | 触发形态：**(A) 模块/域名启用时全自动** vs **(B) 后台一键** vs **(C) 仅建站 Setup 任务** vs **(D) A+B/C 组合**？失败时是否阻断发信？ | 架构师 + Setup + 用户 | 无法写冻结 UC 与验收 |
| **OQ-3** | 生产迁移：QQ `external` 是 **立即停用**、**双轨过渡**、还是 **仅新 scope 用 mail_account**？`deploy.env-map` 旧 `smtp_*` 单键如何退役？ | 项目经理 + 运维/后端 | 生产切换有回滚与中断风险 |
| **OQ-4** | 生产前置：线上是否已有 **Stalwart（或等价引擎）+ 邮箱域名（如 `aiweline.com`）+ DNS（MX/SPF/DKIM）**？若无，本 feature 应用层与运维安装的依赖顺序？ | 领域探查（只读）+ 运维 + 用户 | UC-3 无法排期 |
| **OQ-5** | Scope：默认开通写在 **Global** 还是 **默认 Website**？多网站是否各自一套域名邮箱？ | 架构师 + 用户 | 绑定写错 scope 导致店面仍走 QQ/空绑定 |

---

## 9. 建议上场席位（不代 PM 关项）

见 `../team/mail-channel-default-mailboxes/roster.md`。

**本波已建议**：项目经理、需求分析（本席）、领域探查。  
**澄清后建议**：架构师、扩展点、Setup、后端（Mail/Smtp）、测试；触及通知命名/运营策略时电商顾问；新可见文案时翻译工程师；批量开通热路径时性能检查工程师。  
**本波可 skip**：UI/原型/前端/主题（除非冻结后明确要新后台向导页）、支付席。

---

## 10. 验收方向（澄清级 · 非冻结清单）

1. 契约/单测：默认开通幂等；绑定写入后 `resolveTransportIdForChannel` 非空；`mail_account` 缺账号失败语义。
2. 本机：fake 或真实引擎下至少 1 条渠道测发证据。
3. 生产（授权后）：抽测 ≥1 事务渠道，From 为本域；Setup「发信确认」可 done。
4. 回归：模板 Setup 任务不因本 feature 误红；未开通时错误可理解。

---

## 11. 风险与依赖

| 风险 | 说明 |
|---|---|
| 引擎/DNS | 无 MX/SPF/DKIM 则外域可达性失败，与应用「已开通账号」脱节 |
| 渠道数量 | 动态 `notify_*` / Websites 类渠道可能导致邮箱爆炸（强化 OQ-1） |
| 密钥 | `deploy.env-map` 含历史 QQ 凭据映射；迁移时勿把密钥写入规格/纪要正文 |
| 双模块归属 | Mail 开账号 vs Smtp 写绑定，需扩展点避免循环依赖 |

---

## 12. 文档与通知

- SESSION：`../session/mail-channel-default-mailboxes.md`
- Team：`../team/mail-channel-default-mailboxes/`
- 通道：`../team/mail-channel-default-mailboxes/channel/req-clarify-20260922.md`

**notify_pm: true**

`@项目经理：本席已交付/上报，请检查并更新 SESSION`
