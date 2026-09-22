## msg-1 | 2026-09-22T01:35:00+08:00 | from:项目经理 | to:* | thread:align-freeze | kind:handoff
agent_id: parent
body:
用户授权：按跨境电商常规拍板，禁止再问用户。
规格已 clarified（Q1–Q5 见 spec §7）。
架构师草案：meetings/tech-scheme-draft.md + surfaces.md + components.md。
本线程目标：钉死可执行 UC + contracts.md + deps.md → 规格升 ready-for-plan。
参会：测试（主持）+ 架构师 + 扩展点；涉 UI 后续原型/前端/主题/UI 在施工前组件协商。
---

## msg-2 | 2026-09-22T09:40:00+08:00 | from:测试 | to:* | thread:align-freeze | kind:stance
agent_id: test-seat-align-freeze
body:
**同意冻结草案**（测试主持；验收意图未改，沿用 Q1–Q5 / EARS / 跨境默认 10%/14 天、T1+T2、弹窗 cookie14、Newsletter 拥有 Widget）。

已落盘：
1) 可执行 UC-1…UC-4 → meetings/align-freeze.md（BASE http://p05113ef3.test.weline.com:9555/ ；证据硬字段 subscriber_id|email + coupon_code；UC-2 结账自动用券可观测）
2) contracts.md → 后端↔前端、BinQuery、后端↔Marketing SPI、主题↔footer槽、Smtp、ACL/Setup、T2 事件辅助
3) deps.md → 主题开槽→注入；Marketing upsert→发券；部件迁移→Theme删壳；并行波 施工-1…5

套件：newsletter-subscribe-plan-suite；e2e名 footer-happy / checkout-auto-coupon / validation / campaign-sync。

**待架构师/扩展点 stance**（请项目经理 resume）。若架构否决，测试将改：BinQuery/响应字段名、T2 Event→Interface 表述、valid_days 未进 SPI 时的过期断言措辞、cookie 键名回填、配置存储路径、双发布退役时的「DOM 计数=1」——**不会**放弃终身一次礼、T1+T2 可观测用券、非法邮箱零副作用、Newsletter 拥有 Widget。

规格 frontmatter 已建议/升 `ready-for-plan`（三者已由测试钉死；他席异议回会）。
result=hosted-frozen-by-test
---

## msg-2 | 2026-09-22T09:37:23+08:00 | from:架构师 | to:* | thread:align-freeze | kind:stance
agent_id: architect-align-freeze
body:
**同意冻结推荐方案**（与 tech-scheme-draft / spec §7 一致，无反对票）：

1. 模块落地 `Weline_Newsletter`；命名隔离 Subscription / Mail。
2. **Q5**：Newsletter **拥有** Widget（保留 code `footer-newsletter` / `newsletter-popup`）；Theme 壳退役 + `FooterPartialComposer` newsletter 硬编码停用；`required default_injections`；**否决留壳**。
3. **Q2**：T1+T2 必做；T3 cookie 增强可选。
4. **Q3**：默认 percentage **10%** / 有效期 **14 天**，后台可配。
5. **Q1**：重复提交更新偏好；欢迎券终身一次（台账 `gift_status` 门禁）。
6. **Q4**：弹窗全站 + cookie 14 天；页脚常驻。
7. **硬前置**：Theme `footer-container` **必须先**新增 `footer-newsletter` 槽，否则不得宣称 injection 完成 / 不得开工页脚主路径验收。
8. API：BinQuery `subscribe`（主）+ Frontend `newsletter/subscribe`（兼容表单）→ 同调 Service。
9. Marketing SPI 发券 + Smtp 本模块渠道；台账在 Newsletter。

### valid_days vs Marketing 现网写死 30 天 — 可接受选项排序

| 序 | 选项 | 架构态度 |
|----|------|----------|
| **1（首选）** | `issueRandomCoupon` context 增加 `valid_days`（int，缺省 **30** 兼容 WaitGift）；Newsletter 传配置值（默认 14） | **冻结目标**；扩展点席写入 Marketing SPI 契约 |
| **2** | context 传绝对 `end_date`（UTC SQL）；Newsletter 按配置自算 | 可接受；与 1 二选一即可，优先 1（后台「天数」更直观） |
| **3** | upsert 时把 `valid_days` 写入 rule action `metadata`，issue 从规则读取 | 可接受（活动级默认更稳）；仍属 Marketing 小改，须同波 |
| **4（阻塞）** | Marketing 同波改不进 → **有奖发券路径 deps 阻塞**该 SPI；纯订阅（无券）可并行开工 | 可接受为排期策略；**禁止**对用户宣称 14 天却落库 30 天 |
| **否决** | Newsletter 直改 `Marketing\Model\Coupon` END_DATE | 违反解耦，否决权 |
| **否决** | 永久以 30 天上线并对外写 14 天文案 | 合规/信任风险，否决 |

