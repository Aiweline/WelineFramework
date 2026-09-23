# 领域探查：邮局开启后为 Smtp 渠道自动建默认域名邮箱并绑定传输

- **席位**：Team:领域探查:
- **会话**：`team-mail-channel-default-explore-20260922`
- **仓库**：`/Users/weline/Project/Official/框架`
- **证据日**：2026-09-22（本机 bootstrap 只读 + 源码只读）
- **范围**：`Weline_Mail` / `Weline_Smtp` / `Weline_SiteSetupAssistant` / `Weline_Websites`（渠道声明面）
- **禁区**：本席未写业务码；不拍板选型；不下最终冻结。

---

## 1. 结论摘要（给父会话 / PM）

| 维度 | 事实 |
|---|---|
| 目标能力「开启邮局 → 按 Smtp 渠道批量建默认邮箱 → 写入 `smtp_senders`(mail_account) + `smtp_channel_bindings`」 | **不存在端到端编排** |
| 底层积木（域名/账号开通、mail_account 发信、渠道收集、绑定读写） | **大多已有，可复用** |
| 本机运行态 | 仅 1 个 **fake** 域名 + 1 账号；Smtp 仍 **external QQ**，渠道几乎全绑 `default` |
| 触发点 | 域名 `set-domain-status→active` **仅改状态**，无 Observer / SetupTask / CLI 联动 Smtp |

---

## 2. 现有能力表

| # | 能力 | 位置 | 说明（事实） |
|---|---|---|---|
| M1 | 邮箱域名创建 | `Mail/Controller/Backend/Index::postCreateDomain` | 引擎 `stalwart`\|`fake`；fake 限 `.invalid`/`.test`；真实域名须 Websites 候选；初始 `status=pending` |
| M2 | 域名启停 | `postSetDomainStatus` | 写 `weline_mail_domain.status`；**无后续副作用** |
| M3 | 账号开通 | `MailAccountManagementService::createAccount` | 写 `weline_mail_account`；非 fake 走 `StalwartManagementAdapter::provisionAccount` |
| M4 | Fake vs Real | `MailCustomerAccountService::isFakeDomain`；`StalwartEngineAdapter` / `MailFakeMailboxService` | fake：本地箱；real：Stalwart |
| M5 | Smtp 侧账号配置桥 | `MailSmtpAccountService` | `searchAccounts` / `getAccountConfig` / `sendViaAccount`(仅 fake) / `sendViaAuthorizedAccount` |
| M6 | Mail Query | `MailQueryProvider` | `getSmtpAccounts` / `getSmtpAccountConfig` / `sendViaSmtpAccount`(fake) / `listLocalMailboxes` 等 |
| M7 | 发信成功事件 | `Weline_Mail::mail_message_sent`（`event.php`） | 代发/鉴权发信后；**非域名开启事件** |
| M8 | Mail 前端配置 Observer | `Observer/MailFrontendConfigResourceChanged` | 仅 `resource_changed` → 前台注册配置缓存失效 |
| M9 | Mail CLI | `Console/Mail/{Env,Service,Dns}/*` | 环境安装/启停/DNS；**无批量建箱/绑渠道** |
| S1 | 发件人配置 | `Smtp/Helper/Data`：`smtp_senders` | `source_type` ∈ `external` \| `mail_account`；`setSenders` 会 **clearSetupConfirmed** |
| S2 | 渠道→传输绑定 | `smtp_channel_bindings` | `get/setChannelBindings`；`resolveTransportIdForChannel` |
| S3 | 渠道收集 | `MailChannelCollector` + `MailChannelProviderInterface` | Extends 多实现；code 正则 `^[A-Za-z][A-Za-z0-9_.:-]*$` |
| S4 | mail_account 发信路径 | `SmtpQueryProvider::send` → `sendWithMailAccountSender` | fake→`w_query('mail','sendViaSmtpAccount')`；real→合并 Stalwart SMTP 配置走 `SmtpSender::sendWithConfig`（密码取 sender 配置） |
| S5 | 后台绑定 UI | `Smtp/view/Backend/Config.phtml` + `Controller/Backend/Config` | 可选「自建邮局账号」、渠道绑定面板；**手工** |
| S6 | Smtp SetupTask | `SmtpSetupTaskProvider` | 仅：`smtp_transport` / `smtp_mail_template` / `smtp_mail_template_i18n`；**无「邮局账号/自动绑定」** |
| S7 | 品牌契约 | `MailBrandContextService` | From/Subject 品牌；与传输源无关 |
| W1 | Websites 域名候选 | Mail `Index` 创建真实域名时校验 Websites 候选 | **不负责** Smtp 绑定 |
| SSA | 建站助手 | `SetupTaskCollector` | 已登记 Smtp 等；**Mail 未登记 SetupTaskProvider**（`MigratedSetupTaskProvidersContractTest` 列表无 Mail） |

