# Spec · 结账识别货源行并介入运费试算

## work_kind

`feature`（结账金额正确性；无新布局，`ui_skill_decision=skip`）

## 需求纠偏

用户说「CJ 产品」→ 壳侧按 **listing.provider_code + freight capability** 识别任意货源行（含 CJ），禁止 Checkout/Shipping 硬编码 `cj`。

## EARS

- WHEN 结账行 `offer_id` 命中 `dropship_listing` 且对应 Provider `capabilities.freight=true`，THE SYSTEM SHALL 在 Shipping 本站报价之后、分摊/折扣之前调用 `DropshipFreightAggregator` 试算并覆盖货源段运费。
- WHEN 货源试算失败，THE SYSTEM SHALL 按各 Provider `freightOnFailure()`（供应商 SystemConfig）执行：`fallback_local` 回退本站运费并继续结账；`block_checkout` 阻断结账。多供应商时任一选择阻断则阻断。
- WHEN 购物车无货源货运行，THE SYSTEM SHALL 不改变本站 Shipping 报价。
- IF 购物车全部可配送行为货源行，THEN 运费总额 = Provider 试算（各 provider 取最低档相加，换汇到结账币种）。
- IF 混合购物车，THEN 运费总额 = 本站非货源段 + Provider 货源段（有 `shipping_packages` 时按包替换；否则对本站总额做货源替换启发式：全货源覆盖）。

## UC-1 结账选配送

1. 顾客进入结账，系统 `listQuoteOptions`。
2. Checkout 派发 `shipping_methods::enrich`；Dropship 识别 listing 后试算并改写 `amount_minor`。
3. 顾客冻结报价；`shipping_quote::overlay` 以相同规则改写金额后再分摊/计税/折扣。

## 验收

- UT：识别 / 覆盖 / fail-closed / CjProvider 请求映射
- 契约：Checkout 派发 overlay + enrich；Dropship 观察者注册
- 不改 Shipping 核多贡献 SPI
