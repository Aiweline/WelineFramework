# 技术方案草案 · newsletter-subscribe

| 字段 | 值 |
|------|-----|
| role | 架构师 |
| wave | 技术方案草案（对齐冻结会前） |
| feature | newsletter-subscribe |
| module | `Weline_Newsletter` |
| date | 2026-09-22 |
| status | draft-for-freeze |
| track | 只写 `doc/team`；禁止改生产 PHP/phtml/CSS |

> 澄清已按跨境电商常规由团队拍板（对齐冻结方向）：Q1 重复订阅更新偏好 + 欢迎券终身一次；Q2 T1+T2；Q3 默认 10%/14 天可配；Q4 弹窗全站 + cookie 14 天、页脚常驻；Q5 Newsletter 拥有 Widget、Theme 壳退役、`default_injections`。

---

## 1. 背景（极短）

Theme 已有 `footer-newsletter` / `newsletter-popup` 壳与表单 `action=newsletter/subscribe`，但：

- `Weline_Newsletter` 仅有文档，无 `register.php` / 路由 / Model；
- `footer-container` **未声明** `footer-newsletter` 槽 → required injection 硬阻塞；
- `FooterPartialComposer` 硬编码 Theme 模板路径，与未来 injection **双渲风险**。

目标：落地邮件订阅 + 订阅有奖 + 结账自动用券 + Smtp 默认模板，命名与 `Subscription`（周期订购）/`Mail`（企业邮箱）隔离。

---

## 2. 方案总览（推荐）

**单一主路径：**

```text
访客提交邮箱
  → Frontend Controller（渐进增强）或 BinQuery（主 AJAX）
  → Newsletter SubscribeService（校验 / upsert 订阅 / 主题偏好）
  → （活动启用且终身未领）SubscribeGiftIssuer
       → Marketing RandomCouponCampaignProvider::issueRandomCoupon
       → 台账写 coupon_*；Smtp 发欢迎/发券信
       → T1 MarketingCheckoutCouponSession::applyCoupon（best-effort）
  → T2 结账邮箱匹配时 Observer/服务再 applyCoupon
```

**UI：** Newsletter 拥有 Widget（保留 code `footer-newsletter` / `newsletter-popup`）+ `required default_injections`；Theme 壳退役；主题席先开 footer 槽。

**否决「Theme 留壳 + Newsletter 只 API」**（见 §9）：违反 Q5，且无法消除双注册/硬编码风险。

---

## 3. 冻结决策映射

| 项 | 冻结结论 | 方案落点 |
|----|----------|----------|
| Q1 | 重复提交 → **更新主题偏好**；欢迎券 **终身一次** | `SubscribeService::upsert`；台账 `gift_status∈{issued,redeemed}` 则跳过发券，仍可更新偏好并发「偏好已更新」成功态 |
| Q2 | **T1+T2** 自动用券 | T1：发券后立即 `applyCoupon`；T2：Checkout 可识别邮箱命中未用券 → 再 apply；批发/非 toc 不 apply |
| Q3 | 默认 **percentage 10%** / 有效期 **14 天**；后台可配 | `SubscribeGiftCampaignSyncService` upsert + Newsletter 配置；发券 `valid_days` 经 Marketing SPI（见风险） |
| Q4 | 弹窗 **全站** `page_layouts=["*"]`；cookie **14 天**；页脚常驻 | Widget 元数据；`cookie_days` 默认 14 |
| Q5 | Newsletter **拥有** Widget；Theme **退役/迁出**；`default_injections` | 迁移步骤 §6；Theme 禁止同 code 双注册 |

跨境常规补全（未再问用户）：

- 双主题勾选默认：优惠活动 + 上新；
- 邮箱规范化：trim + lower；
- 退订：本 feature 最小做 `status=unsubscribed` 字段与后台操作；公开一键退订链可二期；
- 验证：服务端 `filter_var` + 长度上限；可选 Captcha 二期；
- 券码前缀：建议协商 Marketing 支持 context `code_prefix=NL`；v1 可接受现网 `MW` 前缀但台账与归因仍归 Newsletter。

---

