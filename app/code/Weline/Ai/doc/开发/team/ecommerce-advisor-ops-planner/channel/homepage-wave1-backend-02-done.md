# channel — WO-HP-P1-02 后端回执（三货架错开选题）

日期：2026-09-22  
角色：`Team:后端:`（主导）+ 协同部件数据源边界  
工单：`WO-HP-P1-02`  
`notify_pm: true`  
`@项目经理：本席已交付/上报，请检查并更新 SESSION`

MCP：`prepare_project` 本回合 `Not connected`，已按 `AI硬规则索引.md` 继续；未编造规则。清 Theme 运行缓存后 nocache 自验。

---

## result

`closed`

## 实现策略

1. 新增纯函数 `HomepageShelfStagger`：Deals → Hot → Featured 优先级；Featured 让位。  
2. `StorefrontProductWidgetCatalog` 增加请求级 memo 的 `homepageFeaturedCards` / `homepageDealsCards` / `homepageHotCards`。  
3. Featured 候选：近 30 日新品优先，再目录填坑（非纯销量）。  
4. Deals：真实划线折扣 ≥15% 或限时标；不足不借其它货架；店面空态 hidden（仅预览用 Demo）。  
5. Hot：既有热度排序池，排除 Deals 前 4。  
6. 三部件模板改接上述入口；禁止 JSON+布局双路径（本波只改 Theme 自有部件数据源，未改 layout 内嵌）。

## paths_changed

- `app/code/Weline/Product/Service/HomepageShelfStagger.php`（新建）
- `app/code/Weline/Product/Service/StorefrontProductWidgetCatalog.php`
- `app/code/Weline/Theme/view/theme/frontend/widgets/product/featured-products/default.phtml`
- `app/code/Weline/Theme/view/theme/frontend/widgets/product/deals-of-day/default.phtml`
- `app/code/Weline/Theme/view/theme/frontend/widgets/product/bestsellers/default.phtml`
- `app/code/Weline/Product/Test/Unit/Service/HomepageShelfStaggerContractTest.php`（新建）
- `app/code/Weline/Theme/test/Unit/Widget/HomepageShelfWidgetDataSourceContractTest.php`（新建）
- `app/code/Weline/Theme/test/Unit/ThemeFeaturedProductsCatalogContractTest.php`（断言 homepageFeaturedCards）
- `app/code/Weline/Ai/doc/开发/team/ecommerce-advisor-ops-planner/channel/homepage-wave1-backend-02-done.md`（本回执）

## paths_forbidden_untouched

- 包邮门槛文案（WO-HP-P1-01）
- 评价 / UGC 布局（WO-HP-P1-03）
- 未 `git restore` / `clean` / `stash`

## 契约测

```text
php vendor/bin/phpunit \
  app/code/Weline/Product/Test/Unit/Service/HomepageShelfStaggerContractTest.php \
  app/code/Weline/Theme/test/Unit/Widget/HomepageShelfWidgetDataSourceContractTest.php
→ OK (5 tests, 22 assertions)
```

## 交集实测（nocache HTTP 抽槽）

验收面：`https://p05113ef3.test.weline.com:9555/`  
前置：`ThemeRuntimeCacheCleaner::clearAllThemeRelatedCaches`（模板/FPC 已刷新）。

| 货架 | 前 4（product path 尾段） |
|------|---------------------------|
| Featured | `543`, `542`, `540`, `538` |
| Deals | `hua-chao-ji-li-ren-ge-…`, `hua-chao-ji-luo-shui-yun-…`, `hua-chao-ji-xian-mei-fu-…`, `hua-chao-ji-chun-qi-…` |
| Hot | `237`, `236`, `235`, `234` |

- Featured∩Deals 前 4 = ∅  
- Featured∩Hot 前 4 = ∅  
- Deals∩Hot 前 4 = ∅  
- Featured 前 8 ≠ Hot 前 8（集合亦不等）  
- 三块全量 SKU 交集 = ∅（无同款三刷）  
- Featured 前 8：`543…534`；Hot 前 8：`237…230`；Deals 共 4 款

Browser MCP 本回合无法建标签（`No browser tab available`）；已用禁缓存 HTTP 抽槽等价验收。`cursor-ide-browser` N/A close。

## related_web_urls

- [首页验收](https://p05113ef3.test.weline.com:9555/)

## session_hint

WO-HP-P1-02 后端+部件数据源 closed；可唤醒测试席禁缓存复验 / 顾问运营意图复审。
