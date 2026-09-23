# 性能检查 — P6 specialty_review（P5 返工后再审）

- seat: Team:性能检查工程师:
- date: 2026-09-23
- team: `wls-perf-regression-20260923`
- wave: specialty_review · **P6**（对照 channel **msg-16** 最新受控 reload）
- against: contracts.md UC-* · `meetings/性能检查-review.md`（**P4 fail 基线**）· 主题-construction-p5 · 后端-construction-p5 · msg-5 冻结
- architect_joint: **true**（沿用 msg-5；本席复审联合口径）
- runtime: Framework **2.5.168** · Product **1.0.301** · Theme **2.2.602**
- reload: **本席未执行**（PM msg-16 已批并执行；**半代次 2.5.167 / PID 7542·7633 作废，不入 verdict**）
- verdict: **pass**
- result: **closed**
- notify_pm: **true**

---

## 0. 业务特性摘要

| 项 | 结论 |
|----|------|
| 面 | 公网匿名 `/`、`/products`（Host `https://p05113ef3.test.weline.com:9555`） |
| 读/写 | 读；FPC + HotCache bags；本波无写侧 |
| 运行时 | Instance `default` · Master 1186 · Workers **11294 / 11348** · Port 19655 |
| 热路径 | edge → FPC → deferred `pre_critical` → critical seal → `idle_gate` → `post_critical_heavy` → `idle_gate` → `locale_idle` → peer_hydrate |
| 对照口径 | **P4 fail 当前 PID**：pre≈**9523** / done≈**27109** / peer≈**7977**（57100/57185）；禁伪加速比；可写 before/after 绝对值 |

---

## 1. UC-warm — 公网暖 TTFB + FPC/edge

探针（cookieless · 2026-09-23 ≈01:48+08 · `/tmp/wls-perf-p6-20260923-v2/`）：

| 路径 | 样本 | TTFB | `x-weline-fpc` | `x-wls-edge-cache` |
|------|------|------|----------------|--------------------|
| `/` | burst1×5 | 5–7ms | HIT | 首 STALE，随后 UPDATING |
| `/products` | burst1×5 | 5–11ms | HIT | 首 STALE，随后 UPDATING |
| `/` `/products` | burst2×3（约+2s） | **5–6ms** | **HIT** | **HIT** |

Worker #1 proof：`homepage warmup: state=hot, hit=true, fpc=HIT, reason=homepage-fpc:deferred-warmup:adopted`。

**UC-warm：pass**（暖稳态 FPC HIT + edge HIT，TTFB 稳 &lt;50ms）。

---

## 2. UC-deferred — 绝对值对照（禁伪加速比）

权威代次 = **当前 Workers**：owner **11294** · peer **11348**（`server:status` 一致 · Framework 2.5.168）。

| stage | P4 fail（57100/57185） | **P6 当前（11294/11348）** |
|-------|------------------------|----------------------------|
| begin | `/` `/products`；`locale_budget=0`；extras→deferred | **同左**（hosts=`:9555`） |
| **pre_critical** | **9522.95ms** · 无 full/slug2；`chrome_rendered:miss` | **2084.4ms** · peeked=1（`candidates` Shared peek）· **无** full/slug2 冷种；仍记 `chrome_rendered:miss` |
| critical_sealed | 3974.47ms | **531.7ms**（warmed=2 failed=0 · adopted） |
| idle_gate(post_critical_heavy) | （P4 无独立 stage 或弱） | **165.72ms** · quiet_window |
| **post_critical_heavy** | **1410.76ms** · full/candidates | **1434.36ms** · full/slug2/candidates（及 idle_gate 后） |
| idle_gate(locale_idle) | — | **26.25ms** |
| locale_idle_begin | 有 · seal+heavy 后 | **有** · 同序 |
| **done** | **27108.77ms** | **19767.56ms**（warmed=6 failed=0） |
| peer_hydrate CPU | **7976.57ms**（57185） | **216.03ms**（11348） |
| peer_bags_hydrated | 14227.78 · delay=6250 | **6466.97** · delay=**6250** |

### A 核对（peek_only · 禁 pre 冷 1000）

