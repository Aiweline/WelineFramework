---
name: runtime-and-process
description: 进程与 WLS。Processer::destroy 结束进程、Worker 优雅退出；server:start/stop/reload/restart、热重载。代码变更默认 reload。
globs:
  - "**/Process/**/*.php"
  - "**/Processer.php"
  - "**/Worker*.php"
alwaysApply: false
---

# runtime-and-process（极简版·进程+WLS）

## 何时使用

- 进程、Worker、fork、daemon、Processer；WLS、server:start、reload、restart、热重载

## 1) 进程管理

- 结束用 Processer::destroy()；Worker 退出时 destroy、关连接、清 PID；禁止直接 kill 端口导致 pid 累积

## 2) WLS

- 代码变更后 `php bin/w server:reload` 即可；仅 Master/Dispatcher/启动参数变更时 `server:restart -r`
- 全量停止 `stopAll` 阶段 2「等待排水」默认 **10s**（原 2s 易误判超时）；可在配置中设置 `wls.orchestrator.stop_all_drain_wait_sec`（1～300）

## 最小示例

```bash
php bin/w server:reload
php bin/w server:restart -r
php bin/w server:start
```

## 禁止

- 笼统说「重启」不区分 reload/restart；业务代码变更让用户 restart（应 reload）；直接 kill 端口
