# channel — WO-HP-P2-07 施工回执（迷你购物车包邮进度）

日期：2026-09-22  
席位：`Team:前端:` + Cart 后端协同（一席施工）  
工单：`WO-HP-P2-07`  
验收面：`https://p05113ef3.test.weline.com:9555/`  
`notify_pm`: **true**

## 运营定档对齐

| 项 | 结果 |
|----|------|
| 包邮门槛 | **硬锁 `$49` USD**（Wave-1 brief / P1-01） |
| 未达门槛文案 | `还差 %1 包邮` → 英：词典/CSV（含 `$` 差额） |
| 已达门槛文案 | `已享包邮` |
| `¥299` | 迷你车进度面 **禁止**；nocache 首页 HTML 探针无 `¥299` |

## 改动摘要

### Cart（`1.3.74` → `1.3.75`）

1. 新增 `Service/FreeShippingProgressService.php`  
   - `THRESHOLD_USD = 49.0`  
   - 按应付小计（扣 `discount_preview`）算差额 / 进度百分比 / 文案  
2. `CartQueryProvider`：`enrichSummaryWithFreeShippingProgress`  
   - `storefrontSummaryPayload` + `successFromSummary` 注入 `free_shipping_progress`  
3. 中英 CSV + 契约 `FreeShippingProgressServiceContractTest`（5 PASS）

### Theme（`2.2.544` → `2.2.545`）

1. `mini-cart-icon/default.phtml`：footer 进度条 DOM + `data-fs-threshold-usd="49"` + i18n data 属性  
2. `mini-cart-icon.js`：`renderFreeShippingProgress` / `resolveFreeShippingProgress`（API 优先，本地 `$49` 兜底）  
3. `mini-cart-drawer.css`：进度条样式；`weline.modules.js` cache-bust `20260922-minicart-fs-progress`  
4. 契约 `MiniCartShopifyDrawerContractTest` 扩展断言（8 PASS）

### Shipping（`2.9.23` → `2.9.24`）

1. 默认种子改为启用 `SEED_FREE_49`、停用 `SEED_FREE_99`  
2. `alignHomepageWave2Threshold49()` + Upgrade 调用（website/0 存量翻转）  
3. 航线 `ensureFreeRule` 锚定 `SEED_FREE_49`  
4. 本机已执行 align：`aligned_rows=2`

## 验收证据

| 探针 | 结果 |
|------|------|
| UT | Cart 5 + Theme 8 + Shipping 3 = **16 PASS** |
| nocache HTML | 含 `data-fs-threshold-usd="49"`、`data-mini-cart-fs-progress`、`data-i18n-fs-remaining` |
| PHP smoke | `$15` → `remaining=$34.00`；`$49`/`$62` → `qualified=1` |
| Browser | Cursor ide-browser / Chrome DevTools 本回合无法建 tab（工具报无可用 tab / 9222 未开）；以 curl 禁缓存 + 契约/烟测为准 |
| i18n:collect | 本机多席争用导致 `--modules=` 形参失败；Cart/Theme 中英 CSV 已落盘；店面 HTML 已出英进度模板 |

### related_web_urls

- [首页验收（nocache）](https://p05113ef3.test.weline.com:9555/?nocache=1)
- `https://p05113ef3.test.weline.com:9555/?nocache=1`

## 升级项目经理

`notify_pm: true` — `@项目经理：WO-HP-P2-07 前端+Cart 已 closed（迷你车包邮进度硬锁 $49；Shipping SEED_FREE_49 已对齐）。请 DoD 并安排禁缓存 Browser 加购复验（未满差额 / ≥$49 已包邮）与顾问复审。`
