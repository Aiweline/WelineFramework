# wave9-9v — Product+Theme A 轴并测（性能 · msg-107）

- date: 2026-09-22 ~20:06+08
- seat: Team:性能检查工程师:
- channel: `framework-unreasonable-audit.md` **msg-107**（re msg-106；对照 msg-100 / `wave8-8v2-cold-gate.md`）
- claim_sla: **false** · 禁自 reload · **禁**自排 8c\* 种袋主波 · **禁**把种袋 HIT 当主门
- 落地宣称：Product **1.0.298**（9p）+ Theme **2.2.584**（9s）
- 本机实测版本：Theme **2.2.584** · F **2.5.164** · S **2.0.79** · Product **1.0.299**（相对 msg-106 宣称 **+0.0.001**，记数）

## result

**fail**（固化回归主门）

| 门组 | 判定 |
|------|------|
| 固化回归（marker / LayoutSlot / header·chrome 布局再生 absent） | **fail** |
| A 轴（card.render / storefront_head builder） | **pass** |
| 辅证 total≤2152 / HIT / rc | 记数：total **fail（辅）** · HIT **pass（辅）** · rc **非处女** |

A 轴探针相对 8v2 **明显降**；但 **`theme.storefront_chrome` + `theme.partials.fetch.header` 布局再生回潮 ~2460ms**，固化整壳直读口径相对 8v2 **回退**。**不得**因种袋 absent 要求重开 8c\*。

## 采样纪律

| 项 | 值 |
|----|-----|
| Master | **37663** · Started `2026-09-22 09:15:56`（对齐既有窗） |
| Workers（采样时） | **19449** / **19472**（etime≈14:26；**本席未自 reload**；采样后本机另有滚动至 47678/47788，**不**改本样本 PID） |
| `setup_upgrade.lock` | **清** · 无并行 upgrade |
| 版本 | Theme **2.2.584** · F **2.5.164** · S **2.0.79** · Product **1.0.299** |
| 样本 | pid=**19472** · worker_id=**2** · `request_count=**2345**`（**≫≲80** · **非真近处女**；数字保留；**不得**用本窗冒充处女 total 关账） |
| 探针 | panel Cookie（BP）· identity · Worker `:19655` · Host `p05113ef3.test.weline.com:9555` · FPC **MISS** · 出站 `private, no-store` |
| request_id | `f147cdd65e57c7ce-681176548357500` |
| TAG | `wave9v20260922200454` |
| HTTP | **200** · 明文 **1,040,866** ≈1.04MB · curl TTFB≈5.94s |
| App total | **5934.37ms** · DB/WLS **1394.54** / **97.52** · truncated=false · dropped_span=4132 |
| shell.phtml | 仓内 **83**（与 8v2 同量级） |
| Browser | MCP 建 tab/导航失败（记 N/A）；公网 HIT 以 curl 为准 |

## 固化回归门（相对 8v2 / 8s5）

| 项 | 门 | 本窗 | 判定 |
|----|----|------|------|
| 响应 `data-wslot=` | **=0** | **0** | **pass** |
| LayoutSlot | ≪100ms 或无 span | phases/top **无** LayoutSlot span（sum=0） | **pass** |
| `theme.partials.fetch.header` 作布局再生 | absent / 近零 | trace_top **2460.30ms** | **fail** |
| `chrome_slot_projection` 作布局再生 | absent / 近零 | builders/top **absent** | **pass** |
| `theme.storefront_chrome` 作布局再生 | absent / 近零 | builder **2460.06ms** · l1/l2 **absent** | **fail** |
| runtime injectChrome / fill 拼布局 | 无 / solidified | HTML 无 `injectChrome`；本窗 **未见** `zero_runtime_fill` / `skip_fill_solidified`（相对 8v2 早退证据缺失） | **fail（辅）** |

**结论**：相对 msg-100，**整壳直读未稳住**——header/`storefront_chrome` 再次成为布局再生主路径。

## A 轴门（相对 8v2）

