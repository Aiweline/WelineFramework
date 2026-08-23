# WLS Fiber 并发与维护模式 — 测试设计方案

> 目的：验证「目标 Fiber 投影 + 入口基线 + 挂起 Fiber 时 `StateManager::reset($omit)` + 失败 Worker 隔离」在 **维护模式**、**SSE 挂起 + 并发短请求**、**登录态 / 后台** 下无串味、无死锁；为 **omit 白名单** 变更提供回归门槛，并验证 **P3 SessionFactory 分桶**。

**执行状态（2026-08-09）**：P3 功能隔离及本机真实 WLS 有界并发已 `COMPLETED/ACCEPTED`；30 分钟阶梯混合长稳态和至少 100 万请求仍属于极致性能总计划的 `PENDING` 验收。

---

## 1. 测试分层总览

| 层级 | 工具 | 覆盖重点 | 门禁 |
|------|------|----------|------|
| L1 单元 | PHPUnit | 白名单与基线方法、omit 行为、小粒度假设 | CI 必过 |
| L2 集成 | PHPUnit + 本机 `php bin/w server:start` | 同进程多连接顺序/交错（无浏览器） | 合并前 |
| L3 E2E | Playwright（已有 `tests/e2e`） | 真实浏览器 Cookie、EventSource、后台 UI | 发版前 / 夜间 |
| L4 手工 | 清单 + `/_wls/health` | 难自动化边界、运维场景 | 大改 WLS 时 |

### 1.1 P1B-003 冻结并发契约

1. **只恢复目标 Fiber 的进程投影**：`WlsFiberContext::captureForFiber()` 只能捕获已挂起且拥有独立 `Context` 的请求 Fiber；`restoreForFiber()` 必须校验目标 Fiber，仅恢复超全局变量、SSE 兼容投影、URL parser scratch 与已注册静态请求状态快照。目标 Fiber 自己的 `Context`、`HeaderCollector` 和 ObjectManager 请求作用域由 Fiber-local 存储持有，恢复时不得借道事件循环主 Fiber，也不得覆盖主 Fiber 状态。
2. **已注册静态请求状态随 Fiber 快照**：`StateManager::captureRegisteredStaticState()` 捕获当前已加载、已注册的请求级静态属性；同伴请求可以在自己的边界执行全量静态 reset，原 Fiber 恢复前再通过 `restoreRegisteredStaticState()` 恢复自己的投影。捕获或恢复失败不是可忽略告警，必须进入失败隔离。
3. **omit 只针对有入口等价清理的已注册回调**：当前白名单仅允许 `request_scoped_objects`、`state_instance`、`router_core_instance`、`controller_instances`、`model_instances`、`observer_instances`、`message_manager_request_state`、`events_manager_observer_cache`、`view_hook_runtime_cache`、`process_url_cache_static`。模块 RequestResetter、`session_instances`、`session_shutdown_queue`、`sse_context`、`request_context`、`db_connection_cleanup`、`request_instance`、`template_instance` 永远不得省略。
4. **所有 reset step 都要尝试**：`StateManager` 的静态请求状态、单例、每个命名回调、ObjectManager 请求作用域和 HeaderCollector；`ModuleRequestResetterRegistry` 的每个 provider；`RequestContext` 的每个 cleanup callback 与投影清理；`WlsRuntime` 的 Session flush、响应快照、namespace、trace、runtime reset、output、globals、Context leave 和请求阶段释放，均使用独立保护。前一步失败不得阻止后续清理，最终用 `RequestResetException` 汇总阶段与原异常。
5. **边界失败即隔离排空**：入口 reset、请求 finalization、Fiber 初始捕获/恢复、调度恢复或取消 unwind 任一步失败，都必须请求 `request_*_failure` Worker drain。当前连接按失败类型关闭或返回通用 500，Worker 不再接收新请求并在当前响应后退出，由 Master 补充新 Worker；不得让可能污染的 Worker 继续复用。

