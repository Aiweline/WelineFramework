# wave8-8v — 近处女冷复测（性能 · 十开 · msg-93）

- date: 2026-09-22 ~19:29+08
- seat: Team:性能检查工程师:
- channel: `framework-unreasonable-audit.md` **msg-93**（re msg-92；对照 msg-81/70）
- claim_sla: **false** · 禁自 reload · 禁暖 HIT 关冷账
- 对照：msg-70（total=2152）· msg-81（七开 rc=65 · total=2401 · chrome absent）· msg-89（九开 header≈846 / chrome absent）· msg-91/92（8c8 + 干净 reload）

## result

**fail**（公网 `/` HIT · `/products` #2 HIT · marker=0 过；**total 10619 劣于 2152**；**header 2448 ≫ 846**；**chrome `theme.storefront_chrome` 仍 l1/l2 absent**；**LayoutSlot 观察者 span 475ms 未≪100**；**rc=110 > ≲80** 近处女纪律未满足）

**采样纪律**：采样时 Workers **90295** / **90350**（对齐 msg-92；**本席未自 reload**）· `setup_upgrade.lock` **清** · 无并行 upgrade；尽早采得 `request_count=**110**`（**未≤80**；数字保留作 fail 证据，不得宣称真近处女关账）。

## 运行窗

| 项 | 值 |
|----|-----|
| Master | **37663** · Started `2026-09-22 09:15:56` |
| Workers（采样时） | **90295** / **90350** |
| 版本（仓内 module.php） | Theme **2.2.580**（PM 批 **2.2.579**）· F **2.5.164** · S **2.0.79** · Product **1.0.297** |
| 样本 | pid=**90295** · worker_id=**1** · `request_count=**110**`（>80 · 非真近处女） |
| 探针 | panel Cookie（BP 尾 `/`）· identity · Worker `:19655` · Host `:9555` · FPC **MISS** · 出站 `private, no-store` |
| request_id | `4bd03a6341617713-679067999376250` |
| HTTP | **200** · 明文 **1,134,548** ≈1.13MB |
| App total | **10619.29ms** · DB/WLS **1600.2** / **1959.2** · truncated=true · dropped_span=4612 |
| LayoutSlotRenderer | **observer::LayoutSlotRenderer = 475.32ms**（另 `zero_runtime_fill` 0ms · skipped · reason=`ctx_was_false`）→ **未≪100** |
| 残 B | **≈3406ms / 32.1%**（header **2448.15** + dict **957.04** + module_cache **0.86**；chrome nest 不双计） |
| A% | **25.4%**（card 2200.5 + head 498.7 = 2699.3） |
| HTML `data-wslot=` | **0** |
| builder meta | `theme.storefront_chrome` · **l1/l2 absent**（shared_read/recheck/write 同 absent；本窗 **无** `chrome_slot_projection` 资源名）· max span **2413.79ms** |

## vs 十开门（msg-92）

| 项 | 门 | 本窗 | 判定 |
|----|----|------|------|
| total | ≤2152（msg-70） | **10619** | **fail**（vs70/81/89 大幅↑劣） |
| header | 勿再 ~846ms（求降） | **2448** | **fail**（↑劣） |
| chrome L1/L2 | **非** absent | **`theme.storefront_chrome` absent** | **fail**（8c8 未闭合） |
| LayoutSlot | ≪100 | **475ms** | **fail** |
| marker | 0 | **0** | **pass** |
| 残 B | ≤~1122 或 ≤48.3% | **3406 / 32.1%** | **%过 · 绝对 fail** |
| 公网 HIT | `/`+`/products` | **`/` HIT**；`/products` #1 MISS→#2 **HIT** | 辅证 **pass** |
| rc | ≲80 | **110** | **纪律未满足** |

## 辅证 · 公网（≠冷达标）

| 路径 | 结果 |
|------|------|
| `/` | #1/#2 **HTTP 200** · FPC **HIT** · TTFB≈34ms / 8ms |
| `/products` | #1 MISS TTFB≈10.4s → #2 **HIT** TTFB≈64ms |

## 明确不宣称

- 禁暖 HIT / 公网 HIT 关冷账；禁 SLA/<100ms
- 残 B% / marker / 公网 HIT 过门 **不得**单独关 total / header / chrome / LayoutSlot 冷账
- rc>80 **不得**宣称真近处女关账
- claim_sla=false

## escalate

**@项目经理：请立刻安排**（禁本席自排 / 禁自 reload）

1. **主 fail**：total **10619** 劣于 msg-70 **2152**（差 ~8.5s）
2. **8c8 未闭合**：owner worker1 上 `theme.storefront_chrome` 仍 **l1/l2 absent**（header **2448**，较 msg-89 **846** 回潮）
3. LayoutSlot 观察者 span **475ms** 未≪100（相对九开无 span / 早退回归）
4. 采样：干净 reload 后尽早采仍 **rc=110**；若坚持真近处女门，请 PM 再批干净 reload 后更早唤醒（本席禁自 reload）

`pass=false` · `claim_sla=false` · suggested_seats: 后端·Runtime（8c8 复验/种袋） / 主题（LayoutSlot 回归） / 架构师 / 性能复测
