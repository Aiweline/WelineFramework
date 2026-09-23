# 性能检查 — 开发后复审（specialty_review）

- seat: Team:性能检查工程师:
- date: 2026-09-23
- team: `wls-perf-regression-20260923`
- wave: specialty_review · 开发后复审轨（对照 PM msg-9 受控 reload）
- against: contracts.md UC-warm / UC-deferred / UC-locale / UC-peer · `meetings/性能检查-design.md` · 后端/主题 construction · channel **msg-5 冻结** + **msg-9**
- architect_joint: **true**（架构师 msg-5 reply；本席复审联合口径）
- reload: **本席未执行**（PM 已批 Workers 滚动；**Nginx owner commit 警告未另批 nginx:reload**；**sidecar 未轮换** — 复审注明）
- verdict: **fail**
- result: **escalate**
- notify_pm: **true**

---

## 0. 业务特性摘要（复审门槛）

| 项 | 结论 |
|----|------|
| 面 | 公网匿名 `/`、`/products`（Host `https://p05113ef3.test.weline.com:9555`） |
| 读/写 | 读；FPC + HotCache bags；本波无写侧 |
| 运行时 | Instance `default` · Master 77443 · Workers **57100 / 57185** · Port 19655 |
| 热路径 | edge → FPC → deferred `pre_critical` → critical seal → `post_critical_heavy` → `locale_idle` → peer_hydrate |
| 基线同口径 | design pid=**2555** · pre_critical **7572.77ms** · done **18572.13ms** · peer **6051.71ms**（含 `scope_identity_missing`） |

---

## 1. UC-warm — 公网暖 TTFB + FPC/edge

探针（cookieless · 2026-09-23 ≈01:25+08 · `/tmp/wls-perf-review-20260923/`）：

| 路径 | 样本 | TTFB | `x-weline-fpc` | `x-wls-edge-cache` |
|------|------|------|----------------|--------------------|
| `/` | burst1×5 | 6–13ms | HIT | 首 STALE，随后 UPDATING |
| `/products` | burst1×5 | 5–7ms | HIT | 首 STALE，随后 UPDATING |
| `/` `/products` | burst2×3（约+2s） | **5–24ms** | **HIT** | **HIT** |

**UC-warm：pass**（暖稳态 FPC HIT + edge HIT，TTFB 稳 &lt;50ms）。  
禁伪加速比；未与冷/忙窗样本混算。

---

## 2. UC-deferred — reload 后 stage 对照（绝对值 · 禁伪加速比）

权威代次 = **当前 Workers**：owner **57100** · peer **57185**（`server:status` 一致）。  
另记过渡代 **43446**（同编排已生效，但非当前 PID）。

| stage | 基线 2555 | 过渡 43446 | **当前 57100/57185** |
|-------|-----------|------------|----------------------|
| begin paths | `/` `/products` + locale 近窗 | `/` `/products` only；`locale_budget=0`；extras→deferred | **同左** |
| **pre_critical** | **7572.77ms** · bags 含 **catalog.full + candidates** | **1477.56ms** · **无** full/candidates | **9522.95ms** · **无** full/candidates；`chrome_rendered:miss` |
| critical_sealed | 679.4ms | 1913.16ms | 3974.47ms（`/` `/products` 首 MISS SSR） |
| **post_critical_heavy** | （无此 stage） | **966.84ms** · full/candidates 在此 | **1410.76ms** · full/candidates 在此 |
| locale_idle_begin | （无） | 有 · 在 seal+heavy 后 | **有** · 同序 |
| **done** | **18572.13ms** | 14992.7ms | **27108.77ms** |
| peer_hydrate | 6051.71ms（3533） | — | **7976.57ms**（57185） |
| peer_bags_hydrated | 12307.94ms · delay=6250 | — | 14227.78ms · delay=6250 |

### A 核对（peek_only · 禁 pre 冷 1000）

| 检查 | 结论 |
|------|------|
| pre_critical bags **不含** `product.catalog_offers.full` / `summary-slug2` / `candidates` | **pass**（57100 / 43446） |
| heavy 迁至 `post_critical_heavy`（seal 之后） | **pass** |
| pre 墙钟「显著下降」相对基线 7.5s | **fail on 57100**（绝对值 **9522.95ms** &gt; 7572.77ms）；过渡代 1477ms 曾改善，**不可**用过渡代冒充当前代加速比 |

→ **A 结构合规 pass；UC-deferred 墙钟目标 fail（以当前 PID 为准）。**  
高 pre 归因证据指向 Theme `chrome_rendered:miss`（轻袋仍烧 ~9.5s），**非** pre 内 catalog.full 冷 1000。

### B 核对（近临界无 locale SSR 挤窗）