结论：冻结结论写「券有效期以 Newsletter 配置为准（默认 14）」；实现依赖 Marketing SPI 选项 1 或 2/3；deps 须挂 `Weline_Marketing` valid_days（或等价）条目。

### contracts / deps 核对

- 通道截至本消息：仓内 **尚无** `contracts.md` / `deps.md`。
- 故本席 **暂无不一致可 ask**；测试落盘后请 @架构师 复核。若 contracts 写「固定 30 天」或「Theme 留壳」或漏 footer 槽硬前置，本席将 kind=ask 纠偏。

roster：架构师 → `waiting_peer`（等测试主持 UC/contracts/deps + 扩展点 SPI 表态）。
---

## msg-3 | 2026-09-22T09:38:05+08:00 | from:扩展点 | to:* | thread:align-freeze | kind:stance
agent_id: extension-point
body:
**立场：同意机制落点可冻结**；与架构师 msg-2 对齐，并就 valid_days **裁定推荐 A（=架构首选 1）**。

### 1. surfaces 审核结论
已修订 `surfaces.md`。机制对照《扩展点选型》**齐全**：
- Widget + required `default_injections`（页脚/弹窗）
- `MailChannelProvider`（subscribe_welcome / subscribe_gift）
- `RandomCouponCampaignProvider` upsert + issueRandomCoupon
- QueryProvider / BinQuery（主）+ Frontend Controller 壳
- Event 可选：`Weline_Newsletter::subscribe_after`
- Hook **skip**：槽+injection 足够；Composer 退役后无需 Hook；再渲=双 DOM（否决）
- 跨模块禁令表已写入 surfaces §6

### 2. valid_days：30 vs 14（裁定）
现网 `issueRandomCoupon` 写死 `+30 days`（已核 Marketing Provider L154）。
| 选项 | 结论 |
|------|------|
| **A**（=架构 1）Marketing context `valid_days`；缺省 30 兼容 WaitGift；Newsletter 传配置默认 14 | **推荐并冻结** |
| B Newsletter 首期接受 30 天 | **否决**（违 Q3/US-2；同架构「否决永久 30 天上线」） |
| C 其它（架构 2/3：`end_date` 或 rule metadata） | 可作 A 的等价备选；主契约仍优先 A |

### 3. T2
机制可冻：Event **或** Interface（禁读 Checkout Model）。现网无邮箱专用 Event → 事件名/FQCN 施工前由事件席+Checkout 补 contracts，**不阻塞**机制类别冻结。

### 4. 可否冻结机制落点？
**可以冻结。** 施工闸门（非机制否决）：主题席 footer 槽、Marketing `valid_days` SPI、Theme 同发布退役、T2 事件名或 Interface 钉死。
---

## msg-4 | 2026-09-22T09:42:00+08:00 | from:测试 | to:* | thread:align-freeze | kind:stance
agent_id: test-seat-align-freeze
body:
**收口确认**：已读架构师 msg-2、扩展点 msg-3。contracts/deps 已对齐：
- footer 槽硬前置（deps D3→D9）；Theme 删壳同发布（D10）
- **valid_days=A** 写入 contracts §3 + deps D6（SPI 未合入则 D7 发券阻塞；禁假 14 真 30）
- T2 Event|Interface 可后续钉名，不挡机制冻；UC-2 仍要求结账自动用券可观测
- 无「固定 30 天」/「Theme 留壳」字样

对齐冻结会 **closed**。规格 `ready-for-plan`。请架构师若需再 kind=ask 复核；否则项目经理可按 deps 唤醒施工-1。
result=closed
---

## msg-2 | 2026-09-22T09:37:23+08:00 | from:架构师 | to:* | thread:align-freeze | kind:stance
agent_id: architect-align-freeze
body:
**同意冻结推荐方案**（与 tech-scheme-draft / spec §7 一致，无反对票）：

1. 模块落地 `Weline_Newsletter`；命名隔离 Subscription / Mail。
2. **Q5**：Newsletter **拥有** Widget（保留 code `footer-newsletter` / `newsletter-popup`）；Theme 壳退役 + `FooterPartialComposer` newsletter 硬编码停用；`required default_injections`；**否决留壳**。
3. **Q2**：T1+T2 必做；T3 cookie 增强可选。
4. **Q3**：默认 percentage **10%** / 有效期 **14 天**，后台可配。
5. **Q1**：重复提交更新偏好；欢迎券终身一次（台账 `gift_status` 门禁）。
6. **Q4**：弹窗全站 + cookie 14 天；页脚常驻。
7. **硬前置**：Theme `footer-container` **必须先**新增 `footer-newsletter` 槽，否则不得宣称 injection 完成 / 不得开工页脚主路径验收。
8. API：BinQuery `subscribe`（主）+ Frontend `newsletter/subscribe`（兼容表单）→ 同调 Service。
9. Marketing SPI 发券 + Smtp 本模块渠道；台账在 Newsletter。

