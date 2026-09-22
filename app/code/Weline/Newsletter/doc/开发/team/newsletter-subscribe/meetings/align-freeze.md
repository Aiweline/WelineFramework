# 对齐冻结会 · newsletter-subscribe

| 字段 | 值 |
|------|-----|
| 席位主持 | **测试** |
| 时间 | 2026-09-22T09:40:00+08:00 |
| 仓库 | `/Users/weline/Project/Official/框架` |
| 通道 | `channel/align-freeze.md` |
| 规格 | `doc/开发/spec/newsletter-subscribe.md` |
| 方案草案 | `meetings/tech-scheme-draft.md` + `surfaces.md` + `components.md` |
| 产物 | 本文件可执行 UC + `../contracts.md` + `../deps.md` |
| result | **closed**（测试主持钉死三者；架构师 msg-2 + 扩展点 msg-3 已同意；`valid_days`=A） |
| track | 只写 `doc/team`；禁止改生产 PHP/phtml/CSS |

---

## 参会 / 表态

| 席位 | 表态 | 说明 |
|------|------|------|
| **测试（主持）** | **同意冻结** | 可执行 UC-1…UC-4 + contracts + deps 已落盘；通道 msg-2/msg-4 |
| 架构师 | **同意冻结推荐方案** | channel msg-2；footer 槽硬前置；否决留壳 / 直改 Coupon / 假 14 真 30 |
| 扩展点 | **同意机制落点可冻** | channel msg-3；`valid_days` **裁定 A**（=架构首选 1）；Hook skip；T2 Event\|Interface |
| 主题 / 前端 / 原型 / UI / 后端 / ACL / Setup / 查询 / Smtp 等 | 待施工波 | 依赖边见 `deps.md`；异议走通道回会 |

### 测试同意立场（摘要）

1. **采纳**规格 §7 / §7b 与架构草案主路径（Newsletter 拥有 Widget、T1+T2、终身一次欢迎礼、10%/14 天、弹窗全站 cookie 14）。
2. 验收**必须**含真实业务证据：`subscriber_id` **或**规范化 `email`，以及发券路径的 `coupon_code`（活动关闭的异常路径除外）。
3. UC-2 **必须**可观测结账自动用券（`checkout-coupon` 会话/标签或摘要折扣），禁止仅断言订阅成功。
4. **采纳**扩展点裁定 `valid_days` 选项 A：Marketing context 读天数（缺省 30 兼容 WaitGift），Newsletter 传配置默认 14；合约/deps 已挂 Marketing SPI 节点。
5. 若后续架构 **ask** 纠偏，测试按「否决时改动清单」回修订，**不**放宽证据字段。

---

## 决议

1. **采纳** Q1–Q5 / EARS；本会升格可执行步骤与对接契约，**不改验收意图**。
2. 套件 id：`newsletter-subscribe-plan-suite`。
3. e2e / WB-OP 名冻结：
   - `newsletter-subscribe-footer-happy`（UC-1）
   - `newsletter-subscribe-checkout-auto-coupon`（UC-2）
   - `newsletter-subscribe-validation`（UC-3）
   - `newsletter-subscribe-campaign-sync`（UC-4）
4. **`valid_days`=A** 写入 contracts §3 / deps D6；禁止对外宣称 14 天却落库 30 天。
5. 施工按 `deps.md` 唤醒；红灯骨架见下节。
6. **禁止**改生产实现骗绿、禁止私改已冻选择器/证据字段。

---

## 冻结选择器 / 基址 / 证据字段