| 检查 | 结论 |
|------|------|
| begin：`locale_paths=[]` · `locale_budget_near_virgin=0` · `locale_deferred_paths` 非空 | **pass** |
| `locale_idle_begin` 出现在 `critical_sealed` + `post_critical_heavy` **之后** | **pass** |
| critical 窗仅 `/`+`/products` | **pass** |

→ **UC-locale / B：pass**（编排层）。  
idle 段仍有多语 MISS SSR（计入 done 墙钟）；符合「后移」而非「取消」。

---

## 3. UC-peer / C — scope_identity_missing↓

| 代次 | peer errors | peer_hydrate elapsed |
|------|-------------|----------------------|
| 基线 3533 | `chrome_rendered:miss` + **`scope_identity_missing`** | 6051.71ms |
| 当前 57185 | `capture_miss` + `chrome_rendered:miss` · **无 scope_identity_missing** | **7976.57ms** |

→ **C：`scope_identity_missing`↓ = pass**；chrome miss 短路径**未**把 hydrate CPU 压下来（绝对值更高）。  
delay=6250 未改（符合「无 Shared 就绪证据禁砍」）。  
**UC-peer 整体：fail**（errors 形态改善，墙钟/chrome miss 未达标）。

---

## 4. 缓存合规复审

| 项 | 结论 |
|----|------|
| 无假 HIT / 无平行袋 | 未见新增；chrome miss 诚实错误串保留 |
| heavy 冷种时机 | 已移出 pre；仍在 owner 事件循环（post_critical_heavy）同步执行 — 合规于 A，但 **未**消除 Worker 忙窗 |
| Host 对齐 `:9555` | pass |
| D 埋点 | `db_span_count`/`wls_span_count` 出现（本样本多为 0 — 不据此夸大） |
| Nginx / sidecar | **未** nginx:reload；sidecar 未轮换 — 暖 edge HIT 仍可达，但 **不能**把 edge 层变更算进本波收益 |

---

## 5. UC 总表 + verdict

| UC | verdict | 一句话 |
|----|---------|--------|
| UC-warm | **pass** | 暖 FPC+edge HIT，TTFB≈5–24ms |
| UC-deferred | **fail** | A 结构 ok；当前 PID pre/done 绝对值未降（pre 9.5s / done 27.1s vs 基线 7.5s / 18.6s） |
| UC-locale | **pass** | budget=0 + idle 后移 + critical 先 seal |
| UC-peer | **fail** | scope_identity_missing↓；chrome miss + peer CPU 仍高 |
| UC-no-fake | **pass**（抽检） | 未见假 HIT / 平行袋 |

**总 verdict = fail**（暖路径达标不足以关闭 deferred/peer 墙钟回归）。  
**result = escalate**（本席不排施工、不 reload）。

---

## 6. findings → escalate · options

根因排序（有证据）：

1. **Theme chrome_rendered:miss** 在 pre/critical/peer 反复出现 → pre≈9.5s、peer≈8.0s（非 catalog.full）  
2. **post_critical_heavy + locale_idle SSR** 仍拉长 owner done（当前 27s）  
3. reload 后 FPC 首封偶发 MISS（`/` 2354ms / `/products` 1493ms）放大 critical_sealed  

### options（≥2 · 交 PM 组队）

**O1（优先 · 主题+后端）** — 修 chrome bake / Scope 键 `default.__store__.__channel__` miss：保证 peer/pre 轻种短路径（诚实 `[]` 且 **不**空烧重投影）；复测 peer_hydrate 与 pre 绝对值。  

**O2（后端）** — 进一步把 `post_critical_heavy` 与 locale_idle 的长同步段 **yield/idle-gate**；目标：critical_sealed 后尽快让出事件循环（禁假 HIT）。  

**O3（PM 运维可选）** — 另批 **nginx:reload** / sidecar 轮换后再采一组暖样本（本席禁私自执行）；与代码修复解耦记账。  

**O4（观测）** — 用 D 埋点在 chrome miss 窗补真实 DB/WLS&gt;0 样本；禁止跨 PID 伪加速比。

### suggested_seats

- Team:项目经理:（组队 / 是否再批 reload·nginx）
- Team:主题开发工程师:（chrome miss · Scope 键）
- Team:后端:（heavy/idle yield · capture_miss）
- Team:架构师:（若需修订 surfaces / delay SLA）
- Team:性能检查工程师:（再审；禁自排）

---

## 7. related_web_urls

- https://p05113ef3.test.weline.com:9555/
- https://p05113ef3.test.weline.com:9555/products

（诊断直连非交付：`http://p05113ef3.test.weline.com:19655/`）

---

@项目经理：请立刻组队解决（verdict=fail · UC-deferred/UC-peer）。本席已交付复审纪要，请检查并更新 SESSION。