## 4. 模块目录骨架

```text
app/code/Weline/Newsletter/
├── register.php
├── etc/
│   ├── module.php                 # provides / depends
│   ├── event.xml                  # subscribe_after；checkout 邮箱匹配（T2）
│   └── acl.xml                    # 后台名单 / 有奖配置
├── Controller/
│   ├── Frontend/
│   │   └── Subscribe.php          # POST newsletter/subscribe；委托 Service
│   └── Backend/
│       ├── Subscriber/
│       │   └── Index.php          # 名单 DataTable
│       └── Campaign/
│           └── Config.php         # 有奖开关与折扣参数
├── Model/
│   ├── Subscriber.php             # 订阅台账（含券码绑定）
│   └── CampaignConfig.php         # 或走 SystemConfig；二选一见表结构
├── Service/
│   ├── SubscribeService.php       # 校验 / upsert / 编排发券与邮件
│   ├── SubscribeGiftCampaignSyncService.php  # ↔ RandomCoupon upsert
│   ├── SubscribeGiftIssuer.php    # 终身一次门禁 + issue + 台账
│   ├── CheckoutAutoApplyService.php         # T1/T2 共用
│   └── NewsletterMailSender.php   # 调 Smtp 渠道发信
├── Observer/
│   ├── SubscribeAfterMailObserver.php       # 可选：事件驱动发信
│   └── CheckoutEmailMatchCouponObserver.php # T2
├── extends/
│   ├── MailChannelProvider.php
│   └── module/
│       ├── Weline_Widget/Weline_Newsletter/widget.php
│       └── Weline_Framework/Query/NewsletterQueryProvider.php
├── view/
│   ├── email/                     # default_templates 文件
│   │   ├── subscribe_welcome/
│   │   └── subscribe_gift/
│   └── templates/
│       ├── frontend/widgets/
│       │   ├── footer-newsletter/default.phtml
│       │   └── newsletter-popup/default.phtml
│       └── backend/...
├── i18n/                          # zh_Hans_CN.csv + en_US.csv（施工期）
└── doc/                           # 已有
```

**依赖（建议）：** `Weline_Framework`、`Weline_Frontend`、`Weline_Theme`（槽契约）、`Weline_Widget`、`Weline_Smtp`、`Weline_Marketing`、`Weline_Websites`、`Weline_SystemConfig`、`Weline_Acl`；**禁止** depend `Weline_Subscription` / `Weline_Mail`。

---

## 5. 模块边界与命名隔离

| 领域 | 拥有 | 只调用 |
|------|------|--------|
| 订阅名单 / 主题偏好 / 发券台账 | **Newsletter** | — |
| Widget 业务模板与 injection | **Newsletter** | Theme 只提供空槽 |
| 随机券规则 / 券行 / 结账券会话 | Marketing | Newsletter 经 SPI |
| 邮件传输与模板持久化 | Smtp | Newsletter `MailChannelProvider` |
| footer 槽字面量 | **Theme**（主题席改 `footer-container`） | Newsletter injection 入槽 |

**命名隔离（硬）：**

| 禁止 | 正确 |
|------|------|
| 扩展 `Weline_Subscription` | 模块名 / 路由前缀 `newsletter` |
| 塞进 `Weline_Mail` | Smtp 渠道 `Weline_Newsletter::…` |
| 文案写「订购」易混周期付费 | 对外「邮件订阅 / Newsletter」 |
| 表名 `subscription_*` | `weline_newsletter_*` |

---

## 6. Widget 迁移（Theme → Newsletter）与双注册避免

### 6.1 选项对比与推荐

| 选项 | 做法 | 结论 |
|------|------|------|
| A. Theme 留壳，Newsletter 只 API | Theme 继续注册同 code；业务 POST 到 Newsletter | **否决**（Q5；壳内业务空心；Composer 硬编码残留） |
| B. Newsletter 拥有 Widget；Theme 退役 | 同 code 迁入 Newsletter；Theme 删注册 + Composer 停用 newsletter | **推荐（冻结）** |
| C. 换新 code | 破坏编辑器/槽认知 `footer-newsletter` | **否决**（除非槽契约一并改名——成本更高） |

