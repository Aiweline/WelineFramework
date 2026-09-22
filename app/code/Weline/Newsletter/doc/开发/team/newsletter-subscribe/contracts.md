# contracts · newsletter-subscribe（对齐冻结 · 测试主持）

- slug: `newsletter-subscribe`
- 冻结时间: 2026-09-22T09:40:00+08:00
- 主持席: 测试
- 权威规格: `app/code/Weline/Newsletter/doc/开发/spec/newsletter-subscribe.md`
- 方案草案: `meetings/tech-scheme-draft.md` + `surfaces.md`
- 可执行 UC: `meetings/align-freeze.md`（UC-1…UC-4）
- 状态: **frozen**（测试主持 + 架构师同意 + 扩展点同意 `valid_days=A`；改验收意图须回对齐冻结会；禁止施工私改）

> 对接即验收契约：每条边写清输入 / 输出 / 就绪条件 / 对应 UC。  
> 已拍板约束（Q1–Q5 / §7b）**勿改验收意图**。

---

## 0. 全局约定

| 项 | 冻结值 |
|----|--------|
| 基址 URL（字面） | `http://p05113ef3.test.weline.com:9555/` |
| 模块 | `Weline_Newsletter`（≠ Subscription ≠ Mail） |
| Widget codes | `footer-newsletter`、`newsletter-popup`（保留 code，Newsletter 唯一 owner） |
| 欢迎礼默认 | percentage **10%**；`valid_days` **14**；终身一次 / `source_key=subscribe_gift` |
| 自动用券 | **T1+T2** 必做；T3 cookie 增强可选 |
| 弹窗 | `page_layouts=["*"]`；`cookie_days=14` |
| 业务 I/O | 前台仅 BinQuery / `Weline.Api` + Frontend Controller 壳；**禁止**原生 fetch/ajax |
| 证据字段 | 响应或台账须可取 `subscriber_id` **或** `email`；发券路径须 `coupon_code` |
| 禁止 | 双注册同 widget code；Composer 硬编码 Theme 业务模板长期并存；直读 Coupon/Cart/Checkout Model；Coupon 表加 `customer_email` |

---

## 1. 后端 ↔ 前端（订阅表单 / 部件）

| 字段 | 内容 |
|------|------|
| 交付席 | **后端**（SubscribeService + Controller 壳）+ **前端**（Widget JS / 表单渐进增强） |
| 消费席 | 测试（UC-1/2/3）、原型/UI 签收 |
| 输入 | `email`（必填）；`topic_promo` / `topic_new_arrivals`（默认 true）；`source_surface`∈{`footer`,`popup`}；可选 CSRF/会话 token |
| 输出 | 成功：`{ ok: true, message, subscriber_id?, email, coupon_code?, preference_updated?, gift_status? }`；失败：`{ ok: false, message/errors }` 且无副作用；HTML POST 路径：flash/redirect 等价信息 |
| 就绪条件 | ① Frontend `POST newsletter/subscribe` 与 BinQuery `subscribe` **同调** SubscribeService；② 页脚/弹窗部件 DOM 钩子稳定；③ 成功态可展示券码（有券时）；④ 重复提交返回 preference_updated 语义 |
| 对应 UC | UC-1、UC-2、UC-3 |
| 谁交付谁 | 后端契约 → 前端绑定；前端选择器/成功态 → 测试 |

---

## 2. 查询 · BinQuery（`NewsletterQueryProvider::subscribe`）

| 字段 | 内容 |
|------|------|
| 交付席 | **查询** + **后端** |
| 消费席 | 前端部件 JS（`Weline.Api` → query-bin）；测试 |
| 输入 | 与 §1 同形业务字段；经框架 Query 信封（模块 `Weline_Newsletter`，操作名冻结为 `subscribe`；扩展点若改名须回会） |
| 输出 | 同 §1 JSON 成功/失败形；**必须**在成功路径提供 `subscriber_id` 或 `email`；发券成功时 **必须** `coupon_code` |
| 就绪条件 | 注册进 Query 收集；店面禁缓存验收下可调用；非法邮箱 `ok=false` 且库无新行 |
| 对应 UC | UC-1、UC-2、UC-3（AJAX 主路径） |
| 谁交付谁 | 查询/后端 → 前端 |

---

## 3. 后端 ↔ Marketing SPI（活动 upsert / 发券 / 用券）

| 字段 | 内容 |
|------|------|
| 交付席 | **后端**（Newsletter）调用；**扩展点**协商 SPI 增强（`valid_days`） |
| 消费席 | 测试、合规抽检 |
| 输入 | |
| · upsert | `RandomCouponCampaignRequest`：`source_module=Weline_Newsletter`，`source_type=newsletter_subscribe_gift`，`source_id=default`，`source_key=subscribe_gift`；`discount_type/value`；`active`；`valid_days`（默认 14） |
| · issue | `issueRandomCoupon($ruleId, $context)`；context 含归因四元组 + `subscriber_id` +（可选）`email_hash` + **`valid_days`（选项 A，冻结）** |
| · apply | `MarketingCheckoutCouponSession::applyCoupon($code)`（T1 发券后 best-effort；T2 邮箱命中未用券） |
| 输出 | upsert → 持久化 rule id（写入 Newsletter 配置）；issue → `coupon_id`/`coupon_code` 回写台账 `gift_status=issued`；券 END 按 `valid_days`（默认 14）；apply → 结账会话可观测已带码 |
| 就绪条件 | ① 不直改 Coupon/Cart Model；② 终身一次门禁在 Newsletter 台账；③ 批发/非 toc 不 apply；④ **Marketing SPI 选项 A**：context `valid_days` int，缺省 30 兼容 WaitGift，Newsletter 传配置默认 14；**禁止**对外 14 天文案却落库 30 天；SPI 未合入则发券路径按 deps 阻塞（纯订阅可并行） |
| 对应 UC | UC-1、UC-2、UC-4 |
| 谁交付谁 | Marketing SPI ← Newsletter；扩展点钉 `valid_days`/T2 事件 |

