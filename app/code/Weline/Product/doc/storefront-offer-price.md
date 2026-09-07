# 前台成交价组装（StorefrontOfferPrice）

Product 拥有**唯一**前台 unit 成交价读出口，方便 Marketing / Promotion / 会员价等模块扩展多种优惠。

## 边界

| 层级 | 归属 | 说明 |
|------|------|------|
| 目录价 | Product `PriceRepository` / Offer `unit_price_minor` | 基础价 |
| 前台 unit 优惠 | Product `StorefrontOfferPriceAssembler` + Providers | 活动主题 deal、未来会员价等；卡片/PDP/Cart 快照同源 |
| 结账券/购物车规则 | Marketing `DiscountQuote` | **不**走 Assembler；已 bake 的 theme deal 在规则侧跳过防双扣 |

Event 只做缓存失效（`w_changed`），**不算价**。

## SPI

- 接口：`Api/Storefront/StorefrontPriceAdjustmentProviderInterface`
- 注册：`extends/module/Weline_Product/StorefrontPriceAdjustmentProvider/{Name}.php`
- 组装：`Api/StorefrontOfferPriceAssemblerInterface` → `Service/Storefront/StorefrontOfferPriceAssembler`
- 视图：`Api/Data/StorefrontOfferPriceView`（`final` / `compare_at` / `primary_campaign.label|url`）

## 合并策略（v1）

1. 非 `stackable`：同一 `exclusive_group`（默认 `unit`）内按节省额取最强一条；`absolute_minor`（绝对单位价）若存在则优先胜出（ToB 价目）。
2. `stackable=true`：在独占胜出后按 priority 顺序叠加（为会员价等预留）。
3. 卡片划线旁展示 `primary_campaign.label`（可链到活动页 URL）。

## 现有 Provider

- `Weline_Promotion`：`PromotionThemeDealPriceAdjustmentProvider`（活动主题 deal + 活动名）
- `Weline_B2B`：`B2BStorefrontPriceAdjustmentProvider`（仅 `sellingMode=tob` 且客户有 membership 时返回 `TYPE_ABSOLUTE_MINOR` 价目单价）

## 含价缓存 vary（硬规则）

店面/列表/卡片若缓存含价 HTML/JSON，键必须至少 vary：

`website + store + channel + locale + currency + selling_mode`

ToB 另加 `group_id`（或 membership 版本）；无身份 tob 不得缓存批发价。失效跟随 membership / 价目 revision。

## 调用约定

```php
$view = $assembler->assemble(StorefrontPriceContext::fromCatalogMinor($productId, $catalogMinor, $currency));
$flat = $view->toArray(); // price / original_price / has_deal / campaign_label / campaign_url
```

禁止在模板或跨模块 Service 内再次 `applyDealToPrice`。

## 变体 / PDP JS / Cart 同源（硬规则）

1. 目录投影可写出 `catalog_price_minor`（原价）+ `unit_price_minor`（Assembler 成交价）。
2. 变体交互 JSON **必须**透传 `catalog_price_minor`（及 `compare_at_minor`/`has_deal`）；禁止只给已折 `unit_price_minor`。
3. PDP JS **禁止**把 `unit_price_minor` 当 catalog 再 `applyDealToMinor`（会叠折）。优先直接展示服务端 `unit` + 划线 `catalog/compare_at`。
4. Cart 加购 bake + 摘要 `presentLine` 重解析 + Checkout freeze 均走同一 Assembler 快照；Marketing 对 `promotion_activity_theme` 跳过防双扣。