| 键 | 值 |
|----|-----|
| BASE | `http://p05113ef3.test.weline.com:9555/` |
| ADMIN_BASE | `http://p05113ef3.test.weline.com:9555/` + 后台前缀（框架既有 admin 路由） |
| 页脚部件根 | `[data-widget-code="footer-newsletter"]`（实现定一主钩子后可回填 `data-testid`；须与 Theme 槽渲染一致） |
| 弹窗部件根 | `[data-widget-code="newsletter-popup"]` |
| 邮箱输入 | 部件根内 `input[type="email"], input[name="email"]` |
| 主题勾选 | `input[name="topic_promo"], input[name="topic_new_arrivals"]`（或等价 `topics[]`；实现定名后回填，须默认真） |
| 成功态 | 页内成功消息 / toast / 弹窗内成功区；文案含订阅成功或「偏好已更新」 |
| 结账券根 | `[data-testid="checkout-coupon"]` / `[data-widget-code="checkout-coupon"]` |
| 结账已用券可观测 | `data-marketing-coupon-tags` 可见且含订阅礼 `coupon_code`，**或** input `#marketing-checkout-coupon-code` 值等于该码，**或** 结账摘要折扣行可归因到该码 |
| 后台名单 | Newsletter 订阅 DataTable 行（邮箱列 + 券码列） |
| 测试账号·后台 | `admin` / `admin` |
| 测试账号·前台客户 | `e2e.customer@weline.local` / `E2eTest!234`（UC-2 可用访客路径，不强制登录） |

**真实业务证据（硬）：**

| 字段 | 要求 |
|------|------|
| `subscriber_id` 或 `email` | 订阅成功后至少其一可从 BinQuery/JSON 响应、后台行、或 DB 探针取得；email 须规范化（trim+lower） |
| `coupon_code` | UC-1 主路径与 UC-2 **必须**；活动关闭备选路径可为空且显式断言「无券」 |
| 禁止 | 仅模板字符串 UT、仅 HTTP 200、仅「成功」文案而无台账/券码 |

---

## 可执行 UC-1…UC-4（权威 · 喂 Playwright / WB-OP）

> 与规格 EARS 对齐；步骤可点选、可断言。环境失败记环境，不改意图。

### UC-1 页脚订阅成功并发券（主路径）

| 字段 | 内容 |
|------|------|
| id | UC-1 |
| 名称 | 页脚订阅 → 发券 → 台账可查 |
| 角色 | 访客（匿名） |
| 前置 | ① 本机 BASE 可访问；② 有奖活动启用（默认 10%/14 天）；③ Marketing `RandomCouponCampaignProvider` 可用；④ Theme `footer-container` 已声明 `footer-newsletter` 槽且 Newsletter `required default_injections` 生效；⑤ 页脚可见订阅部件；⑥ 路由/`SubscribeService` 已接通；⑦ 使用**从未**领过欢迎礼的新邮箱（建议 `nl.e2e.{timestamp}@weline.local`） |
| 步骤 | 1. `page.goto(BASE)`，`waitUntil=domcontentloaded`；Browser 已 `Network.setCacheDisabled`<br>2. `locator('[data-widget-code="footer-newsletter"]').scrollIntoViewIfNeeded()`；`expect` 部件根 `toBeVisible({timeout:20000})`<br>3. `expect` 优惠/上新主题勾选框 **默认 checked**<br>4. 填写合法邮箱 `E`；`click` 提交<br>5. `expect` 成功态可见（非 fatal）；若响应 JSON：`ok===true` 且含 `subscriber_id` **或** `email===E(规范化)`，且含非空 `coupon_code` → 记为 `C`<br>6. **证据 A**：后台打开 Newsletter 订阅名单（admin 登录）→ 行含邮箱 `E` 与券码 `C`（或 DB/契约探针等价：`weline_newsletter_subscriber` 行 `gift_status=issued` + `coupon_code=C`）<br>7. **证据 B（可选增强）**：开发环境 Smtp 日志或渠道发信记录含 `E` 与 `C`（`Weline_Newsletter::subscribe_gift`）<br>8. `expect(body).not.toContainText(/Fatal error\|ParseError\|WLS Runtime Error/i)` |
| 备选/异常 | **A1 重复邮箱**：同 `E` 再提交 → 成功态「已订阅/偏好已更新」；**不**出现新 `coupon_code`（台账 `gift_status` 仍 issued/redeemed）；偏好可写回<br>**A2 活动关闭**：仅订阅成功；响应/台账 **无** 新券；`gift_status` 为 `none` 或 `skipped` |
| 期望 | 订阅行存在；券 `source_module=Weline_Newsletter` / `source_type=newsletter_subscribe_gift`；`coupon_code` 绑定台账 |
| 映射 | e2e `newsletter-subscribe-footer-happy`；WB-OP-页脚订阅有奖 |