### 6.2 施工顺序（单一默认渲染路径）

1. **主题席（硬阻塞先解）：** `footer-container` 的 `@widget.slots` + 模板 `w:slot` **新增** `footer-newsletter`（accept：`footer-newsletter`、`layout-footer-newsletter`、`layout-global-footer-newsletter`；建议 `max:1`）。
2. **Newsletter：** 注册 `footer-newsletter` / `newsletter-popup`（及可选后续 `sidebar-newsletter` 二期）；模板迁到本模块；`default_injections`：
   - `footer-newsletter` → `layout_type=homepage`，`slot=footer-newsletter`，`required=true`，`area=footer`；
   - `newsletter-popup` → `layout_type=homepage`（或 `*` 策略按 Theme injection 惯例），`required=true`，全站 page_layouts。
3. **同发布单元内 Theme 退役：**
   - 从 `Weline_Theme/.../widget.php` 移除 newsletter 三项路径注册；
   - `FooterPartialComposer::NEWSLETTER` 删除或改为 **空实现 / 废弃**，任何 Partials 调用点改为槽渲染；
   - 删除或归档 `view/theme/frontend/widgets/newsletter/*`（或留 `@deprecated` 一版本后删）。
4. **编译：** `welineModules` / widget 注册编译；契约 UT：同 code 仅 `Weline_Newsletter` 一处声明。
5. **双注册门禁：** 禁止 Theme 与 Newsletter 同时 `code=footer-newsletter`；CI/UT 断言 registry 唯一 owner。

### 6.3 footer 槽归属

| 工作 | 席位 |
|------|------|
| `footer-container` 增 `footer-newsletter` 槽 + 布局占位 | **主题席**（Theme 生产改动，本波文档只标依赖） |
| injection 声明与业务 Widget | **Newsletter / 前端 / 主题 token** |
| 编辑器槽列表含 `footer-newsletter` 已有认知 | 主题席核对与 container 一致 |

---

## 7. 订阅 API：BinQuery + Frontend Controller

| 通道 | 职责 | 说明 |
|------|------|------|
| **BinQuery（主）** | `NewsletterQueryProvider::subscribe` | 部件 JS 经 `Weline.Api` → query-bin；返回 `{ok, message, coupon_code?, preference_updated?}`；**禁止**原生 fetch/ajax |
| **Frontend Controller** | `POST /newsletter/subscribe` | 兼容现有 `w:form action="@url{'newsletter/subscribe'}"`；**零业务**，只：取参 → `SubscribeService` → HTML redirect/flash 或 JSON（按 Accept） |
| Service | 唯一写路径 | Controller 与 Query **同调** `SubscribeService`，防分叉 |

建议 Query 操作名：`subscribe`（模块 `Weline_Newsletter`）；只读探测可另加 `getStatusByEmail`（后台/结账内部用 Interface，不必 external）。

**CSRF / 会话：** 走框架表单 token + BinQuery 既有会话规则；不自造 REST。

---

## 8. 发券幂等与 `source_key` 设计

对齐 Maintenance WaitGift：

| 层 | 值 |
|----|-----|
| `SOURCE_MODULE` | `Weline_Newsletter` |
| `SOURCE_TYPE` | `newsletter_subscribe_gift` |
| `SOURCE_ID` | `default`（多站时可改为 `website:{id}`） |
| 活动 upsert `source_key` | `subscribe_gift`（稳定活动身份，对应 WaitGift 的 `wait_gift`） |
| 发券 context 归因 | 同上四元组；附加 `subscriber_id`、`email_hash`（非 PII 明文进 Marketing context 日志时优先 hash） |

**终身一次（业务幂等）在 Newsletter 台账，不在 Marketing source_key 唯一约束：**

1. 订阅 upsert 成功后查 `gift_status`；
2. 若已 `issued|redeemed` → **不**调用 `issueRandomCoupon`；
3. 若 `none|skipped` 且活动启用 → issue → 写 `coupon_id/code`、`gift_issued_at`、`gift_status=issued`；
4. 并发：DB 唯一键 `(website_id, email)` + 发券前 `UPDATE … WHERE gift_status='none'` 乐观锁（或行锁）。

