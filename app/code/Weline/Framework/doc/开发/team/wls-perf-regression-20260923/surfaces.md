# surfaces — wls-perf-regression-20260923

team: `wls-perf-regression-20260923`  
updated: 2026-09-23 · from:架构师 · **architect_joint=true**（msg-5 A–C · msg-20 P7 B′ · **msg-29 P8 O1/O2/O3**）  
选型权威：`Framework/doc/3-开发/扩展点选型.md`  
缓存权威：`Framework/doc/统一缓存范围与性能优化.md`  
继承：`framework-perf-baseline-20260922`（R1 批量 / R2 deferred+FPC / 8a2 固化壳 / 8c 种袋辅）  
性能证据：`meetings/性能检查-design.md` §3–§6 · P6 review · P7 B′ 机制 pass / 绝对值 fail（pid **74859** done=10261.6）· `meetings/性能检查-review-p7.md`  
P7 纪要：`meetings/架构-p7.md` · **P8 纪要：`meetings/架构-p8.md`**

本波**不新建**跨模块 Event/Query；禁止跨模块直调 Service/Model；禁止业务平行进程内袋；禁止架构师单方定「加缓存」。

---

## 机制面映射（现有 · 只读核对）

| 面 | 机制（选型表） | Owner / 落点 | 本波用途（冻结后） | 禁止 |
|----|----------------|--------------|--------------------|------|
| R1 批量缓存传输 | **Interface** | `BatchCacheAdapterInterface` ← `WlsMemoryAdapter`；`CachePool` | 保持真 MGET/MSET；D 埋点计 RPC | 伪批量；旁路 Memory facade |
| R2 owner deferred FPC | **Interface** + Runtime | `FpcWarmupProviderInterface`；`WlsRuntime::runDeferredStorefrontCriticalWarmup` | critical 仅 `/`+`/products`；**B′** 默认不跑多语全量 SSR；**O1** skip 后禁同成本 post_locale | 假 HIT；关整个 deferred；严格 READY 默认改回 |
| R2b HotCache 种袋 | **Interface**（capability） | Theme/Product `StorefrontHotCacheBagWarmupProvider` + Seeder；`primeDeferred…` stages | **A**：pre_critical 轻袋/peek；禁冷 `publishedOffers(1000)`；**O1** post_locale 可 skip/peek/noop | 种袋代 8a2 壳；三阶段重跑 catalog.full；locale skip 后仍全量 post_locale |
| R2c peer hydrate | Runtime | `runDeferredStorefrontPeerHotCacheBagHydrate` | **C**：ScopeIdentity + 空投影短路径；delay 仅 Shared 就绪后可调 | 无证据砍 delay→0；空 stage→heavy |
| R2d Nginx edge | Server Edge | `ManagedNginxConfigWriter` `wls_edge` \|`fpc2` | D：双头证据；B4 机制保留 | 混 Host；edge STALE 当进程未暖 |
| R3 固化壳（继承硬） | bake 直读主 | Theme published shell | **不回退** | SlotFiller/injectChrome 店面再生 |

---

## 业务特性摘要

| 项 | 结论 |
|----|------|
| 面 | 店面公共 `/`+`/products`；owner deferred + peer hydrate |
| 热路径目标 | 暖 HIT 稳 &lt;50ms；deferred **必跑墙钟** `done≤5000`（推荐 ≤3000）；禁伪加速比 |
| 痛点（P7） | `locale_idle` 串行多语冷 SSR（预算=4）占满 `done≈20s` → **B′ 已切除** |
| 痛点（P8） | B′ skip 后仍无条件 `post_locale` 全量 bag≈3875 + chrome miss 贯穿 + products 首 MISS → **编排/种袋续缺陷** |

---

## 共同定制优化方向（**冻结** · A+B′+**O1** 主 / O2 并行框 / O3 次 / C·D 继承）

### A — 削 B1（硬 · 已施工）

| | |
|--|--|
| **同意** | `pre_critical` **禁止**冷同步 `publishedOffers(1000)`（含双扫 + heavy candidates 冷重建） |
| **允许** | Shared peek-only；Fiber yield 分片；PostResponse / idle 轻触；轻袋 summary≤48 + Theme chrome/header |
| **归属** | 后端 · Product Seeder + 可选 Runtime 编排 |
| **扩展点** | 既有 BagWarmup **Interface**；不新建 Event |

### B — 缩 B2 critical 窗（有界 · 保留）

| | |
|--|--|
| **同意** | fail-open / `needsCriticalPrime` 近处女窗 `localeBudget=0`（仅 `/`+`/products` seal） |
| **裁冲突** | `FpcWarmupProvider` 仍可贡献 locale paths → `locale_deferred_paths` |
| **否决** | 永久 `max_paths=1` 挤掉 `/products`；假 HIT 冒充 locale 暖 |
| **归属** | 后端 · `WlsRuntime` |

### B′ — P7 绝对墙钟（硬 · **本波新增**）

