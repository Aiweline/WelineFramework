<?php

declare(strict_types=1);

namespace Weline\Framework\Session;

/**
 * 完整 Session 接口（组合数据存取 + 生命周期）
 *
 * 遵循 ISP（接口隔离原则）：
 * - 需要完整 Session 功能的模块依赖此接口
 * - 只需要数据存取的模块可以只依赖 SessionDataInterface
 * - 只需要生命周期管理的模块可以只依赖 SessionLifecycleInterface
 *
 * 注意：此接口不包含认证逻辑，认证由独立的 AuthenticatedSessionInterface 负责。
 */
interface SessionInterface extends SessionDataInterface, SessionLifecycleInterface
{
}
