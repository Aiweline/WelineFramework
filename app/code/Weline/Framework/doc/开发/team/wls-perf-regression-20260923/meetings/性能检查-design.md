# 性能检查 — 设计诊断（WLS 卡死回归）

- seat: 性能检查工程师（独立子智能体）
- agent_id: bb4d3275-23c1-498c-9d10-bdd5837e5ba7
- date: 2026-09-23
- team: `wls-perf-regression-20260923`
- wave: diagnose（设计检查轨 · 只读探针）
- against: SESSION · channel/perf-architect.md msg-1 · 前序 `framework-perf-baseline-20260922` R1/R2/R3
- architect_joint: **true**（架构师 channel msg-5 reply 已确认；复审见 `meetings/性能检查-review.md`）
- verdict: **异议 / escalate**（暖路径可 HIT，但 deferred bag-prime + locale SSR 同步 CPU 造成「卡死」与偶发 origin 慢）
- reload: **未执行**（禁私自 reload）

---

## 1. 业务特性摘要（开药门槛）

| 项 | 结论 |
|----|------|
| 面 | 店面**公共匿名**路径 `/`、`/products`（及 deferred locale 家 `/ar_SA/`、`/bn_BD/`） |
| 读/写 | 读为主；FPC + HotCache bags；无本波写侧 |
| 个性化 / 草稿 | FPC 仅匿名公共页；Cookie 可使 **edge BYPASS**；草稿/page target 禁入共享结构池（R3 既有） |
| Host | 公网验收 `https://p05113ef3.test.weline.com:9555`；Worker 口 `:19655`；warmup hosts 已对齐 `:9555` |
| 运行时 | WLS Worker×2（owner=#1 deferred critical；#2 peer_hydrate）+ Nginx edge |
| 热路径段落 | ① Nginx edge → FPC ② Worker Process/Shared FPC ③ deferred `pre_critical` bag prime（Product heavy catalog.full）④ critical FPC seal ⑤ locale extras SSR ⑥ peer bag hydrate |
| Owner / 批量入口 | Product `StorefrontHotCacheBagSeeder` → `StorefrontCatalogViewService::publishedOffers`；Theme chrome/header/partials；Framework `WlsRuntime` deferred 编排；CachePolicy/HotCache（禁平行袋） |
| 前序 | R1 真批量 Adapter；R2 fail-open⇒deferred；R3 published layout HotCache |

---

## 2. 框架结构映射

```text
公网 :9555 (Nginx edge)
  → FPC HIT+edge HIT      ≈3–10ms（暖稳态）
  → FPC HIT+edge MISS/STALE → 回源 Worker :19655（Worker 忙则秒级）
  → edge BYPASS（Cookie）→ 仍可能 FPC HIT（本席假 Cookie 样本 ≈27ms）

Worker lifecycle (owner #1):
  READY fail-open
  → Fiber deferred: runDeferredStorefrontCriticalWarmup
       primeDeferredStorefrontHotCacheBags('pre_critical')  ← 同步 CPU 热点
       runStorefrontFpcWarmupInternal(['/','/products'])
       prime('critical')
       locale FPC warmup (/ar_SA/, /bn_BD/)                 ← 多秒 MISS SSR
       prime('post_locale')
  → adopt homepage proof

Worker #2:
  delay 6250ms → prime('peer_hydrate')  ← 又一段同步 CPU（轻种，但仍可数秒）
```

扩展点：`storefront_hot_cache_bag_warmup.*` Provider（Product/Theme）；FPC Warmup Provider；HotCache/CachePolicy/CachePool/WLS L2。  
禁止：跨模块直调、平行进程内袋、假 HIT、私自 reload。

---

## 3. 埋点 / 只读探针证据

### 3.1 实例身份（勿混 ManyCartSync）

- Instance **`default`** · Master 77443 · Workers **2555 / 3533** · Port **19655**
- 公网：`https://p05113ef3.test.weline.com:9555`
- #1：`state=hot, hit=true, reason=homepage-fpc:deferred-warmup:adopted`
- #2：`state=warm, hit=false, reason=homepage-fpc:deferred-after-ready:fail-open`（非 owner · **预期**）

### 3.2 当前代 deferred 日志（pid=2555/3533 · `var/log/wls-storefront-warmup.log`）

