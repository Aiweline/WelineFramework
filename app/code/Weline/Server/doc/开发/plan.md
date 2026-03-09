# Weline_Server 验证测试与问题即修复计划

**状态**：🟡 进行中（status: in_progress）  
**当前阶段**：阶段 7 请求时延优化（2026-03-09）  
**完成度**：25%（2.5/10）  
**最后更新**：2026-03-09

---

## 一、范围与目标

### 1.1 验证范围

- 控制面：`ServerManager` 的 start/stop/restart/reload/maintenance
- 状态面：`BackendStatusService` 输出的统一状态 DTO
- 压测面：`ServerBenchmarkService` 的触发、结果落库与查询
- 流量面：`telemetry` 上报、内存聚合、按 Host 分组查询
- 数据面：周期性落库、幂等 upsert、失败重试
- 前端面：监控页与管理页交互完整性

### 1.2 当前已知问题（计划前分析结论）

1. `setup:upgrade` 已成功执行，但 HTTPS 主入口 `https://127.0.0.1:9981/` 出现连接 reset。
2. 因 TLS 阻断，后台相关接口验证被阻塞，需先恢复可测链路。
3. 需采用“验证 -> 定位 -> 修复 -> 回归”的闭环，避免仅停留在排查结论。

---

## 二、决策自审（强制）

### 决策 A：先设验证门禁，再跑功能验证

| 问题 | 分析 |
|------|------|
| 为什么这么做？ | 当前存在 TLS 阻塞，直接测功能会产生大量伪失败。 |
| 收益 | 节省排查时间，确保失败项都是业务问题而非环境问题。 |
| 缺陷/风险 | 先期耗时增加，但可显著降低返工。 |
| 影响范围 | 验证流程、执行顺序、问题归因方式。 |
| 关联模块 | `Weline_Server`、`Weline_Framework`（网络/请求链路相关）。 |
| 应对方案 | 设立阶段 0 门禁：升级、进程、连通性三项必须通过。 |
| 安全隐患 | 无新增安全风险。 |
| 命中技能 | `quality-assurance`、`weline-server`、`http-request-testing`。 |

### 决策 B：遇到问题直接修复并立即回归

| 问题 | 分析 |
|------|------|
| 为什么这么做？ | 你要求“遇到问题直接修复”，且该需求适合迭代闭环。 |
| 收益 | 快速收敛，减少“问题堆积后统一修”的风险。 |
| 缺陷/风险 | 可能引入连锁影响。 |
| 影响范围 | `worker_ssl.php`、控制器、服务层、模板层。 |
| 关联模块 | 以 `Weline_Server` 为主，必要时触及 `Weline_Framework`。 |
| 应对方案 | 每次修复后执行最小回归 + 关键路径回归。 |
| 安全隐患 | 重点防止临时绕过校验、误放开权限。 |
| 命中技能 | `quality-assurance`、`php-unit-testing`、`weline-server`。 |

---

## 三、命中技能（开发与验证时必须参考）

| 技能 | 路径 | 用途 |
|------|------|------|
| module-development | `dev/ai/skills/module-development/SKILL.md` | 升级与模块级验证流程 |
| quality-assurance | `dev/ai/skills/quality-assurance/SKILL.md` | 验证闭环与通过标准 |
| php-unit-testing | `dev/ai/skills/php-unit-testing/SKILL.md` | 单测/命令验证优先级 |
| weline-server | `dev/ai/skills/weline-server/SKILL.md` | WLS 进程与 reload/restart 规范 |
| http-request-testing | `dev/ai/skills/http-request-testing/SKILL.md` | 接口可达性与响应验证 |

---

## 四、执行阶段与通过标准

### 阶段 0：门禁检查（必须先过）

1. 执行 `setup:upgrade`
2. 执行 `server:status`
3. 检查 `http://127.0.0.1:9980` 与 `https://127.0.0.1:9981`

**通过标准**：升级成功、进程齐全、主入口可请求。  
**失败处理**：立即进入“阻塞修复流程（阶段 0.5）”。

### 阶段 0.5：阻塞修复流程（问题即修复）

