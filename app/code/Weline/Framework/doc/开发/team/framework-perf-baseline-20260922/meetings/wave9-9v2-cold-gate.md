# wave9-9v2 — Theme 2.2.587 + Product 批卡 冷门复测（性能 · msg-115）

- date: 2026-09-22 ~20:40+08
- seat: Team:性能检查工程师:
- channel: `framework-unreasonable-audit.md` **msg-115**（re msg-114 / 114b；对照 msg-107 / `wave9-9v-cold-gate.md`）
- claim_sla: **false** · 禁自 reload · **禁** 8c\* · 禁把种袋 HIT 当主门
- 落地宣称：Theme **2.2.587**（9s3 showHeader 默认 + graft）+ Product 批卡（仓内 Product **1.0.299**）
- PM：已 reload；完整性抽检曾报 `/` chrome 回站；本席复测 **不得** 因首页 PASS 关账

## result

**fail**（完整性双页主门 · `/products` 缺 chrome）

| 门组 | 判定 |
|------|------|
| **完整性**（`/` + `/products` chrome；filters 非空占位；`data-wslot=`=0） | **fail** |
| 固化回归（header / storefront_chrome 布局再生 absent·近零；LayoutSlot≪100） | **fail**（辅于完整性；首页 BP 路径仍见 header 再生） |
| A 轴辅（card≪1289 · head≪1334） | **mixed / 记数**（products card pass；home card phase 超阈） |
| 辅证 total / HIT / rc | 记数：total **fail（辅）** · HIT **pass（辅）** · rc **非处女** |

**首页 PASS 不能关账。** `/products` 出站无 header/footer（仅 `list-*` / `products-bottom`）→ 完整性双页 **fail** → escalate **主题**。

## 采样纪律

| 项 | 值 |
|----|-----|
| Master | **79669** · Started `2026-09-22 12:13:36`（本席未自 reload） |
| Workers | **18300** / **18655**（etime≈15–16m；Worker `:19655`） |
| `setup_upgrade.lock` | **清** |
| 版本 | Theme **2.2.587** · F **2.5.164** · S **2.0.79** · Product **1.0.299** |
| TAG | `wave9v220260922203418` |
| 探针 | panel Cookie（`w_weline_trace_panel`）· identity · Worker `:19655` · Host `p05113ef3.test.weline.com:9555` · FPC **MISS** · 出站 `private, no-store` |
| home 样本 | pid=**18655** · wid=**2** · rc=**2946**（**≫≲80** · **非处女**）· rid=`b3ad7c4f60b5d073-682940324737458` · total **10474.43ms** · DB/WLS **1717.83** / **2233.95** · truncated=true · dropped_span_count=5625 |
| products 样本 | pid=**18300** · wid=**1** · rc=**3313** · rid=`22aecfa8dcdaa222-682950861451166` · total **2833.5ms** · DB/WLS **206.88** / **243.54** · truncated=true · dropped_span_count=1135 |
| Browser | MCP tab/导航失败（记 **N/A**）；完整性以 curl HTML 槽位为准 |

## 完整性门（双页硬）

| 面 | 探针 | chrome 槽 / `weline-header` | `data-wslot=` | filters | 判定 |
|----|------|------------------------------|---------------|---------|------|
| `/` | BP Worker panel | **有** header/footer/delivery/logo/… · `weline-header`=**3** | **0** | n/a | **pass（仅 BP）** |
| `/` | 公网匿名 HTTPS MISS | **无**（仅 homepage-*）· header=**0** | **0** | n/a | **fail（公网）** |
| `/` | 公网 FPC HIT | **无**（仅 homepage-*）· header=**0** | **0** | n/a | **fail（HIT 亦无壳）** |
| `/products` | BP Worker panel | **无**（仅 list-* / products-bottom）· header=**0** | **0** | `w-filters` **有内容**（非空占位） | **fail** |
| `/products` | 公网匿名 MISS | **无** · header=**0** | **0** | 同左 | **fail** |

**结论**：完整性 **未稳住**。`/products` 无论 BP/公网均缺 chrome（与 PM 紧急补充一致）。另：公网匿名 `/` 与 FPC HIT 亦无 chrome——**不得**把「BP 下首页有壳」当成店面完整性关账。

### `/products` 槽位铁证（BP）

`content`, `list-filters`, `list-grid`, `list-pagination`, `list-recommendations`, `list-toolbar`, `products-bottom`  
— **无** `header` / `footer` / `delivery` / `logo` / `navigation`。

## 固化回归门（相对 8v2 / 对照 9v）

样本：BP **`/`**（唯一见 chrome 的路径；products 因丢壳无可比「再生」主路径）

