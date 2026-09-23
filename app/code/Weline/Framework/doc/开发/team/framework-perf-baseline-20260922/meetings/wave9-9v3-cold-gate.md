# wave9-9v3 — Theme 2.2.588 冷门复测（性能 · msg-119）

- date: 2026-09-22 ~20:50+08
- seat: Team:性能检查工程师:
- channel: `framework-unreasonable-audit.md` **msg-119**（re msg-118；对照 msg-115 / `wave9-9v2-cold-gate.md`）
- claim_sla: **false** · 禁自 reload · **禁** 8c\* · 禁把种袋 HIT 当主门
- 落地宣称：Theme **2.2.588**（9s4 chrome-all）
- PM：已 reload；匿名 FPC MISS 抽检曾报双页 chrome PASS；本席复测以双页完整性为主门

## result

**fail**（固化回归 · LayoutSlot≪100 未过）

| 门组 | 判定 |
|------|------|
| **完整性**（匿名无 panel · `/`+`/products` chrome；filters 非空；`data-wslot=`=0） | **pass（稳住）** |
| 固化回归（header / storefront_chrome 布局再生 absent·近零；LayoutSlot≪100；marker=0） | **fail**（LayoutSlot）；**header 已非 ~2.6s** |
| A 轴辅（card≪1289 · head≪1334） | **pass** |
| 辅证 total / HIT / rc | 记数：total **fail（辅）** · HIT **pass（辅）** · rc **非处女** |

**完整性已稳住。** 相对 9v2：双页均有 header/footer/delivery/logo/navigation；公网 HIT `/products` 在 MISS 出站后再 HIT 亦含 chrome。

**header 区分**：本窗 `theme.partials.fetch.header` **0.12–0.16ms**（有壳 · Partials 近零）——**不是**「丢壳」也**不是**「有壳仍 ~2.6s 再生」。固化 fail 源 = LayoutSlotRenderer **~1.6s**。

## 采样纪律

| 项 | 值 |
|----|-----|
| Master | **79669** · Started `2026-09-22 20:13:36`（本席未自 reload） |
| Workers | **52529** / **52912**（etime≈4m；Worker `:19655`；PM reload 后新 Worker） |
| `setup_upgrade.lock` | **清** |
| 版本 | Theme **2.2.588** · F **2.5.164** · S **2.0.79** · Product **1.0.299** |
| TAG | `wave9v320260922205024` |
| 探针 | **匿名无 panel**（HTTPS + Worker）· **可选 BP** panel Cookie（`w_weline_trace_panel`）· identity · Worker `:19655` · Host `p05113ef3.test.weline.com:9555` · FPC **MISS**/wait_miss/build_lock · 出站 `private, no-store`（MISS） |
| home BP 样本 | pid=**52912** · wid=**2** · rc=**970**（**≫≲80** · **非处女**）· rid=`ab8b4b7a75e2ba09-683906258314250` · total **5118.7ms** · DB/WLS **846.91** / **1419.43** · truncated=false · dropped=0 |
| products BP 样本 | pid=**52912** · wid=**2** · rc=**978** · rid=`80c641c53736ec92-683911629324916` · total **3011.23ms** · DB/WLS **381.27** / **630.24** · truncated=false · dropped=0 |

## 完整性门（双页硬 · 匿名为主）

| 面 | 探针 | chrome 槽 / `weline-header` | `data-wslot=` | filters | 判定 |
|----|------|------------------------------|---------------|---------|------|
| `/` | 公网匿名 HTTPS | **有** header/footer/delivery/logo/navigation · `weline-header`=**3** · HIT | **0** | n/a | **pass** |
| `/` | Worker 匿名 | **有**（同上）· HIT | **0** | n/a | **pass** |
| `/` | BP panel Worker | **有**（`data-slot-id` 含 header/footer/delivery/logo/navigation + homepage-*） | **0** | n/a | **pass** |
| `/products` | 公网匿名 HTTPS（初 HIT 无壳；Worker MISS 后再 HIT） | **有** chrome（再 HIT）· `weline-header`=**3** | **0** | `w-filters` **有内容** | **pass**（注：旧袋 HIT 曾无壳，MISS 出站后更新） |
| `/products` | Worker 匿名 MISS | **有** · `weline-header`=**3** · `private, no-store` | **0** | 非空占位 | **pass** |
| `/products` | BP panel Worker | **有**（含 list-* + chrome） | **0** | 非空 | **pass** |

**结论**：完整性 **已稳住**（相对 9v2 `/products` 丢壳）。首包公网 HIT 可能仍短暂服务 9s4 前旧袋——以 **MISS / 更新后 HIT** 为准，不得把旧 HIT 无壳当成 9s4 回退。

## 固化回归门（相对 8v2 / 对照 9v2）

