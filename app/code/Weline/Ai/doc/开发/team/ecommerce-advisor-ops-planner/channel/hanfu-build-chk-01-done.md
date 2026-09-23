# channel — WO-BUILD-CHK-01：CART / CHECKOUT 可达修复（前端）

日期：2026-09-23  
角色：`Team:前端:`  
`client_session_id`：`team-frontend-wo-build-chk-01-b988ecec`  
工单：`WO-BUILD-CHK-01`  
权威：`sitewide-ops-acceptance-rereview.md` · `hanfu-build-chk-browser.md` · `hanfu-build-chk-perf-done.md`  
约束：**`$49` 未改** · **未拆 chrome**（Theme Partials header/footer 保留；否决裸 HTML shell）

## 状态

| 字段 | 值 |
|------|-----|
| status | **code_done**（SSR 瘦身已落盘；站级 502 属 worker 重启/封锁，非本席再叠 stampede） |
| CART code | **slim done** · Cart `1.3.81`（`template()` + 空摘要 + 去推荐槽 + 保 chrome） |
| CHECKOUT code | **slim done** · Checkout `1.5.46`（对齐 Cart；去 trust/bottom；禁 SSR `currentCart`） |
| UT | **PASS** · `CheckoutCartSsrSlimContractTest` + `CheckoutPageTitleContractTest`（6 tests / 39 assertions） |
| live_probe | **blocked / 502** — 性能席重启周期中 nginx 502；父 PM 封锁叠 curl/reload；**不得**本席再打站 |
| `$49` | **unchanged** |
| notify_pm | **true** |

**@项目经理：前端 SSR 可达修复已落盘。请等站稳后唤醒 `Team:测试:` 重跑 `WO-BUILD-CHK-BROWSER`（禁缓存 + 抹 webdriver）；勿再叠 reload/stampede。**

---

## 根因（对齐性能席）

1. **主因**：`fetch()` → `fetch_file_after` → `LayoutSlotRenderer` 填 cart 推荐/底部、checkout trust/bottom → `after_ms≈7–30s` → 仅 2 HTTP worker 饥饿 → nginx **502** / curl **000** / `RequestExitException`。
2. **放大器**：SSR 同步 `storefrontSummary` / `currentCart`（含空车双 QueryBin）占满池。
3. **否决**：裸 HTML 绝对壳（拆 chrome）→ 违反 `theme_seat_integrity`；已纠偏为 **保 chrome 的 `template()` 瘦 SSR**。

---

## 改码（本席 dirty-load 核验 + 对齐）

| 面 | 文件 | 动作 |
|----|------|------|
| CART | `Cart/Controller/Index.php` | `template()`/`fetchHtml`；`emptyStorefrontSummary`（禁 SSR QueryBin）；`showHeader/Footer=true` |
| CART | `Cart/.../layouts/cart/default.phtml` | 省略 SSR 推荐/底部产品槽；**保留** header/footer Partials |
| CHK | `Checkout/Controller/Index.php` | 同上；`emptyCheckoutCart`；禁 `currentCart()` |
| CHK | `Checkout/Controller/Frontend/Checkout.php` | 对齐 Index |
| CHK | `Checkout/.../layouts/checkout/default.phtml` | 省略 trust/bottom SSR 槽；**保留** chrome |
| CHK | `CheckoutPageViewModel.php` | 成功 `getCart`（含空车）不再打 legacy `summary` |
| UT | `CheckoutCartSsrSlimContractTest.php` | 断言 `template()` + chrome + 禁 `fetch`/`currentCart`/裸壳 |

模块：Cart **1.3.81** · Checkout **1.5.46**（见各自 `doc/开发日志.md`）。

---

## 探活

| URL | 结果 | 备注 |
|-----|------|------|
| `/zh_Hans_CN/` | 重启周期 **502**（150B nginx） | 非本席功能回归证据 |
| `/zh_Hans_CN/cart` | 同 | 源码已瘦；站稳后测席补禁缓存 Browser |
| `/zh_Hans_CN/checkout` | 同 | 同上 |

本席**停止**继续 curl（对齐 PM 封锁 / 性能席「勿叠 stampede」）。

期望站稳后：`200` + 正文含 `weline-cart-page` / `checkout-main` / `data-testid=checkout-form-page`；空车文案可读；结账壳可达（地址/运费/支付入口由测席+支付席眼检）。

---

## 合规

- [x] `$49` 禁改 — `SEED_FREE_49` / `THRESHOLD_USD=49` 只读未动  
- [x] 禁拆 chrome — layout 仍渲染 Theme Partials header/footer  
- [x] 与性能席并行 — SSR 路径瘦身；worker/cron/重启交性能（`hanfu-build-chk-perf-done.md`）  
- [x] UT 契约过签  
- [x] channel 非空覆写  

## SESSION

- [x] 改码落盘  
- [x] `$49` / chrome 合规  
- [x] UT PASS  
- [x] channel done 覆写  
- [ ] 站稳后测席 Browser → CART/CHECKOUT 运营面 pass（非本席宣称 ops_acceptance）  

**@项目经理：WO-BUILD-CHK-01 前端 code_done。请记账并在站稳后派测试重跑 CHK-BROWSER；顾问终审须等 CART+CHECKOUT 眼检 pass。**

## related_web_urls

- [购物车](https://p05113ef3.test.weline.com:9555/zh_Hans_CN/cart)
- [结账](https://p05113ef3.test.weline.com:9555/zh_Hans_CN/checkout)
- [首页对照](https://p05113ef3.test.weline.com:9555/zh_Hans_CN/)