| stage | pid | elapsed_ms | 要点 |
|-------|-----|------------|------|
| begin | 2555 | — | paths=`/`,`/products`,`/ar_SA/`,`/bn_BD/`；hosts=`:9555` |
| **hot_cache_bags_primed / pre_critical** | 2555 | **7572.77** | seeded=10；含 `product.catalog_offers.full`+`summary-slug2`+candidates+Theme bags；`in_request=true` |
| critical | 2555 | 325.21 | 轻触；seeded=7 |
| critical_sealed | 2555 | 679.4 | `/`+`/products` FPC **HIT**（80ms / 305ms） |
| post_locale | 2555 | 566.51 | — |
| **done** | 2555 | **18572.13** | locale `/ar_SA/` **MISS SSR 4372ms** body≈980KB；`/bn_BD/` **MISS 4307ms** body≈1.0MB |
| **peer_hydrate** | 3533 | **6051.71** | Theme `chrome_rendered:miss` + `partials.header:scope_identity_missing` |
| peer_bags_hydrated | 3533 | **12307.94** | 含 **delay_ms=6250** + bag CPU |

→ **与 PM 观测 pre_critical≈7.5s / peer≈6s / done≈18s 同一样本，可复现。**

### 3.3 历史聚合（日志末段 bag primes）

| stage | n | min | p50 | max |
|-------|---|-----|-----|-----|
| pre_critical | 22 | 176ms | **4058ms** | **24630ms** |
| peer_hydrate | 23 | 170ms | 1977ms | **59491ms** |
| critical | 22 | 34ms | 920ms | 4548ms |
| post_locale | 21 | 35ms | 156ms | 8559ms |

### 3.4 公网暖路径（本席探针 · 2026-09-23 ≈00:57+08）

| 样本 | TTFB | FPC / edge |
|------|------|------------|
| `/` ×6 burst | 4–22ms | HIT / HIT（偶 STALE） |
| `/products` ×3 | ≈5ms | HIT / HIT |
| Cookie 假会话×5 | ≈27ms | **FPC HIT** + **edge BYPASS** |

**未在本窗复现** PM「FPC HIT+edge MISS≈3.6s」与「Cookie BYPASS FPC MISS≈1.3s」；机制上仍成立：edge miss/bypass 回源时若 Worker 正跑 bag-prime/locale SSR，origin 可秒级（见 3.5/3.6）。禁止跨样本伪加速比。

### 3.5 公网 :9555 vs 直连 :19655

| 探测 | 结果 |
|------|------|
| 公网 `/` | HIT · TTFB≈21ms（edge STALE） |
| 直连 `http://p05113ef3.test.weline.com:19655/`（同窗稍后） | HIT · ≈6ms |
| 同 Host 更早一次直连 | **MISS · TTFB≈2.71s · `Cache-Control: private, no-store`** |
| 公网 `/products` | HIT · ≈5ms |
| 直连 `/products` | HIT · ≈26ms（更早一次 MISS≈2.15s + no-store） |

解读：`:19655` 与 `:9555` **不是同一 edge 层**；Worker 忙或 Process 冷时直连可秒级 MISS。公网暖 HIT **不能**证明 deferred 窗口无卡。

### 3.6 Worker CPU

- 探针突发窗：pid **2555 %CPU≈98%**（随后回落≈2%）
- 与「间歇 89–93%」一致：**同步 bag-prime / locale SSR 占满单 Worker 事件循环**（Fiber start/resume 不可抢占长同步段，见《性能诊断-20260908》）

### 3.7 代码落点（只读）

- `WlsRuntime::primeDeferredStorefrontHotCacheBags`：每次开内部 `/` 请求 + `PostResponseTaskQueue::drain`；`pre_critical` **在** critical FPC seal **之前**同步执行
- `Product\StorefrontHotCacheBagSeeder`：`pre_critical` 调 `publishedOffers(1000,true)` + `(1000,false)` + candidates —— **重投影同步 CPU**
- peer：`storefront_peer_bag_hydrate_delay_ms` 默认 **6000** + hydrate；Theme miss/scope_identity 时仍烧 CPU

探针摘要副本：`meetings/probe-burst-20260923.txt`；原始 curl 头：`/tmp/wls-perf-reg-20260923/`

---

## 4. 瓶颈排序（有证据）

| # | 瓶颈 | 证据强度 | 用户体感 |
|---|------|----------|----------|
| **B1** | **`pre_critical` 同步 heavy bag（catalog.full×1000 等）** | 强（7573ms 同 PID；历史 p50≈4s max≈24s） | reload/开工后数秒「卡死」、Worker CPU 尖峰；抢真实请求 |
| **B2** | **deferred locale FPC MISS SSR（~1MB×2）** | 强（4372+4307ms 计入 done=18.5s） | 拉长 deferred 占 Worker 窗口；期间 edge 回源变慢 |
| **B3** | **peer_hydrate 同步 CPU + 固定 6.25s delay** | 强（6052ms bag + total 12.3s；Theme miss） | Worker#2 窗口忙；#2 仍 fail-open |
| **B4** | **edge MISS/STALE/BYPASS 回源遇上 B1–B3** | 中（机制+偶发直连 2.7s；本窗暖 HIT 未再抓到 3.6s） | 「偶发几秒」 |
| **B5** | Theme bag `chrome_rendered:miss` / `scope_identity_missing` | 中（peer 必现；pre 偶发） | 重复种袋/空跑；放大 B3 |
| B6 | 假 Cookie 未复现真会话 FPC MISS≈1.3s | 弱（需登录态复测） | PM 观测保留，施工波再证 |

