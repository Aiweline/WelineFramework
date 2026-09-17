---
status: ready-for-plan
work_kind: feature
feature_slug: fiber-task-batch-collectors
module: Weline_Framework
updated: 2026-09-15
---

# Fiber 批处理调度器与收集点接入

## 澄清结论（本回合默认锁定）

| 点 | 决定 |
|----|------|
| 核心调度器 | 沿用并强化 `Weline\Framework\Php\FiberTaskRunner`（不另造 Server 侧 `FiberScheduler`） |
| 批处理门面 | 新增 `FiberTaskBatch`：`runEvents` + 主线程进度 + env 并发，供维护页/404/ACL/路由收集复用 |
| ACL | `ControllerAttributes` 按模块任务 Fiber 收集；模块内不 yield，避免共享 pending 竞态；进度仅主线程 |
| 路由扫描 | `setup:upgrade` 按模块 `registerRoute` Fiber 批处理；模块内不 yield |
| 并发默认 | `WELINE_FIBER_CONCURRENCY`（缺省 4）；ACL 可用 `WELINE_ACL_FIBER_CONCURRENCY`；路由可用 `WELINE_ROUTE_FIBER_CONCURRENCY` |
| 非目标 | 不宣称多核加速；不把 upsert/写路由文件放进并发 Fiber；不改 WLS Worker `FiberScheduler` |

## 用户故事

作为框架维护者，我希望所有「多单元收集/发布」共用核心 Fiber 批处理 API，以便 ACL、路由扫描、静态错误页等以同一模式协作切换，并在 CLI 主线程打印进度。

## EARS

1. WHEN 调用方提交多个独立 callable，系统 SHALL 通过 `FiberTaskRunner`/`FiberTaskBatch` 以可配置并发协作调度，且进度回调仅在主 Fiber 执行。
2. WHEN PHP 无 Fiber 或并发≤1，系统 SHALL 串行回退且结果协议与并发分支一致。
3. WHEN ACL 按模块 Fiber 收集，系统 SHALL 在模块任务边界 yield，模块内部收集不 yield，收集结束后仍一次批量 upsert。
4. WHEN 路由按模块 Fiber 扫描，系统 SHALL 在 `registerRoute` 任务边界 yield，不在 Helper 共享缓冲写入途中切换。
5. IF 任务抛错，`FiberTaskBatch` SHALL 按 `fail_fast` 立即终止或汇总为 failed 列表（调用方选定）。
6. WHEN 文档/注释描述本能力，系统 SHALL 明确「协作非多核」。

## 用例

### UC1 主成功：ACL 多模块 Fiber 收集

1. defer 派发多模块 controller_attributes 事件。
2. 观察者按 module 建任务，`FiberTaskBatch::settle`。
3. 每任务：yield → `processModuleControllerAttributes` → yield → 返回。
4. 主线程进度 note；全部完成后 `flushAllPendingAclsBatched`；`releaseWorkingMemory`。

### UC2 主成功：路由扫描批处理

1. upgrade 进入路由收集。
2. 每模块一个 Fiber 任务调用 `registerRoute`。
3. 主线程聚合失败；任一路由失败仍可 fail_fast 回滚阶段。

### UC3 异常：任务拒绝

1. 某模块收集抛异常。
2. fail_fast=true → settle 抛出；fail_fast=false → 记入 failed，其它模块继续。

## 验收

- 单元：`FiberTaskBatch` 进度主线程、并发切换、串行回退。
- 契约：`ControllerAttributes` / Upgrade 路由收集含 `FiberTaskBatch`/`FiberTaskRunner`。
- 既有 `FiberTaskRunner*`、`ControllerAttributesTest`、`RouteUpdateBatchDeferContractTest` 通过。