| 检查 | 结论 |
|------|------|
| pre bags **不含** `product.catalog_offers.full` / `summary-slug2` 冷全量 | **pass** |
| pre 出现 `candidates` | **pass（peek）**：`peeked=1` · 非冷 `publishedOffers(1000)` |
| heavy 在 `post_critical_heavy`（seal + idle_gate 之后） | **pass** |
| pre/done 墙钟相对 P4 绝对值下降 | **pass**（pre 9523→2084；done 27109→19768） |

→ **A 结构 + UC-deferred 墙钟：pass**（相对 P4 fail 基线；禁把半代次 7542 的 pre=12507 / failed=49612 算进本 verdict）。

### 半代次作废（msg-16）

| PID | 代次 | 处置 |
|-----|------|------|
| 7542 / 7633 | Framework **2.5.167**（msg-14） | **作废** — 不入 P6 pass/fail；仅作运维说明 |

---

## 3. UC-locale / B

| 检查 | 结论 |
|------|------|
| begin：`locale_budget_near_virgin=0` · extras→`locale_deferred_paths` | **pass** |
| `locale_idle_begin` 在 `critical_sealed` + `idle_gate` + `post_critical_heavy` **之后** | **pass** |
| critical 窗仅 `/`+`/products` · adopted | **pass** |

→ **UC-locale：pass**。idle 段多语 SSR 仍计入 done（后移非取消）。

---

## 4. UC-peer / C — chrome miss · capture_miss · scope

| 代次 | peer errors | peer_hydrate CPU | delay |
|------|-------------|------------------|-------|
| P4 · 57185 | `capture_miss` + `chrome_rendered:miss` · 无 `scope_identity_missing` | **7976.57ms** | 6250 |
| **P6 · 11348** | **仅** `chrome_rendered:miss` · **无** `capture_miss` · **无** `scope_identity_missing` | **216.03ms** | 6250 |

| 检查 | 结论 |
|------|------|
| `scope_identity_missing`↓ | **pass**（0） |
| `capture_miss`↓（O2 Fiber latch） | **pass**（0） |
| chrome miss 短路径（诚实错误串 + 墙钟不空烧） | **pass**：miss 串仍在（盘上 chrome.rendered 仍缺），但 peer CPU 7977→**216**、pre 9523→**2084**，符合 O1「诚实 [] / 禁空烧」 |
| delay 未无证据砍 0 | **pass**（仍 6250） |

→ **UC-peer：pass**（短路径达标；chrome bake 内容仍缺属残留观测，不否决本 UC）。

---

## 5. 缓存合规复审

| 项 | 结论 |
|----|------|
| 无假 HIT / 无平行袋 | 未见；`chrome_rendered:miss` 诚实保留 |
| heavy 时机 | seal → idle_gate → post_critical_heavy |
| Host `:9555` | pass |
| D 埋点 | `db_span_count`/`wls_span_count` 出现（本样本多为 0） |
| Nginx / sidecar | 本席**未** nginx:reload / shared:stop |

---

## 6. UC 总表 + verdict

| UC | verdict | 一句话 |
|----|---------|--------|
| UC-warm | **pass** | 暖 FPC+edge HIT，TTFB≈5–11ms |
| UC-deferred | **pass** | A 结构 ok；pre/done 绝对值相对 P4 下降（2084 / 19768） |
| UC-locale | **pass** | budget=0 + idle 后移 + critical 先 seal |
| UC-peer | **pass** | capture_miss=0；peer CPU≈216ms；chrome miss 短路径 |
| UC-no-fake | **pass**（抽检） | 未见假 HIT / 平行袋 |

**总 verdict = pass** · **result = closed**。

残余（**不** reopen construction，交 PM 可选排期）：

1. 盘上仍无 durable `chrome.rendered` → 日志继续 `chrome_rendered:miss`（短路径已达标）
2. done≈19.8s 仍主要由 locale idle SSR 构成（B 设计意图）
3. delay=6250 待 Shared 就绪证据后再议下调（架构师 msg-5 SLA）

---

## 7. related_web_urls

- https://p05113ef3.test.weline.com:9555/
- https://p05113ef3.test.weline.com:9555/products

（诊断直连非交付：`http://p05113ef3.test.weline.com:19655/`）

---

@项目经理：本席已交付/上报（P6 **verdict=pass** · Workers **11294/11348** · FW 2.5.168），请检查并更新 SESSION。
