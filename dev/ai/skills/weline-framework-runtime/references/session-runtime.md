# Session 与运行时

## Session Server

- Session Server 是独立进程，维持用户会话状态
- 变更 Session 配置后需要 `server:restart -r`
- Session 数据通过共享内存或 IPC 访问

## 状态重置

- 每次请求开始时清理 static 属性
- 使用 `StateManager::register()` 注册需要重置的属性
- 热重载后所有 worker 的状态都会被重置

## 常见问题

1. **会话丢失**：检查 Session Server 是否运行
2. **状态污染**：检查 static 属性是否注册到 StateManager
3. **内存泄漏**：检查资源是否正确释放