1. 收集失败证据（命令输出/日志）
2. 定位根因（SSL、握手、路由、进程）
3. 直接修复代码
4. 执行最小回归：根路径 + status 接口
5. 恢复后继续阶段 1

### 阶段 1：控制面验证

- 验证 `start/stop/restart/reload/maintenance-enable/maintenance-disable`
- 每个动作后核对实例状态一致性

**通过标准**：动作返回正确，状态变化符合预期。

### 阶段 2：状态 DTO 验证

- 验证 orchestrator 运行快照
- 验证 worker health 明细数据

**通过标准**：DTO 结构完整且字段语义正确。

### 阶段 3：Telemetry 与 Host 分组验证

- 造多 Host 流量
- 查询全局与 host 分组

**通过标准**：`request_count` 递增，Host 分组准确。

### 阶段 4：周期落库验证

- 等待 flush 周期
- 查询历史数据与幂等性

**通过标准**：有历史桶数据、无异常重复累加、重试可恢复。

### 阶段 5：压测链路验证

- 触发压测
- 查询列表与详情
- 验证结果持久化

**通过标准**：可执行、可回看、指标字段完整。

### 阶段 6：UI 交互验证

- 管理页按钮闭环
- 监控页流量与压测显示
- 交互组件规范（非原生 alert/confirm/prompt）

**通过标准**：可用性正常，提示友好且可操作。

---

## 五、风险与修复策略

### 🔴 必须处理

1. HTTPS 主入口 reset 导致全链路验证阻断。
2. 压测失败率 100% 的根因未闭环前，不得判定功能失败。

### 🟡 建议处理

1. 增加验证脚本化顺序，固定复现步骤。
2. 对关键接口建立回归清单，避免修复时回归遗漏。

### 🟢 可选优化

1. 增加更多 host/异常码样本进行 telemetry 精度验证。
2. 增加 UI 自动化回归覆盖关键按钮链路。

---

## 六、交付物

1. 验证报告（阶段结果：PASS/FAIL/BLOCKED）
2. 问题清单（根因、修复点、回归结果）
3. 最终结论（可上线/需继续修复）

---

## 七、进度跟踪

执行详情与勾选项见：[`task.md`](./task.md)

---

## 八、2026-03-09 请求时延优化增量计划

### 8.1 决策 C：优先做低风险高收益优化，暂缓 Linux 直连模式

| 问题 | 分析 |
|------|------|
| 为什么这么做？ | 当前 Linux 下强制走 `Dispatcher` 有规则链路依赖，直接切直连需要把 Dispatcher 能力下放到 Worker，改动面过大。 |
| 收益 | 先落地 `Session` 延迟写、`Cache` 协议轻量化、`WlsRuntime` 日志开关，可更快看到时延下降。 |
| 缺陷/风险 | 不能一次解决 Dispatcher 固定成本。 |
| 影响范围 | `Weline_Framework::Session`、`Weline_Server::Shared/Session/Runtime`。 |
| 关联模块 | `Weline_Server`、`Weline_Framework`。 |
| 应对方案 | 将 Linux 直连模式列为后续专项，先在本轮完成协议层和请求末尾写入优化。 |
| 安全隐患 | Session 必须保证请求末尾强制写入，不能因延迟写导致登录态丢失。 |
| 命中技能 | `create-plan`、`session-development`、`cache-usage`、`weline-server`、`quality-assurance`。 |

### 8.2 本轮优化范围

1. `Session` 写入从“每次变更立即 RPC”改为“请求结束统一保存”。
2. `SharedMemoryService` 的 `exists()/touch()/mget()/mset()` 改为协议原生命令，避免 `get()+set()` 放大。
3. `WlsRuntime` 增加可配置的性能日志/请求日志/错误日志开关，便于量化日志开销。
4. Linux 直连模式记入后续计划，本轮不实施。

### 8.3 后续专项（暂缓）

1. Linux 直连模式：需要把 Dispatcher 的规则生效能力下放到 Worker，再评估切换路径。
2. 请求链固定成本拆账：继续分析 `run_before`、`Router::__init()`、`router->start()`、`StateManager::reset()` 的占比并做定点优化。
