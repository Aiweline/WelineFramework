# channel — WO-BUILD-HOME-ZERO done（后端/商品）

日期：2026-09-23  
席位：`Team:后端/商品:`  
工单：`WO-BUILD-HOME-ZERO`  
权威：`sitewide-ops-acceptance-report.md` HOME fail（SSR 价签样本 ≥4 枚 `$0.00`）  
验收面：`https://p05113ef3.test.weline.com:9555/zh_Hans_CN/`（nocache）  
@项目经理

## 结论

**closed · 首页禁 `$0.00`** — 根因在空车 mini-cart 共享 SSR 预格式化零价；货架侧已有非零 Offer 过滤作纵深。修源码 + 失效陈旧 `view/tpl`/`var/cache/template` 后，含 mini-cart 的 SSR 片段 `$0.00` 计数归零。

## 根因

| 层 | 事实 |
|----|------|
| **主因（本工单）** | Header `mini-cart-icon` 共享 SSR 硬编码空车 `subtotal_formatted = formatMoney(0)` → 可见文本/`data-cart-subtotal`/`goods`/`payable` 四处分出 `$0.00`。运营验收字面 `$0.00` 计数 **=4**，全部落在 mini-cart，**不是**货架卡。 |
| **加剧** | `view/tpl/**/mini-cart-icon/com_default.phtml` 与全页 template cache 仍烘焙旧源（`$cartSubtotal`），源文件已有 Visible/Attr 留空时线上仍出零价。 |
| **货架（次因/已防）** | Offer 池可有 `unit_price_minor=0` / `quote_only`；`StorefrontProductWidgetCatalog::isDisplayableShelfOffer/Card` + `HomepageShelfStagger::takeUnique` + 精选部件兜底 + `product-card` 询价文案，BEFORE 抽检货架 `wpc-price-now` **无** `$0.00`。 |

## 改动

| 路径 | 做什么 |
|------|--------|
| `Theme/.../header/mini-cart-icon/default.phtml` | 空车 `subtotal_formatted=''`（禁 `formatMoney(0)`）；`$cartSubtotalVisible`/`$cartSubtotalAttr` 空车留空；**未改** `data-fs-threshold-usd="49"` |
| 运行时 | 删除陈旧 `Theme/view/tpl/**/mini-cart-icon`；清理烘焙 `$0.00` 的 `var/cache/template/*`；请求按需重编（产物见下） |
| （既有纵深，本回合确认仍在） | `Product/Service/StorefrontProductWidgetCatalog.php` 货架非零过滤；`HomepageShelfStagger.php`；`featured-products` 兜底；`product-card.phtml` 禁渲染 `$0.00` |

## 边界（未改）

- **禁改** 包邮门槛 `$49`（`data-fs-threshold-usd="49"` 仍在）
- **禁** `git restore/checkout/clean/stash`
- 未改首页区块顺序 / 主题气质（他席）

## ZERO 计数证据

### BEFORE（修前 nocache SSR 落盘 `/tmp/home-zh.html`）

| 指标 | 值 |
|------|-----|
| HTTP | 200 · ~1.18MB |
| `$0.00` 全文计数 | **4** |
| `data-w-mini-cart` | 1 |
| `data-cart-subtotal="$0.00"` | 1 |
| `data-fs-threshold-usd="49"` | 1 |
| 货架 `wpc-price-now` 含 `0.00` | **[]（无）** |
| 上下文 | 四处均在 mini-cart：`data-cart-subtotal` / `data-cart-subtotal-text` / `data-cart-goods-subtotal` / `data-cart-total-amount` |

### AFTER（修后含 mini-cart 的 SSR 片段 `var/cache/template/00cb359188ec34327d4912b57c0d75dc.phtml`）

| 指标 | 值 |
|------|-----|
| `$0.00` 全文计数 | **0** |
| `data-cart-subtotal=""` | **4** |
| `data-cart-subtotal="$0.00"` | **0** |
| `data-w-mini-cart` | 4（片段内多处镜像） |
| `data-fs-threshold-usd="49"` | 4 |
| 可见小计节点 | `data-cart-subtotal-text` / `goods` / `total-amount` 均为空串 |

重编壳抽检（例）：`Theme/view/tpl/frontend_w0_default_zh_Hans_CN_USD_ctx_42f4d4cc…/mini-cart-icon/com_default.phtml`  
含 `'subtotal_formatted' => ''`、`$cartSubtotalVisible`/`Attr`、`data-fs-threshold-usd="49"`。

### LIVE / 补充

| 样本 | 结果 |
|------|------|
| 修后含 mini-cart 片段（上表） | `$0.00`=**0** · `data-cart-subtotal=""`×4 · fs49 保留 |
| 修后短暂 live 200 `/tmp/home-zero-ok.html` | `$0.00`=**0** · 货架价 `$17.88/$21.61…` 无零（当时 chrome 未齐，仅作货架侧佐证） |
| **父会话直连盖章** `http://127.0.0.1:19655/zh_Hans_CN/?nocache=1790101536` → `/tmp/home-1790101536.html` | **HTTP 200 · ~1.19MB · `$0.00` 计数=0**（标题「长安汉服」）；公网 `:9555` 同窗仍间歇 502，**不以 502 否定本盖章** |
| **父会话公网盖章** `https://p05113ef3.test.weline.com:9555/zh_Hans_CN/` → `/tmp/sealN.html` | **HTTP 200 · ~1.03MB · `$0.00`=0**；直连 `/tmp/sealD.html` 同窗 **200 · bump `20260923-home-zero-minicart` · ZERO=0** |
| 收口窗口 | 他席并行 `server:stop/start` + lifecycle lock 曾致间歇 502；**盖章以 200 正文为准** |

顾问复审请服务稳定后 nocache：

```bash
curl -skL --max-time 120 -H 'Cache-Control: no-cache' \
  "https://p05113ef3.test.weline.com:9555/zh_Hans_CN/?nocache=$(date +%s)" \
  | tee /tmp/home-zero-recheck.html >/dev/null
rg -c '\$0\.00' /tmp/home-zero-recheck.html          # 期望 0
rg -c 'data-w-mini-cart' /tmp/home-zero-recheck.html # 期望 ≥1
rg -c 'data-fs-threshold-usd="49"' /tmp/home-zero-recheck.html
```

## 给 PM / 顾问

- `result=closed`
- `notify_pm: true`
- HOME 价签噪声主因已消（空车零价）；ASSETS/Browser 气质审图仍他席
- 可唤醒顾问复审 HOME 价签面

— 后端/商品 · HOME-ZERO 回报完毕 —
