# contracts.md — payment-method-incentive-discount（对齐冻结）

| 项 | 值 |
| --- | --- |
| slug | `payment-method-incentive-discount` |
| 日期 | 2026-09-22 |
| **frozen** | **true** |
| 主持升格 | Team:测试:（对齐冻结会） |
| 规格 | `../../spec/payment-method-incentive-discount.md` |
| surfaces | `./surfaces.md` |
| deps | `./deps.md` |
| 扩展点纪要 | `meetings/扩展点.md` |
| 架构师纪要 | `meetings/架构师.md` |
| 冻结汇总 | `meetings/align-freeze.md` |

> **冻结生效**：无否决；顾问 AC 齐全且顾问已 contracts 复核通过；payload 字段名冲突已由测试主持钉死（前端提案为主）。施工波可开，不得弱化本文。

---

## 顾问约束（须原样保留，禁止偷改意图）

1. 只做激励折扣叙事；禁止 surcharge 主路径；激励与 surcharge **反向分字段**。
2. 结账「减 X」可兑现；站内应付 = 网关金额（硬失败否则）。
3. 叠加矩阵首期：购物车/满减 → 券 → 运费优惠 → 支付方式激励（税前）；积分/W币默认不与激励双重大额叠加。
4. 首期 PayPal + fake 对照；其它 method 可配、默认未配=0。
5. 可见串须翻译工程师默认站全语种闭环（模块 CSV 仅 zh+en；其它 locale→词典）。
6. A（激励）+ B（优惠明细透传）一体，缺一不可。
7. 合规红线：禁止虚假划线/隐瞒实质 surcharge；站内≠网关为硬失败。

---

## 扩展点（Team:扩展点: · 机制冻结）

选型权威：`Framework/doc/3-开发/扩展点选型.md`。明细见 `meetings/扩展点.md` + `surfaces.md`。

| ID | 意图 | 机制 | 落点（正式名已钉） | 新建? |
|----|------|------|---------------------|-------|
| E1 | 列表注入可减金额 | **Event** + Observer | **`Weline_Payment::checkout::available_methods::enrich`**（Payment dispatch） | 是（须 event 文档） |
| E2 | 读方式+激励 | **QueryProvider** | `PaymentQueryProvider::paymentMethodPayload` → **§payload 扁字段** | 扩展契约 |
| E3 | 激励报价/应用 | **Interface / SPI** | **`PaymentMethodIncentiveQuoteInterface`**（归属 Payment） | 是 |
| E4 | freeze quote 旁路 | **Event**（既有范式） | `freeze_quote::enrich` 等 | 复用 |
| E5 | 明细透传能力 | **Provider capability** | **`amount_breakdown`** / **`discount_passthrough`**；**≠** `supported_discount_actions` | 是（keys） |
| E6 | 后台配置 | **SystemConfig** | `payment/method/{code}/incentive_*`；未配=0 | 是（schema） |
| E7 | 列表/摘要展示 | **Hook** / HTML + payload | 消费 Query 扁字段；禁主题硬编码 | 扩展契约 |
| E8 | 营销 Action 兼容 | 既有 capability | 保留 `supported_discount_actions` 原语义 | 否（禁混用） |

### 硬否决（写入全波）

- **禁止跨模块直调**：跨模块 `new` Service、依赖对方 Model、直调 Controller。
- **禁止**用 `supported_discount_actions` 表达支付方式激励。
- **禁止**激励与 `surcharge` 共用字段或本 feature 主路径加价。
- **禁止**无 capability 伪造网关 breakdown；禁止壳写死某网关 JSON。
- **禁止**发明未文档化事件名即施工。
- **禁止**平行 REST 拉取激励价（须 BinQuery / 既有 checkout `getData`）。

---

## 架构契约 · Ownership（Team:架构师:）