| 项 | 门（≪ 8v2） | 本窗 | 判定 |
|----|-------------|------|------|
| `product.card.render` | ≪ **1288.97** | **450.99** | **pass** |
| `theme.storefront_head` builder | ≪ **~1334** | **125.22**（l1/l2 absent；meta scope 仍见 `channel`） | **pass** |

## 辅证（记数 · 不关处女 total）

| 项 | 门/锚 | 本窗 | 记数 |
|----|-------|------|------|
| total | ≤2152（msg-70） | **5934.37** | **fail（辅）**；劣于 8v2 的 4265；主因 chrome/header 再生 |
| 公网 HIT | `/`+`/products` | `/` #1/#2 **HIT**（TTFB≈32ms/6ms）；`/products` #1/#2 **HIT**（≈5ms/6ms） | **pass（辅）** |
| rc 纪律 | ≲80 才可议处女 | **2345** | **非处女** · **禁**关处女 total |
| 种袋 L1/L2 | — | head/chrome builders **absent**（预期辅；**禁**据此排 8c\*） | 记数 |

### top phases（辅）

| ms | phase / resource |
|----|------------------|
| 3894.48 | storefront.cache.builder（phases 聚合末写；见 builders） |
| 2460.06 | builder `theme.storefront_chrome`（布局再生 · **回归 fail 源**） |
| 450.99 | product.card.render |
| 406.20 | product.catalog.resolve_filtered |
| 125.57 | theme.partials.fetch.head |
| 125.22 | builder `theme.storefront_head` |

### builders top（辅）

| ms | resource | l1/l2 |
|----|----------|-------|
| 2460.06 | theme.storefront_chrome | absent/absent |
| 330.06 | product.catalog_offers_targeted | absent/absent |
| 270.09 | product.catalog_offers_summary | absent/absent |
| 135.61 | theme.product_card_html | absent/absent |
| 125.22 | theme.storefront_head | absent/absent |

## vs 8v2（msg-100）

| 项 | 8v2 | 本窗 9v |
|----|-----|---------|
| 关账口径 | 固化整壳直读 +（本波）A 轴 | 同 |
| header 布局再生 | **absent** | **2460** fail |
| storefront_chrome | **absent** | **2460** fail |
| LayoutSlot | 81.66 pass | **0**（无 span）pass |
| marker | 0 | **0** |
| card.render | 1288.97 | **450.99** pass |
| storefront_head builder | 1334.18 | **125.22** pass |
| total（辅） | 4264.87 | **5934.37** 更差 |
| Product | 1.0.297 | **1.0.299** |
| Theme | 2.2.581 | **2.2.584** |
| rc | 300 非处女 | **2345** 非处女 |

## 明确不宣称

- claim_sla=false；禁暖 HIT / 公网 HIT 关冷 total 账
- 禁因种袋 absent 要求后端重开 8c\* 主波
- rc=2345 **不得**宣称真近处女 total 关账
- 禁本席自 reload / 自排施工
- A 轴 pass **不得**掩盖固化回归 fail

## escalate

**@项目经理：请安排**（禁本席自排）

1. **固化回归 fail**：相对 8v2，`theme.storefront_chrome` + `partials.fetch.header` 再生 ~2460ms 回潮；请排 **主题**（必要时架构师）查为何 8s5 整壳直读未吃到 / 被旁路；**禁**回 8c\* 种袋代布局。
2. **A 轴 pass**：`product.card.render` 450≪1289、`storefront_head` builder 125≪1334 — 9p/9s 探针方向有效，但被 chrome 再生淹没 total。
3. 辅 total **5934** 劣于 2152 且劣于 8v2；修回归后再由性能复测（可选 NEED_PM 干净 reload 后低 rc 早采）。
4. 版本记数：仓内 Product 现为 **1.0.299**（msg-106 写 1.0.298）。

`pass=false`（固化主门）· A轴=`pass` · `claim_sla=false` · suggested_seats: 项目经理 / 主题 / （可选）架构师 / 性能复测