### 2.1 渠道命名约定（事实）

- 统一形态：`{ModuleName}::{slug}`（例：`Weline_Order::order_paid`）。
- 变体：`Weline_Visitor::notify_pixel_incident_{type}`；`Weline_Backend::notify_*`（部分由 TopicCollector 动态补）；`Weline_Websites::notify_domain_*`。
- **无**「渠道 code → 默认 local_part / 默认邮箱」的 Extends 契约或数据表。

### 2.2 本机渠道快照（bootstrap 2026-09-22）

- `MailChannelCollector::collect()`：**36** 条。
- `smtp_channel_bindings`：**30** 条，值均为传输 id `default`。
- **未绑定 6**：`Weline_Backend::notify_system_info|warning|user_activity`，`Weline_Dropship::fulfillment_consolation`，`Weline_Marketing::cart_abandon_reminder|welcome_customer`。

---

## 3. 缺口表

| # | 缺口 | 证据 | 影响 |
|---|---|---|---|
| G1 | 「域名 active / 邮局就绪」→ 批量建默认邮箱 | `postSetDomainStatus` 无联动；无相关 Service/CLI | 目标主路径缺失 |
| G2 | 按渠道（或按角色组）生成 local_part / display_name 的策略 | 无 Provider / 配置 / 文档契约 | 无法定义 noreply/orders/support 等默认箱 |
| G3 | 自动写入 `smtp_senders`（`source_type=mail_account`） | 仅后台 Config 手工保存 | 传输账户不会出现 |
| G4 | 自动/批量写 `smtp_channel_bindings` | 仅手工；现有多为绑 `default`(QQ) | 渠道不会切到自建箱 |
| G5 | Mail 侧 SetupTask（邮局域名/账号就绪） | SSA 合同测试与 extends 均无 Mail | 建站助手不提示「邮局→Smtp」闭环 |
| G6 | 域名状态变更领域事件 | 无 `mail_domain_*` 事件；仅有 `mail_message_sent` | Observer 扩展点未预埋 |
| G7 | 幂等与冲突策略 | 无 | 与现有 QQ `default`、半绑定渠道、重复邮箱 409 未定义 |
| G8 | real 账号密码进 Smtp sender | `sendWithMailAccountSender` 用 `senderConfig.smtp_password`；fake 免密 | 自动绑定时密码从哪来、是否存 SystemConfig 未定 |
| G9 | 一键脚本 / 运维 CLI | Mail CLI 仅 Env/Service/Dns；Smtp 无 Console | 无运维补救入口 |
| G10 | 单箱多渠道 vs 一渠一箱产品语义 | 无 | 架构需选型（本席不拍板） |

---

## 4. 本机运行态（只读 bootstrap）