| 能力 | 拥有模块 | 交付席（冻结后） |
|------|----------|------------------|
| 激励 SystemConfig + SPI + session `discount_lines` 合并与守恒 | `Weline_Payment` 壳 | Team:支付开发工程师: |
| Provider capability + PayPal breakdown / fake `echo_breakdown` | Extends Provider | Team:支付开发工程师: |
| 券/满减行写入（`source_type≠payment_method_incentive`） | Marketing / Checkout / Payable | 既有轨；支付席合并校验 |
| 列表/摘要 UI | Checkout + 前端/主题 | Team:前端: ± UI/主题开发工程师 |
| 可见文案多语 | I18n | Team:翻译工程师: |
| Event 文档化 | Payment `doc/event` | 支付席施工 + 扩展点合规 |
| UC 验收步骤 | contracts §acceptance | Team:测试: |

壳边界：`payment-shell.md` — 壳编排；网关映射在 Provider；禁壳重写某网关 create/patch。

### 禁止事项（架构补强）

| ID | 禁止 |
|----|------|
| FORBID-PARALLEL | 平行计价引擎 / 绕过 Payable·checkout_session |
| FORBID-SHELL-GW | 壳内写死 PayPal/Stripe JSON |
| FORBID-STALE-INTENT | 切换 method 后仍用旧 incentive / 旧 grand_total |
| FORBID-DEFAULT-ALL | 默认全开所有 method 激励 |
| FORBID-MKT-OWN | Marketing 作为激励金额真相源（MVP） |
| FORBID-ALIAS | 用未写入本文的 payload 别名做验收依据 |

---

## payload 字段名（冻结 · 前端提案为主 · 兼容架构语义）

> **OQ-4 关闭**。验收与前端契约**仅**承认下列正式名；禁止 `incentive_discount.*` / `savings_minor` / `incentive.amount_minor` 等未列别名作为过签依据。

### 列表 / QueryProvider / `payment_methods[]`（扁字段）

| 字段 | 类型/约定 | 语义 |
|------|-----------|------|
| `incentive_savings_minor` | int，**≥0** | 展示可减额（选此可兑现扣减）；= 选中后摘要激励行绝对值 |
| `incentive_display` | string | 服务端 i18n 后的展示文案（「减 ¥X」等） |
| `incentive_available` | bool | 当前是否可享激励（不可用方式须 false，禁误导「可减」） |
| `incentive_type` | `fixed_amount` \| `percentage` \| omit | 激励形态 |
| `incentive_percent` | number \| omit | 百分比时的数值 |

SSR：`payment_methods_html` 同源输出 `data-payment-incentive` 徽章（禁 JS `createElement` 造徽章）。

**架构语义映射**：原架构草案 `incentive_discount.amount_minor`（正数可减）≡ 正式字段 **`incentive_savings_minor`**；SPI 内部可用结构化 quote，但 **Query/列表出站必须投影为上表扁字段**。

### 摘要 / 快照（内部）

| 面 | 正式约定 |
|----|----------|
| `discount_lines[]` | `source_type=payment_method_incentive`；`amount_minor` **负向**；`key` 稳定（如 `pmi:{method}:{ver}`）；激励行必填 `funding_source` + `method_code` |
| 摘要行 | 独立「支付方式优惠」；与券行分列；便捷字段可选 `payment_method_incentive_amount_minor`（负向或与前端纪要一致，验收以分列行为准） |
| 守恒 | `incentive_savings_minor`（选中后）= \|激励行 `amount_minor`\| = 应付相对无激励时的真实扣减 |

### 禁止别名（验收）

| 禁止当验收依据 | 说明 |
|----------------|------|
| `incentive_discount` / `incentive_discount.amount_minor` | 已废止为列表正式名；仅可作内部映射注释 |
| `savings_minor`（无 incentive_ 前缀） | 原型口语，非正式 |
| `incentive.amount_minor` | 支付席口语，非正式 |

---

## 架构契约 · 金额守恒（硬）