上述五条是实现和测试的共同门禁；白名单项、投影字段或失败阶段变化时，必须同时更新 L1/L2 验证与本节。

---

## 2. 环境与前置条件

- **实例**：先选择实际未占用的整数端口 `>=9502`，再执行 `php bin/w server:start ai-test-fiber-{id} -p {integer-port}`，**禁止**默认 9501。
- **模式**：至少一轮在 `deploy=dev` 下跑（便于 `w_log_debug` / fiber 计数日志）。
- **Worker**：与线上一致的前端/SSL Worker；Dispatcher 与维护 Worker 路由逻辑需与当前 Master 配置一致。
- **数据**：固定测试账号（后台）、可选独立 `website_id`，避免污染生产数据。

**可观测性（所有场景通用）**

- `GET /_wls/health?detail=1&fibers=1`（若已启用）：挂起 Fiber 数量、Worker 状态。
- 日志关键字：`[WlsRuntime] request ended with other suspended fibers=`、`concurrent suspended`。
- 浏览器：DevTools Network 中 SSE 连接保持 `pending`，短请求为 200/302。

---

## 3. 场景矩阵（必测）

### S1 — 纯短请求回归（基线）

| 步骤 | 预期 |
|------|------|
| 连续 20 次 GET 首页 / API | 无 500；无串用户/串站点 |
| 同一浏览器登录后台再刷新 10 次 | Session 一致；无登出 |

**自动化**：Playwright `test.describe.serial` 或循环 `page.goto`；断言响应头 Set-Cookie 行为符合预期。

---

### S2 — SSE 挂起 + 并发短请求（核心）

| 步骤 | 预期 |
|------|------|
| 打开 `EventSource`（或 `fetch` stream）连到已知 SSE 路由，保持不断开 | 连接保持；偶发 `yield` 后仍能收事件 |
| **不关闭 SSE**，同一站点另开标签或使用 `Promise.all` 并发 5～10 个普通 GET/POST（含一次需登录的 API） | 短请求全部成功；SSE 不断流、不 502 |
| 关闭 SSE 后再发短请求 | 行为与 S1 一致 |

**自动化草案（Playwright）**

```text
1. pageA.goto(baseUrl)
2. pageA.evaluate(() => { window.__es = new EventSource('/path/to/sse'); window.__chunks = []; window.__es.onmessage = e => window.__chunks.push(e.data); })
3. await page.waitForTimeout(500)
4. 并发：Promise.all([fetch('/'), fetch('/api/...'), ...]) 可在 pageA.evaluate 内执行
5. 断言 window.__chunks.length 随时间增长（或轮询 health）
6. pageA.evaluate(() => { window.__es.close(); })
```

**集成草案（PHPUnit，可选）**

- 若仓库已有「同进程多请求」客户端：开一个长连接客户端 + `http:request` 短请求；断言长连接未被动关闭。
- 否则本场景以 **L3 E2E** 为主。

**失败判据（触发排查 omit / 基线）**

- 短请求拿到上一用户的 JSON / 后台菜单。
- SSE 突然结束且服务端无主动 `close`。
- `health` 中 fiber 数异常飙高且不回落。

---

### S3 — 登录态与后台页

| 步骤 | 预期 |
|------|------|
| 登录后台，打开列表页（DataTable）再打开带 Widget 的编辑页 | 标题/站点/权限与当前用户一致 |
| 在 S2 并发进行时（若可脚本化），后台再点一次「保存」或刷新列表 | 无「无效请求方法」、无旧 `request_id` 冲突日志 |

**自动化**：Playwright storageState 保存登录；在 S2 前后各跑一遍后台关键路径 smoke（3～5 个 URL）。

---

### S4 — 维护模式

| 步骤 | 预期 |
|------|------|
| 开启维护（与现网一致的方式：env / 管理开关 / 命令） | 普通 Worker 池返回维护页或 503；**维护 Worker** 可访问（若产品设计如此） |
| 关闭维护 | 流量回到正常 Worker；Session 不因切换异常登出（在「同 Cookie 域」下验证） |

