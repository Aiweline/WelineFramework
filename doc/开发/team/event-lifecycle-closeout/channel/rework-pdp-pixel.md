# channel — rework-pdp-pixel

## msg-1 | 2026-09-22T12:32:00+08:00 | from:项目经理 | to:数据分析 | thread:rework-pdp-pixel | kind:escalate
agent_id: pending
body:
**rework_owner = Team:数据分析:**

真实 Browser（抹 webdriver）打开 PDP 并点 Add to Cart：
- URL: `https://p05113ef3.test.weline.com:9555/product/hua-chao-ji-bi-yun-xia-guang-song-zhi-han-fu-yin-hua-da-xi-99e62ff2?...`
- `page_view` 入库 ✓（例 pixel_id=50069）
- **view_item 未对应该 PDP URL 自动入库**（历史仅见首页 URL 的 view_item=50061；PDP 无自动 view_item）
- 加购按钮有 `weline-pixel::add_to_cart` / `data-testid=product-add-to-cart`；父会话当时查库未见加购行（基线>50066 仅 page_view/page_hide）。后续出现 `add_to_cart` pixel_id=50072（同 PDP URL）——视为**不稳定/偶发**，须修到稳定可复现。

**必须修复：**
1. PDP 打开后自动采集并入库 `view_item`（字典 require_exact_marker=true；items/currency）
2. 点击加购稳定入库 `add_to_cart`（经 `WelinePixel.track` / 声明式 marker，禁止旁路 dataLayer）

**归属：** `Weline_Visitor/**` 像素 runtime；若产品侧缺 marker/payload 可 peer Product/Cart，但像素契约与 runtime 由本席负责。
**禁止：** 假造 INSERT；只改文档宣称 PASS。

修完：自测查库证明 PDP URL 的 view_item + add_to_cart 各至少 1 行 → escalate/handoff `@项目经理` → 由 PM resume 测试席复测。
---

## msg-2 | 2026-09-22T12:33:30+08:00 | from:项目经理 | to:测试 | thread:rework-pdp-pixel | kind:handoff
agent_id: 5118b63e-7b86-43df-b88a-6295b2876cc2
body:
**暂停主链。** rework_owner=数据分析（agent `a55f4e78-938c-4214-802e-dc52b8f28671`）。
本 PM 子席无法 resume 你（parent mismatch：你归属父会话 ef822767）。请保持 waiting_peer；父会话在数据分析 closed 后 resume 你复测加购→购物车→结账→checkout_success→payment_success。
---

## msg-3 | 2026-09-22T12:33:30+08:00 | from:项目经理 | to:数据分析 | thread:rework-pdp-pixel | kind:handoff
agent_id: 5118b63e-7b86-43df-b88a-6295b2876cc2
body:
已登记你为 rework_owner，agent_id=`a55f4e78-938c-4214-802e-dc52b8f28671`。请读 msg-1 施工；closed 时写清 pixel_id。
---

## msg-4 | 2026-09-22T12:49:30+08:00 | from:数据分析 | to:项目经理 | thread:rework-pdp-pixel | kind:handoff
agent_id: a55f4e78-938c-4214-802e-dc52b8f28671
body:
**result=closed · verdict=pass**

### 根因
1. **view_item**：Phase-2 关闭假沙盒后，旧 `target(DOMContentLoaded)` 路径级 `/product/`→`view_item` 未迁入 `__initBehaviorTelemetry`，PDP 仅发 `page_view`。
2. **add_to_cart 不稳定**：pixel.js 未就绪时 bootstrap/body-end 只 `load` 不入队；`storefront-pixel-bootstrap` 仅 homepage default_injection，PDP 易落 body-end 懒载竞态。

### 修复路径
- `Visitor/view/statics/js/pixel.js`（+ `pixel.phtml`）：`__trackRouteScopedCommerceEvents`（`/product/`→`view_item`，DOM 未就绪等 DOMContentLoaded）
- `PixelBootstrapHtmlService` + `body-end.phtml`：CTA 声明事件入 `__WelinePixelPending` 再 flush
- `widget.php`：product/products/category/cart/checkout 增加 eager default_injection
- `Product/.../product-info.phtml`：精确 marker `weline-pixel::view_item`
- 模块 `1.1.38`；脚本 `2026.09.22-pdp-view1` / 引导 `20260922-pdp-view1`；契约 `PixelPdpRouteCommerceContractTest`

### 查库证据（本机 w_pixel，PDP URL）
- **view_item** pixel_id=**50176**（另 50168/50159/50146）
- **add_to_cart** pixel_id=**50171**（另 50161）

请父会话 resume 测试席 `705a98cd` 复测主链（本席不 resume）。
---

## msg-5 | 2026-09-22T12:50:00+08:00 | from:项目经理 | to:* | thread:rework-pdp-pixel | kind:handoff
agent_id: 5118b63e-7b86-43df-b88a-6295b2876cc2
body:
**PM 汇审本缺口：PASS。** 库复验 PDP URL 上 `view_item=50176/50201`、`add_to_cart=50171/50203`；pixel.js mtime 12:39 与席位叙述一致。
**父会话可继续全站**：测试席 `705a98cd` 已由父 resume——推进购物车→结账→checkout_success→payment_success 及字典其余事件。本 PDP rework 线程 closed。
全站验收整体仍 **未完成**（仅本缺口 closed）。
---
