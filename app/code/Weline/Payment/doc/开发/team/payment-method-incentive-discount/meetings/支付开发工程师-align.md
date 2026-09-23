# 对齐冻结 · Team:支付开发工程师:

| 项 | 值 |
| --- | --- |
| seat | `Team:支付开发工程师:` |
| slug | `payment-method-incentive-discount` |
| 日期 | 2026-09-22 |
| 波次 | align-freeze（**本波不写生产实现码**） |
| 技能 | 已 Read `dev/ai-command/ai/支付开发.md` + `app/code/Weline/Payment/doc/payment-shell.md` + `provider-development.md`；`get_skill(payment_development)` 本回合 MCP indexing DISABLED → 以仓内权威为准，未编造 |
| MCP | `prepare_project` ok（receipt `weline-mcp-1790059828025-0ec7d0d59eeb63ae` / readiness `ready-1790059828001-69bc48252beaaba6`） |
| 禁止 | 改 SESSION；改 Provider/壳 PHP；本波不拉真浏览器（施工后才闭环测试席） |

## 已读输入

- 规格：`../../spec/payment-method-incentive-discount.md`（clarified）
- 探查：`领域探查.md`
- 顾问：`电商顾问.md`（附条件同意）
- 需求：`需求分析.md`
- channel：`../channel/align-freeze.md` msg-1
- 代码现状核对：`PaymentCheckoutSessionPersistenceService::normalizeDiscountLines`、`PayPalApiClient::createOrder`/`patchOrder`（仅合计 `value`）、`FakeProvider`（`fake_card`）、`PaymentLedger` 仅 payment/refund/adjustment、`CodFeeCalculator`（method config fee 加价反向参考）；配置页 `extends/module/Weline_SystemConfig/Config/backend/paypal.phtml` + `fake_card.phtml`

---

## stance

**同意附条件**（非否决；非无条件同意）。

冻结后施工由本席主导壳编排 + Provider 映射；**改完才**拉 `Team:测试:` 真 Browser 过 PayPal sandbox + fake 对照。本会只钉契约与可施工点。

### 条件（须写入 contracts / deps）

1. **壳编排、渠道进 Provider**：激励报价/快照/列表 enrich 在壳 Service；PayPal JSON `amount.breakdown` 映射只在 Extends `PayPalProvider`（+ `PayPalApiClient`）；禁止壳 Controller 拼 PayPal body。
2. **金额一律 `amount_minor`**；禁 float 核心计算；站内应付 = 网关 `amount.value` 为硬失败门禁。
3. **禁止**用 `supported_discount_actions` 表达 method incentive；新能力位见下。
4. **禁止 surcharge 主路径**；激励与 surcharge 分字段。
5. **首期**仅 PayPal 真透传 + fake 对照；其它 method 默认未配=0；后台启用激励须 capability 闸门。
6. **两块一体**：列表「减 X」+ 透传/快照守恒；不可砍半交付。

---

## 1. 激励配置落点

### 表态：**MVP 落 SystemConfig method 字段；首期不新建 Model**

| 方案 | 本席结论 |
| --- | --- |
| SystemConfig `payment/method/{method_code}/incentive_*` | **采纳（首期）** |
| 新建独立 Model / 表 | **否决（MVP）** — 与壳「配置托管」+ SystemConfig Scope 链冲突；运营维度（开关/额/百分比/有效期/funding）够用键值即可 |
| Marketing Rule 独占归属 | **非首期真相源** — 可后续用 Condition 限定客群；列表价与应付仍由 Payment 壳报价入 session |

**键草案（写入 contracts；施工可微调命名但不得换归属）**：

