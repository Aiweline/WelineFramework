# wave9-9v4 — Theme 2.2.589 冷门复测（性能 · msg-123）

- date: 2026-09-22 ~21:12+08
- seat: Team:性能检查工程师:
- channel: `framework-unreasonable-audit.md` **msg-123**（re msg-122；对照 msg-119 / `wave9-9v3-cold-gate.md`）
- claim_sla: **false** · 禁自 reload · **禁** 8c\* · 禁把种袋 HIT 当主门
- 落地宣称：Theme **2.2.589**（9s5 LayoutSlot 完整壳 skip 快路径）
- PM：已 reload；匿名双页 chrome 抽检曾报仍 PASS；本席复测以 **LayoutSlot≪100** 为主门 + 完整性回归

## result

**fail**（固化主门 · LayoutSlot≪100 未过；相对 9v3 未改善）

| 门组 | 判定 |
|------|------|
| **完整性**（匿名无 panel · `/`+`/products` chrome；filters 非空；`data-wslot=`=0） | **pass（稳住）** |
| 固化回归（LayoutSlot≪100；marker=0；header/storefront_chrome 近零） | **fail**（LayoutSlot）；header **混样** |
| A 轴辅（card≪1289 · head≪1334） | **pass** |
| 辅证 total / HIT / rc | 记数：total **fail（辅）** · HIT **pass（辅）** · rc **非处女** |

**完整性已稳住（不回退）。** LayoutSlot 本窗 **~2196 / ~2250ms**，对照 9v3 **~1569–1622ms** — **未过 ≪100，且未相对 9v3 改善**。

## 采样纪律

| 项 | 值 |
|----|-----|
| Master | **29629** · Started `2026-09-22 21:00:17`（本席未自 reload） |
| Workers | **85876** / **87560**（采样窗 etime≈4m；Worker `:19655`；PM reload 后滚动 Worker） |
| `setup_upgrade.lock` | **清** |
| 版本 | Theme **2.2.589** · F **2.5.164** · S **2.0.79** · Product **1.0.299** |
| TAG | `wave9v420260922210757` |
| 探针 | **匿名无 panel**（HTTPS + Worker）· **BP** panel Cookie（`w_weline_trace_panel`）· identity · Worker `:19655` · Host `p05113ef3.test.weline.com:9555` · FPC **MISS**/出站 `private, no-store`（Worker） |
| home BP 样本 | pid=**70381** · wid=**2** · rc=**140**（**≫≲80** · **非处女**）· rid=`c6ce583f76fd09cf-685001239264291` · total **10415.93ms** · DB/WLS **487.97** / **423.77** · truncated=false · dropped=0 |
| products BP 样本 | pid=**85876** · wid=**1** · rc=**528** · rid=`bb457f270759cd6b-685324211749666` · total **24661.64ms** · DB/WLS **261.66** / **1031.05** · truncated=false · dropped=0 |

注：products 首几轮 BP 曾 Empty reply（Worker 滚动）；最终以成功 rid 为准。匿名 Worker MISS 仅作完整性，不关 LayoutSlot 账（无 panel 时 phases 常不全）。

## 完整性门（双页硬 · 匿名为主）

| 面 | 探针 | chrome 槽 / `weline-header` | `data-wslot=` | filters | 判定 |
|----|------|------------------------------|---------------|---------|------|
| `/` | 公网匿名 HTTPS | **有** header/footer/delivery/logo/navigation · `weline-header`=**3** · HIT | **0** | n/a | **pass** |
| `/` | Worker 匿名 | **有** · `private, no-store` | **0** | n/a | **pass** |
| `/` | BP panel Worker | **有** | **0** | n/a | **pass** |
| `/products` | 公网匿名 HTTPS | **有** · HIT · `weline-header`=**3** | **0** | `w-filters` **有** | **pass** |
| `/products` | Worker 匿名 MISS | **有** · `private, no-store` | **0** | 非空 | **pass** |
| `/products` | BP panel Worker | **有** · filters 非空 | **0** | 非空 | **pass** |

**结论**：完整性 **已稳住**（相对 9v3 / PM 抽检不回退）。

## 固化回归门（相对 8v2 / 对照 9v3）

样本：BP **`/`** + **`/products`**（双页均有壳）

| 项 | 门 | home | products | 判定 |
|----|-----|------|----------|------|
| 响应 `data-wslot=` / marker | **=0** | **0** | **0** | **pass** |
| LayoutSlot | ≪100ms 或无 span | LayoutSlotRenderer **2195.64ms** | **2250.35ms** | **fail** |
| `theme.partials.fetch.header` 布局再生 | absent / 近零 | **815.25ms** | **0.37ms** | **混样 fail（home）** |
| `theme.storefront_chrome` 布局再生 | absent / 近零 | **absent** | **absent** | **pass** |
| `chrome_slot_projection` | absent / 近零 | **absent** | **absent** | **pass** |
| `zero_runtime_fill` | skip 完整壳 | reason=`ctx_was_false+safety_net_fill` · **skipped_fill=false** | 同左 | **未吃到 9s5 skip**（辅） |

