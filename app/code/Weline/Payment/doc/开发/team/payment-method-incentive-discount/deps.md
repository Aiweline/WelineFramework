# deps.md · payment-method-incentive-discount

> 依赖序。`contracts.frozen=true`（测试主持升格 2026-09-22）。payload 正式名见 contracts §payload。

```text
对齐冻结 frozen=true（✅）
  → PM 开施工波

施工序：
  [1] 支付：SystemConfig incentive_* + SPI + discount_lines 合并 + Intent 重建
  [2] 支付：PayPal breakdown 守恒 + fake echo_breakdown + TYPE_DISCOUNT
       ∥ 前端/主题（incentive_savings_minor 等）：列表「减 X」+ 摘要分列（Token；UI+原型过签）
       ∥ 翻译：模块 CSV 仅 zh+en；其它词典 + collect
  [3] 退款比例回退
  [4] Team:测试: 真 Browser（fake + PayPal sandbox；抹自动化标志）金额恒等
  → 汇审
```

## 硬依赖

| 下游 | 依赖上游 |
|------|----------|
| 前端展示 | `incentive_savings_minor` / `incentive_display` / `incentive_available` 已冻（✅） |
| PayPal 透传 | 壳多行 `discount_lines` + capability |
| 退款 | 成功支付快照含激励行 + funding_source |
| 过手 | 支付改完 → 测试真浏览器闭环；fake 绿 ≠ PayPal 过 |

## 非依赖

- Stripe 首期透传  
- Marketing 作激励配置源  
- Ledger 类型补齐作为透传门禁（仍须同发布单元落地）
