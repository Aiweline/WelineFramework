# channel — 买家秀 P1 现网返工完成（Review/主题返工）

日期：2026-09-23  
席位：`Team:Review/主题返工`  
对照：`buyer-show-p1-ops-acceptance.md`（ops_acceptance **fail**）· `buyer-show-p1-03-done.md` · `buyer-show-p1-04-seed-done.md`  
`notify_pm: true`  
`@电商顾问：P1 现网接线已返工；请禁缓存复审 ops_acceptance`

| 字段 | 值 |
|------|-----|
| **buyer_show_p1_rewire** | **done** |
| WO-BUYER-SHOW-03 现网 | **pass**（首页 `data-looks-source="review"` + `/media/review/`） |
| WO-BUYER-SHOW-04 店面 | **pass**（PDP `data-entity-uuid`=种子 offer；`ReviewService::list` 各 1 条含图） |
| WO-BUYER-SHOW-05 列表星 | **pass**（有评论见真星；首页种子卡 5.00；`/products` 有真实 `wpc-rating`） |

---

## 根因（返工确认）

1. **发布布局空槽**：hanfu 已发布 homepage 固化壳 `homepage-testimonials` 为空；完整固化路径只 strip、不回填磁盘 `<w:widget>` 默认 → 买家秀区块整段消失（顾问当时见过静态 looks，随后发布把槽挖空）。
2. **Worker/FPC/维护抖动**：多次 `server:start -r -f` 后需 `maintenance:disable`；禁缓存抽检须 HTTPS + SNI（`Host:p051…:9555`）。
3. **链接**：聚合层已改为 `product_id`（非 registry `entity_id`）；DI 模板已兜底具体 `BuyerLooksGalleryService`。

---

## 本席动作

| 动作 | 结果 |
|------|------|
| dirty-load 确认 `image-gallery/default.phtml` DI fallback + `BuyerLooksGalleryService::resolveStorefrontProductId` | 在盘 |
| CLI `galleryItems(6)` | 4 条 `/media/review/`，link=`/product/{245\|542\|543}#product-reviews` |
| `ThemeLayoutService::saveWidget` 发布 `image-gallery` `variant=looks` → 槽 `homepage-testimonials`（items=[]，运行时聚合） | OK（旧 catalog URL 被图片契约拒收，空 items 合法） |
| `ThemeLayoutEntityBakeCoordinator::rebakeAfterInjectionCollect(3)` | migrated=530 |
| `cache:flush` + `server:start -r -f` + `maintenance:disable` | Worker 恢复；首页 200 |

未跑 `setup:upgrade`（锁/已 compile）；未启 Ollama；未 `git restore/clean/stash`。

---

## 禁缓存抽检（本机 HTTPS）

交付 Host：`https://p05113ef3.test.weline.com:9555/`  
探活：`curl -sk --resolve p05113ef3.test.weline.com:9555:127.0.0.1 …`

| # | 标准 | 结果 | 证据 |
|---|------|------|------|
| 1 | 首页 `data-looks-source="review"`；图含 `/media/review/`；可点 PDP`#product-reviews` | **pass** | `data-looks-source=['review']`；`/media/review/` ×4；`sb-looks-media-link` → `/product/245\|542\|543#product-reviews` |
| 2 | PDP 543/542/245 评论区可加载已审含图 | **pass** | SSR：`data-entity-uuid` 分别为 `7910baad-…` / `64c6da07-…` / `b46b6aaf-…`（与 04 种子 offer 一致）；`id="product-reviews"`；CLI `ReviewService::list` 各 `total=1` 且 `kind=image` |
| 3 | 列表真星、无评论无假星 | **pass** | 首页种子卡 `/product/543\|542\|245` 旁 `--rating: 5.00`；`/products` 有真实 `wpc-rating`（例 4.8/(4)）；无评论卡不渲染假星 |
| 4 | 未新建平行晒单模块 | **pass** | 仍仅 Theme looks + Review |

### 抽检 URL

- 首页：`https://p05113ef3.test.weline.com:9555/`
- 列表：`https://p05113ef3.test.weline.com:9555/products`
- 分类（含星级卡）：`https://p05113ef3.test.weline.com:9555/category/women/mamian`
- PDP：`https://p05113ef3.test.weline.com:9555/product/543#product-reviews`（及 542、245）

---

## 席位自检

- [x] dirty-load；未 git restore/clean/stash  
- [x] 未启 Ollama  
- [x] Worker 刷新 + 模板/布局固化 + 禁缓存 DOM  
- [x] 证据本文件；请顾问 resume ops_acceptance  

`suggested_seats`: 电商顾问（复审）, 项目经理
