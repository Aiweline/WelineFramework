# WLS IPC 高可用重构实施清单

对应主文档：
- [WLS IPC 高可用重构方案 (2026-04-15)](WLS-HA-IPC-REDESIGN-2026-04-15.md)

## 第一阶段

- [ ] 引入 `wls-supervisor` 固定控制端点
- [x] Linux/macOS 使用 UDS，Windows 使用固定 loopback TCP
- [ ] CLI 改为连接 supervisor 而不是临时 master control port
- [x] Child 侧新增 `hello -> lease_assign -> ready -> ready_ack` 协议骨架
- [x] 为 slot 引入 `lease_id`
- [x] 所有 child -> control 报文带 `lease_id`

## 第二阶段

- [ ] Dispatcher 改为消费 `pool_snapshot(versioned)`
- [ ] ADD/REMOVE 退化为优化消息，不再是真相来源
- [ ] 建立 `FailureDetector`
- [ ] 重连改为指数退避 + 抖动 + 角色优先级
- [ ] 控制、状态、日志三类消息完成 QoS 隔离

## 验收标准

- [ ] 100 个 child 并发 attach 时 control plane 不雪崩
- [ ] Orchestrator 重启时 child 不需要整体重建数据面
- [ ] stale ready / late exited / duplicate register 不再污染槽位状态
- [ ] Dispatcher worker pool 始终由 versioned snapshot 收敛
- [ ] log storm 不影响 drain/shutdown/ready 路径
