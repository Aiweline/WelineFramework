# wave6-6t — 近处女/尽早冷 MISS · 剩余墙钟归因（A / 残 B / 其它）

- date: 2026-09-22 ~16:57+08
- seat: **Team:性能检查工程师:**（只读探针 · **禁改码 / 禁自 reload / 禁清共享 FPC**）
- team: `framework-perf-baseline-20260922`
- against: channel **msg-30** · 对照 **msg-28**（total~1071ms · B≈1.0%）· `meetings/wave4-cold-trace.md`
- Host（权威）: `https://p05113ef3.test.weline.com:9555`
- Worker 诊断口: `127.0.0.1:19655`（`Host: p05113ef3.test.weline.com:9555`）
- **result: escalate** · **waiting_pm** · 本席不施工、不自排波次
- claim_sla: **false**（禁暖 HIT/br 冒充；禁宣称冷 SSR/SLA 达标）

---

## 0. 业务特性与口径（硬）

| 项 | 结论 |
|----|------|
| 面 | 店面公共 `/` 冷 FPC **MISS** 全量 SSR（探针带面板 Cookie） |
| Trace | 签名 Cookie `w_weline_trace_panel`（现行只认面板 Cookie；`wls_trace` query 不武装） |
| 体积口径 | **明文 SSR**（`Accept-Encoding: identity`）；公网 HIT/br **仅回归**，不作冷对照 |
| 同 Worker | PM 既有窗 Workers **39859** / **39881**（wave5 reload 后窗；本席**未** reload） |
| 处女度 | 本样本 `request_count=**1822**` → **非处女 / 非尽早**；HotCache 进程已暖；与 msg-28（rc=396）同属「进程暖 · FPC MISS」口径。真处女须 **@项目经理 reload 后早采** |

**phase 计量**：`measurement=inclusive` 父子不可相加。A/B **core** 用互不父子叠算代表段；**其它**用 `total − A − B`，并以 `trace_top` 主导 span 解释。

---

## 1. 运行窗事实

| 项 | 值 |
|----|-----|
| Master | 37394 · Started `2026-09-22 08:36:53` |
| Workers | **39859** / **39881** · Port 19655 · 已跑 ~16–17min |
| Homepage status | #1 `hit=true` adopted；#2 fail-open（观测债，非本波） |
| 共享 FPC | **未清**；公网 cookieless `/` 抽检仍 **HIT**（TTFB≈6ms）→ 仅证明池未毁，**≠** 冷达标 |

---

## 2. 主样本（Worker pid=39859）

探针：`wave6t20260922165653=1` · panel Cookie · identity · FPC **MISS** · 出站 `Cache-Control: private, no-store…`

| 项 | 值 |
|----|-----|
| request_id | `8ae11791931332bd-669895715287958` |
| pid / worker_id | **39859** / 1 |
| request_count | **1822**（高；非尽早） |
| App total_ms | **1256.8** |
| curl TTFB | **1.269s** |
| 明文 HTML | **1,318,652** (~1.32MB) |
| DB / WLS（trace_summary） | 74.0ms / 31.8ms |
| spans | 4096 recorded · dropped 378 · truncated=true |

---

## 3. A / 残 B / 其它占比

### 3.1 定义（对齐 wave4 / msg-28）

| 轴 | 含义 | core 代表（减父子双计） |
|----|------|-------------------------|
| **A 体积拼装** | DOM/卡片/head 输出侧 | `product.card.render` + `theme.partials.fetch.head` |
| **残 B** | builder+header+词典等串行（wave5 已关主债） | `storefront.cache.builder` + `theme.partials.fetch.header` + `view.hook.dictionary_prefetch` + `i18n.phrase.module_cache_get` |
| **其它** | `total − A − B`；主导用 `trace_top` | **LayoutSlotRenderer** 及 layout 链残段等 |

### 3.2 占比表（本窗 vs 对照）

| 对照 | total | **A** | **残 B** | **其它** | 主导相位（trace_top） |
|------|-------|-------|----------|----------|------------------------|
| wave4-4t `/` | 24449 | 2998 · 12.3% | 17798 · **72.8%** | 3653 · 14.9% | B：builder+header+dict |
| msg-28（5h+5c） | **1071** | ~162 · ~15%† | **10.4 · 1.0%** | ~899 · ~84% | **LayoutSlotRenderer 788ms** |
| **本窗 wave6-6t** | **1257** | **231 · 18.4%** | **17.2 · 1.4%** | **1008 · 80.2%** | **LayoutSlotRenderer 997ms** |

† msg-28 A：`card.render` 93.4 + `head` 68.8 ≈ 162ms（当时未正式拆 A%，此处为同口径回填）。