| 对象 | 结果 |
|---|---|
| `MailDomain` | **1**：`quote-test.invalid`，`engine=fake`，`status=active`，`hostname=mail.quote-test.invalid`（id=2） |
| `MailAccount` | **1**：`support@quote-test.invalid`，`active`，display=`Quote Support Test`（id=1） |
| `smtp_senders` | **1**：`code=default`，`source_type=external`，`smtp_host=smtp.qq.com`，`smtp_username=aiweline@qq.com`，**无** `mail_account_id` |
| 渠道绑定 | 30→`default`；6 未绑（见 §2.2） |
| 结论 | 邮局冒烟用 fake 已开；**事务邮件传输仍 external QQ**；`mail_account` 路径代码存在但本机未启用 |

---

## 5. 风险 / 冲突点

| # | 冲突 / 风险 | 说明 |
|---|---|---|
| R1 | 覆盖 QQ 默认传输 | 现网/本机绑定几乎全指向 `default`(QQ)。自动改绑若无「仅空绑定 / 仅未确认 / 显式确认」策略，会静默改发信出口 |
| R2 | `setSenders` 清确认态 | 自动写 senders → `clearSetupConfirmed` → SetupTask `smtp_transport` 回 `doing`，与「一键完成」体验冲突 |
| R3 | fake vs real 双路径 | 自动建箱若落在 fake 域，Smtp 渠道发信走虚拟箱；生产期望 Stalwart 时语义不符 |
| R4 | real 密码与 ACL | Query `sendViaSmtpAccount` 禁 real 代发；Smtp `mail_account` real 需密码在 sender 配置；自动开通密码落盘敏感 |
| R5 | 渠道增长 vs 邮箱膨胀 | 36 渠道若一渠一箱 → 账号爆炸；若单箱绑全渠道 → From 身份单一，与「各渠道默认域名邮箱」文案可能冲突 |
| R6 | 未绑渠道与动态 Backend 渠道 | Topic 动态补的 `notify_*` 可能后出现；一次性绑定会漏 |
| R7 | 跨模块所有权 | 邮箱在 Mail，绑定在 Smtp SystemConfig；编排归属 Mail / Smtp / SSA 未定 → 易双写或循环依赖 |
| R8 | Websites 真实域名门槛 | 真实域名必须先 Websites 候选；「开启邮局」若指仅 fake，与品牌站域名解耦 |
| R9 | 账号 409 / 域名 pending | `createAccount` 对非 fake 要求域名 active；编排顺序错会失败 |
| R10 | 品牌 From 契约 | 切到 mail_account 后 From 邮箱变域名箱，显示名仍走品牌服务——需验收收件箱展示，非阻塞但易误判「没绑上」 |

---

## 6. 建议给架构师的候选机制（不拍板）

仅列可挂接点与权衡事实，**不做最终选型冻结**。

### 候选 A — Domain Active Observer / 领域事件

- **挂点**：在 `postSetDomainStatus`（或抽 DomainService）派发如 `Weline_Mail::mail_domain_activated`；Smtp 或编排 Service 订阅。
- **利**：贴合「开启邮局」语义；与现有 `mail_message_sent` 风格一致。
- **弊**：需新增事件契约；同步 Observer 失败回滚策略要设计；本机无现成事件。

### 候选 B — SetupTask 扩展（Mail 新 Provider 或 Smtp 增子任务）

- **挂点**：`SetupTaskProviderInterface`（Mail 新 Provider 或 Smtp 增 `smtp_mail_account_bind`）。
- **利**：建站助手可见；与现有 `smtp_transport`/模板任务并列。
- **弊**：SetupTask 现状是**检测+跳转**，非执行器；「自动建箱」仍要另有 Service；Mail 目前完全未接入 SSA。

### 候选 C — 编排 Service + 显式 Query / CLI

- **挂点**：新 `MailSmtpChannelBootstrapService`（名待定）+ `MailQueryProvider`/`SmtpQueryProvider` 运维 op + 可选 CLI。
- **利**：可幂等、可 dry-run、可手工补救；复用 `MailAccountManagementService` + `Data::setSenders/setChannelBindings`。
- **弊**：须定义触发谁调用（UI 按钮 / 域名开启 / 建站一键）。