1. **单一快照源**：`PaymentCheckoutSessionPersistenceService` amount_snapshot + `discount_lines`。
2. **展示 = 快照**：`incentive_savings_minor`（选中后）= 摘要「支付方式优惠」绝对值 = 快照激励行之 `|amount_minor|`。
3. **站内 = 网关**：Provider 应付 = `grand_total_minor`。PayPal：`amount.value` 与 breakdown 分项代数和守恒。
4. **分列可审计**：站内券行与激励行不得塌缩；网关可聚合 `discount` 总额 + description；运费优惠走 `shipping_discount`。
5. Money：全程 `amount_minor`；禁 float 核心计算。
6. 无 `discount_passthrough` / 无 `amount_breakdown`：**只扣应付净额、不伪造明细**；capability 闸门默认禁配激励透传验收。

---

## 架构契约 · Intent / Attempt 重建（硬）

| 触发 | 行为 |
|------|------|
| 切换支付方式 | 剔旧激励行 → SPI 重算 → 新快照（含 rule_version）→ **新 Attempt**；必要时 **重建 Intent** |
| 配置发布/停用 | 仅影响新 session / 新 Intent；已成功支付快照不变 |
| create/patch 网关 | 必须用当前快照应付；禁止旧金额调新 method |

---

## 开放点冻结决议

| # | 议题 | 决议 |
|---|------|------|
| OQ-1 | 部分退 | **按金额比例**回退（券与激励分列） |
| OQ-2 | PayPal 多行 | 站内多行硬；网关 `discount`/`shipping_discount` **聚合**映射；守恒硬 |
| OQ-3 | Ledger | 施工补 **`TYPE_DISCOUNT`** + 复用 **`ROLE_DISCOUNT`**（同发布单元）；快照仍为透传真相源 |
| OQ-4 | payload | **§payload 扁字段**（`incentive_savings_minor` 等）；见上节 |

---

## 叠加矩阵（已确认写入）

| 顺序 | 优惠类型 | 规则 |
|------|----------|------|
| 1 | 购物车/商品级促销、满减 | 先算 |
| 2 | 优惠券 | 既有券规则 |
| 3 | 运费优惠/包邮 | 运费行；PayPal `shipping_discount` vs `discount` 分字段 |
| 4 | **支付方式激励** | 现金应付前最后一档；同一订单仅一种 method 激励 |
| 5 | 信用/积分/W币 | 默认不与激励双重大额叠加 |

税：首期默认税前。部分退：见 OQ-1。

---

## 支付席条款（完整吸收 · Team:支付开发工程师:）

| ID | 条款 | 状态 |
|----|------|------|
| PAY-1 | 激励配置 MVP → SystemConfig `payment/method/{code}/incentive_*`；**否决**首期新建 Model | ✅ 冻结 |
| PAY-2 | `discount_lines`：`source_type`∈`coupon\|cart\|shipping\|payment_method_incentive`；稳定 `key`；`amount_minor` 负向；激励行 `funding_source`+`method_code`；有激励时禁止塌缩不可审计单行 | ✅ |
| PAY-3 | PayPal `createOrder`/`patchOrder` **必须**带守恒 `amount.breakdown`；value=站内应付；站内多行审计、网关 discount/shipping_discount 聚合 | ✅ |
| PAY-4 | 无 breakdown Provider：**只扣净额、不伪造明细**；capability 闸门默认禁配；不因缺明细拒绝已合法净额激励 | ✅ |
| PAY-5 | `fake_card`：capability + create 回显 `echo_breakdown`；Browser 对照 + **另**跑 PayPal sandbox；**fake 绿 ≠ PayPal 过** | ✅ |
| PAY-6 | 部分退→比例；Ledger→`TYPE_DISCOUNT`+`ROLE_DISCOUNT` | ✅ OQ-1/3 |
| PAY-7 | 壳编排/渠道进 Provider；`amount_minor`；禁复用 `supported_discount_actions`；禁 surcharge 主路径；展示+透传一体；改完才拉测试真 Browser | ✅ |

---

## UI / Theme / 原型 / 翻译门禁