**注意**：维护逻辑与 Dispatcher 路由、PassthroughCore 维护 fallback 强相关；本方案要求 **至少一条 E2E** 覆盖「开维护 → 访问 → 关维护 → 再访问」。

**失败判据**

- 维护开启后仍大量打到非维护 Worker且无维护页。
- 关闭维护后长时间 503（Dispatcher 仍指错池）。

---

### S5 — omit 白名单专项（回归 `WlsConcurrency::callbackNamesOmittableWithPeerFibers`）

| 步骤 | 预期 |
|------|------|
| 在 **DEV** 下强制 `getOtherSuspendedRequestFiberCount() > 0`（自然触发：S2） | 日志出现并发挂起提示；无异常栈 |
| 临时从白名单移除 **单条** 回调名（本地分支） | 重跑 S2/S3；若问题消失，则该条 **永久移出白名单** 并补 **L1 或 L2** 用例锁定行为 |

**L1 用例建议（ PHPUnit ）**

- 已有：`WlsConcurrencyTest` 锁定精确白名单并验证每项均已注册；`StateManagerPersistentEntryBaselineTest` 验证入口基线覆盖与关键回调不省略；`StateManagerResetOmitTest` 验证按名 omit 行为。
- 已有：`StateManagerPeerOmitIntegrationTest` 调用真实 `StateManager::reset($omit)`，验证 RequestContext 与 SSE 仍执行清理，并锁定模块 resetter、Session、DB、Request、Template 等关键回调不在白名单。

### S6 — reset 失败聚合与 Worker 隔离

| 步骤 | 预期 |
|------|------|
| 注册一个故意抛错的 reset provider/callback，并在它之后注册成功探针 | 抛错步骤与后续探针都被调用；异常包含边界名与全部失败 stage |
| 通过独立 WLS 测试实例触发一次真实请求边界失败 | 当前连接关闭或得到通用 500；日志记录 `request_*_failure`；旧 Worker PID 进入 drain 并退出 |
| 等待 Master 补 Worker 后发新请求 | 新 Worker PID 响应正常；无旧请求的 Session、SSE、RequestContext、模板或静态请求状态残留 |

**失败判据**

- 第一个 reset 异常令后续清理步骤未执行。
- 失败被日志吞掉，Worker 继续接受新请求。
- drain 后旧 PID 仍处理新连接，或新 Worker 恢复了事件循环主 Fiber 的请求状态。

---

## 4. P3 SessionFactory 按 Fiber 分桶 — 实现与验收

**功能验收**

- 同 Worker 内：Fiber A 挂起时，Fiber B `session_start` / 读登录态 **不覆盖** A 的工厂缓存槽位。
- Fiber A 结束后仅回收 A 的桶（或 WeakMap 随 Fiber GC）。

**完成证据（2026-08-09）**

- `SessionFiberRequestIsolationTest`：6 tests / 20 assertions，覆盖 Session/AuthSession 交错、局部 reset/flush、目标清理、WeakMap GC 和 1000 次循环清桶。
- 最终 Runtime/Session 回归：主矩阵 82 tests / 327 assertions；旧 Session 路径 32 tests / 53 assertions。
- 独立 TLS WLS `ai-test-fiber-session-accept-20260809`、端口 19685、单 Worker：实际 Fiber 路径 2000/2000 成功，错误 0。
- SSE 保持期间短请求 200/200 成功，SSE 的 10 条 progress 与 done 全部到达。
- 固定 A/B Session cookie 累计 2200 请求，各自 `form_key` 始终稳定且互异，`mismatch=0`。
- 两阶段各 1000 个 Session 请求后，Session Server RSS 保持 43200 KiB；Worker RSS 从 164976 KiB 回落到 163472 KiB，未出现线性爬升。