```text
payment/method/{code}/incentive_enabled          # bool，默认 0
payment/method/{code}/incentive_type             # fixed_amount | percentage
payment/method/{code}/incentive_amount_minor     # fixed 时用；整数最小单位
payment/method/{code}/incentive_percent_bps      # percentage 时用；基点，避免 float（如 500=5%）
payment/method/{code}/incentive_cap_minor        # 可选封顶
payment/method/{code}/incentive_funding_source   # merchant | provider | platform | shared
payment/method/{code}/incentive_starts_at        # 可选
payment/method/{code}/incentive_ends_at          # 可选
payment/method/{code}/incentive_publish_version  # 可选字符串；写入快照防旧 intent 被新配改写
```

**形态参考**：COD `fee`/`cod_fee`（加价）反向同构为减免；UI 进各 method 的 `backend/{code}.phtml`（首期 `paypal.phtml` + `fake_card` 配置页），禁止 Payment 自造隐式来源。

**壳侧服务（施工波）**：`PaymentMethodIncentiveQuoteService`（或等价）读 runtime_config → 产出可减 `amount_minor` + 行草稿；**Provider 不读运营激励公式**，只收已冻结 snapshot / breakdown DTO。

**何时才考虑 Model**：多规则/A-B/客群档位、需独立审计表、或 SystemConfig JSON 膨胀到不可维护——单独立项，不塞本 MVP。

---

## 2. `discount_lines` 扩展 key / 行契约

### 表态：扩展现有行；**`key` 稳定技术 ID + `source_type` 枚举**；金额符号保持**负向减免**

与现有 `normalizeDiscountLines` 兼容，扩展（非破坏）字段：

| 字段 | 要求 |
| --- | --- |
| `key` | 稳定技术 ID：`coupon:{CODE}` / `cart:{rule}` / `shipping:{rule}` / `pmi:{method_code}:{publish_version}` |
| `label` | 展示文案（中文 source；经 i18n） |
| `amount_minor` | **负整数**表示减免（与今日合成行一致） |
| `source_type` | **必填（新）**：`coupon` \| `cart` \| `shipping` \| `payment_method_incentive`（首期）；禁模糊单行 `discount` 作为激励 |
| `funding_source` | 激励行建议必填；券/其它可选 |
| `method_code` | 仅 `payment_method_incentive` 行 |

**守恒**：

```text
sum(|discount_lines.amount_minor|) 与 amount_snapshot.discount_amount_minor 一致（符号约定在 contracts 钉死）
grand_total_minor = subtotal + shipping + tax − discounts（跟现有税务口径；税前折扣）
```

**生产者缺口（探查已证）**：Checkout/Order 须写入结构化多行；壳 `normalizeDiscountLines` 须保留未知 `source_type` 透传字段（勿剥掉 `funding_source`/`method_code`）。合成单行 `{key:discount}` 仅作无多行时的回退，**有激励时禁止塌缩成不可审计单行**。

---

## 3. PayPal `createOrder` / `patchOrder` ↔ `amount.breakdown`

### 表态：**有任一非零优惠/分项时必须带完整 breakdown；与站内快照守恒**

今日仅传 `value` → 施工必须改为（Orders v2）：

```text
amount.value
amount.breakdown:
  item_total
  shipping
  handling          # 无则 0 或省略（与 PayPal 规则一致；冻结：无 handling 则省略）
  tax_total
  insurance         # 无则省略
  shipping_discount # 仅运费优惠行聚合
  discount          # 非运费 discount_lines 绝对值之和（券+满减+支付方式激励等）
```

**守恒硬式**（PayPal）：

```text
value = item_total + tax_total + shipping + handling + insurance
        − shipping_discount − discount
```

且 `value`（换算后 minor）= 站内 `grand_total_minor` / 应付。

**多行 → PayPal 粒度（冻结建议）**：

| 站内 | PayPal |
| --- | --- |
| `source_type=shipping` 行 | 聚合 → `shipping_discount` |
| 其它减免行（含 `payment_method_incentive`、`coupon`、`cart`） | 聚合 → `breakdown.discount` |
| 审计分列 | **站内 snapshot 保留多行**；网关侧用 `purchase_units[].description` 或 custom 字段短摘要（可选，非金额真相）；**禁止**为「可区分」而伪造不存在的多 `discount` 字段 |