| 项 | 门 | 本窗 | 判定 |
|----|----|------|------|
| 响应 `data-wslot=` | **=0** | **0**（双页） | **pass** |
| LayoutSlot | ≪100ms 或无 span | `LayoutSlotRenderer` **1019.37ms**（home）/ **733.84ms**（products） | **fail** |
| `theme.partials.fetch.header` 布局再生 | absent / 近零 | home **2641.51ms**（仍 ~2.6s 常驻） | **fail** |
| `theme.storefront_chrome` 布局再生 | absent / 近零 | builders/phases **absent**（相对 9v 的 2460 builder **未再出现**） | **pass（记）** |
| `chrome_slot_projection` | absent / 近零 | **absent** | **pass** |
| runtime injectChrome / fill | 无 / skipped | 无 `injectChrome`；home **未见** `skip_fill_solidified`；products 见 `zero_runtime_fill`（辅 · 与丢壳并存） | **fail（辅）** |

**结论**：即便 BP 首页能吐 chrome，**整壳直读仍未稳住**——`partials.fetch.header` 再生回潮量级与 9v（~2460）同级；LayoutSlot 观察者仍 ≫100。`storefront_chrome` builder 本窗 absent **不能**单独宣称固化 pass。

## A 轴辅（相对 8v2）

| 项 | 门（≪ 8v2） | home | products | 判定 |
|----|-------------|------|----------|------|
| `product.card.render` | ≪ **1288.97** | phase **1722.07**（top 片段和≈715） | phase **825.59** | home **fail** · products **pass** |
| `theme.storefront_head` | ≪ **~1334** | 无独立 builder ms；`partials.fetch.head` **540.41** | `storefront.cache.builder` meta=`theme.storefront_head` **737.69** | **pass（记）** / 未再 ~1334 |

## 辅证（记数 · 不关处女 total）

| 项 | 门/锚 | 本窗 | 记数 |
|----|-------|------|------|
| total | ≤2152（msg-70） | home **10474** · products **2833** | **fail（辅）**；劣于 9v home 5934 |
| 公网 HIT | `/`+`/products` | `/` #1/#2 **HIT**（≈40ms/10ms）；`/products` #1/#2 **HIT**（≈9ms/7ms） | **pass（辅）**；**但 HIT HTML 无 chrome** |
| rc 纪律 | ≲80 才可议处女 | **2946** / **3313** | **非处女** · **禁**关处女 total |
| 种袋 L1/L2 | — | head/chrome **未**作主门；**禁**据此排 8c\* | 记数 |

### home top phases（辅）

| ms | phase / resource |
|----|------------------|
| 3123.61 | storefront.cache.builder |
| 2641.51 | theme.partials.fetch.header（**固化回归 fail 源**） |
| 1722.07 | product.card.render |
| 1019.37 | LayoutSlotRenderer（observer top） |
| 821.73 | product.catalog.resolve_filtered |
| 540.41 | theme.partials.fetch.head |

## vs 9v / 8v2

| 项 | 8v2 | 9v | 本窗 9v2 |
|----|-----|-----|----------|
| 完整性双页 | （当时未作本门） | 未强调 | **`/products` fail** · 公网 `/` 亦无壳 |
| header 布局再生 | **absent** | **2460** fail | home **2641** fail |
| storefront_chrome builder | **absent** | **2460** fail | **absent** pass（记） |
| LayoutSlot | 81.66 pass | 0/无 span pass | Renderer **1019** fail |
| card.render | 1288.97 | 450.99 pass | home **1722** / products **825** |
| Theme | 2.2.581 | 2.2.584 | **2.2.587** |

## 明确不宣称

- claim_sla=false；禁暖 HIT 关冷 total；HIT 无 chrome **不得**当完整性 pass
- 禁因种袋 absent 要求重开 8c\*
- rc≫80 **不得**宣称真近处女 total
- 禁本席自 reload / 自排施工
- **首页（BP）chrome PASS 不得关账**

## escalate

**@项目经理：请安排**（禁本席自排）

1. **完整性 fail（主）**：`/products` 无 header/footer 槽（BP+公网一致）；公网匿名 `/` 与 FPC HIT 亦无 chrome。请立刻唤醒 **Team:主题开发工程师:**（9s3 仅修了 BP/某路径首页？列表壳 / 公网壳未吃到 showHeader 默认或 graft）。对照盘上 published shell 与出站投影。
2. **固化回归仍 fail（辅主）**：BP 首页虽有壳，但 `theme.partials.fetch.header` **~2641ms** 再生 + LayoutSlotRenderer **~1019ms** — 相对 8v2 整壳直读仍回退；修完整性时 **禁止** 用 runtime fill/injectChrome 换壳。
3. A 轴记数：products `card` 825≪1289、`storefront_head` builder 737≪1334；home `card` phase 1722 超阈 — 完整性修后再由性能复测。
4. **禁** 8c\*；**禁**本席自 reload。可选 NEED_PM 干净 reload 仅在主题落地后。

`pass=false` · 完整性=`fail` · 固化=`fail` · A轴=`mixed` · `claim_sla=false` · suggested_seats: 项目经理 / **主题** / （可选）架构师 / 性能复测
