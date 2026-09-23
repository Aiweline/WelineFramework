# wave4-4t — 同 Worker 冷 MISS · A/B/C 占比归因

- date: 2026-09-22 ~16:00+08
- seat: **Team:性能检查工程师:**（真实席位 · 只读探针 · **禁改码 / 禁自 reload**）
- team: `framework-perf-baseline-20260922`
- against: `dev/ai-command/ai/性能检查.md` · `meetings/wave3c-cold-ssr-design.md` · channel **msg-15** · `统一缓存范围与性能优化.md`
- Host（权威）: `https://p05113ef3.test.weline.com:9555`
- Worker 诊断口: `127.0.0.1:19655`（`Host: p05113ef3.test.weline.com:9555`）
- **result: escalate** · **waiting_pm** · 本席不施工、不自排波次
- claim_sla: **false**（禁用公网暖 HIT / 伪加速比宣称冷达标）

---

## 0. 业务特性与口径（硬）

| 项 | 结论 |
|----|------|
| 面 | 店面公共 `/` 与 `/products` 冷 SSR 构建（FPC MISS） |
| 个性化 | 探针带面板签名 Cookie `w_weline_trace_panel` 开 phase（现行 Trace **只认面板 Cookie**，不认 `wls_trace` query）→ 请求带 Cookie，易拒 FPC；**故意**采全量 SSR |
| 体积口径 | **明文 SSR**（`Accept-Encoding: identity`）；公网 br/gzip 仅传输层，**不得**与源体积混账 |
| 同 Worker | 主样本 **pid=81633**（worker_id=`2`）连续 `/` → `/products` |
| C 轴 | 「本请求本应已被暖」→ 对照 cookieless 公网 + `server:status` homepage proof；**不**把 Cookie 探针的 MISS 写成「FPC 坏了」 |

**phase 计量**：`measurement=inclusive` 父子不可相加。A/B **core** 选用互不父子叠算的代表段；占比为墙钟近似，非精确 CPU self-time。

---

## 1. 运行窗事实

| 项 | 值 |
|----|-----|
| Master | 87173 · Started `2026-09-22 06:51:25` |
| Workers | **76885** / **81633** · Port 19655 |
| Homepage status（两 Worker） | `hit=false` · `reason=homepage-fpc:deferred-after-ready:fail-open` |
| Shared sidecar（进程实况） | Session **68436** / Memory **69470** 在跑；`server:status` 仍显示旧 PID Stopped（观测债，非本波） |
| Trace | 面板 Cookie 签发；`timing.log` 按 `X-Weline-Request-Id` 对齐 |

---

## 2. 主样本（同 Worker pid=81633）

探针：`wave4t2=20260922160041` · FPC=`MISS` · 出站 `Cache-Control: private, no-store…`（入池头另存；**非**设计错误）

| 路径 | request_id | request_count | App total_ms | curl TTFB | 明文 HTML |
|------|-----------|---------------|--------------|-----------|-----------|
| `/` | `293ed2da73348ab0-666523226370291` | 893 | **24449** | 24.55s | **1,318,049** (~1.32MB) |
| `/products` | `e9d827e0580fad25-666548053928416` | 923 | **24918** | 25.26s | **1,513,287** (~1.51MB) |

对照（同 pid 更早一次极冷 `/`，request_count=680）：`4c2ec9e7ef913986-…` · **41381ms** · 明文 1,322,357 — 说明进程内二次冷仍可更长；**禁**与暖 HIT 做伪加速比。

---

## 3. A / B / C 占比表

### 3.1 定义（对齐 wave3c / 架构师 msg-6）

| 轴 | 含义 | 本窗 phase 代表（core，减父子双计） |
|----|------|--------------------------------------|
| **A 体积拼装** | DOM/字符串/卡片等输出侧成本 | `product.card.render` + `theme.partials.fetch.head` |
| **B 串行 builder+扫盘** | HotCache MISS 构建、Header 瀑布、词典/i18n、过滤面板等串行段 | `/`：`storefront.cache.builder` + `theme.partials.fetch.header` + `view.hook.dictionary_prefetch` + `i18n.phrase.module_cache_get`；`/products`：上列 builder 系 + `storefront.filters.panel` + dict/i18n（catalog.* 多在 builder 内，不重复加） |
| **C 预热缺口** | 关键路径本应 FPC/L1 暖却仍全量 SSR | **不与 A/B 加总**；用公网 cookieless + status 判定「机会成本 ≈ 整次墙钟」 |

### 3.2 占比（同 Worker 81633）

| 路径 | total | **A** | **B** | residual* | **C（缺口判定）** |
|------|-------|-------|-------|-----------|-------------------|
| `/` | 24449ms | **2998ms · 12.3%** | **17798ms · 72.8%** | 3653ms · 14.9% | status 两 Worker 均 fail-open；但公网 cookieless `/` 已 **HIT**（见 §4）→ **首页 C 在边缘层部分闭合**；进程冷 MISS 仍贵 |
| `/products` | 24918ms | **9514ms · 38.2%** | **8319ms · 33.4%**† | ~7.1s（含 singleflight 等） | 公网 cookieless `/products` **#1 MISS ~10.2s** → **#2 HIT** → **C 对列表仍开**（deferred/首击窗口） |