### valid_days vs Marketing 现网写死 30 天 — 可接受选项排序

| 序 | 选项 | 架构态度 |
|----|------|----------|
| **1（首选）** | `issueRandomCoupon` context 增加 `valid_days`（int，缺省 **30** 兼容 WaitGift）；Newsletter 传配置值（默认 14） | **冻结目标**；扩展点席写入 Marketing SPI 契约 |
| **2** | context 传绝对 `end_date`（UTC SQL）；Newsletter 按配置自算 | 可接受；与 1 二选一即可，优先 1（后台「天数」更直观） |
| **3** | upsert 时把 `valid_days` 写入 rule action `metadata`，issue 从规则读取 | 可接受（活动级默认更稳）；仍属 Marketing 小改，须同波 |
| **4（阻塞）** | Marketing 同波改不进 → **有奖发券路径 deps 阻塞**该 SPI；纯订阅（无券）可并行开工 | 可接受为排期策略；**禁止**对用户宣称 14 天却落库 30 天 |
| **否决** | Newsletter 直改 `Marketing\Model\Coupon` END_DATE | 违反解耦，否决权 |
| **否决** | 永久以 30 天上线并对外写 14 天文案 | 合规/信任风险，否决 |

结论：冻结结论写「券有效期以 Newsletter 配置为准（默认 14）」；实现依赖 Marketing SPI 选项 1 或 2/3；deps 须挂 `Weline_Marketing` valid_days（或等价）条目。

### contracts / deps 核对

- 通道截至本消息：仓内 **尚无** `contracts.md` / `deps.md`。
- 故本席 **暂无不一致可 ask**；测试落盘后请 @架构师 复核。若 contracts 写「固定 30 天」或「Theme 留壳」或漏 footer 槽硬前置，本席将 kind=ask 纠偏。

roster：架构师 → `waiting_peer`（等测试主持 UC/contracts/deps + 扩展点 SPI 表态）。
---

## msg-3 | 2026-09-22T09:38:05+08:00 | from:扩展点 | to:* | thread:align-freeze | kind:stance
agent_id: extension-point
body:
**立场：同意机制落点可冻结**；与架构师 msg-2 对齐，并就 valid_days **裁定推荐 A（=架构首选 1）**。

### 1. surfaces 审核结论
已修订 `surfaces.md`。机制对照《扩展点选型》**齐全**：
- Widget + required `default_injections`（页脚/弹窗）
- `MailChannelProvider`（subscribe_welcome / subscribe_gift）
- `RandomCouponCampaignProvider` upsert + issueRandomCoupon
- QueryProvider / BinQuery（主）+ Frontend Controller 壳
- Event 可选：`Weline_Newsletter::subscribe_after`
- Hook **skip**：槽+injection 足够；Composer 退役后无需 Hook；再渲=双 DOM（否决）
- 跨模块禁令表已写入 surfaces §6

### 2. valid_days：30 vs 14（裁定）
现网 `issueRandomCoupon` 写死 `+30 days`（已核 Marketing Provider L154）。
| 选项 | 结论 |
|------|------|
| **A**（=架构 1）Marketing context `valid_days`；缺省 30 兼容 WaitGift；Newsletter 传配置默认 14 | **推荐并冻结** |
| B Newsletter 首期接受 30 天 | **否决**（违 Q3/US-2；同架构「否决永久 30 天上线」） |
| C 其它（架构 2/3：`end_date` 或 rule metadata） | 可作 A 的等价备选；主契约仍优先 A |

### 3. T2
机制可冻：Event **或** Interface（禁读 Checkout Model）。现网无邮箱专用 Event → 事件名/FQCN 施工前由事件席+Checkout 补 contracts，**不阻塞**机制类别冻结。

### 4. 可否冻结机制落点？
**可以冻结。** 施工闸门（非机制否决）：主题席 footer 槽、Marketing `valid_days` SPI、Theme 同发布退役、T2 事件名或 Interface 钉死。
---

## msg-5 | 2026-09-22T09:42:12+08:00 | from:架构师 | to:* | thread:align-freeze | kind:reply
agent_id: architect-align-freeze
body:
**复核通过，确认可冻结。** 已读 `contracts.md` + `deps.md`，与 `tech-scheme-draft` / 本席 stance / 扩展点 A 裁定一致：

- Newsletter 唯一 Widget owner；footer 槽 D3→D9 硬前置；D10 同发布删壳
- BinQuery `subscribe` + Controller 同调；T1+T2；10%/14 天
- `valid_days=A`（context int，缺省 30；Newsletter 传 14）；SPI 未合入则 D7 阻塞；无假 14 真 30
- Smtp 两渠道 codes；归因四元组 + `source_key=subscribe_gift`
- 无 Theme 留壳 / 固定 30 天 / 直改 Coupon Model

无 ask 项。roster 架构师 → **closed**。
result=closed
---
