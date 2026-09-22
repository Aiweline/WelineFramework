# surfaces.md · newsletter-subscribe（扩展点审核修订）

> 机制落点表。对齐 `meetings/tech-scheme-draft.md` + 扩展点选型。  
> 状态：**机制选型可冻结**（见 channel stance）；`valid_days` SPI 与 T2 事件名为**施工依赖**，不改机制类别。  
> 修订席：扩展点 · 2026-09-22

## 0. 选型合规总览（对照《扩展点选型》）

| 意图 | 选用机制 | 状态 | 禁止 |
|------|----------|------|------|
| 页脚/弹窗默认展示 | **Widget** + required `default_injections` | ✅ 齐全 | Theme 硬编码外模块业务；**Hook 双渲** |
| 前台写订阅（主 AJAX） | **QueryProvider** / BinQuery + `Weline.Api` | ✅ 齐全 | 原生 fetch/ajax；跨模块 Controller |
| 前台写订阅（渐进增强） | Frontend Controller 壳 → Service | ✅ 齐全 | Controller 内写业务 |
| 发券 / 活动同步 | Marketing **SPI**（`RandomCouponCampaignProvider`） | ✅ 齐全 | 直调 Coupon/Rule Model |
| 结账自动用券 | Marketing **Session** API | ✅ 齐全 | 直调 Cart/Checkout Model |
| 事务邮件 | Smtp **MailChannelProvider** | ✅ 齐全 | 手造渠道；并入 Mail/Subscription |
| 订阅成功旁路 | **Event**（可选） | ✅ 建议保留 | 跨模块 `new` 对方 Service |
| 他模块读订阅态 | 未来 Query / Interface | 二期可补 | 跨模块读 Newsletter Model |
| 页脚兜底 | **Hook skip** | ✅ 理由充分 | 用 Hook 再渲一遍 |

---

## 1. 机制落点表

| surface / 能力 | 机制 | 拥有模块 | 落点（路径/契约） | 依赖 / 备注 |
|----------------|------|----------|-------------------|-------------|
| 页脚订阅入口 | Widget + required `default_injections` | `Weline_Newsletter` | `extends/module/Weline_Widget/Weline_Newsletter/widget.php` → code `footer-newsletter`；injection `slot=footer-newsletter`，`required=true` | **Theme** 须先在 `footer-container` 声明同名槽；禁止 Hook 双渲 |
| 全站订阅弹窗 | Widget + injection | `Weline_Newsletter` | code `newsletter-popup`；`page_layouts=["*"]`；`cookie_days=14` | Theme 壳退役后唯一注册 |
| 侧栏订阅（二期） | Widget（可选） | `Weline_Newsletter` | 原 Theme `sidebar-newsletter` 可二期迁 | 本 feature 非必须 |
| footer 槽契约 | Theme container slots | `Weline_Theme` | `view/theme/frontend/widgets/container/footer/default.phtml` `@widget.slots` + `w:slot` | **硬阻塞**；主题席 |
| 退役 Theme 壳 | 删注册 + Composer | `Weline_Theme` | `widget.php` 去 newsletter 路径；`FooterPartialComposer` 停 newsletter | 与 Newsletter **同发布单元**，防双注册 |
| 表单渐进增强 POST | Frontend Controller | `Weline_Newsletter` | `Controller/Frontend/Subscribe.php` 路由 `newsletter/subscribe` | 零业务：取参 → `SubscribeService`；兼容现有 `@url` |
| AJAX 订阅（主） | QueryProvider / BinQuery | `Weline_Newsletter` | `extends/module/Weline_Framework/Query/NewsletterQueryProvider.php` → op `subscribe`；前端 `Weline.Api` | **禁**原生 fetch/ajax |
| 订阅台账 | Model + Setup | `Weline_Newsletter` | `Model/Subscriber`；表 `weline_newsletter_subscriber` | 含券码绑定；唯一 `(website_id,email)` |
| 主题偏好 upsert | Service | `Weline_Newsletter` | `SubscribeService` | Q1：重复更新偏好 |
| 欢迎券终身一次 | Service 门禁 | `Weline_Newsletter` | `SubscribeGiftIssuer` + `gift_status` | 幂等在 Newsletter 台账，**不**靠 Marketing source_key 唯一 |
| 有奖活动同步 | Marketing SPI | 调用方 Newsletter | `SubscribeGiftCampaignSyncService` → `RandomCouponCampaignProviderInterface::upsert` | 对照 WaitGift；禁直写 Rule Model |
| 发券 | Marketing SPI | 调用方 Newsletter | `::issueRandomCoupon` + `CouponSourceAttribution` | `source_type=newsletter_subscribe_gift`；`source_key=subscribe_gift` |
| 券有效期 14 天 | **SPI 增强（推荐 A）** | `Weline_Marketing` | context **`valid_days`**（缺省 **30** 兼容 WaitGift） | Newsletter issue 传配置默认 14；**否决**首期接受写死 30（违 Q3） |
| T1 自动用券 | Marketing Session | 调用方 Newsletter | `MarketingCheckoutCouponSession::applyCoupon` | 发券后 best-effort |
| T2 自动用券 | Event **或** Interface | Newsletter Observer / 被调 Interface | 结账邮箱可识别后 `applyCoupon` | **禁**读 Checkout Model；事件名见 §3（施工钉） |
| 订阅确认邮件 | Smtp MailChannelProvider | `Weline_Newsletter` | `extends/MailChannelProvider.php` → `Weline_Newsletter::subscribe_welcome` | `MailChannelProviderInterface` + `view/email` |
| 发券通知邮件 | Smtp MailChannelProvider | `Weline_Newsletter` | `Weline_Newsletter::subscribe_gift` | 同上 |
| 后台名单 | ACL + Backend Controller | `Weline_Newsletter` | ACL 资源 + DataTable | ACL 席 |
| 后台有奖配置 | Backend + SystemConfig | `Weline_Newsletter` | 默认 10% / 14 天 | 保存时 sync Marketing |
| 结账券 UI | 既有 Widget | `Weline_Marketing` | `checkout-coupon` | Newsletter **不**重做 |
| 订阅成功旁路 | Event（可选） | `Weline_Newsletter` | 建议 `Weline_Newsletter::subscribe_after` | 文档化后事件席落 event 文档；旁路发信/统计 |
| Hook（页脚） | **skip** | — | — | **理由**：槽 + required injection 足够；Composer 退役后无需 Hook 兜底；Hook 再渲 → 双份 DOM（否决） |
| Taglib | **skip** | — | — | 主题勾选为标准 checkbox，无领域 select |
| i18n | 模块 CSV | `Weline_Newsletter` | 源串简中；中英 CSV | 用户提「翻译」则默认站全语种 |
| 命名隔离 | 模块边界 | — | ≠ Subscription ≠ Mail | 否决并入 |

