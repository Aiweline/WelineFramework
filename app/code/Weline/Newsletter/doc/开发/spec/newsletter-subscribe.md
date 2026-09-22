---
status: ready-for-plan
work_kind: feature
feature_slug: newsletter-subscribe
module: Weline_Newsletter
fe_be_scope: both
plan_complexity: complex
updated: 2026-09-22
clarify_status: answered
team_slug: newsletter-subscribe
align_freeze: closed
---

# 邮件订阅（newsletter-subscribe）需求规格

> 立项波收口 · 需求分析席。澄清已拍板；**对齐冻结会 closed**（测试主持钉死可执行 UC + contracts + deps；架构师同意；扩展点 valid_days=A）→ `ready-for-plan`。实现 how 留给架构/扩展点席。

## 0. 元信息

| 字段 | 值 |
|------|-----|
| `work_kind` | `feature` |
| `fe_be_scope` | `both`（前台 Widget/表单/结账触点 + 后台订阅名单/活动配置 + Smtp 渠道模板 + Marketing 发券） |
| `module` | **新建/补齐** `Weline_Newsletter`（仓内目前仅有 `doc/`，无 `register.php` / Controller / Model） |
| 复杂度 | `complex`（新模块、跨 Marketing/Smtp/Theme 槽、UI 汉服视觉、结账自动用券）→ team 模式 |
| 测试账号（本机） | 后台 `admin`/`admin`；前台 `e2e.customer@weline.local` / `E2eTest!234` |

---

## 1. 目标 / 非目标

### 目标

1. 提供**邮件订阅**能力（访客/客户提交邮箱 + 主题偏好），与 Theme 已有订阅壳表单接通。
2. 前台提供可配置的**订阅弹窗部件**与**页脚订阅部件**；**页脚默认有订阅入口**（注入到 footer 内已声明槽）。
3. 视觉对齐汉服古风：宣纸/信笺气质，支持透明 PNG 背景（对照 `app/design/Weline/hanfu/doc/spec/pages/newsletter.md`）。
4. 「订阅有奖」：折扣活动配置后，对成功订阅邮箱**自动发随机优惠券**，结账侧**尽量自动带上该券**，并在订阅记录上可回查券码。
5. 订阅表单默认勾选：**优惠活动主题**、**上新活动主题**。
6. 在本模块内通过 Smtp `MailChannelProviderInterface` 注册默认事务邮件模板（欢迎/发券通知等）。

### 非目标（本 feature 明确不做）

- 不复用 / 不改造 `Weline_Subscription`（商业周期订购 / 续费）。
- 不并入 / 不扩展 `Weline_Mail`（企业邮箱 / Stalwart 账号管理）。
- 不重写 Marketing 规则引擎；只经已有 Provider / Session SPI 发券与用券。
- 不做完整邮件营销自动化（Drip 序列、AB 测试）——仅订阅入库 + 默认确认/发券邮件。
- 本回合不写生产 PHP/phtml/CSS（仅规格与 team 文档）。

---

## 2. 角色

| 角色 | 说明 |
|------|------|
| 访客 | 未登录，可提交邮箱订阅 |
| 客户 | 已登录，可用账户邮箱或另填邮箱订阅 |
| 店主/运营 | 后台管理订阅名单、主题偏好、有奖活动开关与折扣参数、查看发券记录 |
| 系统 | 发券、写订阅台账、Smtp 发信、结账会话注入券码 |

---

## 3. 用户故事 + EARS（已澄清）

### US-1 页脚 / 弹窗订阅

As a 访客, I want 在页脚或弹窗提交邮箱并勾选主题, so that 我能收到优惠与上新资讯。

- **WHEN** 访客在 `footer-newsletter` 或 `newsletter-popup` 提交合法邮箱 **THEN** 系统 **SHALL** 持久化订阅记录并返回可观察成功态（页内消息或 JSON，以冻结 API 为准）。
- **IF** 邮箱非法或必填缺失 **THEN** 系统 **SHALL** 拒绝订阅且不发券、不发欢迎邮件。
- **WHEN** 表单首次渲染 **THEN** 系统 **SHALL** 默认勾选「优惠活动主题」与「上新活动主题」（访客可取消勾选）。
- **IF** 同一邮箱已处于有效订阅 **THEN** 系统 **SHALL** 更新主题偏好，反馈「已订阅 / 偏好已更新」，且对同一 campaign `source_key` **不**再发欢迎有奖券。
- **WHEN** 表单展示 **THEN** 系统 **SHALL** 在提交区附近展示隐私短注（含「可随时退订」语义）；不另加强制法律页弹窗。

