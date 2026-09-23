# Team:主题开发工程师: 店面返工 — required-default-all-layouts

> work_mode：`theme_module_runtime` · area=`frontend`  
> plan_id：`theme-fix`  
> result：**delivered**（P0 + P1 PASS；related/cross-sell / blog 残差另报）  
> notify_pm：**true**  
> @项目经理：本席已交付/上报，请检查并更新 SESSION

---

## 结论

| 优先级 | 项 | 结果 |
|--------|----|------|
| **P0** | PDP `product-main` `product-selling-mode` Slot duplicate → render-error | **PASS** |
| **P1** | products/product `storefront-pixel-bootstrap` presence 壳（与 category 同构） | **PASS** |
| 假阴 | products 筛选/推荐 `data-widget-code` | **PASS**（矩阵 `[PASS] products`） |

**根因（P0）**：`SlotRendererService::doRenderWidget` 已 `Slot::clearRegisteredSlots()`，但实体路径 `ThemeLayoutEntityWidgetRenderer` → `ThemeComponentRenderer`（含 required overlay `renderNode`）**未**按部件渲染重置。同请求二次渲 `product-info` 时嵌套 `w:slot id="product-selling-mode"` 触发 DEV duplicate → 整段主信息变 `theme-layout-entity:render-error` → 购买/推荐连锁 missing。

**根因（P1 / products 假阴）**：overlay / presence 壳在源站 MISS 已能写入；公网曾被 **nginx edge + WLS FPC STALE/HIT** 挡住旧页。清 `var/server/nginx/cache` + `purgeAll` + 源站热身后公网 MISS 与 category 同构。

**非本席本波关闭**：
- `related-products` / `cross-sell`：布局 `showRelatedProducts` 默认 false → HTML **无** `product-related-products` / `product-cross-sell` 槽；overlay 禁 ghost → 矩阵仍缺（残差，需布局常显空槽或默认开开关）。
- `blog` hub `blog-reviews`：测试席已标探针 N/A（详情 PASS）。

---

## 改文件

| 文件 | 变更 |
|------|------|
| `Service/ThemeComponentRenderer.php` | `render()` 开头 `Slot::clearRegisteredSlots()`（对齐 SlotRenderer / REQ-THEME-0016） |
| `Service/LayoutEntity/ThemeLayoutEntityWidgetRenderer.php` | `render()` 开头再清一次（belt-and-suspenders） |
| `test/Unit/View/RecentlyViewedEmptyShellContractTest.php` | 契约：`ThemeComponentRenderer` 须在模板渲前 clear |
| `etc/module.php` | `2.2.595` → `2.2.596` |
| `doc/开发日志.md` | 本轮 |

**未改**：Product `product-info.phtml` 嵌套槽声明；未 git restore/clean/stash；未 login sibling fetch。

---

## UT

```text
RecentlyViewedEmptyShellContractTest — OK (2 tests, 10 assertions)
```

（含 `testThemeComponentRendererClearsSlotRegistryPerWidgetRender`）

---

## curl 证据（公网 Host，edge 清后 MISS）

Host：`https://p05113ef3.test.weline.com:9555`

### P0 — `/product/yue-ya-ni-shang-…-4d375d6d`

- `render-error=N`
- `data-widget-code="product-info"` = **Y**
- `product-main` 片段起于 `<section class="product-native-detail …" data-widget-code="product-info" …>`
- 另：`product-add-to-cart` / `you-may-like` / `product-faq` / `product-reviews` / `storefront-pixel-bootstrap` = OK

### P1 — `/products` 与 PDP pixel

- `header-pixel-bootstrap` 内：  
  `<div data-widget-code="storefront-pixel-bootstrap" data-testid="storefront-pixel-bootstrap" …>`（与 category/homepage 同构 presence 壳）
- products：`category-filters` / `recommended-products` = OK

### 源站对照（`http://127.0.0.1:19655`，绕开 edge）

同结论：product `render-error=N` + `product-info=Y`；products `pixel/filters/rec` 三位均 Y。

运维：`purgeAll` + 清空 `var/server/nginx/cache`；必要时 `server:stop`/`server:start`（OPcache 载入新 ThemeComponentRenderer）。

---

## 矩阵

```bash
php var/tmp/verify-required-injections-matrix.php
```

| layout | 脚本 |
|--------|------|
| homepage / **products** / category / cart / checkout / login / not_found | **PASS** |
| product | FAIL 仅 `related-products,cross-sell`（槽未出站，见上残差） |
| blog | FAIL `blog-reviews`（探针 N/A） |

`fail_count=2`（字面）；**P0/P1 真缺已绿**。

---

## 上报

- plan_id：`theme-fix`
- result：`delivered`（P0+P1；残差 related/cross-sell + blog 探针）
- notify_pm：**true**
- @项目经理：本席已交付/上报，请检查并更新 SESSION；related/cross-sell 是否拉布局常显空槽请裁决。