### 候选 D — Extends「默认邮箱声明」Provider

- **挂点**：新 Interface（例：每渠道或每模块声明 `local_part` / `shared_mailbox_role`），由 Collector 汇总后开通。
- **利**：渠道方自声明，避免硬编码 36 个 local_part。
- **弊**：10+ 模块要改 `MailChannelProvider` 或平行 Provider；与现有「只声明 channel/模板」边界扩张。

### 候选 E — 仅「共享运营箱」+ 全渠道绑同一 transport

- **挂点**：域名 active 后建 `noreply@`/`support@` 等少量箱 → 一个 `smtp_senders` mail_account → 批量 `setChannelBindings` 指向该 transport id。
- **利**：实现量小；匹配本机已有 `support@quote-test.invalid` 形态。
- **弊**：与「各渠道默认邮箱」字面可能不符；From 身份单一。

### 候选 F — 不自动改绑，仅「建议绑定」UI

- **挂点**：Smtp Config / Mail 域名页提示「可用本机账号一键建议绑定」。
- **利**：避免 R1 静默覆盖 QQ。
- **弊**：达不到「开启后自动」产品句。

---

## 7. 已有可复用 vs 必须新建

### 已有可复用（优先组合，勿重造）

1. `MailAccountManagementService` / `StalwartManagementAdapter` / fake 开通路径  
2. `MailSmtpAccountService` + `MailQueryProvider`（账号配置桥）  
3. `Smtp\Helper\Data::{get,set}Senders` / `{get,set}ChannelBindings` / `generateTransportId`  
4. `SmtpQueryProvider::sendWithMailAccountSender`（发信已通）  
5. `MailChannelCollector`（渠道全集）  
6. Smtp Config UI（手工路径可作为对照验收面）  
7. Websites 域名候选约束（真实域名创建已有）

### 必须新建（当前仓内无对等实现）

1. **编排**：触发（域名 active / 一键 / Setup）→ 建箱 → 写 sender → 写 bindings 的幂等流水线  
2. **策略**：一渠一箱 / 角色共享箱 / 仅未绑渠道 / 是否覆盖 QQ  
3. **可选 Extends**：渠道默认 local_part 声明（若产品要「每渠一箱」）  
4. **触发面之一**：领域事件 **或** Mail SetupTask **或** 显式 Query/CLI（可并存，但至少要有一条权威主路径）  
5. **real 密码与确认态** 产品规则（配合 G8 / R2）

---

## 8. 关键源码索引（便于架构 / 后端跟读）

| 主题 | 路径 |
|---|---|
| 域名创建/启停 | `app/code/Weline/Mail/Controller/Backend/Index.php` |
| 账号开通 | `app/code/Weline/Mail/Service/MailAccountManagementService.php` |
| Smtp 账号桥 | `app/code/Weline/Mail/Service/MailSmtpAccountService.php` |
| Mail Query | `app/code/Weline/Mail/extends/module/Weline_Framework/Query/MailQueryProvider.php` |
| 渠道收集 | `app/code/Weline/Smtp/Service/MailChannelCollector.php` |
| 绑定/发件人 | `app/code/Weline/Smtp/Helper/Data.php` |
| mail_account 发信 | `app/code/Weline/Smtp/extends/module/Weline_Framework/Query/SmtpQueryProvider.php` |
| SetupTask | `app/code/Weline/Smtp/extends/module/Weline_SiteSetupAssistant/SetupTask/SmtpSetupTaskProvider.php` |
| 渠道 Provider 样例 | `app/code/Weline/*/extends/MailChannelProvider.php`（及 Backend `Extends/`） |

---

## 9. 席位收口

`notify_pm: true`

@项目经理：本席已交付/上报，请检查并更新 SESSION

报告路径：`app/code/Weline/Mail/doc/开发/team/mail-channel-default-mailboxes/channel/explore-mail-smtp-auto-bind.md`
