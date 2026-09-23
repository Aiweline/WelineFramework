# channel — 买家秀 P1 运营验收（电商顾问）

日期：2026-09-23  
角色：`Team:电商顾问:`（运营策划；**禁写码**）  
对照：`buyer-show-p1-rewire-done.md`（PM 返工 done）· 旧 fail 本文件 · `Review/doc/买家秀与评论.md`  
验收面：`https://p05113ef3.test.weline.com:9555/`（禁缓存；禁 `*.weline.test` 作主链）

| 字段 | 值 |
|------|-----|
| **ops_acceptance** | **pass** |
| WO-BUYER-SHOW-03 | **pass**（首页 looks 已切 `review` 聚合） |
| WO-BUYER-SHOW-04 | **pass**（PDP 543/542/245 异步评论非 empty，含文含图） |
| WO-BUYER-SHOW-05 | **pass**（首页/列表可见真 `wpc-rating`/`wpc-stars`；无假 0 星） |
| 平行晒单模块 | **pass**（未新建 BuyerShow） |
| `notify_pm` | **true** |

`@项目经理：P1 运营过签`

---

## 验收矩阵

| # | 标准 | 结果 | 证据 |
|---|------|------|------|
| 1 | 首页 `data-looks-source="review"`；图含 `/media/review/`；可点 `a.sb-looks-media-link` → PDP`#product-reviews` | **pass** | 禁缓存 DOM：`data-looks-source="review"`；标题「买家秀」；≥3 `sb-looks-media-link` → `/product/245|542|543#product-reviews`；4 张 `/media/review/...`（含种子图）。点击首链落在 `#product-reviews` 且评论区存在。截图：`/tmp/buyer-show-p1-ops/01-home-looks-retry.png`、`01c-looks-click-pdp-retry.png`。 |
| 2 | PDP `/product/543`（抽检 542/245）：评论区非 empty；可见评论内容；有图则 `/media/review/` | **pass** | 异步加载后：543「长安买家小满 / 长乐公主试穿很惊艳」+ `/media/review/...59453a...jpg`；542「汉服爱好者阿溪 / 刺绣大袖衫质感在线」+ `...ce2209...jpg`；245「云裳买家清禾 / 明制短袄马面很合身」+ `...7dc086...jpg`；均 `1 条评论`、非 empty。截图：`02-pdp-543-reviews.png`、`02b-pdp-542-reviews.png`、`02c-pdp-245-reviews.png`。 |
| 3 | `/products` 或首页商品卡：有评论见真星；无评论无假星 | **pass** | 首页 `wpc-rating`×6 / `wpc-stars`×6；`/products` 见真星 `aria-label="4.8"`、`--rating: 4.80`、`(4)`；`fakeZero=0`（无 `--rating: 0` 假星）。截图：`03-products-ratings.png`、`03b-home-ratings.png`。 |
| 4 | 未新建平行晒单模块 | **pass** | `app/code/Weline` 下无 `BuyerShow`；边界文档仍指 Review 为生产源。 |

---

## 观感结论

P1 返工后店面已兑现：**评论图驱动买家秀** + **种子 PDP 真评论（含图）** + **列表/首页真星级**。相对旧 fail（静态 catalog 占位、评论壳 empty、列表 0 星），用户可见面已交付完成 → **运营过签**。

---

## 方法说明（证据链）

- Cursor `cursor-ide-browser` 本席不可用（navigate 恒报无 tab）；改用本机 Headless Chrome CDP，同等硬规则：`Network.enable` → `setCacheDisabled(true)` → `Page.addScriptToEvaluateOnNewDocument` 抹 `navigator.webdriver`，再导航。
- 辅证：禁缓存 `curl` SSR（首页 looks / 列表星 / 媒体 200）；店面可见以 CDP 异步渲染结果为准。
- 原始 JSON：`/tmp/buyer-show-p1-ops/results.json`、`home-looks-results.json`。

---

## msg | to:项目经理 | kind:issuer_acceptance

P1 `ops_acceptance=pass`；WO-03/04/05 与平行模块项均 pass。可进入汇审/收口，无需再返工接线。