**非主因（本波）**：公网暖 FPC+edge 双 HIT 路径本身；R2 Host `:9555`（已正确）；Worker#2 fail-open 展示（非 owner 预期）。

---

## 5. 缓存合规检查

| 检查项 | 结论 |
|--------|------|
| CachePolicy + scope/vary/dependencies | bag 走模块 Seeder→既有 HotCache/Policy；未见本波新增平行静态袋 |
| 失效挂 owner | 沿用 Product/Theme/catalog/theme namespaces；本波未改写侧 |
| 可变 Model / 个性化 HTML / 草稿进共享池 | 未见新增；FPC 仍匿名；假 Cookie 仅 edge BYPASS |
| 业务平行进程内袋 | 未见新袋；禁区保持 |
| DB N+1 → WLS RPC N+1 | R1 批量仍在；**风险**：heavy `publishedOffers(1000)` 冷构建若内层逐键 WLS，会放大 B1（需后端对 builder 内 RPC/DB 计数插桩） |
| 预热 Host = 公网 Host | **pass**（`:9555`） |
| 合规异议 | **pre_critical 在 READY 后同步占满 Worker 事件循环**，与「deferred 不抢首请求」设计目标冲突 → **设计异议**（非否决整个 HotCache bag 机制） |

---

## 6. 与架构师共同优化方向（提案 · channel）

> 正式 `architect_joint=true` 待架构师在 `channel/perf-architect.md` reply 确认。

**共同目标热路径**：公网 `/` `/products` 暖路径稳 <50ms；deferred 窗口 **不**用多秒同步段堵住 owner Worker；peer 轻 hydrate 失败不放大 CPU。

**允许机制**：HotCache/CachePolicy；真批量 Query/MGET；owner 单 Worker 发布 Shared；Fiber **yield** 分片；PostResponse 轻触。

**禁止**：假 HIT；平行袋；跨模块直调；把 DB N+1 换成 RPC N+1；无证据宣称加速；私自 reload。

### options（≥2）

**A. 削 B1（优先）** — `pre_critical` 拆片 / 降重：  
- catalog.full 改为异步分片或 Shared 已有则 **peek-only**（禁止冷全量 1000 行同步）  
- 或把 heavy seed 移出「首请求可达」窗口（更多 yield + idle gate）  
- 保留 fail-open；禁假 HIT  

**B. 缩 B2** — fail-open 近处女窗 **暂缓 locale extras**（或降并发、降 body 路径优先 HIT 探针）：critical `/`+`/products` 先 seal；locale 放更后 idle  

**C. 修 B3/B5** — Theme peer hydrate：保证 ScopeIdentity；chrome miss→空投影短路径；评估缩短 `peer_bag_hydrate_delay_ms` **仅当** Shared 落盘证明就绪（禁盲目砍 delay）  

**D. 观测闭环** — 后端加只读 timing：bag stage 内 DB/WLS 次数；edge MISS 关联 Worker busy 标志；真登录 Cookie BYPASS FPC MISS 复现  

### recommendation

选 **A+B 为主，C 并行，D 作验收埋点**：先消 owner 上 7s+ 同步 bag 与 8s+ locale SSR，再收 peer Theme miss。  
本席 **不能**私自排施工波 → **escalate 项目经理组队**。

### suggested_seats

- Team:项目经理:（组队 / 批准受控 reload 复测）
- Team:架构师:（确认 surfaces / 禁区 / A–C 机制落点）
- Team:后端:（WlsRuntime deferred 分片 / Product bag seeder / Theme peer ScopeIdentity）
- Team:主题开发工程师:（chrome/partials bag 键与 ScopeIdentity）
- Team:性能检查工程师:（施工后 review；禁自排）

---

## 7. why_cannot_decide / escalate

本席已定位瓶颈与证据，但：

1. 修复属 Framework/Product/Theme **施工**，非本席编码范围  
2. `architect_joint` 尚待架构师 channel 确认  
3. 真会话 Cookie BYPASS FPC MISS 未本窗复现  

→ **result=escalate** · `@项目经理：请立刻组队解决`

---

## 8. related_web_urls

- https://p05113ef3.test.weline.com:9555/
- https://p05113ef3.test.weline.com:9555/products

（直连诊断：`http://p05113ef3.test.weline.com:19655/` — 非交付 Host）