券行 `source_key` 建议仍用活动级 `subscribe_gift`（与 WaitGift 一致）；列表区分靠 `source_module/type` + Newsletter 台账回查。

**有效期 14 天：** 现网 `RandomCouponCampaignProvider::issueRandomCoupon` 写死 `+30 days`。架构要求：

- **扩展点席协商** Marketing 读取 context `valid_days`（缺省 30 兼容 WaitGift）；
- Newsletter issue 传 `valid_days` = 配置（默认 14）；
- **禁止** Newsletter 直改 `Marketing\Model\Coupon`。

---

## 9. Smtp 邮件渠道 codes

`extends/MailChannelProvider.php` 实现 `MailChannelProviderInterface`：

| code | 用途 | 变量（最小集） |
|------|------|----------------|
| `Weline_Newsletter::subscribe_welcome` | 订阅确认（无券或券已发过时的欢迎） | `email`, `topics_label`, `site_name` |
| `Weline_Newsletter::subscribe_gift` | 发券通知（含券码） | `email`, `coupon_code`, `discount_label`, `valid_until`, `shop_url` |

- `default_templates`：`MailTemplateDefaultLocales::fileEntries(...)`；
- 文件目录：`view/email/subscribe_welcome/`、`view/email/subscribe_gift/`；
- **禁止**后台手造渠道 code；**禁止**挂到 `Weline_Mail` / `Weline_Marketing` 渠道下冒充。

编排建议：首次订阅且发券成功 → 发 `subscribe_gift`（可含欢迎语，避免双封轰炸）；仅更新偏好 → 不强制再发；仅订阅无活动 → 发 `subscribe_welcome`。

---

## 10. Marketing / 结账触点

| 步骤 | API |
|------|-----|
| 活动同步 | `RandomCouponCampaignProviderInterface::upsert(RandomCouponCampaignRequest)` |
| 发券 | `::issueRandomCoupon($ruleId, $context)` |
| 归因 | `CouponSourceAttribution`（经 issue context） |
| 自动用券 | `MarketingCheckoutCouponSession::applyCoupon($code)` |

默认活动参数：`discount_type=percentage`，`discount_value=10`，`valid_days=14`，`active` 由后台开关。

T2：监听结账邮箱确定事件（扩展点席钉事件名；若无现成事件则 Newsletter 提供 Checkout 可调用的 Interface，**禁止**读 Checkout Model）。批发 / `disablesStorefrontDiscounts` → 跳过。

---

## 11. 表粗结构（草案）

### 11.1 `weline_newsletter_subscriber`

| 列 | 类型意图 | 说明 |
|----|----------|------|
| `subscriber_id` | PK | |
| `website_id` | int | 默认站 0 |
| `email` | varchar | 规范化后唯一 |
| `status` | enum | `active` / `unsubscribed` |
| `topic_promo` | bool | 优惠活动 |
| `topic_new_arrivals` | bool | 上新 |
| `customer_id` | int null | 登录关联 |
| `source_surface` | string | `footer` / `popup` / `api` |
| `locale` | string | |
| `coupon_id` | int null | 欢迎券 |
| `coupon_code` | string null | |
| `gift_status` | enum | `none` / `issued` / `redeemed` / `skipped` |
| `gift_issued_at` | datetime null | |
| `created_at` / `updated_at` | datetime | |

唯一索引：`(website_id, email)`。

### 11.2 有奖配置

优先 **SystemConfig**（website 作用域）键前缀 `newsletter/subscribe_gift/*`：`enabled`、`discount_type`、`discount_value`、`valid_days`、`marketing_rule_id`、`popup_cookie_days`。  
若运营需要变更审计表，再加 `weline_newsletter_campaign`——冻结会可定 SystemConfig 先行。

**不**改 `weline_marketing_coupon` 加 `customer_email`。

---

## 12. 扩展点选型摘要

