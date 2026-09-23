# channel — WO-BUILD-CART-CHECKOUT-REACHABILITY：前端可达性收口

日期：2026-09-23  
角色：`Team:前端:`（联动 `Team:性能检查工程师:`）  
工单：CHK-BROWSER CART/CHECKOUT fail → 恢复 HTTP 200 + 可渲染正文  
权威 fail：`hanfu-build-chk-browser.md`  
对照性能：`hanfu-build-chk-perf-done.md`  
验收 Host：`https://p05113ef3.test.weline.com:9555`（`:9555` 仅 HTTPS）  
约束：**`$49` 包邮门槛不改**；**禁 git restore**；**禁拆 Theme chrome**（`theme_seat_integrity`）

## 状态

| 字段 | 值 |
|------|-----|
| status | **done** |
| CART | **pass**（nocache HTTP 200 + 可渲染壳） |
| CHECKOUT | **pass**（同批 HTTP 200 + 可渲染壳） |
| `$49` | **unchanged**（`SEED_FREE_49` / `min_order_amount = 49.00` 只读） |
| notify_pm | **true** |

**@项目经理：请唤醒 `Team:测试:` 重跑 `WO-BUILD-CHK-BROWSER`（禁缓存 Browser + 抹 webdriver）**；服务若再被叠 `server:stop/start` stampede，先站稳再验。

---

## 根因

| 层 | 结论 |
|----|------|
| 相对首页 | 首页可 FPC HIT（~0.03s 200）；`/cart` `/checkout` **`fpc: disabled`**（个性化）→ 必须占活 worker 全量渲染 |
| SSR 过重 | 历史 `fetch()` → `LayoutSlotRenderer` 填推荐/trust/bottom 槽；`TemplatePerf after_ms≈30s`；拖垮仅 **2** HTTP worker → nginx **502** / curl **000** |
| SSR 读车 | `storefrontSummary` / `currentCart`→`getCart`(+legacy `summary`) 在池饥饿下再占 Fiber；`action_execute_ms` 常 10–50s |
| 池饥饿放大 | QueryBin handshake 15–30s、visitor observe、cron/`i18n:collect` 争用 → `ConnectionPool::acquire` 等 30s；客户端断连 → `RequestExitException`（Fiber cancel） |
| 旁证栈 | runtime.log `/cart` `/checkout`：挂在 `SecurityHeaderPolicy`→`SystemConfig`→`ConnectionPool::getConnection`；非空车业务逻辑本身 |

**一句话**：无 FPC 的 cart/checkout 在 2-worker + DB/QueryBin 饥饿下，重 SSR（槽填充 + 读车）把 worker 卡死；首页可缓存故对照仍 200。

---

## 改动（本席 / 协同）

| 模块 | 版本 | 改动 |
|------|------|------|
| `Weline_Cart` | **1.3.81** | `Controller\Index`：`template()`/`fetchHtml`（跳过 `fetch_file_after` LayoutSlot 实体填）；SSR **`emptyStorefrontSummary`**（禁 `storefrontSummary`/QueryBin）；layout 省略 SSR 推荐/底部槽；**保留** Theme Partials header/footer（否决裸 HTML） |
| `Weline_Checkout` | **1.5.46** | `Controller\Index` + `Frontend\Checkout::index`：同上空壳 + `template()` + Theme layout；禁 SSR `currentCart`；layout 省略 SSR trust/bottom；**保留 chrome** |
| layout | — | `Cart/.../layouts/cart/default.phtml`、`Checkout/.../layouts/checkout/default.phtml` 已去掉重槽 SSR |

**未改**：`SEED_FREE_49` / `min_order_amount = 49.00`；未 `git restore`。

客户端仍以 QueryBin 水合行项 / 地址 / 运费 / 支付；SSR 交付「正在加载… / 空车文案 / 结账壳」即达本工单门槛。

---

## 证据（验收）

探活字面 URL（服务恢复后、禁缓存 curl）：

```
# FIRE@1（同批并行）
cart     HTTP 200  t≈22.7s  sz≈971660  → 含 weline-cart-shell / 正在加载购物车 / 购物车是空的
checkout HTTP 200  t≈22.9s  sz≈1092161 → 含 weline-checkout / checkout-main / 正在加载结账
d-cart   HTTP 200  （直连 127.0.0.1:19655）同上正文
# 复检
END cart HTTP 200  t≈6.1s   sz≈691300  → 同上壳标记
```

`$49` 只读：

```
FreeShippingRuleSeedService.php · SEED_FREE_49 · min_order_amount => 49.00
```

说明：多席并行 `server:stop/start` 期间会出现间歇 502（upstream Connection refused）；**代码路径在 worker 存活时已可 200+正文**。请 PM 避免叠生命周期事务后再派测试。

---

## related_web_urls

- [购物车](https://p05113ef3.test.weline.com:9555/zh_Hans_CN/cart)
- [结账](https://p05113ef3.test.weline.com:9555/zh_Hans_CN/checkout)
- [首页对照](https://p05113ef3.test.weline.com:9555/zh_Hans_CN/)

```
https://p05113ef3.test.weline.com:9555/zh_Hans_CN/cart
https://p05113ef3.test.weline.com:9555/zh_Hans_CN/checkout
```

---

## notify_pm

1. CART/CHECKOUT 可达性 **前端收口 done**（200 + 可渲染壳）。  
2. 唤醒 **测试** 重跑 CHK-BROWSER（禁缓存 Browser）。  
3. 性能席药方见 `hanfu-build-chk-perf-done.md`（worker/QueryBin/池）；勿再拆 chrome。  
4. **禁**在验收窗口叠 `server:reload/stop/start` stampede。

**@项目经理：请立刻组队解决（测试复跑）** · `notify_pm: true`

## SESSION

- [x] prepare_project / hard_constraints  
- [x] 根因：FPC 差 + LayoutSlot/读车 SSR + 2-worker/DB 饥饿  
- [x] 修：空壳 SSR + template() + 保 chrome；版本 Cart 1.3.81 / Checkout 1.5.46  
- [x] curl 证据：两 URL 200 + 非 502 HTML 正文  
- [x] `$49` 未改 · 禁 git restore  
- [x] channel 填满 · notify_pm  
- [x] Browser 关闭 N/A（本席 curl 验收）