---

## 4. 主题 ↔ footer 槽（开槽 + 注入 + 壳退役）

| 字段 | 内容 |
|------|------|
| 交付席 | **主题**（Theme `footer-container` 槽）+ **后端/主题**（Newsletter Widget + `default_injections`）+ Theme 退役 |
| 消费席 | 前端、测试（UC-1） |
| 输入 | 槽名 `footer-newsletter`；accept：`footer-newsletter`、`layout-footer-newsletter`、`layout-global-footer-newsletter`；建议 `max:1` |
| 输出 | |
| · Theme | `footer-container` `@widget.slots` + 模板 `w:slot` 含 `footer-newsletter` |
| · Newsletter | `required default_injections`：`footer-newsletter` → `slot=footer-newsletter`；`newsletter-popup` 全站 injection |
| · 退役 | Theme `widget.php` 移除 newsletter 三项；`FooterPartialComposer` 停 newsletter 硬编码；同发布单元内 registry **唯一** owner=`Weline_Newsletter` |
| 就绪条件 | 页脚 DOM 内业务订阅区 **恰好一份**；无槽则禁止宣称 injection 完成（硬阻塞） |
| 对应 UC | UC-1（前置）、UC-2（弹窗） |
| 谁交付谁 | 主题开槽 → Newsletter 注入 → Theme 删壳 |

---

## 5. Smtp · MailChannelProvider

| 字段 | 内容 |
|------|------|
| 交付席 | **后端**（Newsletter `extends/MailChannelProvider`） |
| 消费席 | Smtp 收集；测试（可选日志探针）；运营预览 |
| 输入 | 渠道 codes（代码声明，禁后台手造）：`Weline_Newsletter::subscribe_welcome`、`Weline_Newsletter::subscribe_gift` |
| 输出 | `default_templates` 经 `MailTemplateDefaultLocales::fileEntries`；变量最小集见 tech-scheme §9；发券成功优先发 `subscribe_gift`（可含欢迎语）；仅订阅无券发 `subscribe_welcome`；仅偏好更新不强制再发 |
| 就绪条件 | Smtp 渠道列表可见两 code；模板文件在模块 `view/email/...`；**不**挂到 Mail/Marketing 渠道冒充 |
| 对应 UC | UC-1（证据 B 可选）、UC-2（同） |
| 谁交付谁 | Newsletter → Smtp |

---

## 6. ACL / Setup

| 字段 | 内容 |
|------|------|
| 交付席 | **ACL** + **Setup** + **后端** Backend Controller |
| 消费席 | 运营、测试（UC-4 / UC-1 后台证据） |
| 输入 | |
| · Setup | `register.php`、depends、Model `Subscriber`、表 `weline_newsletter_subscriber`、route `newsletter/*`、升版 upgrade |
| · ACL | 后台「订阅名单」「有奖配置」资源；未授权 403 |
| 输出 | 可登录访问的名单 DataTable（邮箱、主题偏好、券码、时间、gift_status）；有奖配置页保存触发 Marketing upsert |
| 就绪条件 | 模块可启用；表唯一键 `(website_id,email)`；admin 凭据本机 `admin`/`admin` 可测 |
| 对应 UC | UC-1（后台证据）、UC-4 |
| 谁交付谁 | Setup/ACL → 后端后台 → 测试 |

---

## 7. 事件 / T2（辅助契约）

| 字段 | 内容 |
|------|------|
| 交付席 | **事件** + **扩展点** + **后端** Observer/Interface |
| 消费席 | Checkout 触点、测试 UC-2 |
| 输入 | 订阅成功可派发 `Weline_Newsletter::subscribe_after`（建议）；结账邮箱确定信号（事件名由扩展点钉，或 Newsletter 提供 Checkout 可调 Interface） |
| 输出 | T2：邮箱命中台账未用未过期欢迎礼 → `applyCoupon`；不适用 cart 则跳过 |
| 就绪条件 | **禁止**读 Checkout Model；事件名写入 surfaces 后回填本表 |
| 对应 UC | UC-2 |
| 谁交付谁 | 扩展点钉名 → 后端实现 → 测试 |

---

## 8. 测试消费契约

| 字段 | 内容 |
|------|------|
| 交付席 | **测试** |
| 输入 | 本文件 + align-freeze UC + deps 可测面 |
| 输出 | plan-suite 红灯骨架 → 施工后变绿；WB-OP 禁缓存；汇审真实 `subscriber_id|email` + `coupon_code` + UC-2 自动用券证据 |
| 就绪条件 | deps 对应节点 closed；选择器若实现微调须回会修订字面 |
| 对应 UC | UC-1…UC-4 |

---

## 依赖边速览

详见 [`deps.md`](./deps.md)。核心：`主题开槽 → Newsletter 注入 → Theme 删壳`；`Marketing upsert → 发券`；`发券 → T1 apply`；`台账+结账邮箱 → T2 apply`。