| 意图 | 选用 | 禁止 |
|------|------|------|
| 前台提交写订阅 | QueryProvider（BinQuery）+ Frontend Controller 壳 | 跨模块 Controller、裸 fetch |
| 页脚默认展示 | Widget + required `default_injections` | Theme 硬编码外模块业务；Hook 双渲 |
| 发券 / 用券 | Marketing SPI | 直调 Coupon/Cart Model |
| 事务邮件 | Smtp `MailChannelProviderInterface` | 手造渠道；并入 Mail 模块 |
| 订阅成功旁路 | Event `Weline_Newsletter::subscribe_after`（建议） | 跨模块 new Service |
| 他模块读订阅态 | 未来 Query/Interface | 读 Newsletter Model |

Hook 席：主路径 **skip**（理由：槽 + injection 足够；Composer 退役后无需 Hook 兜底）。

---

## 13. Surfaces 草稿指针

详见同目录上级 [`../surfaces.md`](../surfaces.md)。

---

## 14. 风险与依赖

| 风险 | 等级 | 缓解 |
|------|------|------|
| footer 无 `footer-newsletter` 槽 | **硬阻塞** | 主题席先改 container；未开槽不得宣称 injection 完成 |
| Theme 与 Newsletter 双注册同 code | 高 | 同发布单元退役；UT 断言唯一 owner |
| `FooterPartialComposer` 残留双渲 | 高 | 删 NEWSLETTER 常量路径；契约测试 |
| `issueRandomCoupon` 固定 30 天 | 中 | Marketing SPI `valid_days`；扩展点席跟 |
| 券前缀 `MW` 与 WaitGift 混读 | 低 | 台账 + source_module 区分；可选 `code_prefix` |
| T2 缺结账邮箱事件 | 中 | 冻结会钉事件或 Interface；不直读 Model |
| 汉服视觉 vs 通用壳 | 中 | 模板迁 Newsletter 后由主题/原型按 hanfu 线稿收 |

---

## 15. 否决权声明（违反框架解耦一律否决）

1. **否决** 把邮件订阅并入 `Weline_Mail` 或 `Weline_Subscription`。  
2. **否决** Theme 布局/Composer **硬编码** Newsletter 业务模板作为长期方案。  
3. **否决** Newsletter **直读/直写** Marketing Coupon、Cart、Checkout **Model**。  
4. **否决** 前台业务 I/O 使用原生 `fetch`/`ajax`（必须 BinQuery / `Weline.Api`）。  
5. **否决** Theme 与 Newsletter **同时注册** 同一 `widget.code`。  
6. **否决** 给 Coupon 表加 `customer_email`「图省事」。  
7. **否决** 用 Hook 再渲一遍页脚订阅导致双份 DOM。  
8. **否决** 为清场执行 `git restore/clean/stash` 擦脏工作区。

---

## 16. 给对齐冻结会的检查清单

- [ ] UC-1～UC-4 与本方案 surfaces 对齐  
- [ ] contracts：`subscribe` 入参/出参；发券 context；渠道 codes  
- [ ] 主题席任务卡：footer 槽 + Composer 退役  
- [ ] 扩展点席：`valid_days` SPI；T2 事件名  
- [ ] 测试席：e2e 名 `newsletter-subscribe-footer-happy` / `…-checkout-auto-coupon` / `…-validation`

---

## 17. 方案一页纸（供项目经理转述用户）

我们将新建并落地 **`Weline_Newsletter`（邮件订阅）** 模块，与周期订购、企业邮箱无关。顾客可在**页脚常驻表单**和**全站弹窗**（关闭后 14 天内不再打扰）提交邮箱，默认勾选优惠与上新；重复提交只更新偏好，**欢迎折扣券每人终身一张**。成功订阅后自动发 **10% 优惠券（默认，后台可改）**，有效期 **14 天**，并尽量在**当次会话与结账填邮箱时自动带上券码**。技术上：订阅部件从 Theme 迁到 Newsletter，页脚容器先增加订阅槽再默认注入；发信走本模块 Smtp 渠道；发券走现有 Marketing 随机券能力。不改生产业务代码的本波只定方案；施工前需主题侧打开页脚槽，避免订阅区无处挂载。