`patchOrder`（express 改价）须 **同步 patch 带 breakdown 的整段 amount**，禁止只 patch `value` 导致校验失败。

**职责切分**：

- 壳：从 amount_snapshot + discount_lines 生成中性 `AmountBreakdown` DTO（minor + currency）注入 createPayment context。
- `PayPalProvider` / `PayPalApiClient`：DTO → PayPal JSON；能力位 `amount_breakdown: true` + `discount_passthrough: true`（或合并枚举 `passthrough_formats: ['paypal_breakdown']`）。

---

## 4. 不支持 breakdown 的 Provider

### 表态：**壳侧仍只扣应付（净额）；禁止伪造 breakdown；不因「无明细」拒绝已合法配置的激励**

分层 capability（勿混）：

| 能力 | 含义 |
| --- | --- |
| 配置/报价激励 | method 有 `incentive_enabled` 且发布有效 → 壳重算应付与 `discount_lines` |
| `amount_breakdown` / `discount_passthrough` | 允许把分项映射进网关请求 |

**行为矩阵（首期冻结）**：

| Provider | 激励配置 | create 行为 |
| --- | --- | --- |
| PayPal（声明 breakdown） | 可配 | 净额 + 完整 breakdown；不一致 → 失败不得标成功 |
| fake_card（声明 shell 断言透传） | 可配（对照） | 净额；结果/元数据回显 breakdown 供契约断言 |
| 其它（未声明） | **后台默认禁止启用激励**（capability 闸门）；若历史误配 | **只传扣减后 `amount_minor`**；**不传**假 breakdown；壳快照仍完整分列 |

**拒绝激励**仅当：方式不可用、激励未发布、叠加后应付≤0/低于最小额、或叠加矩阵禁止——**不是**「网关不支持明细」。

与顾问「其它 method 仅 capability 后启用」对齐：闸门在**配置/可用性**，不在 create 时静默丢折扣。

---

## 5. fake_card 对照如何验收

### 表态：fake 是壳+快照+「模拟透传」契约床；**不替代** PayPal sandbox 真通路

| 层 | 要求 |
| --- | --- |
| Capability | `discount_passthrough` + format `shell_echo`（名可冻）；**勿**假装 PayPal API |
| createPayment | 请求 context 含 amount_snapshot / discount_lines；Result/`requestData` 回显 `echo_breakdown`（或等价）且 `amount_minor`=应付 |
| 单测/契约 | 守恒：sum 分项 − discounts = value；激励行 `source_type=payment_method_incentive`；切换 method 后旧激励行消失 |
| Browser（施工后 · 测试席） | 配 fake 激励 → 列表见减 X → 选中摘要分列 → 支付成功 → 回站/会话可回放分列；**另**跑 PayPal sandbox UC-1/2 |
| 非目标 | 用 fake 绿过宣称「PayPal 透传已过」 |

---

## 6. 对开放点的支付席投票

| # | 开放点 | 本席票 |
| --- | --- | --- |
| 1 | 部分退 | **同意顾问默认：按金额比例**回退激励与券分列；按行规则留后续 |
| 2 | PayPal 多行映射 | **聚合 `discount` + `shipping_discount`**；站内多行审计；description 可选 |
| 3 | Ledger | 首期：**Allocation `ROLE_DISCOUNT` + 快照** 必做；`PaymentLedger::TYPE_DISCOUNT` **建议补齐**（与 payment/refund/adjustment 并列），避免全塞 adjustment；范围=激励入账/退款回退，不做 surcharge 类型 |
| 4 | 列表 payload 命名 | 推荐：`incentive: { amount_minor, currency_code, label, percent_bps?, funding_source?, rule_code? }`（`amount_minor`=可减**正数**展示）；摘要行用 `discount_lines`；**废弃**把激励塞进 `surcharge` |

---

## 7. 施工波文件清单预估（不实现）

