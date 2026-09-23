# channel — WO-BUYER-SHOW-03（+05 顺带）完成 · Review+部件

日期：2026-09-23  
席位：`Team:Review+部件`（可协同主题）  
来源：`pm-buyer-show-p1.md` + `buyer-show-ops-review.md` WO-BUYER-SHOW-03  
`notify_pm: true`  
`@电商顾问：P1 03 接线完成后，04 种子有图 approved 时可再 ops 抽检首页 data-looks-source=review`

| 字段 | 值 |
|------|-----|
| **buyer_show_p1_03** | **done** |
| WO-BUYER-SHOW-03 | Review 已审含图 → looks 运行时聚合；禁平行晒单库 |
| WO-BUYER-SHOW-05 | **顺带 done**：列表/分类 `show_rating=true` + `projectFromOffers` 批量回填；无评论不显星 |
| 新建 BuyerShow 模块 | **否** |

---

## 架构选型

1. **展示面 / 生产源边界**  
   - 买家秀 = Theme `image-gallery` `variant=looks`（展示面）  
   - 评论 = `Weline_Review`（生产 + 审核 + 媒体）  
   - 文档：`app/code/Weline/Review/doc/买家秀与评论.md`

2. **聚合服务（Review 归属）**  
   - `BuyerLooksGalleryInterface` → `BuyerLooksGalleryService`（`module.php` provides）  
   - 规则：`status=approved` + 至少一张 `attached` `image`；按当前 `website_id` 过滤；按评论时间倒序；每评论取首图；上限 `min(24, columns)`  
   - 链接：`/product/{entity_id}#product-reviews`  
   - 无图评论自动跳过

3. **部件运行时优先级（Theme）**  
   - `review` 聚合非空 → 用聚合（覆盖静态 items）  
   - 聚合空 → 用配置静态 items（P0 真实图 fallback）  
   - 再空 → 占位 SVG（既有空态）  
   - DOM：`data-looks-source="review|config|placeholder"`；图可点 `a.sb-looks-media-link`

4. **WO-05 列表星级**  
   - 复用既有 `ReviewSeoFactsInterface::aggregatesForExternalUuids`  
   - `ProductCardRenderer::projectFromOffers` 在 `show_rating` 时批量 hydrate  
   - catalog / category / storefront-offer-card 打开 `show_rating`  
   - 卡片模板本就 `rating > 0` 才渲染；fragment cache key 纳入 rating/count（v3）

---

## 改动文件

| 文件 | 变更 |
|------|------|
| `Review/Api/BuyerLooksGalleryInterface.php` | **新** API |
| `Review/Service/BuyerLooksGalleryService.php` | **新** 聚合实现 |
| `Review/etc/module.php` | provides 注册 |
| `Review/doc/买家秀与评论.md` | **新** 边界短文 |
| `Review/doc/README.md` | 入口指针 |
| `Review/Test/Unit/Service/BuyerLooksGalleryContractTest.php` | **新** 契约 UT |
| `Theme/.../image-gallery/default.phtml` | 运行时填充 + 可点图 + data-looks-source |
| `Theme/test/Unit/ImageGalleryBuyerLooksRuntimeContractTest.php` | **新** 契约 UT |
| `Product/Service/ProductCardRenderer.php` | hydrateReviewAggregates |
| `Product/.../catalog/index.phtml` | show_rating true |
| `Product/.../category/index.phtml` | show_rating true |
| `Product/.../storefront-offer-card.phtml` | show-rating true |
| `Product/Test/Unit/Service/ProductCardBatchRenderContractTest.php` | 断言 hydrate + show_rating |
| `Theme/Service/StorefrontProductCardFragmentCache.php` | key 含 rating/count → v3 |

---

## 如何验

1. **契约 UT（本席已跑）**  
   `php vendor/bin/phpunit app/code/Weline/Review/Test/Unit/Service/BuyerLooksGalleryContractTest.php app/code/Weline/Theme/test/Unit/ImageGalleryBuyerLooksRuntimeContractTest.php app/code/Weline/Product/Test/Unit/Service/ProductCardBatchRenderContractTest.php` → **OK (7 tests)**

2. **首页 looks（禁缓存）**  
   - 尚无 approved 含图评论：`data-looks-source="config"`，仍见 P0 静态 6 图（非 placeholder）  
   - WO-04 种子并通过后：`data-looks-source="review"`，`img` 来自 `/media/review/...`，`a.sb-looks-media-link` 含 `#product-reviews`

3. **列表星级**  
   - `/products`：有已通过评论的卡见 `.wpc-rating` + `(N)`；无评论卡无假星

---

## 未竟项

| 项 | 说明 |
|----|------|
| WO-BUYER-SHOW-04 | 种子含图评论（内容/运营席）；无种子则首页仍走 config fallback，本席接线已就绪 |
| 跨 website 回退 | 当前严格按请求 `website_id`；若种子写错站需修数据或再议回退策略 |
| 搜索页独立模板 | 若搜索复用 catalog 批渲染则已覆盖；若另有平行列表需再扫 |
| 顾问 ops_acceptance | 03 产品接线 done；完整「实货图墙」观感仍依赖 04 种子后 resume |

---

## 席位自检

- [x] dirty-load；未 git restore/clean/stash  
- [x] 未新建 BuyerShow；未启动 Ollama  
- [x] 仅 approved + 有图；静态仅聚合空时兜底  
- [x] 证据本文件  
- [x] UT/契约已补并本地绿