**压测指标（建议 JMeter / wrk / k6 或本机脚本）**

- 并发连接数：N = 50 / 200 / 500（阶梯）。
- 混合比例：80% 短请求 + 20% 长连接（SSE）。
- 观测：进程 RSS、GC 次数、`/_wls/health`、p99 延迟、错误率。
- 通过标准：错误率 &lt; 0.1%，无 Session 串用户报告，内存无持续线性爬升（30min 窗口）。

**剩余性能门槛**：N=50/200/500、20% SSE、30 分钟窗口及至少 100 万请求尚未执行；这些项目继续作为极致性能总计划的性能验收，不否定已通过的 P3 隔离功能验收。

---

## 5. CI 建议集成方式

1. **L1**：`php bin/w phpunit:run --module=Weline_Framework` 或指定 `Test/Unit/Runtime/*Fiber*`、`StateManager*`。
2. **L3**：`tests/e2e` 新增 `wls-fiber-sse-concurrency.spec.js`（或并入现有 backend workbench spec），标记 `slow`，默认 CI 可选 job `e2e:wls`。
3. **维护模式 E2E**：依赖可编程开关（API 或 seed env）；若无则仅 **L4 手工** + 文档记录。

---

## 6. 缺陷分级与回滚

| 级别 | 现象 | 动作 |
|------|------|------|
| P0 | 跨用户数据泄露、任意账户后台 | 立即关闭 `omit` 路径（`WlsRuntime::reset` 强制 `StateManager::reset(null)`），热修 |
| P1 | SSE 大面积断流、维护模式错乱 | 缩小白名单或回滚入口基线相关提交 |
| P2 | 偶发标题/模板串页 | 单条 omit 移除 + 补用例 |

---

## 7. 交付物清单（建议排期）

| 交付物 | 类型 | 优先级 | 状态 |
|--------|------|--------|------|
| `app/code/Weline/Framework/test/e2e/wls/wls-fiber-sse-concurrency.spec.js` | Playwright | P1 | 已加（`WLS_FIBER_SSE_E2E=1`；后台 `Weline_Server/sse-test`；`useProxy: false`；本地 `retries:1`） |
| 系统页弱回归（Backend/Server 多路由容错） | E2E | P1 | 已加（同上开关） |
| `app/code/Weline/Framework/Test/Integration/Runtime/StateManagerPeerOmitIntegrationTest.php` | PHPUnit | P2 | 已加 |
| `app/code/Weline/Framework/Test/Unit/Session/SessionFiberRequestIsolationTest.php` | PHPUnit | P3 | 已加并通过 |
| `迁移前历史任务记录（已清理）` | Live harness | P3 | 已加；双 Session 2200 请求 `mismatch=0` |
| P3 有界真实 WLS 报告 | 运维 | P3 | 已完成并写入任务账本 |
| 30 分钟阶梯混合长稳态 + 至少 100 万请求报告 | 运维 | 总计划 | 待完成 |

### 7.1 运行命令摘要

```bash
# PHPUnit（集成）
php vendor/bin/phpunit app/code/Weline/Framework/Test/Integration/Runtime/StateManagerPeerOmitIntegrationTest.php --bootstrap app/bootstrap_phpunit.php

# Playwright（需 WLS 前端可达；必须在 tests/e2e 目录执行，且指定实例避免选错端口）
cd tests/e2e
set WLS_FIBER_SSE_E2E=1
set PLAYWRIGHT_INSTANCE_NAME=ai-test-e2e-pb
rem 将实例名换成你本机 php bin/w server:status 中「运行中」的那一条
set PLAYWRIGHT_TEST_FILES=["app/code/Weline/Framework/test/e2e/wls/wls-fiber-sse-concurrency.spec.js"]
npx playwright test -c playwright.config.js
rem 可选：set PLAYWRIGHT_TARGET_ORIGIN=https://127.0.0.1:9512
```

---

*本文档为测试设计方案，随实现迭代更新版本号与路径。*
