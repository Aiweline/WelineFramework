# Weline_Cart::cart_summary::enrich

## 事件名

`Weline_Cart::cart_summary::enrich`

## 触发时机

`CartService::summary()` 组装完基础摘要、返回调用方之前。

## 数据契约

| 键 | 类型 | 说明 |
|---|---|---|
| `summary` | `array` | 可写回；观察者可追加 `tax_amount_minor` / `grand_total_minor` 等 |
| `scope` | `array` | `website_id` / `store_id` / `channel_id` / `scope_key` |

## 约束

- Cart **不**硬依赖 Tax；税费由 `Weline_Tax` Observer 可选 enrich。
- 失败隔离：观察者异常不得阻断购物车摘要。
- 运费/关税预估仍在结账阶段；购物车默认可只带销售税预估。

## 注册

`Weline_Tax/etc/event.xml` → `CartSummaryEnrichTaxObserver`