### 7.1 本席主责（Payment）

| 路径（预估 · 相对 `app/code/Weline/Payment/`） | 动作 |
| --- | --- |
| `extends/module/Weline_SystemConfig/Config/backend/paypal.phtml` | 增 `incentive_*` 字段 |
| `extends/module/Weline_SystemConfig/Config/backend/fake_card.phtml` | 同上对照 |
| `Service/PaymentMethodIncentiveQuoteService.php`（新） | 报价/上限/发布语义 |
| `Service/PaymentCheckoutSessionPersistenceService.php` | 扩展 `normalizeDiscountLines`；保留 `source_type`/`funding_source`/`method_code` |
| `Service/AmountBreakdownBuilder.php`（新，名可调） | amount_snapshot + discount_lines → 中性 DTO |
| `extends/module/Weline_Payment/PaymentProvider/PayPalProvider.php` | create/resume/patch 注入 breakdown；新 capability |
| `Service/PayPalApiClient.php` | `createOrder`/`patchOrder` 支持完整 `amount.breakdown` |
| `extends/module/Weline_Payment/PaymentProvider/FakeProvider.php` | capability + `echo_breakdown` 元数据 |
| `extends/module/Weline_Framework/Query/PaymentQueryProvider.php`（或现网 Query 壳路径） | 列表 payload `incentive` |
| `Api/Data/AvailabilityResult.php`（若需） | 对称预留激励字段，勿复用 surcharge |
| `Model/PaymentLedger.php` + Ledger 写入点 | 建议补 `TYPE_DISCOUNT` |
| `Model/PaymentAllocation.php` 使用点 | 复用既有 `ROLE_DISCOUNT` |
| `i18n/zh_Hans_CN.csv` + `en_US.csv` | 配置/摘要可见串（其它语种交翻译席） |
| `doc/payment-shell.md` + PayPal 方法文档短节 | 契约说明 |
| `Test/Unit/...` IncentiveQuote / Breakdown / PayPal payload / Fake echo 契约测 | |

### 7.2 协作席（非本席独占，deps 须钉）

| 路径/面 | 席 |
| --- | --- |
| Checkout 写入多行 `discount_lines`、列表 HTML「减 X」、摘要分列 | 前端/主题 + Checkout 后端 |
| 叠加矩阵与券基价协作 | Marketing/Checkout |
| 可见串默认站全语种 | 翻译工程师 |
| UC Browser 真通路 | 测试（本席改完后唤醒） |

### 7.3 明确本波不做

- 任意 Provider PHP 实现（本对齐波）
- Stripe/其它网关 breakdown
- surcharge 主路径
- 新建激励 Model 表
- 改 SESSION

---

## 8. construction_ready_after_freeze

**条件满足后本席可开工**（仍须 PM 发施工波 + contracts/deps 已冻）：

- [x] 本席 stance 已落盘
- [ ] `contracts.md` / `deps.md` 吸收上文配置键、discount_lines、breakdown、capability 矩阵、部分退比例、payload `incentive`
- [ ] 架构/扩展点确认 Event enrich 名与 SPI 是否新建
- [ ] 测试席 UC 步骤可执行
- [ ] 翻译/前端/主题 surfaces 已点名

`construction_ready_after_freeze`: **true（附条件）** — 冻结物落地后可进施工波；**当前波次仍为文档-only**。

---

## 9. 回报

- `result`: **delivered**（对齐表态 + 可施工点清单）
- `notify_pm`: **true**
- `@项目经理`：本席已交付/上报，请检查并更新 SESSION
- `paths_changed`:
  - `app/code/Weline/Payment/doc/开发/team/payment-method-incentive-discount/meetings/支付开发工程师-align.md`
  - `app/code/Weline/Payment/doc/开发/team/payment-method-incentive-discount/channel/align-freeze.md`
- `construction_ready_after_freeze`: **true**（附条件：contracts/deps 吸收本席条款后）