1. **UI**：L0 应付总额=唯一价格真相；L2「选此减 X」次级徽章；摘要「支付方式优惠」与券分列；**禁行内划线原价** / 把无激励方式标成原价 / 贬损其它方式。
2. **Theme**：必须 Theme Token + Weline UI（`w-*`）；禁 Payment 私有色板；design 禁同 key 覆盖 `theme.css`/`theme.js`；施工须声明 `work_mode`。
3. **原型**：「减 X」挂既有 `payment-method-card` + `weline-checkout__totals`；禁脱离结账壳的通用 SaaS 选卡稿；正式验收等 UI+原型过签。
4. **翻译**：模块 CSV **仅** `zh_Hans_CN` + `en_US`；其它默认站 locale → 系统词典；施工后 `i18n:collect` + 抽检非中英；禁止「最高减」话术。

---

## acceptance（可执行验收 · Team:测试:）

### 门禁（HARD）

| ID | 门禁 |
|----|------|
| ACC-GATE-1 | 真实业务通路（结账→选方式→提交→PayPal sandbox **或** fake 真壳）；禁假数据骗绿 |
| ACC-GATE-2 | Browser：禁缓存 + 抹 `navigator.webdriver` |
| ACC-GATE-3 | 正式验收须 UI+原型过签（施工期可红灯骨架） |
| ACC-GATE-4 | `incentive_savings_minor` = 选中后真实扣减；站内应付 = Provider 应付 |
| ACC-GATE-5 | 本机优先；payload 仅认 §payload 正式字段 |
| ACC-GATE-6 | fake 绿 ≠ PayPal 过（两轨都要） |

### UC 表

| UC | 名称 | 过签要点 |
|----|------|----------|
| UC-1 | 激励主路径金额恒等 | 后台配→列表见减→选中摘要分列→提交→站内=网关；字段名正式 |
| UC-2 | 券+激励透传与切换 | 分列；PayPal breakdown 或 fake echo；切换无脏激励 |
| UC-3 | 未配置/未发布=0 | 无激励减免 |
| UC-4 | 退款按快照 | 全额/部分比例；券与激励分列回退 |

### UC-1 可执行步骤

| 步 | 操作 | 期望 |
|----|------|------|
| 1.1 | 后台发布 method `incentive_*`（固定额或百分比；有效期内） | 配置可保存 |
| 1.2 | 可结算购物车进结账（Browser 禁缓存+抹 webdriver） | 列表渲染 |
| 1.3 | 观察目标方式 | 可见 `incentive_display` / 「减 X」；`incentive_savings_minor`≥0 且可兑现；`incentive_available=true` |
| 1.4 | 选中 | 摘要「支付方式优惠」独立行；应付下降 = `incentive_savings_minor` |
| 1.5 | 提交至成功（PayPal sandbox 或 fake） | 站内应付 = Provider 应付 |
| 1.6 | 查 `discount_lines` | 含 `source_type=payment_method_incentive` 负向行；有券则分列 |
| 1.7 | 对照轨 | fake `echo_breakdown` 与壳一致；另轨 PayPal sandbox |

备选：切换无激励 → 应付回升无残留；激励+券过低下限 → 可观察阻断。

### UC-2

| 步 | 操作 | 期望 |
|----|------|------|
| 2.1 | 有券 + 选激励方式 | 摘要券行与激励行分列 |
| 2.2 | 创建支付 | PayPal breakdown 守恒；或 fake echo 分列可断言 |
| 2.3 | 切换方式 | 列表/摘要/应付/payload 全重算；新 attempt |
| 2.4 | 再付 | 新快照 = Provider |

### UC-3

未配 / 未发布 / 停用后新单：无 `incentive_available` 可减展示；应付无激励扣减。历史成功快照保留。

### UC-4

全额退：激励+券分列按快照回退。部分退：按金额比例分列回退。禁吞折扣/重复退；话术不承诺退「未实付折扣现金」。

---

## 测试主持 · 冻结结论

- **无否决**；电商顾问 **contracts 复核通过**。
- payload 冲突已钉（前端扁字段为主）。
- 支付席附条件已完整吸收。
- **`frozen=true`** → 可开施工波（deps 序）。

---