| | |
|--|--|
| **裁定** | 默认 owner deferred **不得**把多语全量页面 HTML SSR 算进**必跑墙钟** |
| **默认** | `locale_idle` 全量 SSR **budget = 0**（env `wls.worker.storefront_locale_idle_budget` 默认 `0`；与 `max_paths−critical` **脱钩**） |
| **允许** | 可选 **cheap probe**（轻量探测、禁全页 body SSR）须独立开关；显式 env&gt;0 才允许有界全量 locale SSR（ops 自担墙钟） |
| **Provider** | 仍可声明路径；默认**不执行**全量 SSR；记日志 `locale_idle_skipped` + `deferred_count` |
| **必跑口径** | `pre_critical` + critical `/`+`/products` + `post_critical_heavy`（+idle-gate）；**不含**多语全量 SSR |
| **绝对目标** | `done≤5000`（推荐 ≤3000）；复审看绝对值，禁伪加速比 |
| **否决** | 关整个 deferred；假 HIT；平行袋；「移到 idle 即合格」 |
| **归属** | 后端 · `WlsRuntime`；纪要 `meetings/架构-p7.md` |
| **状态** | 机制 **pass**（pid 74859）；绝对值 **fail** → 见 **O1** |

### O1 — P8 绝对墙钟（硬 · **本波主线**）

| | |
|--|--|
| **裁定** | `locale_idle_skipped`（或本轮**未跑** locale SSR）后 **不得**默认同成本再跑 `primeDeferredStorefrontHotCacheBags('post_locale')` 全量 bag |
| **允许** | **skip** / **peek-only** / **no-op stage** 日志（如 `post_locale_skipped`，reason=`locale_idle_skipped`） |
| **仍可全量 post_locale** | 仅当本轮**实际跑过** locale FPC SSR 后的有界 retouch（抗 eviction）；禁假 HIT |
| **代码锚点** | `WlsRuntime.php` ≈1562（B′ skip 分支后无条件 post_locale） |
| **绝对目标** | 继承：`done≤5000`（推荐 ≤3000）；禁伪加速比 |
| **否决** | 关整个 deferred；假 HIT；平行袋；伪加速比；私自 reload |
| **归属** | **后端** · `WlsRuntime`；纪要 `meetings/架构-p8.md` |

### O2 — chrome_rendered:miss 根治边界（框 · 并行可选）

| | |
|--|--|
| **目标** | 修 bag 命中/代次/scope/Shared 就绪；消除贯穿 pre/critical/heavy/post_locale 的 `chrome_rendered:miss` |
| **硬禁** | **禁拆壳**（固化壳主链、header/footer、必装 widget）；主题席底线优先于性能药方 |
| **归属** | **主题**为主（`theme_module_runtime` / frontend）；后端仅编排协作 |
| **与 O1** | 可并行；**不**阻塞 O1；主题可 waiting_peer |

### O3 — `/products` seal 首 MISS（次优先）

| | |
|--|--|
| **现象** | critical_sealed 内 `/products` 首刷 MISS≈1240 |
| **归属** | 后端（可协调 Product）；**次于 O1**；单独不够 ≤5s |

### C — 修 B3/B5（同意 + SLA 框 · 已施工）

| | |
|--|--|
| **空投影 `[]`** | **合规**：诚实 miss 标记；**非** FPC HIT |
| **ScopeIdentity** | peer 须冻店面身份；miss→fail-open 短路径 |
| **delay SLA** | 协调窗非旋钮；有 Shared 就绪证据才可降；默认可留 6000 至探针落地；有信号后目标 **≤1500ms**；禁无证据→0；上限 20s 保留；**复审以性能席为准** |
| **归属** | 主题（chrome/partials）+ 后端（Runtime delay/编排） |

### D — 验收埋点

bag stage 内 DB/WLS 计数；edge MISS↔Worker busy；真登录 BYPASS 复测。禁私自 reload。

### 禁止项（双方硬）

平行 static；假 HIT；删依赖保 HIT；种袋代 8a2；跨模块直调；单方「加缓存」；跨样本伪加速；**关整个 deferred**。

### 验收证据口径

| 指标 | 口径 |
|------|------|
| 暖 cookieless | FPC HIT + edge HIT，TTFB 稳 &lt;50ms |
| deferred 必跑 | stage 拆分；默认 `locale_idle_budget=0`；`done` paths 不含多语全量 SSR；**locale skip 后无同成本 post_locale**；`done≤5000`（推荐 ≤3000） |
| peer | delay vs CPU 分报；空投影≠HIT |
| 达标 | **Team:性能检查工程师: review pass**（绝对墙钟；禁伪加速比） |

---

## escalate 门槛（仍有效）

关 deferred 种袋却要求 fail-open 首 HIT，或回退固化壳主链 / 拆壳药方 → **停工 escalate**。  
P7 B′ / **P8 O1** 与性能席同向 → 联合方向 **closed**（无 escalate）。
