---
status: ready-for-plan
work_kind: feature
feature_slug: shipping-ch2-gates-surcharge
module: Weline_Shipping
updated: 2026-09-14
fe_be_scope: BE 主（加价/门禁）；FE 轻（商品危品、结账透传 point_type）
ui_skill_decision: skip
clarify_status: locked-from-prior
commerce_guards_level: ch2
depends_on: shipping-ch1-rate-profile
---

# 第2章规格：门禁与加价

> SHIP-SURCHARGE-001 / SHIP-ADDR-CAP-001 / SHIP-HAZMAT-001  
> 依赖第1章；`shipping.commerce_guards_level` ≥ `ch2`。  
> **验收债**：实现可已合入，章通路 e2e/WB 须按 `acceptance_ids` PASS。

## 澄清记录

| Q | A（已锁定） |
|---|---|
| 偏远加价 | Local 模板价之上叠加；固定或比例 |
| 外 Provider | 默认不叠店铺偏远（防双计） |
| 地址类型 | `delivery_point_type` × `allowed_point_types`；默认不含 pobox/military |
| 危品 | 行 `shipping_hazard_class`；航线空 accepted=仅普货 |
| 免邮 | 默认只清模板运费，保留偏远加价 |

## 用户故事

As a 店主, I want 偏远加价与地址/危品门禁, so that PO Box、危品、偏远地址不会按普货同价漏收。

## EARS

- WHEN 收货地址命中偏远规则且承运商为 Local THEN 系统 SHALL 在模板运费之上叠加偏远加价。
- WHEN 承运商 `provider_code≠local` THEN 系统 SHALL 默认不叠加店铺偏远规则。
- WHEN 地址 `delivery_point_type` 不在航线 `allowed_point_types` 内 THEN 系统 SHALL 不报价该航线。
- WHEN 行存在危品 class 且航线 `accepted_hazard_classes` 不包含 THEN 系统 SHALL 不报价。
- WHEN 免邮命中 THEN 系统 SHALL 清零模板运费，且默认保留偏远加价。

## 用例

### UC-1 新疆偏远加价

| 字段 | 内容 |
|------|------|
| 角色 | 顾客 |
| scenario_key | `ch2_cn_xj_surcharge` |
| Given | 种子 `SEED_SURCHARGE_CN_XJ`（固定 25）；Local 国内航线；地址省=新疆；1kg 国内行 |
| When | `quoteRates` |
| Then | `SEED_LANE_DOMESTIC` 的 `surcharges` 含 `SEED_SURCHARGE_CN_XJ`；amount_minor ≥ 模板价 + 2500 |
| 映射 | `ship-ch2-ut-surcharge` / `ship-ch2-e2e` |

### UC-2 PO Box 拒报

| 字段 | 内容 |
|------|------|
| 角色 | 顾客 |
| scenario_key | `ch2_pobox_refuse` |
| Given | 航线 allowed 默认不含 pobox；地址 US + delivery_point_type=pobox；2kg General |
| When | `quoteRates` |
| Then | rates 为空或无 Local 航线；门禁 `allowsPointType(..., pobox)=false` |
| 映射 | `ship-ch2-ut-gate` / `ship-ch2-e2e` |

### UC-3 危品×普货拒报

| 字段 | 内容 |
|------|------|
| 角色 | 顾客 |
| scenario_key | `ch2_hazard_refuse` |
| Given | 行 `shipping_hazard_class=battery_lithium`；航线 accepted 空；地址 US residential |
| When | `quoteRates` |
| Then | 抛 `shipping_profile_conflict` 或无 Local 普货航线出价 |
| 映射 | `ship-ch2-ut-gate` / `ship-ch2-e2e` |

### UC-4 外 Provider 不加店铺偏远

| 字段 | 内容 |
|------|------|
| 角色 | 系统 |
| scenario_key | `ch2_external_no_shop_surcharge` |
| Given | Local 计价路径才调用 `ShippingSurchargeService`；外 Provider 自计价 |
| When | 读 LocalTemplatePricing vs Provider 边界 |
| Then | 店铺偏远规则仅 Local overlay；外运商结果无 `SEED_SURCHARGE_*` 注入 |
| 映射 | `ship-ch2-e2e`（契约 + harness 注释断言） |

### UC-5 免邮保留偏远

| 字段 | 内容 |
|------|------|
| 角色 | 顾客 |
| scenario_key | `ch2_free_keeps_remote` |
| Given | 国内航线绑免邮规则且小计达标；地址省=新疆 |
| When | `quoteRates` |
| Then | `free_reason` 非空；模板基价视为 0；`surcharges` 仍含新疆加价且计入 amount_minor（≥2500） |
| 映射 | `ship-ch2-ut-surcharge` / `ship-ch2-e2e` |

## 验收矩阵

| acceptance_id | type | scenario_key | 断言要点 | 证据 |
|---|---|---|---|---|
| `ship-ch2-ut-gate` | ut | `ch2_pobox_refuse` / `ch2_hazard_refuse` | point/hazard 门禁 | `GatesSurchargeChapter2ContractTest` |
| `ship-ch2-ut-surcharge` | ut | `ch2_cn_xj_surcharge` | Local 叠加/种子码 | 同上 + harness |
| `ship-ch2-rt-seed` | rt | — | 六省 SEED_SURCHARGE_* | 契约 + Upgrade |
| `ship-ch2-wb-checkout` | wb | — | 结账透传 delivery_point_type / 危品元数据 | Browser + evidence/ch2 |
| `ship-ch2-e2e` | e2e | 上列 ch2_* | 真报价 harness | `Weline_Shipping-ch2-gates-surcharge.spec.js` |
| `ship-anti-undercharge-plan-suite` | e2e-plan-suite | ch1–3 | 组套 | plan-suite spec |

## 种子

- CN：新疆/西藏/青海/内蒙古/宁夏/甘肃固定加价  
- 默认 `allowed_point_types=residential,commercial,pickup_point`  
- 默认 `accepted_hazard_classes` 空  

## 就绪检查

- [x] ready-for-plan  
- [x] 用户故事 + EARS + UC Given/When/Then  
- [x] acceptance_ids 含独立 `ship-ch2-e2e`  