### UC-2 弹窗订阅 + 结账自动用券

| 字段 | 内容 |
|------|------|
| id | UC-2 |
| 名称 | 弹窗订阅后结账自动带券 |
| 角色 | 访客 → 结账 |
| 前置 | UC-1 同类活动启用；弹窗 Widget 全站可触发（或测试强制打开）；toc 购物车有 ≥1 可报价零售行；新邮箱 `E2` 终身未领礼；结账页含 `checkout-coupon` |
| 步骤 | 1. `goto` BASE（或任意前台页）；触发/打开 `[data-widget-code="newsletter-popup"]`（delay 等待或测试钩子；cookie 未屏蔽）<br>2. 弹窗内填 `E2`，保持默认主题勾选，提交<br>3. `expect` 成功态；捕获证据：`subscriber_id` **或** `email=E2`，以及 `coupon_code=C2`（非空）<br>4. **T1 可观测（同会话）**：进入结账（含 toc 行）；`expect` `[data-testid="checkout-coupon"]` 可见；断言下列**至少一条**：<br> · 券 tags 区可见且文本/属性含 `C2`<br> · `#marketing-checkout-coupon-code` 的 value/`data-*` 等于 `C2`<br> · 结账摘要折扣金额 &gt; 0 且可归因到 `C2`（网络或 DOM）<br>5. **T2 可观测（邮箱匹配）**：若步骤 4 已因 T1 带券，可另开干净上下文（清会话券、保留或重填结账邮箱=`E2`）→ 再次进入结账 → **同样**断言自动出现 `C2`（证明非仅手填）<br>6. （可选增强）下单成功 → 券 usage 递增且台账 `gift_status=redeemed`；失败记环境/支付沙箱，不改「自动带券」意图<br>7. 无 fatal；禁止仅断言订阅成功而无结账券证据 |
| 备选/异常 | **B1 批发 cart**：`disablesStorefrontDiscounts` / 非 toc → **不**自动用券；结账可完成且无订阅礼折扣<br>**B2 券过期**：台账券已过期 → 结账无该折扣、**不**报错阻断 |
| 期望 | 真实通路：`email/subscriber_id` + `coupon_code` + **结账自动用券可观测** |
| 映射 | e2e `newsletter-subscribe-checkout-auto-coupon`；WB-OP-弹窗订阅结账用券 |
| 禁止 | 只检查弹窗关闭；只检查 Marketing 会话 PHPUnit 无 Browser；伪造 DOM 无后端 apply |

### UC-3 非法邮箱拒绝

| 字段 | 内容 |
|------|------|
| id | UC-3 |
| 名称 | 非法邮箱不写入 |
| 角色 | 访客 |
| 前置 | 页脚或弹窗订阅入口可见；记录提交前订阅表最大 `subscriber_id` 或行数快照 `N` |
| 步骤 | 1. `goto` BASE；定位订阅入口（页脚优先）<br>2. 提交非法邮箱（如 `not-an-email`、空串、缺 `@`）<br>3. `expect` 校验错误可见（字段级或表单级）；**无**成功态<br>4. 后台/DB：无新订阅行（行数仍 `N`）；无新 `coupon_code` 归因 `newsletter_subscribe_gift` 针对该输入<br>5. 无 fatal；弹窗路径下弹窗可保持打开（不强制关闭） |
| 备选 | 无 |
| 期望 | 零副作用（无台账、无券、无欢迎/发券邮件） |
| 映射 | e2e `newsletter-subscribe-validation`；WB-OP-订阅校验 |

### UC-4 后台有奖活动同步 Marketing

