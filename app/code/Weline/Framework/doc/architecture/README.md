# WelineFramework 2.0 架构索引

本目录是 `app/code/Weline/**` 的框架架构唯一设计入口。WLS 进程编排、Worker、IPC 和 Transport 细节仍由 `Weline_Server/doc` 维护，这里只定义 Framework 与模块之间的边界。

## 文档

- [01-current.md](01-current.md)：2.0 改造前的现状与已确认缺陷。
- [02-target.md](02-target.md)：目标分层、请求管线与 Provider 方向。
- [03-module-contract.md](03-module-contract.md)：模块清单、依赖、公开 API 与编译 Provider 规则，包括视图/FPC 预热贡献契约。
- [04-performance-budget.md](04-performance-budget.md)：跨平台性能、稳定性和发布门槛。

## 机器门禁

```bash
php bin/w framework:compile
php bin/w architecture:check
php bin/w architecture:check --json
```

`architecture:check` 的 2.0 静态架构门禁为：

- `dependency.framework_reverse = 0`
- `dependency.undeclared = 0`
- `dependency.internal_api = 0`
- `dependency.actual_cycle = 0`
- `runtime.blocking_wait = 0`
- Composer / module manifest gate = `0`

## 历史基线与当前结果

2026-07-11 的中间迁移基线为 83 个已注册模块、3,542 个生产 PHP 文件、7,250 个 PHP 引用点。当时结果为：

- `dependency.framework_reverse = 0`（已从 184 清零）
- `runtime.blocking_wait = 0`
- `dependency.actual_cycle = 104`
- `dependency.internal_api = 1,049`
- `dependency.undeclared = 623`

该组数据只保留为历史基线，不能再用于描述当前代码。

2026-07-12 当前权威结果由 `php bin/w architecture:check --json` 得到：83 个模块、3,955 个 PHP 文件、7,169 个 PHP 引用点。

- `dependency.framework_reverse = 0`
- `dependency.undeclared = 0`
- `dependency.internal_api = 0`
- `dependency.actual_cycle = 0`
- `runtime.blocking_wait = 0`（原生 `sleep/usleep`）
- Composer / module manifest gate = `0`

这表示当前静态模块边界、阻塞等待和清单一致性门禁已经全绿；它不等于 WelineFramework 2.0 的全部发布门槛均已完成。macOS 已有阶段性 WLS 性能证据，Linux/Windows 原生平台矩阵、FPM 对照、全部冷启动与组件级性能预算仍需继续验证，详见 [04-performance-budget.md](04-performance-budget.md)。后续变更仍必须保持 `Api` 契约、`requires/optional` 声明和无环方向，禁止通过 allowlist、内部类 alias 或宽松扫描规则制造假绿。
