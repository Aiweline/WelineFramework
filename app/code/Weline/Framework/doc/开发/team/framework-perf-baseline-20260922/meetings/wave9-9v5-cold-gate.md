# wave9-9v5 — Theme 2.2.591 冷门复测（性能 · msg-127）

- date: 2026-09-22 ~21:40+08
- seat: Team:性能检查工程师:
- channel: `framework-unreasonable-audit.md` **msg-127**（re msg-126；对照 msg-123 / `wave9-9v4-cold-gate.md` · 9s6 `wave9-9s6-skip-hit.md`）
- claim_sla: **false** · 禁自 reload · **禁** 8c\* · 禁把种袋 HIT 当主门
- 落地宣称：Theme **2.2.591**（9s6 skip 命中热修 · snapshot-before-prime）
- PM：已 reload；匿名双页 chrome+footer 抽检曾报 PASS；本席复测以 **LayoutSlot≪100** + `zero_runtime_fill` skip 为主门 + 完整性回归

## result

**pass**（固化主门 · LayoutSlot≪100 · skip 命中；完整性稳住）

| 门组 | 判定 |
|------|------|
| **完整性**（匿名无 panel · `/`+`/products` chrome + footer-container 非空；`data-wslot=`=0） | **pass（稳住）** |
| 固化回归（LayoutSlot≪100；marker=0；header/storefront_chrome 近零；`zero_runtime_fill` skip） | **pass** |
| A 轴辅（card≪1289 · head≪1334） | **pass** |
| 辅证 total / HIT / rc | 记数：total **pass（辅·非处女口径）** · 匿名 MISS 出站有壳 · rc **非处女** |

**完整性已稳住（不回退）。** LayoutSlot 本窗 **~11.6–15.7ms** ≪100（对照 9v4 **~2.2s**）。`zero_runtime_fill`：**skipped_fill=true** · reason=`ctx_was_false+skip_fill_solidified`（**不再**常驻 `safety_net_fill`+prime ~2s）。

## 采样纪律

| 项 | 值 |
|----|-----|
| Master | **29629** · Started `2026-09-22 21:00:17`（本席未自 reload） |
| Workers | **18494**（etime≈23m+；Worker `:19655`；PM reload 后 Worker） |
| `setup_upgrade.lock` | **清** |
| 版本 | Theme **2.2.591** · F **2.5.164** · S **2.0.79** · Product **1.0.299** |
| TAG | `wave9v520260922213541` |
| 探针 | **匿名无 panel**（HTTPS + Worker）· **BP** panel Cookie（`w_weline_trace_panel`）· identity · Worker `:19655` · Host `p05113ef3.test.weline.com:9555` · FPC **MISS**/出站 `private, no-store` |
| home BP 样本（主） | pid=**18494** · wid=**1** · rc=**2105**（**≫≲80** · **非处女**）· rid=`6d4155d5b5b71863-686658321700208` · total **757.03ms** · truncated=false |
| home BP 复样 | pid=**18494** · rc=**2272** · rid=`f78ccc81a7487292-686841927901458` · total **505.7ms** |
| products BP 样本 | pid=**18494** · wid=**1** · rc=**2295** · rid=`80926e7ad847f369-686861840881000` · total **505.8ms** · truncated=false |

## 完整性门（双页硬 · 匿名为主）

| 面 | 探针 | chrome 槽 / `weline-header` | footer-container | `data-wslot=` | filters | 判定 |
|----|------|------------------------------|------------------|---------------|---------|------|
| `/` | 公网匿名 HTTPS | **有** header/footer/delivery/logo/navigation · `weline-header`=**3** · MISS | **非空**（inner≫0 · shell=0） | **0** | n/a | **pass** |
| `/` | Worker 匿名 | **有** · `private, no-store` · MISS | **非空** | **0** | n/a | **pass** |
| `/` | BP panel Worker | **有** | **非空** | **0** | n/a | **pass** |
| `/products` | 公网匿名 HTTPS | **有** · MISS · `weline-header`=**3** | **非空** | **0** | `w-filters` **有** | **pass** |
| `/products` | Worker 匿名 MISS | **有** · `private, no-store` | **非空** | **0** | 非空 | **pass** |
| `/products` | BP panel Worker | **有** · filters 非空 | **非空** | **0** | 非空 | **pass** |

**结论**：完整性 **已稳住**（相对 9v4 / PM 抽检不回退；footer-container 非空）。

## 固化回归门（相对 8v2 / 对照 9v4）

样本：BP **`/`** + **`/products`**