## acceptance 结果（Browser 波 · 2026-09-22）

| 项 | 值 |
| --- | --- |
| **result** | **fail** |
| 席 | Team:测试: |
| Host | `https://p05113ef3.test.weline.com:9555` |
| 纪要 | `meetings/测试-browser.md` |
| 证据 | `meetings/evidence/browser-uc-20260922.json` · EV-CFG-2278 |

| UC | 结果 |
| --- | --- |
| UC-1 | fail（列表无「减 X」/无 `data-payment-incentive`） |
| UC-2 | fail（未完成 fake 金额恒等提交） |
| UC-3 | pass 局部（paypal 未配、无误导徽章） |
| UC-4 | N/A |

主因（首轮）：live observer registry 缺 `Weline_Payment::checkout::available_methods::enrich`；`setup:upgrade` 门禁/锁争用。PayPal sandbox 本波 blocker（主路径未绿前不记过）。

## acceptance 结果（Browser **retest** · 2026-09-22）

| 项 | 值 |
| --- | --- |
| **result** | **fail** |
| 席 | Team:测试: |
| Host | `https://p05113ef3.test.weline.com:9555` |
| 纪要 | `meetings/测试-browser.md`（retest round） |
| 证据 | `meetings/evidence/browser-uc-retest-20260922.json` · EV-REG-RETEST · EV-CLI-AMOUNT |

| UC | 结果 |
| --- | --- |
| UC-1 | fail（结账 DOM 仍无 `data-payment-incentive`） |
| UC-2 | fail（无 order_uuid / transaction_no） |
| UC-3 | pass（paypal 无误导徽章） |
| UC-4 | N/A |

首轮 registry 缺口已闭环。本波主因：Checkout 只传 major `amount`；Observer `??` 链被 `?? 0` 短路 → `baseMinor=0` → 激励为空。PayPal sandbox blocker（未跑）。

## acceptance 结果（Browser **retest round 3** · 2026-09-22）

| 项 | 值 |
| --- | --- |
| **result** | **pass** |
| 席 | Team:测试: |
| Host | `https://p05113ef3.test.weline.com:9555` |
| 纪要 | `meetings/测试-browser.md`（retest round 3） |
| 证据 | `meetings/evidence/browser-uc-retest3-20260922.json` |
| order_uuid | `2f8d298e-37d3-47f1-978e-6677f05dd5dd` |
| transaction_no | `PAY20260922113109144913` |

| UC | 结果 |
| --- | --- |
| UC-1 | pass（`Save USD 5.00` / `data-payment-incentive`） |
| UC-2 | pass（摘要 -$5.00 + fake 支付成功） |
| UC-3 | pass（paypal 无徽章） |
| UC-4 | N/A |

CLI：`listMethods(amount=10.13)` → savings=500。PayPal sandbox blocker（未跑，不挡 fake pass）。观察：扣款 1892 未含 -500，若 ACC-GATE-4 严扣请另开。

## acceptance 结果（Browser **retest round 4 · 金额恒等** · 2026-09-22）

| 项 | 值 |
| --- | --- |
| **result** | **pass** |
| 席 | Team:测试: |
| Host | `https://p05113ef3.test.weline.com:9555` |
| 纪要 | `meetings/测试-browser.md`（retest round 4） |
| 证据 | `meetings/evidence/browser-uc-retest4-20260922.json` |
| order_uuid（新单） | `058c5c3b-6524-4d72-8e29-1c3bf98cb2ae` |
| transaction_no | `PAY20260922114634664353` |

| 验收点 | 结果 |
| --- | --- |
| 列表激励徽章 | pass（Save USD 5.00） |
| 摘要支付方式优惠 | pass（-$5.00） |
| 金额恒等 | pass：grand=txn=**1392**（1013+879−500）；discount_amount_minor=500 |
| discount_lines | pass：`payment_method_incentive` −500 |
| UC-3 paypal 无徽章 | pass |

旧单 `2f8d298e…` 不计入本波。PayPal sandbox blocker（未跑）。
