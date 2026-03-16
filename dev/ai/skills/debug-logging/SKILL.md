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

- 循环内每项都打日志导致刷屏
- 高频函数无过滤全量打日志