**结论**：9s5 宣称完整壳 `+skip_fill_solidified` **本窗未观测到**——双页仍 `safety_net_fill` 且 LayoutSlot **~2.2s**（劣于 9v3 ~1.6s）。products header 近零；home header **815ms** 回潮。完整性有壳 ≠ LayoutSlot 快路径命中。

## A 轴辅（相对 8v2）

| 项 | 门（≪ 8v2） | home | products | 判定 |
|----|-------------|------|----------|------|
| `product.card.render` | ≪ **1288.97** | **144.92**（calls=40） | **125.88**（calls=49） | **pass** |
| `theme.storefront_head` / head | ≪ **~1334** | builder 见 storefront_head 袋 build **255ms**（辅）；`partials.fetch.head` **132.1** | builder **absent**；head **0.28** | **pass**（相对 1334） |

## 辅证（记数 · 不关处女 total）

| 项 | 门/锚 | 本窗 | 记数 |
|----|-------|------|------|
| total | ≤2152（msg-70） | home **10416** · products **24662** | **fail（辅）**；非处女 |
| 公网 HIT | `/`+`/products` | 均 **HIT** 且含 chrome | **pass（辅）** |
| rc 纪律 | ≲80 才可议处女 | **140** / **528** | **非处女** · **禁**关处女 total |
| 种袋 L1/L2 | — | **禁**据此排 8c\* | 记数 |

### home top observers（辅）

| ms | name |
|----|------|
| 2195.64 | LayoutSlotRenderer（**固化 fail 源**） |
| 1883.82 | ControllerFetchFileAfter |
| 815.25 | theme.partials.fetch.header（home 回潮） |

### products top observers（辅）

| ms | name |
|----|------|
| 2250.35 | LayoutSlotRenderer（**固化 fail 源**） |
| 0.37 | theme.partials.fetch.header（近零） |

## vs 9v3 / 8v2

| 项 | 8v2 | 9v3 | 本窗 9v4 |
|----|-----|-----|----------|
| 完整性双页 | — | **pass（稳住）** | **pass（稳住）** |
| header 布局再生 | **absent** | **0.12–0.16** pass | home **815** / products **0.37** · **混样** |
| storefront_chrome builder | **absent** | **absent** | **absent** pass |
| LayoutSlot | 81.66 pass | Renderer **~1569–1622** fail | Renderer **~2196–2250** fail（↑） |
| zero_runtime | skip_fill | safety_net（0ms） | **safety_net · skipped_fill=false** |
| card.render | 1288.97 | 32 / 60 | **145 / 126** pass |
| Theme | 2.2.581 | 2.2.588 | **2.2.589** |

## 明确不宣称

- claim_sla=false；禁暖 HIT 关冷 total
- 禁因种袋 absent 要求重开 8c\*
- rc≫80 **不得**宣称真近处女 total
- 禁本席自 reload / 自排施工
- 完整性 pass **不得**单独宣称 9s5 LayoutSlot 快路径关账

## escalate

**@项目经理：请安排**（禁本席自排）

1. **完整性 pass（稳住）**：Theme 2.2.589 后匿名/BP 双页 chrome 不回退；可记完整性回归过。
2. **固化仍 fail（LayoutSlot 主门）**：双页 LayoutSlotRenderer **~2.2s** ≫100；相对 9v3 **未降反升**。`zero_runtime_fill` 仍 `safety_net_fill` · `skipped_fill=false` —— **9s5 skip 快路径本窗未命中**。请唤醒 **Team:主题开发工程师:** 查为何有 chrome 仍走 safety_net/heal（禁 injectChrome/runtime fill 换壳；禁回退 9s4 匿名 chrome）。
3. home header **815ms** 回潮（products 近零）——与 safety_net 同波核对，勿单独当丢壳。
4. A 轴记数 **pass**；total 辅 **fail**（非处女）——LayoutSlot 过后再议压 total。
5. **禁** 8c\*；**禁**本席自 reload。可选 NEED_PM 干净 reload 仅在主题热修后低 rc 复测。

`pass=false` · 完整性=`pass` · 固化=`fail(LayoutSlot~2.2s)` · header=`混样` · A轴=`pass` · `claim_sla=false` · suggested_seats: 项目经理 / **主题** / 性能复测