### US-2 订阅有奖发券

As a 访客, I want 订阅成功后自动获得折扣券, so that 我在结账时能立刻享受优惠。

- **IF** 后台「订阅有奖」活动已启用且 Marketing 规则有效，且该邮箱对该 campaign `source_key` **从未**成功领过欢迎礼 **THEN** 系统在**首次有效订阅**成功时 **SHALL** 经 `RandomCouponCampaignProviderInterface::issueRandomCoupon` 发一张随机券，并将 `coupon_code` / `coupon_id` 记入该订阅邮箱台账。
- **WHEN** 发券成功 **THEN** 系统 **SHALL** 通过本模块 Smtp 渠道向该邮箱发送含券码的通知（模板在模块 `default_templates`）。
- **IF** 活动关闭或规则失效 **THEN** 系统 **SHALL** 仍允许纯订阅成功，但**不**发券、不发发券邮件。
- **IF** 邮箱曾退订后再次订阅 **THEN** 系统 **SHALL** 恢复订阅并更新偏好，但欢迎有奖券仍按「终身一次」**不再**发放。
- **WHEN** 默认活动参数未改 **THEN** 系统 **SHALL** 使用 **10% 百分比折扣、券有效期 14 天**（后台可改，对齐 WaitGift 可配）。

### US-3 结账自动用券

As a 已订阅访客/客户, I want 结账时自动带上订阅礼券, so that 我无需手抄券码。

- **WHEN** 订阅成功且同会话仍有效 **THEN** 系统 **SHALL** 尽力经 `MarketingCheckoutCouponSession::applyCoupon` 立即写入 toc 券会话（T1）。
- **WHEN** 结账邮箱或登录邮箱命中订阅台账中**未用且未过期**的欢迎礼券 **THEN** 系统 **SHALL** 自动 apply 该券（T2）；换设备依赖邮箱匹配，不强制手填券码。
- **IF** 同浏览器仍有可选 cookie/pending 券码 **THEN** 系统 **SHALL** 可作增强触发（T3，非唯一依赖）。
- **IF** 券已用尽/过期/不适用当前 cart_type **THEN** 系统 **SHALL** 不阻断结账，仅跳过自动用券。
- **WHILE** cart_type 禁用店面折扣（如批发） **THEN** 系统 **SHALL** 不自动应用订阅礼券。
### US-4 运营配置与名单

As a 运营, I want 在后台查看订阅邮箱与发券记录并配置有奖活动, so that 我能运营邮件列表与优惠。

- **WHEN** 运营打开 Newsletter 后台入口 **THEN** 系统 **SHALL** 在 ACL 门控下展示订阅列表（邮箱、主题偏好、券码、时间）。
- **WHEN** 运营保存有奖活动参数 **THEN** 系统 **SHALL** 经 `RandomCouponCampaignProviderInterface::upsert` 同步 Marketing 随机券活动（对照 Maintenance `WaitGiftCampaignSyncService` 模式），并写入 `CouponSourceAttribution` 可识别来源。

### US-5 默认邮件模板

As a 系统, I want 模块自带 Smtp 渠道与默认模板, so that 订阅/发券邮件开箱可用。

- **WHEN** 模块启用且 Smtp 收集渠道 **THEN** `Weline_Newsletter` 的 `MailChannelProviderInterface` 实现 **SHALL** 注册至少：订阅确认、发券通知（渠道 code 前缀 `Weline_Newsletter::…`）。
- **IF** 后台未改模板 **THEN** 系统 **SHALL** 使用模块内 `default_templates`（`MailTemplateDefaultLocales::fileEntries` 同类写法）。

---

## 4. 用例（已对齐冻结 · 可执行权威在 team）

> **权威可执行步骤 / 断言 / 证据字段**：`doc/开发/team/newsletter-subscribe/meetings/align-freeze.md`（UC-1…UC-4）。  
> 契约与依赖：`contracts.md`、`deps.md`。下表仅保留意图摘要，施工与验收以 team 文件为准。