**残 B 结论**：本窗 **B≈1.4%**（header 2.8 + dict 3.6 + module_cache_get 10.8；**无**显著 builder）→ 与 msg-28 **B≈1.0%** 一致，**B 轴仍关**。

**其它结论**：墙钟约 **4/5** 不在 A/B core 具名 phase 内；与 msg-28 同构（其它~84%）。

### 3.3 Top phase / span 证据

**具名 phase（A/B）**

| ms | phase | 轴 |
|----|-------|----|
| 145.4 | `product.card.render` ×16 | **A** |
| 85.9 | `theme.partials.fetch.head` | **A** |
| 10.8 | `i18n.phrase.module_cache_get` | 残 B |
| 3.6 | `view.hook.dictionary_prefetch` | 残 B |
| 2.8 | `theme.partials.fetch.header` | 残 B |
| — | `storefront.cache.builder` | **未出现 / ≈0** |

**trace_top 主导（解释「其它」）**

| ms | name | 注 |
|----|------|----|
| **997** | `observer::…::LayoutSlotRenderer` | **主导**；含卡片子树但不等于 A-core 合计 |
| 197 | `observer::…::ControllerFetchFileAfter` | layout 链（含 head 拼装） |
| 86 | `theme.partials.fetch.head` | 与 A 重叠（已计入 A） |
| 57 | `view::hook::…::body-end` | 部件/尾钩 |
| 41 | `event::…::head_context_resolve`（Seo social） | head 侧 |

`router_profile`：`action_execute_ms≈1203` · `fpc_publish_ms≈15` · `fpc_probe_ms≈8` → 几乎全在 SSR 执行，非边缘传输。

---

## 4. 对照纪律

| 样本 | 用法 |
|------|------|
| 本窗 FPC MISS + identity + panel trace | **冷 SSR 归因**（进程暖 · 高 rc） |
| msg-28 total~1071 / B%=1.0% | 残 B 仍关；墙钟同量级（本窗略高，禁伪加速比） |
| wave4 B≈73% | 历史 B 债已关；**不得**用本窗总时长回写 B 未关 |
| 公网 cookieless HIT | **仅**证明未毁共享 FPC；**禁止**写成冷达标或 br 冒充 |

---

## 5. 建议（给项目经理 · 禁本席自排）

| 优先级 | 发现 | suggested_seats |
|--------|------|-----------------|
| **P0** | **主导相位 = LayoutSlotRenderer（~997ms · ~79%）**；「其它」≈80% — 与并行 **6a**（A 轴卡片/head）同向，但 A-core 具名仅 ~18%，须看插槽/部件/非首屏输出 | **主题 6a**（已排）+ 必要时部件/前端 |
| **P0** | 真**处女/尽早**冷：本窗 rc=1822 **未满足** → 请 PM **`server:reload -n` 后立即唤醒本席早采**（禁本席自 reload；禁清共享 FPC 毁全站） | **项目经理** |
| P1 | 卡片数据侧若投影/袋仍冷，对齐 **6p** | 后端·Product |
| — | 残 B | **不重开**（1.4%）；勿拆 no-store / fail-open |

**明确不做（本席）**：改码、自 reload、清 FPC、假 HIT、伪加速比、宣称 &lt;100ms。

---

## 6. result

```
Team:性能检查工程师: result=escalate
wave=wave6-6t
verdict=attribution_done · remaining_wall_clock_other_dominant · not_virgin
same_worker_pid=39859
request_count=1822
total_ms=1256.8
share=A≈18.4% · residual_B≈1.4% · other≈80.2%
dominant_phase=LayoutSlotRenderer≈997ms(~79%)
vs_msg28=total~1071→1257 · B% 1.0→1.4(still_closed)
vs_wave4=B_closed(was~73%)
artifact=meetings/wave6-cold-trace.md
plaintext_html=/~1.32MB
claim_sla=false
next=@项目经理：请安排/确认 6a 盯 LayoutSlotRenderer+A；若要处女证据请 reload 后早召本席；禁本席自排/自 reload
waiting_pm=true
```

---

## 7. wave6-6v 复测（msg-37 reload · Theme 2.2.561+2.2.562）

- date: 2026-09-22 ~17:08+08
- Workers: **75200** / **75584** · 样本 pid=**75584** · rc=**55** · FPC MISS · identity · panel-trace
- request_id=`6c45b5f3769bb29f-670566535958041`
- **total=3981.75ms** · **LayoutSlotRenderer=1673.42ms** · A=21.9% · 残 B=68.2% · 其它=9.9%
- vs msg-31：LayoutSlot **997→1673**（未降）；total **1257→3982**（近处女冷，B 回流）
- 公网 `/` `/products` HIT（回归）
- result=**escalate** · claim_sla=**false** · channel **msg-38**