样本：BP **`/`** + **`/products`**（双页均有壳，可比再生）

| 项 | 门 | home | products | 判定 |
|----|-----|------|----------|------|
| 响应 `data-wslot=` / marker | **=0** | **0** | **0** | **pass** |
| LayoutSlot | ≪100ms 或无 span | LayoutSlotRenderer **1622.49ms** | **1568.97ms** | **fail** |
| `theme.partials.fetch.header` 布局再生 | absent / 近零（勿再 ~2.6s） | **0.16ms** | **0.12ms** | **pass**（有壳 · Partials 近零） |
| `theme.storefront_chrome` 布局再生 | absent / 近零 | **absent** | **absent** | **pass** |
| `chrome_slot_projection` | absent / 近零 | **absent** | **absent** | **pass** |
| runtime injectChrome / fill | 无 / skipped | 无 `injectChrome`；见 `zero_runtime_fill`（0ms · safety_net） | 同左 | **pass（辅）** |

**结论**：相对 9v2（header **~2641** 再生）：本窗 header **已收口到近零**，且双页 **有壳**——属「有壳的 Partials 近零」，**不是**丢壳。固化主剩余：**LayoutSlotRenderer ~1.6s ≫100**。

## A 轴辅（相对 8v2）

| 项 | 门（≪ 8v2） | home | products | 判定 |
|----|-------------|------|----------|------|
| `product.card.render` | ≪ **1288.97** | **32.0**（calls=40） | **60.39**（calls=49） | **pass** |
| `theme.storefront_head` / head | ≪ **~1334** | builder **absent**；`partials.fetch.head` **0.06** | builder **absent**；head **0.08** | **pass** |

## 辅证（记数 · 不关处女 total）

| 项 | 门/锚 | 本窗 | 记数 |
|----|-------|------|------|
| total | ≤2152（msg-70） | home **5119** · products **3011** | **fail（辅）**；优于 9v2 home 10474，仍劣于锚 |
| 公网 HIT | `/`+`/products` | 再 HIT 均 **HIT** 且含 chrome | **pass（辅）** |
| rc 纪律 | ≲80 才可议处女 | **970** / **978** | **非处女** · **禁**关处女 total |
| 种袋 L1/L2 | — | **禁**据此排 8c\* | 记数 |

### home top observers（辅）

| ms | name |
|----|------|
| 1622.49 | LayoutSlotRenderer（**固化 fail 源**） |
| 1437.57 | ControllerFetchFileAfter |
| 1281.84 | ControllerFetchFileBefore |
| 0.16 | theme.partials.fetch.header（**近零**） |

## vs 9v2 / 8v2

| 项 | 8v2 | 9v2 | 本窗 9v3 |
|----|-----|-----|----------|
| 完整性双页 | （当时未作本门） | `/products` **fail** · 公网 `/` 亦无壳 | **pass（稳住）** |
| header 布局再生 | **absent** | home **2641** fail | **0.12–0.16** pass |
| storefront_chrome builder | **absent** | **absent** | **absent** pass |
| LayoutSlot | 81.66 pass | Renderer **1019** fail | Renderer **~1569–1622** fail |
| card.render | 1288.97 | home 1722 / products 825 | **32 / 60** pass |
| Theme | 2.2.581 | 2.2.587 | **2.2.588** |

## 明确不宣称

- claim_sla=false；禁暖 HIT 关冷 total
- 禁因种袋 absent 要求重开 8c\*
- rc≫80 **不得**宣称真近处女 total
- 禁本席自 reload / 自排施工
- header 近零 **不得**单独宣称固化全过（LayoutSlot 仍 fail）

## escalate

**@项目经理：请安排**（禁本席自排）

1. **完整性 pass（主进展）**：Theme 2.2.588 后匿名/BP 双页 chrome 稳住；可记 9s4 完整性关账。
2. **固化仍 fail（LayoutSlot）**：LayoutSlotRenderer **~1.6s**（双页）；header/storefront_chrome **已不再生**。请唤醒 **Team:主题开发工程师:**（查 LayoutSlot 观察者为何仍 ≫100；禁用 injectChrome/runtime fill 换壳；禁回退 9s4 chrome）。
3. A 轴记数 **pass**（card/head）；total 辅 **fail**（非处女 rc）——完整性+LayoutSlot 后再议压 total。
4. **禁** 8c\*；**禁**本席自 reload。可选 NEED_PM 干净 reload 仅在 LayoutSlot 热修后低 rc 复测。

`pass=false` · 完整性=`pass` · 固化=`fail(LayoutSlot)` · header=`pass(近零·有壳)` · A轴=`pass` · `claim_sla=false` · suggested_seats: 项目经理 / **主题** / 性能复测
