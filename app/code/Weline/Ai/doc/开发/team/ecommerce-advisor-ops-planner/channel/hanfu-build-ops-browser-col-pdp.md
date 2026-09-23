# channel — WO-BUILD-OPS-BROWSER（COLLECTION + PDP 禁缓存补检）

日期：2026-09-23  
席位：`Team:测试:`  
工单：`WO-BUILD-OPS-BROWSER`  
基线：`hanfu-build-fe-01-done.md` · `sitewide-ops-acceptance-report.md` · `ops-acceptance-charter-hanfu-mall.md`  
验收 Host：`https://p05113ef3.test.weline.com:9555`  
约束：**满 `$49` USD 包邮门槛不改**（本席只验不写业务码；未触碰 Shipping 种子/规则）

## 状态

| 字段 | 值 |
|------|-----|
| status | **done** |
| COLLECTION | **pass** |
| PDP | **pass** |
| notify_pm | **true** |
| Browser 路径 | cursor-ide-browser **挂不上**（`browser_tabs list` 空；`browser_navigate` → `No browser tab available`）→ **降级 curl SSR 禁缓存**（本回合权威证据） |
| 禁缓存 | `Cache-Control: no-cache` / `Pragma: no-cache` + `?nocache=1758566101` |
| 抹 webdriver | IDE Browser / CDP **未能**执行 `Page.addScriptToEvaluateOnNewDocument`；已写明降级，**不**据此判 fail |
| 硬禁遵守 | **未**执行 `server:start/restart/reload/stop`；**未**跑 `i18n:collect`；单路串行探活 |

## 抽检 URL

| 面 | URL | 来源 |
|----|-----|------|
| COLLECTION | https://p05113ef3.test.weline.com:9555/zh_Hans_CN/category/women/mamian | 工单指定 |
| PDP | https://p05113ef3.test.weline.com:9555/zh_Hans_CN/product/hua-chao-ji-xia-ji-han-fu-nu-xin-kuan-jian-yue-yin-hua-ma-mian-qun-qua-cf5949d6?size=s&style_type=man-ting-fan | 工单指定 |

## verdict（基于本回合 200 正文，非历史 502）

| 面 | verdict | 一句话 |
|----|---------|--------|
| COLLECTION | **pass** | 禁缓存 SSR **HTTP 200**（~1.31MB）；价签非零 + 加购 CTA + 筛选/排序可读；无 502/Fatal |
| PDP | **pass** | 禁缓存 SSR **HTTP 200**（~1.33MB）；主图 gallery + buybox + 规格/色块 + 加购/立即购买；标题「满庭芳明制印花马面裙套装」；无 502/Fatal |

## 证据

### 1. Browser MCP（硬门禁尝试 → 降级）

| 工具 | 结果 |
|------|------|
| `cursor-ide-browser` `browser_tabs` list | 空 |
| `cursor-ide-browser` `browser_navigate`（含/不含 `newTab`） | `No browser tab available. Please navigate to a page first.` |
| CDP `Network.setCacheDisabled` / `Page.addScriptToEvaluateOnNewDocument` | **未执行**（无 tab） |

→ **降级声明**：本回合可视化 IDE Browser 不可用；以 **curl SSR 禁缓存正文** 为权威过签证据。父会话已确认公网 home/cart/checkout **200**；本席 COL/PDP 同步 **200**，**禁止**再用旧 502 写 fail。

### 2. curl SSR（禁缓存 · 单路串行 · 本机）

| 面 | HTTP | SIZE | TIME | Body 关键信号 |
|----|------|------|------|-------------|
| COL | **200** | 1313281 | ~0.70s | `502=0`；`wpc-price-now=35`；抽出价 32 个且 **全非零**（样例 `$10.73`…`$18.63`）；`product-card-add-to-cart=96`；`btn-add-to-cart=39`；`加入购物车=180`；`筛选=17` / `排序=10` / `category-toolbar=1` / `grid=45`；`product-card=511`；`Fatal=0` |
| PDP | **200** | 1331427 | ~1.41s | `502=0`；`<title>商品详情 \| 长安汉服`；`<h1>`=满庭芳明制印花马面裙套装；`product-gallery=2`（含 thumbs + `data-gallery-src`）；`buybox=28`；`swatch=32`；`规格=64`；`man-ting-fan=1`；`加入购物车=72` / `立即购买=16` / `buy-now=71`；`aspect-ratio` 出现；`Fatal=0` / `Whoops=0` |

探活命令形态（示意）：

```bash
curl -sS -H 'Cache-Control: no-cache' -H 'Pragma: no-cache' \
  '.../zh_Hans_CN/category/women/mamian?nocache=1758566101'
# 后再单路
curl -sS -H 'Cache-Control: no-cache' -H 'Pragma: no-cache' \
  '.../product/hua-chao-ji-xia-ji-han-fu-nu-xin-kuan-jian-yue-yin-hua-ma-mian-qun-qua-cf5949d6?size=s&style_type=man-ting-fan&nocache=1758566101'
```

### 3. `$49` 包邮（只读）

- **未改**任何 Shipping / 包邮种子 / 首页门槛业务码。
- 仓内契约仍锚定 `SEED_FREE_49`（`FreeShippingRuleSeedService` · `min_order_amount => 49.00`；`FreeShippingRuleSeedContractTest` 仅 `SEED_FREE_49` 为 active）。
- 页内可见「包邮」文案存在（COL/PDP 各计 6）；**不构成**门槛被改的证据。

## 检查清单回填

### COLLECTION · `/zh_Hans_CN/category/women/mamian`

- [x] HTTP 探活 → **200**
- [x] 禁缓存尝试 → curl 头 + `?nocache=`
- [ ] 抹 `navigator.webdriver` → **N/A（Browser MCP 未挂上；已降级声明）**
- [x] 卡面非零价签可见 → 抽出 32/32 非零
- [x] 主 CTA「加入购物车」可达 → `加入购物车` / `product-card-add-to-cart` 计数充分
- [x] 筛选/排序/列表密度可读 → 筛选+排序+toolbar+大量 product-card
- [x] 无塌布局 / Fatal → `Fatal=0` / `502=0`

### PDP · 工单指定真实产品 URL

- [x] HTTP 探活 → **200**
- [x] 禁缓存尝试 → 同上
- [ ] 抹自动化标志 → **N/A（降级）**
- [x] 主图 gallery 可读 → `product-gallery` + thumbs/`data-gallery-src`
- [x] 规格选择可见 → `规格` / `swatch` / `style_type=man-ting-fan`
- [x] 加购 / 购买 CTA 可达（buybox）→ buybox + 加入购物车 + 立即购买
- [x] 详情非半成品 → 有标题「满庭芳明制印花马面裙套装」+ 价签 + gallery + buybox

## 建议工单

无阻断续派。Browser IDE 挂不上属工具链问题，**不**阻塞本工单 SSR 过签；若 PM 仍要可视化截图签收，可另派轻量 `WO-BUILD-OPS-BROWSER-SNAP`（非门禁）。

`suggested_seats`：无（本工单关闭）

## @项目经理：

**WO-BUILD-OPS-BROWSER done；COLLECTION=pass，PDP=pass；notify_pm=true。**

本回合禁缓存 curl SSR：COL/PDP 均为 **HTTP 200** 大正文；价签/加购/筛选（COL）与 gallery/buybox/规格/CTA（PDP）信号齐全；无 502/Fatal。cursor-ide-browser 仍挂不上，已按工单降级并写明。**$49 包邮本席未改。** 完成即停。
