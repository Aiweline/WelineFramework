---
name: weline-framework-runtime
description: WelineFramework 运行时与 WLS。worker、dispatcher、reload/restart、维护模式、进程编排、状态重置、Session Server、内存服务、运行时调试。触发词：WLS、Worker、Dispatcher、`server:start`、`server:reload`、`server:restart`、维护、Session Server、StateManager、进程生命周期。
globs:
  - "**/Process/**/*.php"
  - "**/Processer.php"
  - "**/Worker*.php"
alwaysApply: false
---

# Weline Framework Runtime

本技能用于 `E:\WelineFramework\DEV-workspace`。

## 何时使用

- WLS、Worker、Dispatcher 相关问题
- 进程生命周期、reload/restart
- Session Server、StateManager
- 内存服务、状态重置

## 快速参考

按以下顺序阅读：

1. [`references/runtime-basics.md`](references/runtime-basics.md)
2. [`references/session-runtime.md`](references/session-runtime.md) - 如涉及 Session/Auth
3. 按需参考：
   - `dev/ai/skills/runtime-and-process/SKILL.md`
   - `dev/ai/skills/session-development/SKILL.md`
   - `dev/ai/rules/wls-state-management.mdc`

## 默认行为

- 业务代码变更：优先 `php bin/w server:reload`
- 仅在变更启动参数、Master/Dispatcher 流程或其他 reload 不足的运行时部件时使用 restart
- 将请求级 static 属性视为可疑，在 WLS 下验证重置行为
- 审查 WLS 问题时应同时检查 worker、dispatcher、orchestrator、maintenance 和 health-check 路径

## 常用命令

```bash
php bin/w server:start
php bin/w server:reload
php bin/w server:restart -r
php bin/w server:stop
php bin/w server:status
```
