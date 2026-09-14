---
status: ready-for-plan
work_kind: feature
feature_slug: shipping-ch1-rate-profile
module: Weline_Shipping
updated: 2026-09-14
fe_be_scope: BE 主（RateTemplate/Profile/报价）；FE 轻（后台模板表单字段）
ui_skill_decision: skip
clarify_status: locked-from-prior
commerce_guards_level: ch1
---

# 第1章规格：运费阶梯表 + Shipping Profile

> SHIP-RATE-TABLE-001 / SHIP-PROFILE-002  
> 章级开关：`shipping.commerce_guards_level` ≥ `ch1` 方可宣称本章交付。  
> **验收债**：实现可已合入，但章通路 e2e/WB 须按下列 `acceptance_ids` PASS 后才可标章验收关毕。

## 澄清记录

| Q | A（已锁定） |
|---|---|
| 计价形态 | 对齐 Shopify Custom rates：`fixed` / `weight_table` / `price_table` |
| 计费重 | max(实重, LWH/5000)；需配送须重量+三边 |
| Profile | 实体 General/Heavy；空→General；>30kg 可售须 Heavy |
| 多 Profile | `provider_code+service_name` 合并；无同名则合成各组最低价之和 |
| 外物流失败 | 禁止 Local 顶替、禁止静默 0 |

## 用户故事

As a 店主, I want Shopify 式阶梯运费与配送 Profile, so that 缺重/超重/重货不会被首档或便宜航线漏收。

## EARS

- WHEN 费用模板类型为 `weight_table` 或 `price_table` THEN 系统 SHALL 按连续区间命中档价，且不计 `base_fee`。
- WHEN 计费重合计超过模板 `max_weight_kg` THEN 系统 SHALL 不报价该 Local 航线。
- WHEN `weight_table` 计费重为 0 THEN 系统 SHALL 不报价（禁止吃首档）。
- WHEN 商品 `requires_shipping=1` THEN 系统 SHALL 在可售路径要求 `weight_kg>0` 且长宽高>0。
- WHEN Offer/分类未绑 Profile THEN 系统 SHALL 解析为 `SEED_PROFILE_GENERAL`。
- WHEN 计费重>30kg THEN 系统 SHALL 拒绝以 General 可售（须绑 Heavy）。
- WHEN 购物车多 Profile THEN 系统 SHALL 按 `provider_code+service_name` 合并；无同名则合成最低价之和。
- WHEN 外 Provider 报价失败 THEN 系统 SHALL NOT 用 Local 顶替或静默 0 元。

## 用例

### UC-1 新站种子齐全

| 字段 | 内容 |
|------|------|
| 角色 | 运维 / Agent |
| scenario_key | `ch1_seed_profiles` |
| Given | Website=0；可执行 seedDefaultWebsite |
| When | 调用 seed / 查模板与 Profile |
| Then | General 九线模板码 + Heavy 两线 + `SEED_PROFILE_GENERAL`/`SEED_PROFILE_HEAVY` 均存在；`expectedSeedTemplateCodes` 长度=11 |
| 映射 | `ship-ch1-rt-seed` / `ship-ch1-e2e` |

### UC-2 2kg 美洲档价

| 字段 | 内容 |
|------|------|
| 角色 | 顾客 |
| scenario_key | `ch1_americas_2kg` |
| Given | Americas weight_table 种子（base=45, rate=14）；行计费重=2kg（weight_minor=2000，三边>0）；地址 US；Profile=General |
| When | `quoteRates` Local |
| Then | 出现 `SEED_LANE_AMERICAS`；`amount_minor` = 11500（档价 115.00，precision=2）；**不等于**国内首档价 |
| 映射 | `ship-ch1-ut-brackets` / `ship-ch1-e2e` |

### UC-3 35kg General 拒小件

| 字段 | 内容 |
|------|------|
| 角色 | 顾客 / 店主 |
| scenario_key | `ch1_general_35kg_refuse` |
| Given | 行绑 General；计费重 35kg；地址 US |
| When | `quoteRates` |
| Then | General 国际小件航线（如 `SEED_LANE_AMERICAS`）**不出价**（超 max_weight_kg=30） |
| 映射 | `ship-ch1-ut-max` / `ship-ch1-e2e` |

### UC-4 35kg Heavy 高价

| 字段 | 内容 |
|------|------|
| 角色 | 顾客 |
| scenario_key | `ch1_heavy_35kg_quote` |
| Given | 行 `shipping_profile_code=SEED_PROFILE_HEAVY`；计费重 35kg；地址 US |
| When | `quoteRates` |
| Then | `SEED_LANE_HEAVY_INTERNATIONAL` 有价；amount_minor ≥ 220000（Heavy 首档 2200.00）；且高于同址 2kg 美洲档 |
| 映射 | `ship-ch1-e2e` |

### UC-5 缺重/缺尺寸

| 字段 | 内容 |
|------|------|
| 角色 | 店主 / 报价引擎 |
| scenario_key | `ch1_missing_dims_refuse` |
| Given | requires_shipping；weight_minor=0 或缺 L/W/H |
| When | weight_table 报价 / ChargeableWeight 汇总 |
| Then | 缺重 → Local 表价不出价；缺尺寸 → `missing_dims=true`（可售门禁） |
| 映射 | `ship-ch1-ut-chargeable` / `ship-ch1-e2e` |

## 验收矩阵

| acceptance_id | type | scenario_key | 断言要点 | 证据 |
|---|---|---|---|---|
| `ship-ch1-ut-brackets` | ut | `ch1_americas_2kg` | Americas 种子档价连续、封顶 30kg | `RateTableChapter1ContractTest` |
| `ship-ch1-ut-chargeable` | ut | `ch1_missing_dims_refuse` | 体积重优先；缺重/缺尺寸标记 | 同上 |
| `ship-ch1-ut-max` | ut | `ch1_general_35kg_refuse` | 超 max / 缺重拒表 | 同上 + RateCalculation |
| `ship-ch1-rt-seed` | rt | `ch1_seed_profiles` | expectedSeedTemplateCodes=11 | `RateTemplateSeedCompletenessContractTest` + harness |
| `ship-ch1-wb-admin` | wb | — | 后台费用模板可见 weight_table / max_weight_kg | Browser WB-OP + `doc/evidence/ch1/` |
| `ship-ch1-e2e` | e2e | 上列全部 ch1_* | 真报价 harness 按 UC 断言 | `Weline_Shipping-ch1-rate-profile.spec.js` |
| `ship-anti-undercharge-plan-suite` | e2e-plan-suite | ch1–3 关键 | 终章组套（第4章前仅串联 1–3） | `Weline_Shipping-anti-undercharge-plan-suite.spec.js` |

## 非本章

偏远加价、地址类型、危品、分箱、退货、DDP、COD（第2–4章）。

## 就绪检查

- [x] status ≥ clarified → ready-for-plan  
- [x] ≥1 用户故事 + ≥2 EARS  
- [x] ≥1 用例含主成功路径 + Given/When/Then  
- [x] 非目标明确  
- [x] 已点名 `ship-ch1-e2e` / plan-suite  
- [x] 正文不写实现补丁步骤  