| 字段 | 内容 |
|------|------|
| id | UC-4 |
| 名称 | 运营启用订阅礼金 |
| 角色 | 运营（admin） |
| 前置 | 已登录后台 `admin`/`admin`；ACL 允许 Newsletter 有奖配置；Marketing Provider 可用 |
| 步骤 | 1. 打开 Newsletter 有奖/活动配置页<br>2. 启用活动；设 `discount_type=percentage`，`discount_value=10`（或改一可观测值如 `12`），`valid_days=14`<br>3. 保存<br>4. **证据**：Marketing 侧出现/更新对应随机券规则；归因可识别 `source_module=Weline_Newsletter`、`source_key=subscribe_gift`（后台 UI、API 或契约 UT 探针三选一，**须**含真实 rule id）<br>5. （串联）用新邮箱跑 UC-1 一步发券 → 所得券折扣参数与配置一致（抽检 value 或 label）<br>6. 备选：将折扣值设 `0` 或关闭 → 规则 inactive；再订阅 **不**发欢迎礼 |
| 期望 | 与 WaitGift sync 同模式可观测；配置变更可驱动发券行为 |
| 映射 | e2e/契约 `newsletter-subscribe-campaign-sync`；至少一条 Browser WB-OP 或等价后台探活 |

---

## 红灯骨架（路径说明）

对齐冻结后、施工开跑即可落盘；**执行**待可测面就绪。禁止改生产实现骗绿。

### e2e 草稿（待建）

| 路径 | 说明 |
|------|------|
| `app/code/Weline/Newsletter/test/e2e/frontend/newsletter-subscribe-plan-suite.spec.js` | plan-suite；UC-1…UC-4 `test.fix`/`moduleCase` 占位，实现前红/skip |

### 合同测名（建议）

| 测名 | 对应 |
|------|------|
| `NewsletterWidgetOwnerContractTest` | 同 code 仅 Newsletter 注册；Theme 无双注册 |
| `NewsletterFooterSlotInjectionContractTest` | footer 槽 + required injection |
| `NewsletterSubscribeServiceContractTest` | upsert / 终身一次 / 非法邮箱无副作用 |
| `NewsletterSubscribeGiftIssuerContractTest` | issue + 台账 `coupon_code` |
| `NewsletterMailChannelContractTest` | 渠道 codes `subscribe_welcome` / `subscribe_gift` |
| `NewsletterCampaignSyncContractTest` | upsert ↔ Marketing（UC-4） |
| `NewsletterCheckoutAutoApplyContractTest` | T1/T2 applyCoupon 可测桩 |

### Browser WB-OP 待跑名

- `WB-OP-页脚订阅有奖`
- `WB-OP-弹窗订阅结账用券`
- `WB-OP-订阅校验`

打开验收 Browser：**必须** `Network.setCacheDisabled`；交付后关 Browser。

---

## 若架构师否决 · 测试将改的点（预声明）

| 若否决点 | 测试修订 |
|----------|----------|
| BinQuery 操作名 / 响应字段改名 | 同步 UC 步骤 JSON 断言键；**保留** `subscriber_id\|email` + `coupon_code` 证据要求 |
| T2 不用 Event 改 Interface | UC-2 步骤 5 改为「结账填邮箱后调用约定 Interface 的可观测副作用」；不断言具体 Observer 类名 |
| `valid_days` 暂不能进 Marketing SPI | UC-1/UC-4 断言改为「台账记录配置的 14 天意图 + 券过期时间可观测」；标明技术债，**不**把默认改回 30 天当产品意图 |
| 弹窗 cookie 键名/天数实现差 | 回填选择器表；产品意图仍 14 天 |
| 有奖配置走独立表而非 SystemConfig | UC-4 定位页/探针改路径；sync 归因断言不变 |
| Theme 壳分两发布单元退役 | deps 边拆波；UC-1 前置增加「无双渲」断言（DOM 内 footer-newsletter 计数=1） |

**不会因否决而放弃：** 终身一次欢迎礼、T1+T2 自动用券可观测、非法邮箱零副作用、Newsletter 拥有 Widget（除非产品规格回滚——本会不接受）。

---

## 异议保留

- 主持侧：**无**（同意冻结）。
- 架构师 / 扩展点：**已同意**（channel msg-2 / msg-3）。后续若 `kind=ask` 纠偏须回本会修订，**禁止**施工中私改 UC 证据字段。
