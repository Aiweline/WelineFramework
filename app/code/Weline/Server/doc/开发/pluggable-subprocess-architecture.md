# WLS 可插拔子进程管理架构

**状态**：🟢 已完成
**完成日期**：2026-03-01
**完成度**：100%

## 概述

将 WLS Master 对子进程的管理从硬编码模式重构为可插拔架构。新架构下：

- Master 仅负责日志、监控、信号处理等职责
- 所有进程操作委托给 `ServiceOrchestrator`
- 新增服务只需实现 `ServiceProviderInterface` 并注册即可
- 统一使用 `Processer` 进行所有进程创建/销毁操作

## 架构设计

```
┌─────────────────────────────────────────────────────────────┐
│                     MasterProcess (精简版)                    │
│  职责：初始化、信号处理、对外接口兼容                            │
└─────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────┐
│                    ServiceOrchestrator                       │
│  职责：                                                       │
│  - 加载 ServiceProvider（内置 + 模块扫描）                     │
│  - 统一 startAll / stopAll / reloadAll                       │
│  - 健康检查与自动复活                                          │
│  - IPC 消息分发（通用处理 + 委托给 Provider）                   │
│  - 所有进程操作委托给 Processer                                │
└─────────────────────────────────────────────────────────────┘
        │                                    │
        ▼                                    ▼
┌───────────────────┐          ┌───────────────────────────────┐
│  ServiceRegistry  │          │    ServiceProviderInterface    │
│  - providers[]    │          │  + getRole()                   │
│  - instances[]    │          │  + isEnabled()                 │
│  - PID/Port 索引  │          │  + buildCommand()              │
└───────────────────┘          │  + healthCheck()               │
                               │  + handleMessage()             │
                               └───────────────────────────────┘
                                             △
                  ┌──────────────────────────┼──────────────────────────┐
                  │                          │                          │
         ┌────────┴────────┐     ┌───────────┴───────────┐    ┌────────┴────────┐
         │  WorkerProvider │     │  DispatcherProvider   │    │ SessionServer   │
         │  role: worker   │     │  role: dispatcher     │    │ Provider        │
         └─────────────────┘     └───────────────────────┘    └─────────────────┘
```

## 新增文件列表

### Contract 接口（`Service/Contract/`）

| 文件 | 说明 |
|------|------|
| `ServiceProviderInterface.php` | 服务提供者接口，定义 role、enabled、command、healthCheck 等 |
| `AbstractServiceProvider.php` | 抽象基类，提供默认实现 |
| `ServiceContext.php` | 服务上下文，包含全局配置（端口、SSL、实例名等） |
| `ServiceCommand.php` | 进程启动命令封装 |
| `ServiceInstance.php` | 运行时实例状态 |
| `HealthCheckResult.php` | 健康检查结果 |

### 核心组件（`Service/`）

| 文件 | 说明 |
|------|------|
| `ServiceRegistry.php` | Provider 与 Instance 注册表，支持 PID/Port/IPC 索引 |
| `ServiceOrchestrator.php` | 服务编排器，统一管理生命周期 |
| `MasterProcess.php` | 精简版 Master，委托给 Orchestrator |
| `MasterProcess.legacy.php` | 旧版 Master（备份） |

### Provider 实现（`Service/Provider/`）

| 文件 | 说明 |
|------|------|
| `WorkerProvider.php` | HTTP Worker 进程 |
| `DispatcherProvider.php` | 流量分发器 |
| `SessionServerProvider.php` | Session 共享服务 |
| `HttpRedirectProvider.php` | HTTP→HTTPS 重定向 |
| `MaintenanceWorkerProvider.php` | 维护模式 Worker |

### 测试文件（`test/Service/`）

| 文件 | 说明 |
|------|------|
| `standalone_test.php` | 独立测试脚本 |
| `ServiceRegistryTest.php` | Registry 单元测试 |
| `ServiceInstanceTest.php` | Instance 单元测试 |
| `HealthCheckResultTest.php` | HealthCheckResult 测试 |
| `ServiceCommandTest.php` | Command 测试 |
| `ServiceContextTest.php` | Context 测试 |
| `ProviderTest.php` | Provider 实现测试 |

## 如何添加新服务

1. 创建 Provider 类实现 `ServiceProviderInterface`：

```php
namespace Weline\MyModule\Service\Provider;

use Weline\Server\Service\Contract\AbstractServiceProvider;
use Weline\Server\Service\Contract\ServiceCommand;
use Weline\Server\Service\Contract\ServiceContext;
use Weline\Server\IPC\ControlMessage;
use Weline\Framework\System\Process\Processer;

class MyServiceProvider extends AbstractServiceProvider
{
    public function getRole(): string { return 'my_service'; }
    public function getDisplayName(): string { return 'My Service'; }
    public function isEnabled(ServiceContext $context): bool { return true; }
    public function getInstanceCount(ServiceContext $context): int { return 1; }
    public function getPriority(): int { return 50; }

    // 声明为模块进程（模块自定义进程必须重写这两个方法）
    public function getProcessKind(): string { return ControlMessage::PROCESS_KIND_MODULE; }
    public function getModuleCode(): string { return 'Weline_MyModule'; }

    public function buildCommand(int $instanceId, ServiceContext $context): ServiceCommand
    {
        $processName = Processer::buildModuleProcessName('Weline_MyModule', 'my-service-' . $instanceId);
        return new ServiceCommand(
            script: 'app/code/Weline/MyModule/bin/my_service.php',
            arguments: ['--port=' . $this->getPort($instanceId, $context)],
            processName: $processName,
            processKind: ControlMessage::PROCESS_KIND_MODULE,
            moduleCode: 'Weline_MyModule',
        );
    }
}
```

2. 在模块中创建 `etc/wls_services.php`：

```php
return [
    \Weline\MyModule\Service\Provider\MyServiceProvider::class,
];
```

3. `ServiceOrchestrator` 会在启动时自动扫描并加载。

## 核心接口说明

### ServiceProviderInterface

| 方法 | 说明 |
|------|------|
| `getRole()` | 角色标识（如 `worker`、`dispatcher`） |
| `getDisplayName()` | 显示名称 |
| `isEnabled(context)` | 是否启用 |
| `getInstanceCount(context)` | 实例数量 |
| `getPriority()` | 启动优先级（数字越小越先启动） |
| `getResurrectionPriority()` | 复活优先级 |
| `getReloadStrategy()` | 重载策略（graceful/immediate/none） |
| `buildCommand(instanceId, context)` | 构建启动命令 |
| `getPort(instanceId, context)` | 获取端口号 |
| `healthCheck(instance)` | 健康检查 |
| `handleMessage(msg, instance, orchestrator)` | 处理 IPC 消息 |
| `onStarted(instance)` | 启动后回调 |
| `onStopped(instance)` | 停止后回调 |
| `getProcessKind()` | 进程归属类型：'framework' \| 'module'（AbstractServiceProvider 默认返回 'framework'） |
| `getModuleCode()` | 模块代码（仅 module 类进程需要，如 'Weline_Payment'） |

### ServiceOrchestrator 主要方法

| 方法 | 说明 |
|------|------|
| `loadProviders()` | 加载所有 Provider |
| `startAll(context)` | 启动所有服务 |
| `stopAll(reason)` | 停止所有服务 |
| `reloadService(role, type)` | 重载指定服务 |
| `reloadAll(type)` | 重载所有服务 |
| `runLoop()` | 运行主循环（健康检查、IPC） |
| `getStatus()` | 获取状态快照 |

## 测试结果

```
=== Summary ===
Passed: 19, Failed: 0
```

所有核心组件测试通过。
