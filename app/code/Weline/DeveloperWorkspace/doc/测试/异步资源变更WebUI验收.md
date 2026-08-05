# 异步资源变更 WebUI 验收

验收台使用独立的 `async_probe` 资源执行真实 `w_changed()`，不修改业务资源。链路为：

`HTTP 请求 → 事务提交 Outbox → Relay 展开 Delivery → Queue 入队 → Worker 执行 Async Observer → Delivery succeeded`

## 手工验收

1. 登录后台并打开“开发者工具 → 开发者沙盒”，找到“异步资源变更验收台”。
2. 点击“1. 发起资源变更”。页面必须显示“边界已证明”，并显示已提交的 Outbox；此时 Observer 不能是 `succeeded`。Relay 可能并发产生 `pending Delivery`，这不代表 Observer 已执行。
3. 点击“2. 推进异步链路”。等待页面显示“验收通过”。
4. 确认 Delivery 同时显示 `succeeded` 和 `queue #数字`，四个阶段卡片全部通过。
5. 若失败，展开“原始证据”，按 `event_id`、`error_code` 和 `error` 定位。

页面只有在探针 Async Observer 被 Queue Worker 实际调用且 Delivery 进入 `succeeded` 后才判定通过。

## 自动化回归

稳定用例编号：`ASYNC-WEBUI-001`

```bash
PLAYWRIGHT_TARGET_ORIGIN=https://测试实例域名:端口 \
php bin/w e2e:run app/code/Weline/DeveloperWorkspace/Test/e2e/backend/async-event-probe.spec.js
```

Queue Scope 数据覆盖缺陷由 `QueueScopeEnvelopeTest::testSettingScopeEnvelopePreservesPreparedQueueFields` 固化。
