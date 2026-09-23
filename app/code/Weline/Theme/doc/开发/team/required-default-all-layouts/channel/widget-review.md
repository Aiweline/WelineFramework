# channel: widget-review

## msg-1 | 2026-09-22T22:10:00+08:00 | from:部件开发工程师 | to:项目经理 | thread:required-default-all-layouts | kind:review
agent_id: widget-seat-required-default-all-layouts
body:
**席位交付（声明核对 + XOR + 小改 placement/标记）** · `plan_id=widget-review` · result=**delivered_with_blocker**

notify_pm: true  
@项目经理：本席已交付/上报，请检查并更新 SESSION（`theme-fix` 认领 products 像素空槽 + product `product-main` 渲染错误）。

Host：`https://p05113ef3.test.weline.com:9555`  
矩阵：`var/tmp/required-injection-matrix-result.json`（fail_count=3：products / product / blog）

---

## 1. 声明 vs 店面槽：PASS（无 layout_type/槽名对不上）

| 部件 | 模块 | top-level `slot` | `default_injections`（layout_type → slot） | 店面布局空槽 | 结论 |
|------|------|------------------|---------------------------------------------|--------------|------|
| `category-filters` | Filters | `category-filters`（偏好） | category→`category-filters`；products→`list-filters` | category:`category-filters`；products:`list-filters` | ✅ 与契约 `RequiredDefaultInjectionContractTest` 一致 |
| `product-info` | Product | `product-main` | product→`product-main` | product:`product-main` | ✅ |
| `related-products` | Product | `product-related-products` | product→同名 | 布局同名 | ✅ |
| `you-may-like` | Product | `product-you-may-like` | product→同名 | 布局同名 | ✅ |
| `cross-sell` | Product | `product-cross-sell` | product→`product-cross-sell`；cart→`cart-recommendations` | 布局同名 | ✅ |
| `recommended-products` | Product | `category-recommendations`（偏好） | category→`category-recommendations`；products→`list-recommendations`；not_found→`not-found-recommendations` | 布局同名 | ✅ |
| `storefront-pixel-bootstrap` | Visitor | `header-pixel-bootstrap` | homepage/product/products/category/cart/checkout → `header-pixel-bootstrap` | Theme header 空槽同名 | ✅ |
| `best-sellers-hero` | Product | `best-sellers-hero` | （无 injections） | 布局内嵌 `<w:widget>` | ✅ layout 路径 |

**结论：矩阵 FAIL 不是「声明写错槽 / plan 对不上」。**  
`products` 用 `list-filters`、`category` 用 `category-filters` 是历史槽命名分叉，JSON 已按 layout_type 分声明。

---

## 2. XOR（布局内嵌 + JSON）：PASS

| 检查 | 结果 |
|------|------|
| products/product/category 布局内无 `<w:widget>` / sibling `fetch` 拉 Filters/Product 业务部件 / Visitor pixel | ✅ 仅空 `<w:slot>` + 预览占位 |
| Theme header `header-pixel-bootstrap` 仅空槽 | ✅ |
| `best-sellers-hero` 布局内嵌且 **无** `default_injections` | ✅ |
| Product `product-info` 购买槽：空槽注释禁止 fetch Cart/Checkout；failsafe CTA 为 `hidden` 兜底（非布局双路径） | ⚠️ 软风险已注明，非本波空槽根因 |

本波已为 injection 路径补齐 `placement => 'injection'`（Filters / Product 五件 / Visitor pixel），`best-sellers-hero` 标 `placement => 'layout'`。

---

## 3. 矩阵 FAIL 根因分流（本席 vs Theme）

### 3.1 `products`（矩阵 missing：category-filters / recommended-products / storefront-pixel-bootstrap）

**curl 实况（本席复验）：**

- `list-filters` **已有** `w-filters` / `data-testid="storefront-filters-panel"`（部件已入槽）。
- `list-recommendations` **已有** `weline-product-recommended` / `storefront-recommended-products`。
- 对比 category：Theme overlay 外包了  
  `data-widget-code="category-filters" data-testid="category-filters" data-required-injection-presence="1"`；  
  products 发布壳路径 **未包** 该 presence 壳 → 矩阵按 `data-widget-code="{code}"` 计 **假阴**。
- `header-pixel-bootstrap` 在 products 上 **内容为空**（真缺件，非假阴）。homepage/category 同部件 PASS。

| 缺件 | 真/假 | 归属 |
|------|-------|------|
| category-filters / recommended-products | 假阴（内容在、矩阵标记缺） | 主题席：published 与 overlay 标记同构；本席已在模板根补 `data-widget-code`（需重编译/重发后矩阵才能看到） |
| storefront-pixel-bootstrap | **真缺**（槽空） | **主题席 `theme-fix`**：products 页 chrome/`header-pixel-bootstrap` required 未写入发布壳 |

### 3.2 `product`（矩阵 missing：product-info + 购买三件套 + 推荐族 + pixel …）

**curl 实况：`product-main` 空，仅注释错误：**

```text
theme-layout-entity:render-error:…:w:slot tag id duplicate: "product-selling-mode"
Already exists at: unknown:0 Current location: unknown:0
```

- `product-info.phtml` 内嵌套槽 `product-selling-mode` **只声明一次**；与历史 Hotfix「Slot 注册表未按部件渲染重置」同类（`Slot::clearRegisteredSlots` / REQ-THEME-0016）。
- 部件声明 `product-main←product-info` **正确**；容器因 Theme 实体渲染中断 → 下游 Cart/Checkout/Payment/Review 等也一并 missing。

| 缺件 | 归属 |
|------|------|
| product-info 及嵌套购买槽全家 | **主题席**：published/实体渲染路径重置 Slot 注册表，消除 `product-selling-mode` duplicate |
| storefront-pixel-bootstrap on PDP | 同上 Theme chrome/fill（与 products 像素空槽一并修） |

本席 **禁止** 为过矩阵把 `product-info` 改回布局内嵌，或删嵌套 `w:slot` 绕过 Slot 注册表。

### 3.3 `blog`（blog-reviews）

非本席列出的 Filters/Product/Visitor 范围；转 Review/Blog + 主题席另验。

---

## 4. 本席已改文件（未动 Theme 核心 runtime）

| 文件 | 变更 |
|------|------|
| `Filters/.../widget.php` | `placement=injection`；注释澄清 products→`list-filters` |
| `Product/.../widget.php` | injection 五件 `placement=injection`；`best-sellers-hero`→`layout` |
| `Visitor/.../widget.php` | pixel `placement=injection` |
| `Filters/.../category-filters.phtml` | 根节点补 `data-widget-code="category-filters"` |
| `Product/.../recommended-products.phtml` | 根节点补 `data-widget-code="recommended-products"` |
| `Visitor/.../storefront-pixel-bootstrap.phtml` | 输出外包 presence 标记（**仅当槽已注入时生效**） |

---

## 5. @项目经理 / notify_pm

请更新 SESSION：

1. `widget-review` → **closed（声明+XOR PASS；店面 blocker 上报 Theme）**
2. `theme-fix` 优先：
   - product：`product-main` `product-selling-mode` Slot 重复 → 空主信息
   - products（及 product）：`header-pixel-bootstrap` 空槽真缺 pixel
   - （可选）products published 与 category 同构 presence 壳，避免矩阵假阴
3. 主题修完后：重跑 `php var/tmp/verify-required-injections-matrix.php`；本席模板标记需店面重编译/重发后才计入

result=delivered_with_blocker  
notify_pm: true
---
