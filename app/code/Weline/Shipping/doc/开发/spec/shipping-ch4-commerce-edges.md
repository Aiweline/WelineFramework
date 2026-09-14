---
status: done
work_kind: feature
feature_slug: shipping-ch4-commerce-edges
module: Weline_Shipping
updated: 2026-09-14
fe_be_scope: BE 主（Shipping/Payment/Checkout/Order）；FE 轻（结账文案/分行，复用现有 totals 样式）
ui_skill_decision: skip
clarify_status: locked-from-defect-review
commerce_guards_level: ch4
depends_on: shipping-ch3-pack-addon
closeout: ch1-4-plan-suite-pass
---

# 第4章规格：履约周边（退货 / DDP·DDU / COD / 分批发）

> SHIP-RETURN-001 / SHIP-INCOTERM-001 / PAY-COD-FEE-001 / SHIP-SPLIT-SHIPMENT-001  
> 依赖第1–3章；`commerce_guards_level` ≥ `ch4`。  
> 本章全绿 + plan-suite（Ch1–4）后才可宣称「运费防漏收已完成」。

## 澄清记录（含缺陷修订）

| Q | A（已锁定） |
|---|---|
| 退货策略 | Website `return_policy`：`buyer_pays`\|`seller_pays`\|`split_50`（默认 buyer）；模板 `SEED_TPL_RETURN_DOMESTIC`/`INTL`；`ReturnShippingQuoteService` 直算模板，**不**进前向 `quoteRates` |
| 退货×免邮 | 退货报价 **不受** 前向免邮影响 |
| DDP/DDU | 航线 `incoterm`：`ddp`\|`ddu`\|`dap`（默认 `ddu`）；仅 `duty_notice`；**不改** Local `amount_minor` |
| COD | 读 Payment `config.fee` → `cod_fee_amount_minor`；**必须计入** `grand_total_minor`；不进 Local 运费链 |
| 分批发 | 同单多 `OrderShipment`：`first_only`\|`each_shipment`；快照进已有 `shipping_snapshot_json` |
| vs charge owner | 多订单组运费归属 = 既有 `is_shipping_charge_owner`；本章 **不改** |
| vs 多仓拆单 | `SplitShippingQuoteService` 正交；本章不管仓拆 |
| each_shipment | **必须**真二次计费（可测金额>0）；禁止 stub 宣称 |

## 用户故事

As a 店主, I want 退货计价、贸易术语提示、货到付款手续费进总额、同单分批发策略, so that 履约周边不再漏收或误导。

## EARS

- WHEN 调用退货报价 THEN 系统 SHALL 按 return_policy 与退货模板计价，且 SHALL NOT 应用前向免邮。
- WHEN `return_policy=seller_pays` THEN 系统 SHALL 返回 amount_minor=0 且带 reason。
- WHEN `return_policy=split_50` THEN 系统 SHALL 返回约为表价一半（向下取整到 minor）。
- WHEN 航线具有任意 `incoterm` THEN 系统 SHALL 在报价行附带 `incoterm` 与 `duty_notice`，且 SHALL NOT 因此改变 `amount_minor`。
- WHEN 支付方式为货到付款且配置 `fee` THEN 系统 SHALL 计算 `cod_fee_amount_minor` 并 SHALL 将其计入 `grand_total_minor`。
- WHEN `split_shipment_shipping=first_only` 且为第 2+ 次发货 THEN 系统 SHALL 计运费 0。
- WHEN `split_shipment_shipping=each_shipment` 且为第 2+ 次发货 THEN 系统 SHALL 对剩余行再报价且金额可 >0。
- WHEN 多订单组拆分 THEN 系统 SHALL NOT 改变既有 shipping charge owner 语义。

## 用例

### UC-1 DDP 提示不改价

| 字段 | 内容 |
|------|------|
| scenario_key | `ch4_ddp_notice` |
| Given | 某 Local 航线 incoterm 临时为 ddp；同址同货报价两次（ddu/ddp） |
| When | quoteRates |
| Then | 两次 amount_minor 相等；ddp 行含 duty_notice / incoterm=ddp |
| 映射 | `ship-ch4-ut-incoterm` / `ship-ch4-e2e` |

### UC-2 买家付退货运费

| 字段 | 内容 |
|------|------|
| scenario_key | `ch4_return_buyer` |
| Given | return_policy=buyer_pays；国内退货 1kg |
| When | ReturnShippingQuoteService |
| Then | amount_minor > 0；无 free_shipping 清零 |
| 映射 | `ship-ch4-ut-return` / `ship-ch4-e2e` |

### UC-3 卖家承担退货运费

| 字段 | 内容 |
|------|------|
| scenario_key | `ch4_return_seller` |
| Given | return_policy=seller_pays |
| When | ReturnShippingQuoteService |
| Then | amount_minor=0；reason 含 seller_pays |
| 映射 | `ship-ch4-ut-return` / `ship-ch4-e2e` |

### UC-4 COD 进总额

| 字段 | 内容 |
|------|------|
| scenario_key | `ch4_cod_in_grand_total` |
| Given | 支付方式 config.fee=5.00；小计+运费基线已知 |
| When | CodFeeCalculator + totals 组装 |
| Then | cod_fee_amount_minor=500；grand_total = 基线 + 500 |
| 映射 | `ship-ch4-ut-cod` / `ship-ch4-e2e` |

### UC-5 分批发仅首程

| 字段 | 内容 |
|------|------|
| scenario_key | `ch4_split_first_only` |
| Given | split=first_only；首程运费 11500 |
| When | quoteSubsequentShipment(index=2) |
| Then | amount_minor=0 |
| 映射 | `ship-ch4-ut-split` / `ship-ch4-e2e` |

### UC-6 分批发每程计费

| 字段 | 内容 |
|------|------|
| scenario_key | `ch4_split_each_shipment` |
| Given | split=each_shipment；剩余行有计费重 |
| When | quoteSubsequentShipment |
| Then | amount_minor > 0 |
| 映射 | `ship-ch4-ut-split` / `ship-ch4-e2e` |

## 验收矩阵

| acceptance_id | type | scenario_key | 证据 |
|---|---|---|---|
| `ship-ch4-ut-incoterm` | ut | `ch4_ddp_notice` | CommerceEdgesChapter4ContractTest |
| `ship-ch4-ut-return` | ut | return_* | 同上 |
| `ship-ch4-ut-cod` | ut | `ch4_cod_in_grand_total` | Payment CodFee + Checkout totals |
| `ship-ch4-ut-split` | ut | split_* | SplitShipmentShippingService |
| `ship-ch4-rt-seed` | rt | — | 退货模板种子 + Upgrade 2.9.0 |
| `ship-ch4-wb-checkout` | wb | — | duty_notice / COD 行可见或同源+截图 |
| `ship-ch4-e2e` | e2e | 上列 | Weline_Shipping-ch4-commerce-edges.spec.js |
| `ship-anti-undercharge-plan-suite` | e2e-plan-suite | Ch1–4 | plan-suite |

## 划界

- 多单 `shippingChargeOwner`：非本章。
- 多仓 `SplitShippingQuoteService`：非本章。
- 完整 RMA UI / 关税实算：非目标。

## 就绪检查

- [x] ready-for-plan  
- [x] 缺陷修订口径写入澄清  
- [x] UC 含 COD grand_total 与 each_shipment 真金额  
- [x] 独立 `ship-ch4-e2e` + plan-suite  