---

## 2. 渲染路径（单一）

```text
homepage chrome
  └─ footer-container
       └─ slot:footer-newsletter   ← RequiredDefaultInjection: Weline_Newsletter::footer-newsletter
  └─（content）newsletter-popup    ← injection / 布局节点；全站 page_layouts
```

**禁止并行路径：** `FooterPartialComposer::renderNewsletter` 硬编码 Theme 模板。

---

## 3. T2 结账触点（机制可冻，事件名施工钉）

施工钉（后端 D12 · 2026-09-22）：**复用现有 Checkout 事件（选项 A）**，无需新增 Checkout dispatch。

| 选项 | 机制 | 状态 |
|------|------|------|
| **T2-E（采用）** | Observer 挂 `Weline_Checkout::checkout::identity::resolve::after` + `guest::validate::after` → `CheckoutEmailMatchCouponObserver` → `CheckoutAutoApplyService::applyForRecognizedEmail` | **已落地**；doc：`doc/event/checkout-email-match-coupon.md` |
| T2-I（并存） | `NewsletterCheckoutCouponAutoApplyInterface`（`provides` → `CheckoutAutoApplyService`） | 可选直调；主路径不依赖 Checkout 改码 |

**共同禁令：** Newsletter **禁止**读 Checkout / Cart / Coupon Model。批发 / `disablesStorefrontDiscounts` → skip apply。

---

## 4. `valid_days`：30 vs 14（扩展点裁定）

现网证据：`RandomCouponCampaignProvider::issueRandomCoupon` 写死  
`END_DATE = time() + 86400 * 30`（无 context 覆盖）。

| 选项 | 做法 | 结论 |
|------|------|------|
| **A** | Marketing 读 context `valid_days`；缺省 **30**（WaitGift 兼容）；Newsletter 传配置（默认 **14**） | **推荐并冻结** |
| B | Newsletter 首期接受 30 天 | **否决**（违 Q3 / US-2：14 天可配） |
| C | 其它（如仅靠 Rule 上 start/end，issue 忽略 context） | **不推荐作主方案**——upsert 规则日与「每张券独立有效期」语义易混；可作 A 的兜底读序：`context.valid_days` > rule 推导 > 30 |

**契约草案（施工）：**

```text
issueRandomCoupon($ruleId, [
  …归因字段…,
  'valid_days' => int≥1,   // Newsletter 默认 14；省略 → 30
])
```

禁止 Newsletter 直改 `Marketing\Model\Coupon` 的 `end_date`。

---

## 5. 发券归因常量（冻结草案）

```text
SOURCE_MODULE = Weline_Newsletter
SOURCE_TYPE   = newsletter_subscribe_gift
SOURCE_ID     = default          // 多站可演进 website:{id}
SOURCE_KEY    = subscribe_gift
```

---

## 6. 跨模块禁令（硬）

1. **禁止** Newsletter 跨模块 `new` / 直依赖 Marketing·Checkout·Cart·Coupon **Model**。  
2. **禁止** 他模块直读 Newsletter Subscriber Model（须 Query/Interface）。  
3. **禁止** Theme 与 Newsletter **同时**注册同一 `widget.code`。  
4. **禁止** 用 Hook 再渲页脚订阅。  
5. **禁止** 原生 `fetch`/`ajax` 作业务 I/O（须 BinQuery / `Weline.Api`）。  
6. **禁止** 订阅能力并入 `Weline_Subscription` / `Weline_Mail`。