| id | 名称 | 证据硬字段 | 映射 acceptance |
|----|------|------------|-----------------|
| UC-1 | 页脚订阅 → 发券 → 台账可查 | `subscriber_id` 或 `email` + `coupon_code` | `newsletter-subscribe-footer-happy` |
| UC-2 | 弹窗订阅 + 结账自动用券 | 同上 + **结账 `checkout-coupon` 自动带码可观测** | `newsletter-subscribe-checkout-auto-coupon` |
| UC-3 | 非法邮箱拒绝 | 无新行、无新券 | `newsletter-subscribe-validation` |
| UC-4 | 后台有奖同步 Marketing | rule id + `source_key=subscribe_gift` | `newsletter-subscribe-campaign-sync` |

套件：`newsletter-subscribe-plan-suite`。

---

## 5. 框架映射（建议，供架构冻结）

### 5.1 模块归属

| 候选 | 结论 |
|------|------|
| **新建/落地 `Weline_Newsletter`** | **推荐**。仓内目录已占位但仅文档；路由壳已指向 `newsletter/*`；语义即「邮件订阅」。 |
| 扩展 `Weline_Mail` | **否**。Mail=企业邮箱/Stalwart/本机邮箱账号，与营销订阅无关。 |
| 复用 `Weline_Subscription` | **否**。Subscription=周期付费订购 Provider/续费，命名冲突。 |
| 塞进 `Weline_Marketing` | **不推荐作拥有模块**。Marketing 已提供券 SPI；订阅名单与主题偏好应属于 Newsletter 领域。Marketing 仅被调用。 |
| Theme 独占业务 | **否（已拍板）**。Newsletter 拥有业务 Widget；Theme 仅留 chrome 空槽，壳退役/空引用。 |

### 5.2 Widget vs Hook vs default_injections

**已核实线索：**

- Theme 已注册部件壳：`footer-newsletter`、`newsletter-popup`（及 `sidebar-newsletter`），模板在 `Weline_Theme::theme/frontend/widgets/newsletter/...`。
- 表单 `action="@url{'newsletter/subscribe'}"`，**路由/模块实现尚不存在**。
- `FooterPartialComposer` 硬编码渲染 Theme `footer-newsletter` 模板路径。
- 跨模块默认展示惯例：拥有模块 `widget.php` 声明 **`required` `default_injections`** 注入 Theme 空槽；禁止 Theme 布局硬编码外模块业务部件。

**产品已拍板（Q5）：**

1. **Widget（主）**：`Weline_Newsletter` **拥有**业务部件；**保留 code** `footer-newsletter` / `newsletter-popup`（避免断槽）；弹窗 `page_layouts=*`，delay 默认，show_once cookie **14 天**。
2. **default_injections（主）**：`footer-newsletter` → footer 槽 `required=true`（页脚全站常驻）。
3. **Hook**：主路径不走；仅 injection 覆盖不足时再议（编制可 skip + 理由）。
4. **Theme 迁移**：Theme 壳退役或空引用；`FooterPartialComposer` 停止硬编码 Theme 业务模板。实现细节由架构/主题钉；本席不改生产码。

### 5.3 Smtp

- 扩展点：`Weline\Smtp\Api\MailChannelProviderInterface`（`extends.php` 多实现）。
- 参照：`Weline_Order` / `Weline_Marketing` / `Weline_Product` 的 `extends/MailChannelProvider.php` + `MailTemplateDefaultLocales::fileEntries`。
- Newsletter 在本模块注册渠道 + `view/email/...`（或模块约定目录）默认模板；**不**改 Smtp 核心。

### 5.4 Marketing 券（对照「维护有奖」）

模式原样映射 Maintenance：

| 步骤 | 框架面 |
|------|--------|
| 活动 upsert | `RandomCouponCampaignProviderInterface::upsert(RandomCouponCampaignRequest)` |
| 发券 | `::issueRandomCoupon($ruleId, $context)` |
| 来源归因 | `CouponSourceAttribution` + Coupon `source_module/type/id/key`；建议 type 如 `newsletter_subscribe_gift`（常量落 Newsletter，Marketing label 可后续扩展） |
| 结账会话 | `MarketingCheckoutCouponSession::applyCoupon`（WaitGift redeem 已 best-effort 调用） |
| 前台结账 UI | 既有 `checkout-coupon` Widget（Marketing），Newsletter **不**重做结账券 UI |

**重要**：`weline_marketing_coupon` **无** `customer_email` 列。  
「记录在订阅邮箱上」= **Newsletter 自有订阅台账**存 `email + coupon_code/coupon_id`，而非改 Coupon 表加邮箱（除非扩展点席与 Marketing 协商 schema——默认不做）。

### 5.5 结账自动用券策略（产品已拍板；实现由架构钉契约）