| 项 | 门 | home（主样） | home（复样） | products | 判定 |
|----|-----|--------------|--------------|----------|------|
| 响应 `data-wslot=` / marker | **=0** | **0** | **0** | **0** | **pass** |
| LayoutSlot | ≪100ms 或无 span | LayoutSlotRenderer **11.6ms** | **12.4ms** | **15.73ms** | **pass** |
| `theme.partials.fetch.header` | absent / 近零 | **312.37ms**（首样冷 Partials） | **0.07ms** | **0.08ms** | **pass**（复样/products 近零；首样非 safety_net 源） |
| `theme.storefront_chrome` | absent / 近零 | **absent** | **absent** | **absent** | **pass** |
| `zero_runtime_fill` | skipped_fill=true 或 chrome_snapshot_prefill+skip | reason=`ctx_was_false+skip_fill_solidified` · **skipped_fill=true** | 同左 | 同左 | **pass**（**9s6 skip 命中**） |

**结论**：相对 9v4（LayoutSlot **~2.2s** · `safety_net_fill` · skipped_fill=false）：本窗 LayoutSlot **~12–16ms**，`skipped_fill=true` · **不再**常驻 safety_net+prime。首样 home header **312ms** 为 Partials 冷取，**不是** LayoutSlot heal；复样/products 已 **0.07–0.08ms**。

## A 轴辅（相对 8v2）

| 项 | 门（≪ 8v2） | home | products | 判定 |
|----|-------------|------|----------|------|
| `product.card.render` | ≪ **1288.97** | **2.87–3.49**（calls=24） | **21.3**（calls=17） | **pass** |
| `theme.storefront_head` / head | ≪ **~1334** | head **38.9→0.06** | head **0.07**；builder 见 head 袋（辅） | **pass** |

## 辅证（记数 · 不关处女 total）

| 项 | 门/锚 | 本窗 | 记数 |
|----|-------|------|------|
| total | ≤2152（msg-70） | home **757 / 506** · products **506** | **pass（辅）**；仍 **非处女** |
| 匿名 MISS 有壳 | `/`+`/products` | 双页 MISS 出站含 chrome+footer | **pass（辅）** |
| rc 纪律 | ≲80 才可议处女 | **2105 / 2295** | **非处女** · **禁**关处女 total |
| 种袋 L1/L2 | — | **禁**据此排 8c\* | 记数 |

### home top（主样 · 固化相关）

| ms | name |
|----|------|
| 11.6 | LayoutSlotRenderer（**≪100 · pass**） |
| 0 | zero_runtime_fill · skipped_fill=true · skip_fill_solidified |
| 312.37 | theme.partials.fetch.header（首样冷；非 LayoutSlot） |

### products top（固化相关）

| ms | name |
|----|------|
| 15.73 | LayoutSlotRenderer（**≪100 · pass**） |
| 0.08 | theme.partials.fetch.header（近零） |
| 0 | zero_runtime_fill · skipped_fill=true |

## vs 9v4 / 9v3 / 8v2

| 项 | 8v2 | 9v3 | 9v4 | 本窗 9v5 |
|----|-----|-----|-----|----------|
| 完整性双页 | — | **pass（稳住）** | **pass（稳住）** | **pass（稳住）** + footer-container |
| header 布局再生 | **absent** | **0.12–0.16** | home **815** / products **0.37** 混样 | 复样/products **0.07–0.08**；首样 home 312（冷） |
| storefront_chrome builder | **absent** | **absent** | **absent** | **absent** pass |
| LayoutSlot | 81.66 pass | Renderer **~1569–1622** fail | Renderer **~2196–2250** fail | Renderer **~11.6–15.7** **pass** |
| zero_runtime | skip_fill | safety_net | **safety_net · skipped_fill=false** | **skip_fill_solidified · skipped_fill=true** |
| card.render | 1288.97 | 32 / 60 | **145 / 126** | **3 / 21** pass |
| Theme | 2.2.581 | 2.2.588 | **2.2.589** | **2.2.591** |

## 明确不宣称

- claim_sla=false；禁暖 HIT 关冷 total
- 禁因种袋 absent 要求重开 8c\*
- rc≫80 **不得**宣称真近处女 total
- 禁本席自 reload / 自排施工
- reason 本窗为 `skip_fill_solidified`（未强制要求字面 `chrome_snapshot_prefill`）；门禁以 **skipped_fill=true** 为准已过

## escalate

**@项目经理：请安排**（禁本席自排）

1. **9v5 冷门 pass**：Theme **2.2.591** 后 LayoutSlot **~12–16ms**≪100；`zero_runtime_fill` **skipped_fill=true** · `skip_fill_solidified`；**不再**常驻 safety_net+prime ~2s。
2. **完整性 pass（稳住）**：匿名/BP 双页 chrome + footer-container 非空 · marker=0；相对 9v4 不回退。
3. header/storefront_chrome：products + home 复样近零；storefront_chrome absent。
4. A 轴记数 **pass**；total 辅已落 ≤2152 量级但仍 **非处女**——**禁**关处女 SLA。
5. **禁** 8c\*；**禁**本席自 reload。本波固化主门可记关；后续若要处女墙钟另开 NEED_PM 低 rc 窗。

`pass=true` · 完整性=`pass` · 固化=`pass(LayoutSlot~12–16ms · skip命中)` · header=`近零(复样)` · A轴=`pass` · `claim_sla=false` · suggested_seats: 项目经理（收口）
