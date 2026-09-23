# channel — WO-BUILD-CHK-PERF：cart/checkout 超时/`502` 诊断与药方

日期：2026-09-23  
角色：`Team:性能检查工程师:`  
工单：顾问复审 escalate · `sitewide-ops-acceptance-rereview.md`（CART/CHECKOUT fail）  
对照证据：`hanfu-build-chk-browser.md`  
权威：`dev/ai-command/ai/性能检查.md` · `统一缓存范围与性能优化.md` · `theme_seat_integrity_over_peer_requests`  
约束：**禁拆 chrome**；**`$49` 包邮门槛不改**；药方落在 HotCache/框架路径内；**禁空转 MCP**；**禁再叠 server:reload**（验收封锁）。

## 状态

| 字段 | 值 |
|------|-----|
| status | **done** |
| CART 热路径 | **patched** · Cart **`1.3.81`**（纠偏裸壳） |
| CHECKOUT 热路径 | **patched** · Checkout **`1.5.46`**（纠偏裸壳） |
| `$49` | **unchanged**（`SEED_FREE_49` / `min_order_amount=49.00` 只读） |
| chrome | **preserved**（Theme Partials header/footer；否决裸 HTML） |
| notify_pm | **true** |

**@项目经理：请立刻组队解决**（站稳后唤醒 `Team:测试:` 重跑禁缓存 Browser；勿再叠 reload/stampede）

---

## 业务特性摘要

| 项 | 结论 |
|----|------|
| 读/写 | 店面 **读** SSR 页壳；行项/结账数据走 QueryBin（个性化） |
| 个性化 | 访客/登录车 → **不可**整页共享 FPC 冒充 HIT |
| 热路径 | `/zh_Hans_CN/cart` · `/zh_Hans_CN/checkout` · QueryBin `cart.getCart` / `summary` |
| Owner | Cart / Checkout / Theme `LayoutSlotRenderer` / Server WLS（2 HTTP worker） |

---

## 根因（有证据）

### A. 主因：SSR `fetch()` → LayoutSlotRenderer 实体填充过重

| 证据 | 数值 |
|------|------|
| TemplatePerf cart `after_ms` | **7.3s → 30.3s**（例：18:12 `7364ms`；18:02 `30284ms`） |
| RouterPerf cart `action_execute_ms` | **11.5s**（18:12 `stage=return`） |
| RouterPerf checkout `total_ms` | **23–42s** 后 `RequestExitException`（Fiber cancel @ `worker_runtime_common.php:572`） |
| FPC | cart/checkout **`fpc=disabled`**（正确；非漏缓存） |
| 表象 | nginx **502** / curl **000**；同 Host 首页常因 FPC/更轻路径仍 **200** |

机制：`PcController::fetch` → `fetch_file_after` → `LayoutSlotRenderer` 填 cart 推荐/底部槽、checkout trust/bottom 等实体注入；在仅 **2** HTTP worker 上占满池 → 排队超时。

### B. 放大器：空车双 QueryBin

- `CheckoutPageViewModel::currentCart` 曾在 `getCart` **成功且空**时仍打 legacy `summary`。  
- QueryBin `action_execute_ms` 常见 **10–50s**，与 A 叠加打满 worker。  
- 已修：信任成功的 `getCart`（含空车），失败才 legacy。

### C. 运行时放大器（非业务死循环）

- 本机多路 `cron:task:run`（seo / agent_schedule / websites_ops）+ 并行 `i18n:collect` + 多席 `server:start/stop/reload` **stampede**。  
- 瘦 SSR 后仍见 `action_execute_ms≈0.03` 但 `fpc_probe_ms` 达 **5–10s** → **排队/探针**，非路由死循环。  
- **禁止**把拆 header/footer/必装 widget 当优化方向。

### D. 否决的伪药方

并行席曾落 **裸 HTML 绝对壳**（无 Theme Partials）→ 违反 **禁拆 chrome** / `theme_seat_integrity`。本席已 **纠偏驳回**。

---

## 药方（框架内 · 已落盘）

