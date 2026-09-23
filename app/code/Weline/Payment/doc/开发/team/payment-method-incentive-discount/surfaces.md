# surfaces.md — payment-method-incentive-discount（机制落点）

> 扩展点席贡献机制类别；**架构师钉死命名与 ownership**（2026-09-22）。  
> 对齐：`meetings/扩展点.md` + `meetings/架构师.md` + `Framework/doc/3-开发/扩展点选型.md` + `payment-shell.md`。  
> 状态：**机制类别 + 正式命名均可冻结**（与扩展点无冲突）。  
> 首期：PayPal + `fake_card`；**禁止 surcharge 主路径**。

## 0. 选型合规总览

| 意图 | 选用机制 | 状态 | 禁止 |
|------|----------|------|------|
| 列表旁路注入「可减」 | **Event** + Observer | ✅ 新建；正式名已钉 | 跨模块 `new` Service；改 core 模板硬算 |
| 读方式+激励价 | **QueryProvider** | ✅ 扩展 `paymentMethodPayload` | 跨模块读 Model；跳过 Query |
| 激励报价/入快照（写） | **Interface / SPI** | ✅ `PaymentMethodIncentiveQuoteInterface` | 直调 Controller；平行计价 |
| freeze quote 贡献 | **Event**（既有） | ✅ 复用 enrich 范式 | Checkout 直调 Calculator |
| 网关明细透传 | **Provider capability** + Provider | ✅ keys 已钉；首期 PayPal | 伪造 breakdown；复用 `supported_discount_actions` |
| 后台激励配置 | **SystemConfig** | ✅ `payment/method/{code}/incentive_*`；未配=0 | 隐式配置源 |
| 列表/摘要 UI | **Hook** / HTML + payload | ✅ 消费 Query；Theme Token | 主题硬编码 method 折扣 / 私有色板 |
| 营销 Action 兼容 | 既有 capability | ✅ 保留原语义 | 与激励混名 |
| Widget / 拖拽部件 | — | **skip** | 无关注入 |

---

## 1. 机制落点表（架构钉死后）

| surface / 能力 | 机制 | 拥有模块 | 落点（正式） | 备注 |
|----------------|------|----------|--------------|------|
| 可用方式列表 enrich | Event | **`Weline_Payment` dispatch** | **`Weline_Payment::checkout::available_methods::enrich`** | 须 `Payment/doc/event/` + `event.xml`；Checkout 只消费 |
| 方式列表读模型 | QueryProvider | `Weline_Payment` | `PaymentQueryProvider::paymentMethodPayload` → **`incentive_savings_minor` 等扁字段**（见 contracts §payload） | 见 §3；权威以 contracts 为准 |
| 激励报价/应用 | SPI | `Weline_Payment` | **`PaymentMethodIncentiveQuoteInterface`** | 配置真相源=SystemConfig（非 Marketing 主规则） |
| Session 折扣行快照 | Service（壳） | `Weline_Payment` | `PaymentCheckoutSessionPersistenceService` + `discount_lines` | `source_type=payment_method_incentive` |
| freeze quote 旁路 | Event | Checkout 触发 / Payment Observer | `Weline_Checkout::checkout::freeze_quote::enrich` | 对齐资产折扣范式；可选挂激励行 |
| 透传能力声明 | Provider capability | 各 Provider | **`amount_breakdown`** + **`discount_passthrough`**（可选 `passthrough_formats`） | ≠ `supported_discount_actions` |
| PayPal breakdown | Provider 映射 | Extends | `PayPalProvider` / `PayPalApiClient` | 守恒 breakdown；站内多行、网关 discount 聚合 |
| fake 对照 | Provider | Fake | capability + **`echo_breakdown`** 断言 | fake 绿 ≠ PayPal 过 |
| 激励配置 | SystemConfig | `Weline_Payment` | `payment/method/{code}/incentive_*` | Website→Store；默认 0 |
| 营销兼容闸门 | 既有 | Payment + Checkout | `DiscountActionSupportService` | **勿改语义承载激励** |
| 列表「减 X」UI | Hook / HTML | Checkout + Theme | 消费 payload；Token + `w-*` | UI/主题门禁见 contracts |
| Widget | — | — | skip | — |

---

## 2. 架构钉死 · 正式命名

| 项 | 正式值 |
|----|--------|
| Event | `Weline_Payment::checkout::available_methods::enrich`（Payment 归属 dispatch） |
| SPI | `Weline\Payment\Api\PaymentMethodIncentiveQuoteInterface` |
| Capability | `amount_breakdown: bool`；`discount_passthrough: bool`；可选 `passthrough_formats: string[]` |
| 列表字段（正式） | **`incentive_savings_minor`**（≥0）+ `incentive_display` + `incentive_available` + `incentive_type` / `incentive_percent` |
| discount 行 source | `payment_method_incentive` |
| Config 前缀 | `payment/method/{method_code}/incentive_*` |

> **payload 权威**：`contracts.md` §payload（测试主持钉死，2026-09-22）。原 `incentive_discount.amount_minor` ≡ `incentive_savings_minor`；验收禁未写入 contracts 的别名。

---

## 3. Payload / `discount_lines`（冻结 · 与 contracts 对齐）

### 3.1 列表扁字段（Query / `payment_methods[]`）

```text
incentive_savings_minor: int,     // ≥0 展示可减额
incentive_display: string,        // 服务端 i18n 文案
incentive_available: bool,
incentive_type?: 'fixed_amount' | 'percentage',
incentive_percent?: number
```

与 §5.2 `surcharge` **反向分字段**；本 feature 主路径禁止使用 surcharge。  
SPI 内部 quote 可结构化，出站必须投影为上表。

### 3.2 `discount_lines[]`

`key`（稳定，如 `pmi:{method}:{ver}`）、`label`、`amount_minor`（**负向**）、`source_type`∈`coupon|cart|shipping|payment_method_incentive|…`、激励行必填 `funding_source`+`method_code`。

---

## 4. SystemConfig 键（MVP）

`incentive_enabled` / `incentive_type` / `incentive_amount_minor` / `incentive_percent` / `incentive_cap_minor` / `incentive_funding_source` / `incentive_valid_from` / `incentive_valid_to` / `incentive_label`  
（前缀 `payment/method/{method_code}/`）

---

## 5. Marketing / 券边界

| 域 | 拥有 |
|----|------|
| 券/满减行 | Marketing + 既有 Checkout 写入 |
| 支付方式激励额 | Payment 壳（SystemConfig + SPI） |
| 统一快照与透传编排 | Payment 壳 |
| `supported_discount_actions` | 仅营销 Action 兼容 |

---

## 6. Intent 重建

切换 method → 剔旧激励行 → SPI 重算 → 写快照（含 rule_version）→ 新 Attempt；应付/method 变化则重建 Intent。禁止旧金额调新 method。

---

## 7. 跨模块禁令（硬）

同扩展点 X1–X8；另：**禁止**壳内重写网关 create/patch（`shell_provider_business_isomorph`）；**禁止**平行计价；**禁止**本 feature surcharge 主路径。

---

## 8. 与扩展点对齐结论

| 扩展点开放点 | 架构决议 |
|--------------|----------|
| Event 名与归属 | Payment dispatch · 正式名见 §2 |
| payload 命名 | `incentive_savings_minor` 等扁字段（contracts 权威） |
| PayPal 多行粒度 | 站内多行硬；网关 `discount` 聚合；守恒硬 |
| Ledger 类型 | 补 `TYPE_DISCOUNT` + `ROLE_DISCOUNT`（同发布单元；非透传门禁） |

**无 escalate**：机制类别一致；命名已钉。