\* residual：路由/事件/未单独 core 的 SSR 外壳等；**不可**当作第四轴达标证明。  
† `/products` B 含 `filters.panel`（1783ms）；`catalog.*` 已含在 builder inclusive 内未再加。另有 `singleflight_acquire` **1269ms**（L1/L2 `absent`）可并入 B 观感 → B 可至 ~**38%**，与 A 接近。

### 3.3 Top phase 证据（摘）

**`/`（B 主导）**

| ms | phase | 注 |
|----|-------|----|
| 8238 | `storefront.cache.builder` | resource=`product.catalog_offers_targeted`；**l1/l2=absent** |
| 5073 | `theme.partials.fetch.header` | Header 串行瀑布 |
| 3915 | `view.hook.dictionary_prefetch` | 冷词典 |
| 2508 | `theme.partials.fetch.head` | → **A** |
| 490 | `product.card.render` ×16 | → **A**（首页卡片少） |

**`/products`（A≈B）**

| ms | phase | 注 |
|----|-------|----|
| 8857 | `product.card.render` ×40 | → **A**（列表体积主因） |
| 6205 | `storefront.cache.builder` | l1/l2 absent；内含 candidates/snapshots |
| 1783 | `storefront.filters.panel` | 过滤面板冷构建 |
| 1269 | `storefront.cache.singleflight_acquire` | 等待/协调 |
| 657 | `theme.partials.fetch.head` | → **A** |

Theme 独立 `is_file`/`disk` 具名 phase 本窗仅毫秒级（如 `theme.head.disk_override`）；**扫盘债可能埋在 header/layout 瀑布内**，升格 `rememberPolicy(deps=theme)` 仍合理，但不能用「无扫盘 phase」否定 B。

---

## 4. 公网对照（纪律）

| 样本 | 结果 | 用法 |
|------|------|------|
| cookieless `/` #1/#2 | **HIT** · TTFB≈89ms / 15ms · body≈647KB（**传输层压缩**；明文仍 ~1.32MB） | 证明 FPC 层对首页有效；**≠** 冷 SSR 达标 |
| cookieless `/products` #1 | **MISS** · TTFB≈**10.2s** · ~1.51MB | **C 证据** |
| cookieless `/products` #2 | **HIT** · TTFB≈0.57s | 二次入池；禁写成冷已关 |
| 带唯一 query 的公网 | 人为 MISS（key 变体） | **作废**作暖对照 |

---

## 5. 建议下一波优先级（给项目经理 · 禁本席自排）

对齐 msg-15 已开并行席；本席只给**占比驱动的优先序**：

| 优先级 | 轴 | 建议 | suggested_seats |
|--------|----|------|-----------------|
| **P0** | **B（首页）** | `/` 冷墙钟 **~73%** 在 builder+header+词典；主题领 **路径/目录 `rememberPolicy(deps=theme)`** + Header 瀑布收敛；后端/Product 盯 catalog builder MISS（l1/l2 absent） | **主题 4b**（等本纪要）+ 后端 |
| **P0** | **A≈B（列表）** | `/products`：**卡片拼装 ~38%** 与 **builder/filters ~33–38%** 接近 → **小步并行**（禁大码一次清空体积）；列表延迟非首屏卡片/压缩源体积须保语义 | **主题 + 前端**；过滤器/catalog 归后端 |
| **P0** | **C（列表首击）** | 公网 `/products` 首击仍可 10s MISS；与 4c（Framework 2.5.150）deferred+L1 pin **同向**；须 **PM 批 reload 后** 本席复测 adopted + `/products` 首 HIT | **后端·Runtime 4c**（msg-16 已报）+ 本席复测 |
| P1 | 观测 | status 仍双 Worker fail-open 而公网 `/` 已 HIT → M4 status UX；sidecar status 旧 PID | 后端·Server 4o / 文档 |

**明确不做（本席）**：改码、自 reload、拆 `private,no-store`、改 fail-open=0、假 HIT、伪加速比。

---

## 6. result

```
Team:性能检查工程师: result=escalate
wave=wave4-4t
verdict=attribution_done · cold_ssr_still_fail_vs_target
same_worker_pid=81633
share=/ → B≈73% A≈12% · /products → A≈38% B≈33–38% · C=/products_first_hit_gap
artifact=meetings/wave4-cold-trace.md
plaintext_html=/\~1.32MB /products\~1.51MB
claim_sla=false
next=@项目经理：请按占比唤醒主题4b（B主+列表A并行）；4c reload后本席复测C；禁本席自排
waiting_pm=true
```
