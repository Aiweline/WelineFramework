---
name: debug-logging
description: 调试日志。Debug::env() + agent_log() 精确过滤。循环只记录关键项、高频调用按 caller key 过滤。
globs: []
alwaysApply: false
---

# debug-logging（极简版）

## 何时使用

- 添加调试日志
- 调试执行流程、追踪错误
- 循环中只记录关键日志
- 高频调用（如 DB fetch）按调用位置过滤

## 配置规范（硬性）

- **Agent/IDE 调试日志**（agent_log）固定写入 **`.cursor/debug.log`**，按 Cursor 规范，不写入项目根目录（如 `var/log/`）。
- **不在项目根配置该路径**：不在 `app/etc/env.php` 或其它项目级配置里为 agent 调试 log 指定路径；过滤条件仅通过 **Debug::env()** 在运行时设置。
- 框架已实现：agent_log 落盘路径为 `BP . '.cursor' . DIRECTORY_SEPARATOR . 'debug.log'`，无需也不应在 env 中覆盖。

## 必做

- 用 agent_log(location, message, data, target, targetValue)
- location 格式：File.php:method:tag
- 配合 Debug::env() 设置过滤条件
- 循环中只记录关键项，避免刷屏

## 最小示例

```php
agent_log(__FILE__ . ':' . __METHOD__, 'message', $data, 'caller', 'MyService::load');
```

## 禁止

- 在项目根目录（env.php、.env 等）配置 agent 调试 log 路径；调试 log 仅放在 .cursor 下。
- 循环内每项都打日志导致刷屏
- 高频函数无过滤全量打日志