| 面 | 路径 | 机制 |
|----|------|------|
| Cart Index | `app/code/Weline/Cart/Controller/Index.php` | `template()`/`fetchHtml` + layout；`emptyStorefrontSummary`（禁 SSR QueryBin）；`showHeader/Footer=true` |
| Cart layout | `…/layouts/cart/default.phtml` | 保留 Partials chrome；省略 SSR 推荐/底部产品槽 |
| Checkout Index | `app/code/Weline/Checkout/Controller/Index.php` | 同上模式；`emptyCheckoutCart` |
| Checkout Frontend | `…/Controller/Frontend/Checkout.php` | 对齐 |
| Checkout layout | `…/layouts/checkout/default.phtml` | 保留 Partials；省略 trust/bottom SSR 槽 |
| ViewModel | `CheckoutPageViewModel.php` | 成功 `getCart` 不再双打 `summary` |
| 契约 | `CheckoutCartSsrSlimContractTest.php` | 断言 **带 chrome 瘦 SSR**；禁裸 DOCTYPE / `*-p0-shell` |
| `$49` | Shipping seed | **未改** |

版本：Cart **`1.3.80→1.3.81`**；Checkout **`1.5.45→1.5.46`**。

### 缓存合规

| 项 | 判定 |
|----|------|
| 平行业务 static 袋 | **否** |
| 可变车 HTML 进共享池 | **否** |
| 新开 HotCache 误挂个性化 | **N/A**（本波未新开共享缓存） |
| 拆 chrome / 必装 | **否**（纠偏后） |
| DB N+1 → WLS RPC N+1 | **改善**（去掉空车第二次 QueryBin） |

---

## 本回合探活

| 样本 | 结果 |
|------|------|
| 修前 RouterPerf/TemplatePerf | 见上 A（11–35s） |
| 瘦壳后 cart `action_execute_ms` | **≈0.03ms**（18:18–18:19 `stage=return`） |
| 同窗 `fpc_probe_ms` | 仍可达 **5–10s**（stampede 排队） |
| 字面 Host 当前 | 多席 reload/cron 风暴下首页亦曾 `000`/`502` → **运行时未稳，不宣称验收 pass** |
| 契约 UT | `CheckoutCartSsrSlimContractTest`（本席改写后须绿） |

源码门禁已修；**HTTP 200 运营眼检**须站稳后由 `Team:测试:` 重跑 `WO-BUILD-CHK-BROWSER`（禁缓存 + 抹 webdriver）。本席 **不叠 reload**。

---

## 给 PM 的立刻动作

1. **验收封锁维持**：禁并行 `server:reload` / 多路 `i18n:collect` 打站，直至 worker 池空闲。  
2. 站稳后 **单路** 唤醒 `Team:测试:` 重跑 cart/checkout Browser。  
3. 通知前端：裸 HTML P0 壳已被性能席 **否决并回滚**；后续优化必须保留 Partials chrome。  
4. 可选：`Team:主题:` 若回填 trust/bottom/推荐槽，须冷路径 Policy / 预烘焙，禁止热路径重实体填充。  
5. `architect_joint`：建议短会钉死「cart/checkout = template 瘦 SSR + chrome Partials + QueryBin 客户端水合」。

**@项目经理：请立刻组队解决** · `notify_pm: true`  
**@项目经理：请检查并更新 SESSION**

## related_web_urls

- [购物车](https://p05113ef3.test.weline.com:9555/zh_Hans_CN/cart)
- [结账](https://p05113ef3.test.weline.com:9555/zh_Hans_CN/checkout)
- [首页对照](https://p05113ef3.test.weline.com:9555/zh_Hans_CN/)

## SESSION

- [x] 根因：LayoutSlotRenderer after_ms + 双 QueryBin + worker 饥饿（非路由死循环）  
- [x] 框架内改码（Cart `1.3.81` / Checkout `1.5.46`）+ 纠偏裸壳  
- [x] 禁拆 chrome · `$49` 未改  
- [x] 契约改写为 chrome-preserving  
- [x] channel 落盘 · escalate `@项目经理`  
- [x] 未叠 reload（服从验收封锁）  