- **T1 + T2 必做**：订阅成功同会话尽力 `applyCoupon`；结账邮箱 / 登录邮箱命中台账未用有效券亦自动带。
- **T3 增强**：同浏览器 cookie/pending 携带券码，非唯一依赖。
- 换设备：靠 **T2 邮箱匹配**，不要求用户手抄券码。
- 禁止 Newsletter 直调 Cart/Checkout 内部 Model；跨模块读写走 Query / Marketing Session API。
### 5.6 UI / 主题 / 汉服

- 线稿：`app/design/Weline/hanfu/doc/spec/pages/newsletter.md`（竖形：弹窗宣纸板 + 页脚轻量条；注明 Theme 壳尚无独立事务邮件模板——由本模块 Smtp 补齐）。
- 古风信笺透明 PNG：部件 `image` / 背景媒体参数（popup 已有 `image` media_image）；主题 Token + hanfu design，禁止硬编码紫白模板风。
- 涉 UI：roster 须 **原型 ∥ 前端 ∥ 主题 ∥ UI** 双轨。

### 5.7 其它框架面（编制提示）

| 席位 | 触发理由 |
|------|----------|
| 扩展点 | 复杂 team 强制；MailChannel + RandomCoupon + Widget injection |
| Hook | 若选 footer Hook 兜底则强制；否则可 skip 并写理由 |
| 事件 | 建议订阅成功派发文档化 Event（供其它模块监听）；对齐冻结时定 |
| 查询 | 若结账/他模块需读「邮箱是否订阅/未用券」→ QueryProvider，禁跨模块读 Model |
| i18n | 新文案；源串简中；模块 CSV 中英；用户若提「翻译」则默认站全语种 |
| ACL | 新后台入口 |
| Setup | 新模块 register + Model + route → 升版 upgrade |
| 合规 | 优惠券 + 结账触点 |
| Taglib | 无领域 select 需求可 skip（主题勾选用标准表单控件即可） |

---

## 6. 耦合提示 / 需求纠偏

1. **路由悬空**：Theme 表单已指向 `newsletter/subscribe`，无实现 → 必须落地 Newsletter 路由，而非改 Theme action 到 Mail/Subscription。
2. **命名冲突**：口语「订阅」易混 `Weline_Subscription` → 对外文案用「邮件订阅 / Newsletter」；代码模块名 `Newsletter`。
3. **Mail 误扩**：禁止把订阅名单塞进 Mail 企业邮箱模块。
4. **Theme 双路径风险**：Theme 部件注册 + `FooterPartialComposer` 硬编码 + Newsletter `default_injections` 若同时存在 → **双渲 footer**。架构必须选「单一默认渲染路径」（产品决议：Newsletter 拥有业务 Widget，Theme 壳退役/空引用）。
5. **「记录在订阅邮箱」≠ Coupon 表加 email**：台账在 Newsletter；Coupon 用 source_* 归因即可。
6. **「结账自动使用」≠ 改 Checkout 核心**：只通过 Marketing checkout coupon session / 既有 apply 通路。
7. **弹窗范围（已拍板）**：全站可触发（`page_layouts`=`*` 或等价）；delay 默认；`show_once` cookie **14 天**。Theme 壳旧元数据仅 homepage/cms_page → 施工时由 Newsletter 部件覆盖。
8. **汉服线稿 vs 电商通用壳**：线稿要求古风信笺；视觉由 hanfu 主题覆盖 Theme 通用壳。

---

## 7. 已决议澄清表（原 Q1–Q5，无 pending）

> 用户指示：按跨境电商常规自行拍板，禁止再问用户。依据：Shopify / 独立站常见 welcome popup + footer capture + welcome discount 惯例。

| ID | 议题 | 决议 | 为何属常规 |
|----|------|------|------------|
| Q1 | 重复订阅 / 欢迎礼 | 再提交 → **更新主题偏好**，成功反馈「已订阅 / 偏好已更新」；**欢迎有奖券终身一次**（同 email + 同 campaign `source_key` 不重复发）；退订后再订 **不再发**欢迎礼 | Shopify/Klaviyo 等 welcome flow 默认「首订一次礼」；重复提交只同步偏好防刷券 |
| Q2 | 自动用券 | **T1+T2 必做**；T3 cookie 同浏览器增强；换设备靠邮箱匹配，不强制手填 | 独立站常见：订阅当下带码 + checkout 邮箱匹配自动 apply；跨设备靠邮箱而非本地 storage |
| Q3 | 折扣默认 | **10% 百分比**；有效期 **14 天**；后台可改；**不用固定额作默认** | 跨境 welcome 弹窗最常见 10% off；14 天紧迫感适中；百分比随客单价更稳 |
| Q4 | 弹窗 / 页脚范围 | 弹窗 **全站可触发**（`*`）；trigger=delay 默认；**show_once cookie 14 天**；页脚 **全站常驻** | 全站 capture 为独立站默认；频控 7–30 天常见，取 14 略宽于壳默认 7 |
| Q5 | 部件归属 | **Newsletter 拥有业务 Widget**（保留 code `footer-newsletter` / `newsletter-popup` 免断槽）；Theme 壳退役或空引用由架构钉；视觉 hanfu 覆盖 | 对齐 Review/Customer：业务模块拥有部件 + `required default_injections` |

