# channel — WO-HP-P3-MALL-04 done（席 B）

日期：2026-09-22  
工单：`WO-HP-P3-MALL-04 · 货架密度与角标`  
席位：`Team:部件开发工程师:` + 前端（Token 消费；**未**改 Hero / placement 总顺序 / category-grid）  
验收面：`https://p05113ef3.test.weline.com:9555/zh_Hans_CN/?nocache=1`

## 改了什么

### 商品卡角标（canonical）
- `Product/view/templates/frontend/partials/product-card.phtml`：经 `ProductLabelStyle::resolveFlags` 渲染折扣 / 热销 / 包邮。
- `Theme/Helper/ProductLabelStyle.php`：扩展 `hot` / `ship`；折扣优先输出 `-N%`，否则「特价」；`$ ≥ 49` 可出包邮角标（与信任条门槛对齐）。
- `Theme/.../partials/product/labels.phtml` + `components/product-label.phtml`：支持热销/包邮文案与 Token 色。
- `ProductCardRenderer`：规范化 `discount_percent` / `is_hot`；CSS 代次 `20260922-mall04-shelf-badges`。

### 货架密度（1 行×4 + 查看更多）
四个首页货架部件统一：
- 默认 `limit=4`、`columns=4`；遗留 `limit>columns` 收成一行。
- `density="shelf"`；压缩区块纵向 padding。
- 底部「查看更多」：精选→`products`、特价→`products?filter=sale`、新品→`new-arrivals`、热销→`best-sellers`。
- 热销卡强制 `is_hot`；特价卡写出 `-N%` 角标。

涉及文件：
- `Theme/.../widgets/product/featured-products/default.phtml`
- `Theme/.../widgets/product/deals-of-day/default.phtml`
- `Theme/.../widgets/product/new-arrivals/default.phtml`
- `Theme/.../widgets/product/bestsellers/default.phtml`
- `Theme/Service/DefaultLayoutSeeder.php`（首页 featured/new-arrivals `limit` 8→4）
- `Theme/test/Unit/ThemeAmazonProductCardWidgetContractTest.php`
- `Product/Test/Unit/Taglib/ProductCardContractTest.php`（CSS 代次断言）

**未改**：homepage layout 区块顺序、Hero 高度、category-grid（席 A 边界）。

## 验收证据（nocache DOM）

| 货架 | cards | density | 价 | 主 CTA | 查看更多 | 角标 |
|------|-------|---------|----|--------|----------|------|
| 特色产品 | 4 | shelf×4 | 4 | 加购/购买可达 | 1 | 新品 |
| 今日特价 | 4 | shelf×4 | 4 | 同上 | 1 | **-15%** |
| 新品 | 4 | shelf×4 | 4 | 同上 | 1 | 新品 |
| 热销 | 4 | shelf×4 | 4 | 同上 | 1 | **热销** |

自验手段：curl nocache HTML 解析 + Chrome DevTools `evaluate_script`（page 验收面）。  
契约：`ThemeAmazonProductCardWidgetContractTest` **6/6 OK**。

## 残留风险

1. **包邮角标**：现网货架成交价多 `< $49`，自动包邮角标未出现（逻辑保留，达标价后会亮）。
2. **`i18n:collect`**：本席启动后长时间无输出，已中止；`查看更多/特价/热销/包邮` 已在 Theme 中英 CSV；若词典未刷新，zh 源串仍可见。
3. **搜索页 bestsellers**：grid 模式同样一行化；若运营要搜索页双行，需另配 `columns`/`layout=masonry`。
4. **席 A 并行**：未触 layout 顺序；若席 A 重排货架，本单密度/角标仍生效。

## 状态

- [x] 代码已改并自验
- [x] 本 done 落盘

`notify_pm: true`

@项目经理：本席已交付/上报，请检查并更新 SESSION。
