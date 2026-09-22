## msg-1 | 2026-09-22T11:43:00+08:00 | from:性能检查工程师 | to:项目经理 | thread:escalate-to-pm | kind:escalate

@项目经理：请立刻组队解决

result: escalate
verdict: fail
why_cannot_decide: 本席只做检查与方向冻结，不代排施工；机制返工跨 Framework CachePool / WLS FPC / Theme HotCache / Search / ACL，须项目经理分诊组队。

### 根因（给项目经理）

| ID | 根因 | 证据 | 为何导致 fail |
|----|------|------|----------------|
| R1 | **`CachePool::getMultiple` / `setMultiple` 仍是逐键 `foreach`→`get`/`set`**，不是 Adapter 真批量（MGET/MSET） | `app/code/Weline/Framework/Cache/Pool/CachePool.php` L306–328 | 业务侧「批量预取」仍打成 N 次 WLS RPC；文档曾写「已优先批量驱动」与实现不一致 → DB N+1 易被换成 RPC N+1 |
| R2 | **店面冷路径首请求未命中 FPC/预热**：Worker 默认 `ready_gate_homepage_fail_open=1` → `homepage-fpc:deferred-after-ready:fail-open`，warmup `hit=false` | Worker 状态 + `WlsRuntime.php` 注释/实现；本机 HTTPS 首页冷 TTFB≈**2.356s**、暖≈**0.014s**；列表冷≈**2.299s**、暖≈**0.009s** | 暖路径证明共享缓存有效；**公网/进程首击仍全量 SSR**，冷启动目标未达 |
| R3 | **冷构建瀑布未拆完**：Header / Slot / 目录投影 / i18n 等同请求串行重建；HTML 体积大（首页≈1.29MB、列表≈2.14MB） | `meetings/性能检查-design.md`；历史 `性能诊断-20260908.md` | 即使部分 HIT，未预热路径仍秒级；体积放大解析成本 |
| R4 | **已知 backlog 未关**（非本波唯一根因，但是放大器） | 同纪要 | Search **direct** 默认/改一品扫整站风险；后台 ACL 多别名慢；WLS Fiber 长同步段；Theme 已发布布局/Slot 未完整挂 HotCache；I18n 全清广播 |

### 已冻结方向（架构师 stance=同意 · channel/perf-architect.md）

1. 真批量入口（prefetchWords / 真 MGET·MSET；禁 DB N+1→RPC N+1）
2. 商品/目录事实与聚合分层
3. Theme 已发布布局/Slot 进 HotCache（替换空 stub）
4. I18n 定向失效
5. Search 读模型收口 + 修正 FPC/warmup 使首请求可 HIT

### options（≥2）

A. **先修 R1+R2（机制最短路径）**：后端改 CachePool 真批量；架构师+后端收口 homepage fail-open/deferred 策略，使 `/` 与关键路径可 HIT 首击。  
B. **先修 R3 Theme/目录投影 HotCache**：主题席 + 后端，把已发布 Slot/布局进 Policy；冷瀑布变短后再管 FPC。  
C. **并行 A 为主、B 为辅**：R1/R2 本波闭环；R3 开并行通道；R4 入下一波。

### recommendation

选 **C**：本波立刻拉 **架构师 + 后端** 闭环 R1+R2；并行拉 **主题** 启动 R3（已发布布局/Slot HotCache，禁草稿进池）；R4 由项目经理排下一波（Search/ACL/Fiber）。

### suggested_seats

- Team:架构师:（R2 策略表态 + surfaces/禁区）
- Team:后端:（R1 CachePool 真批量 + R2 WLS/FPC 预热收口；Framework 升版）
- Team:主题:（R3 已发布布局/Slot → HotCache）
- Team:性能检查工程师:（施工后 `性能检查-review.md` 复测，禁自排施工）

证据纪要：`meetings/性能检查-design.md`  
联合冻结：`channel/perf-architect.md`
