---
status: ready-for-plan
work_kind: feature
feature_slug: shipping-ch3-pack-addon
module: Weline_Shipping
updated: 2026-09-14
fe_be_scope: BE 主（分箱/旺季/附加）；FE 轻（结账 addons 透传）
ui_skill_decision: skip
clarify_status: locked-from-prior
commerce_guards_level: ch3
depends_on: shipping-ch2-gates-surcharge
---

# 第3章规格：同仓分箱 + 签名保价 + 旺季燃油

> SHIP-PACK-001 / SHIP-ADDON-001 / SHIP-SEASONAL-001  
> 依赖第1–2章；`shipping.commerce_guards_level` ≥ `ch3`。  
> **先规格后码**：本文件 `ready-for-plan` 后方可实现。

## 澄清记录

| Q | A（已锁定） |
|---|---|
| 同仓分箱 | Website 作用域 `ShippingPackingPolicy`：`max_weight_kg` + `max_volume_cm3`；默认 30 / 120000 |
| 与多仓关系 | 正交：多仓拆单之后，仓内再按策略切虚拟箱 |
| 签名/保价 | `ShippingCheckoutAddon`；quote 上下文 `addons.signature` / `addons.insurance_value_minor` |
| 旺季/燃油 | `ShippingSeasonalRule` 日期窗+fixed/percent；种子 is_active=0 |
| 免邮 | 只清模板运费；不清偏远/旺季/签名保价 |
| Provider | 分箱与店铺 addon/季节规则默认仅 Local 叠加 |

## 用户故事

As a 店主, I want 超单箱限重自动分箱计价并可选签名保价与旺季加价, so that 大件/峰值期不再按单箱漏收。

## EARS

- WHEN 同行同仓计费重或体积超过 packing policy 单箱上限 THEN 系统 SHALL 切成多虚拟箱并对各箱 Local 模板价求和。
- WHEN 结账请求 `addons.signature=true` 且规则启用 THEN 系统 SHALL 在运费小计叠加签名费。
- WHEN 结账请求含 `insurance_value_minor` 且保价规则启用 THEN 系统 SHALL 按规则叠加保价费。
- WHEN 当前日期落在启用的季节规则窗内 THEN 系统 SHALL 对 Local 价叠加旺季/燃油费。
- WHEN 免邮命中 THEN 系统 SHALL 清零模板运费，且默认保留分箱后的偏远/旺季/addon。
- WHEN provider≠local THEN 系统 SHALL 默认不叠店铺 packing 重算与季节/addon（外运商自计价）。

## 用例

### UC-1 超限重拆多箱相加

| 字段 | 内容 |
|------|------|
| 角色 | 顾客 |
| scenario_key | `ch3_split_boxes` |
| Given | policy max_weight=30；两件各 20kg（qty=2 或两行）同仓 General；地址 US |
| When | `quoteRates` Local |
| Then | `package_count` ≥ 2；有 Local 航线出价；总额 ≥ 单箱同重报价（防单箱漏收） |
| 映射 | `ship-ch3-ut-pack` / `ship-ch3-e2e` |

### UC-2 勾选签名后合计增加

| 字段 | 内容 |
|------|------|
| 角色 | 顾客 |
| scenario_key | `ch3_signature_addon` |
| Given | 签名 addon 种子启用固定 5；同址同货两次报价 |
| When | 一次无 signature；一次 `addons.signature=true` |
| Then | 后者 amount_minor 更大；`addons` 明细含 `SEED_SIGNATURE`（或 signature 类型） |
| 映射 | `ship-ch3-ut-addon` / `ship-ch3-e2e` |

### UC-3 打开峰值后加价

| 字段 | 内容 |
|------|------|
| 角色 | 运维 / 系统 |
| scenario_key | `ch3_seasonal_on` |
| Given | harness 临时将 `SEED_PEAK`（或 FUEL）`is_active=1` 且日期窗覆盖今天；同址报价 |
| When | `quoteRates` |
| Then | rate.`seasonal` 非空且 amount 大于关闭时；跑完后恢复 is_active=0 |
| 映射 | `ship-ch3-ut-seasonal` / `ship-ch3-e2e` |

### UC-4 多仓后再分箱

| 字段 | 内容 |
|------|------|
| 角色 | 系统 |
| scenario_key | `ch3_split_after_warehouse` |
| Given | PackingSplitter 与 SplitShippingQuote 正交 |
| When | 单仓超箱限 |
| Then | 仓内独立分箱；不跨仓并箱（本章以同仓分箱 harness 覆盖主路径） |
| 映射 | `ship-ch3-e2e` |

## 验收矩阵

| acceptance_id | type | scenario_key | 断言要点 | 证据 |
|---|---|---|---|---|
| `ship-ch3-ut-pack` | ut | `ch3_split_boxes` | PackingSplitter 切箱 | `PackAddonChapter3ContractTest` |
| `ship-ch3-ut-addon` | ut | `ch3_signature_addon` | 签名/保价叠加 | 同上 + harness |
| `ship-ch3-ut-seasonal` | ut | `ch3_seasonal_on` | 日期窗匹配 | 同上 + harness |
| `ship-ch3-rt-seed` | rt | — | policy+季节种子(默认关)+Upgrade | 契约 |
| `ship-ch3-wb-checkout` | wb | — | 结账可传 addons | evidence/ch3 |
| `ship-ch3-e2e` | e2e | 上列 ch3_* | 真报价 harness | `Weline_Shipping-ch3-pack-addon.spec.js` |
| `ship-anti-undercharge-plan-suite` | e2e-plan-suite | ch1–3 | 组套 | plan-suite |

## 非本章

退货、DDP/DDU、COD、分批发策略（第4章）。

## 就绪检查

- [x] ready-for-plan  
- [x] 澄清锁定 + EARS + UC Given/When/Then  
- [x] 独立 `ship-ch3-e2e`  
- [x] 未把类名补丁步骤写入正文（实现阶段再写）  