---

## 7b. 产品决策（跨境常规补充，已拍板）

| 项 | 决策 |
|----|------|
| 主题勾选 | 「优惠活动」「上新」**默认勾选**，访客可取消 |
| 邮箱校验 | 须合法邮箱格式，否则拒绝且无副作用 |
| 隐私短注 | 表单下展示「可随时退订」类一句；**不**另加强制法律页弹窗 |
| 营销同意 | 提交订阅 = 同意按所选主题接收营销邮件（跨境常见：主题勾选即 consent 证据） |
| 主题语义 | 双主题 = 营销邮件偏好；本 feature **至少入库**；按偏好批量发信可后续迭代 |
| 欢迎礼默认 | 10% / 14 天 / 后台可改（见 Q3） |
| 弹窗频控 | show_once + cookie 14 天（见 Q4） |

---

## 8. 隐形需求摘要

- Theme 槽位与编辑器已认知 `footer-newsletter`；须保持槽契约兼容（领域探查曾标 footer 槽缺口 → 主题席施工）。
- Smtp 渠道 code 禁止后台手造；模块代码声明。
- toc 默认可券、非 toc 失败关闭（Marketing 已有）。
- 验收须真实业务通路（订阅行 id / coupon_code / 可选 order），禁止仅模板字符串 UT。
- 脏工作区保留：施工禁 `git restore/clean` 擦未提交改动。
- 欢迎礼幂等键：`email` + campaign `source_key`（及订阅台账「已发欢迎礼」标记）。

---

## 9. 就绪检查

- [x] `work_kind` / `fe_be_scope` 已写
- [x] ≥1 用户故事 + 每故事 ≥2 EARS
- [x] ≥1 主路径用例 + 异常用例
- [x] 非目标明确
- [x] 框架映射与纠偏已写
- [x] 澄清清单已拍板（跨境常规，用户授权团队自决）→ **`clarified`**
- [x] 对齐冻结会钉死 UC/contracts/deps（测试主持 + 架构师同意 + 扩展点 valid_days=A）→ **`ready-for-plan`**
- [x] 未写入具体类实现步骤（how 留给架构）
- [x] 架构师 / 扩展点通道 stance（msg-2 / msg-3；测试 msg-4 收口）

**当前 `status: ready-for-plan`**。下一步：按 `deps.md` 唤醒施工-1（Setup/ACL/开槽/Marketing valid_days SPI）。

---

## 10. 澄清记录

| 时间 | 来源 | 结论 |
|------|------|------|
| 2026-09-22 | 立项波·需求分析 | 初稿落盘；Q1–Q5 待答 |
| 2026-09-22 | 用户指示 + 需求分析拍板 | 「按跨境电商常规，团队自决，勿再问」。Q1–Q5 全部决议写入 §7 / §7b；`clarify_status=answered`；`status=clarified` |
| Q1 | 团队拍板 | 再提交更新偏好 +「已订阅/偏好已更新」；欢迎礼终身一次；退订再订不发礼 |
| Q2 | 团队拍板 | T1+T2 必做；T3 增强；换设备靠邮箱 |
| Q3 | 团队拍板 | 默认 10% / 14 天；后台可改；非固定额默认 |
| Q4 | 团队拍板 | 弹窗全站 + delay；cookie 14 天；页脚全站常驻 |
| Q5 | 团队拍板 | Newsletter 拥有 `footer-newsletter`/`newsletter-popup`；Theme 壳退役/空引用；hanfu 视觉 |
| 2026-09-22 | 对齐冻结会·测试主持 | 可执行 UC-1…UC-4 + contracts + deps 落盘；架构师/扩展点已同意（valid_days=A）；`status=ready-for-plan`；会 closed |